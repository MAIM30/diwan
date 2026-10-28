/* PATH LOKASI FILE: public/assets/js/app.js
   Peningkatan progresif: halaman tetap terbaca dan berfungsi tanpa berkas ini (tab terbuka semua, tabel tersedia
   sebagai padanan grafik, kolom domisili tetap kolom teks, pesan tetap kotak .alert, konfirmasi memakai confirm(),
   pohon struktur tetap bisa diketuk karena memakai <details>).
   Satu-satunya pustaka adalah SweetAlert2 (dimuat sebelum berkas ini, opsional). Semua teks dari data dimasukkan
   lewat textContent atau `title`/`text`, tak pernah sebagai HTML. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- Navigasi mobile ---------- */
  function setNav(open) {
    document.body.classList.toggle('nav-open', open);
    var btn = $('[data-nav-toggle]');
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-nav-toggle]')) { setNav(!document.body.classList.contains('nav-open')); return; }
    if (e.target.closest('[data-nav-close]')) { setNav(false); return; }
    // Menu pengguna (<details>) menutup saat klik di luarnya.
    $$('.user-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  /* ---------- Roster ringkas dari rekap biodata ---------- */
  document.addEventListener('click', function (e) {
    var trigger = e.target.closest ? e.target.closest('[data-roster-url]') : null;
    var close = e.target.closest ? e.target.closest('[data-roster-close]') : null;
    var copy = e.target.closest ? e.target.closest('[data-roster-copy]') : null;
    var statusCopy = e.target.closest ? e.target.closest('[data-status-roster-copy]') : null;
    var dialog = document.getElementById('biodata-roster-modal');
    if (close && dialog) { dialog.close(); return; }
    if (statusCopy) {
      var statusNames = Array.prototype.map.call(document.querySelectorAll('#biodata-status-table tbody tr'), function (row) {
        var cells = row.querySelectorAll('td');
        if (!cells.length || cells[0].classList.contains('muted')) return '';
        var name = cells[0].textContent.trim();
        var phone = cells[1] ? cells[1].textContent.trim() : '';
        if (phone && phone !== '-') {
          var digits = phone.replace(/\D/g, '');
          if (digits.indexOf('62') === 0) phone = '0' + digits.slice(2);
          else phone = digits || phone;
        }
        if (!phone) phone = '-';
        var mark = row.getAttribute('data-has-biodata') === '1' ? '✅' : '❌';
        return mark + ' | ' + name + ' | ' + phone;
      }).filter(Boolean);
      if (!statusNames.length) return;
      var statusButton = statusCopy;
      var statusCopied = function () {
        statusButton.textContent = 'Tersalin';
        window.setTimeout(function () { if (statusButton.isConnected) statusButton.textContent = 'Salin nama'; }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(statusNames.join('\n')).then(statusCopied).catch(function () { copyRosterFallback(statusNames.join('\n'), statusCopied); });
      } else copyRosterFallback(statusNames.join('\n'), statusCopied);
      return;
    }
    if (copy && dialog) {
      var rosterRows = Array.prototype.slice.call(document.querySelectorAll('#biodata-roster-body tr'));
      var names = rosterRows.map(function (row) {
        var cells = row.querySelectorAll('td');
        if (cells.length < 3 || cells[0].classList.contains('muted')) return '';
        var mark = cells[0].textContent.trim() === '✓' ? '✅' : '❌';
        return mark + ' | ' + cells[1].textContent.trim();
      }).filter(Boolean);
      if (!names.length) return;
      var doneCount = rosterRows.filter(function (row) {
        var cells = row.querySelectorAll('td');
        return cells.length >= 3 && cells[0].textContent.trim() === '✓';
      }).length;
      var totalCount = names.length;
      var missingCount = totalCount - doneCount;
      var donePercent = (doneCount * 100 / totalCount).toFixed(1);
      var missingPercent = (missingCount * 100 / totalCount).toFixed(1);
      var groupTitle = document.getElementById('biodata-roster-title').textContent.trim();
      var clipboardText = groupTitle + '\n' +
        'Sudah memiliki biodata: ' + doneCount + '/' + totalCount + ' (' + donePercent + '%)\n' +
        'Belum memiliki biodata: ' + missingCount + '/' + totalCount + ' (' + missingPercent + '%)\n\n' +
        names.join('\n');
      var button = copy;
      var copied = function () {
        button.textContent = 'Tersalin';
        window.setTimeout(function () { if (button.isConnected) button.textContent = 'Salin daftar'; }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(clipboardText).then(copied).catch(function () { copyRosterFallback(clipboardText, copied); });
      } else copyRosterFallback(clipboardText, copied);
      return;
    }
    if (!trigger || !dialog) return;
    e.preventDefault();
    var title = document.getElementById('biodata-roster-title');
    var body = document.getElementById('biodata-roster-body');
    var copyButton = document.querySelector('[data-roster-copy]');
    copyButton.disabled = true;
    copyButton.textContent = 'Salin daftar';
    var nodeName = trigger.textContent.trim();
    title.textContent = nodeName || 'Daftar pengurus';
    body.innerHTML = '';
    var loading = document.createElement('tr');
    var loadingCell = document.createElement('td');
    loadingCell.className = 'muted'; loadingCell.textContent = 'Memuat daftar...';
    loading.appendChild(loadingCell); body.appendChild(loading);
    dialog.showModal();
    fetch(trigger.getAttribute('data-roster-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (response) { if (!response.ok) throw new Error('Gagal memuat daftar.'); return response.json(); })
      .then(function (rows) {
        body.innerHTML = '';
        if (!rows.length) {
          var tr = document.createElement('tr'), td = document.createElement('td');
          td.className = 'muted'; td.colSpan = 3; td.textContent = 'Belum ada pengurus pada kelompok ini.'; tr.appendChild(td); body.appendChild(tr); return;
        }
        copyButton.disabled = false;
        rows.forEach(function (person) {
          var tr = document.createElement('tr');
          var status = document.createElement('td');
          status.className = Number(person.has_biodata) ? 'biodata-roster-status is-done' : 'biodata-roster-status is-missing';
          status.textContent = Number(person.has_biodata) ? '✓' : '✗';
          status.setAttribute('aria-label', Number(person.has_biodata) ? 'Sudah punya biodata' : 'Belum punya biodata');
          var name = document.createElement('td'); name.textContent = person.full_name;
          var phone = document.createElement('td'); phone.textContent = person.phone || '-';
          tr.appendChild(status); tr.appendChild(name); tr.appendChild(phone);
          body.appendChild(tr);
        });
      })
      .catch(function () {
        body.innerHTML = '';
        var tr = document.createElement('tr'), td = document.createElement('td');
        td.className = 'muted'; td.colSpan = 3; td.textContent = 'Daftar gagal dimuat. Silakan coba lagi.'; tr.appendChild(td); body.appendChild(tr);
      });
  });

  function copyRosterFallback(text, done) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed'; textarea.style.left = '-9999px';
    document.body.appendChild(textarea); textarea.select();
    try { if (document.execCommand('copy')) done(); }
    finally { document.body.removeChild(textarea); }
  }

  /* ---------- Pintasan: Ctrl/Cmd+K atau "/" memfokuskan pencarian; Esc menutup ---------- */
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      var s = $('#global-search');
      if (s) { e.preventDefault(); s.focus(); s.select(); }
    } else if (e.key === '/' && !typing) {
      var s2 = $('#global-search');
      if (s2) { e.preventDefault(); s2.focus(); }
    } else if (e.key === 'Escape') {
      setNav(false);
      $$('.user-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
      hideTip();
    }
  });

  /* ---------- Tab ---------- */
  $$('[data-tabs]').forEach(function (box) {
    var tabs = $$('[role="tab"]', box);
    var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
    if (!tabs.length) return;

    function select(i, focus) {
      tabs.forEach(function (t, k) {
        var on = k === i;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        if (panels[k]) panels[k].hidden = !on;
      });
      if (focus) tabs[i].focus();
      if (tabs[i].id && history.replaceState) history.replaceState(null, '', '#' + tabs[i].id);
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(i); });
      t.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') { e.preventDefault(); select((i + 1) % tabs.length, true); }
        if (e.key === 'ArrowLeft')  { e.preventDefault(); select((i - 1 + tabs.length) % tabs.length, true); }
      });
    });
    var start = 0;
    tabs.forEach(function (t, i) { if (t.id && location.hash === '#' + t.id) start = i; });
    select(start);
  });

  /* ---------- Tampilkan/sembunyikan kata sandi ---------- */
  $$('[data-toggle-pw]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-toggle-pw'));
    var on = $('.pw-on', btn), off = $('.pw-off', btn);
    if (!input) return;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      if (show) input.setAttribute('data-pw-visible', ''); else input.removeAttribute('data-pw-visible');
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
      if (on) on.hidden = show;
      if (off) off.hidden = !show;
      input.focus();
    });
  });

  /* ---------- Buka/tutup baris formulir ubah (mis. pengaturan token) ---------- */
  $$('[data-toggle-edit]').forEach(function (btn) {
    var row = document.getElementById(btn.getAttribute('data-toggle-edit'));
    if (!row) return;
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function () {
      var show = row.hidden;
      row.hidden = !show;
      btn.setAttribute('aria-expanded', show ? 'true' : 'false');
      if (show) { var f = row.querySelector('input, select'); if (f) f.focus(); }
    });
  });

  /* ---------- Unggah foto: kirim otomatis setelah memilih berkas ---------- */
  $$('input[type="file"][data-autosubmit]').forEach(function (inp) {
    inp.addEventListener('change', function () { if (inp.files && inp.files.length) inp.form.submit(); });
  });

  /* ---------- Foto di formulir biodata: pratinjau + perkecil di peramban sebelum dikirim ----------
     Foto ponsel 3-8 MB sering melampaui batas unggah server, jadi sisi terpanjang dikecilkan ke 1280 px (JPEG) lebih dulu.
     Gagal di langkah mana pun = berkas asli dikirim apa adanya; server tetap memeriksa ulang semuanya. */
  $$('input[type="file"][data-shrink-image]').forEach(function (inp) {
    var box = inp.form ? $('[data-photo-preview]', inp.form) : null;
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      if (!f || !/^image\/(jpeg|png|webp)$/.test(f.type)) return;
      var url = URL.createObjectURL(f);
      var img = new Image();
      img.onload = function () {
        if (box) {
          var th = document.createElement('span');
          th.className = 'photo-thumb';
          var pv = document.createElement('img');
          pv.src = url; pv.alt = '';
          th.appendChild(pv);
          box.textContent = '';
          box.appendChild(th);
        }
        var scale = Math.min(1, 1280 / Math.max(img.naturalWidth, img.naturalHeight));
        if (scale === 1 && f.size <= 1500000) return;
        try {
          var c = document.createElement('canvas');
          c.width = Math.round(img.naturalWidth * scale);
          c.height = Math.round(img.naturalHeight * scale);
          var ctx = c.getContext('2d');
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, c.width, c.height);
          ctx.drawImage(img, 0, 0, c.width, c.height);
          c.toBlob(function (blob) {
            if (!blob || blob.size >= f.size) return;
            try {
              var dt = new DataTransfer();
              dt.items.add(new File([blob], 'foto.jpg', { type: 'image/jpeg' }));
              inp.files = dt.files;
            } catch (err) { /* peramban lama: kirim berkas asli */ }
          }, 'image/jpeg', 0.88);
        } catch (err) { /* kanvas tak tersedia: kirim berkas asli */ }
      };
      img.src = url;
    });
  });

  /* ---------- Avatar dari foto biodata: bila gagal dimuat (mis. Drive bermasalah), buang <img>-nya agar
     inisial di bawahnya tampil bersih tanpa ikon gambar rusak ---------- */
  $$('.av-fb img').forEach(function (img) {
    var drop = function () { img.remove(); };
    if (img.complete && img.naturalWidth === 0) drop(); else img.addEventListener('error', drop);
  });

  /* ---------- Pratinjau nama lengkap di formulir (gelar depan + nama + gelar belakang) ---------- */
  $$('[data-name-form]').forEach(function (form) {
    var pre = $('[data-np="prefix"]', form), nm = $('[data-np="name"]', form), suf = $('[data-np="suffix"]', form);
    var out = $('[data-name-preview]', form);
    if (!nm || !out) return;
    function render() {
      var base = ((pre && pre.value.trim() ? pre.value.trim() + ' ' : '') + nm.value.trim()).trim();
      var s = suf && suf.value.trim() ? ', ' + suf.value.trim() : '';
      out.textContent = (base + s) || '—';
    }
    [pre, nm, suf].forEach(function (el) { if (el) el.addEventListener('input', render); });
    render();
  });

  /* ---------- Notifikasi dan konfirmasi (SweetAlert2) ----------
     Pustakanya dimuat sebelum berkas ini (layouts/scripts.php). Tanpa pustaka itu halaman tetap berfungsi: pesan sekali-tampil tetap terbaca
     sebagai kotak .alert, dan formulir berisiko tetap memakai confirm() bawaan peramban (atribut onsubmit di HTML). Teks selalu lewat
     `title`/`text` (bukan `html`), jadi isi pesan tak pernah ditafsirkan sebagai HTML. */
  var swal = window.Swal;
  if (swal) {
    var NAVY = '#0a3c4d', DANGER = '#a4252a', GREY = '#5f7c87';

    // Pesan sekali-tampil (Flash): sukses dan info = toast sebentar; galat dan peringatan = jendela yang harus ditutup. Berurutan, satu per satu.
    var flashBox = $('[data-flash]');
    if (flashBox) {
      var queue = Promise.resolve();
      $$('.alert', flashBox).forEach(function (el) {
        var type = el.classList.contains('error') ? 'error' : el.classList.contains('warning') ? 'warning' : el.classList.contains('info') ? 'info' : 'success';
        var text = el.textContent.trim();
        queue = queue.then(function () {
          if (type === 'success' || type === 'info') {
            return swal.fire({ toast: true, position: 'top-end', icon: type, title: text, showConfirmButton: false, timer: 4500, timerProgressBar: true });
          }
          return swal.fire({ icon: type, title: type === 'error' ? 'Belum berhasil' : 'Perhatian', text: text, confirmButtonText: 'OK', confirmButtonColor: NAVY });
        });
      });
      flashBox.hidden = true;
    }

    // Tindakan berisiko: <form data-confirm="Pertanyaan?"> meminta persetujuan dulu.
    $$('form[data-confirm]').forEach(function (form) {
      form.removeAttribute('onsubmit');
      var approved = false;
      form.addEventListener('submit', function (e) {
        if (approved) return;
        e.preventDefault();
        var by = e.submitter;
        swal.fire({
          icon: 'warning', title: 'Lanjutkan?', text: form.getAttribute('data-confirm'),
          showCancelButton: true, confirmButtonText: 'Ya, lanjutkan', cancelButtonText: 'Batal',
          confirmButtonColor: DANGER, cancelButtonColor: GREY, reverseButtons: true, focusCancel: true
        }).then(function (r) {
          if (!r.isConfirmed) return;
          approved = true;
          if (form.requestSubmit) form.requestSubmit(by || undefined); else form.submit();
        });
      });
    });

    // Formulir yang memanggil layanan luar (WA Gateway, Google Drive) dan bisa butuh beberapa detik:
    // <form data-loading="Judul..." data-loading-text="Keterangan (opsional)"> menampilkan jendela loading
    // supaya tidak terlihat macet. Tidak dipakai bersamaan dengan data-confirm pada form yang sama.
    $$('form[data-loading]').forEach(function (form) {
      form.addEventListener('submit', function () {
        swal.fire({
          title: form.getAttribute('data-loading'),
          text: form.getAttribute('data-loading-text') || undefined,
          allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false,
          didOpen: function () { swal.showLoading(); }
        });
      });
    });
    // Tombol Kembali memulihkan halaman dari cache dengan jendela loading masih terbuka: tutup, agar tidak tampak macet.
    window.addEventListener('pageshow', function (e) { if (e.persisted && swal.isVisible()) swal.close(); });
  }

  /* ---------- Domisili bertingkat: provinsi -> kota/kabupaten -> kecamatan -> kelurahan/desa ----------
     Daftar dari <data-lokasi>/provinsi, /kabupaten/<kode>, ... (LokasiController, bersumber API wilayah). Kolom teks aslinya tetap ada di
     HTML dan menjadi cadangan: bila daftar tak bisa dimuat, kolom itu tetap bisa diketik. Yang tersimpan adalah NAMA resmi. Nilai lama yang
     tak cocok dengan daftar (data impor atau ketikan bebas) tidak dibuang: tampil sebagai pilihan "(data lama)" dan tetap terkirim sampai diganti. */
  $$('[data-lokasi]').forEach(function (form) {
    var base = form.getAttribute('data-lokasi').replace(/\/+$/, '');
    var chain = [
      { key: 'province', path: 'provinsi',  hint: 'Pilih provinsi',          wait: '' },
      { key: 'city',     path: 'kabupaten', hint: 'Pilih kota / kabupaten',  wait: 'Pilih provinsi dulu' },
      { key: 'district', path: 'kecamatan', hint: 'Pilih kecamatan',         wait: 'Pilih kota / kabupaten dulu' },
      { key: 'village',  path: 'kelurahan', hint: 'Pilih kelurahan / desa',  wait: 'Pilih kecamatan dulu' }
    ];
    var complete = chain.every(function (f) {
      f.input = $('[data-lokasi-field="' + f.key + '"]', form);
      if (!f.input) return false;
      f.legacy = f.input.value.trim(); f.code = ''; f.select = null; f.seq = 0;
      return true;
    });
    if (!complete || !window.fetch) return;

    // "Kab. Gowa", "KABUPATEN GOWA", "Gowa" -> "gowa"; "Kota Bau Bau" dan "Baubau" -> "baubau".
    function norm(s) { return s.toLowerCase().replace(/^(provinsi|prov\.?|kabupaten|kab\.?|kota)\s+/, '').replace(/[^a-z0-9]/g, ''); }
    // Nama resmi yang cocok dengan teks lama; hanya bila TEPAT satu (mis. "Bogor" ada Kabupaten dan Kota: dibiarkan sebagai data lama).
    function match(items, text) {
      if (!text) return null;
      var t = text.trim().toLowerCase();
      var exact = items.filter(function (it) { return it.name.toLowerCase() === t; });
      if (exact.length === 1) return exact[0];
      var n = norm(text);
      if (!n) return null;
      var loose = items.filter(function (it) { return norm(it.name) === n; });
      return loose.length === 1 ? loose[0] : null;
    }
    function option(value, label, code) {
      var o = document.createElement('option');
      o.value = value; o.textContent = label;
      if (code) o.setAttribute('data-code', code);
      return o;
    }
    function toSelect(f) {
      if (f.select) return f.select;
      var sel = document.createElement('select');
      sel.className = 'lokasi-select';
      sel.name = f.input.name;
      var id = f.input.id; f.input.id = id + '-teks'; sel.id = id;     // label tetap menunjuk ke kolom yang aktif
      f.input.disabled = true; f.input.hidden = true;                  // yang dinonaktifkan tidak ikut terkirim
      f.input.parentNode.insertBefore(sel, f.input.nextSibling);
      sel.addEventListener('change', function () { pick(f); });
      f.select = sel;
      return sel;
    }
    function toText(f, value) {
      if (f.select) { var id = f.select.id; f.select.parentNode.removeChild(f.select); f.select = null; f.input.id = id; }
      f.input.disabled = false; f.input.hidden = false;
      if (value !== undefined) f.input.value = value;
    }
    // Mengisi pilihan. items === null: induknya belum dipilih (atau sedang memuat, bila `busy`); hanya nilai lama yang bisa tampil.
    function fill(f, items, busy) {
      var sel = toSelect(f), chosen = null;
      sel.innerHTML = ''; f.code = '';
      sel.classList.toggle('is-waiting', !items);
      sel.setAttribute('aria-busy', busy ? 'true' : 'false');
      sel.appendChild(option('', busy ? 'Memuat…' : (items ? f.hint : f.wait)));
      if (items) {
        items.forEach(function (it) { sel.appendChild(option(it.name, it.name, it.code)); });
        chosen = match(items, f.legacy);
        if (chosen) f.legacy = '';
      }
      if (chosen) { sel.value = chosen.name; f.code = chosen.code; }
      else if (f.legacy) { sel.insertBefore(option(f.legacy, f.legacy + ' (data lama)'), sel.options[1] || null); sel.value = f.legacy; }
      return chosen;
    }
    function reset(from) {                // tingkat di bawah: kosongkan sampai induknya dipilih
      for (var j = from; j < chain.length; j++) { chain[j].seq++; fill(chain[j], null); }
    }
    function fallback(i) {                // daftar tak bisa dimuat: kembali ke kolom teks biasa, nilai yang ada dipertahankan
      for (var j = i; j < chain.length; j++) {
        var g = chain[j]; g.seq++;
        toText(g, g.select ? (g.select.value || g.legacy || '') : g.input.value);
      }
    }
    function load(i) {
      var f = chain[i], parent = i > 0 ? chain[i - 1] : null, seq = ++f.seq;
      if (parent && !parent.code) { fill(f, null); reset(i + 1); return; }
      fill(f, null, true); reset(i + 1);
      var url = base + '/' + f.path + (parent ? '/' + encodeURIComponent(parent.code) : '');
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function (d) {
          if (seq !== f.seq) return;                                   // jawaban basi: pilihan sudah berganti
          if (!d || !Array.isArray(d.data)) throw new Error('bentuk');
          fill(f, d.data);
          if (i + 1 < chain.length) load(i + 1);
        })
        .catch(function () { if (seq === f.seq) fallback(i); });
    }
    function pick(f) {
      var i = chain.indexOf(f), o = f.select.options[f.select.selectedIndex];
      f.code = o ? (o.getAttribute('data-code') || '') : '';
      if (o && (o.getAttribute('data-code') || o.value === '')) f.legacy = '';   // memilih nama resmi atau mengosongkan: nilai lama dilepas
      if (i + 1 < chain.length) load(i + 1);
    }

    load(0);   // tingkat teratas dimuat; sisanya menyusul berantai, tiap nilai tersimpan dicocokkan dengan nama resmi
  });

  /* ---------- Pohon struktur: "Buka semua" / "Tutup semua" ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-tree-all]') : null;
    var tree = b ? document.getElementById(b.getAttribute('data-tree')) : null;
    if (!tree) return;
    var open = b.getAttribute('data-tree-all') === 'open';
    // Panel Ubah/Tambah (details.sn-edit) bukan simpul pohon: ikut dibuka semua hanya akan memenuhi layar dengan formulir.
    $$('details:not(.sn-edit)', tree).forEach(function (d) { d.open = open; });
  });

  /* ---------- Tooltip grafik ---------- */
  var tip = $('#tip');
  var tipTitle = tip && $('.tl', tip), tipVal = tip && $('.tv', tip), tipKey = tip && $('.key', tip);
  var activeArea = null;

  function showTip(el, x, y) {
    if (!tip) return;
    tipTitle.textContent = el.getAttribute('data-tip-title') || '';
    tipVal.textContent = el.getAttribute('data-tip-value') || '';
    var color = el.getAttribute('data-tip-color');
    tipKey.style.background = color || 'transparent';
    tip.hidden = false;

    var w = tip.offsetWidth, h = tip.offsetHeight, pad = 12;
    var left = x + 14, top = y - h - 12;
    if (left + w + pad > window.innerWidth) left = x - w - 14;
    if (left < pad) left = pad;
    if (top < pad) top = y + 18;
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
  }
  function hideTip() {
    if (tip) tip.hidden = true;
    if (activeArea) { activeArea.classList.remove('hover'); activeArea = null; }
  }

  // Crosshair + titik pada grafik area: mengikuti pita sentuh yang sedang disorot.
  function areaCrosshair(el) {
    var svg = el.closest('svg.area');
    if (!svg || !el.hasAttribute('data-cx')) return;
    var cx = el.getAttribute('data-cx'), cy = el.getAttribute('data-cy');
    var xh = $('.xh', svg), dot = $('.xdot', svg);
    xh.setAttribute('x1', cx); xh.setAttribute('x2', cx);
    dot.setAttribute('cx', cx); dot.setAttribute('cy', cy);
    svg.classList.add('hover');
    activeArea = svg;
  }

  document.addEventListener('pointermove', function (e) {
    var el = e.target.closest ? e.target.closest('[data-tip-title]') : null;
    if (!el) { hideTip(); return; }
    areaCrosshair(el);
    showTip(el, e.clientX, e.clientY);
  });
  // Tanpa fase tangkap: pointerleave di setiap elemen anak akan membuat tooltip berkedip.
  document.documentElement.addEventListener('pointerleave', hideTip);
  document.addEventListener('scroll', hideTip, true);

  // Papan ketik: fokus menampilkan isi yang sama dengan hover.
  document.addEventListener('focusin', function (e) {
    var el = e.target.closest ? e.target.closest('[data-tip-title]') : null;
    if (!el) return;
    areaCrosshair(el);
    var r = el.getBoundingClientRect();
    showTip(el, r.left + r.width / 2, r.top + r.height / 2);
  });
  document.addEventListener('focusout', function (e) {
    if (e.target.closest && e.target.closest('[data-tip-title]')) hideTip();
  });

  /* ---------- Paginasi sisi-klien: data-per-page="tableId" + data-pager-for="tableId" ---------- */
  $$('select[data-per-page]').forEach(function (sel) {
    var tableId = sel.getAttribute('data-per-page');
    var table = document.getElementById(tableId);
    var pager = $('[data-pager-for="' + tableId + '"]');
    if (!table) return;

    function dataRows() {
      var rows = [];
      var trs = table.tBodies[0] ? table.tBodies[0].rows : [];
      for (var i = 0; i < trs.length; i++) {
        if (!trs[i].id) rows.push(trs[i]);
      }
      return rows;
    }

    var curPage = 1;

    function render() {
      var perPage = parseInt(sel.value, 10) || 25;
      var rows = dataRows();
      var total = rows.length;
      var pages = Math.max(1, Math.ceil(total / perPage));
      if (curPage > pages) curPage = pages;
      var start = (curPage - 1) * perPage;
      var end = start + perPage;

      for (var i = 0; i < rows.length; i++) {
        rows[i].hidden = i < start || i >= end;
        var editId = rows[i].cells[rows[i].cells.length - 1] ?
          rows[i].querySelector('[data-toggle-edit]') : null;
        if (editId) {
          var editRow = document.getElementById(editId.getAttribute('data-toggle-edit'));
          if (editRow && rows[i].hidden) editRow.hidden = true;
        }
      }

      if (!pager) return;
      pager.innerHTML = '';
      if (pages <= 1) return;

      function btn(label, page, disabled, active) {
        var a = document.createElement('a');
        a.className = 'page-btn' + (active ? ' active' : '');
        a.textContent = label;
        a.href = '#';
        if (disabled || active) { a.classList.add('disabled'); a.setAttribute('aria-disabled', 'true'); }
        else a.addEventListener('click', function (e) { e.preventDefault(); curPage = page; render(); });
        pager.appendChild(a);
      }

      btn('‹', curPage - 1, curPage === 1, false);
      for (var p = 1; p <= pages; p++) btn('' + p, p, false, p === curPage);
      btn('›', curPage + 1, curPage === pages, false);
    }

    sel.addEventListener('change', function () { curPage = 1; render(); });
    render();
  });
})();
