</main>
<footer class="border-t border-stone-200 py-5 text-center text-sm text-stone-500 print:hidden">
  <?= e($CONFIG['app_name']) ?> · <?= e(app_subtitle()) ?>
  · <button type="button" class="underline hover:text-brand-700" data-about>เกี่ยวกับระบบ · ผู้พัฒนา</button>
</footer>
<template id="about-template"><?= about_html() ?></template>
<script src="<?= BASE ?>assets/vendor/sweetalert2.all.min.js"></script>
<?= $extraScripts ?? '' ?>
<script src="<?= BASE ?>assets/js/app.js"></script>
</body>
</html>
