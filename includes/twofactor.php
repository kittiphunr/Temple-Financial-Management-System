<?php
/**
 * รหัสยืนยันทางอีเมล ใช้ได้ครั้งเดียว ลองผิดได้ 5 ครั้ง แยกตามจุดประสงค์ (purpose):
 *  login = ยืนยันตัวตนสองชั้นตอนเข้าสู่ระบบ (6 หลัก, 10 นาที)
 *  enroll = ยืนยันอีเมลของแอดมินหลัก (6 หลัก, 10 นาที)
 *  reset = กู้รหัสผ่านที่ลืม (8 หลัก, 15 นาที)
 */

const OTP_TTL_SECONDS = 600;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN = 60;
const OTP_MAX_PER_30_MIN = 5;
const OTP_PENDING_SECONDS = 900;
const OTP_PURPOSES = ['login' => ['digits' => 6, 'ttl' => 600], 'enroll' => ['digits' => 6, 'ttl' => 600], 'reset' => ['digits' => 8, 'ttl' => 900]];

function otp_hash(string $code, string $salt, int $userId): string
{
    return hash_hmac('sha256', $code, $salt . ':' . $userId);
}

/** วินาทีที่ต้องรอก่อนขอรหัสใหม่ได้ (0 = ขอได้เลย) */
function otp_cooldown_left(PDO $pdo, int $userId, string $purpose = 'login'): int
{
    $st = $pdo->prepare('SELECT ' . OTP_RESEND_COOLDOWN . ' - TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM login_otps WHERE user_id = ? AND purpose = ?');
    $st->execute([$userId, $purpose]);
    $left = $st->fetchColumn();
    return $left === null ? 0 : max(0, (int)$left);
}

/**
 * สร้างรหัสและส่งอีเมล
 * @return array{0:bool,1:string} [สำเร็จ, ข้อความผิดพลาด]
 */
function otp_issue(PDO $pdo, array $user, string $email, string $purpose = 'login'): array
{
    $uid = (int)$user['id'];
    $cfg = OTP_PURPOSES[$purpose];
    if (otp_cooldown_left($pdo, $uid, $purpose) > 0) {
        return [false, 'กรุณารอสักครู่ก่อนขอรหัสใหม่'];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_otps WHERE user_id = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $st->execute([$uid, $purpose]);
    if ((int)$st->fetchColumn() >= OTP_MAX_PER_30_MIN) {
        return [false, 'ขอรหัสบ่อยเกินไป กรุณาลองใหม่ภายหลัง 30 นาที'];
    }

    $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$uid, $purpose]);
    $code = str_pad((string)random_int(0, 10 ** $cfg['digits'] - 1), $cfg['digits'], '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO login_otps (user_id, purpose, code_hash, salt, expires_at) VALUES (?,?,?,?, DATE_ADD(NOW(), INTERVAL ' . (int)$cfg['ttl'] . ' SECOND))')
        ->execute([$uid, $purpose, otp_hash($code, $salt, $uid), $salt]);
    $otpId = (int)$pdo->lastInsertId();

    $minutes = $cfg['ttl'] / 60;
    $ignore = "หากคุณไม่ได้เป็นผู้ดำเนินการนี้ ให้เพิกเฉยต่ออีเมลนี้ และแจ้งผู้ดูแลระบบ ห้ามบอกรหัสนี้กับผู้อื่น\n";
    [$subject, $intro] = match ($purpose) {
        'reset' => ['รหัสกู้รหัสผ่าน', 'รหัสสำหรับตั้งรหัสผ่านใหม่ของระบบบัญชีวัด'],
        'enroll' => ['รหัสยืนยันอีเมล', 'รหัสยืนยันอีเมลสำหรับบัญชีผู้ดูแลระบบ'],
        default => ['รหัสยืนยันเข้าสู่ระบบ', 'รหัสยืนยันการเข้าสู่ระบบ'],
    };
    $body = "$intro\n\nรหัสของคุณคือ: $code\n\nรหัสนี้ใช้ได้ครั้งเดียวและหมดอายุใน $minutes นาที\n$ignore";
    if (!send_mail($email, $subject . ' ' . $code, $body)) {
        $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ?')->execute([$otpId]);
        return [false, 'ส่งอีเมลรหัสยืนยันไม่สำเร็จ กรุณาแจ้งผู้ดูแลระบบ'];
    }
    return [true, ''];
}

/** @return string ok | bad | expired | locked */
function otp_verify(PDO $pdo, int $userId, string $code, string $purpose = 'login'): string
{
    $st = $pdo->prepare('SELECT *, (expires_at > NOW()) AS alive FROM login_otps
                         WHERE user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$userId, $purpose]);
    $row = $st->fetch();
    if (!$row || !$row['alive']) {
        return 'expired';
    }
    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        return 'locked';
    }
    if (hash_equals($row['code_hash'], otp_hash($code, $row['salt'], $userId))) {
        // ใช้ได้ครั้งเดียว: ถ้าสองคำขอชนกัน มีเพียงคำขอเดียวที่อัปเดตแถวนี้สำเร็จ
        $u = $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $u->execute([$row['id']]);
        return $u->rowCount() === 1 ? 'ok' : 'expired';
    }
    $pdo->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    if ((int)$row['attempts'] + 1 >= OTP_MAX_ATTEMPTS) {
        $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return 'locked';
    }
    return 'bad';
}

function otp_invalidate_all(PDO $pdo, int $userId, ?string $purpose = null): void
{
    $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL' . ($purpose ? ' AND purpose = ?' : ''))
        ->execute($purpose ? [$userId, $purpose] : [$userId]);
}
