<?php
/** ยืนยันตัวตนสองชั้น: รหัส 6 หลักส่งเข้าอีเมลวัด ใช้ได้ครั้งเดียว หมดอายุ 10 นาที ลองผิดได้ 5 ครั้ง */

const OTP_TTL_SECONDS = 600;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_COOLDOWN = 60;
const OTP_MAX_PER_30_MIN = 5;
const OTP_PENDING_SECONDS = 900;

function otp_hash(string $code, string $salt, int $userId): string
{
    return hash_hmac('sha256', $code, $salt . ':' . $userId);
}

/** วินาทีที่ต้องรอก่อนขอรหัสใหม่ได้ (0 = ขอได้เลย) */
function otp_cooldown_left(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare('SELECT ' . OTP_RESEND_COOLDOWN . ' - TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM login_otps WHERE user_id = ?');
    $st->execute([$userId]);
    $left = $st->fetchColumn();
    return $left === null ? 0 : max(0, (int)$left);
}

/**
 * สร้างรหัสและส่งอีเมล
 * @return array{0:bool,1:string} [สำเร็จ, ข้อความผิดพลาด]
 */
function otp_issue(PDO $pdo, array $user, string $email): array
{
    $uid = (int)$user['id'];
    if (otp_cooldown_left($pdo, $uid) > 0) {
        return [false, 'กรุณารอสักครู่ก่อนขอรหัสใหม่'];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_otps WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= OTP_MAX_PER_30_MIN) {
        return [false, 'ขอรหัสบ่อยเกินไป กรุณาลองใหม่ภายหลัง 30 นาที'];
    }

    $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$uid]);
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO login_otps (user_id, code_hash, salt, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ' . OTP_TTL_SECONDS . ' SECOND))')
        ->execute([$uid, otp_hash($code, $salt, $uid), $salt]);
    $otpId = (int)$pdo->lastInsertId();

    $body = "รหัสยืนยันการเข้าสู่ระบบบัญชีวัด\n\n"
        . "รหัสของคุณคือ: $code\n\n"
        . 'รหัสนี้ใช้ได้ครั้งเดียวและหมดอายุใน ' . (OTP_TTL_SECONDS / 60) . " นาที\n"
        . "หากคุณไม่ได้เป็นผู้พยายามเข้าสู่ระบบ ให้เพิกเฉยต่ออีเมลนี้ และแจ้งผู้ดูแลระบบ ห้ามบอกรหัสนี้กับผู้อื่น\n";
    if (!send_mail($email, 'รหัสยืนยันเข้าสู่ระบบ ' . $code, $body)) {
        $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE id = ?')->execute([$otpId]);
        return [false, 'ส่งอีเมลรหัสยืนยันไม่สำเร็จ กรุณาแจ้งผู้ดูแลระบบ'];
    }
    return [true, ''];
}

/** @return string ok | bad | expired | locked */
function otp_verify(PDO $pdo, int $userId, string $code): string
{
    $st = $pdo->prepare('SELECT *, (expires_at > NOW()) AS alive FROM login_otps
                         WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$userId]);
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

function otp_invalidate_all(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE login_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
}
