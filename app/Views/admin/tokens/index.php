<?php
// PATH LOKASI FILE: app/Views/admin/tokens/index.php
$this->view('layouts/header', ['title' => $title]);
?>

<div class="page-head"><h1>Token Aplikasi Konsumen</h1></div>

<?php if ($freshToken): ?>
  <div class="card">
    <h2>Token baru untuk &ldquo;<?= e($freshToken['name']) ?>&rdquo;</h2>
    <div class="alert warn">
      <strong>Salin sekarang.</strong> Yang tersimpan di database hanya hash-nya, jadi nilai ini
      tidak bisa ditampilkan lagi setelah Anda meninggalkan halaman ini.
    </div>
    <div class="token-box"><?= e($freshToken['token']) ?></div>
    <p class="small muted">
      Pemakaian di aplikasi konsumen:
      <code class="mono">Authorization: Bearer <?= e(substr($freshToken['token'], 0, 20)) ?>…</code>
    </p>
  </div>
<?php endif; ?>

<div class="note">
  API ini <strong>hanya menerima GET</strong>. Tidak ada endpoint tulis, jadi aplikasi konsumen
  tidak bisa mengubah data master dari jauh &mdash; bahkan kalau ada bug di sana.
  Izin <code>people:nik</code> sengaja dipisah dari <code>people:read</code>: token sinkronisasi
  harian tidak perlu, dan karena itu sebaiknya tidak diberi, kemampuan membuka nomor KTP.
</div>

<div class="card">
  <h2>Terbitkan Token Baru</h2>
  <form method="post" action="<?= BASEURL ?>/tokens/create">
    <?= Csrf::field() ?>
    <div class="grid-3">
      <div class="field">
        <label for="name">Nama Aplikasi Konsumen</label>
        <input type="text" id="name" name="name" required placeholder="mis. syuro">
      </div>
      <div class="field">
        <label for="rate_limit_per_min">Batas per Menit</label>
        <input type="number" id="rate_limit_per_min" name="rate_limit_per_min" value="60" min="1">
      </div>
      <div class="field">
        <label for="expires_at">Kedaluwarsa (opsional)</label>
        <input type="date" id="expires_at" name="expires_at">
      </div>
    </div>

    <div class="field">
      <label>Izin</label>
      <?php foreach ($scopeList as $k => $v): ?>
        <label class="check">
          <input type="checkbox" name="scopes[]" value="<?= e($k) ?>"
                 <?= in_array($k, ['structures:read','people:read','placements:read'], true) ? 'checked' : '' ?>>
          <span>
            <code class="mono"><?= e($k) ?></code> &mdash; <?= e($v) ?>
            <?php if ($k === 'people:nik'): ?>
              <span class="badge danger">beri hanya bila benar-benar perlu</span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="field" style="max-width:420px">
      <label for="scope_structure_id">Batasi ke Subtree Struktur (opsional)</label>
      <select id="scope_structure_id" name="scope_structure_id">
        <option value="">— seluruh organisasi —</option>
        <?php foreach ($structures as $s): ?>
          <option value="<?= (int) $s['id'] ?>">
            <?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="help">Untuk token tingkat wilayah yang hanya boleh melihat wilayahnya sendiri.</div>
    </div>

    <button type="submit" class="btn">Terbitkan Token</button>
  </form>
</div>

