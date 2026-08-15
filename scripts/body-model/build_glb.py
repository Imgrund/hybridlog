"""Merge the BodyParts3D parts into one glTF, one mesh per freshness zone.

The parts arrive as binary STL triangle soup in the scanner's millimetre
frame. This does three things and leaves the rest to gltf-transform: it
groups parts into the sixteen zones, it collapses the soup onto a grid so
the quadric simplifier downstream gets something it can chew in memory,
and it puts the body into the same frame the old model used, so body3d.js
needs no change to camera or picking.
"""
import json
import struct
import sys
from pathlib import Path

import numpy as np

import zones

HERE = Path(__file__).parent
STL = HERE / 'stl'

# Cell size of the clustering grid, in source millimetres. The whole point
# is to get under the memory the quadric pass needs while keeping enough
# shape for it to judge; it is not the final resolution.
CELL = float(sys.argv[1]) if len(sys.argv) > 1 else 4.0

# The heart never gets a finer grid than this, whatever the muscles get.
# "Wall of heart" is a wall: an outer surface, an inner one and the chamber
# walls between them, and only the first of those is ever seen. On the 3 mm
# the zones use it came to 11,544 points, a sixth of the whole model for an
# organ glimpsed through a chest. At 5 mm it is 3,874 and the silhouette is
# unchanged; the shallow dimples the coarser grid leaves are the inner wall
# pulling on the outer one, at a scale nothing on screen can resolve. Below
# 8 mm because there the outline itself goes lumpy.
HEART_CELL = max(CELL, 5.0)

# The old model stood about 17 units tall and body3d.js frames the camera
# off the bounding box, so any consistent scale works. Keeping 17 means the
# BLEND_RADIUS that was tuned against the old mesh still means the same
# distance on this one.
TARGET_HEIGHT = 17.0

# The head. Its measurements are the scan's, its shapes are not: see
# head_surface().
#
# HEAD_REACH is the percentile of the skin radius a cell keeps, and the two
# after it are how much structure survives around a cross-section and up
# the head. Two harmonics is an ellipse: size, offset from the axis, oval,
# and nothing finer. A brow, a cheekbone and the ridge of a nose all live
# above that, and all three are features rather than form.
HEAD_REACH = 80
HEAD_HARMONICS = 2
HEAD_SMOOTH = 15

# Where the crown starts closing, as a fraction of the head's height. The
# scan thins out at the very top, where a horizontal slab holds few
# triangles and the measured radius wanders, so the last stretch is drawn
# as a quarter ellipse rather than measured. Low is worse than high here:
# at 0.82 the drawn dome takes over while the skull is still widening out
# of it and the head comes to a point like a hood.
HEAD_CROWN = 0.93

# How far below the narrowest point of the neck the body carries on, in
# millimetres. It exists so the head has somewhere to end. Cut at the neck
# and the mesh stops in mid-air under the jaw, where the head is 110 mm deep
# and the trapezius it lands on is 40: from the side that is a jaw and a
# nape hanging off a stalk, which is the one angle it was never checked
# from. Seventy millimetres puts the last ring inside the shoulders, so the
# neck ends where nobody can see it end.
HEAD_NECK_DROP = 70.0


def read_stl(path):
    """Vertices of one binary STL, as (n, 3, 3) float32."""
    raw = path.read_bytes()
    count = struct.unpack('<I', raw[80:84])[0]
    body = np.frombuffer(raw, dtype=np.uint8, count=count * 50, offset=84)
    # 50 bytes per facet: normal, three corners, then a two-byte attribute
    # that has to be stripped before the floats can be read as a block.
    tri = body.reshape(count, 50)[:, 12:48].copy().view(np.float32)

    return tri.reshape(count, 3, 3)


