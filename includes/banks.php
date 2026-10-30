<?php
/**
 * ธนาคารไทยพร้อมโลโก้ (จาก casperstack/thai-banks-logo — ดูเครดิตใน CREDITS.md) และภาพหน้าบุ๊คแบงก์ของบัญชีธนาคาร
 * ธนาคารที่ไม่อยู่ในรายชื่อ (สหกรณ์ออมทรัพย์ ฯลฯ) เลือก "อื่น ๆ" แล้วพิมพ์ชื่อเองได้ — ไม่มีโลโก้
 * ถ้ายังไม่ได้รัน migration (sql/migrations/2026_bank_book.sql) จะยังพิมพ์ชื่อธนาคารเองได้เหมือนเดิม และไม่มีการแนบภาพหน้าบุ๊คแบงก์
 */

/** @return array<string,array{code:string,name:string,name_long:string,name_en:string,color:string,logo:string}> รหัส => ข้อมูล */
function thai_banks(): array
{
    static $banks = null;
    if ($banks === null) {
        $banks = [];
        $j = @file_get_contents(dirname(__DIR__) . '/data/thai_banks.json');
        foreach ((array)json_decode((string)$j, true) as $b) {
            if (isset($b['code'], $b['name_long'])) {
                $banks[$b['code']] = $b;
            }
        }
    }
    return $banks;
}

function bank_book_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT bank_code, bookbank_media_id FROM accounts LIMIT 1');
            $ok = true;
        } catch (PDOException) {
            $ok = false;
        }
    }
    return $ok;
}

/** ธนาคารของบัญชี: จากรหัสที่เลือก หรือเดาจากชื่อที่พิมพ์ไว้เดิม (ตรงกับชื่อสั้น/ชื่อเต็ม/ชื่อภาษาอังกฤษ) @return ?array */
function bank_of_account(array $a): ?array
{
    $banks = thai_banks();
    if (!empty($a['bank_code']) && isset($banks[$a['bank_code']])) {
        return $banks[$a['bank_code']];
    }
    $n = mb_strtolower(trim((string)($a['bank_name'] ?? '')));
    if ($n === '') {
        return null;
    }
    foreach ($banks as $b) {
        foreach ([$b['name'], $b['name_long'], $b['name_en'], 'ธนาคาร' . $b['name']] as $cand) {
            if ($cand !== '' && mb_strtolower($cand) === $n) {
                return $b;
            }
        }
    }
    return null;
}

/** โลโก้ธนาคารขนาดเล็กหน้าชื่อธนาคาร (ไม่มีโลโก้ = ว่าง) */
function bank_logo_img(?array $bank, string $class = 'h-6 w-6'): string
{
    return $bank ? '<img src="' . e(BASE . $bank['logo']) . '" alt="" width="24" height="24" loading="lazy" class="inline-block shrink-0 rounded-md ' . e($class) . '">' : '';
}

/** ข้อความธนาคารพร้อมโลโก้ (ถ้ามี) */
function bank_chip(array $a): string
{
    $b = bank_of_account($a);
    return '<span class="inline-flex items-center gap-2">' . bank_logo_img($b) . '<span>' . e($b['name_long'] ?? ($a['bank_name'] ?: 'ธนาคาร')) . '</span></span>';
}

/**
 * ตัวเลือกธนาคารพร้อมโลโก้ (ใช้ได้โดยไม่ต้องมี JavaScript — เป็น radio ใน details; JS เพิ่มการค้นหาและปิดเมนูเมื่อเลือก)
 * ส่งค่า bank_code (รหัสธนาคาร หรือว่างถ้าเลือกอื่น ๆ) และ bank_name (ชื่อที่พิมพ์เองเมื่อเลือกอื่น ๆ)
 */
function bank_picker(string $selectedCode = '', string $otherName = '', string $id = 'bank'): string
{
    $banks = thai_banks();
    $cur = $banks[$selectedCode] ?? null;
    $h = '<div class="bank-picker" data-bank-picker><details class="relative"><summary class="ctl-box flex cursor-pointer list-none items-center gap-2 text-stone-800 hover:border-gold-500" data-bank-summary>'
        . '<span class="flex min-w-0 flex-1 items-center gap-2" data-bank-label>' . ($cur ? bank_logo_img($cur) . '<span class="truncate">' . e($cur['name_long']) . '</span>' : '<span class="text-stone-500">เลือกธนาคาร…</span>') . '</span>'
        . icon('chevron-down', 'opacity-60') . '</summary>'
        . '<div class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-y-auto rounded-xl border border-stone-200 bg-white p-1.5 shadow-lg">'
        . '<input type="search" placeholder="ค้นหาธนาคาร" class="mb-1 w-full" data-bank-search autocomplete="off" aria-label="ค้นหาธนาคาร">';
    foreach ($banks as $b) {
        $h .= '<label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-stone-800 hover:bg-gold-100 has-[:checked]:bg-gold-100" data-bank-opt data-q="' . e(mb_strtolower($b['name'] . ' ' . $b['name_long'] . ' ' . $b['name_en'] . ' ' . $b['code'])) . '">'
            . '<input type="radio" name="bank_code" value="' . e($b['code']) . '" class="sr-only" ' . ($selectedCode === $b['code'] ? 'checked' : '') . '>'
            . bank_logo_img($b) . '<span class="min-w-0 flex-1 truncate">' . e($b['name_long']) . '</span><small class="text-stone-500">' . e($b['code']) . '</small></label>';
    }
    $h .= '<label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-stone-800 hover:bg-gold-100 has-[:checked]:bg-gold-100" data-bank-opt data-q="อื่น ๆ other">'
        . '<input type="radio" name="bank_code" value="" class="sr-only" ' . ($selectedCode === '' && $otherName !== '' ? 'checked' : '') . '><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-stone-100 text-stone-500">' . icon('more-horizontal') . '</span><span>อื่น ๆ (พิมพ์ชื่อเอง)</span></label>'
        . '</div></details>'
        . '<input name="bank_name" maxlength="100" value="' . e($otherName) . '" placeholder="ชื่อธนาคาร/สถาบันการเงิน (กรณีเลือก อื่น ๆ)" class="mt-1.5 w-full" data-bank-other aria-label="ชื่อธนาคารอื่น ๆ"></div>';
    return $h;
}

/** รหัสธนาคารและชื่อที่จะเก็บ จากฟอร์ม (bank_code ที่ถูกต้อง → ใช้ชื่อเต็มของรายการ · ไม่งั้นใช้ชื่อที่พิมพ์) @return array{0:?string,1:string} [bank_code, bank_name] */
function bank_from_input(array $in): array
{
    $banks = thai_banks();
    $code = (string)($in['bank_code'] ?? '');
    if ($code !== '' && isset($banks[$code])) {
        return [$code, $banks[$code]['name_long']];
    }
    return [null, mb_substr(trim((string)($in['bank_name'] ?? '')), 0, 100)];
}

/** ภาพหน้าบุ๊คแบงก์: ผู้ใช้ปัจจุบันดูภาพนี้ได้หรือไม่ (ใช้ใน media.php) — เฉพาะผู้ที่แก้ไขบัญชีวัดนั้นได้ · null = ไม่ใช่ภาพบุ๊คแบงก์ */
function bank_book_photo_can_view(PDO $pdo, int $mediaId): ?bool
{
    if (!bank_book_available()) {
        return null;
    }
    $st = $pdo->prepare('SELECT temple_id FROM accounts WHERE bookbank_media_id = ?');
    $st->execute([$mediaId]);
    $tid = $st->fetchColumn();
    return $tid === false ? null : can_access_temple((int)$tid);
}
