#!/bin/zsh
# Pull only the parts the zones actually need, eight at a time.
set -u
cd "$(dirname "$0")"
DATA=https://raw.githubusercontent.com/Kevin-Mattheus-Moerman/BodyParts3D/main/assets/BodyParts3D_data
BASE=$DATA/stl
mkdir -p stl

# The two indexes zones.py resolves against: every structure the dataset
# names, and every one it actually ships an STL for. Neither is committed
# (they are the source's, not ours), so they are fetched on demand like the
# meshes. Both are small, and skipped when they are already here.
#
# The second one is not a file in the repository, it is the repository: the
# sizes come from the tree listing, which is also how the fetch below knows
# a structure exists before asking for 76 MB of it.
[ -s parts_list.txt ] || curl -sfL "$DATA/parts_list_e.txt" -o parts_list.txt || exit 1
[ -s stl_list.tsv ] || curl -sfL \
    "https://api.github.com/repos/Kevin-Mattheus-Moerman/BodyParts3D/contents/assets/BodyParts3D_data/stl?per_page=1000" \
    | python3 -c "
import json, sys
rows = sorted((f['name'], f['size']) for f in json.load(sys.stdin) if f['name'].endswith('.stl'))
assert rows, 'empty file list'
sys.stdout.write(''.join(f'{n}\t{s}\n' for n, s in rows))
" > stl_list.tsv || { rm -f stl_list.tsv; echo 'could not build stl_list.tsv'; exit 1; }

python3 -c "
import zones
z, _, _ = zones.resolve()
for zone, ids in z.items():
    for i in ids:
        print(f'{zone}\t{i}')
" > selection.tsv || exit 1

cut -f2 selection.tsv | while read id; do
    [ -s "stl/$id.stl" ] && continue
    echo "$id"
done | xargs -P 8 -I{} curl -sfL -o "stl/{}.stl" "$BASE/{}.stl"

want=$(wc -l < selection.tsv)
have=$(ls stl/*.stl 2>/dev/null | wc -l)
echo "geladen: $have / $want"
[ "$have" -eq "$want" ] || { echo "FEHLEND:"; cut -f2 selection.tsv | while read id; do [ -s "stl/$id.stl" ] || echo "  $id"; done; exit 1; }
du -sh stl
