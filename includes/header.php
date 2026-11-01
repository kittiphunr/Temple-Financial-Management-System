<?php
/** @var string $pageTitle */
$u = user();
$cur = basename($_SERVER['SCRIPT_NAME']);
$type = $_GET['type'] ?? '';
$inAdmin = basename(dirname($_SERVER['SCRIPT_FILENAME'])) === 'admin';

/** ลิงก์เดี่ยวบนแถบเมนู: ['href'=>, 'label'=>, 'icon'=>, 'active'=>bool] */
function nav_link(string $href, string $label, string $icon, bool $active): array
{
    return ['href' => $href, 'label' => $label, 'icon' => $icon, 'active' => $active];
}

/** กลุ่มเมนู (dropdown): ['label'=>, 'icon'=>, 'children'=>[nav_link...]] — ไม่มีรายการลูกให้ใช้เลย */
function nav_group(string $label, string $icon, array $children): ?array
{
    $children = array_values(array_filter($children));
    return $children ? ['label' => $label, 'icon' => $icon, 'children' => $children, 'active' => (bool)array_filter($children, fn($c) => $c['active'])] : null;
}

/** เรนเดอร์เมนู: $mobile=false = แถบแนวนอน (กลุ่มเป็น dropdown) · true = รายการแนวตั้งแบ่งหัวกลุ่ม */
function nav_render(array $menu, bool $mobile): string
{
    $link = function (array $it, bool $inPanel) use ($mobile): string {
        $cls = $it['active'] ? 'bg-brand-600 text-white' : 'text-stone-700 hover:bg-gold-100';
        $compact = !$mobile && !$inPanel && !empty($it['compact']);   // แถบแนวนอน: แสดงแต่ไอคอนจนกว่าจอจะกว้างมาก
        return '<a class="flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 font-semibold no-underline ' . $cls . '" href="' . e(BASE . $it['href']) . '"' . ($it['active'] ? ' aria-current="page"' : '') . ($compact ? ' title="' . e($it['label']) . '"' : '') . '>'
            . icon($it['icon']) . '<span' . ($compact ? ' class="sr-only 2xl:not-sr-only"' : '') . '>' . e($it['label']) . '</span></a>';
    };
    $h = '';
    foreach ($menu as $m) {
        if (isset($m['children'])) {
            $kids = implode('', array_map(fn($c) => $link($c, true), $m['children']));
            if ($mobile) {
                $h .= '<div class="mt-1 border-t border-stone-100 pt-1"><div class="flex items-center gap-2 px-3 py-1 text-xs font-bold uppercase tracking-wide text-stone-500">' . icon($m['icon']) . e($m['label']) . '</div>' . $kids . '</div>';
            } else {
                $cls = $m['active'] ? 'bg-brand-600 text-white' : 'text-stone-700 hover:bg-gold-100';
                $h .= '<details class="nav-group relative"><summary class="flex cursor-pointer list-none items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-2 font-semibold ' . $cls . '">'
                    . icon($m['icon']) . '<span>' . e($m['label']) . '</span>' . icon('chevron-down', 'opacity-70') . '</summary>'
                    . '<div class="absolute left-0 top-full z-20 mt-1 flex w-64 flex-col gap-0.5 rounded-xl border border-stone-200 bg-white p-1.5 shadow-lg">' . $kids . '</div></details>';
            }
        } else {
            $h .= $link($m, false);
        }
    }
    return $h;
}

