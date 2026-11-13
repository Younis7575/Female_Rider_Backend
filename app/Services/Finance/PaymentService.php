<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Trip;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Payment records, cash collection confirmation and ledger posting.
 * Digital gateways plug in via processWebhook() (signature verified + idempotent).
 */
class PaymentService
{
    public function __construct(private LedgerService $ledger, private NotificationService $notifications) {}

    public function createForTrip(Trip $trip): Payment
    {
        $payment = Payment::query()->firstOrCreate(
            ['trip_id' => $trip->id, 'purpose' => 'fare'],
            [
                'payer_id' => $trip->customer_id,
                'amount' => $trip->amount_due,
                'currency' => $trip->currency,
                'method' => $trip->payment_method,
                'provider' => $trip->payment_method === 'cash' ? 'cash' : config('services.payments.default_gateway'),
                'status' => 'pending',
            ],
        );

        Commission::query()->firstOrCreate(['trip_id' => $trip->id], [
            'driver_id' => $trip->driver_id,
            'gross_fare' => $trip->final_fare,
            'rate' => $trip->commission_rate,
            'amount' => $trip->commission_amount,
            'currency' => $trip->currency,
        ]);

        // Earnings are recognised at completion regardless of payment method.
        $this->ledger->post([
            ['account' => 'customer_receivable', 'direction' => 'debit', 'amount' => $trip->final_fare, 'user_id' => $trip->customer_id],
            ['account' => 'driver_payable', 'direction' => 'credit', 'amount' => $trip->final_fare, 'user_id' => $trip->driver_id],
        ], 'trip_fare', "Trip {$trip->uuid} fare", ['trip_id' => $trip->id, 'payment_id' => $payment->id], $trip->currency);

        $this->ledger->post([
            ['account' => 'driver_payable', 'direction' => 'debit', 'amount' => $trip->commission_amount, 'user_id' => $trip->driver_id],
            ['account' => 'platform_revenue', 'direction' => 'credit', 'amount' => $trip->commission_amount],
        ], 'commission', "Commission {$trip->commission_rate}% on trip {$trip->uuid}", ['trip_id' => $trip->id], $trip->currency);

        if ($trip->discount_amount > 0) {
            $this->ledger->post([
                ['account' => 'promotions_expense', 'direction' => 'debit', 'amount' => $trip->discount_amount],
                ['account' => 'customer_receivable', 'direction' => 'credit', 'amount' => $trip->discount_amount, 'user_id' => $trip->customer_id],
            ], 'promotion', "Promo discount on trip {$trip->uuid}", ['trip_id' => $trip->id], $trip->currency);
        }

        if ($payment->amount <= 0) {
            $this->markPaid($payment, 'system', null);
        }

        return $payment;
    }

    public function createCancellationFee(Trip $trip, float $fee): Payment
    {
        $payment = Payment::query()->firstOrCreate(
            ['trip_id' => $trip->id, 'purpose' => 'cancellation_fee'],
            ['payer_id' => $trip->customer_id, 'amount' => $fee, 'currency' => $trip->currency, 'method' => 'cash', 'provider' => 'cash', 'status' => 'pending'],
        );
        $this->ledger->post([
            ['account' => 'customer_receivable', 'direction' => 'debit', 'amount' => $fee, 'user_id' => $trip->customer_id],
            ['account' => 'driver_payable', 'direction' => 'credit', 'amount' => $fee, 'user_id' => $trip->driver_id],
        ], 'cancellation_fee', "Cancellation fee for trip {$trip->uuid}", ['trip_id' => $trip->id, 'payment_id' => $payment->id], $trip->currency);

        return $payment;
    }

    /** Driver confirms cash received from the customer. Idempotent. */
    public function confirmCash(User $driver, Trip $trip): Payment
    {
        if ($trip->driver_id !== $driver->id) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
        $payment = $trip->farePayment;
        if (! $payment) {
            throw new BusinessRuleException('No payment is due for this trip.', 'no_payment', 409);
        }
        if ($payment->method !== 'cash') {
            throw new BusinessRuleException('This trip is not a cash trip.', 'not_cash', 409);
        }
        if ($payment->status === 'paid') {
            return $payment;
        }

        return $this->markPaid($payment, 'driver', $driver->id);
    }

    public function markPaid(Payment $payment, string $actorType = 'system', ?int $actorId = null, ?string $providerRef = null): Payment
    {
        return DB::transaction(function () use ($payment, $actorType, $actorId, $providerRef) {
            $payment = Payment::query()->with('trip')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'paid') {
                return $payment;
            }
            $payment->update(['status' => 'paid', 'paid_at' => now(), 'provider_ref' => $providerRef ?? $payment->provider_ref]);
            $trip = $payment->trip;

            if ($payment->amount > 0) {
                // Cash goes to the driver directly: reduces what the platform owes them.
                // Digital money lands in the platform's gateway clearing account.
                $settle = $payment->method === 'cash'
                    ? ['account' => 'driver_payable', 'direction' => 'debit', 'amount' => $payment->amount, 'user_id' => $trip->driver_id]
                    : ['account' => 'gateway_clearing', 'direction' => 'debit', 'amount' => $payment->amount];
                $this->ledger->post([
                    $settle,
                    ['account' => 'customer_receivable', 'direction' => 'credit', 'amount' => $payment->amount, 'user_id' => $payment->payer_id],
                ], $payment->method === 'cash' ? 'cash_collection' : 'payment_capture', ucfirst($payment->purpose)." payment for trip {$trip->uuid}",
                    ['trip_id' => $trip->id, 'payment_id' => $payment->id], $payment->currency, $actorType, $actorId);
            }

            if ($payment->purpose === 'fare') {
                $trip->update(['payment_status' => 'paid']);
            }

            DB::afterCommit(fn () => $this->notifications->send($payment->payer, 'payment.updated', 'Payment received',
                'Thank you! '.$payment->currency.' '.number_format($payment->amount).' received.', ['trip_id' => $trip->uuid, 'screen' => 'trip_receipt']));

            return $payment;
        });
    }

    /**
     * Process a digital-payment provider webhook. Signature must already be verified by the controller.
     * Idempotent on (provider, event_id).
     */
    public function processWebhook(string $provider, array $payload): PaymentWebhookEvent
    {
        $eventId = (string) ($payload['id'] ?? throw new BusinessRuleException('Missing event id.', 'validation'));
        $event = PaymentWebhookEvent::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => $eventId],
            ['type' => $payload['type'] ?? null, 'payload' => $payload],
        );
        if ($event->processed_at) {
            return $event; // replay
        }

        try {
            $payment = Payment::query()->where('uuid', $payload['data']['payment_id'] ?? null)->first();
            if ($payment) {
                match ($payload['type'] ?? null) {
                    'payment.succeeded' => $this->markPaid($payment, 'system', null, $payload['data']['reference'] ?? null),
                    'payment.failed' => $payment->update(['status' => 'failed', 'failure_reason' => $payload['data']['reason'] ?? 'declined']),
                    default => null,
                };
            }
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['error' => substr($e->getMessage(), 0, 250)]);
            throw $e;
        }

        return $event;
    }
}
