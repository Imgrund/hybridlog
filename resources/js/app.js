import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

window.Alpine = Alpine;

/* ------------------------------------------------------------------ */
/* Area tabs: one panel per area under the fixed answer zone. A URL   */
/* hash wins over the remembered tab, the selection survives reloads, */
/* and arrows / Home / End drive the tablist per the ARIA pattern.    */
/* ------------------------------------------------------------------ */

const TAB_STORE_KEY = 'dashboard.activeTab';

Alpine.data('dashTabs', (tabs, legacy = {}) => ({
    tabs,
    active: tabs[0],

    init() {
        // A renamed tab keeps its old links: the legacy map turns the
        // retired id into the current one and the address bar is
        // rewritten, so the old anchor never opens the wrong panel and
        // never gets re-shared onward.
        let fromHash = window.location.hash.slice(1);
        if (legacy[fromHash] && this.tabs.includes(legacy[fromHash])) {
            fromHash = legacy[fromHash];
            history.replaceState(null, '', '#' + fromHash);
        }
        const remembered = legacy[localStorage.getItem(TAB_STORE_KEY)] ?? localStorage.getItem(TAB_STORE_KEY);
        // A hash may name an element inside a panel (the redirect anchor
        // after a log). The panel is x-cloak-hidden while the browser
        // attempts its native jump, so the element's panel wins the tab
        // choice and the jump is repeated once the panel is visible.
        const anchor = fromHash && !this.tabs.includes(fromHash) ? document.getElementById(fromHash) : null;
        const anchorTab = anchor?.closest('[role="tabpanel"]')?.id.replace(/^panel-/, '');
        if (this.tabs.includes(fromHash)) {
            this.active = fromHash;
        } else if (anchorTab && this.tabs.includes(anchorTab)) {
            this.active = anchorTab;
        } else if (this.tabs.includes(remembered)) {
            this.active = remembered;
        }
        this.$nextTick(() => {
            this.revealActiveTab(false);
            if (anchorTab && this.active === anchorTab) anchor.scrollIntoView({ block: 'start' });
        });
    },

    select(tab) {
        if (!this.tabs.includes(tab) || tab === this.active) return;
        this.active = tab;
        localStorage.setItem(TAB_STORE_KEY, tab);
        // replaceState keeps every tab linkable without stacking one
        // history entry per click onto the back button.
        history.replaceState(null, '', '#' + tab);
        this.$nextTick(() => {
            window.dispatchEvent(new CustomEvent('dashboard:tab-shown', { detail: { tab } }));
            this.revealActiveTab(true);
        });
    },

    move(step) {
        this.moveTo((this.tabs.indexOf(this.active) + step + this.tabs.length) % this.tabs.length);
    },

    moveTo(index) {
        const tab = this.tabs[index];
        this.select(tab);
        document.getElementById('tab-' + tab)?.focus();
    },

    syncHash() {
        const raw = window.location.hash.slice(1);
        const tab = legacy[raw] ?? raw;
        if (this.tabs.includes(tab)) this.select(tab);
    },

    /* Scrolls the strip, never the page: the active chip centers
       inside the tablist without fighting scroll restoration. */
    revealActiveTab(smooth) {
        const list = this.$refs.tablist;
        const chip = document.getElementById('tab-' + this.active);
        if (!list || !chip || list.scrollWidth <= list.clientWidth) return;
        const left = chip.offsetLeft - (list.clientWidth - chip.offsetWidth) / 2;
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        list.scrollTo({ left: Math.max(0, left), behavior: smooth && !reduce ? 'smooth' : 'auto' });
    },
}));

/* ------------------------------------------------------------------ */
/* Global range switch: one display window for every series below the */
/* answer zone. The server owns the window math; the client fetches   */
/* the same charts shape, swaps it into __DASH__ and rebuilds, so     */
/* every derived value (reference means, axis minima, bar accents)    */
/* is recomputed by the one code path that owns it.                   */
/* ------------------------------------------------------------------ */

const RANGE_STORE_KEY = 'dashboard.range';

const validRanges = () => window.__DASH__?.rangeOptions ?? [];

function storedRange() {
    const v = parseInt(localStorage.getItem(RANGE_STORE_KEY) ?? '', 10);
    // The span check is not redundant with the allowlist: a range remembered
    // while the mirror was wider (a demo, another account) would otherwise be
    // restored onto a stage the switch now renders disabled, leaving the
    // group showing a selection nobody can move off.
    const limit = window.__DASH__?.rangeLimit ?? Infinity;

    return validRanges().includes(v) && v <= limit ? v : null;
}

let rangeBusy = false;

/* Swaps one window for another. Content dims in place while loading
   (no spinner, the chart boxes keep their fixed height) and returns
   to full strength with the new data. */
