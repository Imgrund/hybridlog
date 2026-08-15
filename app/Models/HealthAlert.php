<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per user, fired alert rule and day: the dedupe ledger behind
 * app:health-alerts. Append-only, so there is no updated_at.
 */
class HealthAlert extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'rule', 'date', 'message'];

    /** Only this user's alerts; every read goes through it. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
