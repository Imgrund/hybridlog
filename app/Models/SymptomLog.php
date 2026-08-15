<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One self-reported symptom ("kratziger Hals"), captured strictly in
 * passing: the AI logs it only when the user volunteers it and never
 * asks for it. Shown nowhere except as context on the illness
 * early-warning banner, and only ever to the user who reported it.
 */
class SymptomLog extends Model
{
    /** Reports older than this stop speaking about today's training. */
    private const ACTIVE_DAYS = 14;

    protected $table = 'symptom_log';

    protected $fillable = ['user_id', 'date', 'logged_at', 'symptom', 'severity', 'note', 'body_zone', 'side'];

    protected $casts = [
        'logged_at' => 'datetime',
        'severity' => 'integer',
    ];

    /** Only this user's reports; every read goes through it. */
    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Every region the log accepts: the muscle zones the map draws, plus
     * the joint regions an athlete actually names. Kept as one list so the
     * MCP tool and the validation cannot drift apart.
     *
     * @return list<string>
     */
    public static function regions(): array
    {
        return array_values(array_unique(array_merge(
            config('muscle_map.zones'),
            array_keys(config('muscle_map.symptom_regions')),
        )));
    }

    /** The muscle zone a reported region is drawn on, approximated. */
    public static function zoneFor(?string $region): ?string
    {
        if ($region === null) {
            return null;
        }

        return config("muscle_map.symptom_regions.{$region}")
            ?? (in_array($region, config('muscle_map.zones'), true) ? $region : null);
    }

    /**
     * The user's recent reports per muscle zone, worst first. This is the
     * only thing the body map overlays: a symptom is what the athlete felt,
     * so it is drawn as a marker beside the load, never mixed into it.
     *
     * @return array<string, array{symptom: string, severity: int|null, date: string, side: string|null, region: string}>
     */
    public static function byZone(User $user): array
    {
        $reports = static::query()
            ->for($user)
            ->whereNotNull('body_zone')
            ->where('date', '>=', now()->subDays(self::ACTIVE_DAYS)->toDateString())
            ->orderByDesc('date')
            ->get();

        $byZone = [];
        foreach ($reports as $report) {
            $zone = static::zoneFor($report->body_zone);
            if ($zone === null) {
                continue;
            }
            // Newest wins, and a more severe report of the same day wins
            // over a milder one: the map has room for one marker per zone.
            $held = $byZone[$zone] ?? null;
            if ($held !== null && ($held['date'] > $report->date
                || ($held['date'] === $report->date && ($held['severity'] ?? 0) >= ($report->severity ?? 0)))) {
                continue;
            }
            $byZone[$zone] = [
                'symptom' => $report->symptom,
                'severity' => $report->severity,
                'date' => $report->date,
                'side' => $report->side,
                'region' => $report->body_zone,
            ];
        }

        return $byZone;
    }
}
