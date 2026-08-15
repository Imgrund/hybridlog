<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The app timezone moved from UTC to Europe/Berlin. Rows written before that
 * hold UTC wall-clock values that Eloquent now reads as local time, which
 * would date every one of them two hours early: a token would expire before
 * it should, and the header would report a stale fetch time.
 *
 * Converting rather than adding a fixed offset, so a row written in winter
 * shifts by one hour and a row written in summer by two.
 */
return new class extends Migration
{
    /** Tables this app owns, with the instant-valued columns in each. */
    private const COLUMNS = [
        'insights' => ['created_at', 'updated_at'],
        'dashboard_cards' => ['created_at', 'updated_at'],
        'mcp_tool_calls' => ['created_at'],
        // Not the mirror: fetch_log already records local wall-clock time,
        // which is half the reason for this move in the first place.
        'connector_settings' => ['created_at', 'updated_at'],
        'athlete_profiles' => ['created_at', 'updated_at'],
        'nutrition_log' => ['created_at', 'updated_at', 'logged_at'],
        'oauth_clients' => ['created_at', 'updated_at'],
        'oauth_access_tokens' => ['created_at', 'updated_at', 'expires_at'],
        'oauth_refresh_tokens' => ['expires_at'],
        'oauth_auth_codes' => ['expires_at'],
        'oauth_device_codes' => ['user_approved_at', 'last_polled_at', 'expires_at'],
        'users' => ['created_at', 'updated_at', 'email_verified_at'],
        'sessions' => [],
    ];

    public function up(): void
    {
        $this->shift('UTC', 'Europe/Berlin');
    }

    public function down(): void
    {
        $this->shift('Europe/Berlin', 'UTC');
    }

    private function shift(string $from, string $to): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            // The column list is written by hand, so a schema that drifted
            // away from it must be skipped rather than crash the migration.
            $present = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));
            if ($present === []) {
                continue;
            }

            foreach (DB::table($table)->select(array_merge(['id'], $present))->get() as $row) {
                $updates = [];
                foreach ($present as $column) {
                    if ($row->{$column} === null) {
                        continue;
                    }
                    $updates[$column] = Carbon::parse($row->{$column}, $from)
                        ->setTimezone($to)
                        ->format('Y-m-d H:i:s');
                }
                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        }
    }
};
