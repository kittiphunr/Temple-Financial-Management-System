<?php
/** ส่วนประกอบหน้าแดชบอร์ด: การ์ดสถิติ (ไอคอน Feather จาก includes/icons.php) 4 ใบ (ตามแบบเดโม่), กราฟรายเดือน (SVG) */

/**
 * การ์ดสถิติ 4 ใบ: รายรับรวม / รายจ่ายรวม / ยอดคงเหลือ / จำนวนวัด
 * @param string $scopeText เช่น "ในสังกัดอำเภอเมืองแพร่"
 */
function stat_cards(array $sum, int $beYear, int $temples, string $scopeText): string
{
    $card = function (string $icon, string $iconCls, string $label, string $labelCls, string $value, string $valueCls, string $unit, string $note): string {
        return '<div class="card flex items-center gap-3">'
            . '<span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full ' . $iconCls . '">' . icon($icon, 'h-7 w-7') . '</span>'
            . '<span class="min-w-0"><span class="block text-sm font-semibold ' . $labelCls . '">' . e($label) . '</span>'
            . '<span class="block break-words text-xl font-bold leading-tight sm:text-2xl ' . $valueCls . '">' . e($value) . ' <span class="text-sm font-semibold">' . e($unit) . '</span></span>'
            . '<span class="block text-sm text-stone-500">' . e($note) . '</span></span></div>';
    };
    return '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">'
        . $card('trending-up', 'bg-emerald-100 text-emerald-700', 'รายรับรวม', 'text-emerald-700', money($sum['income']), 'text-emerald-800', 'บาท', 'รายการทั้งหมด ' . number_format($sum['n_income']) . ' รายการ')
        . $card('trending-down', 'bg-red-100 text-red-700', 'รายจ่ายรวม', 'text-red-700', money($sum['expense']), 'text-red-700', 'บาท', 'รายการทั้งหมด ' . number_format($sum['n_expense']) . ' รายการ')
        . $card('briefcase', 'bg-sky-100 text-sky-700', 'ยอดคงเหลือ', 'text-sky-700', money($sum['balance']), 'text-sky-800', 'บาท', 'ณ วันที่ ' . balance_as_of($beYear))
        . $card('home', 'bg-violet-100 text-violet-700', 'จำนวนวัดในระบบ', 'text-violet-700', number_format($temples), 'text-violet-800', 'วัด', $scopeText)
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
function category_bars(array $rows, string $barCls, array $dots = []): string
{
    if (!$rows) {
        return '<p class="text-sm text-stone-500">ยังไม่มีรายการ</p>';
    }
    $max = max(array_column($rows, 'total')) ?: 1;
    $h = '<ul class="grid gap-3">';
    foreach (array_values($rows) as $i => $r) {
        // $dots = สีของแต่ละชิ้นในกราฟโดนัท: จุดสีหน้าชื่อหมวดช่วยเทียบกับกราฟ
        $dot = isset($dots[$i]) ? '<i class="mr-2 inline-block h-2.5 w-2.5 rounded-full align-baseline" style="background:' . e($dots[$i]) . '"></i>' : '';
        $h .= '<li><div class="flex items-baseline justify-between gap-3 text-sm"><span>' . $dot . e($r['name']) . '</span>'
            . '<span class="whitespace-nowrap font-semibold">' . money($r['total']) . '</span></div>'
            . '<div class="mt-1 h-2.5 overflow-hidden rounded-full bg-stone-200"><i class="block h-full ' . $barCls . '" style="width:' . round($r['total'] / $max * 100) . '%"></i></div></li>';
    }
    return $h . '</ul>';
}

/** สีของกราฟ (ตรงกับกราฟ SVG เดิมและแถบหมวดบนแดชบอร์ด) */
const CHART_INCOME = '#059669';
const CHART_EXPENSE = '#ef4444';
const CHART_INCOME_SHADES = ['#047857', '#059669', '#10b981', '#34d399', '#6ee7b7', '#a7f3d0'];
const CHART_EXPENSE_SHADES = ['#b91c1c', '#dc2626', '#ef4444', '#f87171', '#fca5a5', '#fecaca'];

/** แท็กสคริปต์ Chart.js (ใส่ใน $extraScripts เฉพาะหน้าที่มีกราฟ เพื่อไม่ให้หน้าอื่นโหลดไฟล์ใหญ่โดยไม่จำเป็น) */
function chart_scripts(): string
{
    return '<script src="' . BASE . 'assets/vendor/chart.umd.min.js"></script><script src="' . BASE . 'assets/js/charts.js"></script>';
}

/**
 * กล่องกราฟ Chart.js แบบ progressive enhancement — ไม่มี JavaScript จะเห็น $fallbackHtml แทน (ดู assets/js/charts.js)
 * @param array $config type (bar|doughnut), labels, datasets[{label,data,color|colors}], unit, legend
 */
function chart_box(array $config, string $fallbackHtml, string $label, string $height = '18rem'): string
{
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<div class="chart-box" data-chart="' . e($json) . '" data-chart-label="' . e($label) . '" data-chart-height="' . e($height) . '">'
        . '<div data-chart-fallback>' . $fallbackHtml . '</div></div>';
}

/** กราฟแท่งรายเดือน (Chart.js) ใช้กราฟ SVG เดิมเป็นกราฟสำรอง · $series[เดือน 1–12] = ['inc'=>, 'exp'=>] */
function monthly_chart_js(array $series): string
{
    $svg = monthly_chart($series);
    if (!str_starts_with($svg, '<svg')) {
        return $svg;   // ยังไม่มีรายการ: ข้อความเดิม ไม่ต้องสร้างกราฟ
    }
    $short = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $inc = $exp = [];
    for ($m = 1; $m <= 12; $m++) {
        $inc[] = round((float)($series[$m]['inc'] ?? 0), 2);
        $exp[] = round((float)($series[$m]['exp'] ?? 0), 2);
    }
    return chart_box(['type' => 'bar', 'labels' => array_slice($short, 1), 'unit' => 'บาท', 'legend' => false,
        'datasets' => [['label' => 'รายรับ', 'data' => $inc, 'color' => CHART_INCOME], ['label' => 'รายจ่าย', 'data' => $exp, 'color' => CHART_EXPENSE]]],
        $svg, 'กราฟแท่งรายรับและรายจ่ายรายเดือน', '18rem');
}

/** กราฟโดนัทสัดส่วนหมวด (Chart.js) · ไม่มีรายการ/ไม่มี JS ไม่แสดงอะไร (รายการข้างล่างยังครบ) @param array $rows [{name,total}] */
function category_doughnut(array $rows, array $shades, string $label): string
{
    if (!$rows) {
        return '';
    }
    return '<div class="mb-4">' . chart_box(['type' => 'doughnut', 'labels' => array_column($rows, 'name'), 'unit' => 'บาท', 'legend' => false,
        'datasets' => [['data' => array_map(fn($r) => round((float)$r['total'], 2), $rows), 'colors' => array_slice($shades, 0, count($rows))]]], '', $label, '13rem') . '</div>';
}
