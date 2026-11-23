<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\DriverDeposit;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Manual payment method (the only one for now): the driver sends money from her own mobile
 * wallet to FemaleRider's JazzCash account, then submits the TID and a screenshot. An admin
 * checks the transfer and approves it, which credits the driver's balance in the ledger.
 */
class ManualPaymentService
{
    public function __construct(private LedgerService $ledger, private NotificationService $notifications) {}

    /** Account details shown to drivers (editable in Admin → System settings, group "payments"). */
    public function accountDetails(): array
    {
        return [
            'method' => 'jazzcash',
            'method_label' => 'JazzCash',
            'account_title' => (string) SystemSetting::get('payments.manual.account_title', 'FemaleRider'),
            'account_number' => (string) SystemSetting::get('payments.manual.jazzcash_number', ''),
            'instructions' => (string) SystemSetting::get('payments.manual.instructions', ''),
            'min_amount' => (float) SystemSetting::get('payments.manual.min_amount', 100),
            'max_amount' => (float) SystemSetting::get('payments.manual.max_amount', 100000),
            'enabled' => filled(SystemSetting::get('payments.manual.jazzcash_number')),
        ];
    }

    public function submit(User $driver, float $amount, string $tid, UploadedFile $screenshot, ?string $senderNumber = null, ?string $note = null): DriverDeposit
    {
        if (! $driver->driverProfile) {
            throw new BusinessRuleException('Only drivers can submit payments.', 'not_driver', 403);
        }
        $cfg = $this->accountDetails();
        if (! $cfg['enabled']) {
            throw new BusinessRuleException('Payments are not available right now. Please contact support.', 'payments_disabled', 409);
        }
        if ($amount < $cfg['min_amount'] || $amount > $cfg['max_amount']) {
            throw new BusinessRuleException('Amount must be between PKR '.number_format($cfg['min_amount']).' and PKR '.number_format($cfg['max_amount']).'.', 'amount_out_of_range', 422);
        }
        $tid = strtoupper(preg_replace('/\s+/', '', $tid));
        if (DriverDeposit::query()->where('method', 'jazzcash')->where('transaction_ref', $tid)->exists()) {
            throw new BusinessRuleException('This transaction ID has already been submitted.', 'duplicate_tid', 409);
        }
        if (DriverDeposit::query()->where('driver_id', $driver->id)->where('status', 'pending')->count() >= 3) {
            throw new BusinessRuleException('You already have 3 payments waiting for verification.', 'too_many_pending', 429);
        }

        $path = $screenshot->store("payment-proofs/{$driver->uuid}", 'local');
        $deposit = DriverDeposit::query()->create([
            'driver_id' => $driver->id,
            'amount' => round($amount, 2),
            'transaction_ref' => $tid,
            'sender_number' => $senderNumber ? preg_replace('/[^\d+]/', '', $senderNumber) : null,
            'screenshot_path' => $path,
            'driver_note' => $note,
            'status' => 'pending',
            'currency' => 'PKR',
            'method' => 'jazzcash',
        ]);
        AuditLog::record('driver_deposit.submitted', $deposit, [], ['amount' => $deposit->amount, 'tid' => $tid], [], $driver);

        return $deposit;
    }

    public function approve(AdminUser $admin, DriverDeposit $deposit, float $amount, ?string $note = null): DriverDeposit
    {
        return DB::transaction(function () use ($admin, $deposit, $amount) {
            $deposit = DriverDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($deposit->status !== 'pending') {
                throw new BusinessRuleException('This payment has already been '.$deposit->status.'.', 'already_reviewed', 409);
            }
            if ($amount <= 0 || $amount > 1000000) {
                throw new BusinessRuleException('Enter the amount actually received.', 'invalid_amount', 422);
            }
            $tx = $this->ledger->post([
                ['account' => 'manual_payments_clearing', 'direction' => 'debit', 'amount' => $amount],
                ['account' => 'driver_payable', 'direction' => 'credit', 'amount' => $amount, 'user_id' => $deposit->driver_id],
            ], 'driver_deposit', "JazzCash payment {$deposit->transaction_ref} from driver", [], $deposit->currency, 'admin', $admin->id);

            $deposit->forceFill([
                'status' => 'approved', 'approved_amount' => round($amount, 2), 'reviewed_by' => $admin->id,
                'reviewed_at' => now(), 'ledger_transaction_id' => $tx,
            ])->save();
            AuditLog::record('driver_deposit.approved', $deposit, ['status' => 'pending'], ['status' => 'approved', 'amount' => $amount], [], $admin);

            DB::afterCommit(fn () => $this->notifications->send($deposit->driver, 'payment.approved', 'Payment verified',
                'PKR '.number_format($amount).' has been added to your FemaleRider account.', ['screen' => 'driver_earnings', 'deposit_id' => $deposit->uuid]));

            return $deposit;
        });
    }

    public function reject(AdminUser $admin, DriverDeposit $deposit, string $reason): DriverDeposit
    {
        return DB::transaction(function () use ($admin, $deposit, $reason) {
            $deposit = DriverDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            if ($deposit->status !== 'pending') {
                throw new BusinessRuleException('This payment has already been '.$deposit->status.'.', 'already_reviewed', 409);
            }
            $deposit->forceFill(['status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
            AuditLog::record('driver_deposit.rejected', $deposit, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $reason], [], $admin);

            DB::afterCommit(fn () => $this->notifications->send($deposit->driver, 'payment.rejected', 'Payment not verified',
                "We could not verify your payment (TID {$deposit->transaction_ref}): {$reason}", ['screen' => 'driver_earnings', 'deposit_id' => $deposit->uuid]));

            return $deposit;
        });
    }

    public function screenshotExists(DriverDeposit $deposit): bool
    {
        return Storage::disk('local')->exists($deposit->screenshot_path);
    }
}
