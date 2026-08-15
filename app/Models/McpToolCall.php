<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per MCP tool invocation, written by App\Mcp\LoggedTool.
 *
 * This is the only record of how the AI connectors actually use the server:
 * neither laravel/mcp nor the HTTP request log knows which tool ran, with which
 * arguments, or whether it worked.
 *
 * Rows carry free text about the athlete's health (SQL, symptom wording),
 * so they get a lifecycle: telemetry answers "how is the connector used
 * lately", and lately is not forever. model:prune runs daily on the
 * scheduler.
 */
class McpToolCall extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Days a call record is kept before the daily prune removes it. */
    public const KEEP_DAYS = 90;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    protected $fillable = [
        'user_id', 'tool', 'arguments', 'transport', 'client', 'session_id', 'duration_ms', 'ok', 'error',
    ];

    /** Only this user's calls; every read goes through it. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    protected $casts = [
        'arguments' => 'array',
        'ok' => 'boolean',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];
}
