<?php
/** @var string $pageTitle */
$u = user();
$cur = basename($_SERVER['SCRIPT_NAME']);
$type = $_GET['type'] ?? '';
function nav_item(string $href, string $label, bool $active): string
{
    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(BASE . $href) . '">' . e($label) . '</a>';
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? '') ? $pageTitle . ' | ' : '') . e($CONFIG['app_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE ?>assets/css/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= BASE ?>index.php">
    <img class="logo" src="<?= BASE ?>assets/img/logo.svg" alt="ตราสัญลักษณ์">
    <span><strong><?= e($CONFIG['app_name']) ?></strong><small><?= e($CONFIG['app_sub']) ?></small></span>
  </a>
  <nav class="nav">
    <?= nav_item('index.php', 'หน้าแรก', $cur === 'index.php') ?>
    <?php if ($u): ?>
      <?= nav_item('transactions.php?type=income', 'บัญชีรายรับ', $cur === 'transactions.php' && $type === 'income') ?>
      <?= nav_item('transactions.php?type=expense', 'บัญชีรายจ่าย', $cur === 'transactions.php' && $type === 'expense') ?>
      <?= nav_item('reports.php', 'รายงาน', $cur === 'reports.php') ?>
    <?php endif; ?>
    <?= nav_item('temples.php', 'ข้อมูลวัด', $cur === 'temples.php') ?>
    <?php if ($u && $u['role'] === 'admin'): ?>
      <?= nav_item('admin/index.php', 'จัดการระบบ', basename(dirname($_SERVER['SCRIPT_FILENAME'])) === 'admin') ?>
    <?php endif; ?>
    <?php if ($u): ?>
      <a class="btn btn-outline" href="<?= BASE ?>logout.php">ออกจากระบบ (<?= e($u['username']) ?>)</a>
    <?php else: ?>
      <a class="btn btn-outline" href="<?= BASE ?>login.php">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container">
<?php if ($f = flash()): ?>
  <div class="alert alert-<?= e($f[1]) ?>"><?= e($f[0]) ?></div>
<?php endif; ?>
