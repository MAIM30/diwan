<?php
// PATH LOKASI FILE: app/Views/admin/_struktur_picker.php
// Pemilih struktur + periode untuk formulir jabatan (modal Tambah Jabatan di profil, dan formulir penempatan). Pengganti <select> yang memuat
// ratusan struktur sekaligus: orang memilih jenjang (chip), mengetik sebagian nama, lalu mengetuk satu baris. Daftarnya dimuat dari
// placements/structures (app.js, [data-struct-picker]) dan hanya berisi beberapa baris; yang dipilih tampil sebagai kartu kecil dengan tombol Ganti.
//
// Yang menerima nilainya: <input type="hidden" id="$id"> berisi id periode (term). Setiap pilihan memicu 'change' di input itu (menggelembung),
// jadi halaman tuan rumah cukup mendengarkan input tersebut, dan 'sp:change' di elemen .sp. Elemen .sp punya `structPicker` ({value, setWing, clear}).
//
// Masukan: $id, $name (boleh kosong), $wing ('umum' | 'muslimah' | ''), $gp (acuan periode, 0 = semua), $selected (null atau
// ['id', 'level', 'name', 'period', 'current', 'wing']), $placeholder (boleh kosong).
$selected = $selected ?? null;
$name = $name ?? '';
$gp = (int) ($gp ?? 0);
?>
<div class="sp" data-struct-picker data-url="<?= BASEURL ?>/placements/structures" data-wing="<?= e($wing ?? '') ?>" data-gp="<?= $gp ?>"<?= $selected ? ' data-selected-wing="' . e($selected['wing'] ?? '') . '"' : '' ?>>
  <input type="hidden" id="<?= e($id) ?>"<?= $name !== '' ? ' name="' . e($name) . '"' : '' ?> value="<?= $selected ? (int) $selected['id'] : '' ?>">

  <div class="sp-picked"<?= $selected ? '' : ' hidden' ?>>
    <span class="sp-badge"><?= $selected ? e($selected['level']) : '' ?></span>
    <span class="sp-picked-text">
      <b><?= $selected ? e($selected['name']) : '' ?></b>
      <small><?= $selected ? e($selected['period'] . (!empty($selected['current']) ? ' · berjalan' : ' · periode lain')) : '' ?></small>
    </span>
    <button type="button" class="btn small secondary" data-sp-change>Ganti</button>
  </div>

  <div class="sp-find"<?= $selected ? ' hidden' : '' ?>>
    <div class="sp-chips" role="group" aria-label="Saring menurut jenjang"></div>
    <div class="sp-box">
      <?= Ui::icon('search', 17) ?>
      <input type="search" class="sp-q" autocomplete="off" aria-label="Cari struktur"
             placeholder="<?= e($placeholder ?? 'Cari struktur, mis. Maros, Kendari, Sulawesi Selatan') ?>">
    </div>
    <ul class="sp-list"></ul>
    <p class="sp-note" role="status" aria-live="polite"></p>
  </div>
</div>
