<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\RideRequest;
use App\Models\User;
use App\Support\Money;

class CouponService
{
    public function validate(string $code, User $user, float $fare, ?int $categoryId = null, ?int $cityId = null): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();

        $fail = fn (string $msg) => throw new BusinessRuleException($msg, 'coupon_invalid');

        if (! $coupon || ! $coupon->is_active) {
            $fail('This promo code is not valid.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $fail('This promo code is not active yet.');
        }
        if ($coupon->ends_at && $coupon->ends_at->isPast()) {
            $fail('This promo code has expired.');
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            $fail('This promo code has reached its usage limit.');
        }
        if ($coupon->budget !== null && $coupon->budget_used >= $coupon->budget) {
            $fail('This promotion has ended.');
        }
        if ($fare < $coupon->min_fare) {
            $fail('Minimum fare for this promo code is '.number_format($coupon->min_fare).'.');
        }
        if ($coupon->vehicle_category_id && $categoryId && $coupon->vehicle_category_id !== $categoryId) {
            $fail('This promo code is not valid for the selected vehicle.');
        }
        if ($coupon->city_id && $cityId && $coupon->city_id !== $cityId) {
            $fail('This promo code is not valid in this city.');
        }
        $used = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->whereIn('status', ['reserved', 'redeemed'])->count();
        if ($used >= $coupon->per_user_limit) {
            $fail('You have already used this promo code.');
        }
        if ($coupon->first_ride_only && $user->customerTrips()->where('status', 'completed')->exists()) {
            $fail('This promo code is only valid on your first ride.');
        }

        return $coupon;
    }

    public function discountFor(Coupon $coupon, float $fare): float
    {
        $discount = $coupon->type === 'percent' ? $fare * ($coupon->value / 100) : $coupon->value;
        if ($coupon->max_discount) {
            $discount = min($discount, $coupon->max_discount);
        }
        if ($coupon->budget !== null) {
            $discount = min($discount, max(0, $coupon->budget - $coupon->budget_used));
        }

        return min(Money::round($discount, 1), $fare);
    }

    public function reserve(Coupon $coupon, User $user, RideRequest $ride, float $discount): void
    {
        CouponRedemption::query()->create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'ride_request_id' => $ride->id,
            'discount_amount' => $discount, 'status' => 'reserved',
        ]);
    }

    public function release(RideRequest $ride): void
    {
        CouponRedemption::query()->where('ride_request_id', $ride->id)->where('status', 'reserved')->update(['status' => 'released']);
    }

    /** Finalise on trip completion; returns the actual discount applied. */
    public function redeem(RideRequest $ride, int $tripId, float $finalFare): float
    {
        $redemption = CouponRedemption::query()->with('coupon')->where('ride_request_id', $ride->id)->where('status', 'reserved')->lockForUpdate()->first();
        if (! $redemption) {
            return 0.0;
        }
        $discount = $this->discountFor($redemption->coupon, $finalFare);
        $redemption->update(['status' => 'redeemed', 'trip_id' => $tripId, 'discount_amount' => $discount]);
        $redemption->coupon->increment('used_count');
        $redemption->coupon->increment('budget_used', $discount);

        return $discount;
    }
}
