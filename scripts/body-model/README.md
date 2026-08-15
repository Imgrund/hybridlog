# Rebuilding `resources/models/body-zones.glb`

The écorché in the 3D body map is a derived asset. This is the recipe that
derives it, kept next to the file it produces so a future change to the
zones is an afternoon rather than an archaeology project.

Read `resources/models/CREDITS.md` first. The source is share-alike and the
obligations that puts on the output file are described there.

## What you need

- `python3` with `numpy`
- `npx` for `@gltf-transform/cli` (fetched on demand, nothing to install)
- about 450 MB of scratch space and a working network connection

Nothing here runs in CI or at deploy time. The GLB is committed; this only
runs when the zones or the reduction have to change.

## Run

```sh
cd scripts/body-model
./fetch.sh                      # the two indexes, then 230 structures,
                                # ~427 MB, skips whatever is already here
python3 build_glb.py 3.0        # zones + head + heart, clustered onto a 3 mm grid
npx @gltf-transform/cli weld zones_raw.glb zones_weld.glb
npx @gltf-transform/cli simplify zones_weld.glb zones_simp.glb --ratio 0.12 --error 0.002
npx @gltf-transform/cli merge zones_simp.glb smooth_raw.glb zones_merged.glb --merge-scenes
python3 normals.py zones_merged.glb zones_norm.glb
npx @gltf-transform/cli meshopt zones_norm.glb zones_final.glb
cp zones_final.glb ../../resources/models/body-zones.glb
```

Expect about 66,714 vertices and 684 kB, and a line reporting the neck at
roughly 1436 mm with the cut 70 mm below it. A neck anywhere near the top
of the head means the walk in `crop_to_head` ended early; the crown is
narrower than the neck, so that failure looks like a plausible number
rather than an error.

## The five numbers that shape the head

The head keeps the scan's measurements and none of its shapes, and these
decide how far the reduction goes.

`HEAD_REACH` is the percentile of the radii in a cell that the cell keeps.
The maximum was tried first and is what a lump on the head is made of: it
takes the nose, an ear, or one stray triangle of scan noise and builds the
surface out to it.

`HEAD_HARMONICS` is what survives around a cross-section. At 2 the section
is an ellipse, which is the point: there is no term left that could carry a
brow or a jaw line. Raising it is how the head grows a face back, and it
does not take much. At 10 it has a jaw, cheekbones and the ridge of a nose,
which is anatomically right and reads, at the forty pixels the card gives
the figure, as one specific person seen badly.

`HEAD_SMOOTH` averages up the head, over 15 rings, twice. It is what keeps
one ring from stepping out past its neighbours. Raising it much further
costs the chin: at 25 the average reaches from the jaw past the throat and
the head sits on the neck as one bell.

`HEAD_CROWN` is where the measured rings hand over to a drawn quarter
ellipse, and it wants to be high. At 0.82 the dome takes over while the
skull is still widening into it and the head comes to a point like a hood;
0.93 closes it round. Two things about that step are load-bearing and both
were bugs first: it scales the rings that are there rather than replacing
them with the last measured one (replacing stands the skull up as a
cylinder and leaves a rim around it like a swimming cap), and it runs after
the vertical average rather than before it (an average that reaches past
the crown lifts the tip back off the axis, and the head is then open at the
top, invisible until the camera looks down into it).

`HEAD_NECK_DROP` is how far below the neck the body carries on, and it is
what keeps the head from ending on screen. Cut at the neck, the mesh stops
in mid-air under the jaw, where the head is 110 mm deep and the trapezius
it lands on is 40: head-on that passes, from the side it is a jaw and a
nape hanging off a stalk. Seventy millimetres puts the last ring inside the
shoulders. Below the neck the radius is also capped at the neck's own,
because down there the scan is measuring shoulders and a lathe radius
around a shoulder is a cone.

That skin costs a little of the trapezius, which runs up the side of the
neck. Rendered with the zone flagged and its pixels counted, the loss is
0 % from behind, 0.2 % from the side and 11 % from the front, where six
times less of it is visible in the first place.

## The heart

The one part of the model that is not there to be looked at. It beats, at
the reader's own resting pulse and with their HRV as the unevenness of the
interval; `body3d.js` drives that and `App\View\Heartbeat` supplies the two
numbers. Tapping it opens the cardiovascular finding, the same panel the
marker over the chest opens on the flat map.

