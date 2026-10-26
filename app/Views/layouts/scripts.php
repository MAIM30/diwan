<?php
// PATH LOKASI FILE: app/Views/layouts/scripts.php
// Skrip bersama semua halaman. Urutannya penting: SweetAlert2 (disimpan di proyek, bukan dari CDN) dimuat sebelum app.js yang memakainya.
?>
<script src="<?= BASEURL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js" defer></script>
<script src="<?= BASEURL ?>/assets/js/app.js" defer></script>