def crop_to_head(tris, up):
    """Keep the head, the neck, and enough below it to hide the cut.

    The neck is found rather than measured off a table, so it stays
    anatomical if the source is ever re-released at another scale: start at
    the widest slab of the skull and walk down until the figure stops
    getting narrower. That is the neck. Taking the narrowest slab outright
    does not work, because the crown of the head is narrower still.

    The cut itself goes HEAD_NECK_DROP below that, into the shoulders. What
    it must not do is take the shoulders with it, which is what the deck in
    head_surface() is for.

    A slab is measured between its 1st and 99th percentile rather than
    corner to corner. Corner to corner, one triangle of an earlobe counts as
    much as the neck it hangs beside, and the walk stops a centimetre high.

    Returns the triangles and the height of the neck, which is the level the
    surface below is held to.
    """
    step = 10.0
    flat = tris.reshape(-1, 3)
    fz = flat[:, up]
    across = next(a for a in (0, 1, 2) if a != up)
    lo, hi = fz.min(), fz.max()

    def width_at(level):
        slab = flat[(fz >= level) & (fz < level + step)]
        # A slab too thin to be a cross-section says nothing about a neck.
        if len(slab) <= 200:
            return np.inf
        low, high = np.percentile(slab[:, across], [1, 99])

        return high - low

    skull = np.arange(hi - (hi - lo) * 0.10, hi, step)
    level = skull[int(np.argmax([width_at(x) for x in skull]))]

    # A millimetre of tolerance so a single noisy slab does not end the
    # walk on the way down the neck; the shoulders widen by centimetres a
    # slab, so nothing can carry it past them.
    best = (width_at(level), level)
    while level > lo + step:
        level -= step
        seen = width_at(level)
        if seen > best[0] + 1.0:
            break
        if seen < best[0]:
            best = (seen, level)

    neck = best[1]
    keep = tris[:, :, up].mean(axis=1) >= neck - HEAD_NECK_DROP
    print(f'{"HEAD":16s} Hals bei {neck:.0f} mm ({best[0]:.0f} mm breit), Schnitt bei '
          f'{neck - HEAD_NECK_DROP:.0f} mm, {keep.sum()} von {len(tris)} Dreiecken behalten',
          flush=True)

    return tris[keep], neck


