<?php
/**
 * สำรองข้อมูล: ส่งออกฐานข้อมูลทั้งหมดเป็นไฟล์ SQL บีบอัด (.sql.gz) โดยใช้ PDO ล้วน ไม่ต้องมี mysqldump
 * เก็บไว้ที่ storage/backups/ (ห้ามเข้าถึงจากเว็บ) · ดาวน์โหลด/สร้าง/ลบได้ที่ admin/backup.php · ตั้งสำรองอัตโนมัติได้ หรือใช้ cron: php backup.php
 * ข้อมูลอ่อนไหว (เลขบัตร ที่อยู่ผู้บริจาค) ในไฟล์สำรองเป็นข้อมูลที่เข้ารหัสด้วยกุญแจระบบ (storage/app.key) — ต้องสำรองกุญแจแยกต่างหาก
 * โดยส่งออกแบบเข้ารหัสด้วยรหัสผ่านสำรอง (backup_key_export) ไม่เก็บกุญแจไว้ในไฟล์เดียวกับฐานข้อมูล
 * กู้คืนด้วยบรรทัดคำสั่ง: php restore_backup.php ไฟล์.sql.gz --yes [--key=ไฟล์กุญแจ]
 */

const BACKUP_NAME_RE = '/^tfms-\d{8}-\d{6}(-\d{1,3})?\.sql\.gz$/';

function backup_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function backup_valid_name(string $name): bool
{
    return (bool)preg_match(BACKUP_NAME_RE, $name);
}

function backup_sql_value(PDO $pdo, $v, bool $binary): string
{
    if ($v === null) {
        return 'NULL';
    }
    if ($binary) {
        return $v === '' ? "''" : '0x' . bin2hex((string)$v);
    }
    return $pdo->quote((string)$v);
}

/**
 * เขียน SQL ทั้งฐานข้อมูลผ่านฟังก์ชัน $write(string) — หนึ่งคำสั่งต่อหนึ่งบรรทัด
 * @return array{tables:int,rows:int}
 */
