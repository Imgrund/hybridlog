# Model credits

## `body-zones.glb`

An écorché: the muscles the freshness model names (`config/muscle_map.php`),
each grouped into one of sixteen zone meshes, plus three groups that make no
load claim, `NONE` for the bone a skinless figure shows, `HEAD`, and
`HEART`. 66,714 vertices, 684 kB.

`HEART` is `wall of heart`, and it is the only part of the file with a
reading of its own. It shows through the chest, beats at the athlete's
resting pulse with their HRV as the unevenness between beats, and answers
to a tap with the cardiovascular finding. The wall rather than the whole
heart because the dataset ships an STL only for the wall, which is also all
that is wanted: it is the closed outer surface, and the chambers and valves
inside it would be triangles behind it. It is clustered at 5 mm rather than
the 3 mm the muscles get, because a wall carries an inner surface too and
nothing up there survives to be seen.

The head carries the scan's measurements and none of its shapes. It is
measured as a radius per height and angle, the two sides are averaged into
each other, and every cross-section is then reduced to an ellipse. What is
left is a head of the right size in the right place, tapering the way the
scan's does. What is gone is everything that could be read as a face.

`HEAD` takes the neck with it and ends inside the shoulders, because a head
has to end somewhere and every place it can end on its own looks wrong.
Cut under the jaw, where the head is 110 mm deep and the trapezius beneath
it is 40, it overhangs its own stalk from the side. Closed off like the
crown, it is an egg resting on the shoulders. Below the neck nothing is
allowed to be wider than the neck, so the last stretch stays a neck instead
of flaring into the shoulders it is measured against.

Getting there took four attempts. The bare skull is a memento mori. The
scan's own skin surface keeps the sunken eyes and the parted lips and reads
as a death mask. A head drawn from control points has no face to go wrong
and looks like an egg on a stick, because a head's form is jaw and cheek and
chin and nobody draws those from memory. Keeping the low frequencies of the
scan draws those three correctly and still fails: at the size the card gives
the figure, a jaw, a nose and two painted-on eyes read as one specific
person, seen badly. Reducing the head past the point where any of it can be
read as a feature is the one that holds.

The skin over the neck costs a little of the trapezius, which runs up the
side of it and is a zone with a reading of its own. Measured against a
render with the zone flagged, that is 0 % of it from behind, 0.2 % from the
side and 11 % from the front, where six times less of it is visible to
begin with.

### License, and what it obliges

> BodyParts3D, Copyright© The Database Center for Life Science
> licensed by CC Attribution-Share Alike 2.1 Japan

- **Source:** [BodyParts3D / Anatomography](http://lifesciencedb.jp/bp3d/),
  Database Center for Life Science. Polygons derived from a full-body MRI
  set of one adult male ("TARO"). Fetched from the
  [STL conversion by Kevin Mattheus Moerman](https://github.com/Kevin-Mattheus-Moerman/BodyParts3D).
- **License:** CC Attribution-Share Alike 2.1 Japan. Share-alike, and the
  dataset's own README says so in as many words: "You must distribute any
  derivative work based on part or whole of the data from this database
  under this License."
- **What that binds:** this file. `body-zones.glb` is an adaptation of the
  BodyParts3D data, so it carries CC BY-SA 2.1 Japan and the credit above,
  and anyone who receives it may reuse it under the same terms. It does not
  reach the application around it: a page that serves the model is a
  collection, not an adaptation of it.
- **Watch out:** the NBDC archive mirror
  ([dbarchive.biosciencedbc.jp](https://dbarchive.biosciencedbc.jp/en/bodyparts3d/lic.html))
  states CC BY 4.0 for the same dataset, which contradicts the license file
  shipped with the data. The stricter of the two is the one honoured here.
  Do not "correct" this to CC BY on the strength of the mirror.

### How the file is produced

The source is 934 separately named structures, about 1.25 GB of binary STL.
The 230 the zones need are 427 MB, which is not something to keep in a repo,
so `scripts/body-model/` fetches them on demand instead of vendoring them.
Its README carries the commands; what follows is why each step exists.

1. Resolve zone → structure by anatomical name, not by hardcoded FMA id, so
   a rename in the source surfaces as a miss rather than as a silent gap.
   Sub-heads matter and are the reason for going to this dataset at all: a
   deltoid is three parts here, and its front and back belong to two
   different zones. The head is the one exception to "load-bearing muscle
   only": it is `skin`, one 76 MB structure covering the whole body, cut
   seven centimetres below the narrowest slab under the skull's widest.
   That slab is the neck and nothing else up there; taking the narrowest
   one outright lands on the crown, which is narrower still. What survives
   the crop is measured, not kept: the head is rebuilt from those
   measurements as one ellipse per cross-section, and the scan's own
   triangles up there are thrown away.
2. Cluster the triangle soup onto a 3 mm grid, placing each surviving vertex
   at the mean of what fell into its cell rather than at the lattice point,
   which keeps the surface where it was instead of faceting every muscle.
   This is only to get the quadric pass something it can hold in memory. The
   heart gets 5 mm, because most of what its structure contains is an inner
   surface nothing can see.
3. `gltf-transform weld`, then `simplify --ratio 0.12 --error 0.002`. The
   error bound is what binds, not the ratio: at 0.0008 the model came out at
   126k vertices and 1.35 MB, at 0.003 the thin sheets of the abdominal wall
   collapsed into noise.
4. Merge the head and the heart back in. They travel in their own file and
   skip step 3, because three millimetres of chord error is nothing on a
   muscle belly and a terrace on a skull-sized sphere. Both are drawn at the
   resolution they should have, so there is nothing to take off them.
5. Compute angle-weighted vertex normals across zone boundaries, not per
   mesh. A matcap samples nothing but the normal, and normals averaged per
   mesh crease along every border.
6. `gltf-transform meshopt`. Meshopt rather than Draco because its decoder
   is a plain ES module the bundler can swallow, so the model needs no
   second asset path to survive a deploy.

Structures are deliberately dropped. The internal oblique lies under the
external one and is never seen, and being a thin sheet it survives the
decimation badly enough to poke through the muscle covering it. Femur,
humerus, pelvis, ribs, scapula and the vertebrae are covered by the muscles
in front of them, so they would cost triangles nobody ever sees. The
eighteen cranial bones went the same way when the head arrived: nothing up
there is visible through a closed head.

### What this model is, and is not

The zones are real muscle bellies, so a zone border is a muscle border. That
is what the previous model could not do: it was a smooth skin surface cut
into sixteen regions, and its boundaries were clean cuts rather than
anatomy. The flat SVG map beside it (`resources/data/body-polygons.json`,
MIT, from the body-highlighter project family) stays the primary rendering
because it is the accessible one, not because it is the finer one.

It is one adult male body, not the athlete's. Nothing in the reading depends
on the geometry: the figure shows where a zone is, the colour shows what the
data says about it.

### Alternatives considered

- **MakeHuman base mesh** (CC0) was the previous model and carries no
  licence obligation at all. It is a base mesh, so it is smooth by
  construction: no muscle relief, and the zones had to be painted regions.
- **Z-Anatomy** (CC BY-SA 4.0) is tidier than BodyParts3D but shares the
  same share-alike obligation, without the advantage of being the source
  everything else derives from.
- **Blender Studio Human Base Meshes** (CC0) would give better proportions
  and no obligation, but a base mesh again has no muscle relief, and it
  ships as a `.blend` that needs Blender to export.
- **SMPL / STAR / SUPR** are non-commercial *and* carry an explicit
  redistribution ban, so they cannot live in a repository at all.
