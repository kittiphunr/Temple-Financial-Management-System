#!/usr/bin/env python3
"""รันชุดทดสอบทั้งหมด:  python3 tests/run_all.py [features|install|people|receipts|auth|ui|statements|import|subdistrict|credits|bangkok]

ต้องมี: PHP 8.1+ (CLI + pdo_mysql) และ MySQL/MariaDB ที่เชื่อมต่อได้ด้วยบัญชีที่สร้าง/ลบฐานข้อมูลได้
ตั้งค่าผ่านตัวแปรสภาพแวดล้อม  TFMS_TEST_DB_HOST (127.0.0.1)  TFMS_TEST_DB_PORT (3306)
                               TFMS_TEST_DB_USER (root)       TFMS_TEST_DB_PASS ('')

ทุกการทดสอบรันบนสำเนาโปรเจกต์ในโฟลเดอร์ชั่วคราว + ฐานข้อมูลชื่อสุ่ม tfms_test_* ซึ่งถูกลบเมื่อจบ
จึงไม่แตะไฟล์/ข้อมูลจริง รหัสออก: 0 = ผ่านทั้งหมด, 1 = มีข้อที่ไม่ผ่าน, 2 = เชื่อมต่อฐานข้อมูลไม่ได้
"""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import test_features  # noqa: E402
import test_install  # noqa: E402
import test_people  # noqa: E402
import test_receipts  # noqa: E402
import test_auth  # noqa: E402
import test_ui  # noqa: E402
import test_statements  # noqa: E402
import test_import  # noqa: E402
import test_subdistrict  # noqa: E402
import test_credits  # noqa: E402
import test_bangkok  # noqa: E402
from support import Db, DbUnavailable, Results  # noqa: E402

SUITES = {'features': test_features, 'install': test_install, 'people': test_people, 'receipts': test_receipts, 'auth': test_auth, 'ui': test_ui, 'statements': test_statements, 'import': test_import, 'subdistrict': test_subdistrict, 'credits': test_credits, 'bangkok': test_bangkok}


def main(argv):
    names = argv or list(SUITES)
    unknown = [n for n in names if n not in SUITES]
    if unknown:
        print('ไม่รู้จักชุดทดสอบ:', ', '.join(unknown), '(ใช้ได้: ' + ', '.join(SUITES) + ')')
        return 2
    db = Db()
    try:
        db.ping()
    except DbUnavailable as e:
        print(e)
        return 2
    all_ok = True
    try:
        for n in names:
            print(f'\n##### ชุดทดสอบ: {n} #####')
            r = Results()
            SUITES[n].run(db, r)
            all_ok &= r.summary(n)
            if r.failures:
                print('ข้อที่ไม่ผ่าน:', ', '.join(r.failures))
    finally:
        db.cleanup()
    print('\nสรุป:', 'ผ่านทั้งหมด' if all_ok else 'มีข้อที่ไม่ผ่าน')
    return 0 if all_ok else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
