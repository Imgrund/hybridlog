<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Push\PushError;
use App\Push\Vapid;
use App\Push\WebPush;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The signature a push service checks, and what happens to a device that
 * is no longer there.
 *
 * The signing is written out in App\Push\Vapid rather than taken from a
 * library, so the tests do what the push service does: verify the token
 * against the public half, with OpenSSL rather than with the same code
 * that produced it.
 */
class PushSendTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /** A pair made once for the whole class: generating one costs real time. */
    private static array $keys;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$keys = Vapid::generate();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();

        // An installation that has been given a pair. The one test about
        // the other state takes this back out.
        config([
            'push.vapid.public_key' => self::$keys['public'],
            'push.vapid.private_key' => self::$keys['private'],
            'push.vapid.subject' => 'mailto:athlete@example.com',
        ]);
    }

    private function vapid(): Vapid
    {
        return new Vapid(self::$keys['public'], self::$keys['private'], 'mailto:athlete@example.com');
    }

    private function subscribed(string $endpoint = self::ENDPOINT): PushSubscription
    {
        return PushSubscription::remember($endpoint, 'iPhone', $this->athlete());
    }

    /** The three dot-separated pieces of the token in the header. */
    private function token(string $authorization): array
    {
        preg_match('/t=([^,]+)/', $authorization, $matches);
        $parts = explode('.', $matches[1] ?? '');

        return [
            'header' => json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true),
            'claims' => json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true),
            'signed' => $parts[0].'.'.$parts[1],
            'signature' => base64_decode(strtr($parts[2] ?? '', '-_', '+/')),
        ];
    }

    public function test_a_generated_pair_has_the_shape_the_browser_expects(): void
    {
        $public = base64_decode(strtr(self::$keys['public'], '-_', '+/'));
        $private = base64_decode(strtr(self::$keys['private'], '-_', '+/'));

        // 0x04 and two 32-byte coordinates: the uncompressed point form,
        // which is the only one applicationServerKey accepts.
        $this->assertSame(65, strlen($public));
        $this->assertSame("\x04", $public[0]);
        $this->assertSame(32, strlen($private));
        // base64url, so neither value needs escaping in an .env line.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', self::$keys['public']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', self::$keys['private']);
    }

    public function test_the_token_verifies_against_the_public_half(): void
    {
        // What a push service does with the header: rebuild the key from
        // the k= parameter and check the signature over the first two
        // pieces. Done here with OpenSSL's own verify, so a mistake in the
        // DER-to-raw conversion cannot pass by agreeing with itself.
        $header = $this->vapid()->authorization(self::ENDPOINT);
        $token = $this->token($header);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], $token['header']);
        $this->assertSame(64, strlen($token['signature']), 'ES256 signatures are two 32-byte halves');

        $verified = openssl_verify(
            $token['signed'],
            $this->derSignature($token['signature']),
            $this->publicPem(),
            OPENSSL_ALGO_SHA256
        );

        $this->assertSame(1, $verified);
    }

    public function test_the_token_is_addressed_to_the_service_it_is_sent_to(): void
    {
        // A token minted for one push service is rejected by every other
        // one, which is the point of the audience claim.
        Carbon::setTestNow('2026-07-29 14:00:00');

        $token = $this->token($this->vapid()->authorization('https://updates.push.services.mozilla.com/wpush/v2/xyz'));

        $this->assertSame('https://updates.push.services.mozilla.com', $token['claims']['aud']);
        $this->assertSame('mailto:athlete@example.com', $token['claims']['sub']);
        // Twelve hours is the most RFC 8292 allows.
        $this->assertSame(Carbon::parse('2026-07-30 02:00:00')->getTimestamp(), $token['claims']['exp']);
    }

    public function test_the_public_key_travels_in_the_header_next_to_the_token(): void
    {
        $this->assertStringContainsString(', k='.self::$keys['public'], $this->vapid()->authorization(self::ENDPOINT));
    }

    public function test_a_malformed_key_says_which_one_and_how_to_fix_it(): void
    {
        $vapid = new Vapid(self::$keys['public'], 'not-a-key', 'mailto:a@b.c');

        $this->expectException(PushError::class);
        $this->expectExceptionMessage('push:keys');

        $vapid->authorization(self::ENDPOINT);
    }

    public function test_a_push_carries_no_body(): void
    {
        // The whole reason there is no encryption here: nothing about the
        // athlete's stress reaches the push service, not even encrypted.
        // The service worker fetches the wording from this dashboard.
        Http::fake([self::ENDPOINT => Http::response('', 201)]);

        $this->assertTrue(app(WebPush::class)->wake($this->subscribed(), 'healthalert'));

        Http::assertSent(function (Request $request): bool {
            return $request->body() === ''
                && $request->hasHeader('TTL')
                // The topic the caller named, so one kind of push only ever
                // replaces a queued one of its own kind.
                && $request->hasHeader('Topic', 'healthalert')
                && str_starts_with($request->header('Authorization')[0], 'vapid t=');
        });
    }

    public function test_a_successful_push_is_stamped_on_the_device(): void
    {
        Carbon::setTestNow('2026-07-29 14:05:00');
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        $subscription = $this->subscribed();

        app(WebPush::class)->wake($subscription, 'briefing');

        $this->assertSame('2026-07-29 14:05:00', $subscription->fresh()->last_push_at->format('Y-m-d H:i:s'));
    }

    public function test_a_retired_subscription_is_deleted_rather_than_retried(): void
    {
        // Browsers drop subscriptions on their own, at a reinstall or when
        // the permission is revoked in the browser's own settings. The row
        // is then an address nobody lives at.
        Http::fake([self::ENDPOINT => Http::response('', 410)]);
        $subscription = $this->subscribed();

        $this->assertFalse(app(WebPush::class)->wake($subscription, 'briefing'));
        $this->assertNull(PushSubscription::find($subscription->id));
    }

    public function test_a_service_having_a_bad_minute_keeps_the_device(): void
    {
        // A 500 from a push service says nothing about the subscription,
        // and losing the phone over it would need a trip to the settings
        // to undo.
        Http::fake([self::ENDPOINT => Http::response('', 503)]);
        $subscription = $this->subscribed();

        $this->assertFalse(app(WebPush::class)->wake($subscription, 'briefing'));
        $this->assertNotNull(PushSubscription::find($subscription->id));
    }

    public function test_nothing_is_sent_without_a_key_pair(): void
    {
        // The state of a fresh installation: the feature is off, and off
        // means silent rather than an exception on every fetch.
        Http::fake();
        config(['push.vapid.public_key' => '', 'push.vapid.private_key' => '']);
        $this->subscribed();

        $this->assertSame(0, app(WebPush::class)->wakeAll(PushSubscription::all(), 'briefing'));

        Http::assertNothingSent();
    }

    public function test_every_subscribed_device_is_woken(): void
    {
        Http::fake([
            'https://phone.example/*' => Http::response('', 201),
            'https://laptop.example/*' => Http::response('', 201),
        ]);
        $this->subscribed('https://phone.example/p/1');
        $this->subscribed('https://laptop.example/p/2');

        $this->assertSame(2, app(WebPush::class)->wakeAll(PushSubscription::all(), 'briefing'));
    }

    public function test_the_same_browser_subscribing_twice_stays_one_device(): void
    {
        // The notifications page subscribes on every load, to notice a
        // subscription the browser dropped on its own. That must not pile
        // up a row per visit.
        $first = $this->subscribed();
        $second = PushSubscription::remember(self::ENDPOINT, 'iPhone', $this->athlete());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PushSubscription::count());
    }

    /** The public half as a PEM, the way openssl_verify wants it. */
    private function publicPem(): string
    {
        // SubjectPublicKeyInfo for an uncompressed P-256 point. Fixed
        // prefix, because both the algorithm and the length are constant.
        $der = base64_decode('MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE')
            .substr(base64_decode(strtr(self::$keys['public'], '-_', '+/')), 1);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** The raw halves back into the DER pair OpenSSL verifies. */
    private function derSignature(string $raw): string
    {
        $integer = function (string $half): string {
            $half = ltrim($half, "\x00");
            // DER integers are signed, so a high top bit needs a zero byte
            // in front of it or the value reads as negative.
            $half = ord($half[0]) > 0x7F ? "\x00".$half : $half;

            return "\x02".chr(strlen($half)).$half;
        };

        $pair = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));

        return "\x30".chr(strlen($pair)).$pair;
    }
}
