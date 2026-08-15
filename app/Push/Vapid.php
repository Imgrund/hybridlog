<?php

declare(strict_types=1);

namespace App\Push;

use Carbon\Carbon;

/**
 * The signature a push service asks for before it will carry a message.
 *
 * Web Push identifies the sender with a JWT signed by an elliptic-curve
 * key pair the installation owns (RFC 8292). The public half also travels
 * to the browser as the applicationServerKey, which is what ties a
 * subscription to this dashboard and nothing else.
 *
 * Written out here rather than pulled in as a library because that is all
 * of it: one ES256 signature over two JSON objects. The heavy part of a
 * web-push library is encrypting the payload, and there is no payload to
 * encrypt (see WebPush): the notification text never leaves this server.
 *
 * Keys are the operator's, made once with `php artisan push:keys`. There
 * is no shared pair to fall back on: it is the identity every browser
 * subscription is bound to, and a shared one would let whoever holds it
 * push to every installation that used it.
 */
class Vapid
{
    /** OID 1.2.840.10045.3.1.7, the P-256 curve, in the DER form below. */
    private const CURVE_OID = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    /** Both coordinates and the private scalar are this wide on P-256. */
    private const SCALAR_BYTES = 32;

    /**
     * A fresh key pair as the two values that belong in the environment.
     *
     * Raw base64url rather than PEM, the format the browser APIs and every
     * other web-push tool use, so a pair generated elsewhere works here
     * and vice versa.
     *
     * @return array{public: string, private: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new PushError('Could not generate a key pair; this build of PHP has no EC support in OpenSSL.');
        }

        $details = openssl_pkey_get_details($key)['ec'] ?? [];

        return [
            'public' => self::encode(self::point($details['x'] ?? '', $details['y'] ?? '')),
            'private' => self::encode(self::pad($details['d'] ?? '')),
        ];
    }

    /**
     * @param  string  $publicKey  base64url of the uncompressed point
     * @param  string  $privateKey  base64url of the 32-byte scalar
     * @param  string  $subject  a mailto: or https: the push service can
     *                           complain to, as RFC 8292 asks for
     */
    public function __construct(
        private string $publicKey,
        private string $privateKey,
        private string $subject,
    ) {}

    /** Whether the installation has been given a key pair at all. */
    public function configured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '';
    }

    /** The applicationServerKey the browser subscribes with. */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * The Authorization header for one push, valid for this endpoint only.
     *
     * The audience is the push service's origin, and every service checks
     * it: a token minted for Google will not move a Mozilla endpoint. The
     * lifetime is the twelve hours RFC 8292 allows at most, which costs
     * nothing here because a token is made per request anyway.
     */
    public function authorization(string $endpoint, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();
        $parts = parse_url($endpoint);

        if (! isset($parts['scheme'], $parts['host'])) {
            throw new PushError('Push endpoint is not a URL: '.$endpoint);
        }

        $token = $this->token([
            'aud' => $parts['scheme'].'://'.$parts['host'],
            'exp' => $now->copy()->addHours(12)->getTimestamp(),
            'sub' => $this->subject,
        ]);

        return 'vapid t='.$token.', k='.$this->publicKey;
    }

    /**
     * The signed JWT. ES256 only, because that is the one algorithm the
     * push protocol names.
     *
     * @param  array<string, mixed>  $claims
     */
    private function token(array $claims): string
    {
        $header = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $body = self::encode((string) json_encode($claims));
        $input = $header.'.'.$body;

        $key = openssl_pkey_get_private($this->pem());

        if ($key === false) {
            throw new PushError('VAPID_PRIVATE_KEY is not a P-256 key; generate a pair with `php artisan push:keys`.');
        }

        if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new PushError('Could not sign the push token.');
        }

        return $input.'.'.self::encode(self::rawSignature($der));
    }

    /**
     * The key pair as PEM, which is the only form openssl_sign takes.
     *
     * Assembled by hand from the two base64url values (RFC 5915's
     * ECPrivateKey), so the environment can hold the same short strings
     * every other web-push tool uses instead of a multi-line PEM block.
     * The public half is written in as well: without it OpenSSL has to
     * derive the point, which it does not do in every build.
     */
    private function pem(): string
    {
        $private = self::decode($this->privateKey);
        $point = self::decode($this->publicKey);

        // Checked by length rather than left to OpenSSL: a truncated or
        // mistyped value can still assemble into a loadable key, which
        // then signs tokens every push service rejects.
        if (strlen($private) !== self::SCALAR_BYTES) {
            throw new PushError('VAPID_PRIVATE_KEY is not a P-256 scalar; generate a pair with `php artisan push:keys`.');
        }

        if (strlen($point) !== 1 + 2 * self::SCALAR_BYTES) {
            throw new PushError('VAPID_PUBLIC_KEY is not an uncompressed P-256 point; generate a pair with `php artisan push:keys`.');
        }

        $der = "\x02\x01\x01"                                    // version 1
            ."\x04".chr(self::SCALAR_BYTES).$private             // the scalar
            ."\xa0".chr(strlen(self::CURVE_OID)).self::CURVE_OID // [0] the curve
            ."\xa1".chr(strlen($point) + 3)                      // [1] the point,
            ."\x03".chr(strlen($point) + 1)."\x00".$point;       // as a bit string

        // Short-form length: the whole structure is well under 128 bytes.
        $der = "\x30".chr(strlen($der)).$der;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * OpenSSL signs into a DER SEQUENCE of two integers; JOSE wants the
     * two halves raw and fixed-width. The integers are signed, so a half
     * whose top bit is set carries a leading zero byte that has to come
     * back off, and a short one has to be padded back up.
     */
    private static function rawSignature(string $der): string
    {
        $offset = 3 + ord($der[3]);
        $r = substr($der, 4, ord($der[3]));
        $s = substr($der, $offset + 3, ord($der[$offset + 2]));

        return self::pad(ltrim($r, "\x00")).self::pad(ltrim($s, "\x00"));
    }

    /** The uncompressed point form every browser expects: 0x04, x, y. */
    private static function point(string $x, string $y): string
    {
        return "\x04".self::pad($x).self::pad($y);
    }

    /** Left-pads a big-endian integer to the curve's width. */
    private static function pad(string $value): string
    {
        return str_pad($value, self::SCALAR_BYTES, "\x00", STR_PAD_LEFT);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
