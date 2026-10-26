#!/usr/bin/env python3
# PATH LOKASI FILE: database/tools/biodata_pdf_to_json.py
# Mengubah PDF "Biodata / Profil Singkat Pengurus" menjadi biodata.json + foto, untuk dibaca
# database/import_biodata.php. Tidak menyentuh basis data.
#
#   python database/tools/biodata_pdf_to_json.py <folder-pdf> <folder-keluaran> [--meta entries.json]
#
# Kebutuhan: `pdftotext` (poppler) di PATH. Tanpa pustaka Python tambahan.
#
# PDF diperlakukan sebagai DATA TIDAK DIPERCAYA: hanya dibaca (teks lewat pdftotext, foto lewat
# pemindaian aliran JPEG), tidak pernah dieksekusi, dan nama berkas tidak dipakai sebagai jalur keluaran.
#
# Keluaran:
#   <keluaran>/biodata.json   satu objek per PDF (isian mentah + nama berkas foto)
#   <keluaran>/photos/<n>.jpg foto pas (JPEG asli dari PDF; di-sanitasi ulang saat impor)
#
# --meta (opsional): JSON [[id, judul, "Oct 4"], ...] dari daftar folder Drive, untuk tanggal ubah.

import hashlib
import json
import os
import re
import subprocess
import sys

FIELDS = [
    ('nama', r'1\.\s*Nama Lengkap dan Gelar'),
    ('ttl', r'2\.\s*Tempat, Tanggal Lahir'),
    ('domisili', r'3\.\s*Domisili'),
    ('hp', r'4\.\s*Nomor HP/WhatsApp'),
    ('pekerjaan', r'5\.\s*Pekerjaan/Profesi'),
    ('hp_keluarga', r'6\.\s*No\. HP Keluarga'),
    ('jabatan', r'7\.\s*Jabatan/Amanah'),
    ('bidang', r'8\.\s*Bidang/Departemen/Lembaga'),
    ('pengalaman', r'9\.\s*Pengalaman Organisasi'),
    ('kompetensi', r'10\.\s*Kompetensi/Keahlian'),
    ('karya', r'11\.\s*Karya/Kontribusi'),
]
LOGO_SIZE = (240, 210)   # logo Wahdah Islamiyah yang tertanam di tiap formulir, bukan foto orang


def pdf_text(path):
    # -raw menjaga pasangan "label : nilai" tetap sebaris; -layout menggeser nilai satu baris.
    r = subprocess.run(['pdftotext', '-raw', path, '-'], capture_output=True, check=True)
    return r.stdout.decode('utf-8', errors='replace').replace('\f', '\n')


def parse_fields(text):
    cut = text.find('Komitmen Kesiapan Menjadi Pengurus')
    head = text[:cut] if cut > 0 else text
    pos = []
    for key, rx in FIELDS:
        m = re.search(rx + r'\s*:', head)
        pos.append((key, m.start() if m else None, m.end() if m else None))
    rec = {}
    for i, (key, s, e) in enumerate(pos):
        if s is None:
            rec[key] = None
            continue
        nxt = [p[1] for p in pos[i + 1:] if p[1] is not None]
        v = head[e:(min(nxt) if nxt else len(head))]
        v = re.split(r'\n\s*B\.\s*Profil Keorganisasian', v)[0]
        rec[key] = re.sub(r'\s+', ' ', v).strip()
    m = re.search(r'Tempat, Tanggal:\s*([^\n]*)', text)
    rec['ttd_tempat_tanggal'] = m.group(1).strip() if m else None
    return rec


def extract_photo(path):
    # Aliran gambar JPEG (DCTDecode) tersimpan apa adanya di dalam PDF, jadi cukup diiris.
    b = open(path, 'rb').read()
    best = None
    for m in re.finditer(rb'<<([^>]*?/Subtype\s*/Image[^>]*?)>>\s*stream\r?\n', b, re.S):
        d = m.group(1)
        if b'DCTDecode' not in d:
            continue
        w = re.search(rb'/Width\s+(\d+)', d)
        h = re.search(rb'/Height\s+(\d+)', d)
        if not w or not h:
            continue
        w, h = int(w.group(1)), int(h.group(1))
        if (w, h) == LOGO_SIZE:
            continue
        start = m.end()
        end = b.find(b'endstream', start)
        if end < 0:
            continue
        data = b[start:end].rstrip(b'\r\n')
        if data[:2] != b'\xff\xd8' or data[-2:] != b'\xff\xd9':
            continue
        if best is None or w * h > best[0]:
            best = (w * h, data)
    return best[1] if best else None


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    meta_path = None
    if '--meta' in sys.argv:
        meta_path = sys.argv[sys.argv.index('--meta') + 1]
        args = [a for a in args if a != meta_path]
    if len(args) != 2:
        print(__doc__)
        sys.exit(1)
    src, out = args
    os.makedirs(os.path.join(out, 'photos'), exist_ok=True)

    meta = {}
    if meta_path:
        for fid, title, mod in json.load(open(meta_path, encoding='utf-8')):
            meta[fid] = (title, mod)

    records = []
    files = sorted(f for f in os.listdir(src) if f.lower().endswith('.pdf'))
    for n, f in enumerate(files, 1):
        path = os.path.join(src, f)
        rec = parse_fields(pdf_text(path))
        fid = os.path.splitext(f)[0]
        rec['source'] = meta[fid][0] if fid in meta else f
        rec['modified'] = meta[fid][1] if fid in meta else None
        rec['source_id'] = fid
        jpg = extract_photo(path)
        rec['photo'] = None
        if jpg:
            name = 'photos/' + hashlib.sha1(fid.encode()).hexdigest()[:16] + '.jpg'
            open(os.path.join(out, name), 'wb').write(jpg)
            rec['photo'] = name
        records.append(rec)

    json.dump(records, open(os.path.join(out, 'biodata.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('%d formulir, %d berfoto -> %s' % (len(records), sum(1 for r in records if r['photo']), os.path.join(out, 'biodata.json')))


if __name__ == '__main__':
    main()
