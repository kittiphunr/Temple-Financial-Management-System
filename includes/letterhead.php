<?php
/** หัวเอกสาร (โลโก้ + ชื่อหน่วยงาน) และบล็อกลงนาม สำหรับเอกสารที่พิมพ์จากระบบ */

/**
 * @param int $templeId วัด (>0 = หัวเอกสารของวัด ใช้โลโก้วัด), ไม่งั้นดู $districtId (โลโก้อำเภอ), ไม่งั้นระดับจังหวัด
 * @param bool $printOnly true = แสดงเฉพาะตอนพิมพ์ (หน้าจอปกติซ่อนไว้)
 * @param string $rightHtml (โหมด centered) เนื้อหาช่องขวาของหัวกระดาษ เช่น QR code — ว่าง = ช่องว่างขนาดเท่าโลโก้
 * @param bool $centered true = จัดสมมาตร (โลโก้ซ้าย + ช่องว่างขวาเท่ากัน ชื่อ/ที่อยู่อยู่กึ่งกลาง) ไม่แสดง "พิมพ์เมื่อ" — ใช้กับอนุโมทนาบัตร
 */
function letterhead(PDO $pdo, int $templeId, int $districtId, string $title, string $subtitle = '', bool $printOnly = false, bool $centered = false, string $rightHtml = ''): string
{
    global $CONFIG;
    $province = $CONFIG['province_name'] ?? 'แพร่';
    $logoId = null;
    $taxLine = '';
    $warn = '';
    if ($templeId && ($t = temple_row($templeId))) {
        $d = $pdo->prepare('SELECT name FROM districts WHERE id = ?');
        $d->execute([$t['district_id']]);
        $dn = (string)$d->fetchColumn();
        $name = $t['name'];
        $addr = array_filter([
            $t['house_no'] ? 'เลขที่ ' . $t['house_no'] : null,
            $t['moo'] ? 'หมู่ ' . trim($t['moo']) : null,
            $t['village'] ? 'บ้าน' . preg_replace('/^บ้าน/u', '', $t['village']) : null,
            $t['soi'] ? 'ซอย' . $t['soi'] : null,
            $t['road'] ? 'ถนน' . $t['road'] : null,
            $t['subdistrict'] ? 'ต.' . $t['subdistrict'] : null,
            'อ.' . $dn, 'จ.' . $province, $t['postcode'],
        ]);
        $line2 = implode(' ', $addr) . ($t['phone'] ? ' · โทร ' . $t['phone'] : '');
        $line3 = 'สังกัดคณะสงฆ์อำเภอ' . $dn;
        $taxLine = $t['tax_id'] ? 'หมายเลขประจำตัวผู้เสียภาษี ' . substr($t['tax_id'], 0, 3) . '-' . substr($t['tax_id'], 3, 1) . '-' . substr($t['tax_id'], 4, 8) . '-' . substr($t['tax_id'], 12, 1) : '';
        $logoId = $t['logo_media_id'] ? (int)$t['logo_media_id'] : null;
        if (!$logoId) {
            $link = people_can_edit($templeId)
                ? ' <a class="font-semibold underline" href="' . e(BASE . 'profile.php' . (user()['role'] === 'admin' ? '?temple=' . $templeId : '') . '#logo') . '">ใส่โลโก้วัด</a>' : '';
            $warn = '<p class="mb-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 print:hidden">ยังไม่ได้ใส่โลโก้วัด — เอกสารที่พิมพ์จะไม่มีโลโก้' . $link . '</p>';
        }
    } elseif ($districtId) {
        $d = $pdo->prepare('SELECT name, logo_media_id FROM districts WHERE id = ?');
        $d->execute([$districtId]);
        $row = $d->fetch() ?: ['name' => '', 'logo_media_id' => null];
        $name = 'คณะสงฆ์อำเภอ' . $row['name'];
        $line2 = 'จังหวัด' . $province;
        $line3 = '';
        $logoId = $row['logo_media_id'] ? (int)$row['logo_media_id'] : null;
    } else {
        $name = 'คณะสงฆ์จังหวัด' . $province;
        $line2 = $line3 = '';
    }
    $img = $logoId ? media_url($logoId) : BASE . 'assets/img/logo.svg';

    if ($centered) {
        return $warn . '<div class="rc-head">'
            . '<img class="rc-logo" src="' . e($img) . '" alt="โลโก้">'
            . '<div class="text-center"><div class="text-xl font-bold text-brand-800">' . e($name) . '</div>'
            . ($line2 !== '' ? '<div class="text-sm text-stone-700">' . e($line2) . '</div>' : '')
            . ($taxLine !== '' ? '<div class="text-sm text-stone-700">' . e($taxLine) . '</div>' : '')
            . ($line3 !== '' ? '<div class="text-sm text-stone-700">' . e($line3) . '</div>' : '') . '</div>' . ($rightHtml !== '' ? $rightHtml : '<div class="rc-logo" aria-hidden="true"></div>') . '</div>'
            . ($title !== '' ? '<div class="rc-title">' . e($title) . '</div>' : '');
    }
    $h = $warn . '<div class="' . ($printOnly ? 'hidden print:block' : '') . ' mb-4">'
        . '<div class="flex items-center gap-4 border-b-2 border-gold-500 pb-3">'
        . '<img class="h-20 w-20 shrink-0 object-contain" src="' . e($img) . '" alt="โลโก้">'
        . '<div><div class="text-xl font-bold text-brand-800">' . e($name) . '</div>'
        . ($line2 !== '' ? '<div class="text-sm text-stone-700">' . e($line2) . '</div>' : '')
        . ($line3 !== '' ? '<div class="text-sm text-stone-700">' . e($line3) . '</div>' : '') . '</div></div>'
        . '<div class="mt-3 text-center"><div class="text-lg font-bold">' . e($title) . '</div>'
        . ($subtitle !== '' ? '<div class="text-sm text-stone-700">' . e($subtitle) . '</div>' : '')
        . '<div class="text-xs text-stone-500">พิมพ์เมื่อ ' . e(th_date(date('Y-m-d'))) . '</div></div></div>';
    return $h;
}

/** บล็อกลงนามท้ายเอกสารของวัด: เจ้าอาวาส และไวยาวัจกร (ดึงชื่อจากข้อมูลบุคลากร) */
function signature_block(PDO $pdo, int $templeId, bool $printOnly = true): string
{
    $name = function (string $pos) use ($pdo, $templeId): string {
        $st = $pdo->prepare('SELECT title, first_name, last_name FROM temple_people WHERE temple_id = ? AND position = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $st->execute([$templeId, $pos]);
        $p = $st->fetch();
        return $p ? person_full_name($p, false) : '';
    };
    $box = fn(string $role, string $n) => '<div class="text-center"><div class="mb-1 mt-10 border-b border-dotted border-stone-500"></div>'
        . '<div class="text-sm">(' . ($n !== '' ? e($n) : '&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;&hellip;') . ')</div><div class="text-sm font-semibold">' . e($role) . '</div></div>';
    return '<div class="' . ($printOnly ? 'hidden print:grid' : 'grid') . ' mt-10 grid-cols-2 gap-10">'
        . $box('ไวยาวัจกร / ผู้จัดทำ', $name('steward')) . $box('เจ้าอาวาส', $name('abbot')) . '</div>';
}
