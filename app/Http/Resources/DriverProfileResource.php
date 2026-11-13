<?php

namespace App\Http\Resources;

use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Driver's own application view. Internal reviewer notes are never exposed. */
class DriverProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $p->loadMissing('documents', 'primaryVehicle.category');
        $docs = $p->latestDocuments();
        $cnic = (string) $p->cnic_number;
        $lic = (string) $p->license_number;
        $acct = (string) $p->payout_account_number;

        return [
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'reason' => $p->user_facing_reason,
            'full_name' => $p->full_name,
            'cnic_number_masked' => $cnic ? str_repeat('•', max(0, strlen($cnic) - 4)).substr($cnic, -4) : null,
            'date_of_birth' => $p->date_of_birth?->toDateString(),
            'license_number_masked' => $lic ? str_repeat('•', max(0, strlen($lic) - 4)).substr($lic, -4) : null,
            'license_expiry' => $p->license_expiry?->toDateString(),
            'address' => $p->address,
            'emergency_contact_name' => $p->emergency_contact_name,
            'has_emergency_contact_phone' => filled($p->emergency_contact_phone),
            'is_online' => $p->is_online,
            'rating' => (float) $p->rating_avg,
            'completed_trips' => $p->completed_trips,
            'payout' => [
                'method' => $p->payout_method,
                'account_title' => $p->payout_account_title,
                'account_number_masked' => $acct ? str_repeat('•', max(0, strlen($acct) - 4)).substr($acct, -4) : null,
            ],
            'vehicle' => $p->primaryVehicle ? new VehicleResource($p->primaryVehicle) : null,
            'documents' => DriverDocumentResource::collection($docs),
            'required_documents' => DriverProfile::REQUIRED_DOCUMENTS,
            'optional_documents' => DriverProfile::OPTIONAL_DOCUMENTS,
            'compliance_issues' => $p->complianceIssues(),
            'can_edit' => $p->status->canEditApplication(),
            'submitted_at' => $p->submitted_at?->toIso8601String(),
            'approved_at' => $p->approved_at?->toIso8601String(),
        ];
    }
}
