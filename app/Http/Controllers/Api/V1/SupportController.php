<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\Trip;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok(SupportTicketResource::collection($request->user()->supportTickets()->with('trip')->latest('updated_at')->paginate(20)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['general', 'payment', 'lost_item', 'complaint', 'account', 'driver', 'safety', 'refund', 'fare_dispute'])],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:5', 'max:3000'],
            'trip_id' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ]);
        $user = $request->user();
        $trip = null;
        if (! empty($data['trip_id'])) {
            $trip = Trip::query()->where('uuid', $data['trip_id'])->first();
            if (! $trip || ! $trip->involves($user)) {
                return ApiResponse::error('Trip not found.', 'not_found', 404);
            }
        }
        if ($user->supportTickets()->where('created_at', '>=', now()->subHour())->count() >= 5) {
            return ApiResponse::error('You have opened several tickets recently. Please wait for a reply.', 'rate_limited', 429);
        }
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id, 'trip_id' => $trip?->id, 'category' => $data['category'], 'subject' => $data['subject'],
            'priority' => in_array($data['category'], ['safety', 'lost_item'], true) ? 'high' : 'normal', 'status' => 'open',
        ]);
        $ticket->messages()->create([
            'sender_type' => 'user', 'sender_id' => $user->id, 'body' => $data['message'],
            'attachment_path' => $request->file('attachment')?->store("support/{$ticket->reference}", 'local'),
        ]);

        return ApiResponse::created(new SupportTicketResource($ticket->load('messages', 'trip')), 'Ticket created. Reference '.$ticket->reference);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return ApiResponse::error('Ticket not found.', 'not_found', 404);
        }

        return ApiResponse::ok(new SupportTicketResource($ticket->load('messages', 'trip')));
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $request->user()->id) {
            return ApiResponse::error('Ticket not found.', 'not_found', 404);
        }
        $data = $request->validate(['message' => ['required', 'string', 'max:3000'], 'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192']]);
        if ($ticket->status === 'closed') {
            return ApiResponse::error('This ticket is closed. Please open a new one.', 'ticket_closed', 409);
        }
        $ticket->messages()->create([
            'sender_type' => 'user', 'sender_id' => $request->user()->id, 'body' => $data['message'],
            'attachment_path' => $request->file('attachment')?->store("support/{$ticket->reference}", 'local'),
        ]);
        $ticket->update(['status' => 'open']);

        return ApiResponse::ok(new SupportTicketResource($ticket->load('messages', 'trip')), 'Reply sent.');
    }
}
