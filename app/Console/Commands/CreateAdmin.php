<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Creates (or re-activates) an admin-panel account. The password is typed by the
 * operator at a hidden prompt and never passed on the command line or logged.
 */
class CreateAdmin extends Command
{
    protected $signature = 'fr:create-admin {email} {--name=} {--role=super_admin}';

    protected $description = 'Create an admin panel user (prompts for the password)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $role = $this->option('role');
        if (! Role::query()->where('name', $role)->where('guard_name', 'admin')->exists()) {
            $this->error("Unknown role \"{$role}\". Run the RolesAndPermissionsSeeder first.");

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 12 chars, mixed case, number, symbol)');
        $confirm = $this->secret('Repeat password');
        $v = Validator::make(['email' => $email, 'password' => $password, 'password_confirmation' => $confirm], [
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $admin = AdminUser::query()->firstOrNew(['email' => $email]);
        $admin->fill(['name' => $this->option('name') ?: ($admin->name ?? 'Administrator'), 'password' => $password, 'is_active' => true])->save();
        $admin->syncRoles([$role]);

        $this->info("Admin {$email} is ready with role {$role}. Sign in at ".url('/admin').' and set up two-factor authentication.');

        return self::SUCCESS;
    }
}
