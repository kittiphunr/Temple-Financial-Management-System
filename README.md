# ระบบบัญชีรายรับ-รายจ่ายวัด (จังหวัดแพร่)

ระบบ PHP + MySQL สำหรับบันทึกบัญชีรายรับ-รายจ่ายของวัด จัดกลุ่มวัดตามอำเภอทั้ง 8 อำเภอของจังหวัดแพร่
(เมืองแพร่, ร้องกวาง, ลอง, สูงเม่น, เด่นชัย, สอง, วังชิ้น, หนองม่วงไข่)

## ความสามารถ
- บันทึก/แก้ไข/ลบ (soft delete) รายรับและรายจ่าย แยกหมวด ผูกกับวัด
- สรุปภาพรวมรายปี (พ.ศ.) รายเดือน รายหมวด รายวัด จัดกลุ่มตามอำเภอ
- พิมพ์รายงาน / บันทึกเป็น PDF ผ่านเบราว์เซอร์ และส่งออก CSV (เปิดด้วย Excel ได้)
- บทบาทผู้ใช้: `admin` (ทุกวัด), `district` (เฉพาะอำเภอ), `temple` (เฉพาะวัดตนเอง)
- ระบบจัดการ (`admin/`): วัด, นำเข้ารายชื่อวัด CSV, ผู้ใช้, หมวดบัญชี, ประวัติการใช้งาน (audit log)
- ความปลอดภัย: PDO prepared statements, CSRF token, `password_hash`, จำกัดสิทธิ์ตามขอบเขต, กัน CSV formula injection

## ติดตั้ง
ต้องการ PHP 8.1+ (ส่วนขยาย pdo_mysql, mbstring) และ MySQL 5.7+ / MariaDB 10.3+

```bash
# ตั้งค่าการเชื่อมต่อใน config.php หรือผ่านตัวแปรสภาพแวดล้อม TFMS_DB_HOST/PORT/NAME/USER/PASS
php setup.php 'รหัสผ่านผู้ดูแลอย่างน้อย8ตัว'   # สร้างฐานข้อมูล ตาราง ข้อมูลตั้งต้น และผู้ใช้ admin
php -S 127.0.0.1:8080                            # ทดลองใช้งาน แล้วเปิด http://127.0.0.1:8080
```

บนเซิร์ฟเวอร์จริงให้ชี้ web root มาที่โฟลเดอร์นี้ (Apache/Nginx + PHP-FPM) ใช้ HTTPS
และควรลบหรือห้ามเข้าถึง `setup.php`, `sql/`, `data/` จากภายนอก (`setup.php` ทำงานได้เฉพาะ CLI)

## การจัดหน้า (Tailwind CSS)
ทุกหน้าใช้ Tailwind CSS 3 จัดหน้า รองรับมือถือ/แท็บเล็ต/เดสก์ท็อป และพิมพ์รายงานได้ (`print:` variants)
ไฟล์ `assets/css/tailwind.css` ที่ build แล้วถูก commit ไว้ ใช้งานบนเซิร์ฟเวอร์ PHP ได้เลยโดยไม่ต้องมี Node
ถ้าแก้ class ใน `*.php` หรือ `assets/css/input.css` / `tailwind.config.js` ให้ build ใหม่:

```bash
npm install
npm run build:css      # หรือ npm run watch:css ระหว่างพัฒนา
```

ธีมสี `brand` (แดง) และ `gold` (ทอง) กำหนดใน `tailwind.config.js` ฟอนต์ Sarabun โหลดจาก Google Fonts

## ข้อมูลวัดจังหวัดแพร่
`setup.php` โหลดทะเบียนวัดจริง 381 วัด จาก `data/temples_phrae.csv` (แปลงจาก `Report_Temple.xlsx`
ทะเบียนวัดของสำนักงานพระพุทธศาสนา) พร้อมตำบล หมู่ที่ รหัสวัด 11 หลัก ประเภทวัด และนิกาย ทุกวัดมีสถานะ "ตรวจสอบแล้ว"

| อำเภอ | วัด | อำเภอ | วัด |
|---|---|---|---|
| เมืองแพร่ | 95 | สอง | 40 |
| ลอง | 60 | เด่นชัย | 31 |
| วังชิ้น | 52 | หนองม่วงไข่ | 9 |
| สูงเม่น | 48 | ร้องกวาง | 46 |

- ชื่อวัดในไฟล์ต้นฉบับไม่มีคำว่า "วัด" นำหน้า สคริปต์แปลงจึงเติมให้
- วัดที่ชื่อซ้ำกันแยกด้วยรหัสวัด/ตำบล (เช่น "วัดน้ำชำ" มี 2 แห่งในอำเภอเมืองแพร่)
- ไฟล์ต้นฉบับมีรายการที่ถูกแก้ไขชื่อ/ที่ตั้งในคอลัมน์หมายเหตุ (เช่น โปร่งศรี → โป่งศรี) ซึ่งช่องชื่อวัดเป็นชื่อล่าสุดแล้ว
- อัปเดตทะเบียนภายหลังได้ที่ จัดการระบบ → นำเข้ารายชื่อวัด หรือสร้าง CSV ใหม่ด้วย
  `python3 tools/build_temples_csv.py Report_Temple.xlsx data/temples_phrae.csv`
- ข้อมูลเจ้าอาวาสและโทรศัพท์ไม่มีในทะเบียน แก้ไขได้ที่ จัดการระบบ → จัดการวัด

## โครงสร้าง
```
index.php  login.php  logout.php  temples.php  transactions.php  reports.php
admin/     index, temples, import, users, categories, audit
includes/  bootstrap (DB, auth, CSRF, scope), stats, header, footer
data/      temples_phrae.csv (ทะเบียนวัด), temples_template.csv   tools/  build_temples_csv.py
sql/       schema.sql, seed.sql      assets/  css (input.css, tailwind.css), img/logo.svg (ตัวอย่าง — เปลี่ยนเป็นตราจริงได้)
tailwind.config.js  package.json
```
