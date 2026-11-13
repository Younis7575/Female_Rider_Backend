<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Driver\EligibilityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VerificationController extends Controller
{
    public function __construct(private EligibilityService $eligibility) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $user->latestEligibility;

        return ApiResponse::ok([
            'status' => $user->eligibility_status->value,
            'label' => $user->eligibility_status->label(),
            'reason' => $v?->user_facing_reason,
            'document_type' => $v?->document_type,
            'submitted_at' => $v?->submitted_at?->toIso8601String(),
            'reviewed_at' => $v?->reviewed_at?->toIso8601String(),
            'can_submit' => $user->eligibility_status->canSubmit(),
            'can_appeal' => $user->eligibility_status->canAppeal(),
            'policy' => [
                'version' => config('femalerider.eligibility.policy_version'),
                'accepted_documents' => config('femalerider.eligibility.accepted_documents'),
                'require_selfie' => config('femalerider.eligibility.require_selfie'),
                'summary' => 'FemaleRider is a women-only community. A trained reviewer checks the document you submit; no automated gender detection is used. Documents are stored privately, accessed only by authorised reviewers, and every access is logged. You can appeal any decision.',
            ],
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(config('femalerider.eligibility.accepted_documents'))],
            'document_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
            'document_back' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
            'selfie' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:8192'],
            'declaration' => ['required', 'accepted'],
            'consent' => ['required', 'accepted'],
        ]);
        $v = $this->eligibility->submit(
            $request->user(), ['document_type' => $data['document_type'], 'declaration' => 'I confirm I meet the FemaleRider women-only eligibility policy.'],
            $request->file('document_front'), $request->file('document_back'), $request->file('selfie'), $request->ip(),
        );

        return ApiResponse::created(['status' => $v->status->value], 'Submitted for review. We usually respond within 24 hours.');
    }

    public function appeal(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $v = $this->eligibility->appeal($request->user(), $data['reason']);

        return ApiResponse::ok(['status' => $v->status->value], 'Appeal submitted.');
    }
}
