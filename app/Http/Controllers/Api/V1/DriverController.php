<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverDocumentResource;
use App\Http\Resources\DriverProfileResource;
use App\Http\Resources\RideOfferResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\VehicleResource;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\RideDispatch;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Models\VehicleCategory;
use App\Services\Dispatch\DispatchService;
use App\Services\Dispatch\DriverPresenceService;
use App\Services\Driver\DriverOnboardingService;
use App\Services\Rides\NegotiationService;
use App\Services\Trips\TripService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Driver onboarding, availability, incoming requests and negotiation. */
class DriverController extends Controller
{
    public function __construct(private DriverOnboardingService $onboarding, private NegotiationService $negotiation) {}

    public function profile(Request $request): JsonResponse
    {
        return ApiResponse::ok(new DriverProfileResource($this->onboarding->profileFor($request->user())));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        // Encrypted fields are returned masked, so once stored they may be omitted on re-edit.
        $existing = $request->user()->driverProfile()->first();
        $req = fn (string $field) => blank($existing?->{$field}) ? 'required' : 'nullable';
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'cnic_number' => [$req('cnic_number'), 'string', 'regex:/^\d{5}-?\d{7}-?\d$/'],
            'date_of_birth' => ['required', 'date', 'before:-18 years'],
            'license_number' => [$req('license_number'), 'string', 'max:40'],
            'license_expiry' => ['required', 'date', 'after:today'],
            'address' => ['required', 'string', 'max:255'],
            'emergency_contact_name' => ['required', 'string', 'max:80'],
            'emergency_contact_phone' => [$req('emergency_contact_phone'), 'string', 'regex:/^\+?[\d\s\-]{7,16}$/'],
        ], ['date_of_birth.before' => 'You must be at least 18 years old.']);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        return ApiResponse::ok(new DriverProfileResource($this->onboarding->saveProfile($request->user(), $data)), 'Driver details saved.');
    }

    public function updateVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_category_id' => ['required', 'integer', Rule::exists('vehicle_categories', 'id')->where('is_active', true)],
            'make' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'max:30'],
            'year' => ['required', 'integer', 'min:'.(now()->year - 25), 'max:'.(now()->year + 1)],
            'plate_number' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/'],
            'registration_expiry' => ['nullable', 'date', 'after:today'],
            'insurance_expiry' => ['nullable', 'date', 'after:today'],
        ]);
        $data['capacity'] = VehicleCategory::query()->find($data['vehicle_category_id'])->capacity;

        return ApiResponse::ok(new VehicleResource($this->onboarding->saveVehicle($request->user(), $data)), 'Vehicle saved.');
    }

    public function documents(Request $request): JsonResponse
    {
        return ApiResponse::ok(DriverDocumentResource::collection($this->onboarding->profileFor($request->user())->latestDocuments()));
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_merge(DriverProfile::REQUIRED_DOCUMENTS, DriverProfile::OPTIONAL_DOCUMENTS))],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
            'expiry_date' => ['nullable', 'date', 'after:today'],
        ]);
        $doc = $this->onboarding->uploadDocument($request->user(), $data['type'], $request->file('file'), $data['expiry_date'] ?? null);

        return ApiResponse::created(new DriverDocumentResource($doc), 'Document uploaded.');
    }

    public function submit(Request $request): JsonResponse
    {
        $request->validate(['accept_agreement' => ['required', 'accepted']]);

        return ApiResponse::ok(new DriverProfileResource($this->onboarding->submit($request->user(), $request->ip())), 'Application submitted for review.');
    }

    public function updatePayoutAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payout_method' => ['required', Rule::in(['bank_transfer', 'jazzcash', 'easypaisa'])],
            'payout_account_title' => ['required', 'string', 'max:120'],
            'payout_account_number' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,34}$/'],
        ]);
        $profile = $this->onboarding->profileFor($request->user());
        $profile->fill($data)->save();
        AuditLog::record('driver.payout_account_updated', $profile, [], ['method' => $data['payout_method']], [], $request->user());

        return ApiResponse::ok(new DriverProfileResource($profile), 'Payout account saved.');
    }

    // ---- Availability ---------------------------------------------------

    public function online(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $this->onboarding->goOnline($request->user(), (float) $data['lat'], (float) $data['lng']);

        return ApiResponse::ok(['is_online' => true, 'location_interval_s' => config('femalerider.location.online_interval_seconds')], 'You are online.');
    }

    public function offline(Request $request): JsonResponse
    {
        $this->onboarding->goOffline($request->user());

        return ApiResponse::ok(['is_online' => false], 'You are offline.');
    }

    /** High-frequency location ping (online or on trip). */
    public function location(Request $request, DriverPresenceService $presence, TripService $trips): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'recorded_at' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $profile = $user->driverProfile;
        if (! $profile || ! $user->isApprovedDriver()) {
            return ApiResponse::error('Driver account not approved.', 'driver_not_approved', 403);
        }
        $trip = $user->activeTrip();
        if (! $profile->is_online && ! $trip) {
            return ApiResponse::error('Go online to share your location.', 'driver_offline', 409);
        }
        if (($data['accuracy'] ?? 0) > config('femalerider.location.max_accuracy_m')) {
            return ApiResponse::ok(['accepted' => false, 'reason' => 'low_accuracy']);
        }

        $accepted = $presence->update($profile, (float) $data['lat'], (float) $data['lng'], $data);
        if ($accepted && $trip && $trip->driver_id === $user->id) {
            $trips->recordLocation($user, $trip, $data);
        } elseif ($accepted && ! $trip) {
            app(DispatchService::class)->discoverForDriver($user); // throttled; picks up requests as she moves
        }

        return ApiResponse::ok([
            'accepted' => $accepted,
            'next_interval_s' => $trip ? config('femalerider.location.trip_interval_seconds') : config('femalerider.location.online_interval_seconds'),
            'active_trip_id' => $trip?->uuid,
        ]);
    }

    // ---- Requests & negotiation ------------------------------------------

    public function requests(Request $request, DispatchService $dispatch): JsonResponse
    {
        $user = $request->user();
        // Make sure every nearby open request is listed, not only those broadcast while she was online.
        $dispatch->discoverForDriver($user, true);
        $dispatches = RideDispatch::query()->with('rideRequest.customer', 'rideRequest.vehicleCategory')
            ->where('driver_id', $user->id)->whereIn('status', ['notified', 'seen', 'offered'])
            ->whereHas('rideRequest', fn ($q) => $q->whereIn('status', ['searching', 'offers_available'])->where('expires_at', '>', now()))
            ->latest('id')->limit(20)->get();

        $data = $dispatches->map(function (RideDispatch $d) use ($dispatch, $user) {
            if ($d->status === 'notified') {
                $d->update(['status' => 'seen']);
            }
            $payload = $dispatch->driverPayload($d->rideRequest, $d->distance_m, $d->eta_s);
            $myOffers = RideOffer::query()->where('ride_request_id', $d->ride_request_id)->where('driver_id', $user->id)->latest('id')->get();
            $payload['my_offer'] = $myOffers->first() ? (new RideOfferResource($myOffers->first()))->resolve() : null;

            return $payload;
        });

        return ApiResponse::ok($data);
    }

    public function offer(Request $request, RideRequest $ride): JsonResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:1']]);
        $offer = $this->negotiation->driverOffer($request->user(), $ride, isset($data['amount']) ? (float) $data['amount'] : null);

        return ApiResponse::created(new RideOfferResource($offer), 'Offer sent to customer.');
    }

    public function decline(Request $request, RideRequest $ride): JsonResponse
    {
        $this->negotiation->driverDecline($request->user(), $ride);

        return ApiResponse::ok(null, 'Request declined.');
    }

    public function withdraw(Request $request, RideOffer $offer): JsonResponse
    {
        return ApiResponse::ok(new RideOfferResource($this->negotiation->driverWithdraw($request->user(), $offer)), 'Offer withdrawn.');
    }

    public function respondToCounter(Request $request, RideOffer $offer): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'reject', 'counter'])], 'amount' => ['required_if:action,counter', 'nullable', 'numeric', 'min:1']]);
        $result = $this->negotiation->driverRespondToCounter($request->user(), $offer, $data['action'], isset($data['amount']) ? (float) $data['amount'] : null);

        return $result instanceof Trip
            ? ApiResponse::ok(['trip' => new TripResource($result)], 'Ride confirmed.')
            : ApiResponse::ok(['offer' => new RideOfferResource($result)], $data['action'] === 'reject' ? 'Counter-offer declined.' : 'New offer sent.');
    }
}
