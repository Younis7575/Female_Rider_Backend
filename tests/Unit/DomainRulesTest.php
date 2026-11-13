<?php

namespace Tests\Unit;

use App\Enums\TripStatus;
use App\Models\ServiceZone;
use App\Support\Geo;
use App\Support\Money;
use Tests\TestCase;

class DomainRulesTest extends TestCase
{
    public function test_trip_state_machine_only_allows_valid_transitions(): void
    {
        $this->assertTrue(TripStatus::Arrived->canTransitionTo(TripStatus::InProgress));
        $this->assertFalse(TripStatus::DriverAssigned->canTransitionTo(TripStatus::Completed), 'cannot complete before start');
        $this->assertFalse(TripStatus::DriverAssigned->canTransitionTo(TripStatus::InProgress), 'cannot start without arrival/PIN');
        $this->assertFalse(TripStatus::Completed->canTransitionTo(TripStatus::Completed), 'cannot complete twice');
        $this->assertFalse(TripStatus::InProgress->canTransitionTo(TripStatus::CancelledByCustomer));
        $this->assertSame([], TripStatus::transitions()[TripStatus::CancelledByDriver->value]);
    }

    public function test_point_in_polygon(): void
    {
        $z = new ServiceZone(['polygon' => [[25.1, 66.85], [25.1, 67.35], [24.75, 67.35], [24.75, 66.85]]]);
        $this->assertTrue($z->contains(24.86, 67.0));
        $this->assertFalse($z->contains(31.5, 74.3));
    }

    public function test_geo_and_money_helpers(): void
    {
        $d = Geo::distanceM(24.8607, 67.0011, 24.8021, 67.0300);
        $this->assertGreaterThan(6500, $d);
        $this->assertLessThan(7500, $d);
        $this->assertSame(625.0, Money::round(623.4));
        $this->assertSame(60, Geo::etaSeconds(10));
    }
}