def head_surface(tris, neck, up, wide, deep, rings, segments):
    """The scan's head as a radius per height and angle, reduced to a form.

    A lathe grid: `rings` slabs from the neck cut up to the crown, each a
    ring of `segments` angles around a vertical axis through the head, and
    at every crossing the distance from that axis out to the skin.

    Three reductions, in this order, and each one answers a way the head
    went wrong on screen.

    A cell keeps a high percentile of the radii that fell into it rather
    than the largest. The maximum is the outermost skin in that direction,
    which sounds like the safe reading and is not: outermost on a head is
    the nose, an ear, or one stray triangle of scan noise, and each of them
    came back as a lump of its own size.

    Then the two halves are averaged into each other. The scan is one man's
    head and no head is symmetric; nothing in a body map means anything by
    that asymmetry, and at the forty pixels the card gives the figure it
    reads as a head set on crooked rather than as a likeness.

    Then a low pass around each ring and a moving average up the head, which
    is what takes the remaining features off: HEAD_HARMONICS leaves every
    cross-section an ellipse, and the vertical average keeps one ring from
    stepping out past the ones above and below it.

    Returns the axis, the height range and the radius grid.
    """
    flat = tris.reshape(-1, 3)
    height = flat[:, up]
    lo, hi = float(height.min()), float(height.max())

    # The axis through the head, taken at the jaw rather than at the mean:
    # the crop keeps more neck on the back than on the front, so the mean
    # of everything sits behind the head and tilts every angle with it.
    jaw = flat[height <= lo + (hi - lo) * 0.35]
    axis_wide, axis_deep = float(np.median(jaw[:, wide])), float(np.median(jaw[:, deep]))

    across = flat[:, wide] - axis_wide
    along = flat[:, deep] - axis_deep
    radius = np.hypot(across, along)
    angle = np.arctan2(along, across)

    row = np.clip(((height - lo) / (hi - lo) * (rings - 1)).astype(int), 0, rings - 1)
    col = np.mod((angle / (2 * np.pi) * segments).astype(int), segments)

    # Percentile per cell, taken by sorting the radii cell by cell and
    # picking one out of each run, because there is no scatter form of
    # np.percentile the way there is for np.maximum.
    cell = row * segments + col
    order = np.lexsort((radius, cell))
    ranked, sorted_cell = radius[order], cell[order]
    edge = np.searchsorted(sorted_cell, np.arange(rings * segments + 1))
    count = np.diff(edge)
    seen = count > 0
    pick = edge[:-1] + ((count - 1) * HEAD_REACH // 100)
    grid = np.zeros(rings * segments)
    grid[seen] = ranked[pick[seen]]
    grid = grid.reshape(rings, segments)

    # A cell no triangle fell into gets its ring's mean, and a ring with
    # nothing at all the ring below it. Both happen near the crown, where
    # a slab is a few square centimetres of scalp.
    for i in range(rings):
        filled = grid[i] > 0
        if filled.any():
            grid[i, ~filled] = grid[i, filled].mean()
        elif i:
            grid[i] = grid[i - 1]

    # Left onto right. The sagittal plane is `across` = 0, so a mirrored
    # angle is pi - angle, which on this grid is the column the flip and
    # the half-turn land on together.
    grid = (grid + np.roll(grid[:, ::-1], segments // 2, axis=1)) / 2

    # Around each ring: keep the first few Fourier terms and drop the rest.
    # Term 0 is the size, 1 the offset of the section from the axis, 2 the
    # oval. Everything above them is a feature.
    spectrum = np.fft.rfft(grid, axis=1)
    spectrum[:, HEAD_HARMONICS + 1:] = 0
    grid = np.fft.irfft(spectrum, n=segments, axis=1)

    # Up the head: a moving average over the same kind of distance, run
    # twice. One pass is a box, and a box leaves a ripple of its own at the
    # edge of what it removes; twice is a triangle, which does not.
    kernel = np.ones(HEAD_SMOOTH) / HEAD_SMOOTH
    for _ in range(2):
        pad = np.pad(grid, ((HEAD_SMOOTH // 2, HEAD_SMOOTH // 2), (0, 0)), mode='edge')
        grid = np.apply_along_axis(lambda c: np.convolve(c, kernel, mode='valid'), 0, pad)[:rings]

    # The crown is drawn, not measured. Up there a slab holds few triangles
    # and its measured radius wanders; a quarter ellipse closes the head
    # instead, and it closes it round.
    #
    # It scales the rings that are there rather than replacing them with the
    # last trusted one. Replacing them stands the head up as a cylinder from
    # that ring and rounds off only the top of it, so the slope jumps where
    # the drawn dome meets the measured head and the jump reads as a rim
    # right around the skull, like a swimming cap. Scaling keeps the slope
    # the head already had and still lands the last ring on the axis.
    #
    # It has to come after the average, not before it. An average that
    # reaches past the crown pulls the tip back up off the axis, which
    # leaves the head open at the top: nothing shows until the camera is
    # high enough to look down the hole.
    first = int(HEAD_CROWN * (rings - 1))
    close = np.linspace(0, 1, rings - first)
    grid[first:] *= np.sqrt(np.clip(1 - close ** 2, 0, 1))[:, None]

    # Below the neck the scan is already turning into shoulders, and a lathe
    # radius around a shoulder is a cone. So nothing under the neck is
    # allowed to be wider than the neck itself: the last stretch stays a
    # neck all the way down and disappears between the trapezius and the
    # chest, where its end is nobody's business.
    under = int(np.clip((neck - lo) / (hi - lo), 0, 1) * (rings - 1))
    grid[:under] = np.minimum(grid[:under], grid[under])

    return (axis_wide, axis_deep), (lo, hi), grid


def head_solid(tris, neck, up, wide, deep, rings=112, segments=128):
    """The head and its neck: the scan's measurements, none of its shapes.

    Everything else was tried first. The bare skull read as a memento mori.
    The skin surface over it kept sunken eyes, parted lips and the speckle
    of the reconstruction, so it read as a death mask. Taubin smoothing
    converges after twenty rounds having moved the median vertex one
    millimetre, which is the noise and not the eye socket. A head drawn from
    control points has no face to go wrong and looks like an egg on a stick,
    because a head's form is jaw and cheek and chin and nobody draws those
    from memory.

    Keeping enough of the scan to carry a jaw and a nose was tried after
    those, with two darker patches painted on for eyes, and it is the one
    that got furthest and still went wrong: at the size the card gives the
    figure, half a face is not a quiet face. It reads as a specific person,
    seen badly.

    So the head is reduced instead of drawn or kept. head_surface() takes it
    down to one ellipse per cross-section, and what is left is a head of the
    right size in the right place, tapering the way the scan's does, with
    nothing on it to read as a face at all. There is no argument left about
    which features to keep, because none of them survive.

    The neck comes along for a different reason. A head has to end
    somewhere, and every place it can end on its own looks wrong: cut under
    the jaw it overhangs its own stalk, closed off like the crown it is an
    egg resting on the shoulders. So it does not end on screen at all. The
    body carries on past the neck and stops inside the shoulders.
    """
    axis, span, grid = head_surface(tris, neck, up, wide, deep, rings, segments)
    (axis_wide, axis_deep), (lo, hi) = axis, span

    angle = np.linspace(0, 2 * np.pi, segments, endpoint=False)
    verts = np.zeros((rings * segments, 3))
    for i in range(rings):
        ring = slice(i * segments, (i + 1) * segments)
        verts[ring, up] = lo + (hi - lo) * i / (rings - 1)
        verts[ring, wide] = axis_wide + grid[i] * np.cos(angle)
        verts[ring, deep] = axis_deep + grid[i] * np.sin(angle)

    faces = []
    for i in range(rings - 1):
        low, high = i * segments, (i + 1) * segments
        for j in range(segments):
            k = (j + 1) % segments
            faces.append([low + j, high + j, high + k])
            faces.append([low + j, high + k, low + k])

    box = np.ptp(verts, axis=0)
    print(f'{"HEAD":16s} Kopf: {box[wide]:.0f} mm breit, {box[deep]:.0f} mm tief, '
          f'{box[up]:.0f} mm hoch, {rings}x{segments}, {HEAD_HARMONICS} Harmonische',
          flush=True)

    return {'HEAD': (verts.astype(np.float32), np.array(faces, dtype=np.uint32))}


def cluster(tris, cell):
    """Collapse a triangle soup onto a grid, dropping degenerates."""
    flat = tris.reshape(-1, 3)
    keys = np.floor(flat / cell).astype(np.int64)
    # Unique cell -> one vertex, placed at the mean of what fell into it,
    # which keeps the surface where it was instead of snapping it to the
    # lattice and giving every muscle a faceted skin.
    inverse = np.unique(keys, axis=0, return_inverse=True)[1].reshape(-1)
    n = inverse.max() + 1
    sums = np.zeros((n, 3), dtype=np.float64)
    np.add.at(sums, inverse, flat)
    counts = np.bincount(inverse, minlength=n).reshape(-1, 1)
    verts = (sums / counts).astype(np.float32)

    faces = inverse.reshape(-1, 3)
    keep = (faces[:, 0] != faces[:, 1]) & (faces[:, 1] != faces[:, 2]) & (faces[:, 0] != faces[:, 2])

    return verts, faces[keep].astype(np.uint32)


def write_glb(meshes, centre, scale, order, up, path):
    """One GLB, one named node per zone, positions only."""
    views, accessors, mesh_defs, nodes = [], [], [], []
    blob = bytearray()

    def add_view(data, target):
        while len(blob) % 4:
            blob.append(0)
        views.append({'buffer': 0, 'byteOffset': len(blob), 'byteLength': len(data), 'target': target})
        blob.extend(data)

        return len(views) - 1

    for zone, (verts, faces) in meshes.items():
        v = (verts - centre) * scale
        v = np.stack([v[:, order[0]], v[:, order[1]], v[:, order[2]]], axis=1)
        if up == 2:
            v[:, 2] *= -1
        v = np.ascontiguousarray(v, dtype=np.float32)

        pos = add_view(v.tobytes(), 34962)
        accessors.append({'bufferView': pos, 'componentType': 5126, 'count': len(v), 'type': 'VEC3',
                          'min': v.min(axis=0).tolist(), 'max': v.max(axis=0).tolist()})
        p_acc = len(accessors) - 1

        idx = add_view(np.ascontiguousarray(faces, dtype=np.uint32).tobytes(), 34963)
        accessors.append({'bufferView': idx, 'componentType': 5125, 'count': faces.size, 'type': 'SCALAR'})
        i_acc = len(accessors) - 1

        mesh_defs.append({'name': zone, 'primitives': [{'attributes': {'POSITION': p_acc}, 'indices': i_acc}]})
        nodes.append({'name': zone, 'mesh': len(mesh_defs) - 1})

    gltf = {
        'asset': {'version': '2.0', 'generator': 'hybridlog BodyParts3D zone builder'},
        'scene': 0, 'scenes': [{'nodes': list(range(len(nodes)))}],
        'nodes': nodes, 'meshes': mesh_defs, 'accessors': accessors,
        'bufferViews': views, 'buffers': [{'byteLength': len(blob)}],
    }

    js = json.dumps(gltf, separators=(',', ':')).encode()
    js += b' ' * (-len(js) % 4)
    blob.extend(b'\0' * (-len(blob) % 4))
    with open(path, 'wb') as fh:
        fh.write(struct.pack('<III', 0x46546C67, 2, 12 + 8 + len(js) + 8 + len(blob)))
        fh.write(struct.pack('<II', len(js), 0x4E4F534A) + js)
        fh.write(struct.pack('<II', len(blob), 0x004E4942) + bytes(blob))
    print(f'{path.name}: {path.stat().st_size / 1048576:.1f} MB, '
          f'{sum(len(v) for v, _ in meshes.values())} Punkte, '
          f'{sum(len(f) for _, f in meshes.values())} Dreiecke')


def main():
    selection, avail, _ = zones.resolve()
    meshes = {}
    for zone, ids in selection.items():
        if zone == 'HEAD':
            continue
        tris = np.concatenate([read_stl(STL / f'{i}.stl') for i in ids])
        verts, faces = cluster(tris, HEART_CELL if zone == 'HEART' else CELL)
        meshes[zone] = (verts, faces)
        print(f'{zone:16s} {len(tris):8d} Dreiecke roh -> {len(verts):7d} Punkte / {len(faces):7d} Dreiecke',
              flush=True)

    # The scan's own axes, asked of the body rather than assumed: the longest
    # span is up, and the wider of the other two is across the shoulders. The
    # head is measured in this frame, so it has to be settled before it.
    span = np.concatenate([v for v, _ in meshes.values()])
    axes = np.ptp(span, axis=0)
    up_axis = int(np.argmax(axes))
    across = [a for a in (0, 1, 2) if a != up_axis]
    wide, deep = (across if axes[across[0]] > axes[across[1]] else across[::-1])
    print(f'\nHochachse {up_axis}, Breite {wide}, Tiefe {deep}')

    scan = np.concatenate([read_stl(STL / f'{i}.stl') for i in selection['HEAD']])
    meshes.update(head_solid(*crop_to_head(scan, up_axis), up_axis, wide, deep))

    # One frame for every zone, derived from the whole body so the parts
    # keep their relative positions.
    allv = np.concatenate([v for v, _ in meshes.values()])
    lo, hi = allv.min(axis=0), allv.max(axis=0)
    size = hi - lo
    up = int(np.argmax(size))
    scale = TARGET_HEIGHT / size[up]
    centre = (lo + hi) / 2
    print(f'\nRohmasse {size.round(1)} mm, Hochachse = Achse {up}, Faktor {scale:.5f}\n')

    # glTF is y-up, z toward the viewer. The scan's up axis becomes y and
    # the remaining two are ordered so the body faces +z.
    order = {0: (1, 0, 2), 1: (0, 1, 2), 2: (0, 2, 1)}[up]

    # Two files, because the round things must not meet the quadric
    # simplifier. Its error bound is about three millimetres of the whole
    # figure, which the muscles carry without anyone noticing and a smooth
    # closed surface cannot: on a skull-sized sphere three millimetres of
    # chord error is a facet every thirty degrees, and the head came out of
    # it terraced like a contour map. The heart is smaller again, so it
    # would come out worse. Both are already as coarse as they should be,
    # so they skip the step and are merged back in afterwards. See README.
    smooth = {key: meshes.pop(key) for key in ('HEAD', 'HEART')}
    write_glb(meshes, centre, scale, order, up, HERE / 'zones_raw.glb')
    write_glb(smooth, centre, scale, order, up, HERE / 'smooth_raw.glb')


if __name__ == '__main__':
    main()
