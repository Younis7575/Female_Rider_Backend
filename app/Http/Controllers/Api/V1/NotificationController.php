<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNotificationResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->user()->appNotifications()->latest('id')->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'));

        return ApiResponse::ok(AppNotificationResource::collection($q->paginate(30)), null, 200, ['unread' => $request->user()->appNotifications()->whereNull('read_at')->count()]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $request->user()->appNotifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::ok(null);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::ok(null, 'All notifications marked as read.');
    }
}
