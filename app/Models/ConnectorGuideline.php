<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A standing behaviour rule the athlete gave the connector as feedback.
 *
 * Written by give-feedback-tool, appended to the MCP instructions on
 * every handshake, retired from the chat (retired_at) or deleted for
 * good on /connect. source_feedback keeps the user's own words next to
 * the distilled rule, so a guideline can always be traced back to what
 * was actually said. Guidelines are personal: each user's connector
 * hears only their own.
 */
class ConnectorGuideline extends Model
{
    protected $fillable = ['user_id', 'guideline', 'source_feedback', 'retired_at'];

    protected $casts = ['retired_at' => 'datetime'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /** Only this user's guidelines; every read goes through it. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * The block the server appends to its instructions for one user,
     * empty when there is nothing to say (or nobody to say it to).
     * Oldest first, so the list reads in the order the rules were given;
     * the [gN] marker is the handle the model needs to retire a rule the
     * user takes back.
     */
    public static function instructionsBlock(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        $active = static::query()->for($user)->active()->orderBy('id')->get();

        if ($active->isEmpty()) {
            return '';
        }

        return "\n\nStanding guidelines from the athlete's own feedback. Follow them; the [gN] "
            ."marker is the id give-feedback-tool's retire_guideline_id refers to:\n"
            .$active->map(fn (self $guideline): string => '- [g'.$guideline->id.'] '.$guideline->guideline)->implode("\n");
    }
}