$forced = !empty($u['must_change']);
$role = $u['role'] ?? '';
$canWrite = in_array($role, ['admin', 'district', 'temple'], true);   // เจ้าคณะจังหวัด/ตำบลดูข้อมูลได้อย่างเดียว
$menu = [];
if (!$u) {
    // ผู้เยี่ยมชม: หน้าแรก + ข้อมูลวัด (ผู้ล็อกอินกดโลโก้เพื่อกลับหน้าแรก)
    $menu[] = nav_link('index.php', 'หน้าแรก', 'home', $cur === 'index.php' && !$inAdmin);
    $menu[] = nav_link('temples.php', 'ข้อมูลวัด', 'map-pin', $cur === 'temples.php');
} elseif (!$forced) {
    $menu[] = nav_link('dashboard.php', 'แดชบอร์ด', 'grid', $cur === 'dashboard.php');
    if ($canWrite) {
        $menu[] = nav_group('บันทึกบัญชี', 'edit-3', [
            nav_link('quick.php', 'บันทึกด่วน', 'zap', $cur === 'quick.php'),
            recurring_available() ? nav_link('recurring.php', 'รายการประจำ', 'repeat', $cur === 'recurring.php') : null,
            nav_link('transactions.php?type=income', 'บัญชีรายรับ', 'trending-up', $cur === 'transactions.php' && $type === 'income'),
            nav_link('transactions.php?type=expense', 'บัญชีรายจ่าย', 'trending-down', $cur === 'transactions.php' && $type === 'expense'),
            nav_link('receipts.php', 'อนุโมทนาบัตร', 'award', in_array($cur, ['receipts.php', 'receipt.php'], true)),
            donor_available() ? nav_link('donors.php', 'ผู้บริจาค', 'heart', $cur === 'donors.php') : null,
        ]);
    }
    $menu[] = nav_group('การเงิน', 'credit-card', [
        nav_link('accounts.php', 'เงินสด / ธนาคาร', 'credit-card', $cur === 'accounts.php'),
        $canWrite ? nav_link('bank_import.php', 'นำเข้าจากไฟล์ธนาคาร', 'upload', $cur === 'bank_import.php') : null,
        reconcile_available() ? nav_link('reconcile.php', 'กระทบยอดธนาคาร', 'check-square', $cur === 'reconcile.php') : null,
        budget_available() ? nav_link('budget.php', 'งบประมาณ', 'target', $cur === 'budget.php') : null,
        fund_available() ? nav_link('funds.php', 'กองทุน/โครงการ', 'layers', $cur === 'funds.php') : null,
    ]);
    $menu[] = nav_group('รายงาน', 'bar-chart-2', [
        nav_link('reports.php', 'รายงานภาพรวม', 'pie-chart', $cur === 'reports.php'),
        nav_link('search.php', 'ค้นหารายการ', 'search', $cur === 'search.php'),
        nav_link('annual.php', 'งบรายรับ-รายจ่ายประจำปี', 'file-text', $cur === 'annual.php'),
        nav_link('statements.php', 'รายงานแบบที่ 1–3', 'file', $cur === 'statements.php'),
        ($role !== 'temple' && report_available()) ? nav_link('report_status.php', 'ติดตามรายงาน', 'clipboard', $cur === 'report_status.php') : null,
    ]);
    $menu[] = nav_group('ทะเบียน', 'book-open', [
        $role === 'temple' ? nav_link('profile.php', 'ข้อมูลวัดของฉัน', 'home', $cur === 'profile.php') : nav_link('temples.php', 'ข้อมูลวัด', 'map-pin', $cur === 'temples.php'),
        nav_link('people.php', 'บุคลากร', 'users', in_array($cur, ['people.php', 'person.php'], true)),
        asset_available() ? nav_link('assets.php', 'ทรัพย์สิน', 'package', $cur === 'assets.php') : null,
    ]);
    if ($role === 'admin') {
        $menu[] = nav_link('admin/index.php', 'จัดการระบบ', 'settings', $inAdmin);
    }
}
$menu = array_values(array_filter($menu));
// เมนูผู้ใช้ (ชิดขวา): บัญชีของฉัน + ออกจากระบบ
$userMenu = $u
    ? [nav_link('account.php', $forced ? 'เปลี่ยนรหัสผ่าน' : 'บัญชีของฉัน', 'user', $cur === 'account.php') + ['compact' => true]]
    : [];
