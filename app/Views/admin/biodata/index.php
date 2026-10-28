<?php
// PATH LOKASI FILE: app/Views/admin/biodata/index.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head"><h1><?= Ui::icon('file-text', 22) ?> Biodata Pengurus</h1></div>

<div class="note">
  Daftar biodata yang sudah diisi pengurus sendiri lewat Portal Pengurus. Isiannya tidak bisa diubah
  dari sini -- hanya diunduh ulang atau dikirim ulang ke Google Drive kalau sebelumnya gagal.
</div>

<nav class="biodata-tabs" aria-label="Tampilan biodata">
  <a class="btn <?= $tab === 'rekap' ? '' : 'secondary' ?>" href="<?= BASEURL ?>/biodata?tab=rekap">Rekap per Pengelompokan Unit</a>
  <a class="btn <?= $tab === 'status' ? '' : 'secondary' ?>" href="<?= BASEURL ?>/biodata?tab=status">Daftar Status Biodata</a>
</nav>

<?php if ($tab === 'rekap'): ?>
<section class="card" aria-labelledby="biodata-recap-title">
  <div class="page-head" style="margin:0 0 12px">
    <h2 id="biodata-recap-title" style="font-size:18px">Rekap pengisian biodata</h2>
    <span class="muted small">Jumlah orang unik pada periode berjalan</span>
  </div>
  <?php
  $metric = function ($counts) {
      $total = (int) ($counts['total'] ?? 0);
      $done = (int) ($counts['biodata'] ?? 0);
      return $done . ' dari ' . $total . ' pengurus';
  };
  $recap = $recap ?? ['structures' => [], 'groups' => [], 'units' => [], 'core' => []];
  ?>
  <?php if (!$entries && !$without): ?>
    <div class="empty"><span>Belum ada struktur dalam lingkup Anda.</span></div>
  <?php else: ?>
    <div class="btn-row" style="margin:0 0 8px">
      <button type="button" class="btn small secondary" data-tree="biodata-recap" data-tree-all="open">Buka semua</button>
      <button type="button" class="btn small secondary" data-tree="biodata-recap" data-tree-all="close">Tutup semua</button>
    </div>
    <ul class="stree" id="biodata-recap">
      <?php foreach ($entries as $entry):
        $structure = $entry['structure']; $term = $entry['term']; $tid = (int) $term['id'];
        $structureCount = $recap['structures'][$tid] ?? ['total' => 0, 'biodata' => 0];
        $coreCount = $recap['core'][$tid] ?? ['total' => 0, 'biodata' => 0];
        $grouped = []; $loose = [];
        foreach ($entry['units'] as $unit) {
            if ($unit['unit_group_id'] !== null) $grouped[(int) $unit['unit_group_id']][] = $unit;
            else $loose[] = $unit;
        }
        usort($entry['groups'], function ($a, $b) {
            $rank = ['bidang' => 0, 'subbidang' => 1];
            return [$rank[$a['kind']] ?? 2, (int) $a['sort_order'], $a['name']] <=> [$rank[$b['kind']] ?? 2, (int) $b['sort_order'], $b['name']];
        });
      ?>
        <li class="sn">
          <details>
            <summary>
              <?= Ui::icon('chevron-right', 16, 'caret') ?>
              <span class="sn-main"><span class="sn-name"><?= e($structure['level'] . ' ' . $structure['name']) ?></span> <span class="badge"><?= e($term['name']) ?></span></span>
              <span class="sn-n"><?= e($metric($structureCount)) ?> sudah mengisi</span>
            </summary>
            <div class="sn-body">
              <ul>
                <li class="sn"><div class="sn-recap-line"><span class="sn-main"><button type="button" class="biodata-roster-trigger" data-roster-url="<?= BASEURL ?>/biodata/roster/<?= $tid ?>/core">Pengurus Inti</button></span><span class="sn-n"><?= e($metric($coreCount)) ?> sudah mengisi</span></div></li>
                <?php foreach ($entry['groups'] as $group):
                  $gid = (int) $group['id'];
                  $groupCount = $recap['groups'][$tid][$gid] ?? ['total' => 0, 'biodata' => 0];
                  $list = Susunan::sortUnits($grouped[$gid] ?? []);
                ?>
                  <li class="sn">
                    <details>
                      <summary><?= Ui::icon('chevron-right', 16, 'caret') ?><span class="sn-main"><button type="button" class="biodata-roster-trigger" data-roster-url="<?= BASEURL ?>/biodata/roster/<?= $tid ?>/<?= $gid ?>"><?= e($group['name']) ?></button></span><span class="sn-n"><?= e($metric($groupCount)) ?> sudah mengisi</span></summary>
                      <?php if ($list): ?><ul>
                        <?php foreach ($list as $unit):
                          $unitCount = $recap['units'][$tid][(int) $unit['id']] ?? ['total' => 0, 'biodata' => 0];
                          $unitName = $unit['display_name'] ?: $unit['catalog_name'];
                        ?>
                          <li class="sn"><div class="sn-recap-line"><span class="sn-main"><button type="button" class="biodata-roster-trigger" data-roster-url="<?= BASEURL ?>/biodata/roster/<?= $tid ?>/0/<?= (int) $unit['id'] ?>"><?= e($unitName) ?></button></span><span class="sn-n"><?= e($metric($unitCount)) ?> sudah mengisi</span></div></li>
                        <?php endforeach; ?>
                      </ul><?php else: ?><p class="muted small" style="margin:6px 10px">Belum ada unit.</p><?php endif; ?>
                    </details>
                  </li>
                <?php endforeach; ?>
                <?php if ($loose):
                  $looseCount = $recap['groups'][$tid][0] ?? ['total' => 0, 'biodata' => 0];
                ?>
                  <li class="sn">
                    <details>
                      <summary><?= Ui::icon('chevron-right', 16, 'caret') ?><span class="sn-main"><button type="button" class="biodata-roster-trigger" data-roster-url="<?= BASEURL ?>/biodata/roster/<?= $tid ?>/0"><?= e('Unit tanpa Bidang') ?></button></span><span class="sn-n"><?= e($metric($looseCount)) ?> sudah mengisi</span></summary>
                      <ul>
                        <?php foreach ($loose as $unit):
                          $unitCount = $recap['units'][$tid][(int) $unit['id']] ?? ['total' => 0, 'biodata' => 0];
                          $unitName = $unit['display_name'] ?: $unit['catalog_name'];
                        ?>
                          <li class="sn"><div class="sn-recap-line"><span class="sn-main"><button type="button" class="biodata-roster-trigger" data-roster-url="<?= BASEURL ?>/biodata/roster/<?= $tid ?>/0/<?= (int) $unit['id'] ?>"><?= e($unitName) ?></button></span><span class="sn-n"><?= e($metric($unitCount)) ?> sudah mengisi</span></div></li>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  </li>
                <?php endif; ?>
              </ul>
            </div>
          </details>
        </li>
      <?php endforeach; ?>
      <?php if ($without): ?>
        <li class="sn"><details>
          <summary><span class="sn-main"><span class="sn-name">Belum punya periode berjalan</span></span><span class="sn-n"><?= count($without) ?> struktur</span></summary>
          <div class="sn-body sn-roster"><ul><?php foreach ($without as $structure): ?><li class="sn"><div class="sn-recap-line"><span class="sn-main"><span class="sn-name"><?= e($structure['level'] . ' ' . $structure['name']) ?></span></span><span class="sn-n muted">Belum ada periode berjalan</span></div></li><?php endforeach; ?></ul></div>
        </details></li>
      <?php endif; ?>
    </ul>
  <?php endif; ?>
