<?php
/** ส่งไฟล์หลักฐานแนบรายการ — ตรวจสิทธิ์ตามขอบเขตของผู้ใช้ทุกครั้ง ไม่มี URL ไฟล์ตรง ๆ */
require __DIR__ . '/includes/bootstrap.php';
require_login();
if (!attachments_available()) {
    http_response_code(404);
    exit('ไม่พบไฟล์');
}
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT a.id, a.temple_id, a.filename, a.mime, a.size FROM transaction_attachments a JOIN transactions x ON x.id = a.transaction_id WHERE a.id = ? AND x.is_deleted = 0');
$st->execute([$id]);
$a = $st->fetch();
if (!$a) {
    http_response_code(404);
    exit('ไม่พบไฟล์');
}
if (!attachment_can_view(db(), (int)$a['temple_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูไฟล์นี้');
}
$d = db()->prepare('SELECT data FROM transaction_attachments WHERE id = ?');
$d->execute([$id]);
$isPdf = $a['mime'] === 'application/pdf';
header_remove('Pragma');
header_remove('Expires');
header('Cache-Control: private, no-store');
header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . $a['size']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'none'; sandbox");
$fn = rawurlencode($a['filename']);
header('Content-Disposition: ' . ($isPdf || isset($_GET['dl']) ? 'attachment' : 'inline') . "; filename*=UTF-8''" . $fn);
echo $d->fetchColumn();
