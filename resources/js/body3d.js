/* ------------------------------------------------------------------ */
/* The body map as a solid.                                            */
/*                                                                      */
/* This module is loaded on demand and never from the main bundle: the  */
/* SVG map is the real surface, and it stays in the DOM as the          */
/* accessible one. What arrives here is a second rendering of the same  */
/* selection state, so anything that cannot run it (no WebGL, reduced   */
/* motion, a screen reader) loses a view, never a feature.              */
/*                                                                      */
/* Model: BodyParts3D, grouped into the sixteen zone meshes the          */
/* freshness model names. CC BY-SA 2.1 Japan, credit and the terms it    */
/* puts on that one file: resources/models/CREDITS.md.                   */
/* ------------------------------------------------------------------ */

import {
    AmbientLight,
    Box3,
    CanvasTexture,
    Color,
    Group,
    MathUtils,
    MeshMatcapMaterial,
    PerspectiveCamera,
    Raycaster,
    Scene,
    SRGBColorSpace,
    Vector2,
    Vector3,
    WebGLRenderer,
} from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
// Meshopt rather than Draco: its decoder is a plain ES module the bundler can
// swallow, so the model needs no second asset path to survive a deploy.
import { MeshoptDecoder } from 'three/examples/jsm/libs/meshopt_decoder.module.js';
import modelUrl from '../models/body-zones.glb?url';

/* What the model carries that makes no load claim: the head, and the bones
   an écorché shows, which is hands, feet and the shafts no muscle covers.
   They keep the figure a body rather than sixteen muscles floating in the
   dark. Separate groups because a glTF node name is the key here, and one
   name cannot hold two meshes.

   The heart is in this set because no training load is attributable to it
   either, so it takes no colour off the ramp. It is the one of the three
   that answers to a tap: not with a zone, but with the body system it
   belongs to. */
const INERT = 'NONE';
const HEAD = 'HEAD';
const HEART = 'HEART';
const NO_CLAIM = new Set([INERT, HEAD, HEART]);

/* What a tap on the heart selects. Not a mesh name but the key of the
   cardiovascular system in the findings list, which is what the marker on
   the flat map selects too: the two surfaces are two renderings of one
   selection, so tapping the organ here and the dot over there have to end
   in the same panel. Exported because the caller has to let this one key
   back in; every other finding selects nothing on the figure. */
export const ORGAN_PICK = 'heart';

/* Steps on the load ramp, matching BodyMap::RAMP_STEPS. Only used to place
   a step inside the solid's lightness band, never to pick a colour: the
   palette itself stays in CSS. */
const RAMP_STEPS = 10;

/* What everything else turns into once a muscle is picked: faint enough
   that a covered pick reads straight through it, present enough that the
   body is still there to place the pick in.

   The two themes need opposite recipes and the numbers are measured, not
   guessed. Against the dark card a pale ghost at low opacity separates by
   0.21 in OKLab lightness. The same recipe against the light card measured
   0.028, which is nothing: the ghost was barely darker than the card to
   begin with, and the matcap then lifted it the rest of the way. So the
   light theme gets a genuinely dark ghost and roughly twice the opacity. */
const GHOST = {
    dark: { hue: 0.6, lightness: 0.3, alpha: 0.16 },
    light: { hue: 0.62, lightness: 0.26, alpha: 0.3 },
};

/* Which pass a mesh belongs to. See render(): each of the two after the
   body gets a cleared depth buffer, so what it draws is never behind
   anything. That is the only way a deep muscle can be shown at all, and
   the heart sits deeper than any of them. */
const LAYER_BODY = 0;
const LAYER_PICK = 1;
const LAYER_ORGAN = 2;