</section>

<dialog class="biodata-roster-modal" id="biodata-roster-modal" aria-labelledby="biodata-roster-title">
  <div class="page-head"><h2 id="biodata-roster-title">Daftar pengurus</h2><div class="actions"><button type="button" class="btn small secondary" data-roster-copy disabled>Salin daftar</button><button type="button" class="btn small secondary" data-roster-close>Tutup</button></div></div>
  <div class="table-wrap"><table><thead><tr><th>Status biodata</th><th>Nama</th><th>No. HP/WA</th></tr></thead><tbody id="biodata-roster-body"><tr><td colspan="3" class="muted">Memuat...</td></tr></tbody></table></div>
</dialog>
<?php else: ?>
<?php
  $totalRoster = count($roster);
  $doneRoster = count(array_filter($roster, function ($r) { return !empty($r['has_biodata']); }));
?>
<section class="card">
  <div class="page-head"><h2 style="font-size:18px">Daftar status biodata</h2><div class="actions"><span class="badge"><?= Ui::n($doneRoster) ?> / <?= Ui::n($totalRoster) ?> sudah mengisi</span><button type="button" class="btn small secondary" data-status-roster-copy <?= $roster ? '' : 'disabled' ?>>Salin nama</button></div></div>
  <form class="toolbar biodata-roster-filter" method="get" action="<?= BASEURL ?>/biodata">
    <input type="hidden" name="tab" value="status">
    <label class="field"><span>Cari nama, WA, atau asal</span><input type="search" name="q" value="<?= e($rosterQuery) ?>" placeholder="Nama / nomor WA / kota"></label>
    <label class="field"><span>Bidang atau Departemen</span><select name="penempatan"><option value="">Semua Bidang/Departemen</option><?php foreach ($rosterFilterOptions as $key => $label): ?><option value="<?= e($key) ?>" <?= $rosterFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Status biodata</span><select name="status"><option value="all" <?= $rosterStatus === 'all' ? 'selected' : '' ?>>Semua</option><option value="done" <?= $rosterStatus === 'done' ? 'selected' : '' ?>>Sudah punya</option><option value="missing" <?= $rosterStatus === 'missing' ? 'selected' : '' ?>>Belum punya</option></select></label>
    <button class="btn" type="submit">Tampilkan</button>
  </form>
  <div class="table-wrap"><table id="biodata-status-table">
    <thead><tr><th>Nama</th><th>No. HP/WA</th><th>Asal</th><th>Penempatan aktif</th><th class="center">Biodata</th></tr></thead>
    <tbody>
    <?php if (!$roster): ?><tr><td colspan="5" class="muted">Tidak ada pengurus yang cocok.</td></tr><?php endif; ?>
    <?php foreach ($roster as $person): $origin = trim(implode(', ', array_filter([$person['city'] ?? '', $person['province'] ?? '']))); ?>
      <tr data-has-biodata="<?= !empty($person['has_biodata']) ? '1' : '0' ?>">
        <td><?= e($person['full_name']) ?></td><td class="nowrap"><?= e($person['phone'] ?: '-') ?></td><td><?= e($origin ?: '-') ?></td>
        <td class="small"><?= e($person['placement_labels'] ?: '-') ?></td>
        <td class="center"><?php if (!empty($person['has_biodata'])): ?><span class="badge ok" aria-label="Sudah punya biodata">&#10003;</span><?php else: ?><span class="badge warn" aria-label="Belum punya biodata">&times;</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<?php if ($tab === 'rekap'): ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nama</th><th>Struktur</th><th>Status Drive</th><th>Diperbarui</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="5" class="muted">Belum ada biodata masuk di lingkup Anda.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= Ui::personCell(['uuid' => $r['person_uuid'], 'full_name' => $r['full_name'], 'photo_path' => $r['photo_path']]) ?></td>
          <td class="small"><?= e($r['structure_label'] ?? '-') ?></td>
          <td>
            <?php if ($r['gdrive_file_id']): ?>
              <span class="badge ok"><?= Ui::icon('check', 13) ?> Terkirim</span>
            <?php else: ?>
              <span class="badge"><?= Ui::icon('clock', 13) ?> Belum</span>
            <?php endif; ?>
          </td>
          <td class="small nowrap"><?= e(date('d/m/Y H:i', strtotime($r['updated_at']))) ?></td>
          <td class="nowrap">
            <a class="btn small secondary" href="<?= BASEURL ?>/biodata/pdf/<?= e($r['uuid']) ?>" target="_blank" rel="noopener">Unduh</a>
            <?php if (!$r['gdrive_file_id']): ?>
              <form method="post" action="<?= BASEURL ?>/biodata/resend" style="display:inline"
                    data-loading="Mengirim ke Google Drive..." data-loading-text="Mohon tunggu sebentar.">
                <?= Csrf::field() ?>
                <input type="hidden" name="uuid" value="<?= e($r['uuid']) ?>">
                <button type="submit" class="btn small secondary">Kirim ke Drive</button>
              </form>
            <?php elseif ($r['gdrive_file_url']): ?>
              <a class="btn small secondary" href="<?= e($r['gdrive_file_url']) ?>" target="_blank" rel="noopener">Buka di Drive</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php $this->view('layouts/footer'); ?>
