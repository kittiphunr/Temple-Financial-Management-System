#!/usr/bin/env bash
# สร้างชุดติดตั้งล่าสุดเข้า website/downloads/ พร้อม release.json  (ใช้:  bash website/build.sh)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bash "$ROOT/deploy/build.sh" >/dev/null
Z="$(ls -t "$ROOT"/deploy/dist/tfms-*.zip | head -1)"
D="$ROOT/website/downloads"; mkdir -p "$D"; rm -f "$D"/tfms-*.zip "$D"/tfms-*.sha256
cp "$Z" "$D/"; F="$(basename "$Z")"
SHA="$(sha256sum "$D/$F" | cut -d' ' -f1)"; echo "$SHA  $F" > "$D/$F.sha256"
cat > "$D/release.json" <<J
{"version":"$(tr -d '[:space:]' < "$ROOT/VERSION")","build":$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["build"];' "$ROOT/deploy/dist/tfms/version.json"),"commit":"$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["commit"];' "$ROOT/deploy/dist/tfms/version.json")","file":"$F","size":$(stat -c%s "$D/$F"),"sha256":"$SHA","date":"$(date +%Y-%m-%d)"}
J
echo "ok: $D/$F"