/* The heart, which is the one thing on the figure that is neither a muscle
   nor scenery.

   It gets a colour the ramp cannot lend it. The ramp is blue from end to
   end and means accumulated load, so a heart drawn anywhere on it would
   read as a zone with a reading of its own. Warm and desaturated, at
   roughly a third of full strength: that is what an organ showing through
   a chest looks like, where a solid red one in a training figure reads as
   an alarm. Two recipes, because the tone that sits nicely against the
   dark card washes out against the light one.

   `picked` is what it comes to when the reader taps it. Not opaque: it is
   still an organ inside a chest, and a heart that turns solid on selection
   would be the only thing on the figure that stops obeying its own
   position. Enough to read as chosen rather than as passed over. */
const ORGAN = {
    dark: { hue: 0.995, sat: 0.44, lightness: 0.58, alpha: 0.34, picked: 0.86 },
    light: { hue: 0.995, sat: 0.52, lightness: 0.46, alpha: 0.42, picked: 0.9 },
};

/* The stroke, in milliseconds and as a fraction of the heart's own size.
   The reach is the one number here that was measured rather than
   picked. The heart comes out about 33 pixels tall on a stage this size,
   so 9 % moved its outline by three of them and read as a shimmer; 16 %
   moves five and reads as a beat. It happens to be honest as well: a real
   ventricle shortens along its long axis by roughly that much. Systole
   keeps a fixed length because it has one; a slow heart is a longer gap
   between beats, never a longer squeeze.

   BREATH_MS is where the reading lives. The interval between strokes
   swings over this cycle, and how far it swings is the HRV. See
   App\View\Heartbeat, which computes both numbers and is where the
   exaggeration is argued. */
const BEAT_MS = 260;
const BEAT_PEAK = 0.22;
const BEAT_REACH = 0.16;
const BREATH_MS = 5000;

/* A matcap is a sphere of pre-lit material: sampling it by the surface
   normal gives a studio-clay render with no lights and no shadow pass.
   Drawing it into a canvas rather than shipping an image keeps the
   module self-contained and lets both themes get their own. */
function clayMatcap(dark) {
    const size = 256;
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = size;
    const ctx = canvas.getContext('2d');

    // Base sphere, lit from the upper left, same direction as the light
    // over the SVG figure so the two readings agree about where the sun is.
    // The whole ramp sits high on purpose. The zone colour multiplies this,
    // so anything dark here subtracts from the load reading rather than
    // shading it. The floor is the number that matters: at the old 0.42 the
    // shaded side of a quiet zone fell below the card colour and the body
    // lost its edge against it, so shading now spans roughly 0.7 to 1
    // instead of 0.42 to 1. It costs some roundness and buys the silhouette.
    // Raising it further is tempting and wrong: at 0.65 the shaded side of
    // every zone converges and the ramp differences the map is for wash out.
    const key = ctx.createRadialGradient(size * 0.36, size * 0.3, size * 0.03, size * 0.5, size * 0.5, size * 0.62);
    key.addColorStop(0, '#ffffff');
    key.addColorStop(0.45, dark ? '#d2d8e0' : '#eef1f4');
    key.addColorStop(1, dark ? '#7c848f' : '#a8afb9');
    ctx.fillStyle = key;
    ctx.fillRect(0, 0, size, size);

    // Rim light along the far edge. This is what separates the silhouette
    // from the card behind it without an outline pass. It is additive and
    // it covers the outer annulus, which on a body is most of the surface
    // you see, so it has to stay weak: at the strength that suited the old
    // dark base it now washes every zone toward the same pale blue and
    // undoes the differences the ramp just gained.
    const rim = ctx.createRadialGradient(size * 0.5, size * 0.5, size * 0.34, size * 0.5, size * 0.5, size * 0.5);
    rim.addColorStop(0, 'rgba(255,255,255,0)');
    rim.addColorStop(0.86, dark ? 'rgba(214,228,244,0.2)' : 'rgba(255,255,255,0.28)');
    rim.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = rim;
    ctx.fillRect(0, 0, size, size);

    const texture = new CanvasTexture(canvas);
    texture.colorSpace = SRGBColorSpace;

    return texture;
}