It resolves as `wall of heart`, not `heart`: the dataset lists both and
ships an STL only for the wall, which is the better half anyway. The wall
is the closed outer surface, and the chambers and valves inside it would be
triangles behind it.

`HEART_CELL` is why it is clustered coarser than the muscles. A wall has an
inner surface too, and the chamber walls between the two, none of which is
ever seen: at the 3 mm the zones use, that came to 11,544 points, a sixth
of the whole model, for an organ glimpsed through a chest. At 5 mm it is
3,874 with an unchanged outline. The shallow dimples the coarser grid
leaves are the inner wall pulling on the outer one, well under anything the
figure can resolve on screen. Do not go past 8 mm, where the outline itself
goes lumpy.

## The merge step, and two ways to lose the head

`build_glb.py` writes two files. `smooth_raw.glb` carries the head and the
heart, and they go round the simplifier: its error bound is three
millimetres of the whole figure, which a muscle belly carries and a
skull-sized sphere does not, and the head came back out of it terraced like
a contour map. The heart is smaller again, so it would come out worse.

Both flags on the merge line are load-bearing.

- Without `--merge-scenes` the merge gives each input its own scene, and
  `GLTFLoader` loads scene 0. The head is then in the file, weighs what it
  should weigh, and is not on screen.
- `--merge-scenes` has to come after the output path, not between the
  inputs and it. The final positional path is the output, so putting the
  flag in the middle makes `smooth_raw.glb` the output: the merge
  overwrites it with a copy of the zones, `zones_merged.glb` keeps whatever
  it held from the last run, and the build reports success at roughly the
  right file size.

## The two numbers that decide the decimation

`--error` binds, not `--ratio`. At `0.0008` the model came out at 126k
vertices and 1.35 MB; at `0.003` the thin sheets of the abdominal wall
collapsed into noise. `0.002` is the compromise that was shipped.

The 3 mm grid in `build_glb.py` is not the final resolution. It exists so
the quadric simplifier gets an input it can hold in memory, and it places
each surviving vertex at the mean of its cell rather than at the lattice
point, which keeps the surface where it was instead of faceting every
muscle.

## Changing the zones

Edit `ZONES` in `zones.py`. It resolves structures by anatomical name
rather than by FMA id on purpose: a rename upstream then surfaces as a
missing muscle instead of as a silently empty zone. The script asserts that
no two zones claim the same structure, so an overlapping pattern fails loudly.

Zone names must keep matching `config/muscle_map.php`, because
`resources/js/body3d.js` looks the meshes up by those names. `NONE`,
`HEAD` and `HEART` carry no load; all three are listed in `NO_CLAIM`
there, and a fourth added here has to be added there too or it will be
painted as if it had a load and will answer to a tap.

## After a rebuild

Check it in the browser, not only in the file size:

- every zone takes its colour (a zone whose pattern matched nothing renders
  as nothing at all, and an empty mesh is easy to miss in a diff)
- picking opens the right zone
- the figure faces the camera
- the head is a head, and the neck runs into the shoulders rather than
  stopping short of them
- turn the figure and look at the head from the side and from above. Every
  way it went wrong was invisible head-on: an overhanging jaw and nape need
  a profile, a rim around the skull needs a raking angle, and a head left
  open at the crown needs the camera above it.
- the heart sits behind the sternum and slightly to the anatomical left,
  it beats, and tapping it opens the cardiovascular finding. It is
  quantised like everything else in the file, so its node already carries
  the transform that decodes it: anything that writes a position or a
  scale onto that node instead of onto the group above it moves the organ
  into the pelvis, which is what happened the first time. A tap that opens
  the chest muscle instead means the raycaster is back on layer 0 only,
  where the organ's own layer is invisible to it.

One trap when checking in a headless browser: the canvas renders on demand,
so a second screenshot composites an empty drawing buffer and looks like a
blank stage. Grab the element (`page.$('.bm-stage')` then `.screenshot()`)
rather than computing a clip, and note that `getBoundingClientRect` is in
viewport coordinates while `page.screenshot({clip})` wants page ones.

The orientation comes out of the data: `build_glb.py` picks the longest
bounding-box axis as up and flips depth so the body faces the viewer. A
different source release could change that, and the only way to see it is
to look.
