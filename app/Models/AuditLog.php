<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/** Append-only audit trail for privileged and sensitive actions. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'meta' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit logs are immutable.'));
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(string $action, ?Model $subject = null, array $before = [], array $after = [], array $meta = [], ?Model $actor = null): self
    {
        $actor ??= Auth::guard('admin')->user() ?? Auth::guard('sanctum')->user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return static::query()->create([
            'actor_type' => match (true) {
                $actor instanceof AdminUser => 'admin',
                $actor instanceof User => 'user',
                default => 'system',
            },
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'before' => $before ?: null,
            'after' => $after ?: null,
            'meta' => $meta ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 250) : null,
        ]);
    }

    public function actorName(): string
    {
        return match ($this->actor_type) {
            'admin' => AdminUser::find($this->actor_id)?->name ?? 'Admin #'.$this->actor_id,
            'user' => User::find($this->actor_id)?->displayName() ?? 'User #'.$this->actor_id,
            default => 'System',
        };
    }
}