<div class="card">
  <h2>Token Terdaftar</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Nama</th><th>Prefix</th><th>Izin</th><th>Lingkup</th><th class="num">Batas/mnt</th><th class="num">24 jam</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="muted">Belum ada token.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $scopes = json_decode($r['scopes'], true) ?: []; ?>
        <tr>
          <td><strong><?= e($r['name']) ?></strong>
            <?php if ($r['last_used_at']): ?>
              <div class="small muted">dipakai <?= e(date('d/m/Y H:i', strtotime($r['last_used_at']))) ?></div>
            <?php else: ?>
              <div class="small muted">belum pernah dipakai</div>
            <?php endif; ?>
          </td>
          <td class="small mono"><?= e($r['token_prefix']) ?></td>
          <td class="small">
            <?php foreach ($scopes as $s): ?>
              <span class="badge <?= $s === 'people:nik' ? 'danger' : '' ?>"><?= e($s) ?></span>
            <?php endforeach; ?>
          </td>
          <td class="small"><?= e($r['scope_name'] ? $r['level'] . ' ' . $r['scope_name'] : 'seluruh organisasi') ?></td>
          <td class="num"><?= (int) $r['rate_limit_per_min'] ?></td>
          <td class="num"><a href="<?= BASEURL ?>/tokens/log/<?= (int) $r['id'] ?>"><?= (int) $r['calls_24h'] ?></a></td>
          <td>
            <?php if ($r['status'] === 'active'): ?><span class="badge ok">aktif</span>
            <?php else: ?><span class="badge danger">dicabut</span><?php endif; ?>
            <?php if (!empty($r['expires_at']) && strtotime($r['expires_at']) < time()): ?>
              <span class="badge danger">kedaluwarsa</span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <button type="button" class="btn small secondary" data-toggle-edit="tok-<?= (int) $r['id'] ?>">Ubah</button>
            <form class="inline" method="post" action="<?= BASEURL ?>/tokens/rotate"
                  data-confirm="Terbitkan token baru? Token lama LANGSUNG tidak berlaku." onsubmit="return confirm(this.dataset.confirm)">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn small secondary">Rotasi</button>
            </form>
            <?php if ($r['status'] === 'active'): ?>
              <form class="inline" method="post" action="<?= BASEURL ?>/tokens/revoke"
                    data-confirm="Cabut token ini?" onsubmit="return confirm(this.dataset.confirm)">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn small danger">Cabut</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <tr id="tok-<?= (int) $r['id'] ?>" hidden>
          <td colspan="8">
            <form method="post" action="<?= BASEURL ?>/tokens/update">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <div class="grid-3">
                <div class="field">
                  <label for="n<?= (int) $r['id'] ?>">Nama Aplikasi Konsumen</label>
                  <input type="text" id="n<?= (int) $r['id'] ?>" name="name" required value="<?= e($r['name']) ?>">
                </div>
                <div class="field">
                  <label for="rl<?= (int) $r['id'] ?>">Batas per Menit</label>
                  <input type="number" id="rl<?= (int) $r['id'] ?>" name="rate_limit_per_min" min="1"
                         value="<?= (int) $r['rate_limit_per_min'] ?>">
                </div>
                <div class="field">
                  <label for="ex<?= (int) $r['id'] ?>">Kedaluwarsa (opsional)</label>
                  <input type="date" id="ex<?= (int) $r['id'] ?>" name="expires_at"
                         value="<?= e($r['expires_at'] ? date('Y-m-d', strtotime($r['expires_at'])) : '') ?>">
                </div>
              </div>
              <div class="field">
                <label>Izin</label>
                <?php foreach ($scopeList as $k => $v): ?>
                  <label class="check">
                    <input type="checkbox" name="scopes[]" value="<?= e($k) ?>"
                           <?= in_array($k, $scopes, true) ? 'checked' : '' ?>>
                    <span><code class="mono"><?= e($k) ?></code> &mdash; <?= e($v) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="field" style="max-width:420px">
                <label for="sc<?= (int) $r['id'] ?>">Batasi ke Subtree Struktur (opsional)</label>
                <select id="sc<?= (int) $r['id'] ?>" name="scope_structure_id">
                  <option value="">— seluruh organisasi —</option>
                  <?php foreach ($structures as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $r['scope_structure_id'] ? 'selected' : '' ?>>
                      <?= e(str_repeat('— ', (int) $s['depth']) . $s['level'] . ' ' . $s['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn">Simpan Perubahan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $this->view('layouts/footer'); ?>
