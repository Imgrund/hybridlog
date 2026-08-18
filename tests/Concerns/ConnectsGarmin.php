<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Marks a tenant as having signed in to Garmin, the way login.py leaves
 * it: one row in garmin_private.garmin_session, keyed by user id.
 *
 * The trigger refuses to start a fetch for anybody without one, so every
 * test that expects a fetch to start first says who is connected. The
 * row lives outside the transaction the suite rolls back;
 * CreatesMirrorSchema clears the table before every test.
 */
trait ConnectsGarmin
{
    protected function connectGarmin(User $user): void
    {
        Mirror::ensure($user->id);
        Mirror::unpin();

        DB::connection('garmin')->table('garmin_private.garmin_session')->insert([
            'id' => $user->id,
            'tokens' => str_repeat('t', 64),
            'updated_at' => now()->format('Y-m-d\TH:i:s'),
        ]);
    }
}
