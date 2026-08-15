<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of feature toggles per user, controlling what AI connectors
 * may access on that user's behalf. Enforced inside the MCP tools, so
 * they apply to every transport.
 */
class ConnectorSettings extends Model
{
    /** The tables share_body_metrics covers, hidden and refused as one set. */
    public const BODY_METRIC_TABLES = ['body_comp', 'fitness_age'];

    protected $fillable = ['user_id', 'share_health_data', 'share_body_metrics', 'allow_symptoms', 'allow_refresh', 'allow_feedback'];

    /**
     * Mirror of the column defaults: a row freshly created by for()
     * would otherwise expose null for every flag until re-read, and the
     * tools' bool-typed permission checks would throw instead of allowing.
     */
    protected $attributes = [
        'share_health_data' => true,
        'share_body_metrics' => true,
        'allow_symptoms' => true,
        'allow_refresh' => true,
        'allow_feedback' => true,
    ];

    protected $casts = [
        'share_health_data' => 'boolean',
        'share_body_metrics' => 'boolean',
        'allow_symptoms' => 'boolean',
        'allow_refresh' => 'boolean',
        'allow_feedback' => 'boolean',
    ];

    /** This user's switches, one row per user (unique user_id). */
    public static function for(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * The switches as the athlete reads them on /connect, in the order
     * they appear there.
     *
     * One source for two audiences: the page renders label and hint, and
     * a refused tool quotes the same label back at the AI. Kept together
     * because a tool that names a switch the page does not have sends the
     * reader looking for something that is not there. A method rather
     * than a constant because the words are translated.
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function permissions(): array
    {
        return [
            'share_health_data' => [
                'label' => __('Read health data'),
                'hint' => __('Training, sleep, HRV, readiness: without this switch the connection is mute.'),
            ],
            'share_body_metrics' => [
                'label' => __('Read body metrics'),
                'hint' => __('Weight, body composition, fitness age.'),
            ],
            'allow_symptoms' => [
                'label' => __('Log how you feel'),
                'hint' => __('Record symptoms mentioned in passing; the AI never asks for them.'),
            ],
            'allow_refresh' => [
                'label' => __('Start a fetch'),
                'hint' => __('The AI may trigger a Garmin fetch when the data is too old for a question about right now.'),
            ],
            'allow_feedback' => [
                'label' => __('Process feedback'),
                'hint' => __('Turn your chat feedback into standing guidelines for the connector.'),
            ],
        ];
    }

    /** The switch label a refused tool quotes back. */
    public static function label(string $field): string
    {
        return self::permissions()[$field]['label'] ?? $field;
    }
}
