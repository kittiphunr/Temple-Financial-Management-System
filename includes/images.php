<?php
/**
 * รับรูปที่อัปโหลด (โลโก้/รูปถ่าย/หลักฐาน): ตรวจชนิดจากเนื้อไฟล์จริง จำกัดขนาด ย่อตามโปรไฟล์ และ "แปลงใหม่" เป็น WebP ด้วย GD
 * การแปลงใหม่ลบข้อมูลแฝง (EXIF/GPS) และโค้ดที่ซ่อนในไฟล์ ไม่รับ SVG/GIF
 * เก็บผลลัพธ์ในตาราง media (ไม่เก็บเป็นไฟล์บนเว็บ)
 */

const MEDIA_MAX_BYTES = 2 * 1024 * 1024;
const MEDIA_MAX_PIXELS = 25000000;

/**
 * โปรไฟล์ขนาดรูปที่เก็บในระบบ: กรอบสูงสุด (กว้าง x สูง) คุณภาพ WebP และขนาดไฟล์สูงสุดหลังแปลง
 * logo = ตราวัด (คงความโปร่งใส) · photo = รูปบุคลากร/ทรัพย์สิน · evidence = หลักฐานแนบ (ใบเสร็จ/สลิป ต้องอ่านตัวเลขออก)
 */
const IMAGE_PROFILES = [
    'logo' => ['w' => 512, 'h' => 512, 'quality' => 85, 'alpha' => true, 'max_bytes' => MEDIA_MAX_BYTES],
    'photo' => ['w' => 600, 'h' => 800, 'quality' => 82, 'alpha' => false, 'max_bytes' => MEDIA_MAX_BYTES],
    'evidence' => ['w' => 2000, 'h' => 2000, 'quality' => 82, 'alpha' => false, 'max_bytes' => 4 * 1024 * 1024],
];

/** GD เข้ารหัส WebP ได้หรือไม่ (ถ้าไม่ได้ จะใช้ PNG สำหรับโลโก้และ JPEG สำหรับรูปอื่นแทน) */
function image_webp_supported(): bool
{
    return function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
}

/**
 * แปลงรูปเป็นไฟล์ที่จะเก็บ: ตรวจชนิดจากเนื้อไฟล์จริง ย่อตามโปรไฟล์ และเข้ารหัสใหม่เป็น WebP (ลบ EXIF/GPS และโค้ดที่ซ่อนในไฟล์)
 * @param string $profile logo|photo|evidence
 * @return array{0:?string,1:?string,2:string} [ไบต์รูปที่แปลงแล้ว, mime, ข้อความผิดพลาด]
 */
function image_process(string $bytes, string $profile): array
{
    $prof = IMAGE_PROFILES[$profile] ?? IMAGE_PROFILES['photo'];
    if (!function_exists('imagecreatefromstring')) {
        return [null, null, 'เซิร์ฟเวอร์ยังไม่ได้เปิดใช้ส่วนขยาย GD จึงอัปโหลดรูปไม่ได้'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [null, null, 'รองรับเฉพาะไฟล์ภาพ JPEG, PNG หรือ WebP เท่านั้น'];
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
    $scale = min(1, $prof['w'] / $w, $prof['h'] / $h);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    if ($prof['alpha']) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // พื้นโปร่งใส -> พื้นขาว
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    if (image_webp_supported()) {
        imagewebp($dst, null, $prof['quality']);
        $out = 'image/webp';
    } elseif ($prof['alpha']) {
        imagepng($dst, null, 6);
        $out = 'image/png';
    } else {
        imagejpeg($dst, null, 85);
        $out = 'image/jpeg';
    }
    $data = (string)ob_get_clean();
    unset($src, $dst);
    if ($data === '' || strlen($data) > $prof['max_bytes']) {
        return [null, null, 'ประมวลผลภาพไม่สำเร็จหรือไฟล์หลังแปลงใหญ่เกินไป'];
    }
    return [$data, $out, ''];
}

/** นามสกุลไฟล์ที่ตรงกับ mime ที่เก็บ */
function image_ext(string $mime): string
{
    return ['image/webp' => '.webp', 'image/png' => '.png', 'image/jpeg' => '.jpg'][$mime] ?? '';
}

/**
 * บันทึกรูปจาก $_FILES[...] ลงตาราง media
 * @return array{0:?int,1:string} [media id, ข้อความผิดพลาด] — ไม่ได้เลือกไฟล์ = [null, '']
 */
function media_save_upload(PDO $pdo, ?array $file, string $kind, ?int $userId): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, ''];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'ไฟล์ภาพใหญ่เกินไป (ไม่เกิน 2 MB)'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่'];
    }
    if ($file['size'] > MEDIA_MAX_BYTES) {
        return [null, 'ไฟล์ภาพใหญ่เกินไป (ไม่เกิน 2 MB)'];
    }
    [$data, $mime, $err] = image_process((string)file_get_contents($file['tmp_name']), $kind);
    if ($data === null) {
        return [null, $err];
    }
    $st = $pdo->prepare('INSERT INTO media (kind, mime, size, data, created_by) VALUES (?,?,?,?,?)');
    $st->bindValue(1, $kind);
    $st->bindValue(2, $mime);
    $st->bindValue(3, strlen($data), PDO::PARAM_INT);
    $st->bindValue(4, $data, PDO::PARAM_LOB);
    $st->bindValue(5, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    return [(int)$pdo->lastInsertId(), ''];
}

function media_delete(PDO $pdo, ?int $id): void
{
    if ($id) {
        $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
    }
}

function media_url(?int $id): string
{
    return $id ? BASE . 'media.php?id=' . $id : '';
}
