<?php

namespace App\Services\Pricing;

use App\Exceptions\BusinessRuleException;
use App\Models\FareRule;
use App\Models\ServiceZone;
use App\Models\SystemSetting;
use App\Models\VehicleCategory;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Server-side fare engine. Suggested fare = (base + km×rate + min×rate + booking fee)
 * × time-rule multiplier × capped zone surge, floored at the minimum fare.
 * Negotiation bounds are derived from the suggested fare and category ratios.
 */
class FareCalculator
{
    /**
     * @return array{suggested_fare: float, min_fare: float, max_fare: float, surge_multiplier: float, commission_percent: float, currency: string, breakdown: array}
     */
    public function estimate(VehicleCategory $category, int $distanceM, int $durationS, ?ServiceZone $zone = null, ?CarbonInterface $at = null): array
    {
        if (! $category->is_active) {
            throw new BusinessRuleException('This vehicle category is not available.', 'category_inactive');
        }

        $at ??= now();
        $rates = $this->resolveRates($category, $zone, $at);

        $km = $distanceM / 1000;
        $minutes = $durationS / 60;

        $distanceCharge = $km * $rates['per_km'];
        $timeCharge = $minutes * $rates['per_minute'];
        $subtotal = $rates['base_fare'] + $distanceCharge + $timeCharge + $category->booking_fee;
        $subtotal *= $rates['time_multiplier'];

        $surge = $zone?->effectiveSurge() ?? 1.0;
        $total = max($subtotal * $surge, $rates['minimum_fare']);
        $suggested = Money::round($total);

        $minRatio = (float) SystemSetting::get('fare.min_offer_ratio', $category->min_offer_ratio);
        $maxRatio = (float) SystemSetting::get('fare.max_offer_ratio', $category->max_offer_ratio);
        $minFare = max(Money::round($suggested * $minRatio), Money::round($rates['minimum_fare'] * 0.8));
        $maxFare = Money::round($suggested * $maxRatio);

        return [
            'suggested_fare' => $suggested,
            'min_fare' => $minFare,
            'max_fare' => max($maxFare, $minFare),
            'surge_multiplier' => $surge,
            'commission_percent' => $rates['commission_percent'],
            'currency' => $zone?->city?->currency ?? config('femalerider.currency'),
            'breakdown' => [
                'base_fare' => round($rates['base_fare'], 2),
                'distance_km' => round($km, 2),
                'distance_charge' => round($distanceCharge, 2),
                'duration_min' => round($minutes, 1),
                'time_charge' => round($timeCharge, 2),
                'booking_fee' => round($category->booking_fee, 2),
                'time_multiplier' => $rates['time_multiplier'],
                'surge_multiplier' => $surge,
                'minimum_fare' => $rates['minimum_fare'],
            ],
        ];
    }

    /** Validate that a negotiated amount is within server-computed bounds. */
    public function assertWithinBounds(float $amount, float $min, float $max): void
    {
        if ($amount < $min || $amount > $max) {
            throw new BusinessRuleException(
                sprintf('Fare must be between %s and %s.', number_format($min), number_format($max)),
                'fare_out_of_bounds', 422, ['min_fare' => $min, 'max_fare' => $max],
            );
        }
    }

    public function commissionRate(VehicleCategory $category, ?ServiceZone $zone = null): float
    {
        return $this->resolveRates($category, $zone, now())['commission_percent'];
    }

    private function resolveRates(VehicleCategory $category, ?ServiceZone $zone, CarbonInterface $at): array
    {
        $rates = [
            'base_fare' => $category->base_fare,
            'per_km' => $category->per_km,
            'per_minute' => $category->per_minute,
            'minimum_fare' => $category->minimum_fare,
            'commission_percent' => $category->commission_percent,
            'time_multiplier' => 1.0,
        ];

        $rules = FareRule::query()
            ->where('vehicle_category_id', $category->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('service_zone_id')->orWhere('service_zone_id', $zone?->id))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at))
            ->orderByRaw('service_zone_id IS NULL DESC') // global first, zone-specific overrides after
            ->get();

        foreach ($rules as $rule) {
            if (! $this->ruleAppliesAt($rule, $at)) {
                continue;
            }
            foreach (['base_fare', 'per_km', 'per_minute', 'minimum_fare', 'commission_percent'] as $f) {
                if ($rule->{$f} !== null) {
                    $rates[$f] = (float) $rule->{$f};
                }
            }
            $rates['time_multiplier'] = max($rates['time_multiplier'], (float) $rule->time_multiplier);
        }

        $rates['time_multiplier'] = min($rates['time_multiplier'], 2.0);

        return $rates;
    }

    private function ruleAppliesAt(FareRule $rule, CarbonInterface $at): bool
    {
        if ($rule->days_of_week && ! in_array($at->dayOfWeekIso, array_map('intval', $rule->days_of_week), true)) {
            return false;
        }
        if ($rule->applies_from && $rule->applies_to) {
            $t = $at->format('H:i:s');
            $from = $rule->applies_from;
            $to = $rule->applies_to;

            return $from <= $to ? ($t >= $from && $t <= $to) : ($t >= $from || $t <= $to);
        }

        return true;
    }
}
