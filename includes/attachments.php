<?php
/**
 * หลักฐานแนบรายการ (ใบเสร็จ/สลิปโอนเงิน): ภาพ JPEG/PNG/WebP แปลงใหม่ด้วย GD (ลบ EXIF/GPS) ย่อด้านยาวไม่เกิน 2000 px
 * และ PDF (ตรวจหัวไฟล์ %PDF) เก็บตามต้นฉบับ ไม่เกิน 5 ไฟล์ต่อรายการ — เก็บในฐานข้อมูล เปิดผ่าน attachment.php ที่ตรวจสิทธิ์เท่านั้น
 * ถ้ายังไม่ได้รัน migration (sql/migrations/2026_attachments.sql) ฟีเจอร์นี้จะถูกซ่อน
 */

const ATT_MAX_UPLOAD = 4 * 1024 * 1024;
const ATT_MAX_STORED = 4 * 1024 * 1024;
const ATT_MAX_PER_TXN = 5;
const ATT_LONG_SIDE = 2000;

function attachments_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT id, transaction_id, temple_id, filename, mime, size FROM transaction_attachments LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** @return array{0:?string,1:?string,2:string} [ไบต์, mime, ข้อความผิดพลาด] */
function attachment_process(string $bytes): array
{
    if (str_starts_with($bytes, '%PDF-')) {
        if (strlen($bytes) > ATT_MAX_STORED) {
            return [null, null, 'ไฟล์ PDF ใหญ่เกินไป (ไม่เกิน 4 MB)'];
        }
        return [$bytes, 'application/pdf', ''];
    }
    if (!function_exists('imagecreatefromstring')) {
        return [null, null, 'เซิร์ฟเวอร์ยังไม่ได้เปิดใช้ส่วนขยาย GD จึงแนบรูปไม่ได้'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [null, null, 'รองรับเฉพาะภาพ JPEG, PNG, WebP และไฟล์ PDF'];
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[0] < 32 || $info[1] < 32) {
        return [null, null, 'ไฟล์ภาพไม่ถูกต้องหรือเล็กเกินไป'];
    }
    if ($info[0] * $info[1] > MEDIA_MAX_PIXELS) {
        return [null, null, 'ภาพมีความละเอียดสูงเกินไป'];
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return [null, null, 'อ่านไฟล์ภาพไม่ได้ (ไฟล์อาจเสียหาย)'];
    }
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, ATT_LONG_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, 85);
    $data = (string)ob_get_clean();
    unset($src, $dst);
    if ($data === '' || strlen($data) > ATT_MAX_STORED) {
        return [null, null, 'ประมวลผลภาพไม่สำเร็จหรือไฟล์ใหญ่เกินไป'];
    }
    return [$data, 'image/jpeg', ''];
}

function attachment_count(PDO $pdo, int $txnId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM transaction_attachments WHERE transaction_id = ?');
    $st->execute([$txnId]);
    return (int)$st->fetchColumn();
}

/** @return array<int,array> รายการแนบ (ไม่รวมเนื้อไฟล์) */
function attachment_list(PDO $pdo, int $txnId): array
{
    $st = $pdo->prepare('SELECT id, filename, mime, size, created_at FROM transaction_attachments WHERE transaction_id = ? ORDER BY id');
    $st->execute([$txnId]);
    return $st->fetchAll();
}

/** ผู้ใช้ปัจจุบันดูหลักฐานของวัดนี้ได้หรือไม่ (รวมเจ้าคณะจังหวัด/ตำบลตามขอบเขต) */
function attachment_can_view(PDO $pdo, int $templeId): bool
{
    [$sc, $sp] = scope_sql('t');
    $st = $pdo->prepare('SELECT 1 FROM temples t WHERE t.id = ?' . $sc);
    $st->execute(array_merge([$templeId], $sp));
    return (bool)$st->fetchColumn();
}

function attachment_clean_name(string $name, string $mime): string
{
    $base = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', basename(str_replace('\\', '/', $name)));
    $base = trim((string)$base, " ._");
    $base = mb_substr($base, 0, 100);
    $ext = $mime === 'application/pdf' ? '.pdf' : '.jpg';
    $base = preg_replace('/\.(jpe?g|png|webp|pdf)$/iu', '', $base);
    return ($base === '' ? 'หลักฐาน' : $base) . $ext;
}

/** @return string ข้อความผิดพลาด ('' = สำเร็จ) */
function attachment_save(PDO $pdo, int $txnId, int $templeId, ?array $file, ?int $userId): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'กรุณาเลือกไฟล์ที่จะแนบ';
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > ATT_MAX_UPLOAD) {
        return 'ไฟล์ใหญ่เกินไป (ไม่เกิน 4 MB)';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่';
    }
    if (attachment_count($pdo, $txnId) >= ATT_MAX_PER_TXN) {
        return 'แนบได้ไม่เกิน ' . ATT_MAX_PER_TXN . ' ไฟล์ต่อรายการ';
    }
    [$data, $mime, $err] = attachment_process((string)file_get_contents($file['tmp_name']));
    if ($data === null) {
        return $err;
    }
    $st = $pdo->prepare('INSERT INTO transaction_attachments (transaction_id, temple_id, filename, mime, size, data, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $st->bindValue(1, $txnId, PDO::PARAM_INT);
    $st->bindValue(2, $templeId, PDO::PARAM_INT);
    $st->bindValue(3, attachment_clean_name((string)$file['name'], $mime));
    $st->bindValue(4, $mime);
    $st->bindValue(5, strlen($data), PDO::PARAM_INT);
    $st->bindValue(6, $data, PDO::PARAM_LOB);
    $st->bindValue(7, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    audit('attach', 'transaction', $txnId, 'attachment ' . (int)$pdo->lastInsertId());
    return '';
}
