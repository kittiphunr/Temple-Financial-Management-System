#!/usr/bin/env bash
# สร้างชุดไฟล์พร้อมติดตั้งลงเซิร์ฟเวอร์จริง  ->  deploy/dist/tfms/  และ  deploy/dist/tfms-<วันที่>.zip
# ใช้:  bash deploy/build.sh        (ต้องมี rsync, zip, php)
# ชุดที่ได้มีเฉพาะไฟล์ที่ระบบต้องใช้ตอนรันจริง: ไม่มีชุดทดสอบ, node_modules, เครื่องมือพัฒนา, config.local.php,
# storage/app.key, รหัสผ่านเริ่มต้น หรือข้อมูลของเครื่องนี้ — storage/ เป็นโฟลเดอร์เปล่าที่ห้ามเข้าถึงจากเว็บ
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/deploy/dist"
OUT="$DIST/tfms"
STAMP="$(date +%Y%m%d)"
ZIP="$DIST/tfms-$STAMP.zip"

for c in rsync zip php; do command -v "$c" >/dev/null || { echo "ต้องติดตั้ง $c ก่อน" >&2; exit 1; }; done

rm -rf "$OUT" "$ZIP" "$ZIP.sha256"
mkdir -p "$OUT"

# ---- คัดลอกเฉพาะไฟล์ที่ต้องใช้ (whitelist) ----
cd "$ROOT"
rsync -a --prune-empty-dirs \
  --include='/*.php' \
  --include='/admin/' --include='/admin/*.php' \
  --include='/includes/' --include='/includes/*.php' \
  --include='/assets/' --include='/assets/css/' --include='/assets/css/tailwind.css' --include='/assets/img/' --include='/assets/img/*' \
  --include='/assets/js/' --include='/assets/js/*.js' --include='/assets/vendor/' --include='/assets/vendor/*' --include='/assets/fonts/' --include='/assets/fonts/*' \
  --include='/data/' --include='/data/temples_phrae.csv' --include='/data/temples_template.csv' --include='/data/thai_geo.json' --include='/data/thai_geo.LICENSE' --include='/data/credits.json' \
  --include='/sql/' --include='/sql/*.sql' --include='/sql/migrations/' --include='/sql/migrations/*.sql' \
  --exclude='*' ./ "$OUT/"
cp README.md UPGRADE.md LICENSE CREDITS.md "$OUT/" 2>/dev/null || true

# ---- storage/ เปล่า + กันเข้าถึงจากเว็บ ----
mkdir -p "$OUT/storage"
cp storage/.htaccess "$OUT/storage/.htaccess"
printf '*\n!.gitignore\n!.htaccess\n' > "$OUT/storage/.gitignore"
# ---- โฟลเดอร์ที่ไม่ควรถูกเปิดตรง ๆ จากเว็บ (Apache; Nginx ใช้ deploy/nginx.conf.example) ----
for d in includes sql data; do cp storage/.htaccess "$OUT/$d/.htaccess"; done
# ไฟล์ CSV ที่ตัวช่วยติดตั้งต้องอ่านทางโค้ด (ไม่ผ่านเว็บ) จึงปิดทางเว็บได้ปลอดภัย

# ---- ตรวจความถูกต้อง ----
fail=0
while IFS= read -r -d '' f; do
  php -l "$f" >/dev/null 2>&1 || { echo "PHP ผิดพลาด: $f" >&2; fail=1; }
done < <(find "$OUT" -name '*.php' -print0)
for bad in config.local.php storage/app.key storage/installed.lock tests tools node_modules package.json tailwind.config.js .git; do
  [ -e "$OUT/$bad" ] && { echo "พบไฟล์ที่ไม่ควรอยู่ในชุดติดตั้ง: $bad" >&2; fail=1; }
done
ls "$OUT"/storage/*.csv "$OUT"/storage/*.log >/dev/null 2>&1 && { echo "พบไฟล์ข้อมูลใน storage/" >&2; fail=1; }
[ -s "$OUT/assets/vendor/sweetalert2.all.min.js" ] && [ -s "$OUT/assets/js/app.js" ] && [ -s "$OUT/assets/fonts/JS-Chanok-Normal.ttf" ] && [ -s "$OUT/assets/fonts/GPL-License-JS.pdf" ] && [ -s "$OUT/data/thai_geo.json" ] || { echo "ไม่พบ assets/vendor/sweetalert2.all.min.js หรือ assets/js/app.js" >&2; fail=1; }
[ -s "$OUT/assets/css/tailwind.css" ] || { echo "ไม่พบ assets/css/tailwind.css (รัน npm run build:css ก่อน)" >&2; fail=1; }
[ "$fail" = 0 ] || { echo "สร้างชุดติดตั้งไม่สำเร็จ" >&2; exit 1; }

# ---- แพ็ก zip + checksum ----
(cd "$DIST" && zip -qr "$(basename "$ZIP")" tfms -x '*/.DS_Store')
(cd "$DIST" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

echo "สำเร็จ"
echo "  โฟลเดอร์ : $OUT ($(find "$OUT" -type f | wc -l) ไฟล์)"
echo "  zip      : $ZIP ($(du -h "$ZIP" | cut -f1))"
echo "  sha256   : $(cut -d' ' -f1 "$ZIP.sha256")"
echo "ขั้นตอนต่อไปดู deploy/README.md"
