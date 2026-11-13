<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DriverDocument;
use App\Models\EligibilityVerification;
use App\Models\IncidentEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams private identity documents / evidence to authorised admins only.
 * Every view is written to the audit log.
 */
class SecureFileController extends Controller
{
    public function show(string $type, int $id, ?string $variant = null): StreamedResponse
    {
        $admin = auth('admin')->user();

        [$model, $path, $permission] = match ($type) {
            'driver-document' => [$m = DriverDocument::query()->findOrFail($id), $m->file_path, 'documents.view'],
            'eligibility' => [$m = EligibilityVerification::query()->findOrFail($id), match ($variant) {
                'back' => $m->document_back_path, 'selfie' => $m->selfie_path, default => $m->document_path,
            }, 'eligibility.review'],
            'evidence' => [$m = IncidentEvidence::query()->findOrFail($id), $m->file_path, 'safety.evidence.view'],
            default => abort(404),
        };

        abort_unless($admin && $admin->can($permission), 403);
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        AuditLog::record('sensitive_file.viewed', $model, [], [], ['type' => $type, 'variant' => $variant]);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }
}
