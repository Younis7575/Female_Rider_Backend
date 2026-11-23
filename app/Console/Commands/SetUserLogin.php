<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Give an existing (e.g. QA / store-review) account an email + password so it can use the
 * email login. The password is typed at a hidden prompt and never echoed.
 */
class SetUserLogin extends Command
{
    protected $signature = 'femalerider:set-login {phone : E.164 phone, e.g. +923001110002} {email}';

    protected $description = 'Set the email and password (hidden prompt) of an existing user';

    public function handle(): int
    {
        $user = User::query()->where('phone', $this->argument('phone'))->first();
        if (! $user) {
            $this->error('No user with that phone.');

            return self::FAILURE;
        }
        $email = strtolower(trim($this->argument('email')));
        if (User::query()->where('email', $email)->where('id', '!=', $user->id)->exists()) {
            $this->error('That email belongs to another account.');

            return self::FAILURE;
        }
        $password = (string) $this->secret('New password (min 8, letters + numbers)');
        $v = Validator::make(['password' => $password], ['password' => [Password::min(8)->letters()->numbers()]]);
        if ($v->fails()) {
            $this->error($v->errors()->first('password'));

            return self::FAILURE;
        }
        $user->forceFill(['email' => $email, 'password' => $password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $this->info("OK: {$user->name} can now log in with {$email}.");

        return self::SUCCESS;
    }
}
