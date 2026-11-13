<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideOfferResource;
use App\Http\Resources\RideRequestResource;
use App\Http\Resources\TripResource;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Services\Rides\NegotiationService;
use App\Services\Rides\RideService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer-side ride requests and offer handling. */
class RideController extends Controller
{
    public function __construct(private RideService $rides, private NegotiationService $negotiation) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_category_id' => ['required', 'integer', 'exists:vehicle_categories,id'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_address' => ['nullable', 'string', 'max:255'],
            'pickup_note' => ['nullable', 'string', 'max:255'],
            'proposed_fare' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'payment_method' => ['nullable', Rule::in(['cash'])],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        $ride = $this->rides->create($request->user(), $data, $request->header('Idempotency-Key'));

        return ApiResponse::created(new RideRequestResource($ride->load('vehicleCategory')->loadCount('dispatches')), 'Looking for drivers near you.');
    }

    public function index(Request $request): JsonResponse
    {
        $rides = $request->user()->rideRequests()->with('vehicleCategory', 'trip')->latest('id')->paginate(min((int) $request->query('per_page', 20), 50));

        return ApiResponse::ok(RideRequestResource::collection($rides));
    }

    public function active(Request $request): JsonResponse
    {
        $ride = $request->user()->rideRequests()->whereIn('status', ['searching', 'offers_available'])->where('expires_at', '>', now())
            ->with(['vehicleCategory', 'offers' => fn ($q) => $q->where('status', 'pending')->where('expires_at', '>', now())])->withCount('dispatches')->latest('id')->first();

        return ApiResponse::ok($ride ? new RideRequestResource($ride) : null);
    }

    public function show(Request $request, RideRequest $ride): JsonResponse
    {
        $this->rides->assertOwner($request->user(), $ride);
        $ride->load(['vehicleCategory', 'trip', 'offers' => fn ($q) => $q->latest('id')])->loadCount('dispatches');

        return ApiResponse::ok(new RideRequestResource($ride));
    }

    public function offers(Request $request, RideRequest $ride): JsonResponse
    {
        $this->rides->assertOwner($request->user(), $ride);
        $live = $request->boolean('all') ? $ride->offers()->latest('id')->get() : $ride->offers()->where('status', 'pending')->where('expires_at', '>', now())->latest('id')->get();

        return ApiResponse::ok(RideOfferResource::collection($live->load('rideRequest')));
    }

    public function cancel(Request $request, RideRequest $ride): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::ok(new RideRequestResource($this->rides->cancel($request->user(), $ride, $data['reason'] ?? null)), 'Ride request cancelled.');
    }

    public function updateFare(Request $request, RideRequest $ride): JsonResponse
    {
        $data = $request->validate(['proposed_fare' => ['required', 'numeric', 'min:1']]);

        return ApiResponse::ok(new RideRequestResource($this->rides->updateFare($request->user(), $ride, (float) $data['proposed_fare'])->load('vehicleCategory')->loadCount('dispatches')), 'Fare updated.');
    }

    public function acceptOffer(Request $request, RideOffer $offer): JsonResponse
    {
        $trip = $this->negotiation->customerAccept($request->user(), $offer);

        return ApiResponse::ok(new TripResource($trip), 'Driver assigned.');
    }

    public function rejectOffer(Request $request, RideOffer $offer): JsonResponse
    {
        return ApiResponse::ok(new RideOfferResource($this->negotiation->customerReject($request->user(), $offer)), 'Offer declined.');
    }

    public function counterOffer(Request $request, RideOffer $offer): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        return ApiResponse::created(new RideOfferResource($this->negotiation->customerCounter($request->user(), $offer, (float) $data['amount'])), 'Counter-offer sent.');
    }
}
