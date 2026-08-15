<?php

declare(strict_types=1);

namespace App\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wakes the subscribed devices. Carries no message.
 *
 * A push can hold an encrypted payload, and this one deliberately does
 * not: the service worker is woken empty and fetches the wording from
 * this dashboard itself. Two things fall out of that. The push services
 * of Google, Mozilla and Apple never hold a body about somebody's
 * stress at all, not even an encrypted one. And what the notification
 * says is decided at the moment it is shown rather than at the moment it
 * was queued, so a window answered in the meantime cannot ring.
 *
 * It also means there is no ECDH, no HKDF and no AES-GCM here, which is
 * the entire reason a web-push library would be needed. What is left is
 * a signed header (see Vapid) and an empty POST.
 */
class WebPush
{
    /**
     * How long a push service holds a message for a device that is off.
     *
     * Four hours, because the question this carries is "what was that?"
     * and the answer fades: a buzz the next morning about yesterday
     * afternoon is the thing the notification exists to avoid. The window
     * stays on the dashboard either way.
     */
    private const TTL_SECONDS = 4 * 3600;

    public function __construct(private Vapid $vapid) {}

    /**
     * Wakes the given devices, and returns how many took it. Whose
     * devices ring is the caller's decision: this class only carries
     * the knock, never the address book.
     *
     * A subscription the push service has retired is deleted rather than
     * reported: browsers drop them on their own, at reinstall or when a
     * permission is revoked elsewhere, and the row is then an address
     * nobody lives at. Any other failure is logged and left alone, since
     * a service having a bad minute is not a reason to lose the device.
     *
     * @param  iterable<PushSubscription>  $subscriptions
     */
    public function wakeAll(iterable $subscriptions, string $topic): int
    {
        if (! $this->vapid->configured()) {
            return 0;
        }

        $woken = 0;

        foreach ($subscriptions as $subscription) {
            if ($this->wake($subscription, $topic)) {
                $woken++;
            }
        }

        return $woken;
    }

    /**
     * Within one topic a newer push replaces an older one still queued
     * for a device that is off, instead of stacking up (RFC 8030). Each
     * kind of push names its own topic so a briefing does not swallow a
     * queued health alert: the two are different news.
     */
    public function wake(PushSubscription $subscription, string $topic): bool
    {
        if (! $this->vapid->configured()) {
            return false;
        }

        $response = Http::withHeaders([
            'Authorization' => $this->vapid->authorization($subscription->endpoint),
            'TTL' => (string) self::TTL_SECONDS,
            'Topic' => $topic,
            // Without a body the header is still expected, and some
            // services answer 400 to a POST that omits it entirely.
            'Content-Length' => '0',
        ])->send('POST', $subscription->endpoint);

        if ($response->successful()) {
            $subscription->update(['last_push_at' => now()]);

            return true;
        }

        // 404 the subscription never existed, 410 it has been retired.
        // Either way this endpoint will never carry anything again.
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        Log::warning('push rejected', [
            'status' => $response->status(),
            // The endpoint is a per-device secret, so the log gets the
            // service it belongs to and not the address itself.
            'service' => parse_url($subscription->endpoint, PHP_URL_HOST),
        ]);

        return false;
    }
}
