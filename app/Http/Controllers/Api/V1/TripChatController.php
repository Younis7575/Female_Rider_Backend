<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\Chat\TripChatService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer ⇄ driver chat for an assigned trip. */
class TripChatController extends Controller
{
    public function __construct(private TripChatService $chat) {}

    public function index(Request $request, Trip $trip): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $user = $request->user();
        $messages = $this->chat->messages($user, $trip, $data['after'] ?? null, $data['limit'] ?? 100);

        return ApiResponse::ok([
            'trip_id' => $trip->uuid,
            'viewer_role' => $trip->roleOf($user),
            'can_send' => $this->chat->canSend($trip),
            'max_length' => TripChatService::MAX_LENGTH,
            'unread' => $this->chat->unreadCount($user, $trip),
            'messages' => $messages->map->toPayload()->values(),
        ]);
    }

    public function store(Request $request, Trip $trip): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(TripChatService::MAX_LENGTH * 2)],
            'client_id' => ['nullable', 'string', 'max:64'],
        ]);
        $message = $this->chat->send($request->user(), $trip, $data['body'], $data['client_id'] ?? null);

        return ApiResponse::ok($message->toPayload(), 'Sent.', 201);
    }

    public function read(Request $request, Trip $trip): JsonResponse
    {
        return ApiResponse::ok(['marked' => $this->chat->markRead($request->user(), $trip)]);
    }
}
