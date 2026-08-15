<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The reader behind this dashboard: the interface language and the sky
 * they train under, both properties of the reader rather than of the
 * deployment.
 *
 * Everything else about the athlete comes from the watch and is never
 * duplicated here; the deeper questions live in the chat, which reads
 * the mirror itself.
 */
class AthleteProfile extends Model
{
    /** The languages the interface is actually translated into. */
    public const LOCALES = ['en', 'de'];

    protected $fillable = ['user_id', 'locale', 'latitude', 'longitude', 'location_name'];

    protected function casts(): array
    {
        // Cast, because a decimal column comes back from Postgres as a
        // string and the fetcher is handed these as numbers.
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** This reader's row, one per user (unique user_id). */
    public static function for(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Whether this athlete has a sky of their own.
     *
     * Both halves or neither: a latitude without a longitude is not a
     * place, and passing half of one to the fetcher would silently move
     * the weather to the Gulf of Guinea.
     */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
