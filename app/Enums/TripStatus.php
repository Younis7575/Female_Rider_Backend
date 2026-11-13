<?php

namespace App\Enums;

/**
 * Server-authoritative trip state machine.
 */
enum TripStatus: string
{
    case DriverAssigned = 'driver_assigned';
    case DriverEnRoute = 'driver_en_route';
    case Arrived = 'arrived';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CancelledByCustomer = 'cancelled_by_customer';
    case CancelledByDriver = 'cancelled_by_driver';
    case CancelledByAdmin = 'cancelled_by_admin';
    case SafetyHold = 'safety_hold';
    case Disputed = 'disputed';

    /** @return array<string, array<int, self>> */
    public static function transitions(): array
    {
        return [
            self::DriverAssigned->value => [self::DriverEnRoute, self::Arrived, self::CancelledByCustomer, self::CancelledByDriver, self::CancelledByAdmin, self::SafetyHold],
            self::DriverEnRoute->value => [self::Arrived, self::CancelledByCustomer, self::CancelledByDriver, self::CancelledByAdmin, self::SafetyHold],
            self::Arrived->value => [self::InProgress, self::CancelledByCustomer, self::CancelledByDriver, self::CancelledByAdmin, self::SafetyHold],
            self::InProgress->value => [self::Completed, self::CancelledByAdmin, self::SafetyHold],
            self::SafetyHold->value => [self::InProgress, self::CancelledByAdmin, self::Completed],
            self::Completed->value => [self::Disputed],
            self::Disputed->value => [self::Completed],
            self::CancelledByCustomer->value => [],
            self::CancelledByDriver->value => [],
            self::CancelledByAdmin->value => [],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, self::transitions()[$this->value] ?? [], true);
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** @return array<int, self> */
    public static function active(): array
    {
        return [self::DriverAssigned, self::DriverEnRoute, self::Arrived, self::InProgress];
    }

    public static function activeValues(): array
    {
        return array_map(fn ($s) => $s->value, self::active());
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::CancelledByCustomer, self::CancelledByDriver, self::CancelledByAdmin], true);
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
