<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Least-privilege resource authorization using Spatie permissions on the admin guard.
 * Resources declare: protected static string $viewPermission; protected static ?string $managePermission;
 */
trait AuthorizesWithPermission
{
    protected static function admin()
    {
        return Auth::guard('admin')->user();
    }

    public static function canViewAny(): bool
    {
        return (bool) static::admin()?->can(static::$viewPermission);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canManage(): bool
    {
        $perm = static::$managePermission ?? null;

        return $perm !== null && (bool) static::admin()?->can($perm);
    }

    public static function canCreate(): bool
    {
        return static::canManage() && (static::$allowCreate ?? true);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage() && (static::$allowEdit ?? true);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage() && (static::$allowDelete ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::canManage() && (static::$allowDelete ?? false);
    }
}
