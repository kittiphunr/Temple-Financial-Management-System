<?php
/** @var string $pageTitle */
$u = user();
$cur = basename($_SERVER['SCRIPT_NAME']);
$type = $_GET['type'] ?? '';
$inAdmin = basename(dirname($_SERVER['SCRIPT_FILENAME'])) === 'admin';

function nav_item(string $href, string $label, bool $active): string
{
    $cls = $active
        ? 'bg-brand-600 text-white'
        : 'text-stone-700 hover:bg-gold-100';
    return '<a class="whitespace-nowrap rounded-lg px-2 py-2 font-semibold no-underline 2xl:px-3 ' . $cls . '" href="' . e(BASE . $href) . '">' . e($label) . '</a>';
}

$forced = !empty($u['must_change']);
$role = $u['role'] ?? '';
$items = '';
if (!$u) {
    // ผู้เยี่ยมชม: หน้าแรก + ข้อมูลวัด (ผู้ล็อกอินกดโลโก้เพื่อกลับหน้าแรก)
    $items .= nav_item('index.php', 'หน้าแรก', $cur === 'index.php' && !$inAdmin);
    $items .= nav_item('temples.php', 'ข้อมูลวัด', $cur === 'temples.php');
} elseif (!$forced) {
    $items .= nav_item('dashboard.php', 'แดชบอร์ด', $cur === 'dashboard.php');
    if (in_array($role, ['admin', 'district', 'temple'], true)) {   // เจ้าคณะจังหวัดดูข้อมูลได้อย่างเดียว ไม่มีเมนูบันทึกบัญชี
        $items .= nav_item('transactions.php?type=income', 'บัญชีรายรับ', $cur === 'transactions.php' && $type === 'income');
        $items .= nav_item('transactions.php?type=expense', 'บัญชีรายจ่าย', $cur === 'transactions.php' && $type === 'expense');
    }
    $items .= nav_item('reports.php', 'รายงาน', in_array($cur, ['reports.php', 'statements.php', 'accounts.php'], true));
    if ($role !== 'province') {
        $items .= nav_item('receipts.php', 'อนุโมทนาบัตร', in_array($cur, ['receipts.php', 'receipt.php'], true));
    }
    $items .= $role === 'temple'
        ? nav_item('profile.php', 'ข้อมูลวัดของฉัน', $cur === 'profile.php')
        : nav_item('temples.php', 'ข้อมูลวัด', $cur === 'temples.php');
    $items .= nav_item('people.php', 'บุคลากร', in_array($cur, ['people.php', 'person.php'], true));
    if ($role === 'admin') {
        $items .= nav_item('admin/index.php', 'จัดการระบบ', $inAdmin);
    }
}
if ($u) {
    $items .= nav_item('account.php', $forced ? 'เปลี่ยนรหัสผ่าน' : 'บัญชีของฉัน', $cur === 'account.php');
}
$items .= $u
    ? '<a class="btn btn-outline btn-sm ml-1 whitespace-nowrap" href="' . BASE . 'logout.php">ออกจากระบบ</a>'
    : '<a class="btn btn-outline btn-sm ml-1 whitespace-nowrap" href="' . BASE . 'login.php">เข้าสู่ระบบ</a>';
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
<link rel="stylesheet" href="<?= BASE ?>assets/css/tailwind.css">
</head>
<body class="min-h-screen">
<header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 backdrop-blur print:hidden">
  <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-2.5">
    <a class="flex items-center gap-3 no-underline" href="<?= BASE ?>index.php">
      <img class="h-12 w-12 shrink-0" src="<?= BASE ?>assets/img/logo.svg" alt="ตราสัญลักษณ์">
      <span class="leading-tight">
        <strong class="block whitespace-nowrap text-lg text-brand-600 sm:text-xl"><?= e($CONFIG['app_name']) ?></strong>
        <small class="text-sm text-stone-700"><?= e($CONFIG['app_sub']) ?></small>
      </span>
    </a>
    <nav class="hidden items-center justify-end gap-1 xl:flex"><?= $items ?></nav>
    <details class="relative xl:hidden">
      <summary class="btn btn-outline btn-sm list-none">เมนู ☰</summary>
      <nav class="absolute right-0 mt-2 flex w-60 flex-col gap-1 rounded-xl border border-stone-200 bg-white p-2 shadow-lg"><?= $items ?></nav>
    </details>
  </div>
</header>
<main class="mx-auto max-w-6xl px-4 pb-10 pt-5">
<?php if ($f = flash()): ?>
  <?= alert_box($f[0], $f[1] === 'err' ? 'err' : 'ok') ?>
<?php endif; ?>
<?php
// แจ้งเตือนแอดมินวัด: ต้องใส่โลโก้วัดเพื่อใช้ในเอกสาร (แสดงทุกหน้าจนกว่าจะใส่) และควรกรอกข้อมูลเจ้าอาวาส
if ($u && $role === 'temple' && !$forced && !in_array($cur, ['profile.php', 'account.php'], true)):
    $chk = temple_checklist(db(), (int)$u['temple_id']);
    if (!$chk['logo']): ?>
  <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-950 print:hidden" role="alert">
    <span><strong>ยังไม่ได้ใส่โลโก้วัด</strong> — แอดมินวัดต้องใส่โลโก้เพื่อใช้เป็นหัวเอกสารที่ออกจากระบบ</span>
    <a class="btn btn-sm" href="<?= BASE ?>profile.php#logo">ใส่โลโก้วัดตอนนี้ →</a>
  </div>
    <?php endif;
    if (!$chk['abbot'] && !in_array($cur, ['people.php', 'person.php'], true)): ?>
  <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sky-950 print:hidden">
    <span>ยังไม่ได้กรอกข้อมูลเจ้าอาวาสและบุคลากรของวัด</span>
    <a class="btn btn-outline btn-sm" href="<?= BASE ?>people.php">กรอกข้อมูลบุคลากร →</a>
  </div>
    <?php endif;
endif; ?>
