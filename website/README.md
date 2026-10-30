# เว็บประชาสัมพันธ์ระบบ (landing page)

เว็บ static (HTML/CSS/JS ล้วน ไม่ต้องมี PHP หรือ build) วางบนโฮสต์ใดก็ได้

- `bash website/build.sh` — สร้างชุดติดตั้งล่าสุดเข้า `downloads/` พร้อม `release.json` (เวอร์ชัน/ขนาด/SHA-256) ที่หน้าเว็บอ่านไปแสดง
- `assets/shots/` — ภาพหน้าจอจากระบบจริงด้วยข้อมูลสมมติ
- ดูตัวอย่างในเครื่อง: `cd website && python3 -m http.server 8000`
- ยังไม่ได้ระบุสัญญาอนุญาต (LICENSE) ของซอฟต์แวร์ — หน้าเว็บจึงไม่อ้างว่าฟรี/โอเพนซอร์ส ให้เพิ่มข้อความเมื่อกำหนดแล้ว

## เผยแพร่บน GitHub Pages
`.github/workflows/pages.yml` อัปโหลดโฟลเดอร์ `website/` ขึ้น GitHub Pages ทุกครั้งที่ `main` มีการเปลี่ยนแปลงใน `website/`
เปิดใช้ครั้งเดียวที่ Settings → Pages → Source = **GitHub Actions** (repo แบบ private ต้องใช้แผนที่รองรับ Pages และหน้าเว็บจะเปิดสาธารณะ)
เมื่อออกเวอร์ชันใหม่: `bash website/build.sh` แล้ว commit ไฟล์ใน `website/downloads/`
