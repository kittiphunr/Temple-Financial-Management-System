<?php
/**
 * ส่งอีเมลข้อความล้วน (UTF-8)
 * driver: smtp (ค่าเริ่มต้น) | mail (PHP mail()) | log (ทดสอบเท่านั้น — เขียนลง storage/mail.log)
 */
function mail_valid_address(string $addr): bool
{
    return strlen($addr) <= 190 && !preg_match('/[\r\n\s<>]/', $addr) && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false;
}

function mail_encode_header(string $s): string
{
    $s = str_replace(["\r", "\n"], ' ', $s);
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function send_mail(string $to, string $subject, string $body): bool
{
    global $CONFIG;
    if (!mail_valid_address($to)) {
        return false;
    }
    switch ($CONFIG['mail_driver']) {
        case 'log':
            $dir = __DIR__ . '/../storage';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            return file_put_contents($dir . '/mail.log',
                '[' . date('c') . "] TO: $to\nSUBJECT: $subject\n$body\n----\n", FILE_APPEND | LOCK_EX) !== false;
        case 'mail':
            $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n"
                . 'From: ' . mail_encode_header($CONFIG['mail_from_name']) . ' <' . $CONFIG['mail_from'] . ">\r\n";
            return @mail($to, mail_encode_header($subject), chunk_split(base64_encode($body)), $headers);
        default:
            return smtp_send($CONFIG, $to, $subject, $body);
    }
}

function smtp_send(array $c, string $to, string $subject, string $body): bool
{
    if ($c['smtp_host'] === '' || !mail_valid_address($c['mail_from'])) {
        return false;
    }
    $secure = $c['smtp_secure'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . $c['smtp_port'],
        $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 10);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $line, array $ok) use ($fp, $read): ?string {
        fwrite($fp, $line . "\r\n");
        $r = $read();
        return in_array((int)substr($r, 0, 3), $ok, true) ? $r : null;
    };

    $ok = false;
    do {
        if ((int)substr($read(), 0, 3) !== 220) break;
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
        if ($cmd("EHLO $host", [250]) === null) break;
        if ($secure === 'tls') {
            if ($cmd('STARTTLS', [220]) === null) break;
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) break;
            if ($cmd("EHLO $host", [250]) === null) break;
        }
        if ($c['smtp_user'] !== '') {
            if ($cmd('AUTH LOGIN', [334]) === null) break;
            if ($cmd(base64_encode($c['smtp_user']), [334]) === null) break;
            if ($cmd(base64_encode($c['smtp_pass']), [235]) === null) break;
        }
        if ($cmd('MAIL FROM:<' . $c['mail_from'] . '>', [250]) === null) break;
        if ($cmd("RCPT TO:<$to>", [250, 251]) === null) break;
        if ($cmd('DATA', [354]) === null) break;

        $msg = 'From: ' . mail_encode_header($c['mail_from_name']) . ' <' . $c['mail_from'] . ">\r\n"
            . "To: <$to>\r\n"
            . 'Subject: ' . mail_encode_header($subject) . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . ">\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body));
        fwrite($fp, $msg . "\r\n.\r\n");
        if ((int)substr($read(), 0, 3) !== 250) break;
        $ok = true;
    } while (false);

    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}
