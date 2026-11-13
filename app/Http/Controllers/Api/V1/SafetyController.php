<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\Trip;
use App\Services\Safety\IncidentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SafetyController extends Controller
{
    public function __construct(private IncidentService $incidents) {}

    public function sos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trip_id' => ['nullable', 'string'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $trip = ! empty($data['trip_id']) ? Trip::query()->where('uuid', $data['trip_id'])->first() : $request->user()->activeTrip();
        $incident = $this->incidents->sos($request->user(), $trip, $data['lat'] ?? null, $data['lng'] ?? null, $data['note'] ?? null);

        return ApiResponse::created([
            'incident' => new IncidentResource($incident),
            'emergency_number' => $trip?->serviceZone?->city?->emergency_number ?? '15',
            'support_hours' => config('femalerider.support.hours'),
        ], 'SOS alert sent to FemaleRider safety team.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trip_id' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['safety_report', 'harassment', 'unsafe_driving', 'accident', 'vehicle_mismatch', 'other'])],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,pdf,mp4,m4a', 'max:20480'],
        ]);
        $incident = $this->incidents->report($request->user(), $data, $request->file('evidence', []));

        return ApiResponse::created(new IncidentResource($incident), 'Report received. Our safety team will review it confidentially.');
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok(IncidentResource::collection(Incident::query()->with('trip')->where('reporter_id', $request->user()->id)->latest('id')->paginate(20)));
    }
}
