<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverPayoutResource;
use App\Http\Resources\LedgerEntryResource;
use App\Models\DriverOnlineSession;
use App\Models\DriverPayout;
use App\Models\LedgerEntry;
use App\Models\Trip;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PayoutService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverFinanceController extends Controller
{
    public function __construct(private LedgerService $ledger, private PayoutService $payouts) {}

    private function stats(int $driverId, Carbon $from, Carbon $to): array
    {
        $trips = Trip::query()->where('driver_id', $driverId)->whereBetween('created_at', [$from, $to]);
        $completed = (clone $trips)->where('status', 'completed');

        return [
            'completed_trips' => (clone $completed)->count(),
            'cancelled_trips' => (clone $trips)->where('status', 'cancelled_by_driver')->count(),
            'gross_fares' => round((float) (clone $completed)->sum('final_fare'), 2),
            'commission' => round((float) (clone $completed)->sum('commission_amount'), 2),
            'net_earnings' => round((float) (clone $completed)->sum('driver_earning'), 2),
            'cash_collected' => round((float) (clone $completed)->where('payment_method', 'cash')->sum('amount_due'), 2),
        ];
    }

    private function onlineHours(int $profileId, Carbon $from): float
    {
        $secs = DriverOnlineSession::query()->where('driver_profile_id', $profileId)->where(fn ($q) => $q->whereNull('offline_at')->orWhere('offline_at', '>=', $from))
            ->get()->sum(fn ($s) => max(0, ($s->offline_at ?? now())->timestamp - max($s->online_at->timestamp, $from->timestamp)));

        return round($secs / 3600, 1);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->driverProfile;
        $today = $this->stats($user->id, now()->startOfDay(), now());
        $week = $this->stats($user->id, now()->startOfWeek(), now());

        return ApiResponse::ok([
            'is_online' => (bool) $profile?->is_online,
            'status' => $profile?->status?->value ?? 'not_submitted',
            'rating' => (float) ($profile?->rating_avg ?? 5),
            'total_completed_trips' => (int) $profile?->completed_trips,
            'today' => $today + ['online_hours' => $profile ? $this->onlineHours($profile->id, now()->startOfDay()) : 0],
            'week' => $week + ['online_hours' => $profile ? $this->onlineHours($profile->id, now()->startOfWeek()) : 0],
            'balance' => $this->ledger->driverBalance($user->id),
            'available_for_payout' => max(0, $this->payouts->availableBalance($user->id)),
            'currency' => config('femalerider.currency'),
            'compliance_issues' => $profile?->complianceIssues() ?? [],
            'expiring_documents' => $profile ? $profile->latestDocuments()->filter(fn ($d) => $d->expiry_date && $d->expiry_date->between(now(), now()->addDays(30)))->map(fn ($d) => ['type' => $d->type, 'label' => $d->label(), 'expiry_date' => $d->expiry_date->toDateString()])->values() : [],
            'unread_notifications' => $user->appNotifications()->whereNull('read_at')->count(),
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->query('period', 'week');
        $from = match ($period) {
            'today' => now()->startOfDay(),
            'month' => now()->startOfMonth(),
            default => now()->startOfWeek(),
        };
        $daily = Trip::query()->where('driver_id', $user->id)->where('status', 'completed')->where('completed_at', '>=', $from)
            ->selectRaw('DATE(completed_at) d, COUNT(*) trips, SUM(driver_earning) earnings, SUM(commission_amount) commission')
            ->groupBy('d')->orderBy('d')->get()
            ->map(fn ($r) => ['date' => $r->d, 'trips' => (int) $r->trips, 'earnings' => round((float) $r->earnings, 2), 'commission' => round((float) $r->commission, 2)]);

        return ApiResponse::ok(['period' => $period, 'summary' => $this->stats($user->id, $from, now()), 'daily' => $daily, 'currency' => config('femalerider.currency')]);
    }

    /** Monthly earnings statement (GET /driver/statements?month=YYYY-MM). */
    public function statement(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = isset($data['month']) ? Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth() : now()->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $user = $request->user();
        $trips = Trip::query()->with('rideRequest')->where('driver_id', $user->id)->where('status', 'completed')->whereBetween('completed_at', [$from, $to])->orderBy('completed_at')->get();
        $payouts = DriverPayout::query()->where('driver_id', $user->id)->where('status', 'paid')->whereBetween('paid_at', [$from, $to])->get();
        $opening = (float) LedgerEntry::query()->where('account', 'driver_payable')->where('user_id', $user->id)->where('created_at', '<', $from)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) b")->value('b');

        return ApiResponse::ok([
            'month' => $from->format('Y-m'),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => config('femalerider.currency'),
            'summary' => $this->stats($user->id, $from, $to) + [
                'payouts_paid' => round((float) $payouts->sum('amount'), 2),
                'opening_balance' => round($opening, 2),
                'closing_balance' => $to->isPast() ? round((float) LedgerEntry::query()->where('account', 'driver_payable')->where('user_id', $user->id)->where('created_at', '<=', $to)->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) b")->value('b'), 2) : $this->ledger->driverBalance($user->id),
            ],
            'trips' => $trips->map(fn (Trip $t) => [
                'id' => $t->uuid, 'completed_at' => $t->completed_at->toIso8601String(),
                'from' => $t->rideRequest->pickup_address, 'to' => $t->rideRequest->dropoff_address,
                'fare' => $t->final_fare, 'commission' => $t->commission_amount, 'earning' => $t->driver_earning, 'payment_method' => $t->payment_method,
            ])->values(),
            'payouts' => $payouts->map(fn ($p) => ['id' => $p->uuid, 'amount' => $p->amount, 'paid_at' => $p->paid_at?->toIso8601String(), 'reference' => $p->provider_ref])->values(),
        ]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $entries = LedgerEntry::query()->with('trip')->where('account', 'driver_payable')->where('user_id', $request->user()->id)->latest('id')->paginate(30);

        return ApiResponse::ok(LedgerEntryResource::collection($entries), null, 200, ['balance' => $this->ledger->driverBalance($request->user()->id)]);
    }

    public function payouts(Request $request): JsonResponse
    {
        return ApiResponse::ok(DriverPayoutResource::collection($request->user()->hasMany(DriverPayout::class, 'driver_id')->latest('id')->paginate(20)),
            null, 200, ['available_for_payout' => max(0, $this->payouts->availableBalance($request->user()->id)), 'min_payout' => (float) config('femalerider.finance.min_payout_amount')]);
    }

    public function requestPayout(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        return ApiResponse::created(new DriverPayoutResource($this->payouts->request($request->user(), (float) $data['amount'])), 'Payout requested.');
    }
}