async function applyRange(days) {
    if (rangeBusy || !window.__DASH__) return false;
    rangeBusy = true;
    const region = document.querySelector('[data-chart-region]');
    region?.setAttribute('data-range-busy', '');
    region?.setAttribute('aria-busy', 'true');
    try {
        const res = await fetch(`${window.__DASH__.chartsUrl}?days=${days}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(`range fetch failed: ${res.status}`);
        const json = await res.json();
        window.__DASH__.charts = json.charts;
        window.__DASH__.range = json.range;
        swapKpiRows(json.kpi);
        swapDescriptors(json.meta?.desc);
        rebuild();
        localStorage.setItem(RANGE_STORE_KEY, String(json.range));
        window.dispatchEvent(new CustomEvent('dashboard:range-applied', { detail: { days: json.range } }));
        return true;
    } finally {
        rangeBusy = false;
        region?.removeAttribute('data-range-busy');
        region?.removeAttribute('aria-busy');
    }
}

/* The server re-renders each KPI row through the same Blade component
   the page shipped with; swapping outerHTML keeps one markup source.
   Trust boundary: the fragment comes only from our own authenticated
   same-origin endpoint, is Blade-escaped there, and contains no
   user-generated free text (fixed labels, formatted numbers). */
function swapKpiRows(kpiHtml) {
    for (const [key, html] of Object.entries(kpiHtml ?? {})) {
        const el = document.querySelector(`[data-kpi="${key}"]`);
        if (el) el.outerHTML = html;
    }
}

/* Card descriptors name the window they show; a stale one would claim
   a window the chart no longer has. */
function swapDescriptors(desc) {
    for (const [key, text] of Object.entries(desc ?? {})) {
        const el = document.querySelector(`[data-range-desc="${key}"] .card-desc`);
        if (el) el.textContent = text;
    }
}

Alpine.data('rangeSwitch', (ranges, initial, limit = Infinity) => ({
    ranges,
    limit,
    active: initial,
    error: false,

    // A stage the mirror cannot fill is rendered disabled, so the pointer
    // already cannot reach it. Arrow keys have to agree: a roving tabindex
    // that lands on a disabled radio is a keyboard dead end, and the group
    // would report a selection the server was never asked for.
    reachable(days) {
        return days <= this.limit;
    },

    init() {
        // First paint always matches the server payload. A remembered range
        // is restored after that paint, so a slow network can never leave an
        // otherwise complete dashboard looking blank.
        this.active = initial;
        window.addEventListener('dashboard:range-applied', (e) => {
            this.active = e.detail.days;
            this.error = false;
        });
    },

    async select(days) {
        if (!this.reachable(days) || days === this.active || rangeBusy) return;
        const prev = this.active;
        this.active = days;
        this.error = false;
        try {
            if (!(await applyRange(days))) this.active = prev;
        } catch {
            this.active = prev;
            this.error = true;
        }
    },

    // Walks in the asked-for direction until it lands on a stage that can be
    // drawn, wrapping as before. Bounded by the list length, so a mirror too
    // young for any stage stops instead of circling.
    move(step) {
        const n = this.ranges.length;
        let i = this.ranges.indexOf(this.active);
        for (let tried = 0; tried < n; tried++) {
            i = (i + step + n) % n;
            if (this.reachable(this.ranges[i])) return this.moveTo(i);
        }
    },

    moveTo(index) {
        const days = this.ranges[index];
        if (!this.reachable(days)) return;
        this.select(days);
        this.$nextTick(() => document.getElementById('range-' + days)?.focus());
    },

    // Home and End mean the ends of what can be chosen, not the ends of the
    // list. Pointed at a disabled stage they would do nothing at all, which
    // reads as a broken key rather than an unavailable window.
    moveToEdge(last) {
        const reachable = this.ranges.filter((d) => this.reachable(d));
        const days = last ? reachable.at(-1) : reachable[0];
        if (days !== undefined) this.moveTo(this.ranges.indexOf(days));
    },
}));

/* ------------------------------------------------------------------ */
/* Fetch watch: after "Fetch from Garmin" the flash polls the status  */
/* endpoint and reloads once the run is over and last_fetch moved     */
/* past the value the page was rendered with: the mirror's actual    */
/* done-signal rather than a blind timer, and exactly one reload,     */
/* because the stamp itself already moves per endpoint mid-run.       */
/*                                                                    */
/* A fetch that dies never writes that stamp, so the endpoint reports */
/* the failure alongside it and the watch ends on the reason. Without */
/* that it waited its minutes out and then blamed the speed for what  */
/* was a missing Garmin connection.                                   */
/*                                                                    */
/* The poll also carries how far the run has come (day N of M), which */
/* the line prints while the run is a first-connect backfill: that    */
/* one spends many minutes on a quarter of a year, and the moving     */
/* number is what stands between it and "is anything happening".      */
/*                                                                    */
/* The watch used to give up after four minutes flat, which a healthy */
/* backfill outlives several times over: the poll stopped, and the    */
/* page broke its own promise to reload. So the clock watches         */
/* movement instead of duration: while stamps or day counts keep      */
/* arriving the wait is alive at any length, and four quiet minutes   */
/* say "stalled" without ending the poll, because ending is the       */
/* server's call (the failure verdict, or the running mark's TTL      */
/* expiring, both of which arrive as running: false or failed_at).    */
/* Lives only while the page says a fetch is under way, so no other   */
/* page state ever polls.                                             */
/* ------------------------------------------------------------------ */

Alpine.data('fetchWatch', (url, initial, progress = null, messages = {}) => ({
    stalled: false,
    problem: null,
    finished: false,
    action: null,
    connectUrl: null,
    timer: null,
    progress,
    lastFetch: initial,
    lastMovement: Date.now(),

    start() {
        this.timer = setInterval(() => this.check(), 10000);
    },

    stop() {
        clearInterval(this.timer);
    },

    /* The one line under the header. The backfill variants outrank the
       flash text whichever door started the run: what the reader is
       waiting on is the quarter of a year walking in, and that is the
       thing worth reporting on. */
    message() {
        if (this.stalled) return messages.stalled;
        const p = this.progress;
        if (p && p.backfill) {
            if (p.done >= p.total) return messages.backfillRest;
            if (p.done > 0)
                return messages.backfillDay.replace(':done', p.done).replace(':total', p.total);
            return messages.backfillStart.replace(':total', p.total);
        }
        return messages.plain;
    },

    async check() {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (data.failed_at) {
                // Only shown when the trigger cleared the previous verdict
                // at start, so anything present here belongs to this run.
                // Asked before the stamp: a run can log a few endpoints
                // and then die, and a reload would swallow the reason.
                this.stop();
                this.problem = data.problem;
                // Offered only where signing in is the actual fix; a
                // timeout does not get a button that cannot help. The
                // label travels with it because it differs between a
                // first sign-in and a session that has stopped working.
                this.connectUrl = data.connect_url ?? null;
                this.action = this.connectUrl ? data.action : null;
                return;
            }
            // Movement is what the stall clock runs on: a moved fetch
            // stamp (any endpoint landing anything) or a further day.
            const moved = (data.last_fetch ?? null) !== this.lastFetch
                || (data.progress?.done ?? null) !== (this.progress?.done ?? null);
            this.lastFetch = data.last_fetch ?? null;
            if (data.progress) this.progress = data.progress;
            if (moved) this.lastMovement = Date.now();
            // The stamp moves with every endpoint the run gets through,
            // so a moved stamp alone only proves the run is under way.
            // Reloading on it tore the page down every poll tick for the
            // length of the run, the flicker. The one reload waits for
            // the run to be over; over with the stamp unmoved means
            // Garmin had nothing newer to give. Compared against the
            // value the page was rendered with, not the one the polls
            // walked forward, or a run that landed anything would never
            // count as news.
            if (data.running === false) {
                this.stop();
                if (data.last_fetch && data.last_fetch !== initial) return location.reload();
                this.finished = true;
                return;
            }
            this.stalled = Date.now() - this.lastMovement > 240000;
        } catch {
            // Transient network error: keep polling. The wait cannot run
            // away on a dead server either, because a page that cannot
            // reach it is not being told a fetch is running.
        }
    },
}));

/* ------------------------------------------------------------------ */
/* Login watch: a Garmin sign-in runs on a worker, so the page has no  */
/* other way to learn that Garmin has asked for an MFA code. Polls     */
/* while the attempt is unfinished and reloads on every change of      */
/* stage, which is what swaps the password form for the code form and  */
/* finally for the verdict.                                           */
/*                                                                    */
/* It also counts the wait down. That minute is spent inside a library */
/* working through five sign-in routes, so there is nothing to report  */
/* from it and no progress to show: the countdown is an estimate the   */
/* page is honest about, not a measurement. waitSeconds of 0 turns it  */
/* off, which is what the stage after the code does, being short.      */
/* ------------------------------------------------------------------ */

Alpine.data('loginWatch', (url, stage, waitSeconds = 0, elapsed = 0, every = 2000) => ({
    timer: null,
    countdown: null,
    remaining: 0,

    start() {
        this.timer = setInterval(() => this.check(), every);

        // Resumed from the attempt's own age, so a reload mid-wait does
        // not hand out another full minute.
        this.remaining = waitSeconds > 0 ? Math.max(0, waitSeconds - elapsed) : 0;

        if (this.remaining > 0) {
            this.countdown = setInterval(() => this.count(), 1000);
        }
    },

    count() {
        this.remaining -= 1;

        // Stops at zero rather than going negative: past here the page
        // says it is running long, which needs no further counting.
        if (this.remaining <= 0) {
            this.remaining = 0;
            clearInterval(this.countdown);
        }
    },

    get barWidth() {
        return waitSeconds > 0 ? (this.remaining / waitSeconds) * 100 : 0;
    },

    async check() {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            if (data.status !== stage) {
                clearInterval(this.timer);
                clearInterval(this.countdown);
                location.reload();
            }
        } catch {
            // transient network error: the next tick tries again
        }
    },
}));

/* ------------------------------------------------------------------ */
/* Card expand: a card draws open into its one-layer detail. State is */
/* ephemeral by design (no hash, no storage): anything worth a link   */
/* would be a route, not an expansion. The detail carries no canvas,  */
/* so no chart ever needs a resize when it opens.                     */
/* ------------------------------------------------------------------ */

Alpine.data('cardExpand', () => ({
    open: false,

    toggle() {
        this.open = !this.open;
    },

    /* Escape closes the innermost open thing only: the event stops
       here so the body map's window-level deselect never fires on the
       same keypress, and focus returns to the toggle so it is never
       stranded in the now-hidden subtree. */
    onEscape(event) {
        if (!this.open) return;
        event.stopPropagation();
        this.open = false;
        this.$refs.toggle?.focus();
    },
}));

/* ------------------------------------------------------------------ */
/* Count-up for a headline figure. The element renders its final value  */
/* server-side and this only replaces it while it climbs, so a refused  */
/* or failed script leaves the correct number on screen rather than a   */
/* zero. Eased on the same curve as the ring arc beside it, and skipped */
/* outright under prefers-reduced-motion: a number ticking is exactly   */
/* the kind of motion that setting is asking us to stop.                */
/* ------------------------------------------------------------------ */
Alpine.data('countUp', (target, duration = 1000, delay = 0) => ({
    shown: target,

    init() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        this.shown = 0;
        const ease = (t) => 1 - Math.pow(1 - t, 3);
        const step = (start) => (now) => {
            const t = Math.min(1, (now - start) / duration);
            this.shown = Math.round(target * ease(t));
            if (t < 1) requestAnimationFrame(step(start));
        };

        setTimeout(() => requestAnimationFrame((now) => step(now)(now)), delay);
    },
}));

/* ------------------------------------------------------------------ */
/* Metric overlay switch: curated chart cards carry a small radiogroup */
/* ("Aus" plus the coach-paired metrics the card offers). The choice   */
/* lives in this module store so the chart layer reads it on every     */
/* (re)build: an active overlay survives range swaps and theme         */
/* changes, redrawn from the freshly windowed payload; a window        */
/* without drawable points keeps the choice and words the gap instead  */
/* (see applyOverlay). Choices persist per chart and are validated     */
/* against the menu the server actually rendered, so a stale stored    */
/* entry can never invent a pair the curation does not offer.          */
/* ------------------------------------------------------------------ */

const OVERLAY_STORE_KEY = 'dashboard.overlays';

const overlayMenus = {};
const overlayChoice = {};

function storedOverlays() {
    try {
        const parsed = JSON.parse(localStorage.getItem(OVERLAY_STORE_KEY) ?? '{}');
        return typeof parsed === 'object' && parsed !== null ? parsed : {};
    } catch {
        return {};
    }
}

function registerOverlayMenu(chartId, keys) {
    overlayMenus[chartId] = keys;
    const stored = storedOverlays()[chartId];
    overlayChoice[chartId] = keys.includes(stored) ? stored : null;

    return overlayChoice[chartId];
}

function setOverlay(chartId, key) {
    overlayChoice[chartId] = key;
    const stored = storedOverlays();
    if (key === null) {
        delete stored[chartId];
    } else {
        stored[chartId] = key;
    }
    localStorage.setItem(OVERLAY_STORE_KEY, JSON.stringify(stored));
    rebuildOne(chartId);
}

Alpine.data('chartOverlay', (chartId, options) => ({
    chartId,
    options,
    active: null,
    note: '',
    hasChart: true,

    init() {
        this.active = registerOverlayMenu(this.chartId, this.options.map((o) => o.key));
        window.addEventListener('dashboard:overlay-state', (e) => {
            if (e.detail.chart !== this.chartId) return;
            this.hasChart = e.detail.hasChart;
            this.note = e.detail.note;
        });
    },

    /* The off state is a real first radio: "exactly one on" holds and
       the arrow-key cycle includes the way back out. */
    keys() {
        return [null, ...this.options.map((o) => o.key)];
    },

    select(key) {
        if (key === this.active || rangeBusy) return;
        this.active = key;
        setOverlay(this.chartId, key);
    },

    move(step) {
        const keys = this.keys();
        this.moveTo((keys.indexOf(this.active) + step + keys.length) % keys.length);
    },

    moveTo(index) {
        const key = this.keys()[index];
        this.select(key);
        this.$nextTick(() => document.getElementById(this.segId(key))?.focus());
    },

    segId(key) {
        return 'ov-' + this.chartId + '-' + (key ?? 'off');
    },
}));

/* ------------------------------------------------------------------ */
/* Notifications: the switch on /connect/notifications, and the only  */
/* place in the app that talks to the browser's push API.             */
/*                                                                    */
/* Subscribing is the browser's business and it hands out an endpoint */
/* at its vendor's push service; all this does is keep that endpoint  */
/* and the server's list of devices in step. It re-subscribes on      */
/* every visit while switched on, because a browser drops a           */
/* subscription on its own (at a reinstall, or when the permission    */
/* is revoked in its own settings) and the server would otherwise     */
/* keep pushing at an address nobody lives at.                        */
/*                                                                    */
/* The state lives in the browser (permission plus subscription), not */
/* in a preference here: those two are what actually decide whether a */
/* notification arrives, and a remembered "on" that disagrees with    */
/* them is a switch that lies.                                        */
/* ------------------------------------------------------------------ */

Alpine.data('pushSwitch', (config) => ({
    /* unsupported | denied | off | on | working */
    state: 'working',
    error: null,

    async init() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            /* iOS before 16.4, and every browser in a private window.
               Also what a home-screen-less iOS install looks like, which
               is why the page explains that case in words. */
            this.state = 'unsupported';

            return;
        }

        if (Notification.permission === 'denied') {
            this.state = 'denied';

            return;
        }

        const subscription = await (await this.worker()).pushManager.getSubscription();

        if (!subscription) {
            this.state = 'off';

            return;
        }

        /* Known to this browser: tell the server again, in case this is
           the visit after a reinstall handed out a new endpoint. */
        await this.tell(config.subscribeUrl, subscription.endpoint);
        this.state = 'on';
    },

    async enable() {
        this.state = 'working';
        this.error = null;

        try {
            if (await Notification.requestPermission() !== 'granted') {
                this.state = Notification.permission === 'denied' ? 'denied' : 'off';

                return;
            }

            const subscription = await (await this.worker()).pushManager.subscribe({
                /* Required to be true, and true anyway: every push this
                   app sends is a question for the person holding it. */
                userVisibleOnly: true,
                applicationServerKey: config.key,
            });

            await this.tell(config.subscribeUrl, subscription.endpoint);
            this.state = 'on';
        } catch (e) {
            this.state = 'off';
            this.error = e.message;
        }
    },

    async disable() {
        this.state = 'working';

        try {
            const subscription = await (await this.worker()).pushManager.getSubscription();

            if (subscription) {
                /* Server first: a browser that unsubscribed while the row
                   stayed is a device the server pushes at forever, and
                   the reverse is only a push that gets a 410 and cleans
                   itself up. */
                await this.tell(config.unsubscribeUrl, subscription.endpoint);
                await subscription.unsubscribe();
            }

            this.state = 'off';
        } catch (e) {
            this.error = e.message;
            this.state = 'on';
        }
    },

    /* The registration, registering it if this is the first visit. */
    async worker() {
        return navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((registration) => navigator.serviceWorker.ready.then(() => registration));
    },

    async tell(url, endpoint) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': config.token,
            },
            /* Enough to tell two devices apart in the list, and nothing
               more: "iPhone", "MacIntel". Not the user agent string. */
            body: JSON.stringify({
                endpoint,
                device: navigator.userAgentData?.platform || navigator.platform || null,
            }),
        });

        if (!res.ok) throw new Error('HTTP ' + res.status);
    },
}));

/* ------------------------------------------------------------------ */
/* The body map as a solid.                                            */
/*                                                                      */
/* A second rendering of a state the flat map already holds, loaded on  */
/* demand and never from the main bundle. Everything that cannot run it */
/* keeps the SVG, which is why this component may fail quietly: it      */
/* switches nothing on until the module is actually there.              */
/*                                                                      */
/* The zone chips below the figure stay the accessible control in both  */
/* modes, so the canvas is aria-hidden and never a tab stop of its own. */
/* ------------------------------------------------------------------ */
Alpine.data('body3d', () => {
    // Module and viewer live in the closure, not on the component: Alpine
    // would make both deeply reactive, and a proxied WebGL renderer is
    // both pointless and slow.
    let module = null;
    let viewer = null;

    return {
        solid: false,
        solidReady: false,
        // Until the module has answered, the control does not exist. It
        // must never appear and then turn out not to work.
        solidOffered: false,

        init() {
            // The capability test is three lines of canvas, so it runs
            // here; the renderer behind it is 150 kB and does not, until
            // someone actually asks for it.
            try {
                const probe = document.createElement('canvas');
                this.solidOffered = Boolean(window.WebGLRenderingContext && probe.getContext('webgl2'));
            } catch {
                this.solidOffered = false;
            }

            // The theme lives in a media query and the matcap is baked per
            // theme, so a switch has to reach a running viewer.
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                viewer?.setTheme(e.matches);
            });
        },

        async toggleSolid() {
            if (!this.solidOffered) {
                return;
            }
            this.solid = !this.solid;
            if (this.solid && !viewer) {
                try {
                    module = module ?? await import('./body3d.js');
                    viewer = module.mount(this.$refs.stage, {
                        onPick: (zone) => this.$dispatch('zone-pick', zone),
                    });
                    this.solidReady = true;
                } catch {
                    // A failure here costs a view, not a feature: fall back
                    // to the flat map and say nothing more about it.
                    this.solid = false;
                    this.solidOffered = false;

                    return;
                }
            }
            // A canvas mounted while its host was hidden comes up at zero
            // size, so the first paint after showing has to re-measure.
            if (this.solid) {
                this.$nextTick(() => viewer?.resize());
            }
        },

        // Driven by x-effect off the parent's state, so the solid and the
        // flat map cannot disagree about what is selected or which lens is
        // on. Pushing it in keeps the viewer itself free of Alpine.
        syncSolid(zones, lens, selected) {
            // solidReady is read before the guard on purpose: it makes the
            // effect depend on it, so the run that finds no viewer yet comes
            // back by itself once one exists. Without that the first paint
            // after mounting would keep every zone at its default grey.
            if (!this.solidReady || !viewer) {
                return;
            }
            const fills = {};
            Object.entries(zones).forEach(([key, zone]) => {
                fills[key] = zone.fills ? zone.fills[lens] : null;
            });
            viewer.setFills(fills);
            // Zone keys, plus the one thing on the solid that is not a zone
            // and can still be picked: the heart, which answers with the
            // body system it belongs to rather than with a muscle. Every
            // other finding selects nothing on the figure, because nothing
            // on the figure is what it is about.
            viewer.setSelected(zones[selected] || selected === module.ORGAN_PICK ? selected : null);
        },
    };
});

Alpine.start();

Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', sans-serif";

/* ------------------------------------------------------------------ */
/* Chart layer: reads window.__DASH__, colors come from CSS roles      */
/* ------------------------------------------------------------------ */

/* Instances keyed by canvas id, so an overlay toggle can rebuild one
   chart without touching its neighbours. */
const charts = new Map();

const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

/* Chart text follows the interface language, not the browser's: the
   server ships the map for the active locale and the key is the
   English source string, exactly as __() uses it in Blade. A missing
   entry therefore degrades to readable English, never to a bare key,
   which also keeps the login page (no payload) working. */
const T = (source, replace = {}) =>
    Object.entries(replace).reduce(
        (text, [key, value]) => text.replace(':' + key, value),
        window.__DASH__?.i18n?.[source] ?? source,
    );

/* Month names and weekday order come from the page language for the
   same reason, not from whatever locale the browser is set to. */
const LOCALE = document.documentElement.lang || 'en';

const fmtDay = (iso) =>
    new Date(iso + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });

/* ------------------------------------------------------------------ */
/* Shared chart tooltip: one HTML element serves every chart.          */
/*                                                                     */
/* The canvas tooltip Chart.js ships read its background from a        */
/* --sheet role this codebase never defined, so the library's dark     */
/* default won and collided with light mode. Recolouring it would     */
/* have cured only that: a canvas box cannot cast the theme's          */
/* shadow, it repaints only when its own chart does and every chart    */
/* owns one, which is how stale copies piled up on several cards at    */
/* once. A single fixed-position element styled by .chart-tip follows  */
/* the theme live and makes "one tooltip on the whole dashboard" a     */
/* property of the structure rather than of bookkeeping.               */
/*                                                                     */
/* It also leaves differently. The canvas box only ever went away on   */
/* mouseout, an event a finger never sends, so a tap left it standing. */
/* This one closes on a press anywhere else, on any scroll, when       */
/* another chart takes over and, after a touch, on a short timer of    */
/* its own. It sits beside the hovered column rather than on top of    */
/* it, so the points it describes stay visible.                        */
/* ------------------------------------------------------------------ */

/* Clears the hovered column plus the grown hover radius of the widest
   marker (18px on the training-effect bubbles), so the box never sits
   on what it describes. */
const TIP_GAP = 20;
const TIP_EDGE = 8;
/* A finger cannot mouse out, so after a touch the box folds on its own
   once reading time has passed. */
const TIP_TOUCH_HOLD = 3000;

let tipEl = null;
let tipChart = null;
let tipTouchTimer = null;
/* Chart.js opens tooltips on mousemove and touchstart alike and its
   model does not say which it was; the last pointer the window saw
   decides whether the self-closing timer is armed. */
let tipPointerType = 'mouse';

function tipElement() {
    if (!tipEl) {
        tipEl = document.createElement('div');
        tipEl.className = 'chart-tip';
        /* Hidden from assistive tech: the box repeats values the cards
           and tiles already state, and a region rewriting itself on
           every hover would only be noise. */
        tipEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(tipEl);
    }

    return tipEl;
}

/* Folds the element away without touching any chart: for the paths
   where Chart.js already dropped its own hover state (mouseout, a
   chart being destroyed). */
function dropTip() {
    clearTimeout(tipTouchTimer);
    if (tipEl) tipEl.style.opacity = '0';
    tipChart = null;
}

/* The full close, for the paths Chart.js never sees (a press
   elsewhere, scroll, the touch timer): the chart still believes it is
   hovered, so its active elements are cleared as well or the grown
   points would stay grown and the next repaint would bring the box
   straight back. */
function closeTip() {
    const chart = tipChart;
    dropTip();
    if (chart && chart.ctx) {
        chart.tooltip?.setActiveElements([], { x: 0, y: 0 });
        chart.setActiveElements([]);
        chart.update('none');
    }
}

/* The swatch takes the stroke where the series has one and the fill
   where it does not (bars); band helpers with neither get no swatch,
   exactly as their canvas rows carried an invisible box. */
function tipKeyColor(colors) {
    return [colors?.borderColor, colors?.backgroundColor]
        .find((c) => typeof c === 'string' && c !== 'transparent') ?? null;
}

/* Everything lands as text nodes, never as markup: dynamic-card labels
   originate outside this file. */
function renderTip(el, tooltip) {
    el.replaceChildren();
    const add = (cls, text, color = null) => {
        const row = document.createElement('div');
        row.className = cls;
        if (color) {
            const key = document.createElement('span');
            key.className = 'chart-tip-key';
            key.style.background = color;
            row.appendChild(key);
        }
        row.appendChild(document.createTextNode(text));
        el.appendChild(row);
    };
    (tooltip.title ?? []).forEach((line) => add('chart-tip-title', line));
    (tooltip.beforeBody ?? []).forEach((line) => add('chart-tip-row', line));
    (tooltip.body ?? []).forEach((item, i) => {
        const color = tipKeyColor(tooltip.labelColors?.[i]);
        (item.before ?? []).forEach((line) => add('chart-tip-row', line));
        (item.lines ?? []).forEach((line) => add('chart-tip-row', line, color));
        (item.after ?? []).forEach((line) => add('chart-tip-row', line));
    });
    (tooltip.afterBody ?? []).forEach((line) => add('chart-tip-row', line));
    (tooltip.footer ?? []).forEach((line) => add('chart-tip-foot', line));
}

/* Beside the hovered column, never on it: TIP_GAP to the right of the
   caret, flipped to the left when the viewport ends there, clamped so
   it never leaves the screen. */
function placeTip(el, chart, tooltip) {
    const rect = chart.canvas.getBoundingClientRect();
    const x = rect.left + tooltip.caretX;
    const y = rect.top + tooltip.caretY;
    /* Parked at the origin before measuring: squeezed against the
       right viewport edge from its previous position it would wrap and
       report a narrower box than it will have once placed. */
    el.style.left = '0px';
    el.style.top = '0px';
    const w = el.offsetWidth;
    const h = el.offsetHeight;
    let left = x + TIP_GAP;
    if (left + w > window.innerWidth - TIP_EDGE) left = x - TIP_GAP - w;
    el.style.left = Math.max(TIP_EDGE, left) + 'px';
    el.style.top = Math.min(Math.max(y - h / 2, TIP_EDGE), window.innerHeight - h - TIP_EDGE) + 'px';
}

function sharedTooltip(context) {
    const { chart, tooltip } = context;
    if (tooltip.opacity === 0) {
        /* Chart.js closed this one itself, so only the element needs
           to follow, and only if another chart does not own it by now. */
        if (tipChart === chart) dropTip();

        return;
    }
    /* Whoever held the box before loses it, hover state included: one
       tooltip on the whole dashboard. */
    const prev = tipChart;
    tipChart = chart;
    if (prev && prev !== chart && prev.ctx) {
        prev.tooltip?.setActiveElements([], { x: 0, y: 0 });
        prev.setActiveElements([]);
        prev.update('none');
    }
    const el = tipElement();
    renderTip(el, tooltip);
    placeTip(el, chart, tooltip);
    el.style.opacity = '1';
    clearTimeout(tipTouchTimer);
    if (tipPointerType === 'touch') tipTouchTimer = setTimeout(closeTip, TIP_TOUCH_HOLD);
}

/* Capture phase, so a handler that stops propagation cannot keep a
   stale box alive. A press on the open chart's own canvas is the one
   that does not close: it is how touch walks along the series. */
window.addEventListener('pointerdown', (e) => {
    tipPointerType = e.pointerType || 'mouse';
    if (tipChart && e.target !== tipChart.canvas) closeTip();
}, true);

window.addEventListener('pointermove', (e) => {
    tipPointerType = e.pointerType || 'mouse';
}, true);

/* Scrolling moves the anchor out from under the box; closing is calmer
   than chasing it. Capture, because scroll does not bubble from inner
   scrollers like the tab strip. */
window.addEventListener('scroll', () => {
    if (tipChart) closeTip();
}, { capture: true, passive: true });

/* Range swaps, overlay toggles and theme changes destroy and rebuild
   charts; a chart that goes away takes the box with it instead of
   leaving yesterday's numbers floating over its successor. */
Chart.register({
    id: 'sharedTipCleanup',
    beforeDestroy(chart) {
        if (tipChart === chart) dropTip();
    },
});

function baseOptions() {
    const grid = cssVar('--gridline');
    const muted = cssVar('--text-muted');
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: false,
                labels: { color: cssVar('--text-secondary'), boxWidth: 14, boxHeight: 3 },
            },
            tooltip: {
                /* The model, the callbacks and the caret all still run;
                   only the canvas painter is off. sharedTooltip above
                   draws the box as HTML, where the theme's roles reach
                   it. */
                enabled: false,
                external: sharedTooltip,
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { color: cssVar('--baseline') },
                ticks: { color: muted, maxTicksLimit: 7, maxRotation: 0 },
            },
            y: {
                grid: { color: grid },
                border: { display: false },
                ticks: { color: muted, maxTicksLimit: 6 },
            },
        },
    };
}

/* Charts whose container had no box when they were built. They cannot
   repair themselves and are rebuilt when their tab is first revealed
   (see the dashboard:tab-shown listener). Nothing lands here at boot
   any more, where such a chart is skipped rather than built blind, but
   a panel can lose its box later for reasons boot cannot see. */
const bornBlind = new Set();

/* Whether building this chart now would produce a blind one.
   The container is what gets measured, never the canvas: a canvas
   inside a display:none panel reports zero regardless. */
function boxless(id) {
    const el = document.getElementById(id);

    return !!el && !el.parentElement?.getBoundingClientRect().width;
}

/* Builds a chart the first time its box comes into view.
   A closed tab is the common case, but a folded <details> and an Alpine
   x-show container behave the same way and announce themselves through
   no event at all. Intersection is the one question whose answer covers
   all three, and it stays true when a panel opens below the fold: the
   margin builds the chart just before it is scrolled to.

   The box is what gets observed, never the canvas, for the same reason
   the reveal listener below measures the box: a canvas that was once
   built blind carries an explicit 0x0 of its own, and an element with
   no area intersects nothing, ever. The box keeps its CSS height in
   every state a panel can be in. */
const revealer = new IntersectionObserver(
    (entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            revealer.unobserve(entry.target);
            // Cleared here rather than inside make(), because a builder
            // may decide there is nothing to draw and say so in words
            // instead. That is an answer too, and it must not be read
            // through a placeholder claiming the chart is still coming.
            entry.target.removeAttribute('data-pending');
            const canvas = entry.target.querySelector('canvas');
            if (canvas) rebuildOne(canvas.id);
        });
    },
    { rootMargin: '250px' },
);

/* Hands a chart to the revealer. Returns false when there is no box to
   observe, which leaves the caller to build it now: a chart nobody
   watches for would otherwise never be built at all. */
function buildOnReveal(id) {
    const box = document.getElementById(id)?.closest('.chart-box');
    if (!box) return false;
    // Marked, not just skipped: opening a panel would otherwise show an
    // empty box for the frame between the reveal and the build.
    box.setAttribute('data-pending', '');
    revealer.observe(box);

    return true;
}

function make(id, config) {
    const el = document.getElementById(id);
    if (!el) return;
    charts.set(id, new Chart(el, config));
    if (el.getBoundingClientRect().width === 0) {
        bornBlind.add(id);
    } else {
        bornBlind.delete(id);
    }
}

function line(color, data, extra = {}) {
    return {
        type: 'line',
        borderColor: color,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.25,
        data,
        ...extra,
    };
}

/**
 * Colour arrays for weekly bars. The running week is not a result yet:
 * accenting a Monday against seven finished weeks reads as a collapse, so
 * the accent stays on the last complete week and the running one is drawn
 * hollow: present on the axis, visibly not comparable.
 *
 * Returns Chart.js-ready colour arrays plus the running index so callers
 * can label it in the tooltip.
 */
function accentWeeks(values, running, accent, muted) {
    const last = running === null || running === undefined ? values.length - 1 : running - 1;

    return {
        background: values.map((_, i) => (i === running ? 'transparent' : i === last ? accent : muted)),
        border: values.map((_, i) => (i === running ? accent : 'transparent')),
        borderWidth: values.map((_, i) => (i === running ? 1.5 : 0)),
        running,
    };
}

/**
 * The same running-week outline for a stacked week chart, per segment.
 *
 * accentWeeks() cannot serve here: its fill carries "last finished week",
 * and in a stack the fill is already spoken for by which segment it is.
 * What survives is the part that still reads unambiguously, the outline
 * for the week that has not finished yet.
 */
function stackedWeeks(values, running) {
    return {
        background: (fill) => values.map((_, i) => (i === running ? 'transparent' : fill)),
        border: (fill) => values.map((_, i) => (i === running ? fill : 'transparent')),
        borderWidth: values.map((_, i) => (i === running ? 1.5 : 0)),
        running,
    };
}

/**
 * Honest empty window: too few points for a drawable series puts a
 * sentence into the chart box instead of leaving a blank pair of axes.
 * Returns true when the chart build should be skipped (a canvas the
 * server never rendered included). Lines need two points; bar charts
 * pass needed = 1 because a single bar still says something.
 */
function emptyBox(id, points, needed = 2, message = null) {
    const canvas = document.getElementById(id);
    if (!canvas) return true;
    const box = canvas.closest('.chart-box');
    let note = box?.querySelector('.chart-empty');
    if (points >= needed) {
        canvas.style.removeProperty('display');
        note?.remove();
        return false;
    }
    if (box && !note) {
        note = document.createElement('p');
        note.className = 'chart-empty';
        box.appendChild(note);
    }
    if (note) {
        /* The default speaks of a series over time. A card that is not
           one says why itself, because "no data" would be wrong there
           in the one case that matters: data exists, it just cannot
           answer this question yet. */
        note.textContent = message ?? (points === 1
            ? T('Only one reading in this window, no trend yet.')
            : T('No data in the selected window.'));
    }
    canvas.style.display = 'none';
    return true;
}

/* ------------------------------------------------------------------ */
/* Overlay series: every candidate is drawn from the windowed charts   */
/* payload the page already loads: the range endpoint returns the     */
/* same shape, so boot, range swap and overlay can never disagree and  */
/* the server needed no new code. Alignment is a join on the raw x     */
/* key, never a resample: daily series are sampled at the host         */
/* chart's own dates, weekly series join on the ISO week key. A key    */
/* the overlay series does not carry yields null; spanGaps bridges     */
/* interior misses while leading and trailing gaps stay honest.        */
/* ------------------------------------------------------------------ */

const overlaySeriesMap = (keys, values) => {
    const map = new Map();
    (keys ?? []).forEach((k, i) => {
        const v = values?.[i];
        if (v !== null && v !== undefined) map.set(k, v);
    });
    return map;
};

/* label names the series in legend and tooltip; axis titles the
   right-hand scale, so the second axis is readable in words as
   belonging to the overlay, never by colour alone (Written-Word
   Rule). Both are source strings, translated where they are drawn:
   this table is built once at module load, before the payload that
   carries the translations is guaranteed to be there. Which chart
   may offer which key is decided by the curated markup in
   dashboard.blade.php, where each pair carries its justification;
   this table only knows how to extract a series. */
const OVERLAY_SERIES = {
    atl: { label: 'Fatigue (ATL)', axis: 'ATL', pick: (D) => overlaySeriesMap(D.charts.pmc.dates, D.charts.pmc.atl) },
    rhr: { label: 'Resting heart rate', axis: 'Resting heart rate, bpm', pick: (D) => overlaySeriesMap(D.charts.rhr.dates, D.charts.rhr.values) },
    intensity: { label: 'Intensity minutes', axis: 'Minutes', pick: (D) => overlaySeriesMap(D.charts.intensity.weeks, D.charts.intensity.minutes) },
    strengthLoad: { label: 'Strength load', axis: 'Load', pick: (D) => overlaySeriesMap(D.charts.strengthLoad.weeks, D.charts.strengthLoad.load) },
};

/* Tells the card's overlay control what its chart just did: without a
   drawn chart the control hides, an undrawable overlay gets its worded
   note. Only charts that registered a menu listen. */
function overlayState(id, hasChart, note = '') {
    if (!(id in overlayMenus)) return;
    window.dispatchEvent(new CustomEvent('dashboard:overlay-state', { detail: { chart: id, hasChart, note } }));
}

/* Draws the chosen overlay into a builder's datasets and options: a
   2px --series-2 line against its own right-hand axis. order -1 keeps
   it above opaque fills and bars (Chart.js draws ascending order
   last); the legend is forced on and re-sorted back to dataset order
   so the chart's own series stay first. Fewer than two drawable
   points draws nothing and words the gap instead: the .chart-empty
   voice, without evicting a chart that itself has data. */
function applyOverlay(id, xKeys, datasets, opts, xPos = null) {
    const key = overlayChoice[id] ?? null;
    const spec = key !== null ? OVERLAY_SERIES[key] : null;
    let note = '';
    if (spec) {
        const map = spec.pick(window.__DASH__);
        const data = xKeys.map((k) => map.get(k) ?? null);
        const points = data.filter((v) => v !== null).length;
        if (points >= 2) {
            datasets.push({
                // xPos: the host chart runs a metric x axis, so the overlay
                // has to carry its own coordinates instead of riding on the
                // category index.
                ...line(cssVar('--series-2'), xPos ? data.map((v, i) => ({ x: xPos[i], y: v })) : data),
                label: T(spec.label),
                yAxisID: 'y2',
                order: -1,
                spanGaps: true,
            });
            opts.scales.y2 = {
                position: 'right',
                grid: { display: false }, // the left axis owns the gridlines
                border: { display: false },
                ticks: { color: cssVar('--text-muted'), maxTicksLimit: 6 },
                title: { display: true, text: T(spec.axis), color: cssVar('--text-muted'), font: { size: 10, weight: 600 } },
            };
            opts.plugins.legend.display = true;
            opts.plugins.legend.labels.sort = (a, b) => a.datasetIndex - b.datasetIndex;
        } else {
            note = points === 1
                ? T(':series: only one reading in this window, no trend yet.', { series: T(spec.label) })
                : T(':series: no data in the selected window.', { series: T(spec.label) });
        }
    }
    overlayState(id, true, note);
}

/* ------------------------------------------------------------------ */
/* Builders: one per canvas, each reading window.__DASH__ and the CSS  */
/* roles fresh, so a single chart can be rebuilt (overlay toggle)      */
/* exactly like all of them (range swap, theme change).                */
/* ------------------------------------------------------------------ */

/* --- PMC: CTL/ATL lines + TSB diverging bars ------------------- */
function buildPmc() {
    const pmc = window.__DASH__.charts.pmc;
    if (emptyBox('chart-pmc', pmc.dates.length)) return;
    const s1 = cssVar('--series-1');
    // No chart legend: the KPI tiles under the plot already name the
    // three series and carry their colours.
    const opts = baseOptions();
    // Days at the left edge where CTL is still a 42-day average filling
    // up from zero rather than a fitness level. The controller counts
    // them; at a settled model it is 0 and nothing is dashed.
    const warmup = pmc.warmup ?? 0;
    make('chart-pmc', {
        data: {
            labels: pmc.dates.map(fmtDay),
            datasets: [
                {
                    ...line(s1, pmc.ctl),
                    label: T('Fitness (CTL)'),
                    /* The mirror is 58 days old and CTL is a 42-day EWMA
                       seeded with zero, so it has reached about three
                       quarters of the level this training actually
                       sustains. Its first weeks climb because the average
                       is filling up, and drawn solid that reads as the one
                       thing the card must never claim falsely: fitness
                       gained. Dashed until one time constant has passed. */
                    segment: { borderDash: (ctx) => (ctx.p1DataIndex < warmup ? [5, 4] : undefined) },
                },
                { ...line(cssVar('--series-2'), pmc.atl), label: T('Fatigue (ATL)') },
                {
                    type: 'bar',
                    label: T('Form (TSB)'),
                    data: pmc.tsb,
                    /* One fill for both signs. The bar already points up or
                       down from a drawn zero line, so the sign is encoded
                       twice over; painting the negative half in the status
                       red on top of that called a normal build-phase
                       deficit an alarm. Status colours stay out of series
                       fills for exactly this reason. */
                    backgroundColor: cssVar('--series-1-soft'),
                    borderRadius: 2,
                    barPercentage: 1,
                    categoryPercentage: 0.9,
                },
            ],
        },
        options: opts,
    });
}

/* --- HRV: baseline band + weekly line + nightly dots ----------- */
function buildHrv() {
    const hrv = window.__DASH__.charts.hrv;
    if (emptyBox('chart-hrv', hrv.dates.length)) {
        overlayState('chart-hrv', false);
        return;
    }
    const s1 = cssVar('--series-1');
    const opts = baseOptions();
    /* The helper dataset that closes the band fill never earns a
       legend entry, with or without an overlay. */
    opts.plugins.legend.labels.filter = (item) => item.text !== 'bandlow';
    const nightly = hrv.lastNight.filter((v) => v !== null);
    if (nightly.length) {
        opts.scales.y.suggestedMin = Math.min(...nightly) - 8;
    }
    // Chart.js paints dataset 0 on top: lines and dots first, the
    // baseline band last so it stays behind the data.
    const datasets = [
        { ...line(s1, hrv.weekly), label: T('7-day mean') },
        {
            type: 'line',
            label: T('Night value'),
            data: hrv.lastNight,
            borderWidth: 0,
            pointRadius: 2.5,
            pointHoverRadius: 5,
            pointBackgroundColor: s1 + '99',
            showLine: false,
            /* Nothing is stroked here (showLine: false), but the legend
               swatch reads dataset.backgroundColor. Without it the entry
               would be a bare word next to three coloured neighbours the
               moment an overlay turns this chart's legend on. */
            backgroundColor: s1 + '99',
        },
        {
            ...line('transparent', hrv.bandUp),
            label: T('Normal band'),
            backgroundColor: cssVar('--map-neutral'),
            fill: '+1',
            borderWidth: 0,
            pointHoverRadius: 0,
        },
        { ...line('transparent', hrv.bandLow), label: 'bandlow', borderWidth: 0, pointHoverRadius: 0 },
    ];
    applyOverlay('chart-hrv', hrv.dates, datasets, opts);
    make('chart-hrv', {
        data: { labels: hrv.dates.map(fmtDay), datasets },
        options: opts,
    });
}

/* --- weekly strength load --------------------------------------- */
function buildStrengthLoad() {
    const t = window.__DASH__.charts.strengthLoad;
    if (emptyBox('chart-strength-load', t.weeks.length, 1)) {
        overlayState('chart-strength-load', false);
        return;
    }
    const opts = baseOptions();
    const c = accentWeeks(t.load, t.runningIndex, cssVar('--series-1'), cssVar('--series-muted'));
    const datasets = [
        {
            label: T('Strength load'),
            data: t.load,
            backgroundColor: c.background,
            borderColor: c.border,
            borderWidth: c.borderWidth,
            borderRadius: 2,
            maxBarThickness: 26,
        },
    ];
    opts.plugins.tooltip.callbacks = { afterLabel: (i) => (i.dataIndex === c.running ? T('still running') : undefined) };
    applyOverlay('chart-strength-load', t.weeks, datasets, opts);
    make('chart-strength-load', {
        type: 'bar',
        data: {
            labels: t.weeks.map((w) => w.replace(/^\d{4}-/, '')),
            datasets,
        },
        options: opts,
    });
}

/* --- weekly strength progression: reps stacked by category ------- */
function buildStrengthProgress() {
    const p = window.__DASH__.charts.strengthProgress;
    if (!p || emptyBox('chart-strength-progress', p.weeks.length, 1)) return;
    const opts = baseOptions();
    opts.plugins.legend.display = true;
    /* Every entry in this legend is a bar segment; the flat 3px swatch
       would read as a line. */
    opts.plugins.legend.labels.boxHeight = 10;
    opts.scales.x.stacked = true;
    opts.scales.y.stacked = true;
    const c = stackedWeeks(p.weeks, p.runningIndex);
    /* Series labels arrive translated from the server: category names
       are the mirror's own vocabulary, not source strings. The stack
       steps down the one series hue, largest category first; the Other
       fold takes the muted grey so it never competes with a named
       category, and the reserved comparison colour stays untouched. */
    const steps = ['--series-1', '--series-1-dim', '--series-1-soft'];
    const datasets = p.series.map((s, i) => {
        const fill = cssVar(s.other ? '--series-muted' : steps[i % steps.length]);
        return {
            type: 'bar',
            label: s.label,
            data: s.reps,
            stack: 'reps',
            backgroundColor: c.background(fill),
            borderColor: c.border(fill),
            borderWidth: c.borderWidth,
            borderRadius: 2,
            maxBarThickness: 26,
        };
    });
    /* Footer, not afterLabel: with stacked segments the note would
       otherwise be printed once per segment. */
    opts.plugins.tooltip.callbacks = {
        footer: (items) => (items[0]?.dataIndex === c.running ? T('still running') : undefined),
    };
    make('chart-strength-progress', {
        data: {
            labels: p.weeks.map((w) => w.replace(/^\d{4}-/, '')),
            datasets,
        },
        options: opts,
    });
}

/* --- weekly intensity minutes + WHO goal ------------------------ */
function buildIntensity() {
    const im = window.__DASH__.charts.intensity;
    if (emptyBox('chart-intensity', im.weeks.length, 1)) {
        overlayState('chart-intensity', false);
        return;
    }
    const opts = baseOptions();
    opts.plugins.legend.display = true;
    opts.plugins.legend.labels.filter = (item) => item.text !== 'bandlow';
    /* Every entry in this legend is an area, not a line: two bar segments
       and a band. The default flat 3px swatch reads as a line and would
       make the two segments look like series they are not. */
    opts.plugins.legend.labels.boxHeight = 10;
    /* The two segments add up to the plotted total, so the bars stack.
       The explicit stack keys keep the corridor out of that sum: on a
       stacked axis Chart.js would otherwise pile the two constant lines
       on top of the minutes and push the band off the plot. */
    opts.scales.x.stacked = true;
    opts.scales.y.stacked = true;
    const c = stackedWeeks(im.minutes, im.runningIndex);
    const segment = (label, data, fill) => ({
        type: 'bar',
        label,
        data,
        stack: 'minutes',
        backgroundColor: c.background(fill),
        borderColor: c.border(fill),
        borderWidth: c.borderWidth,
        borderRadius: 2,
        maxBarThickness: 26,
    });
    const datasets = [
        /* One accent, two steps of it: the hard minutes carry the data
           colour because they are the part a plan is steered by, the easy
           ones sit one step below in the same hue. Not the muted grey the
           other week charts use for history: that grey is also the corridor
           band's colour, and a grey segment inside a grey band is a segment
           nobody can find. The legend names both, so the split is readable
           without reading the colours. */
        segment(T('Moderate'), im.moderate, cssVar('--series-1-dim')),
        segment(T('Vigorous, counted double'), im.vigorous, cssVar('--series-1')),
        /* The WHO states a corridor, not a floor: 150 to 300 minutes a
           week. A single 150 line makes 400 look like a triumph when the
           guideline stops recommending more at 300. Same two-constant-
           datasets-plus-fill idiom as the HRV normal band. */
        {
            ...line('transparent', im.weeks.map(() => im.goalUpper)),
            label: T('WHO corridor'),
            stack: 'bandhigh',
            backgroundColor: cssVar('--map-neutral'),
            fill: '+1',
            borderWidth: 0,
            pointHoverRadius: 0,
        },
        {
            ...line('transparent', im.weeks.map(() => im.goal)),
            label: 'bandlow',
            stack: 'bandlow',
            borderWidth: 0,
            pointHoverRadius: 0,
        },
    ];
    /* Footer, not afterLabel: with two stacked segments the note would
       otherwise be printed once per segment. */
    opts.plugins.tooltip.callbacks = {
        footer: (items) => (items[0]?.dataIndex === c.running ? T('still running') : undefined),
    };
    applyOverlay('chart-intensity', im.weeks, datasets, opts);
    make('chart-intensity', {
        data: {
            labels: im.weeks.map((w) => w.replace(/^\d{4}-/, '')),
            datasets,
        },
        options: opts,
    });
}

/* --- training effect: aerobic against anaerobic per session ------ */

/* Firstbeat's own steps on the 0–5 Training Effect scale. Both axes use
   the same one; only the wording differs by system, so the labels stay
   generic and the card names the system once, in its heading. */
const TE_STEPS = [
    { from: 0, label: 'no effect' },
    { from: 1, label: 'minor' },
    { from: 2, label: 'maintaining' },
    { from: 3, label: 'improving' },
    { from: 4, label: 'highly improving' },
    { from: 5, label: 'overreaching' },
];

const teStep = (v) => T(TE_STEPS[Math.min(5, Math.floor(v))].label);

function buildTrainingEffect() {
    const te = window.__DASH__.charts.trainingEffect;
    if (emptyBox('chart-training-effect', te.count, 1)) return;
    const opts = baseOptions();
    opts.plugins.legend.display = true;
    /* Point by point, not by index: a scatter has no shared x, and the
       index mode would report every series at once for an unrelated dot. */
    opts.interaction = { mode: 'point', intersect: true };
    const axis = (text) => ({
        type: 'linear',
        min: 0,
        max: 5,
        grid: { color: cssVar('--gridline') },
        border: { color: cssVar('--baseline') },
        ticks: { color: cssVar('--text-muted'), stepSize: 1 },
        title: { display: true, text, color: cssVar('--text-muted') },
    });
    opts.scales = { x: axis(T('Aerobic effect')), y: axis(T('Anaerobic effect')) };

    const fill = {
        run: cssVar('--series-1'),
        combo: cssVar('--series-1-dim'),
        strength: cssVar('--series-2'),
        other: cssVar('--series-muted'),
    };
    const datasets = te.groups.map((g) => ({
        type: 'scatter',
        label: g.label,
        data: g.points,
        /* Translucent body, opaque rim: where two kinds land close together
           the outlines still separate them, and the overlap is visible as
           overlap instead of resolving into one flat blob. */
        backgroundColor: (fill[g.bucket] ?? cssVar('--series-muted')) + 'b3',
        borderColor: fill[g.bucket] ?? cssVar('--series-muted'),
        borderWidth: 1,
        /* Area, not radius, carries the count: seventeen walks share one
           coordinate here, and a marker that grew linearly with n would
           claim about four times the weight it should. A lone session keeps
           the base radius, so size only ever means "more than one". */
        pointRadius: (ctx) => Math.min(16, 3.5 * Math.sqrt(ctx.raw?.n ?? 1)),
        pointHoverRadius: (ctx) => Math.min(18, 3.5 * Math.sqrt(ctx.raw?.n ?? 1) + 2),
    }));

    opts.plugins.tooltip.callbacks = {
        title: (items) => {
            const p = items[0]?.raw;
            if (!p) return '';

            return p.n > 1 ? T(':count sessions, last on :date', { count: p.n, date: p.date }) : p.date;
        },
        label: (i) => `${i.dataset.label} · ${T('aerobic')} ${i.raw.x.toFixed(1)} · ${T('anaerobic')} ${i.raw.y.toFixed(1)}`,
        afterLabel: (i) => `${T('aerobic')} ${teStep(i.raw.x)} · ${T('anaerobic')} ${teStep(i.raw.y)}`,
    };
    make('chart-training-effect', { data: { datasets }, options: opts });
}

const builders = {
    'chart-pmc': buildPmc,
    'chart-hrv': buildHrv,
    'chart-strength-load': buildStrengthLoad,
    'chart-strength-progress': buildStrengthProgress,
    'chart-intensity': buildIntensity,
    'chart-training-effect': buildTrainingEffect,
};

/* A chart inside a closed panel is not built here.
   It would be built at zero width, show nothing, and be thrown away and
   built a second time the moment the panel opens: on this dashboard
   that was thirteen of fifteen charts, all of it spent before the page
   could be used at all. Left to the revealer, each is built once, at
   the moment it becomes worth building. */
function buildAll() {
    if (!window.__DASH__) return;
    for (const [id, build] of Object.entries(builders)) {
        if (!boxless(id) || !buildOnReveal(id)) safeBuild(id, build);
    }
}

function safeBuild(id, build) {
    const canvas = document.getElementById(id);
    const box = canvas?.closest('.chart-box');
    box?.querySelector('[data-chart-error]')?.remove();
    if (canvas) canvas.hidden = false;

    try {
        build();
    } catch (error) {
        if (!box || box.querySelector('[data-chart-error]')) return;
        canvas.hidden = true;
        const note = document.createElement('p');
        note.dataset.chartError = '';
        note.className = 'chart-error';
        note.textContent = T('This chart could not be loaded.');
        box.append(note);
        console.error(`Chart ${id} failed`, error);
    }
}

function rebuild() {
    charts.forEach((c) => c.destroy());
    charts.clear();
    buildAll();
}

/* Rebuilds one chart in place (overlay toggle): the neighbours keep
   their instances, so nothing else flickers or re-animates. */
function rebuildOne(id) {
    if (!window.__DASH__) return;
    charts.get(id)?.destroy();
    charts.delete(id);
    const build = builders[id];
    if (build) safeBuild(id, build);
}

/* First build is never held behind a remembered-range request. The server
   payload paints immediately; restoring a different range is a progressive
   update after usable content already exists. */
async function boot() {
    const D = window.__DASH__;
    buildAll();
    if (D) window.dispatchEvent(new CustomEvent('dashboard:range-applied', { detail: { days: D.range } }));

    const want = storedRange();
    if (D && want !== null && want !== D.range) {
        try {
            await applyRange(want);
        } catch {
            /* The shipped default remains fully usable. */
        }
    }
}

document.addEventListener('DOMContentLoaded', boot);
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', rebuild);

/* A revealed tab resizes the charts it was holding, and repairs any
   that were built blind after all. The revealer handles the ones never
   built; this handles the ones built at the wrong size.

   Chart.js measures the container once, at construction, and writes the
   result onto the canvas as an inline width and height. Inside a
   display:none panel that result is 0px, and it stays 0px after the
   panel opens: the canvas now has an explicit zero size of its own, so
   resize() re-measures nothing and update() redraws the scales while
   the bars and points keep the pixel positions they got at 0x0 (the
   Belastung tab showed seven weekly bars crammed into the left fifth of
   a correctly labelled axis). Only a new Chart() reads the container
   again, so blind-born charts are rebuilt, once, on first reveal. */
window.addEventListener('dashboard:tab-shown', () => {
    /* Belt as well as braces for the case this listener was written
       for. The revealer is the general mechanism and covers what no
       event announces, but it is also the newer of the two and it goes
       quiet in a background tab; a tab click is the one reveal that
       always says so out loud, so it is not left to the observer
       alone. Whichever gets there first, unobserve() and the removed
       attribute stop the other from building a second time. */
    document.querySelectorAll('.chart-box[data-pending]').forEach((box) => {
        if (!box.getBoundingClientRect().width) return;
        revealer.unobserve(box);
        box.removeAttribute('data-pending');
        const canvas = box.querySelector('canvas');
        if (canvas) rebuildOne(canvas.id);
    });

    // A snapshot, because rebuildOne() writes to the same Map.
    [...charts.keys()].forEach((id) => {
        if (!bornBlind.has(id)) {
            // Covers a panel that changed width while it was hidden.
            charts.get(id)?.resize();
        } else if (!boxless(id)) {
            rebuildOne(id);
        }
    });
});
