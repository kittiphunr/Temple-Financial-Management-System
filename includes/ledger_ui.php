<?php
/** แผงบัญชีเงินสด/ธนาคารของวัด (การ์ดรายบัญชี + ยอดยกมา + เพิ่มบัญชีธนาคาร) ใช้ร่วมกันที่ accounts.php และหน้า "ข้อมูลวัด" (profile.php)
 *  ฟอร์มทั้งหมดส่งไป accounts.php; $back = 'profile' ให้กลับมาหน้าข้อมูลวัดหลังบันทึก */

function ledger_accounts_panel(PDO $pdo, array $temple, bool $canEdit, string $back = ''): string
{
    $tid = (int)$temple['id'];
    $accounts = ledger_accounts($pdo, $tid);
    $today = date('Y-m-d');
    ob_start();
    ?>
<div class="grid gap-3">
<?php foreach ($accounts as $a):
    $bal = ledger_balance_on($pdo, $a, $today);
    $isBank = $a['kind'] === 'bank';
    $bk = $isBank ? bank_of_account($a) : null; ?>
  <section class="card <?= $a['is_active'] ? '' : 'opacity-70' ?>">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h3 class="!my-0"><?= $isBank ? 'บัญชีธนาคาร' : 'เงินสด / เช็ค' ?><?= $a['is_active'] ? '' : ' <span class="badge">ปิดใช้งาน</span>' ?></h3>
        <?php if ($isBank): ?><div class="text-sm text-stone-600">ชื่อบัญชี <?= e($a['name']) ?> · <?= bank_chip($a) ?> สาขา<?= e($a['branch'] ?: '-') ?> · เลขที่ <span class="font-mono"><?= e($a['account_no']) ?></span></div><?php endif; ?>
      </div>
      <div class="text-right"><div class="text-xs text-stone-500">ยอดคงเหลือวันนี้</div><div class="text-xl font-bold <?= $bal < 0 ? 'text-red-700' : 'text-emerald-700' ?>"><?= money($bal) ?> บาท</div></div>
    </div>
    <?php if ($isBank && reconcile_available()): ?><div class="mt-2"><a class="btn btn-sm btn-outline" href="<?= BASE ?>reconcile.php?temple=<?= $tid ?>&account=<?= (int)$a['id'] ?>">กระทบยอดกับ statement</a></div><?php endif; ?>
    <?php if ($isBank && $canEdit): ?><div class="mt-2"><a class="btn btn-sm btn-outline" href="<?= BASE ?>bank_import.php?temple=<?= $tid ?>&account=<?= (int)$a['id'] ?>">นำเข้ารายการจากไฟล์ธนาคาร (CSV/Excel)</a></div><?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= BASE ?>accounts.php" class="mt-3 flex flex-wrap items-end gap-3 border-t border-stone-100 pt-3">
      <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="set_opening"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
      <div><label>ยอดยกมา (บาท)</label><input name="opening_balance" inputmode="decimal" value="<?= e(number_format((float)$a['opening_balance'], 2, '.', '')) ?>" class="w-40"></div>
      <div><label>ณ วันเริ่มบัญชี (พ.ศ.)</label><?= date_be_field('opening_date', $a['opening_date'] ?: (date('Y') . '-01-01'), 15, 1) ?></div>
      <button class="btn btn-sm">บันทึกยอดยกมา</button>
      <?php if (!$a['opening_date']): ?><span class="text-sm text-amber-800">ยังไม่ได้ตั้งยอดยกมา — ยอดคงเหลือคิดจากรายการทั้งหมดโดยเริ่มที่ 0</span><?php endif; ?>
    </form>
    <?php if ($isBank): ?>
    <details class="mt-2"><summary class="cursor-pointer text-sm text-brand-700">แก้ไขข้อมูลบัญชี / ปิดใช้งาน</summary>
      <form method="post" action="<?= BASE ?>accounts.php" class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 [&_input:not([type=radio])]:w-full">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="edit_bank"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
        <div><label>ชื่อบัญชี</label><input name="name" value="<?= e($a['name']) ?>" maxlength="150"></div>
        <div class="sm:col-span-2"><label>ธนาคาร</label><?= bank_picker((string)($bk['code'] ?? ''), $bk ? '' : (string)$a['bank_name']) ?></div>
        <div><label>สาขา</label><input name="branch" value="<?= e($a['branch']) ?>" maxlength="100"></div>
        <div><label>เลขที่บัญชี</label><input name="account_no" value="<?= e($a['account_no']) ?>" maxlength="30" required></div>
        <div class="col-span-full"><button class="btn btn-sm">บันทึก</button></div>
      </form>
      <form method="post" action="<?= BASE ?>accounts.php" class="mt-2" data-confirm="<?= $a['is_active'] ? 'ปิดใช้งานบัญชีนี้? (ยังแสดงในรายงานย้อนหลัง แต่เลือกบันทึกรายการใหม่ไม่ได้)' : 'เปิดใช้งานบัญชีนี้อีกครั้ง?' ?>">
        <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
        <button class="btn btn-sm btn-outline"><?= $a['is_active'] ? 'ปิดใช้งานบัญชีนี้' : 'เปิดใช้งานบัญชีนี้' ?></button></form>
    </details>
    <?php endif; ?>
    <?php if ($isBank && bank_book_available()): ?>
    <div class="mt-3 flex flex-wrap items-start gap-3 border-t border-stone-100 pt-3">
      <?php if (!empty($a['bookbank_media_id'])): ?>
        <a href="<?= e(media_url((int)$a['bookbank_media_id'])) ?>" target="_blank" rel="noopener" title="เปิดภาพหน้าบุ๊คแบงก์"><img src="<?= e(media_url((int)$a['bookbank_media_id'])) ?>" alt="ภาพหน้าบุ๊คแบงก์ <?= e($a['account_no']) ?>" loading="lazy" class="h-24 w-auto max-w-[9rem] rounded-xl border border-stone-200 object-cover"></a>
      <?php else: ?><div class="flex h-24 w-32 items-center justify-center rounded-xl border border-dashed border-stone-300 text-center text-xs text-stone-400"><?= icon('image') ?>&nbsp;ยังไม่มีภาพ<br>หน้าบุ๊คแบงก์</div><?php endif; ?>
      <?php if ($canEdit): ?>
      <div class="flex min-w-0 flex-1 flex-col gap-2">
        <form method="post" action="<?= BASE ?>accounts.php" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
          <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="bookbank_upload"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
          <div><label>ภาพหน้าบุ๊คแบงก์ (JPEG/PNG/WebP ไม่เกิน 2 MB)</label><input type="file" name="bookbank" accept="image/jpeg,image/png,image/webp" required></div>
          <button class="btn btn-sm btn-outline"><?= icon('upload') ?> <?= !empty($a['bookbank_media_id']) ? 'เปลี่ยนภาพ' : 'แนบภาพ' ?></button>
        </form>
        <?php if (!empty($a['bookbank_media_id'])): ?>
        <form method="post" action="<?= BASE ?>accounts.php" data-confirm="ลบภาพหน้าบุ๊คแบงก์นี้?"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="bookbank_remove"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="temple" value="<?= $tid ?>">
          <button class="btn btn-sm btn-outline"><?= icon('trash-2') ?> ลบภาพ</button></form>
        <?php endif; ?>
        <p class="text-xs text-stone-500">ภาพมีเลขบัญชีและชื่อเจ้าของบัญชี เปิดดูได้เฉพาะแอดมินวัด/อำเภอ/หลักที่แก้ไขบัญชีนี้ได้ (ไม่แสดงต่อเจ้าคณะจังหวัด/ตำบล)</p>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php elseif ($a['opening_date']): ?><div class="mt-2 text-sm text-stone-600">ยอดยกมา <?= money($a['opening_balance']) ?> บาท ณ <?= e(th_date($a['opening_date'])) ?></div><?php endif; ?>
  </section>
<?php endforeach; ?>
</div>

<?php if ($canEdit): ?>
<section class="card">
  <h3>เพิ่มบัญชีธนาคาร</h3>
  <p class="mb-3 text-sm text-stone-600">วัดมีบัญชีเงินฝากได้หลายบัญชี (ไม่จำกัดจำนวน) — เพิ่มทีละบัญชีได้ที่นี่ ปัจจุบันมี <?= count(array_filter($accounts, fn($x) => $x['kind'] === 'bank')) ?> บัญชีธนาคาร</p>
  <form method="post" action="<?= BASE ?>accounts.php" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 [&_input:not([type=radio])]:w-full">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="action" value="add_bank"><input type="hidden" name="temple" value="<?= $tid ?>">
    <div class="sm:col-span-2"><label>ธนาคาร *</label><?= bank_picker() ?></div>
    <div><label>สาขา</label><input name="branch" maxlength="100"></div>
    <div><label>เลขที่บัญชี *</label><input name="account_no" required maxlength="30" inputmode="numeric" placeholder="000-0-00000-0"></div>
    <div><label>ชื่อบัญชี</label><input name="name" maxlength="150" value="<?= e($temple['name']) ?>"></div>
    <div><label>ยอดยกมา (บาท)</label><input name="opening_balance" inputmode="decimal" value="0"></div>
    <div><label>ณ วันเริ่มบัญชี (พ.ศ.)</label><?= date_be_field('opening_date', date('Y') . '-01-01', 15, 1) ?></div>
    <?php if (bank_book_available()): ?><div class="sm:col-span-2"><label>ภาพหน้าบุ๊คแบงก์ (ไม่บังคับ)</label><input type="file" name="bookbank" accept="image/jpeg,image/png,image/webp"></div><?php endif; ?>
    <div class="flex items-end"><button class="btn">เพิ่มบัญชี</button></div>
  </form>
</section>
<?php endif; ?>
    <?php
    return ob_get_clean();
}
