<?php
// PATH LOKASI FILE: app/Views/public/daftar.php
$prefillPhone = '';
if (!empty($_SESSION['daftar_phone'])) {
    $prefillPhone = $_SESSION['daftar_phone'];
    unset($_SESSION['daftar_phone']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f96b0">
<title>Pendaftaran Data Pengurus &middot; <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= Ui::asset('css/app.css') ?>">
</head>
<body>
<div class="centered">
  <div class="card wide">
    <div class="hero">
    <h1>Pendaftaran Data Pengurus</h1>
    <p class="sub"><?= e(APP_NAME) ?></p>
    </div>

    <?php $this->view('layouts/flash'); ?>

    <div class="alert info">
      Formulir ini hanya mengirim <strong>pengajuan</strong>. Data Anda akan diperiksa pengurus
      struktur yang Anda pilih sebelum tercatat sebagai data resmi.
      Data lain seperti tanggal lahir dan NIK bisa dilengkapi sendiri setelah berhasil masuk ke Portal Pengurus.
    </div>

    <form method="post" action="<?= BASEURL ?>/daftar/submit" data-name-form>
      <?= Csrf::field() ?>

      <div class="field">
        <label for="person_search">Cari nama yang sudah terdaftar</label>
        <input type="hidden" id="existing_person_id" name="existing_person_id" value="">
        <input type="search" id="person_search" autocomplete="off" placeholder="Ketik minimal 3 huruf nama pengurus...">
        <div id="person-results" class="autocomplete-list"></div>
        <div class="help">Cari dengan sebagian nama; kata-katanya tidak harus berurutan. Daftar hanya menampilkan pengurus yang belum memiliki nomor WA dan dapat dimuat bertahap. Jika nama ditemukan, pilih agar pengajuan tertaut ke data yang sudah ada. Jika tidak ditemukan, isi nama seperti biasa di bawah.</div>
        <div id="person-selected" class="help" style="display:none;color:var(--success)"></div>
      </div>

      <div class="name-grid">
        <div class="field">
          <label for="title_prefix">Gelar depan</label>
          <input type="text" id="title_prefix" name="title_prefix" data-np="prefix" placeholder="Dr. H.">
        </div>
        <div class="field">
          <label for="name">Nama asli <span style="color:var(--danger)">*</span></label>
          <input type="text" id="name" name="name" data-np="name" required placeholder="Rahmat Hidayat">
        </div>
        <div class="field">
          <label for="title_suffix">Gelar belakang</label>
          <input type="text" id="title_suffix" name="title_suffix" data-np="suffix" placeholder="S.Pd., M.Si.">
        </div>
      </div>
      <div class="name-preview"><span class="k">Nama lengkap</span><span class="v" data-name-preview>—</span></div>

      <div class="grid-2">
        <div class="field">
          <label for="gender">Jenis Kelamin <span style="color:var(--danger)">*</span></label>
          <select id="gender" name="gender" required>
            <option value="L">Laki-laki</option>
            <option value="P">Perempuan</option>
          </select>
        </div>
        <div class="field">
          <label for="phone">Nomor WhatsApp <span style="color:var(--danger)">*</span></label>
          <input type="tel" id="phone" name="phone" inputmode="tel" required
                 placeholder="08xxxxxxxxxx" value="<?= e($prefillPhone) ?>">
        </div>
      </div>

      <div class="field">
        <label for="structure_search">Struktur Tempat Anda Menjabat <span style="color:var(--danger)">*</span></label>
        <input type="hidden" id="target_structure_id" name="target_structure_id" required>
        <input type="text" id="structure_search" autocomplete="off" required
               placeholder="Ketik nama kota/kabupaten atau provinsi...">
        <div id="structure-results" class="autocomplete-list"></div>
        <div class="help">Ketik minimal 2 huruf untuk mencari. Laki-laki memilih struktur DPP/DPW/DPLN/DPD/DPC/DPRa; perempuan memilih MWP/MWW/MWD/MWC/MWRa.</div>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn">Kirim Pengajuan</button>
        <a class="btn secondary" href="<?= BASEURL ?>/pengurus/login">Sudah terdaftar? Masuk</a>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var personSearch = document.getElementById('person_search');
  var personId = document.getElementById('existing_person_id');
  var personResults = document.getElementById('person-results');
  var personSelected = document.getElementById('person-selected');
  var searchTimer = null;
  var searchRequest = null;
  var choosingPerson = false;
  var loadingMorePeople = false;

  function addPersonOption(person) {
    var item = document.createElement('div');
    item.className = 'autocomplete-item';
    var strong = document.createElement('strong');
    strong.textContent = person.full_name;
    var gender = document.createElement('span');
    gender.className = 'muted small';
    gender.textContent = person.gender === 'P' ? ' · Perempuan' : ' · Laki-laki';
    item.appendChild(strong);
    item.appendChild(gender);
    var origin = document.createElement('div');
    origin.className = 'muted small';
    origin.textContent = person.origin_name ? ((person.origin_level || '') + ' ' + person.origin_name).trim() : 'Struktur asal belum dicatat';
    item.appendChild(origin);
    item.addEventListener('mousedown', function (e) {
      e.preventDefault();
      choosingPerson = true;
      personId.value = person.id;
      document.getElementById('title_prefix').value = person.title_prefix || '';
      document.getElementById('name').value = person.name || '';
      document.getElementById('title_suffix').value = person.title_suffix || '';
      document.getElementById('gender').value = person.gender;
      document.getElementById('name').dispatchEvent(new Event('input', { bubbles: true }));
      personSearch.value = person.full_name;
      personSelected.textContent = 'Nama terhubung ke data pengurus yang sudah ada.';
      personSelected.style.display = 'block';
      personResults.style.display = 'none';
      choosingPerson = false;
    });
    personResults.appendChild(item);
  }

  function searchPeople(q, offset) {
    if (searchRequest) searchRequest.abort();
    searchRequest = new AbortController();
    loadingMorePeople = offset > 0;
    fetch('<?= BASEURL ?>/daftar/cariNama?q=' + encodeURIComponent(q) + '&offset=' + offset, { signal: searchRequest.signal })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (offset === 0) personResults.innerHTML = '';
        var items = data.items || [];
        if (offset === 0 && !items.length) {
          var empty = document.createElement('div');
          empty.className = 'autocomplete-item muted';
          empty.textContent = 'Tidak ada nama tanpa nomor WA yang cocok.';
          personResults.appendChild(empty);
        } else {
          items.forEach(addPersonOption);
        }
        if (data.has_more) {
          var more = document.createElement('button');
          more.type = 'button';
          more.className = 'autocomplete-item';
          more.textContent = 'Muat 50 nama berikutnya';
          more.addEventListener('mousedown', function (e) { e.preventDefault(); });
          more.addEventListener('click', function () {
            if (!loadingMorePeople) searchPeople(q, offset + items.length);
          });
          personResults.appendChild(more);
        }
        loadingMorePeople = false;
        personResults.style.display = 'block';
      })
      .catch(function (error) { loadingMorePeople = false; if (error.name !== 'AbortError') personResults.style.display = 'none'; });
  }

  personSearch.addEventListener('input', function () {
    personId.value = '';
    personSelected.style.display = 'none';
    var q = personSearch.value.trim();
    clearTimeout(searchTimer);
    if (searchRequest) searchRequest.abort();
    if (q.length < 3) { personResults.style.display = 'none'; return; }
    searchTimer = setTimeout(function () { searchPeople(q, 0); }, 250);
  });

  personSearch.addEventListener('blur', function () {
    setTimeout(function () { personResults.style.display = 'none'; }, 150);
  });

  ['title_prefix', 'name', 'title_suffix', 'gender'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () {
      if (choosingPerson || !personId.value) return;
      personId.value = '';
      personSelected.textContent = 'Nama berubah. Cari dan pilih kembali agar tertaut ke data yang benar.';
    });
    document.getElementById(id).addEventListener('change', function () {
      if (choosingPerson || !personId.value) return;
      personId.value = '';
      personSelected.textContent = 'Data berubah. Cari dan pilih kembali agar tertaut ke data yang benar.';
    });
  });

  var structures = <?php
    $list = [];
    foreach ($structures as $s) {
        if (!$s['is_active']) continue;
        $list[] = [
            'id'    => (int) $s['id'],
            'label' => $s['level'] . ' ' . $s['name'],
            'level' => $s['level'],
            'parent'=> $s['parent_name'] ? ($s['parent_level'] . ' ' . $s['parent_name']) : '',
        ];
    }
    echo json_encode($list, JSON_UNESCAPED_UNICODE);
  ?>;

  var input = document.getElementById('structure_search');
  var hidden = document.getElementById('target_structure_id');
  var results = document.getElementById('structure-results');
  var active = -1;

  function render(items) {
    results.innerHTML = '';
    active = -1;
    if (!items.length) { results.style.display = 'none'; return; }
    items.forEach(function (s, i) {
      var div = document.createElement('div');
      div.className = 'autocomplete-item';
      div.setAttribute('data-idx', i);
      div.innerHTML = '<strong>' + esc(s.label) + '</strong>'
        + (s.parent ? ' <span class="muted small">' + esc(s.parent) + '</span>' : '');
      div.addEventListener('mousedown', function (e) {
        e.preventDefault();
        pick(s);
      });
      results.appendChild(div);
    });
    results.style.display = 'block';
  }

  function pick(s) {
    hidden.value = s.id;
    input.value = s.label;
    results.style.display = 'none';
    input.setCustomValidity('');
  }

  function esc(t) {
    var d = document.createElement('span');
    d.textContent = t;
    return d.innerHTML;
  }

  function navigate(dir) {
    var items = results.querySelectorAll('.autocomplete-item');
    if (!items.length) return;
    if (active >= 0) items[active].classList.remove('is-active');
    active += dir;
    if (active < 0) active = items.length - 1;
    if (active >= items.length) active = 0;
    items[active].classList.add('is-active');
    items[active].scrollIntoView({ block: 'nearest' });
  }

  input.addEventListener('input', function () {
    hidden.value = '';
    input.setCustomValidity('Pilih salah satu struktur dari daftar');
    var q = this.value.trim().toLowerCase();
    if (q.length < 2) { render([]); return; }
    var words = q.split(/\s+/);
    var matched = structures.filter(function (s) {
      var hay = (s.label + ' ' + s.parent).toLowerCase();
      return words.every(function (w) { return hay.indexOf(w) !== -1; });
    });
    render(matched.slice(0, 20));
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); navigate(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); navigate(-1); }
    else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      var items = results.querySelectorAll('.autocomplete-item');
      if (items[active]) {
        var idx = parseInt(items[active].getAttribute('data-idx'));
        var filtered = structures.filter(function (s) {
          var hay = (s.label + ' ' + s.parent).toLowerCase();
          var words = input.value.trim().toLowerCase().split(/\s+/);
          return words.every(function (w) { return hay.indexOf(w) !== -1; });
        });
        if (filtered[idx]) pick(filtered[idx]);
      }
    }
  });

  input.addEventListener('blur', function () {
    setTimeout(function () { results.style.display = 'none'; }, 150);
  });

  input.addEventListener('focus', function () {
    if (input.value.trim().length >= 2 && !hidden.value) input.dispatchEvent(new Event('input'));
  });

  // Validasi: pastikan struktur dipilih dari daftar
  input.setCustomValidity('Pilih salah satu struktur dari daftar');
  document.querySelector('form').addEventListener('submit', function (e) {
    if (!hidden.value) {
      input.setCustomValidity('Pilih salah satu struktur dari daftar');
      input.reportValidity();
      e.preventDefault();
    }
  });
})();
</script>

<?php $this->view('layouts/scripts'); ?>
</body>
</html>
