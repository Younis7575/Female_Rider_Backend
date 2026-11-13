<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboard.view', 'operations.view', 'reports.view', 'audit.view',
        'customers.view', 'customers.manage', 'eligibility.review',
        'drivers.view', 'drivers.review', 'drivers.manage', 'documents.view',
        'rides.view', 'rides.manage',
        'finance.view', 'finance.refunds.approve', 'finance.payouts.approve',
        'safety.view', 'safety.manage', 'safety.evidence.view',
        'support.view', 'support.manage',
        'pricing.manage', 'zones.manage', 'promotions.manage', 'cms.manage', 'notifications.send',
        'admins.manage', 'settings.manage',
    ];

    public const ROLES = [
        'super_admin' => ['*'],
        'operations_manager' => ['dashboard.view', 'operations.view', 'reports.view', 'customers.view', 'drivers.view', 'drivers.manage', 'rides.view', 'rides.manage', 'zones.manage', 'pricing.manage', 'support.view', 'safety.view', 'notifications.send', 'promotions.manage'],
        'driver_verification_officer' => ['dashboard.view', 'customers.view', 'eligibility.review', 'drivers.view', 'drivers.review', 'documents.view'],
        'customer_support' => ['dashboard.view', 'customers.view', 'drivers.view', 'rides.view', 'support.view', 'support.manage', 'cms.manage'],
        'safety_officer' => ['dashboard.view', 'operations.view', 'customers.view', 'customers.manage', 'drivers.view', 'drivers.manage', 'rides.view', 'safety.view', 'safety.manage', 'safety.evidence.view'],
        'finance_manager' => ['dashboard.view', 'reports.view', 'rides.view', 'finance.view', 'finance.refunds.approve', 'finance.payouts.approve'],
        'analyst' => ['dashboard.view', 'reports.view', 'rides.view', 'finance.view', 'operations.view'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        foreach (self::ROLES as $role => $perms) {
            $r = Role::findOrCreate($role, 'admin');
            $r->syncPermissions($perms === ['*'] ? self::PERMISSIONS : $perms);
        }
    }
}
