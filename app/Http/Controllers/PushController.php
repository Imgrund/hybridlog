<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HealthAlert;
use App\Models\PushSend;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\EveningNudge;
use App\Push\MorningBriefing;
use App\Push\Vapid;
use App\Push\WeeklyReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * The notifications this dashboard sends.
 *
 * Two jobs, and they are the same job seen from two sides: the browser
 * registering a device, and the service worker asking what to say. Its own
 * controller because none of it renders the dashboard, and all of it only
 * exists to shorten the distance between the data and the moment it is
 * worth a glance.
 */
class PushController extends Controller
{
    /**
     * The permission page: what a notification would be, and the switch.
     *
     * The subscribing itself happens in the browser, which is the only
     * party that can ask, so this page hands the public key to the script
     * and takes the resulting endpoint back.
     */
    public function settings(Request $request): View
    {
        return view('connect-notifications', [
            'configured' => app(Vapid::class)->configured(),
            'publicKey' => app(Vapid::class)->publicKey(),
            'devices' => $request->user()->pushSubscriptions()->orderByDesc('created_at')->get(),
        ]);
    }

    /**
     * Remembers a device.
     *
     * The endpoint is a URL at the browser vendor's push service that the
     * browser handed out, and it is the whole credential: whoever has it
     * can make that device ring. So it is write-only from here on: the
     * model hides it, and this endpoint sits behind the same sign-in as
     * everything else.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            // https only, and long enough for every service in the wild:
            // Apple's are short, Microsoft's are not.
            'endpoint' => ['required', 'url:https', 'max:1000'],
            // What the list on the settings page calls this device. From
            // the browser's user agent, so it is a hint, not an identity.
            'device' => ['nullable', 'string', 'max:80'],
        ]);

        PushSubscription::remember($data['endpoint'], $data['device'] ?? null, $request->user());

        return response()->json(['subscribed' => true]);
    }

    /**
     * Forgets a device, at its own request.
     *
     * Called when the athlete switches notifications off here, and also
     * when the browser reports a subscription it no longer has. Silent
     * about a row that was already gone: the desired state is "not
     * subscribed", and it is reached either way.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000'],
        ]);

        PushSubscription::forEndpoint($data['endpoint'], $request->user())?->delete();

        return response()->json(['subscribed' => false]);
    }

    /**
     * What the notification should say, fetched by the service worker at
     * the moment it is about to show one.
     *
     * The push itself carries no body (see App\Push\WebPush), so this is
     * where the wording is decided, after the buzz was queued rather than
     * before it. That is the point: an item dealt with in the meantime
     * returns nothing here and the phone stays quiet.
     *
     * One feed, four kinds, one answer: the newest item that is still
     * pending. Each kind defines its own freshness. Briefing and nudge
     * live for the day they were sent and are composed again here, so a
     * finding that no longer holds when the phone is picked up un-says
     * its own notification; health alerts live for their day; the weekly
     * reminder lives for its Sunday.
     *
     * The response keeps its historical shape: the item sits under
     * "window" and carries title, body and url, which is everything a
     * service worker from before the feed became multi-typed reads. The
     * "type" field is additive; renaming any of this would break workers
     * that browsers have not refreshed yet.
     */
    public function next(
        Request $request,
        MorningBriefing $briefing,
        EveningNudge $nudge,
        WeeklyReminder $reminder,
    ): JsonResponse {
        // Everything in the feed hangs off the asking user's own ledger
        // rows, so a user nothing was sent to gets an empty feed: their
        // phone was never woken, and this endpoint must not invent news.
        $user = $request->user();

        $item = collect([
            $this->briefingItem($briefing, $user),
            $this->nudgeItem($nudge, $user),
            $this->alertsItem($user),
            $this->weeklyItem($reminder, $user),
        ])
            ->filter()
            ->sortByDesc('at')
            ->first();

        return response()->json([
            'window' => $item === null ? null : Arr::except($item, ['at']),
        ]);
    }

    /**
     * The morning briefing, only on the day it was sent, and composed
     * fresh so the phone shows this moment's numbers rather than the
     * ones from 09:40.
     *
     * @return array<string, mixed>|null
     */
    private function briefingItem(MorningBriefing $briefing, User $user): ?array
    {
        $sent = PushSend::sentToday(PushSend::KIND_BRIEFING, $user);

        if ($sent === null) {
            return null;
        }

        $item = $briefing->compose();

        return $item === null ? null : $item + ['type' => 'morning-briefing', 'at' => $sent->sent_at];
    }

    /**
     * The evening nudge, only on the day it was sent and only while its
     * finding still stands: a slot logged between the buzz and the glance
     * composes to null here, and the phone stays quiet.
     *
     * @return array<string, mixed>|null
     */
    private function nudgeItem(EveningNudge $nudge, User $user): ?array
    {
        $sent = PushSend::sentToday(PushSend::KIND_NUDGE, $user);

        if ($sent === null) {
            return null;
        }

        $item = $nudge->compose();

        return $item === null ? null : $item + ['type' => 'evening-nudge', 'at' => $sent->sent_at];
    }

    /**
     * Today's fired alert rules as one notification. Joined rather than
     * picked from, because two broken thresholds are one morning's news;
     * yesterday's alerts belong to yesterday and are not shown at all.
     *
     * @return array<string, mixed>|null
     */
    private function alertsItem(User $user): ?array
    {
        $alerts = HealthAlert::for($user)->where('date', now()->toDateString())
            ->orderByDesc('created_at')->get();

        if ($alerts->isEmpty()) {
            return null;
        }

        return [
            'title' => trans_choice('{1}Health alert|[2,*]:count health alerts', $alerts->count(), ['count' => $alerts->count()]),
            'body' => $alerts->pluck('message')->implode("\n"),
            'url' => route('dashboard'),
            'type' => 'health-alert',
            'at' => $alerts->first()->created_at,
        ];
    }

    /**
     * The Sunday reminder, for the Sunday it was sent: the report itself
     * lives in the chat and leaves no trace here, so the reminder simply
     * expires with its day. Monday mornings return nothing.
     *
     * @return array<string, mixed>|null
     */
    private function weeklyItem(WeeklyReminder $reminder, User $user): ?array
    {
        $sent = PushSend::sentToday(PushSend::KIND_WEEKLY, $user);

        if ($sent === null) {
            return null;
        }

        return $reminder->compose($user) + ['type' => 'weekly-report', 'at' => $sent->sent_at];
    }
}
