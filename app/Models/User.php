<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function athleteProfile(): HasOne
    {
        return $this->hasOne(AthleteProfile::class);
    }

    public function connectorSettings(): HasOne
    {
        return $this->hasOne(ConnectorSettings::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function healthAlerts(): HasMany
    {
        return $this->hasMany(HealthAlert::class);
    }

    /**
     * The installation owner: the oldest admin account, which is exactly
     * the tenant all pre-tenancy rows were backfilled to.
     *
     * Consulted only by the one path that has no authenticated user and
     * no token to name one: the local stdio MCP transport, which is one
     * person at a keyboard. Authenticated paths never ask for it.
     */
    public static function owner(): ?self
    {
        return static::query()->where('is_admin', true)->orderBy('id')->first();
    }

    /**
     * Everybody a push could actually reach, oldest account first.
     *
     * What the four scheduled senders iterate. Filtered on having a
     * subscription rather than on merely existing, because composing a
     * briefing means reading a mirror and running the load models over
     * it, and doing that for an account with no device to ring is work
     * nobody asked for. An athlete who allows notifications later is in
     * the list from the next run on.
     *
     * @return Collection<int, self>
     */
    public static function reachableByPush(): Collection
    {
        return static::query()->whereHas('pushSubscriptions')->orderBy('id')->get();
    }
}
