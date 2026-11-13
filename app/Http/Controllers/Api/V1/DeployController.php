<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Deploy\DeployTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Called by the CI/CD pipeline right after it promotes a release, so deploys start immediately. */
class DeployController extends Controller
{
    public function notify(DeployTrigger $trigger): JsonResponse
    {
        abort_unless(config('femalerider.shared_hosting'), 404);

        // Debounce: at most one launch every 15 s (pull-deploy.sh also holds its own lock).
        $launched = Cache::add('deploy:notify', now()->timestamp, 15) && $trigger->launch();

        return response()->json(['queued' => $launched], 202);
    }
}
