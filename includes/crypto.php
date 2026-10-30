<?php
/**
 * การเข้ารหัสข้อมูลส่วนบุคคล (เลขบัตรประชาชน) ด้วย libsodium
 * กุญแจหลัก 32 ไบต์: config 'app_key' / ตัวแปร TFMS_APP_KEY (base64) / ไฟล์ storage/app.key (สร้างอัตโนมัติ สิทธิ์ 0600)
 * ถ้ากุญแจสูญหาย เลขบัตรที่เข้ารหัสไว้จะถอดรหัสไม่ได้ — ต้องสำรองไฟล์ storage/app.key ไว้เสมอ
 */

function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    global $CONFIG;
    $b64 = trim((string)($CONFIG['app_key'] ?? '')) ?: trim((string)getenv('TFMS_APP_KEY'));
    if ($b64 === '') {
        $file = dirname(__DIR__) . '/storage/app.key';
        if (!is_file($file)) {
            is_dir(dirname($file)) || @mkdir(dirname($file), 0750, true);
            $fh = @fopen($file, 'x'); // สร้างแบบ exclusive กันสองคำขอสร้างกุญแจคนละดอก
            if ($fh !== false) {
                fwrite($fh, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
                fclose($fh);
                @chmod($file, 0600);
            }
        }
        for ($i = 0; $i < 20 && (!is_file($file) || filesize($file) === 0); $i++) {
            usleep(50000);
        }
        $b64 = is_file($file) ? trim((string)file_get_contents($file)) : '';
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('กุญแจเข้ารหัสไม่ถูกต้อง (ต้องเป็น base64 ของ 32 ไบต์) หรือสร้างไฟล์ storage/app.key ไม่ได้ — ตรวจสิทธิ์เขียนโฟลเดอร์ storage/');
    }
    return $key = $raw;
}

function crypto_subkey(string $label): string
{
    return hash_hmac('sha256', 'tfms:' . $label, app_key(), true);
}

function crypto_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, crypto_subkey('secretbox'));
}

function crypto_decrypt(string $blob): ?string
{
    if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), crypto_subkey('secretbox'));
    return $plain === false ? null : $plain;
}

/** ค่าแฮช HMAC ของเลขบัตร ใช้กันซ้ำโดยไม่ต้องถอดรหัส */
function national_id_index(string $id13): string
{
    return hash_hmac('sha256', $id13, crypto_subkey('national-id-index'));
}

/** เลขบัตรประชาชนไทย 13 หลัก ตรวจหลักตรวจสอบ (mod 11) */
function thai_id_valid(string $id): bool
{
    if (!preg_match('/^\d{13}$/', $id)) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$id[$i] * (13 - $i);
    }
    return ((11 - ($sum % 11)) % 10) === (int)$id[12];
}

function format_thai_id(string $id13): string
{
    return sprintf('%s-%s-%s-%s-%s', substr($id13, 0, 1), substr($id13, 1, 4), substr($id13, 5, 5), substr($id13, 10, 2), substr($id13, 12, 1));
}

/** แสดงแบบปิดบัง เหลือ 4 หลักท้าย: X-XXXX-XXXX9-12-3 */
function mask_thai_id(string $last4): string
{
    return format_thai_id(str_repeat('X', 9) . $last4);
}
