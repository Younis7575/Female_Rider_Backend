<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\DriverPayout;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Driver payout requests: balance checks, maker-checker approval and ledger settlement. */
class PayoutService
{
    public function __construct(private LedgerService $ledger, private NotificationService $notifications) {}

    public function availableBalance(int $driverUserId): float
    {
        $pending = DriverPayout::query()->where('driver_id', $driverUserId)->whereIn('status', ['requested', 'approved', 'processing'])->sum('amount');

        return round($this->ledger->driverBalance($driverUserId) - (float) $pending, 2);
    }

    public function request(User $driver, float $amount): DriverPayout
    {
        $profile = $driver->driverProfile;
        if (! $profile || ! $driver->isApprovedDriver()) {
            throw new BusinessRuleException('Only approved drivers can request payouts.', 'driver_not_approved', 403);
        }
        if (! $profile->payout_method || ! $profile->payout_account_number) {
            throw new BusinessRuleException('Add your payout account details first.', 'payout_account_missing', 422);
        }

        return Cache::lock("payout:driver:{$driver->id}", 10)->block(5, function () use ($driver, $profile, $amount) {
            $min = (float) config('femalerider.finance.min_payout_amount');
            if ($amount < $min) {
                throw new BusinessRuleException('Minimum payout is '.number_format($min).'.', 'payout_below_minimum', 422);
            }
            if ($amount > $this->availableBalance($driver->id)) {
                throw new BusinessRuleException('Amount exceeds your available balance.', 'insufficient_balance', 422);
            }
            $acct = (string) $profile->payout_account_number;

            return DriverPayout::query()->create([
                'driver_id' => $driver->id,
                'amount' => round($amount, 2),
                'currency' => config('femalerider.currency'),
                'method' => $profile->payout_method,
                'account_title' => $profile->payout_account_title,
                'account_number_masked' => str_repeat('•', max(0, strlen($acct) - 4)).substr($acct, -4),
                'status' => 'requested',
            ]);
        });
    }

    public function approve(AdminUser $admin, DriverPayout $payout, ?string $notes = null): DriverPayout
    {
        return DB::transaction(function () use ($admin, $payout, $notes) {
            $payout = DriverPayout::query()->lockForUpdate()->findOrFail($payout->id);
            $threshold = (float) config('femalerider.finance.maker_checker_threshold');

            if ($payout->status === 'requested') {
                $needsChecker = $payout->amount > $threshold;
                $payout->update(['maker_id' => $admin->id, 'admin_notes' => $notes, 'status' => $needsChecker ? 'approved' : 'processing', 'approved_at' => $needsChecker ? null : now()]);
                AuditLog::record('payout.approved_maker', $payout, [], ['amount' => $payout->amount]);

                return $payout;
            }
            if ($payout->status === 'approved') {
                if ($payout->maker_id === $admin->id) {
                    throw new BusinessRuleException('A different finance approver must perform the second approval.', 'maker_checker', 403);
                }
                $payout->update(['checker_id' => $admin->id, 'status' => 'processing', 'approved_at' => now()]);
                AuditLog::record('payout.approved_checker', $payout, [], ['amount' => $payout->amount]);

                return $payout;
            }
            throw new BusinessRuleException('Payout is not awaiting approval.', 'invalid_state', 409);
        });
    }

    public function markPaid(AdminUser $admin, DriverPayout $payout, string $providerRef): DriverPayout
    {
        return DB::transaction(function () use ($admin, $payout, $providerRef) {
            $payout = DriverPayout::query()->lockForUpdate()->findOrFail($payout->id);
            if ($payout->status !== 'processing') {
                throw new BusinessRuleException('Payout must be approved before it is marked paid.', 'invalid_state', 409);
            }
            $payout->update(['status' => 'paid', 'paid_at' => now(), 'provider_ref' => $providerRef]);
            $this->ledger->post([
                ['account' => 'driver_payable', 'direction' => 'debit', 'amount' => $payout->amount, 'user_id' => $payout->driver_id],
                ['account' => 'payout_clearing', 'direction' => 'credit', 'amount' => $payout->amount],
            ], 'payout', "Payout {$payout->uuid}", ['payout_id' => $payout->id], $payout->currency, 'admin', $admin->id);
            AuditLog::record('payout.paid', $payout, [], ['provider_ref' => $providerRef]);

            DB::afterCommit(fn () => $this->notifications->send($payout->driver, 'payout.updated', 'Payout sent',
                $payout->currency.' '.number_format($payout->amount).' has been sent to your account.', ['screen' => 'driver_earnings']));

            return $payout;
        });
    }

    public function reject(AdminUser $admin, DriverPayout $payout, string $reason): DriverPayout
    {
        if (! in_array($payout->status, ['requested', 'approved', 'processing'], true)) {
            throw new BusinessRuleException('Payout cannot be rejected in its current state.', 'invalid_state', 409);
        }
        $payout->update(['status' => 'rejected', 'failure_reason' => $reason, 'checker_id' => $admin->id]);
        AuditLog::record('payout.rejected', $payout, [], ['reason' => $reason]);
        $this->notifications->send($payout->driver, 'payout.updated', 'Payout rejected', $reason, ['screen' => 'driver_earnings']);

        return $payout;
    }
}
