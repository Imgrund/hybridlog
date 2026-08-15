/*
 * The service worker, which exists for one thing: to be awake when the
 * dashboard is not, so the moment's notification can be shown while it
 * still matters.
 *
 * The push that wakes it carries no message. It fetches the wording from
 * /push/next itself, and that is deliberate twice over: the push services
 * of Google, Mozilla and Apple never hold anything about somebody's
 * health, and what the notification says is decided at the moment it is
 * shown rather than when it was queued, so an item dealt with from the
 * dashboard in the meantime stays quiet.
 *
 * The feed answers with one item of one kind: a stress window, the
 * morning briefing, the evening nudge, a health alert or the weekly
 * report reminder. This worker does not know the kinds apart and does
 * not need to: title, body and target URL arrive decided.
 *
 * Deliberately no asset caching. Offline is not what this is for, the
 * dashboard is a live view of a mirror, and a cache of hashed Vite bundles
 * would be a second source of truth about which version is running.
 */

/* Skip the wait: there is no state in here worth keeping a stale copy for. */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

const ANSWER_PAGE = '/stress-window'

/* One notification per kind of news. A newer item of the same kind
 * replaces the one still on the lock screen (a second fetch found the
 * newer state of the same question), while items of different kinds
 * stand next to each other: a health alert must not swallow the
 * briefing that arrived five minutes before it. The fallback is the
 * feed's original single kind, from before it carried a type at all. */
const TAG_FALLBACK = 'stress-window'

self.addEventListener('push', (event) => {
    event.waitUntil(ask())
})

async function ask() {
    let item = null

    try {
        const res = await fetch('/push/next', {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        })

        // A signed-out browser gets 401 here rather than a redirect to the
        // login page, because the request asks for JSON.
        if (res.ok) {
            item = (await res.json()).window
        }
    } catch (e) {
        // No network at the moment the push arrived. Nothing to say, and
        // whatever it was is still on the dashboard the next time anything
        // asks.
    }

    if (!item) {
        // Showing nothing is against the letter of the rule that a push
        // must produce a notification, and it is the right answer here:
        // the alternative is a buzz that says the thing it woke you for
        // has already been dealt with. It is also rare, because the push
        // goes out seconds after the item is found.
        return
    }

    await self.registration.showNotification(item.title, {
        body: item.body,
        tag: item.type || TAG_FALLBACK,
        renotify: true,
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data: { url: item.url || ANSWER_PAGE },
    })
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close()
    event.waitUntil(open(event.notification.data?.url || ANSWER_PAGE))
})

/*
 * One tap lands on the target, in the window that is already open if there
 * is one. Opening a second copy of an installed app is how a one-tap
 * answer turns into looking for the right tab.
 */
async function open(url) {
    const target = new URL(url, self.location.origin)

    // An external target (the weekly reminder opens the chat app's site)
    // gets a window of its own: navigating the installed app's tab away
    // to another origin would trade the dashboard for the link.
    if (target.origin !== self.location.origin) {
        return self.clients.openWindow(target.href)
    }

    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })

    for (const client of clients) {
        if ('navigate' in client) {
            await client.navigate(target.href)

            return client.focus()
        }
    }

    return self.clients.openWindow(target.href)
}
