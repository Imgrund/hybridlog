<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's device that agreed to be woken for notifications.
 *
 * Created by the notifications page when the browser hands over a push
 * subscription, removed when the athlete turns it off or when the push
 * service says the subscription is gone. Nothing else reads this table:
 * the endpoint is a standing invitation to wake a phone.
 */
class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'device', 'last_push_at'];

    protected $hidden = ['endpoint', 'endpoint_hash'];

    protected $casts = [
        'last_push_at' => 'datetime',
    ];

    /**
     * Stores a subscription, or finds the row the same browser already has.
     *
     * A browser hands out the same endpoint for as long as the permission
     * stands, so subscribing twice from one device is the normal case: the
     * page does it on every load to notice a subscription the browser
     * dropped on its own.
     *
     * The endpoint identifies one physical browser, so its unique index
     * stays global and the row follows whoever subscribed it last: a
     * device rings for exactly one user at a time.
     */
    public static function remember(string $endpoint, ?string $device, User $user): self
    {
        return static::updateOrCreate(
            ['endpoint_hash' => static::hash($endpoint)],
            ['endpoint' => $endpoint, 'device' => $device, 'user_id' => $user->id],
        );
    }

    /** The user's own row for an endpoint; somebody else's is not found. */
    public static function forEndpoint(string $endpoint, User $user): ?self
    {
        return static::where('endpoint_hash', static::hash($endpoint))
            ->where('user_id', $user->id)->first();
    }

    /** What the unique index holds; see the migration for why. */
    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
