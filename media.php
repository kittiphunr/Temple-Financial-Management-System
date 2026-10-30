<?php
/** ส่งรูป (โลโก้/รูปถ่าย) จากตาราง media — ตรวจสิทธิ์ทุกครั้ง ไม่มี URL ไฟล์ตรง ๆ ให้เปิดได้ */
require __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT id, kind, mime, size FROM media WHERE id = ?');
$st->execute([$id]);
$m = $st->fetch();
if (!$m) {
    http_response_code(404);
    exit('ไม่พบรูปภาพ');
}
if ($m['kind'] === 'photo') {
    // รูปถ่ายบุคคล: ดูได้เฉพาะผู้ที่มีสิทธิ์ดูบุคลากรของวัดนั้น
    $p = db()->prepare('SELECT temple_id FROM temple_people WHERE photo_media_id = ?');
    $p->execute([$id]);
    $person = $p->fetch();
    // รูปทรัพย์สินใช้ชนิด photo เหมือนกัน: ถ้าไม่ใช่รูปบุคคล ให้ตรวจสิทธิ์ตามวัดเจ้าของทรัพย์สิน
    $allowed = $person ? people_can_view((int)$person['temple_id']) : asset_photo_can_view(db(), $id);
    if (!$allowed) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์ดูรูปนี้');
    }
}

$etag = '"m' . $m['id'] . '-' . $m['size'] . '"';
header_remove('Pragma');
header_remove('Expires');
header('ETag: ' . $etag);
// โลโก้เก็บแคชในเบราว์เซอร์ได้ (id เปลี่ยนทุกครั้งที่อัปโหลดใหม่); รูปถ่ายบุคคลต้องตรวจสอบสิทธิ์ซ้ำทุกครั้ง
header('Cache-Control: ' . ($m['kind'] === 'logo' ? 'private, max-age=86400' : 'private, no-cache'));
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
$d = db()->prepare('SELECT data FROM media WHERE id = ?');
$d->execute([$id]);
header('Content-Type: ' . $m['mime']);
header('Content-Length: ' . $m['size']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'none'");
echo $d->fetchColumn();
