<?php
// ตัวช่วยของชุดทดสอบ: รัน SQL แล้วพิมพ์แถวผลลัพธ์แบบคั่นด้วยแท็บ (ไม่ต้องมี mysql CLI)
// ใช้: php sql.php "<SQL>" [ชื่อฐานข้อมูล]   ค่าเชื่อมต่อจาก TFMS_TEST_DB_HOST/PORT/USER/PASS
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$sql = $argv[1] ?? '';
$db = $argv[2] ?? '';
$dsn = sprintf('mysql:host=%s;port=%s;%scharset=utf8mb4', getenv('TFMS_TEST_DB_HOST') ?: '127.0.0.1',
    getenv('TFMS_TEST_DB_PORT') ?: '3306', $db !== '' ? "dbname=$db;" : '');
try {
    $pdo = new PDO($dsn, getenv('TFMS_TEST_DB_USER') ?: 'root', getenv('TFMS_TEST_DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $st = $pdo->query($sql);
    if ($st && $st->columnCount() > 0) {
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
            echo implode("\t", array_map(fn($v) => $v === null ? '' : (string)$v, $row)), "\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
