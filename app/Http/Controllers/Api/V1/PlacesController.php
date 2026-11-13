<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavedPlaceResource;
use App\Models\Coupon;
use App\Services\Finance\CouponService;
use App\Services\Maps\MapsService;
use App\Services\Rides\RideService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlacesController extends Controller
{
    public function __construct(private MapsService $maps) {}

    private function coords(): array
    {
        return ['numeric', 'between:-180,180'];
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120'], 'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', ...$this->coords()]]);

        return ApiResponse::ok($this->maps->search($data['q'], $data['lat'] ?? null, $data['lng'] ?? null), null, 200, ['provider' => $this->maps->provider()]);
    }

    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', ...$this->coords()]]);

        return ApiResponse::ok(['address' => $this->maps->reverseGeocode($data['lat'], $data['lng'])]);
    }

    public function recent(Request $request): JsonResponse
    {
        $recent = $request->user()->rideRequests()->latest('id')->limit(30)->get(['dropoff_address', 'dropoff_lat', 'dropoff_lng'])
            ->unique('dropoff_address')->take(8)
            ->map(fn ($r) => ['name' => explode(',', $r->dropoff_address)[0], 'address' => $r->dropoff_address, 'lat' => $r->dropoff_lat, 'lng' => $r->dropoff_lng])->values();

        return ApiResponse::ok($recent);
    }

    public function serviceArea(Request $request, RideService $rides): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', ...$this->coords()]]);
        try {
            $zone = $rides->assertServiceable($data['lat'], $data['lng']);

            return ApiResponse::ok(['serviceable' => true, 'zone' => $zone->name, 'city' => $zone->city->name, 'surge_multiplier' => $zone->effectiveSurge()]);
        } catch (BusinessRuleException $e) {
            return ApiResponse::ok(['serviceable' => false, 'message' => $e->getMessage()]);
        }
    }

    public function estimate(Request $request, RideService $rides): JsonResponse
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'], 'pickup_lng' => ['required', ...$this->coords()],
            'dropoff_lat' => ['required', 'numeric', 'between:-90,90'], 'dropoff_lng' => ['required', ...$this->coords()],
        ]);

        return ApiResponse::ok($rides->estimate($data['pickup_lat'], $data['pickup_lng'], $data['dropoff_lat'], $data['dropoff_lng']));
    }

    // ---- Saved places ---------------------------------------------------

    public function savedIndex(Request $request): JsonResponse
    {
        return ApiResponse::ok(SavedPlaceResource::collection($request->user()->savedPlaces()->orderByRaw("FIELD(label,'home','work','other')")->get()));
    }

    public function savedStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', Rule::in(['home', 'work', 'other'])],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', ...$this->coords()],
        ]);
        $user = $request->user();
        if ($user->savedPlaces()->count() >= 20) {
            return ApiResponse::error('You can save up to 20 places.', 'limit_reached', 422);
        }
        $place = in_array($data['label'], ['home', 'work'], true)
            ? $user->savedPlaces()->updateOrCreate(['label' => $data['label']], $data)
            : $user->savedPlaces()->create($data);

        return ApiResponse::created(new SavedPlaceResource($place), 'Place saved.');
    }

    public function savedDestroy(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->savedPlaces()->whereKey($id)->delete();

        return $deleted ? ApiResponse::ok(null, 'Place removed.') : ApiResponse::error('Place not found.', 'not_found', 404);
    }

    // ---- Coupons ---------------------------------------------------------

    public function coupons(Request $request): JsonResponse
    {
        $list = Coupon::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->latest()->limit(20)->get()
            ->map(fn (Coupon $c) => ['code' => $c->code, 'description' => $c->description, 'type' => $c->type, 'value' => $c->value, 'max_discount' => $c->max_discount, 'min_fare' => $c->min_fare, 'ends_at' => $c->ends_at?->toIso8601String()]);

        return ApiResponse::ok($list);
    }

    public function validateCoupon(Request $request, CouponService $coupons): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40'], 'fare' => ['required', 'numeric', 'min:0'], 'vehicle_category_id' => ['nullable', 'integer']]);
        $coupon = $coupons->validate($data['code'], $request->user(), (float) $data['fare'], $data['vehicle_category_id'] ?? null);

        return ApiResponse::ok(['code' => $coupon->code, 'discount' => $coupons->discountFor($coupon, (float) $data['fare']), 'description' => $coupon->description]);
    }
}
