<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Refund;
use App\Models\Trip;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/** Refund requests with maker-checker approval above the configured threshold. */
class RefundService
{
    public function __construct(private LedgerService $ledger, private NotificationService $notifications) {}

    public function request(User $user, Trip $trip, float $amount, string $reason): Refund
    {
        if ($trip->customer_id !== $user->id) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
        $payment = $trip->farePayment;
        if (! $payment || $payment->status !== 'paid') {
            throw new BusinessRuleException('Only paid trips can be refunded.', 'not_refundable', 409);
        }
        $pending = $payment->refunds()->whereIn('status', ['requested', 'approved'])->sum('amount');
        if ($amount <= 0 || $amount > $payment->refundableAmount() - $pending) {
            throw new BusinessRuleException('Refund amount exceeds the refundable balance.', 'refund_exceeds_balance', 422);
        }

        return Refund::query()->create([
            'payment_id' => $payment->id, 'trip_id' => $trip->id, 'user_id' => $user->id,
            'amount' => round($amount, 2), 'reason' => $reason, 'status' => 'requested',
        ]);
    }

    /** First approval (maker). Below threshold this also processes the refund. */
    public function approve(AdminUser $admin, Refund $refund, ?string $notes = null): Refund
    {
        return DB::transaction(function () use ($admin, $refund, $notes) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            $threshold = (float) config('femalerider.finance.maker_checker_threshold');

            if ($refund->status === 'requested') {
                $refund->update(['maker_id' => $admin->id, 'admin_notes' => $notes, 'status' => 'approved', 'approved_at' => now()]);
                AuditLog::record('refund.approved_maker', $refund, [], ['amount' => $refund->amount]);
                if ($refund->amount <= $threshold) {
                    return $this->process($refund, $admin);
                }

                return $refund;
            }

            if ($refund->status === 'approved' && $refund->amount > $threshold) {
                if ($refund->maker_id === $admin->id) {
                    throw new BusinessRuleException('A different finance approver must perform the second approval.', 'maker_checker', 403);
                }
                $refund->update(['checker_id' => $admin->id]);
                AuditLog::record('refund.approved_checker', $refund, [], ['amount' => $refund->amount]);

                return $this->process($refund, $admin);
            }

            throw new BusinessRuleException('Refund is not awaiting approval.', 'invalid_state', 409);
        });
    }

    public function reject(AdminUser $admin, Refund $refund, string $notes): Refund
    {
        if (! in_array($refund->status, ['requested', 'approved'], true)) {
            throw new BusinessRuleException('Refund is not awaiting approval.', 'invalid_state', 409);
        }
        $refund->update(['status' => 'rejected', 'admin_notes' => $notes, 'checker_id' => $admin->id]);
        AuditLog::record('refund.rejected', $refund, [], ['notes' => $notes]);
        $this->notifications->send($refund->user, 'payment.refund', 'Refund update', 'Your refund request was not approved. '.$notes, ['screen' => 'payments']);

        return $refund;
    }

    private function process(Refund $refund, AdminUser $admin): Refund
    {
        $payment = $refund->payment()->lockForUpdate()->first();
        $this->ledger->post([
            ['account' => 'refunds_expense', 'direction' => 'debit', 'amount' => $refund->amount],
            ['account' => 'customer_refunds_payable', 'direction' => 'credit', 'amount' => $refund->amount, 'user_id' => $refund->user_id],
        ], 'refund', "Refund {$refund->uuid}", ['trip_id' => $refund->trip_id, 'payment_id' => $payment->id, 'refund_id' => $refund->id], $payment->currency, 'admin', $admin->id);

        $payment->increment('refunded_amount', $refund->amount);
        $payment->update(['status' => $payment->fresh()->refundableAmount() <= 0 ? 'refunded' : 'partially_refunded']);
        $refund->update(['status' => 'processed', 'processed_at' => now()]);

        DB::afterCommit(fn () => $this->notifications->send($refund->user, 'payment.refund', 'Refund processed',
            $payment->currency.' '.number_format($refund->amount).' has been refunded.', ['screen' => 'payments']));

        return $refund;
    }
}
