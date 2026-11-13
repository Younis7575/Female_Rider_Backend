<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TripResource;
use App\Models\Rating;
use App\Models\Trip;
use App\Models\User;
use App\Services\Finance\PaymentService;
use App\Services\Trips\TripService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function __construct(private TripService $trips) {}

    private function authorizeTrip(Request $request, Trip $trip): void
    {
        if (! $trip->involves($request->user())) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $as = $request->query('as', $user->active_mode);
        $q = Trip::query()->with('rideRequest', 'customer', 'driver.driverProfile', 'vehicle', 'vehicleCategory', 'farePayment', 'ratings')
            ->where($as === 'driver' ? 'driver_id' : 'customer_id', $user->id)
            ->when($request->query('status') === 'completed', fn ($q) => $q->where('status', 'completed'))
            ->when($request->query('status') === 'cancelled', fn ($q) => $q->whereIn('status', ['cancelled_by_customer', 'cancelled_by_driver', 'cancelled_by_admin']))
            ->latest('id');

        return ApiResponse::ok(TripResource::collection($q->paginate(min((int) $request->query('per_page', 20), 50))));
    }

    public function active(Request $request): JsonResponse
    {
        $trip = $request->user()->activeTrip();

        return ApiResponse::ok($trip ? new TripResource($trip) : null);
    }

    public function show(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);

        return ApiResponse::ok(new TripResource($trip));
    }

    public function enRoute(Request $request, Trip $trip): JsonResponse
    {
        return ApiResponse::ok(new TripResource($this->trips->markEnRoute($request->user(), $trip)), 'Heading to pickup.');
    }

    public function arrive(Request $request, Trip $trip): JsonResponse
    {
        return ApiResponse::ok(new TripResource($this->trips->arrive($request->user(), $trip)), 'Customer notified that you have arrived.');
    }

    public function start(Request $request, Trip $trip): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'string', 'digits:'.config('femalerider.trip.pin_length')]]);

        return ApiResponse::ok(new TripResource($this->trips->start($request->user(), $trip, $data['pin'])), 'Trip started.');
    }

    public function complete(Request $request, Trip $trip): JsonResponse
    {
        return ApiResponse::ok(new TripResource($this->trips->complete($request->user(), $trip)), 'Trip completed.');
    }

    public function cancel(Request $request, Trip $trip): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok(new TripResource($this->trips->cancel($request->user(), $trip, $data['reason'])), 'Trip cancelled.');
    }

    /** Customer-side location (optional, for safety); driver pings go via /driver/location. */
    public function location(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric'], 'accuracy' => ['nullable', 'numeric'], 'sequence' => ['nullable', 'integer'],
        ]);
        $this->trips->recordLocation($request->user(), $trip, $data);

        return ApiResponse::ok(['accepted' => true]);
    }

    public function share(Request $request, Trip $trip): JsonResponse
    {
        return ApiResponse::ok($this->trips->share($request->user(), $trip), 'Share link created.');
    }

    public function revokeShare(Request $request, Trip $trip): JsonResponse
    {
        $this->trips->revokeShare($request->user(), $trip);

        return ApiResponse::ok(null, 'Share link revoked.');
    }

    public function cashCollected(Request $request, Trip $trip, PaymentService $payments): JsonResponse
    {
        return ApiResponse::ok(new PaymentResource($payments->confirmCash($request->user(), $trip)->load('trip.rideRequest')), 'Cash payment recorded.');
    }

    public function receipt(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        if ($trip->status !== TripStatus::Completed) {
            return ApiResponse::error('Receipt is available after the trip is completed.', 'trip_not_completed', 409);
        }
        $trip->load('rideRequest', 'farePayment', 'vehicleCategory', 'driver', 'vehicle');

        return ApiResponse::ok([
            'receipt_number' => 'FR-'.strtoupper(substr(str_replace('-', '', $trip->uuid), 0, 10)),
            'trip_id' => $trip->uuid,
            'date' => $trip->completed_at->toIso8601String(),
            'from' => $trip->rideRequest->pickup_address,
            'to' => $trip->rideRequest->dropoff_address,
            'distance_km' => round(($trip->actual_distance_m ?? $trip->rideRequest->distance_m) / 1000, 2),
            'duration_min' => (int) round(($trip->actual_duration_s ?? 0) / 60),
            'vehicle_category' => $trip->vehicleCategory->name,
            'driver_first_name' => $trip->driver->firstName(),
            'vehicle_plate' => $trip->vehicle?->plate_number,
            'fare_breakdown' => $trip->fare_breakdown,
            'total' => $trip->final_fare,
            'discount' => $trip->discount_amount,
            'amount_paid' => $trip->amount_due,
            'currency' => $trip->currency,
            'payment_method' => $trip->payment_method,
            'payment_status' => $trip->farePayment?->status ?? $trip->payment_status,
            'refundable_amount' => $trip->farePayment && $trip->farePayment->status !== 'pending'
                ? max(0, round($trip->farePayment->refundableAmount() - (float) $trip->farePayment->refunds()->whereIn('status', ['requested', 'approved'])->sum('amount'), 2))
                : 0,
        ]);
    }

    public function rate(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $user = $request->user();
        $role = $trip->roleOf($user);
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:6'],
            'tags.*' => ['string', Rule::in(config('femalerider.rating_tags.'.$role))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        if ($trip->status !== TripStatus::Completed) {
            return ApiResponse::error('You can rate after the trip is completed.', 'trip_not_completed', 409);
        }
        if ($trip->completed_at->lt(now()->subDays(7))) {
            return ApiResponse::error('The rating window for this trip has closed.', 'rating_window_closed', 409);
        }
        if (Rating::query()->where('trip_id', $trip->id)->where('rater_id', $user->id)->exists()) {
            return ApiResponse::error('You have already rated this trip.', 'already_rated', 409);
        }

        DB::transaction(function () use ($trip, $user, $role, $data) {
            $rateeId = $role === 'customer' ? $trip->driver_id : $trip->customer_id;
            Rating::query()->create([
                'trip_id' => $trip->id, 'rater_id' => $user->id, 'ratee_id' => $rateeId, 'rater_role' => $role,
                'score' => $data['score'], 'tags' => $data['tags'] ?? [], 'comment' => $data['comment'] ?? null,
                'moderation_status' => $data['score'] <= 2 ? 'flagged' : 'visible',
            ]);
            $agg = Rating::query()->where('ratee_id', $rateeId)->where('moderation_status', '!=', 'hidden')->selectRaw('AVG(score) a, COUNT(*) c')->first();
            $ratee = User::query()->find($rateeId);
            $ratee->forceFill(['rating_avg' => round((float) $agg->a, 2), 'rating_count' => (int) $agg->c])->save();
            if ($role === 'customer') {
                $ratee->driverProfile?->forceFill(['rating_avg' => round((float) $agg->a, 2), 'rating_count' => (int) $agg->c])->save();
            }
        });

        return ApiResponse::created(null, 'Thanks for your feedback.');
    }
}
