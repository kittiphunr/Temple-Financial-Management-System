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
    return '<a class="whitespace-nowrap rounded-lg px-3 py-2 font-semibold no-underline ' . $cls . '" href="' . e(BASE . $href) . '">' . e($label) . '</a>';
}

$forced = !empty($u['must_change']);
$items = $forced ? '' : nav_item('index.php', 'หน้าแรก', $cur === 'index.php' && !$inAdmin);
if ($u && !$forced) {
    $items .= nav_item('dashboard.php', 'แดชบอร์ด', $cur === 'dashboard.php');
    $items .= nav_item('transactions.php?type=income', 'บัญชีรายรับ', $cur === 'transactions.php' && $type === 'income');
    $items .= nav_item('transactions.php?type=expense', 'บัญชีรายจ่าย', $cur === 'transactions.php' && $type === 'expense');
    $items .= nav_item('reports.php', 'รายงาน', $cur === 'reports.php');
}
if (!$forced) {
    $items .= nav_item('temples.php', 'ข้อมูลวัด', $cur === 'temples.php');
}
if ($u && !$forced && $u['role'] === 'admin') {
    $items .= nav_item('admin/index.php', 'จัดการระบบ', $inAdmin);
}
if ($u) {
    $items .= nav_item('account.php', $forced ? 'เปลี่ยนรหัสผ่าน' : 'บัญชีของฉัน', $cur === 'account.php');
}
$items .= $u
    ? '<a class="btn btn-outline btn-sm ml-1" href="' . BASE . 'logout.php">ออกจากระบบ</a>'
    : '<a class="btn btn-outline btn-sm ml-1" href="' . BASE . 'login.php">เข้าสู่ระบบ</a>';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? '') ? $pageTitle . ' | ' : '') . e($CONFIG['app_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
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
  <div class="mb-4 rounded-lg px-4 py-2.5 <?= $f[1] === 'err' ? 'bg-red-50 text-red-900' : 'bg-emerald-50 text-emerald-900' ?>"><?= e($f[0]) ?></div>
<?php endif; ?>
