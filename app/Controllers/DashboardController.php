<?php
// PATH LOKASI FILE: app/Controllers/DashboardController.php

class DashboardController extends Controller {
    // Warna grafik. Dua seri berbeda identitas (jenis kelamin) memakai --viz-1 dan --viz-2; tingkat
    // struktur berurutan (Pusat -> Ranting) jadi memakai ramp satu hue --ord-1..5. Nilainya
    // didefinisikan di app.css dan sudah divalidasi dengan validate_palette.js.
    private const GENDER_COLORS = ['L' => 'var(--viz-1)', 'P' => 'var(--viz-2)'];

    public function index() {
        $this->requireLogin();

        $dash = $this->model('DashboardModel');
        $stats = $dash->stats();

        // ---- Komposisi per tingkat (donat, ramp ordinal) ----
        $tierItems = [];
        foreach ($dash->byTier() as $row) {
            $tier = (int) $row['tier'];
            $tierItems[] = [
                'label' => Ui::tierName($tier),
                'value' => (int) $row['c'],
                'color' => 'var(--ord-' . max(1, min(5, $tier)) . ')',
            ];
        }
        $tierDonut = Chart::donut($tierItems, Ui::n($stats['pengurus_aktif']), 'pengurus aktif', 'orang',
                                  'Pengurus aktif per tingkat struktur');

        // ---- Jenis kelamin (satu batang bertumpuk; donat 2 irisan dihindari) ----
        $genderSegs = [];
        foreach ($dash->byGender() as $row) {
            $genderSegs[] = [
                'label' => $row['gender'] === 'P' ? 'Perempuan' : 'Laki-laki',
                'value' => (int) $row['c'],
                'color' => self::GENDER_COLORS[$row['gender']] ?? 'var(--viz-1)',
            ];
        }
        $genderBar = Chart::stacked($genderSegs, 'orang', 'Pengurus aktif menurut jenis kelamin');

        // ---- Pengurus per struktur (batang horizontal, satu warna) ----
        $structRows = [];
        foreach ($dash->topStructures(8) as $row) {
            $structRows[] = [
                'label' => trim($row['level'] . ' ' . $row['name']),
                'value' => (int) $row['c'],
                'href'  => BASEURL . '/terms?structure=' . (int) $row['id'],
            ];
        }
        $structBars = Chart::hbar($structRows, 'orang', 'Pengurus aktif per struktur');

        // ---- Penempatan dicatat per bulan (area, satu seri) ----
        $areaChart = Chart::area($dash->monthlyPlacements(), 'penempatan', 'Penempatan yang dicatat per bulan, 12 bulan terakhir');

        $this->view('admin/dashboard', [
            'title'        => 'Dasbor',
            'stats'        => $stats,
            'tierDonut'    => $tierDonut,
            'genderBar'    => $genderBar,
            'structBars'   => $structBars,
            'areaChart'    => $areaChart,
            'recentPeople' => $dash->recentPeople(6),
            'endings'      => $dash->termEndings(5),
            'pending'      => Auth::canVerify() ? $dash->pendingRegistrations(4) : [],
            'quality'      => $dash->quality(),
            'activity'     => $dash->activity(7),
        ]);
    }
}