function backup_dump(PDO $pdo, callable $write): array
{
    $write('-- ระบบบัญชีรายรับ-รายจ่ายวัด · ไฟล์สำรองข้อมูล · ' . app_version()['label'] . ' · ' . date('Y-m-d H:i:s') . "\n");
    // คอลัมน์ TIMESTAMP แสดงตามเขตเวลาของการเชื่อมต่อ จึงล็อกเป็น UTC ทั้งตอนสำรองและกู้คืน ไม่เช่นนั้นเวลาจะเลื่อนเมื่อเขตเวลาต่างกัน
    $oldTz = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
    $pdo->exec("SET time_zone = '+00:00'");
    $write("SET NAMES utf8mb4;\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $nRows = 0;
    foreach ($tables as $t) {
        $q = '`' . str_replace('`', '``', (string)$t) . '`';
        $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
        $write("DROP TABLE IF EXISTS $q;\n" . str_replace(["\r", "\n"], ' ', $create) . ";\n");
        $bin = [];
        $names = [];
        foreach ($pdo->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $names[] = '`' . str_replace('`', '``', $c['Field']) . '`';
            $bin[] = (bool)preg_match('/blob|binary/i', $c['Type']);
        }
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $pdo->query("SELECT * FROM $q");
        $batch = [];
        $size = 0;
        $head = "INSERT INTO $q (" . implode(',', $names) . ') VALUES ';
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            $vals = [];
            foreach ($row as $i => $v) {
                $vals[] = backup_sql_value($pdo, $v, $bin[$i]);
            }
            $tuple = '(' . implode(',', $vals) . ')';
            $batch[] = $tuple;
            $size += strlen($tuple);
            $nRows++;
            if (count($batch) >= 200 || $size > 2000000) {
                $write($head . implode(',', $batch) . ";\n");
                $batch = [];
                $size = 0;
            }
        }
        $st->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        if ($batch) {
            $write($head . implode(',', $batch) . ";\n");
        }
    }
    $write("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
    $pdo->exec('SET time_zone = ' . $pdo->quote($oldTz));
    return ['tables' => count($tables), 'rows' => $nRows];
}

/** สร้างไฟล์สำรองใหม่ใน storage/backups @return array{name:string,size:int,tables:int,rows:int} */
function backup_create(PDO $pdo): array
{
    @set_time_limit(300);
    $dir = backup_dir();
    $base = 'tfms-' . date('Ymd-His');
    $name = $base . '.sql.gz';
    for ($n = 2; file_exists("$dir/$name") && $n < 1000; $n++) {
        $name = "$base-$n.sql.gz";
    }
    $tmp = "$dir/.tmp-" . bin2hex(random_bytes(4));
    $gz = gzopen($tmp, 'wb6');
    if ($gz === false) {
        throw new RuntimeException('สร้างไฟล์สำรองไม่ได้ ตรวจสิทธิ์เขียนโฟลเดอร์ storage/');
    }
    try {
        $info = backup_dump($pdo, function (string $s) use ($gz) {
            gzwrite($gz, $s);
        });
    } catch (Throwable $e) {
        gzclose($gz);
        @unlink($tmp);
        throw $e;
    }
    gzclose($gz);
    @chmod($tmp, 0600);
    rename($tmp, "$dir/$name");
    return ['name' => $name, 'size' => (int)filesize("$dir/$name")] + $info;
}

/** @return array<int,array{name:string,size:int,mtime:int}> ใหม่สุดก่อน */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/tfms-*.sql.gz') ?: [] as $f) {
        if (backup_valid_name(basename($f))) {
            $out[] = ['name' => basename($f), 'size' => (int)filesize($f), 'mtime' => (int)filemtime($f)];
        }
    }
    usort($out, fn($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
    return $out;
}

/** ลบไฟล์สำรองเก่า เหลือ $keep ไฟล์ล่าสุด @return int จำนวนที่ลบ */
function backup_prune(int $keep): int
{
    $n = 0;
    foreach (array_slice(backup_list(), max(1, $keep)) as $b) {
        if (@unlink(backup_dir() . '/' . $b['name'])) {
            $n++;
        }
    }
    return $n;
}

/** กู้คืนจากไฟล์สำรองลงฐานข้อมูลที่เชื่อมต่ออยู่ (ลบ/สร้างตารางตามไฟล์) @return int จำนวนคำสั่งที่รัน */
function backup_restore(PDO $pdo, string $path): int
{
    $gz = gzopen($path, 'rb');
    if ($gz === false) {
        throw new RuntimeException('เปิดไฟล์สำรองไม่ได้');
    }
    $n = 0;
    while (($line = gzgets($gz, 64 * 1024 * 1024)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || str_starts_with($line, '--')) {
            continue;
        }
        $pdo->exec($line);
        $n++;
    }
    gzclose($gz);
    return $n;
}

/** ส่งออกกุญแจระบบแบบเข้ารหัสด้วยรหัสผ่านสำรอง (Argon2id + XSalsa20-Poly1305) เป็น JSON */
function backup_key_export(string $passphrase): string
{
    if (mb_strlen($passphrase) < 12) {
        throw new InvalidArgumentException('รหัสผ่านสำรองต้องยาวอย่างน้อย 12 ตัวอักษร');
    }
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
    $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
    $k = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $ct = sodium_crypto_secretbox(app_key(), $nonce, $k);
    sodium_memzero($k);
    return json_encode(['tfms_key' => 1, 'kdf' => 'argon2id13', 'ops' => $ops, 'mem' => $mem, 'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce), 'ct' => base64_encode($ct), 'created' => date('Y-m-d')], JSON_UNESCAPED_UNICODE) . "\n";
}

/** ถอดรหัสไฟล์กุญแจที่ส่งออกไว้ @return ?string กุญแจดิบ 32 ไบต์ (null = รหัสผ่านผิดหรือไฟล์ไม่ถูกต้อง) */
function backup_key_import(string $json, string $passphrase): ?string
{
    $m = json_decode($json, true);
    if (!is_array($m) || ($m['tfms_key'] ?? 0) !== 1) {
        return null;
    }
    $salt = base64_decode((string)($m['salt'] ?? ''), true);
    $nonce = base64_decode((string)($m['nonce'] ?? ''), true);
    $ct = base64_decode((string)($m['ct'] ?? ''), true);
    if ($salt === false || $nonce === false || $ct === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $k = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, (int)($m['ops'] ?? 0), (int)($m['mem'] ?? 0), SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $plain = sodium_crypto_secretbox_open($ct, $nonce, $k);
    sodium_memzero($k);
    return $plain === false || strlen($plain) !== 32 ? null : $plain;
}

/** สำรองอัตโนมัติ: ถ้าเปิดใช้และถึงกำหนด (ทุก N วัน) จะสร้างไฟล์สำรองและลบไฟล์เก่า — เรียกจากหน้าจัดการระบบของแอดมินหลัก */
function backup_auto_maybe(PDO $pdo): ?array
{
    $days = (int)setting('backup_auto_days', '0');
    if ($days < 1) {
        return null;
    }
    $last = (int)setting('backup_last', '0');
    if ($last > 0 && time() - $last < $days * 86400 - 3600) {
        return null;
    }
    $lock = 'tfms_backup';
    if (!(int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn()) {
        return null;
    }
    try {
        setting_set('backup_last', (string)time());   // ตั้งก่อน กันหลายคำขอวิ่งพร้อมกันหรือล้มซ้ำทุกครั้ง
        $r = backup_create($pdo);
        backup_prune(max(1, (int)setting('backup_keep', '14')));
        audit('backup_auto', 'system', null, $r['name']);
        return $r;
    } catch (Throwable $e) {
        audit('backup_auto_failed', 'system', null, mb_substr($e->getMessage(), 0, 200));
        return null;
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}
