"""Resolve the sixteen freshness zones to BodyParts3D structures by name."""
import re

# Each zone is the set of muscles that actually does the work the zone is
# named for. Sub-heads are pulled in explicitly (a deltoid in BodyParts3D
# is three separate parts, and the front and back of it belong to two
# different zones), which is the whole reason for going to this dataset.
ZONES = {
    'CHEST':          [r'part of (left|right) pectoralis major'],
    'FRONT_DELTOIDS': [r'(clavicular|acromial) part of (left|right) deltoid'],
    'BACK_DELTOIDS':  [r'spinal part of (left|right) deltoid'],
    'BICEPS':         [r'head of (left|right) biceps brachii', r'^(left|right) brachialis$',
                       r'^(left|right) coracobrachialis$'],
    'TRICEPS':        [r'head of (left|right) triceps brachii'],
    'FOREARM':        [r'^(left|right) brachioradialis$', r'(left|right) (flexor|extensor) carpi',
                       r'head of (left|right) (flexor|extensor) carpi',
                       r'^(left|right) (flexor|extensor) digitorum$',
                       r'head of (left|right) flexor digitorum superficialis',
                       r'^(left|right) flexor digitorum profundus$',
                       r'head of (left|right) pronator teres'],
    'ABS':            [r'^(left|right) rectus abdominis$'],
    # External only. The internal oblique lies underneath it and is never
    # seen, so it costs triangles and, being a thin sheet, survives the
    # decimation badly enough to poke through the muscle covering it.
    'OBLIQUES':       [r'^(left|right) external oblique$'],
    'QUADRICEPS':     [r'^(left|right) rectus femoris$', r'^(left|right) vastus '],
    'HAMSTRING':      [r'head of (left|right) biceps femoris', r'^(left|right) semitendinosus$',
                       r'^(left|right) semimembranosus$'],
    'GLUTEAL':        [r'^(left|right) gluteus maximus$'],
    'CALVES':         [r'head of (left|right) gastrocnemius', r'^(left|right) soleus$'],
    'TRAPEZIUS':      [r'(descending|transverse|ascending) part of (left|right) trapezius'],
    'UPPER_BACK':     [r'^(left|right) latissimus dorsi$', r'^(left|right) rhomboid (major|minor)$',
                       r'^(left|right) infraspinatus muscle$', r'^(left|right) teres (major|minor)$'],
    'LOWER_BACK':     [r'^(left|right) iliocostalis (lumborum|thoracis)$',
                       r'^(left|right) longissimus thoracis$', r'^(left|right) multifidus$'],
    'ABDUCTORS':      [r'^(left|right) gluteus (medius|minimus)$',
                       r'^(left|right) tensor fasciae latae$'],
}

# Nothing here makes a load claim. It exists so the figure reads as a body
# and not as a pile of muscle: hands and feet mark where the limbs end.
# Only bone that an écorché actually shows. Femur, humerus, pelvis, ribs,
# scapula and the vertebrae are covered by the muscles in front of them,
# so they would cost triangles nobody ever sees.
INERT = [r'bone$',
         r'phalanx of (left|right) (thumb|index|middle|ring|little) finger',
         r'phalanx of (left|right) (big|second|third|fourth|little) toe',
         r'metacarpal bone', r'metatarsal bone',
         r'^(left|right) (scaphoid|lunate|triquetral|pisiform|trapezium|trapezoid|capitate|hamate)',
         r'^(left|right) (calcaneus|talus|navicular|cuboid)',
         r'cuneiform bone', r'^(left|right) (radius|ulna|tibia|fibula|patella|clavicle)$',
         r'^sternum$']

# The head is the scan's own outer surface, not the skull underneath it. A
# bare skull turns a training figure into a memento mori, and the face is
# the one part of the body nobody reads anatomically. The skin is a single
# structure covering the whole body; build_glb.py crops it at the neck, so
# it gives the figure a face and hides nothing else.
HEAD = [r'^skin$']

# The heart, and it is the one part here that is not scenery. The head and
# the bones exist so the figure reads as a body; the heart carries a
# reading of its own, beating at the resting pulse with the unevenness of
# the HRV. See body3d.js.
#
# "Wall of heart" rather than "heart": the dataset has both, but only the
# wall ships as an STL, and it is the better half anyway. It is the closed
# outer surface, which is all that is ever seen from outside; the chambers,
# valves and papillary muscles inside it would be triangles behind a closed
# shell.
HEART = [r'^wall of heart$']

# Cranial bone, matched only so it can be taken back out of INERT, which
# otherwise claims everything ending in "bone". The head surface covers all
# of it, so keeping it would cost triangles behind a closed surface.
SKULL = [r'(frontal|occipital|sphenoid|temporal|parietal|zygomatic|lacrimal|nasal|palatine|ethmoid|hyoid) bone$',
         r'^(mandible|vomer)$']


def load():
    names = {}
    for line in open('parts_list.txt', encoding='utf-8', errors='replace'):
        p = line.rstrip('\n').split('\t')
        if len(p) >= 2:
            names[p[0]] = p[1]
    sizes = {row.split('\t')[0].replace('.stl', ''): int(row.split('\t')[1])
             for row in open('stl_list.tsv')}
    return {i: n for i, n in names.items() if i in sizes}, sizes


def resolve():
    avail, sizes = load()
    skull = [re.compile(p, re.I) for p in SKULL]
    out, seen = {}, set()
    for zone, pats in list(ZONES.items()) + [('NONE', INERT), ('HEAD', HEAD), ('HEART', HEART)]:
        rx = [re.compile(p, re.I) for p in pats]
        ids = sorted(i for i, n in avail.items() if any(r.search(n) for r in rx))
        if zone == 'NONE':
            ids = [i for i in ids if not any(r.search(avail[i]) for r in skull)]
        dupes = [i for i in ids if i in seen]
        assert not dupes, f'{zone} claims parts another zone already has: {dupes}'
        assert ids, f'{zone} matched nothing'
        seen.update(ids)
        out[zone] = ids
    return out, avail, sizes


if __name__ == '__main__':
    zones, avail, sizes = resolve()
    total = 0
    for zone, ids in zones.items():
        mb = sum(sizes[i] for i in ids) / 1048576
        total += mb
        print(f'{zone:16s} {len(ids):3d} Teile  {mb:7.1f} MB')
        if zone != 'NONE':
            for i in ids:
                print(f'{"":18s}{avail[i]}')
    print(f'{"GESAMT":16s} {sum(len(v) for v in zones.values()):3d} Teile  {total:7.1f} MB')
