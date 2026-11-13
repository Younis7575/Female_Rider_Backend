<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'title' => $this->title, 'body' => $this->body, 'data' => $this->data, 'read' => (bool) $this->read_at, 'created_at' => $this->created_at?->toIso8601String()];
    }
}
