<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->uuid, 'amount' => $this->amount, 'currency' => $this->currency, 'method' => $this->method, 'account_title' => $this->account_title, 'account_number_masked' => $this->account_number_masked, 'status' => $this->status, 'failure_reason' => $this->failure_reason, 'paid_at' => $this->paid_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String()];
    }
}
