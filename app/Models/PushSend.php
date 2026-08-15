<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per user and companion push that reached a device (morning
 * briefing, evening nudge, weekly reminder): the once-per-day ledger
 * behind the three scheduled push commands, and the freshness anchor the
 * push feed reads. Append-only, so there is no updated_at.
 */
class PushSend extends Model
{
    public const UPDATED_AT = null;

    public const KIND_BRIEFING = 'briefing';

    public const KIND_NUDGE = 'nudge';

    public const KIND_WEEKLY = 'weekly';

    protected $fillable = ['user_id', 'kind', 'date', 'sent_at', 'devices'];

    protected $casts = [
        'date' => 'date',
        'sent_at' => 'datetime',
        'devices' => 'integer',
    ];

    /** Only this user's sends; every read goes through it. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** The user's row of a kind today, which is also "already sent?". */
    public static function sentToday(string $kind, User $user): ?self
    {
        return static::query()->for($user)
            ->where('kind', $kind)->whereDate('date', now()->toDateString())->first();
    }

    public static function record(string $kind, int $devices, User $user): self
    {
        return static::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'date' => now()->toDateString(),
            'sent_at' => now(),
            'devices' => $devices,
        ]);
    }
}