$authLabel = $u ? 'ออกจากระบบ' : 'เข้าสู่ระบบ';
$authHref = BASE . ($u ? 'logout.php' : 'login.php');
$authIcon = icon($u ? 'log-out' : 'log-in');
$authBtn = '<a class="btn btn-outline btn-sm ml-1 inline-flex items-center gap-1.5 whitespace-nowrap" href="' . $authHref . '">' . $authIcon . $authLabel . '</a>';
$authBtnBar = '<a class="btn btn-outline btn-sm ml-1 inline-flex items-center gap-1.5 whitespace-nowrap" href="' . $authHref . '" title="' . $authLabel . '">' . $authIcon . '<span class="sr-only 2xl:not-sr-only">' . $authLabel . '</span></a>';
$navDesktop = nav_render(array_merge($menu, $userMenu), false) . $authBtnBar;
$navMobile = nav_render(array_merge($menu, $userMenu), true) . '<div class="mt-1 border-t border-stone-100 pt-2">' . $authBtn . '</div>';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? '') ? $pageTitle . ' | ' : '') . e($CONFIG['app_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="<?= BASE ?>assets/img/logo.svg">
<?= $extraHead ?? '' ?>
<link rel="stylesheet" href="<?= BASE ?>assets/css/tailwind.css">
</head>
<body class="min-h-screen">
<header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 backdrop-blur print:hidden">
  <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-2.5">
    <a class="flex items-center gap-3 no-underline" href="<?= BASE ?>index.php">
      <img class="h-12 w-12 shrink-0" src="<?= BASE ?>assets/img/logo.svg" alt="ตราสัญลักษณ์">
      <span class="leading-tight">
        <strong class="block whitespace-nowrap text-lg text-brand-600 sm:text-xl"><?= e($CONFIG['app_name']) ?></strong>
        <small class="text-sm text-stone-700"><?= e(app_subtitle()) ?></small>
      </span>
    </a>
    <nav class="hidden items-center justify-end gap-0.5 xl:flex" aria-label="เมนูหลัก"><?= $navDesktop ?></nav>
    <details class="nav-mobile relative xl:hidden">
      <summary class="btn btn-outline btn-sm flex list-none items-center gap-1.5"><?= icon('menu') ?>เมนู</summary>
      <nav class="absolute right-0 z-20 mt-2 flex max-h-[80vh] w-72 flex-col gap-0.5 overflow-y-auto rounded-xl border border-stone-200 bg-white p-2 shadow-lg" aria-label="เมนูหลัก"><?= $navMobile ?></nav>
    </details>
  </div>
</header>
<main class="mx-auto max-w-6xl px-4 pb-10 pt-5">
<?php if ($f = flash()): ?>
  <?= alert_box($f[0], $f[1] === 'err' ? 'err' : 'ok') ?>
<?php endif; ?>
<?php
// แจ้งเตือนแอดมินวัด: ต้องใส่โลโก้วัดเพื่อใช้ในเอกสาร (แสดงทุกหน้าจนกว่าจะใส่) และควรกรอกข้อมูลเจ้าอาวาส
if ($u && $role === 'temple' && !$forced && !in_array($cur, ['profile.php', 'account.php', 'accounts.php'], true)):
    $chk = temple_checklist(db(), (int)$u['temple_id']);
    if (!$chk['logo']): ?>
  <div class="callout flex flex-wrap items-center justify-between gap-2 border-amber-300 bg-amber-50 text-amber-950 print:hidden" role="alert">
    <span><strong>ยังไม่ได้ใส่โลโก้วัด</strong> — แอดมินวัดต้องใส่โลโก้เพื่อใช้เป็นหัวเอกสารที่ออกจากระบบ</span>
    <a class="btn btn-sm" href="<?= BASE ?>profile.php#logo">ใส่โลโก้วัดตอนนี้ →</a>
  </div>
    <?php endif;
    if (!$chk['abbot'] && !in_array($cur, ['people.php', 'person.php'], true)): ?>
  <div class="callout flex flex-wrap items-center justify-between gap-2 border-sky-200 bg-sky-50 text-sky-950 print:hidden">
    <span>ยังไม่ได้กรอกข้อมูลเจ้าอาวาสและบุคลากรของวัด</span>
    <a class="btn btn-outline btn-sm" href="<?= BASE ?>people.php">กรอกข้อมูลบุคลากร →</a>
  </div>
    <?php endif;
    if (!$chk['cash_opening']): ?>
  <div class="callout flex flex-wrap items-center justify-between gap-2 border-sky-200 bg-sky-50 text-sky-950 print:hidden">
    <span>ยังไม่ได้ตั้ง <strong>ยอดยกมาเงินสด</strong> และรายละเอียดบัญชีธนาคารของวัด — ใช้ออกรายงานแบบที่ 1–3 ประจำเดือน</span>
    <a class="btn btn-outline btn-sm" href="<?= BASE ?>profile.php#accounts">กรอกบัญชีของวัด →</a>
  </div>
    <?php endif;
    if ($cur === 'dashboard.php' && ($dueN = recurring_due_count(db(), (int)$u['temple_id'])) > 0): ?>
  <div class="callout flex flex-wrap items-center justify-between gap-2 border-sky-200 bg-sky-50 text-sky-950 print:hidden">
    <span>มี <strong>รายการประจำ <?= $dueN ?> รายการ</strong> ถึงกำหนดรอยืนยันบันทึก</span>
    <a class="btn btn-outline btn-sm" href="<?= BASE ?>recurring.php">ตรวจและบันทึก →</a>
  </div>
    <?php endif;
endif; ?>
