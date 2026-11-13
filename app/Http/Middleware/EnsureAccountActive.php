<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks suspended/deleted accounts from using the API (tokens remain revocable). */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && in_array($user->account_status, ['suspended', 'deleted'], true)) {
            return ApiResponse::error(
                $user->account_status === 'suspended' ? 'Your account is suspended. '.($user->suspension_reason ?? 'Please contact support.') : 'This account has been deleted.',
                'account_'.$user->account_status,
                403,
            );
        }

        return $next($request);
    }
}
