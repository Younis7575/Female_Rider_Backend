<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->reference,
            'reference' => $this->reference,
            'category' => $this->category,
            'subject' => $this->subject,
            'priority' => $this->priority,
            'status' => $this->status,
            'trip_id' => $this->trip?->uuid,
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->where('is_internal', false)->map(fn ($m) => [
                'id' => $m->id,
                'from' => $m->sender_type === 'admin' ? 'support' : 'me',
                'body' => $m->body,
                'has_attachment' => filled($m->attachment_path),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
