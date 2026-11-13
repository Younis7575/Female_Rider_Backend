<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Double-entry ledger. Every posting is balanced (sum debits == sum credits) and immutable.
 *
 * Accounts:
 *   customer_receivable  – amounts owed by customers for trips
 *   driver_payable       – per-driver balance (credit = platform owes driver)
 *   platform_revenue     – commission income
 *   promotions_expense   – coupon discounts funded by platform
 *   gateway_clearing     – digital payments collected by platform
 *   refunds_expense      – refunds issued
 *   payout_clearing      – payouts sent to drivers
 */
class LedgerService
{
    /**
     * @param  array<int, array{account:string, direction:'debit'|'credit', amount:float, user_id?:int|null}>  $lines
     * @return string transaction id
     */
    public function post(array $lines, string $entryType, string $description, array $refs = [], string $currency = 'PKR', string $actorType = 'system', ?int $actorId = null): string
    {
        $lines = array_values(array_filter($lines, fn ($l) => round((float) $l['amount'], 2) > 0));
        if (empty($lines)) {
            return '';
        }

        $debits = round(array_sum(array_map(fn ($l) => $l['direction'] === 'debit' ? $l['amount'] : 0, $lines)), 2);
        $credits = round(array_sum(array_map(fn ($l) => $l['direction'] === 'credit' ? $l['amount'] : 0, $lines)), 2);
        if (abs($debits - $credits) > 0.001) {
            throw new LogicException("Unbalanced ledger posting: debits {$debits} != credits {$credits}");
        }

        $txId = (string) Str::uuid();
        DB::transaction(function () use ($lines, $txId, $entryType, $description, $refs, $currency, $actorType, $actorId) {
            foreach ($lines as $line) {
                LedgerEntry::query()->create([
                    'transaction_id' => $txId,
                    'account' => $line['account'],
                    'user_id' => $line['user_id'] ?? null,
                    'trip_id' => $refs['trip_id'] ?? null,
                    'payment_id' => $refs['payment_id'] ?? null,
                    'payout_id' => $refs['payout_id'] ?? null,
                    'refund_id' => $refs['refund_id'] ?? null,
                    'direction' => $line['direction'],
                    'amount' => round((float) $line['amount'], 2),
                    'currency' => $currency,
                    'entry_type' => $entryType,
                    'description' => $description,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                ]);
            }
        });

        return $txId;
    }

    /** Positive = platform owes the driver; negative = driver owes the platform (e.g. cash commission). */
    public function driverBalance(int $driverUserId): float
    {
        $row = LedgerEntry::query()
            ->where('account', 'driver_payable')->where('user_id', $driverUserId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) AS bal")
            ->first();

        return round((float) $row->bal, 2);
    }

    public function accountBalance(string $account): float
    {
        $row = LedgerEntry::query()->where('account', $account)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) AS bal")
            ->first();

        return round((float) $row->bal, 2);
    }

    /** Sanity check: the whole ledger must net to zero. */
    public function isBalanced(): bool
    {
        $row = LedgerEntry::query()->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) AS net")->first();

        return abs((float) $row->net) < 0.01;
    }
}
