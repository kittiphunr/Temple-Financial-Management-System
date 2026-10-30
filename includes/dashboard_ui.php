<?php
/** ส่วนประกอบหน้าแดชบอร์ด: ไอคอน, การ์ดสถิติ 4 ใบ (ตามแบบเดโม่), กราฟรายเดือน (SVG) */

function icon(string $name, string $cls = 'h-6 w-6'): string
{
    $paths = [
        'coins' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
        'doc' => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6M10 17h6"/>',
        'scale' => '<path d="M12 4v16M7 20h10M4 8h16"/><path d="M6 8l-3 6a3 3 0 0 0 6 0zM18 8l-3 6a3 3 0 0 0 6 0z"/>',
        'temple' => '<path d="M12 2v3"/><path d="M12 5l4 4H8z"/><path d="M7 9h10l1 4H6z"/><path d="M5 13h14v8H5z"/><path d="M10 21v-4h4v4"/>',
    ];
    return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * การ์ดสถิติ 4 ใบ: รายรับรวม / รายจ่ายรวม / ยอดคงเหลือ / จำนวนวัด
 * @param string $scopeText เช่น "ในสังกัดอำเภอเมืองแพร่"
 */
function stat_cards(array $sum, int $beYear, int $temples, string $scopeText): string
{
    $card = function (string $icon, string $iconCls, string $label, string $labelCls, string $value, string $valueCls, string $unit, string $note): string {
        return '<div class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-white p-4 shadow-sm">'
            . '<span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full ' . $iconCls . '">' . icon($icon, 'h-7 w-7') . '</span>'
            . '<span class="min-w-0"><span class="block text-sm font-semibold ' . $labelCls . '">' . e($label) . '</span>'
            . '<span class="block break-words text-xl font-bold leading-tight sm:text-2xl ' . $valueCls . '">' . e($value) . ' <span class="text-sm font-semibold">' . e($unit) . '</span></span>'
            . '<span class="block text-sm text-stone-500">' . e($note) . '</span></span></div>';
    };
    return '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">'
        . $card('coins', 'bg-emerald-100 text-emerald-700', 'รายรับรวม', 'text-emerald-700', money($sum['income']), 'text-emerald-800', 'บาท', 'รายการทั้งหมด ' . number_format($sum['n_income']) . ' รายการ')
        . $card('doc', 'bg-red-100 text-red-700', 'รายจ่ายรวม', 'text-red-700', money($sum['expense']), 'text-red-700', 'บาท', 'รายการทั้งหมด ' . number_format($sum['n_expense']) . ' รายการ')
        . $card('scale', 'bg-sky-100 text-sky-700', 'ยอดคงเหลือ', 'text-sky-700', money($sum['balance']), 'text-sky-800', 'บาท', 'ณ วันที่ ' . balance_as_of($beYear))
        . $card('temple', 'bg-violet-100 text-violet-700', 'จำนวนวัดในระบบ', 'text-violet-700', number_format($temples), 'text-violet-800', 'วัด', $scopeText)
        . '</div>';
}

/** กราฟแท่งรายเดือน (รายรับ/รายจ่าย) เป็น SVG ไม่พึ่ง JavaScript */
function monthly_chart(array $series): string
{
    $short = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $max = 0.0;
    foreach ($series as $v) {
        $max = max($max, $v['inc'], $v['exp']);
    }
    if ($max <= 0) {
        return '<p class="py-10 text-center text-stone-500">ยังไม่มีรายการบัญชีในปีที่เลือก</p>';
    }
    // เลือกสเกลแกน Y ให้เป็นเลขกลม (1, 2, 2.5, 5 × 10^n) แบ่ง 4 ช่อง
    $raw = $max / 4;
    $pow = 10 ** floor(log10($raw));
    $step = $pow;
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        $step = $m * $pow;
        if ($step >= $raw) break;
    }
    $top = $step * 4;
    $fmt = function (float $v): string {
        return $v >= 1000000 ? rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M'
            : ($v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k' : (string)(int)$v);
    };

    $W = 1000; $H = 300; $l = 44; $r = 8; $tp = 12; $b = 30;
    $ph = $H - $tp - $b; $pw = $W - $l - $r; $gw = $pw / 12; $bw = $gw * 0.32;
    $y = fn(float $v) => $tp + $ph - ($v / $top) * $ph;

    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="h-auto w-full" role="img" aria-label="กราฟรายรับและรายจ่ายรายเดือน">';
    for ($i = 0; $i <= 4; $i++) {
        $v = $step * $i;
        $yy = round($y($v), 1);
        $svg .= '<line x1="' . $l . '" x2="' . ($W - $r) . '" y1="' . $yy . '" y2="' . $yy . '" class="stroke-stone-200"/>'
            . '<text x="' . ($l - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" class="fill-stone-500" font-size="11">' . $fmt($v) . '</text>';
    }
    foreach ($series as $m => $v) {
        $cx = $l + $gw * ($m - 1) + $gw / 2;
        foreach ([['inc', -1, 'fill-emerald-600', 'รายรับ'], ['exp', 0, 'fill-red-500', 'รายจ่าย']] as [$k, $off, $cls, $lbl]) {
            $h = ($v[$k] / $top) * $ph;
            if ($h > 0) {
                $x = $cx + ($off === -1 ? -$bw - 1 : 1);
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($tp + $ph - $h, 1) . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '" rx="2" class="' . $cls . '">'
                    . '<title>' . e($short[$m] . ' — ' . $lbl . ' ' . money($v[$k]) . ' บาท') . '</title></rect>';
            }
        }
        $svg .= '<text x="' . round($cx, 1) . '" y="' . ($H - 10) . '" text-anchor="middle" class="fill-stone-600" font-size="11">' . $short[$m] . '</text>';
    }
    return $svg . '</svg>';
}

/** แท่งแนวนอนสำหรับรายการหมวด */
function category_bars(array $rows, string $barCls): string
{
    if (!$rows) {
        return '<p class="text-sm text-stone-500">ยังไม่มีรายการ</p>';
    }
    $max = max(array_column($rows, 'total')) ?: 1;
    $h = '<ul class="grid gap-3">';
    foreach ($rows as $r) {
        $h .= '<li><div class="flex items-baseline justify-between gap-3 text-sm"><span>' . e($r['name']) . '</span>'
            . '<span class="whitespace-nowrap font-semibold">' . money($r['total']) . '</span></div>'
            . '<div class="mt-1 h-2.5 overflow-hidden rounded-full bg-stone-200"><i class="block h-full ' . $barCls . '" style="width:' . round($r['total'] / $max * 100) . '%"></i></div></li>';
    }
    return $h . '</ul>';
}
