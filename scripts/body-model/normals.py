"""Add angle-weighted vertex normals to the zone GLB.

A matcap samples nothing but the normal, so a model without them renders
as a flat cut-out. The accumulation runs across zone boundaries rather
than per mesh: the zones are separate meshes that share a border, and
normals averaged per mesh would crease along every one of those borders.
"""
import json
import struct
import sys
from pathlib import Path

import numpy as np

SRC = Path(sys.argv[1])
DST = Path(sys.argv[2])
# Two vertices this close are the same point on the body, whichever mesh
# they belong to. The body is 17 units tall, so this is about a millimetre.
WELD = 1e-3


def load(path):
    raw = path.read_bytes()
    jlen = struct.unpack('<I', raw[12:16])[0]
    gltf = json.loads(raw[20:20 + jlen])
    bin_off = 20 + jlen + 8

    return gltf, bytearray(raw[bin_off:])


# glTF picks the narrowest index type that fits, so a mesh under 65536
# vertices arrives as uint16 and reading it as uint32 silently walks off
# the end of the table.
DTYPES = {5120: np.int8, 5121: np.uint8, 5122: np.int16, 5123: np.uint16,
          5125: np.uint32, 5126: np.float32}


def view(gltf, blob, acc_i, comps):
    acc = gltf['accessors'][acc_i]
    bv = gltf['bufferViews'][acc['bufferView']]
    off = bv.get('byteOffset', 0) + acc.get('byteOffset', 0)
    dtype = DTYPES[acc['componentType']]

    # Copied out on purpose: a live view into the bytearray would pin it
    # and the NORMAL data cannot be appended to a buffer that cannot grow.
    return np.frombuffer(blob, dtype=dtype, count=acc['count'] * comps,
                         offset=off).reshape(-1, comps).copy()


def main():
    gltf, blob = load(SRC)
    parts = []
    for mesh in gltf['meshes']:
        prim = mesh['primitives'][0]
        pos = view(gltf, blob, prim['attributes']['POSITION'], 3)
        # Widened before use: the offset into the shared table exceeds the
        # uint16 the accessor may be stored as, and numpy raises rather
        # than wrapping.
        idx = view(gltf, blob, prim['indices'], 1).reshape(-1).astype(np.int64)
        parts.append((mesh['name'], pos, idx))

    # One shared vertex table for the whole body, so a face on one side of
    # a zone border still contributes to the normal on the other side.
    allpos = np.concatenate([p for _, p, _ in parts])
    keys = np.round(allpos / WELD).astype(np.int64)
    shared = np.unique(keys, axis=0, return_inverse=True)[1].reshape(-1)

    accum = np.zeros((shared.max() + 1, 3), dtype=np.float64)
    base = 0
    for _, pos, idx in parts:
        tri = shared[base + idx].reshape(-1, 3)
        v = allpos[base + idx].reshape(-1, 3, 3)
        base += len(pos)
        e0, e1, e2 = v[:, 1] - v[:, 0], v[:, 2] - v[:, 1], v[:, 0] - v[:, 2]
        face = np.cross(e0, -e2)
        # Weight by the corner angle, not by area: a long thin triangle
        # would otherwise drag the normal of the corner it barely touches.
        for c, (a, b) in enumerate([(e0, -e2), (e1, -e0), (e2, -e1)]):
            na = a / (np.linalg.norm(a, axis=1, keepdims=True) + 1e-12)
            nb = b / (np.linalg.norm(b, axis=1, keepdims=True) + 1e-12)
            ang = np.arccos(np.clip((na * nb).sum(axis=1), -1, 1)).reshape(-1, 1)
            np.add.at(accum, tri[:, c], face * ang)

    norm = np.linalg.norm(accum, axis=1, keepdims=True)
    accum = np.divide(accum, norm, out=np.zeros_like(accum), where=norm > 1e-12)

    # Append one NORMAL accessor per mesh to the end of the buffer.
    while len(blob) % 4:
        blob.append(0)
    base = 0
    for mesh, (_, pos, _) in zip(gltf['meshes'], parts):
        n = np.ascontiguousarray(accum[shared[base:base + len(pos)]], dtype=np.float32)
        base += len(pos)
        while len(blob) % 4:
            blob.append(0)
        gltf['bufferViews'].append({'buffer': 0, 'byteOffset': len(blob),
                                    'byteLength': n.nbytes, 'target': 34962})
        blob.extend(n.tobytes())
        gltf['accessors'].append({'bufferView': len(gltf['bufferViews']) - 1, 'componentType': 5126,
                                  'count': len(n), 'type': 'VEC3'})
        mesh['primitives'][0]['attributes']['NORMAL'] = len(gltf['accessors']) - 1

    gltf['buffers'] = [{'byteLength': len(blob)}]
    js = json.dumps(gltf, separators=(',', ':')).encode()
    js += b' ' * (-len(js) % 4)
    blob.extend(b'\0' * (-len(blob) % 4))
    with open(DST, 'wb') as fh:
        fh.write(struct.pack('<III', 0x46546C67, 2, 12 + 8 + len(js) + 8 + len(blob)))
        fh.write(struct.pack('<II', len(js), 0x4E4F534A) + js)
        fh.write(struct.pack('<II', len(blob), 0x004E4942) + bytes(blob))

    degenerate = int((norm <= 1e-12).sum())
    print(f'{DST.name}: {DST.stat().st_size / 1048576:.2f} MB, {len(accum)} geteilte Punkte, '
          f'{degenerate} ohne Normale')


if __name__ == '__main__':
    main()
