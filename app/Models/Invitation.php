<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A standing permission for one address to become one account.
 *
 * See the migration for why this exists and why it is not a sign-up.
 * The short of it: the owner issues one, the invited person spends it,
 * and nobody else can do either.
 */
class Invitation extends Model
{
    protected $fillable = ['email', 'name', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Issue one, replacing whatever stood for this address before.
     *
     * Reissuing rather than refusing is deliberate: the common reason to
     * invite the same person twice is that the first link never arrived
     * or has run out, and the old one dying in the process is the point.
     *
     * @return array{0: self, 1: string} the invitation and its token,
     *                                   which is returned here and never
     *                                   again
     */
    public static function issue(string $email, ?string $name, int $days): array
    {
        $token = Str::random(48);

        static::query()->where('email', $email)->delete();

        $invitation = static::query()->create([
            'email' => $email,
            'name' => $name,
            'token_hash' => static::hashOf($token),
            'expires_at' => Carbon::now()->addDays($days),
        ]);

        return [$invitation, $token];
    }

    /** The invitation a token opens, if it still opens anything. */
    public static function findUsable(string $token): ?self
    {
        $invitation = static::query()->where('token_hash', static::hashOf($token))->first();

        return $invitation?->isUsable() === true ? $invitation : null;
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * Not a password hash, on purpose.
     *
     * bcrypt cannot be searched by, and its slowness buys nothing
     * against forty-eight characters of randomness: there is no
     * dictionary of those to run. What is wanted here is only that the
     * table stops being a list of working links if it is ever read.
     */
    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
