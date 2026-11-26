<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /me/ratings – ratings the user has received (as rider or driver). */
class RatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('as') === 'driver' ? 'customer' : ($request->query('as') === 'customer' ? 'driver' : null);
        $base = Rating::query()->where('ratee_id', $user->id)->where('moderation_status', '!=', 'hidden')
            ->when($role, fn ($q) => $q->where('rater_role', $role));

        $breakdown = (clone $base)->selectRaw('score, COUNT(*) c')->groupBy('score')->pluck('c', 'score');
        $count = (int) $breakdown->sum();
        $avg = $count ? round((clone $base)->avg('score'), 2) : null;

        $items = (clone $base)->with('rater:id,name')->latest('id')->limit(50)->get()->map(fn (Rating $r) => [
            'id' => $r->id,
            'score' => (int) $r->score,
            'tags' => $r->tags ?? [],
            'comment' => $r->comment,
            'from' => $r->rater_role, // customer | driver
            'from_name' => $r->rater?->name ? strtok($r->rater->name, ' ') : null, // first name only
            'created_at' => $r->created_at?->toIso8601String(),
        ]);

        return ApiResponse::ok([
            'average' => $avg,
            'count' => $count,
            'breakdown' => collect(range(5, 1))->mapWithKeys(fn ($s) => [(string) $s => (int) ($breakdown[$s] ?? 0)]),
            'items' => $items,
        ]);
    }
}
