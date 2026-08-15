<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Garmin sign-in currently in progress.
 *
 * Written from both ends: the queue worker moves the status along as the
 * Python login answers, the browser drops the MFA code in when Garmin
 * asks for one. Exactly one attempt is ever live, because starting a new
 * one clears the last (see App\Garmin\GarminLogin).
 */
class GarminLoginAttempt extends Model
{
    /** Waiting for the worker to pick the job up. */
    public const STARTING = 'starting';

    /** Garmin wants a second factor and the login is holding for it. */
    public const MFA_REQUIRED = 'mfa_required';

    /** The code has been handed over, Garmin has not answered yet. */
    public const COMPLETING = 'completing';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /**
     * Seconds a sign-in normally needs before the code field appears.
     *
     * Measured rather than guessed. The login library works through five
     * routes in turn, and on a host Garmin has rate limited it spends most
     * of that time in the delays it puts between its own attempts so as
     * not to trip Cloudflare: a run that got there in six seconds and one
     * that took ninety-eight differ almost entirely in how many of those
     * delays they sat through. A minute covers the ordinary case, and the
     * page says plainly that it can run over rather than stalling at zero.
     */
    public const WAIT_SECONDS = 60;

    protected $fillable = ['user_id', 'status', 'mfa_code', 'mfa_channel', 'account', 'error'];

    /** The user's live attempt, or null when they are not signing in. */
    public static function currentFor(User $user): ?self
    {
        return static::query()->where('user_id', $user->id)->latest('id')->first();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    /**
     * Seconds this attempt has been going, for the countdown to resume at.
     *
     * Counted from the row rather than from the page load, so a reload
     * mid-wait picks the countdown up where it stood instead of granting
     * another full minute.
     */
    public function secondsWaiting(): int
    {
        return max(0, (int) $this->created_at?->diffInSeconds(now()));
    }

    /**
     * Where to go looking for the code, in the athlete's words.
     *
     * The page used to claim the code had been sent by email or text. That
     * is only true for two of the three second factors Garmin offers: an
     * authenticator app is never sent anywhere, and someone told to check
     * an inbox will spend the whole five-minute window doing it.
     *
     * mfa_channel holds what the login library reported, in the shape
     * "method=email flow=ios", with "delivery=unconfirmed" appended when
     * the sign-in came through a path that cannot confirm Garmin sent
     * anything at all, and "page=<title>" when the path was the widget.
     */
    public function mfaHint(): string
    {
        $channel = (string) $this->mfa_channel;
        $factor = $this->secondFactor($channel);

        $hint = match ($factor) {
            'email' => __('Garmin has sent a code to the email address on your Garmin account.'),
            'sms' => __('Garmin has sent a code by text message.'),
            'app' => __('The code is in the authenticator app on your Garmin account. Garmin does not send anything in this case.'),
            default => __('Garmin has asked for the second factor set up on your account.'),
        };

        $hint .= ' '.__('It is held open for five minutes; after that the sign-in has to be started again.');

        if ($this->deliveryUnconfirmed($channel, $factor)) {
            $hint .= ' '.__('This sign-in took a fallback route on which Garmin does not confirm it sent anything. If no code arrives, start the sign-in again.');
        }

        return $hint;
    }

    /**
     * Which second factor Garmin is asking for: email, sms, app or unknown.
     *
     * Two sources, in order of how much they are worth. method= is Garmin
     * naming the factor itself, which only the login APIs get told. The
     * widget path scrapes HTML instead and is told nothing, so there the
     * heading of the page it stopped on is the whole of the evidence: an
     * authenticator app gets "Enter MFA code for login", an emailed code
     * gets "GARMIN Authentication Application".
     *
     * Read fresh every time rather than assumed from the last sign-in.
     * The same account has answered with different pages on consecutive
     * attempts, so a factor remembered from an hour ago is a guess.
     *
     * Matched loosely: the vocabulary is Garmin's and it is free to grow
     * one we have not seen, in which case this says so instead of picking.
     */
    private function secondFactor(string $channel): string
    {
        $method = str_contains($channel, 'method=')
            ? strtolower(strtok(substr($channel, strpos($channel, 'method=') + 7), ' '))
            : '';

        $factor = match (true) {
            str_contains($method, 'email') => 'email',
            str_contains($method, 'sms'), str_contains($method, 'text'), str_contains($method, 'phone') => 'sms',
            str_contains($method, 'totp'), str_contains($method, 'auth'), str_contains($method, 'app') => 'app',
            default => '',
        };

        if ($factor !== '' || ! str_contains($channel, 'page=')) {
            return $factor;
        }

        // Everything after "page=" is the title: it is appended last and a
        // heading has spaces in it.
        $title = strtolower(substr($channel, strpos($channel, 'page=') + 5));

        return match (true) {
            str_contains($title, 'authentication application') => 'email',
            str_contains($title, 'mfa') => 'app',
            default => '',
        };
    }

    /**
     * Whether nothing may have been sent, however the hint above reads.
     *
     * The library flags this itself, but the flag does not survive its own
     * strategy chain: it is reset before every strategy and left out of
     * the state that gets restored when an earlier MFA is fallen back on,
     * so by the time we read it the widget's warning has been cleared by
     * the strategies tried after it. The route is the durable evidence,
     * and it is enough: the widget path is scraped HTML with no JavaScript
     * behind it, so it never confirms a delivery. Only for factors that
     * involve one, since an authenticator app has nothing to send.
     */
    private function deliveryUnconfirmed(string $channel, string $factor): bool
    {
        if (str_contains($channel, 'delivery=unconfirmed')) {
            return true;
        }

        return str_contains($channel, 'flow=widget') && $factor !== 'app';
    }
}