/* CSS owns the palette. Reading the computed value keeps the solid on
   exactly the ramp the flat map uses, including both themes, instead of
   duplicating ten hex values that would then drift. */
function cssColor(el, name, fallback) {
    const value = getComputedStyle(el).getPropertyValue(name).trim();

    return new Color(value || fallback);
}

export function isSupported() {
    try {
        const canvas = document.createElement('canvas');

        return Boolean(window.WebGLRenderingContext && canvas.getContext('webgl2'));
    } catch {
        return false;
    }
}

/**
 * Mount the solid into `host`.
 *
 * @param {HTMLElement} host        element the canvas fills
 * @param {object}      options
 * @param {Function}    options.onPick   called with a zone key, or null
 * @returns {{setFills:Function, setSelected:Function, setTheme:Function, resize:Function, destroy:Function}}
 */
export function mount(host, { onPick } = {}) {
    const scene = new Scene();
    const camera = new PerspectiveCamera(30, 1, 0.1, 100);
    const renderer = new WebGLRenderer({ alpha: true, antialias: true });
    // Two is the point past which retina costs pixels without showing
    // more; phones with a DPR of 3 would pay 2.25x for nothing.
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setClearAlpha(0);
    host.appendChild(renderer.domElement);
    renderer.domElement.setAttribute('aria-hidden', 'true');

    scene.add(new AmbientLight(0xffffff, 1));

    const root = new Group();
    scene.add(root);

    let dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    let matcap = clayMatcap(dark);
    const zones = new Map();
    let selected = null;
    let fills = {};
    let frame = null;
    let disposed = false;

    // The beat. Both numbers come off the host, resolved server-side
    // beside the HRV card's own reading, so the organ cannot disagree
    // with the number the page prints. Without them the heart is still:
    // an interval invented here would animate a measurement nobody took.
    const beat = {
        interval: Number(host.dataset.beatInterval) || 0,
        sway: Number(host.dataset.beatSway) || 0,
    };
    // The group the heart mesh hangs under, and the thing that actually
    // scales; see the loader for why it is not the mesh itself.
    let heart = null;
    // Start of the stroke being drawn, null between beats, and the phase
    // through the breathing cycle that decides the next gap.
    let stroke = null;
    let phase = 0;
    let timer = null;
    // Whether the solid is on screen at all. A canvas hidden behind the
    // flat map still gets its frames, and a heart beating there would be
    // the one thing on this page that runs unwatched.
    let shown = false;

    // One turntable angle, driven by drag and by the arrow keys. Only Y:
    // a body map has an up, and letting the figure tumble would cost the
    // reader the one orientation they navigate by.
    let yaw = 0;
    let targetYaw = 0;
    let dragging = false;
    let lastX = 0;

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

    /* Where a ramp step sits in the solid's own lightness band.
     *
     * The flat map picks its fills against a card that also hands the
     * figure seam strokes, hatching and a shading overlay to carry shape.
     * The solid has one channel, the fill, and the matcap multiplies it:
     * taken literally, the low half of the dark ramp lands under the card
     * colour and the body dissolves into it. So the solid keeps the ramp's
     * hue and saturation, which is where the meaning lives, and remaps
     * only lightness into a band that survives the multiply. Monotone, so
     * the order the legend promises is untouched; per theme, because the
     * band has to run the other way against a light card.
     */
    function bandLightness(step) {
        const [from, to] = dark ? [0.34, 0.78] : [0.74, 0.3];

        return from + (to - from) * (step / (RAMP_STEPS - 1));
    }

    /* Zones with no load, and the parts that make no claim at all. Both
     * sit at the quiet end of the band so the body still reads as a body,
     * with the inert parts a step further back than a recovered muscle.
     * The step back is small on purpose: the matcap floor multiplies it
     * again, and a deeper offset put the shaded side of the head and hands
     * within 0.003 lightness of the card, which ate the silhouette exactly
     * where the figure needs an edge. */
    function restColour(inert) {
        const l = bandLightness(0) + (inert ? (dark ? -0.04 : 0.06) : 0);

        return new Color().setHSL(dark ? 0.6 : 0.62, inert ? 0.03 : 0.06, l);
    }

    /* The one flat colour a zone's material carries. */
    function zoneColour(key) {
        if (NO_CLAIM.has(key)) {
            return restColour(true);
        }
        const value = fills[key];
        // The fill arrives as the same `var(--load-n)` string the flat map
        // uses, so it is resolved here rather than parsed.
        const varName = typeof value === 'string' && value.startsWith('var(')
            ? value.slice(4, -1).trim()
            : null;
        const step = varName ? Number(varName.match(/^--load-(\d)$/)?.[1]) : NaN;
        if (!Number.isInteger(step)) {
            // Either recovered (--map-neutral) or a zone with no data.
            return restColour(false);
        }

        const colour = cssColor(host, varName, '#293651');
        const hsl = {};
        colour.getHSL(hsl);
        // Saturation is lifted because the matcap washes it out and the
        // solid has no hatching to fall back on when two steps sit close.
        //
        // Written as a move toward full saturation rather than as a
        // multiplier, and the distance grows with the step. A multiplier
        // gets both ends wrong: flat, it makes a zone one point past the
        // neutral threshold shout as loudly as a hard-worked one; large
        // enough to matter at the top, it clamps at 1 and the two loudest
        // steps of the dark ramp come out identically saturated, which is
        // the opposite of what this is for. Approaching 1 can never
        // clamp, so it keeps whatever spacing the CSS ramp encodes.
        const reach = 0.03 + 0.47 * (step / (RAMP_STEPS - 1));
        colour.setHSL(hsl.h, hsl.s + (1 - hsl.s) * reach, bandLightness(step));

        return colour;
    }

    function paint() {
        // One flat colour per zone. The earlier mesh was a single skin
        // surface cut into regions, so two zones met along a shared
        // triangle edge and a flat colour each drew that edge as a saw
        // blade; a per-vertex blend existed only to quiet it. Every zone
        // is a real muscle now, with space between it and the next, so
        // there is no shared edge to hide and bleeding one zone's colour
        // across the gap would put a reading on a muscle it never touches.
        //
        // A pick makes exactly one muscle solid and turns the whole rest of
        // the body, bone included, into a colourless ghost. Two things fall
        // out of that, and both were the point:
        //
        //   - only the picked muscle carries a load colour, so nothing else
        //     can be mistaken for part of the answer. The earlier version
        //     merely dimmed the others, which left a hard-worked neighbour
        //     glowing next to the pick.
        //   - the pick stays readable when something covers it. It moves to
        //     its own layer and render() draws that layer against a cleared
        //     depth buffer, so the body in front of it is simply no longer
        //     in the way. Letting the ghosts blend over it instead was the
        //     first attempt and it fails on exactly the case this is for: a
        //     deep muscle sits behind several layers, and three layers of
        //     ghost cost it 40 % of its colour. Its colour has to keep
        //     meaning what the legend says.
        //
        // The pick keeps its plain load colour rather than a highlight
        // lift. Being the only solid thing on screen already carries the
        // focus, and a lift would push the one zone the reader is looking
        // at off the scale the legend promises.
        const focused = selected !== null;
        const fade = GHOST[dark ? 'dark' : 'light'];
        const tint = ORGAN[dark ? 'dark' : 'light'];

        zones.forEach((mesh, key) => {
            // The heart answers to its system's key rather than to its own
            // mesh name, because that is what a tap on it selects.
            const mine = key === HEART ? selected === ORGAN_PICK : selected === key;
            const ghost = focused && !mine;
            // The heart shows through the chest while nothing is picked,
            // and ghosts with everything else once a muscle is: the pick
            // has to be the only solid thing on screen, and an organ
            // glowing through it would be a second one. Picked itself, it
            // stays on its own layer and comes forward instead.
            const organ = key === HEART && !ghost;
            const material = mesh.material;

            material.color = organ
                ? new Color().setHSL(tint.hue, tint.sat, tint.lightness)
                : ghost
                    ? new Color().setHSL(fade.hue, 0.05, fade.lightness)
                    : zoneColour(key);
            material.opacity = organ ? (mine ? tint.picked : tint.alpha) : ghost ? fade.alpha : 1;
            // Ghosts do not write depth, so they read as one x-ray wash
            // rather than as whichever of them happened to draw first. The
            // pick does, because it gets a depth buffer of its own and has
            // to occlude itself properly inside it. So does the heart, for
            // the same reason: it is a closed surface, and without it the
            // far wall shows through the near one as a second outline.
            material.depthWrite = !ghost;
            mesh.layers.set(organ ? LAYER_ORGAN : ghost || !focused ? LAYER_BODY : LAYER_PICK);
            const clear = organ || ghost;
            if (material.transparent !== clear) {
                // Only on the flip: `transparent` is part of the program
                // cache key, so setting it every paint would recompile
                // eighteen shaders on every pointer move.
                material.transparent = clear;
                material.needsUpdate = true;
            }
        });
        request();
    }

    /* Whether the heart is the thing on screen it is drawn as: an organ on
     * its own layer, in front of the chest. True with nothing picked and
     * true when it is itself the pick, false behind a picked muscle, where
     * it is a ghost in the body like everything else. Rendering and
     * picking both read it, so neither can be right while the other is
     * wrong about what the reader is looking at. */
    function organShowing() {
        return zones.has(HEART) && (selected === null || selected === ORGAN_PICK);
    }

    /* How far into the stroke the heart is, as a scale factor. Rises to
     * the peak, falls back over the rest, both eased: a linear ramp reads
     * as a twitch rather than as a squeeze. */
    function strokeScale(now) {
        if (stroke === null) {
            return 1;
        }
        const t = (now - stroke) / BEAT_MS;
        if (t >= 1) {
            stroke = null;

            return 1;
        }
        const ramp = t < BEAT_PEAK ? t / BEAT_PEAK : (1 - t) / (1 - BEAT_PEAK);

        return 1 + BEAT_REACH * (ramp * ramp * (3 - 2 * ramp));
    }

    /* One beat, and the timer for the next one.
     *
     * Timers rather than a fixed animation, because a beat that decides
     * its own next interval cannot be written as a curve: the gap swings
     * over the breathing cycle, and that swing is the whole reading. The
     * same argument, and the same two numbers, drive the heart on the HRV
     * tile; see beatHearts() in app.js.
     */
    function pump() {
        stroke = performance.now();
        request();
        const gap = beat.interval * (1 + beat.sway * Math.sin((2 * Math.PI * phase) / BREATH_MS));
        phase = (phase + gap) % BREATH_MS;
        timer = setTimeout(pump, gap);
    }

    function startBeat() {
        stopBeat();
        // No interval means no measured pulse, and there is nothing to
        // beat out. Reduced motion, a background tab and the flat map all
        // mean nobody is watching this run.
        if (!heart || !beat.interval || reduced.matches || document.hidden || !shown) {
            return;
        }
        pump();
    }

    function stopBeat() {
        clearTimeout(timer);
        timer = null;
        stroke = null;
        heart?.scale.setScalar(1);
        request();
    }

    function render() {
        frame = null;
        // Ease toward the target so a drag feels like mass rather than a
        // slider, unless the reader asked for no motion.
        const step = reduced.matches ? 1 : 0.18;
        if (Math.abs(targetYaw - yaw) > 0.0005) {
            yaw += (targetYaw - yaw) * step;
            request();
        } else {
            yaw = targetYaw;
        }
        root.rotation.y = yaw;

        if (heart) {
            heart.scale.setScalar(strokeScale(performance.now()));
            // Frames only while a stroke is running. Between beats the
            // figure is still again, which is what keeps a body map from
            // becoming the page's largest battery cost.
            if (stroke !== null) {
                request();
            }
        }

        const organ = organShowing();

        if (selected === null && !organ) {
            camera.layers.set(LAYER_BODY);
            renderer.autoClear = true;
            renderer.render(scene, camera);

            return;
        }

        // Passes with the depth buffer cleared between them. The body
        // draws first, and whatever comes after it draws into an empty
        // depth buffer, which puts it in front of everything while it
        // still occludes itself correctly: three heads of a hamstring
        // overlap, and simply switching the depth test off drew the far
        // one over the near one and made the muscle look hollow.
        //
        // The heart takes the first of those passes and a picked muscle the
        // second, so the muscle is never behind the organ that happens to
        // sit in the same chest. Only one of them is ever on: a muscle pick
        // sends the heart back into the ghost body.
        renderer.autoClear = false;
        renderer.clear();
        camera.layers.set(LAYER_BODY);
        renderer.render(scene, camera);
        renderer.clearDepth();
        if (organ) {
            camera.layers.set(LAYER_ORGAN);
            renderer.render(scene, camera);
        }
        if (selected !== null && selected !== ORGAN_PICK) {
            camera.layers.set(LAYER_PICK);
            renderer.render(scene, camera);
        }
    }

    // Render on demand only. A permanently spinning loop is the single
    // largest battery cost a page like this can carry, and the figure is
    // still most of the time.
    function request() {
        if (!disposed && frame === null) {
            frame = requestAnimationFrame(render);
        }
    }

    function resize() {
        const w = host.clientWidth;
        const h = host.clientHeight;
        if (!w || !h) {
            return;
        }
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        request();
    }

    const raycaster = new Raycaster();
    // Layers decide which pass draws a mesh, and a ray has no passes: it
    // asks what is under the pointer. A raycaster tests layer 0 only, so
    // without this the heart is invisible to a tap for exactly the reason
    // it is visible to the eye, which is that it is drawn on its own one.
    raycaster.layers.enableAll();
    const pointer = new Vector2();

    function pick(event) {
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
        pointer.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
        raycaster.setFromCamera(pointer, camera);
        const under = raycaster
            .intersectObjects(root.children, true)
            .map((hit) => hit.object?.userData?.zone)
            .filter(Boolean);

        // The heart first, and out of depth order on purpose. It lies
        // behind the sternum and the chest muscle, so by distance it never
        // wins; on screen it is drawn in front of both, and a tap has to
        // answer to what the reader can see rather than to what is
        // nearest. Only while it is showing: with a muscle picked it is a
        // ghost like everything else, and picking a ghost is picking blind.
        if (organShowing() && under.includes(HEART)) {
            onPick?.(ORGAN_PICK);

            return;
        }

        // Otherwise the first thing under the pointer that actually claims
        // a load. The head and the bones are skipped rather than treated as
        // a miss: they sit in front of real muscle, and once something is
        // picked they are transparent, so a reader aiming at a muscle they
        // can plainly see through a hand should not be told there is
        // nothing there.
        const key = under.find((zone) => !NO_CLAIM.has(zone));
        if (key) {
            onPick?.(key);
        }
    }

    // Drag to turn, and a drag must not also count as a tap.
    let moved = 0;

    function onDown(event) {
        dragging = true;
        moved = 0;
        lastX = event.clientX;
        renderer.domElement.setPointerCapture?.(event.pointerId);
    }

    function onMove(event) {
        if (!dragging) {
            return;
        }
        const dx = event.clientX - lastX;
        lastX = event.clientX;
        moved += Math.abs(dx);
        targetYaw += dx * 0.011;
        request();
    }

    function onUp(event) {
        if (dragging && moved < 4) {
            pick(event);
        }
        dragging = false;
    }

    function onKey(event) {
        const by = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0;
        if (!by) {
            return;
        }
        event.preventDefault();
        targetYaw += by * MathUtils.degToRad(30);
        request();
    }

    renderer.domElement.addEventListener('pointerdown', onDown);
    renderer.domElement.addEventListener('pointermove', onMove);
    renderer.domElement.addEventListener('pointerup', onUp);
    renderer.domElement.addEventListener('pointercancel', () => { dragging = false; });
    host.addEventListener('keydown', onKey);

    // Everything that decides whether the heart may run, watched rather
    // than asked once at startup.
    //
    // The third of them is why this is an observer and not a flag pushed
    // in from outside: the viewer outlives a switch back to the flat map,
    // it sits inside a tab panel that can be switched away, and the card
    // can simply be scrolled past. All three end as an element that is not
    // on screen, and one observer answers for all three.
    const onHidden = () => (document.hidden ? stopBeat() : startBeat());
    document.addEventListener('visibilitychange', onHidden);
    reduced.addEventListener('change', startBeat);

    const watcher = new IntersectionObserver(([entry]) => {
        shown = entry.isIntersecting;
        shown ? startBeat() : stopBeat();
    });
    watcher.observe(host);

    const observer = new ResizeObserver(resize);
    observer.observe(host);

    new GLTFLoader().setMeshoptDecoder(MeshoptDecoder).load(modelUrl, (gltf) => {
        if (disposed) {
            return;
        }
        gltf.scene.traverse((child) => {
            if (!child.isMesh) {
                return;
            }
            const key = child.name || child.parent?.name || INERT;
            child.userData.zone = key;
            child.material = new MeshMatcapMaterial({ matcap, color: new Color('#ffffff') });
            zones.set(key, child);
        });

        // A beat is a scale, and a scale is about the node's own origin,
        // which is nowhere near the heart: the mesh arrives quantised, so
        // its node already carries the position and scale that decode it.
        // Writing a centre into either of those is what put the heart in
        // the pelvis, several centimetres of decoded model away from where
        // it belongs. Hanging it under a group placed at its own middle
        // touches neither: the group is what beats.
        const organ = zones.get(HEART);
        if (organ) {
            heart = new Group();
            const mid = new Box3().setFromObject(organ).getCenter(new Vector3());
            heart.position.copy(mid);
            organ.position.sub(mid);
            gltf.scene.add(heart);
            heart.add(organ);
        }

        // Centre the figure on its own bounds and frame the camera off its
        // height, rather than off numbers that a re-export would break.
        root.add(gltf.scene);
        const bounds = new Box3().setFromObject(gltf.scene);
        const centre = bounds.getCenter(new Vector3());
        const size = bounds.getSize(new Vector3());
        gltf.scene.position.sub(centre);
        camera.position.set(0, 0, (size.y || 1.8) * 2.05);
        camera.lookAt(0, 0, 0);

        paint();
        resize();
        startBeat();
        host.dataset.ready = 'true';
    });

    return {
        setFills(next) {
            fills = next || {};
            paint();
        },
        setSelected(key) {
            selected = key;
            paint();
        },
        setTheme(isDark) {
            dark = isDark;
            matcap.dispose();
            matcap = clayMatcap(dark);
            zones.forEach((mesh) => { mesh.material.matcap = matcap; });
            paint();
        },
        // Turn the figure to show a zone the reader picked somewhere else.
        faceBack(back) {
            targetYaw = back ? Math.PI : 0;
            request();
        },
        resize,
        destroy() {
            disposed = true;
            stopBeat();
            observer.disconnect();
            watcher.disconnect();
            document.removeEventListener('visibilitychange', onHidden);
            reduced.removeEventListener('change', startBeat);
            host.removeEventListener('keydown', onKey);
            zones.forEach((mesh) => {
                mesh.geometry.dispose();
                mesh.material.dispose();
            });
            matcap.dispose();
            renderer.dispose();
            renderer.domElement.remove();
        },
    };
}
