<?php
// Sidebar template for the attendance system
// This file should be included after the header

// Determine active menu based on current page
$current_page = basename($_SERVER['PHP_SELF']);

// Define menu items based on user level
$user_level = getUserLevel();
$menu_items = [];

// Helper function to sort menu items alphabetically, keeping Dashboard first and Logout last
if (!function_exists('sort_all_menu_items')) {
    function sort_all_menu_items(&$items) {
        if (empty($items)) return;
        
        $dashboard = null;
        $logout = null;
        $middle = [];
        $seen_titles = [];

        foreach ($items as $item) {
            $title = isset($item['title']) ? trim(strip_tags($item['title'])) : 'Untitled';
            
            // Normalize title for deduplication (e.g. Profil & Pengaturan vs Profil &amp; Pengaturan)
            $normalized_title = html_entity_decode($title);
            
            if (isset($seen_titles[$normalized_title])) continue;
            $seen_titles[$normalized_title] = true;

            if (strcasecmp($normalized_title, 'Dashboard') === 0) {
                $dashboard = $item;
            } elseif (strcasecmp($normalized_title, 'Logout') === 0) {
                $logout = $item;
            } else {
                if (isset($item['submenu']) && is_array($item['submenu']) && (strpos($normalized_title, 'Absensi') !== false)) {
                    usort($item['submenu'], function($a, $b) {
                        return strcasecmp(trim(strip_tags($a['title'])), trim(strip_tags($b['title'])));
                    });
                }
                $middle[] = $item;
            }
        }
        
        // No auto-sorting for middle items (revert to original order)
        
        // Reconstruct
        $new_items = [];
        if ($dashboard) $new_items[] = $dashboard;
        foreach ($middle as $m) $new_items[] = $m;
        if ($logout) $new_items[] = $logout;
        
        $items = $new_items;
    }
}

switch ($user_level) {
    case 'admin':
        $absensi_submenu_admin = [
            ['title' => 'Absensi Guru', 'url' => '../admin/absensi_guru.php', 'active' => $current_page === 'absensi_guru.php'],
            ['title' => 'Absensi Les Guru', 'url' => '../admin/absensi_les_guru.php', 'active' => $current_page === 'absensi_les_guru.php'],
            ['title' => 'Absensi Les Siswa', 'url' => '../admin/absensi_les_siswa.php', 'active' => $current_page === 'absensi_les_siswa.php'],
            ['title' => 'Absensi Sholat Berjamaah', 'url' => '../admin/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
            ['title' => 'Absensi Sholat Dhuha', 'url' => '../admin/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
            ['title' => 'Absensi Siswa', 'url' => '../admin/absensi_harian.php', 'active' => $current_page === 'absensi_harian.php'],
            ['title' => 'Rekap Absensi Guru', 'url' => '../admin/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
            ['title' => 'Rekap Absensi Les', 'url' => '../admin/rekap_absensi_les_siswa.php', 'active' => $current_page === 'rekap_absensi_les_siswa.php'],
            ['title' => 'Rekap Absensi Les Guru', 'url' => '../admin/rekap_absensi_les_guru.php', 'active' => $current_page === 'rekap_absensi_les_guru.php'],
            ['title' => 'Rekap Absensi Siswa', 'url' => '../admin/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
            ['title' => 'Rekap Sholat Berjamaah', 'url' => '../admin/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../admin/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php'],
            ['title' => 'Scan Absensi', 'url' => '../admin/scan_qr.php', 'active' => $current_page === 'scan_qr.php']
        ];

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../admin/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Master Data',
                'icon' => 'fas fa-database',
                'submenu' => [
                    ['title' => 'Data Guru', 'url' => '../admin/data_guru.php', 'active' => $current_page === 'data_guru.php'],
                    ['title' => 'Data Kelas', 'url' => '../admin/data_kelas.php', 'active' => $current_page === 'data_kelas.php'],
                    ['title' => 'Data Siswa', 'url' => '../admin/data_siswa.php', 'active' => $current_page === 'data_siswa.php'],
                    ['title' => 'Data Siswa Baru', 'url' => '../admin/siswa_baru.php', 'active' => $current_page === 'siswa_baru.php'],
                    ['title' => 'Data Alumni', 'url' => '../admin/data_alumni.php', 'active' => $current_page === 'data_alumni.php'],
                    ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Jam Mengajar', 'url' => '../admin/jam_mengajar.php', 'active' => $current_page === 'jam_mengajar.php'],
                    ['title' => 'Kenaikan Kelas', 'url' => '../admin/kenaikan_kelas.php', 'active' => $current_page === 'kenaikan_kelas.php'],
                    ['title' => 'Kalender Pendidikan', 'url' => '../admin/kalender_pendidikan.php', 'active' => $current_page === 'kalender_pendidikan.php']
                ],
                'active' => in_array($current_page, ['data_guru.php', 'data_kelas.php', 'data_siswa.php', 'siswa_baru.php', 'data_alumni.php', 'mata_pelajaran.php', 'jam_mengajar.php', 'kenaikan_kelas.php', 'kalender_pendidikan.php'])
            ],
            [
                'title' => 'Inventaris Sarpras',
                'icon' => 'fas fa-boxes',
                'submenu' => [
                    ['title' => 'Kategori Inventaris', 'url' => '../admin/kategori_inventaris.php', 'active' => $current_page === 'kategori_inventaris.php'],
                    ['title' => 'Data Inventaris Sarpras', 'url' => '../admin/data_inventaris.php', 'active' => $current_page === 'data_inventaris.php']
                ],
                'active' => in_array($current_page, ['kategori_inventaris.php', 'data_inventaris.php'])
            ],
            [
                'title' => 'Keuangan',
                'icon' => 'fas fa-money-bill-wave',
                'submenu' => [
                    ['title' => 'Kategori Anggaran', 'url' => '../admin/kategori_anggaran.php', 'active' => $current_page === 'kategori_anggaran.php'],
                    ['title' => 'RAB Madrasah', 'url' => '../admin/rab_madrasah.php', 'active' => $current_page === 'rab_madrasah.php'],
                    ['title' => 'RAB Ekstrakurikuler', 'url' => '../admin/rab_ekstrakurikuler.php', 'active' => $current_page === 'rab_ekstrakurikuler.php'],
                    ['title' => 'RAB Ujian', 'url' => '../admin/rab_ujian.php', 'active' => $current_page === 'rab_ujian.php']
                ],
                'active' => in_array($current_page, ['kategori_anggaran.php', 'rab_madrasah.php', 'rab_ekstrakurikuler.php', 'rab_ujian.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_admin,
                'active' => in_array($current_page, ['scan_qr.php', 'absensi_guru.php', 'rekap_absensi_guru.php', 'absensi_harian.php', 'absensi_les_siswa.php', 'rekap_absensi.php', 'rekap_absensi_les_siswa.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-chart-bar',
                'submenu' => [
                    ['title' => 'Nilai Harian', 'url' => '../admin/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
                    ['title' => 'Nilai Tengah Semester', 'url' => '../admin/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
                    ['title' => 'Nilai Akhir Semester', 'url' => '../admin/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
                    ['title' => 'Nilai Akhir Tahun', 'url' => '../admin/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
                    ['title' => 'Nilai Kokurikuler', 'url' => '../admin/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php'],
                    ['title' => 'Nilai Pra Ujian', 'url' => '../admin/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'],
                    ['title' => 'Nilai Ujian', 'url' => '../admin/nilai_ujian.php', 'active' => $current_page === 'nilai_ujian.php'],
                    ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php', 'active' => $current_page === 'data_nilai_ujian.php'],
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'data_nilai_ujian.php', 'rekap_nilai.php'])
            ],

            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../admin/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../admin/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Les', 'url' => '../admin/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php', 'active' => $current_page === 'jadwal_imam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_les.php', 'jadwal_imam.php'])
            ],
            [
                'title' => 'Jurnal',
                'icon' => 'fas fa-book-open',
                'submenu' => [
                    ['title' => 'Jurnal Mengajar', 'url' => '../admin/jurnal_mengajar.php', 'active' => $current_page === 'jurnal_mengajar.php'],
                    ['title' => 'Jurnal Les', 'url' => '../admin/jurnal_les.php', 'active' => $current_page === 'jurnal_les.php']
                ],
                'active' => in_array($current_page, ['jurnal_mengajar.php', 'jurnal_les.php'])
            ],
            [
                'title' => 'Pengaturan',
                'icon' => 'fas fa-school',
                'url' => '../admin/profil_madrasah.php',
                'active' => $current_page === 'profil_madrasah.php'
            ],
            [
                'title' => 'Pengguna',
                'icon' => 'fas fa-users',
                'url' => '../admin/pengguna.php',
                'active' => $current_page === 'pengguna.php'
            ],
            [
                'title' => 'Backup & Restore',
                'icon' => 'fas fa-hdd',
                'url' => '../admin/backup_restore.php',
                'active' => $current_page === 'backup_restore.php'
            ],
            [
                'title' => 'Logout',
                'icon' => 'fas fa-sign-out-alt',
                'url' => '#',
                'active' => false,
                'attributes' => 'onclick="confirmLogoutInline(); return false;"'
            ]
        ];
        break;

    case 'kepala_madrasah':
        $rekap_absensi_submenu_kepala = [
            ['title' => 'Rekap Absensi Guru', 'url' => '../kepala/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
            ['title' => 'Rekap Absensi Les', 'url' => '../admin/rekap_absensi_les_siswa.php?session_type=kepala_madrasah', 'active' => $current_page === 'rekap_absensi_les_siswa.php'],
            ['title' => 'Rekap Absensi Les Guru', 'url' => '../admin/rekap_absensi_les_guru.php?session_type=kepala_madrasah', 'active' => $current_page === 'rekap_absensi_les_guru.php'],
            ['title' => 'Rekap Absensi Siswa', 'url' => '../kepala/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
            ['title' => 'Rekap Sholat Berjamaah', 'url' => '../kepala/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../kepala/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
        ];

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../kepala/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Kalender Pendidikan',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../admin/kalender_pendidikan.php?session_type=kepala_madrasah',
                'active' => $current_page === 'kalender_pendidikan.php'
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../kepala/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../kepala/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Les Kelas 6', 'url' => '../kepala/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=kepala_madrasah', 'active' => $current_page === 'jadwal_imam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_les.php', 'jadwal_imam.php'])
            ],
            [
                'title' => 'Keuangan',
                'icon' => 'fas fa-money-bill-wave',
                'submenu' => [
                    ['title' => 'RAB Madrasah', 'url' => '../admin/rab_madrasah.php', 'active' => $current_page === 'rab_madrasah.php'],
                    ['title' => 'RAB Ekstrakurikuler', 'url' => '../admin/rab_ekstrakurikuler.php', 'active' => $current_page === 'rab_ekstrakurikuler.php'],
                    ['title' => 'RAB Ujian', 'url' => '../admin/rab_ujian.php', 'active' => $current_page === 'rab_ujian.php']
                ],
                'active' => in_array($current_page, ['rab_madrasah.php', 'rab_ekstrakurikuler.php', 'rab_ujian.php'])
            ],
            [
                'title' => 'Rekap Absensi',
                'icon' => 'fas fa-file-alt',
                'submenu' => $rekap_absensi_submenu_kepala,
                'active' => in_array($current_page, ['rekap_absensi_guru.php', 'rekap_absensi.php', 'rekap_sholat.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-chart-bar',
                'submenu' => [
                    ['title' => 'Nilai Harian', 'url' => '../admin/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
                    ['title' => 'Nilai Tengah Semester', 'url' => '../admin/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
                    ['title' => 'Nilai Akhir Semester', 'url' => '../admin/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
                    ['title' => 'Nilai Akhir Tahun', 'url' => '../admin/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
                    ['title' => 'Nilai Kokurikuler', 'url' => '../admin/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php'],
                    ['title' => 'Nilai Pra Ujian', 'url' => '../admin/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'],
                    ['title' => 'Nilai Ujian', 'url' => '../admin/nilai_ujian.php', 'active' => $current_page === 'nilai_ujian.php'],
                    ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_nilai_ujian.php'],
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'data_nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Jurnal',
                'icon' => 'fas fa-book-open',
                'submenu' => [
                    ['title' => 'Jurnal Mengajar', 'url' => '../kepala/jurnal_mengajar.php', 'active' => $current_page === 'jurnal_mengajar.php'],
                    ['title' => 'Jurnal Les', 'url' => '../admin/jurnal_les.php?session_type=kepala_madrasah', 'active' => $current_page === 'jurnal_les.php']
                ],
                'active' => in_array($current_page, ['jurnal_mengajar.php', 'jurnal_les.php'])
            ],
            [
                'title' => 'Logout',
                'icon' => 'fas fa-sign-out-alt',
                'url' => '#',
                'active' => false,
                'attributes' => 'onclick="confirmLogoutInline(); return false;"'
            ]
        ];
        break;
        
    case 'tata_usaha':
        $absensi_submenu_tu = [
            ['title' => 'Absensi Guru', 'url' => '../admin/absensi_guru.php', 'active' => $current_page === 'absensi_guru.php'],
            ['title' => 'Absensi Sholat Berjamaah', 'url' => '../admin/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
            ['title' => 'Absensi Sholat Dhuha', 'url' => '../admin/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
            ['title' => 'Absensi Siswa', 'url' => '../admin/absensi_harian.php', 'active' => $current_page === 'absensi_harian.php'],
            ['title' => 'Rekap Absensi Guru', 'url' => '../admin/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
            ['title' => 'Rekap Absensi Siswa', 'url' => '../admin/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
            ['title' => 'Rekap Sholat Berjamaah', 'url' => '../admin/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../admin/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php'],
            ['title' => 'Scan Absensi', 'url' => '../admin/scan_qr.php', 'active' => $current_page === 'scan_qr.php']
        ];

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../tata_usaha/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Kalender Pendidikan',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../admin/kalender_pendidikan.php?session_type=' . $_SESSION['level'],
                'active' => $current_page === 'kalender_pendidikan.php'
            ],
            [
                'title' => 'Data Siswa Baru',
                'icon' => 'fas fa-users',
                'url' => '../admin/siswa_baru.php?session_type=' . $_SESSION['level'],
                'active' => $current_page === 'siswa_baru.php'
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../tata_usaha/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../tata_usaha/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=tata_usaha', 'active' => $current_page === 'jadwal_imam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_imam.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_tu,
                'active' => in_array($current_page, ['scan_qr.php', 'absensi_guru.php', 'rekap_absensi_guru.php', 'absensi_harian.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-chart-bar',
                'submenu' => [
                    ['title' => 'Nilai Harian', 'url' => '../admin/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
                    ['title' => 'Nilai Tengah Semester', 'url' => '../admin/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
                    ['title' => 'Nilai Akhir Semester', 'url' => '../admin/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
                    ['title' => 'Nilai Akhir Tahun', 'url' => '../admin/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
                    ['title' => 'Nilai Kokurikuler', 'url' => '../admin/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php'],
                    ['title' => 'Nilai Pra Ujian', 'url' => '../admin/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'],
                    ['title' => 'Nilai Ujian', 'url' => '../admin/nilai_ujian.php', 'active' => $current_page === 'nilai_ujian.php'],
                    ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php?session_type=tata_usaha', 'active' => $current_page === 'data_nilai_ujian.php'],
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'data_nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Keuangan',
                'icon' => 'fas fa-money-bill-wave',
                'submenu' => [
                    ['title' => 'RAB Madrasah', 'url' => '../admin/rab_madrasah.php', 'active' => $current_page === 'rab_madrasah.php'],
                    ['title' => 'RAB Ekstrakurikuler', 'url' => '../admin/rab_ekstrakurikuler.php', 'active' => $current_page === 'rab_ekstrakurikuler.php'],
                    ['title' => 'RAB Ujian', 'url' => '../admin/rab_ujian.php', 'active' => $current_page === 'rab_ujian.php']
                ],
                'active' => in_array($current_page, ['rab_madrasah.php', 'rab_ekstrakurikuler.php', 'rab_ujian.php'])
            ],
            [
                'title' => 'Jurnal',
                'icon' => 'fas fa-book-open',
                'submenu' => [
                    ['title' => 'Jurnal Mengajar', 'url' => '../admin/jurnal_mengajar.php?session_type=tata_usaha', 'active' => $current_page === 'jurnal_mengajar.php'],
                    ['title' => 'Jurnal Les', 'url' => '../admin/jurnal_les.php?session_type=tata_usaha', 'active' => $current_page === 'jurnal_les.php']
                ],
                'active' => in_array($current_page, ['jurnal_mengajar.php', 'jurnal_les.php'])
            ],
            [
                'title' => 'Backup & Restore',
                'icon' => 'fas fa-hdd',
                'url' => '../admin/backup_restore.php',
                'active' => $current_page === 'backup_restore.php'
            ],
            [
                'title' => 'Logout',
                'icon' => 'fas fa-sign-out-alt',
                'url' => '#',
                'active' => false,
                'attributes' => 'onclick="confirmLogoutInline(); return false;"'
            ]
        ];
        break;

    case 'guru':
        $is_grade_6_guru = false;
        if (isset($_SESSION['user_id'])) {
            $id_guru_check = $_SESSION['user_id'];
            if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
                $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
                $stmt_uid->execute([$_SESSION['user_id']]);
                $id_guru_check = $stmt_uid->fetchColumn();
            }
            
            if ($id_guru_check) {
                $stmt_g = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
                $stmt_g->execute([$id_guru_check]);
                $mengajar_json = $stmt_g->fetchColumn();
                $mengajar_arr = json_decode($mengajar_json, true) ?? [];
                
                if (!empty($mengajar_arr)) {
                    // Check if any class is grade 6
                    $placeholders = str_repeat('?,', count($mengajar_arr) - 1) . '?';
                    // We need to check both IDs and Names because mengajar might contain either
                    // Ideally we fetch all classes and check
                    // For simplicity, let's fetch classes that match IDs or Names
                    $params = array_merge($mengajar_arr, $mengajar_arr);
                    $stmt_cls = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas IN ($placeholders) OR nama_kelas IN ($placeholders)");
                    $stmt_cls->execute($params);
                    $classes_taught = $stmt_cls->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($classes_taught as $nk) {
                        $nk = strtoupper($nk);
                        if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false) {
                            $is_grade_6_guru = true;
                            break;
                        }
                    }
                }
            }
        }

        $nilai_submenu_guru = [
            ['title' => 'Nilai Harian', 'url' => '../guru/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
            ['title' => 'Nilai Tengah Semester', 'url' => '../guru/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
            ['title' => 'Nilai Akhir Semester', 'url' => '../guru/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
            ['title' => 'Nilai Akhir Tahun', 'url' => '../guru/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
            ['title' => 'Nilai Kokurikuler', 'url' => '../guru/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php']
        ];

        if ($is_grade_6_guru) {
            $nilai_submenu_guru[] = ['title' => 'Nilai Pra Ujian', 'url' => '../guru/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'];
            $nilai_submenu_guru[] = ['title' => 'Nilai Ujian', 'url' => '../guru/nilai_ujian.php', 'active' => $current_page === 'nilai_ujian.php'];
        }
        
        // Menu Rekap Nilai untuk semua guru
        $nilai_submenu_guru[] = ['title' => 'Rekap Nilai', 'url' => '../guru/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php'];
        
        $nilai_urls_guru = array_map(function($item) {
            return basename($item['url']);
        }, $nilai_submenu_guru);

        $absensi_submenu_guru = [
            ['title' => 'Absensi Harian', 'url' => '../guru/absensi_kelas.php', 'active' => $current_page === 'absensi_kelas.php'],
            ['title' => 'Absensi Sholat Berjamaah', 'url' => '../guru/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
            ['title' => 'Absensi Sholat Dhuha', 'url' => '../guru/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
            ['title' => 'Rekap Absensi Harian', 'url' => '../guru/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
            ['title' => 'Rekap Sholat Berjamaah', 'url' => '../guru/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../guru/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
        ];

        if ($is_grade_6_guru) {
            $absensi_submenu_guru[] = ['title' => 'Absensi Les Guru', 'url' => '../guru/absensi_les_guru.php', 'active' => $current_page === 'absensi_les_guru.php'];
            $absensi_submenu_guru[] = ['title' => 'Absensi Les Siswa', 'url' => '../admin/absensi_les_siswa.php?session_type=guru', 'active' => $current_page === 'absensi_les_siswa.php'];
            $absensi_submenu_guru[] = ['title' => 'Rekap Absensi Les', 'url' => '../admin/rekap_absensi_les_siswa.php?session_type=guru', 'active' => $current_page === 'rekap_absensi_les_siswa.php'];
            $absensi_submenu_guru[] = ['title' => 'Rekap Absensi Les Guru', 'url' => '../admin/rekap_absensi_les_guru.php?session_type=guru', 'active' => $current_page === 'rekap_absensi_les_guru.php'];
        }

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../guru/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_guru,
                'active' => in_array($current_page, ['absensi_kelas.php', 'absensi_les_guru.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => $nilai_submenu_guru,
                'active' => in_array($current_page, $nilai_urls_guru)
            ],
            [
                'title' => 'Keuangan',
                'icon' => 'fas fa-money-bill-wave',
                'submenu' => [
                    ['title' => 'RAB Madrasah', 'url' => '../admin/rab_madrasah.php', 'active' => $current_page === 'rab_madrasah.php'],
                    ['title' => 'RAB Ekstrakurikuler', 'url' => '../admin/rab_ekstrakurikuler.php', 'active' => $current_page === 'rab_ekstrakurikuler.php'],
                    ['title' => 'RAB Ujian', 'url' => '../admin/rab_ujian.php', 'active' => $current_page === 'rab_ujian.php']
                ],
                'active' => in_array($current_page, ['rab_madrasah.php', 'rab_ekstrakurikuler.php', 'rab_ujian.php'])
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../guru/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../guru/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=guru', 'active' => $current_page === 'jadwal_imam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_imam.php'])
            ],
            [
                'title' => 'Kalender Pendidikan',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../admin/kalender_pendidikan.php?session_type=guru',
                'active' => $current_page === 'kalender_pendidikan.php'
            ]
        ];

        // Jurnal menu for all teachers
        $jurnal_submenu_guru = [
            ['title' => 'Jurnal Mengajar', 'url' => '../guru/jurnal_mengajar.php', 'active' => $current_page === 'jurnal_mengajar.php']
        ];
        if ($is_grade_6_guru) {
            $jurnal_submenu_guru[] = ['title' => 'Jurnal Les', 'url' => '../guru/jurnal_les.php', 'active' => $current_page === 'jurnal_les.php'];
        }
        
        array_splice($menu_items, 4, 0, [[
            'title' => 'Jurnal',
            'icon' => 'fas fa-book-open',
            'submenu' => $jurnal_submenu_guru,
            'active' => in_array($current_page, ['jurnal_mengajar.php', 'jurnal_les.php'])
        ]]);

        if ($is_grade_6_guru) {
            // Add Jadwal Les into Jadwal submenu for Grade 6 Teachers
            foreach ($menu_items as &$m_item) {
                if ($m_item['title'] === 'Jadwal') {
                    $m_item['submenu'][] = ['title' => 'Jadwal Les Kelas 6', 'url' => '../guru/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'];
                    break;
                }
            }
        }

        $menu_items[] = [
            'title' => 'Profil',
            'icon' => 'fas fa-user',
            'url' => '../guru/profil.php',
            'active' => $current_page === 'profil.php'
        ];
        $menu_items[] = [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '#',
            'active' => false,
            'attributes' => 'onclick="confirmLogoutInline(); return false;"'
        ];
        break;
        
    case 'wali':
        $is_grade_6 = false;
        
        // Cek jika Wali Kelas 6
        if (isset($_SESSION['nama_guru'])) {
            $stmt_cls = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
            $stmt_cls->execute([$_SESSION['nama_guru']]);
            $cls = $stmt_cls->fetch(PDO::FETCH_ASSOC);
            if ($cls) {
                $nk = strtoupper($cls['nama_kelas']);
                if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false) {
                    $is_grade_6 = true;
                }
            }
        }

        // Cek jika Guru Mapel Kelas 6 (Wali juga Guru)
        if (!$is_grade_6 && isset($_SESSION['user_id'])) {
            $id_guru_check = $_SESSION['user_id'];
            if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
                $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
                $stmt_uid->execute([$_SESSION['user_id']]);
                $id_guru_check = $stmt_uid->fetchColumn();
            }
            
            if ($id_guru_check) {
                $stmt_g = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
                $stmt_g->execute([$id_guru_check]);
                $mengajar_json = $stmt_g->fetchColumn();
                $mengajar_arr = json_decode($mengajar_json, true) ?? [];
                
                if (!empty($mengajar_arr)) {
                    $placeholders = str_repeat('?,', count($mengajar_arr) - 1) . '?';
                    $params = array_merge($mengajar_arr, $mengajar_arr);
                    $stmt_cls = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas IN ($placeholders) OR nama_kelas IN ($placeholders)");
                    $stmt_cls->execute($params);
                    $classes_taught = $stmt_cls->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($classes_taught as $nk) {
                        $nk = strtoupper($nk);
                        if (strpos($nk, '6') !== false || strpos($nk, 'VI') !== false) {
                            $is_grade_6 = true;
                            break;
                        }
                    }
                }
            }
        }
        
        // Gunakan file guru agar wali bisa input nilai sebagai guru mapel
        $nilai_submenu = [
             ['title' => 'Nilai Harian', 'url' => '../guru/nilai_harian.php?session_type=wali', 'active' => $current_page === 'nilai_harian.php'],
             ['title' => 'Nilai Tengah Semester', 'url' => '../guru/nilai_uts.php?session_type=wali', 'active' => $current_page === 'nilai_uts.php'],
             ['title' => 'Nilai Akhir Semester', 'url' => '../guru/nilai_uas.php?session_type=wali', 'active' => $current_page === 'nilai_uas.php'],
             ['title' => 'Nilai Akhir Tahun', 'url' => '../guru/nilai_pat.php?session_type=wali', 'active' => $current_page === 'nilai_pat.php'],
             ['title' => 'Nilai Kokurikuler', 'url' => '../guru/nilai_kokurikuler.php?session_type=wali', 'active' => $current_page === 'nilai_kokurikuler.php']
        ];
        
        if ($is_grade_6) {
             $nilai_submenu[] = ['title' => 'Nilai Pra Ujian', 'url' => '../guru/nilai_pra_ujian.php?session_type=wali', 'active' => $current_page === 'nilai_pra_ujian.php'];
             $nilai_submenu[] = ['title' => 'Nilai Ujian', 'url' => '../guru/nilai_ujian.php?session_type=wali', 'active' => $current_page === 'nilai_ujian.php'];
        }

        // Menu Rekap Nilai untuk wali kelas
        $nilai_submenu[] = ['title' => 'Rekap Nilai', 'url' => '../guru/rekap_nilai.php?session_type=wali', 'active' => $current_page === 'rekap_nilai.php'];
        
        // Data Nilai Ujian hanya untuk wali kelas 6
        if ($is_grade_6) {
            $nilai_submenu[] = ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php?session_type=wali', 'active' => $current_page === 'data_nilai_ujian.php'];
        }
        
        $nilai_urls = array_map(function($item) {
            return basename($item['url']);
        }, $nilai_submenu);

        $absensi_submenu_wali = [
            ['title' => 'Absensi Harian', 'url' => '../wali/absensi_kelas.php', 'active' => $current_page === 'absensi_kelas.php'],
            ['title' => 'Absensi Sholat Berjamaah', 'url' => '../wali/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
            ['title' => 'Absensi Sholat Dhuha', 'url' => '../wali/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
            ['title' => 'Rekap Absensi', 'url' => '../wali/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
            ['title' => 'Rekap Sholat Berjamaah', 'url' => '../wali/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../wali/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
        ];

        if ($is_grade_6) {
            $absensi_submenu_wali[] = ['title' => 'Absensi Les Guru', 'url' => '../wali/absensi_les_guru.php', 'active' => $current_page === 'absensi_les_guru.php'];
            $absensi_submenu_wali[] = ['title' => 'Absensi Les Siswa', 'url' => '../admin/absensi_les_siswa.php?session_type=wali', 'active' => $current_page === 'absensi_les_siswa.php'];
            $absensi_submenu_wali[] = ['title' => 'Rekap Absensi Les', 'url' => '../admin/rekap_absensi_les_siswa.php?session_type=wali', 'active' => $current_page === 'rekap_absensi_les_siswa.php'];
            $absensi_submenu_wali[] = ['title' => 'Rekap Absensi Les Guru', 'url' => '../admin/rekap_absensi_les_guru.php?session_type=wali', 'active' => $current_page === 'rekap_absensi_les_guru.php'];
        }

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../wali/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../wali/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../wali/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=wali', 'active' => $current_page === 'jadwal_imam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_imam.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_wali,
                'active' => in_array($current_page, ['absensi_kelas.php', 'absensi_les_guru.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => $nilai_submenu,
                'active' => in_array($current_page, $nilai_urls)
            ],
            [
                'title' => 'Keuangan',
                'icon' => 'fas fa-money-bill-wave',
                'submenu' => [
                    ['title' => 'RAB Madrasah', 'url' => '../admin/rab_madrasah.php', 'active' => $current_page === 'rab_madrasah.php'],
                    ['title' => 'RAB Ekstrakurikuler', 'url' => '../admin/rab_ekstrakurikuler.php', 'active' => $current_page === 'rab_ekstrakurikuler.php'],
                    ['title' => 'RAB Ujian', 'url' => '../admin/rab_ujian.php', 'active' => $current_page === 'rab_ujian.php']
                ],
                'active' => in_array($current_page, ['rab_madrasah.php', 'rab_ekstrakurikuler.php', 'rab_ujian.php'])
            ],
            [
                'title' => 'Kalender Pendidikan',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../admin/kalender_pendidikan.php?session_type=wali',
                'active' => $current_page === 'kalender_pendidikan.php'
            ]
        ];

        // Jurnal menu for all wali
        $jurnal_submenu_wali = [
            ['title' => 'Jurnal Mengajar', 'url' => '../wali/jurnal_mengajar.php', 'active' => $current_page === 'jurnal_mengajar.php']
        ];
        if ($is_grade_6) {
            $jurnal_submenu_wali[] = ['title' => 'Jurnal Les', 'url' => '../wali/jurnal_les.php', 'active' => $current_page === 'jurnal_les.php'];
        }

        array_splice($menu_items, 5, 0, [[
            'title' => 'Jurnal',
            'icon' => 'fas fa-book-open',
            'submenu' => $jurnal_submenu_wali,
            'active' => in_array($current_page, ['jurnal_mengajar.php', 'jurnal_les.php'])
        ]]);

        if ($is_grade_6) {
            // Add Jadwal Les into Jadwal submenu for Grade 6 Wali
            foreach ($menu_items as &$m_item) {
                if ($m_item['title'] === 'Jadwal') {
                    $m_item['submenu'][] = ['title' => 'Jadwal Les Kelas 6', 'url' => '../wali/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'];
                    break;
                }
            }
        }

        $menu_items[] = [
            'title' => 'Profil & Pengaturan',
            'icon' => 'fas fa-user-cog',
            'url' => '../wali/profil.php',
            'active' => $current_page === 'profil.php'
        ];
        $menu_items[] = [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '#',
            'active' => false,
            'attributes' => 'onclick="confirmLogoutInline(); return false;"'
        ];
        break;

    case 'siswa':
        // Check for Grade 6
        $is_grade_6_siswa = false;
        if (isset($_SESSION['user_id'])) {
            $stmt_cls = $pdo->prepare("SELECT k.nama_kelas FROM tb_siswa s JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ?");
            $stmt_cls->execute([$_SESSION['user_id']]);
            $cls_name = $stmt_cls->fetchColumn();
            if ($cls_name) {
                $cls_name = strtoupper($cls_name);
                if (strpos($cls_name, '6') !== false || strpos($cls_name, 'VI') !== false) {
                    $is_grade_6_siswa = true;
                }
            }
        }

        $nilai_submenu_siswa = [
            ['title' => 'Rekap Nilai', 'url' => '../siswa/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
        ];

        if ($is_grade_6_siswa) {
            $nilai_submenu_siswa[] = ['title' => 'Nilai Pra Ujian', 'url' => '../siswa/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'];
            $nilai_submenu_siswa[] = ['title' => 'Nilai Ujian', 'url' => '../siswa/nilai_ujian.php', 'active' => $current_page === 'nilai_ujian.php'];
        }

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../siswa/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Pelajaran', 'url' => '../siswa/jadwal_pelajaran.php', 'active' => $current_page === 'jadwal_pelajaran.php']
                ],
                'active' => in_array($current_page, ['jadwal_pelajaran.php', 'jadwal_les.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-book',
                'submenu' => $nilai_submenu_siswa,
                'active' => in_array($current_page, ['rekap_nilai.php', 'nilai_pra_ujian.php', 'nilai_ujian.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    ['title' => 'Rekap Absensi Harian', 'url' => '../siswa/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
                    ['title' => 'Rekap Sholat Berjamaah', 'url' => '../siswa/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
                    ['title' => 'Rekap Sholat Dhuha', 'url' => '../siswa/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
                ],
                'active' => in_array($current_page, ['rekap_absensi.php', 'rekap_sholat.php', 'rekap_sholat_dhuha.php', 'rekap_absensi_les.php'])
            ],
            [
                'title' => 'Kalender Pendidikan',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../admin/kalender_pendidikan.php?session_type=siswa',
                'active' => $current_page === 'kalender_pendidikan.php'
            ]
        ];

        if ($is_grade_6_siswa) {
            // Add Jadwal Les into Jadwal submenu for Grade 6 Students
            foreach ($menu_items as &$m_item) {
                if ($m_item['title'] === 'Jadwal') {
                    $m_item['submenu'][] = ['title' => 'Jadwal Les Kelas 6', 'url' => '../siswa/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'];
                }
                if ($m_item['title'] === 'Absensi') {
                    $m_item['submenu'][] = ['title' => 'Rekap Absensi Les', 'url' => '../siswa/rekap_absensi_les.php', 'active' => $current_page === 'rekap_absensi_les.php'];
                }
            }

            // Add Biaya Ujian menu for Grade 6 Students
            array_splice($menu_items, 5, 0, [[
                'title' => 'Biaya Ujian',
                'icon' => 'fas fa-money-bill-wave',
                'url' => '../siswa/biaya_ujian.php',
                'active' => $current_page === 'biaya_ujian.php'
            ]]);
        }

        $menu_items[] = [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '#',
            'active' => false,
            'attributes' => 'onclick="confirmLogoutInline(); return false;"'
        ];
        break;

    default:
        $menu_items = [];
        break;
}

// Sort all menu items alphabetically, keeping Dashboard first and Logout last
sort_all_menu_items($menu_items);

if (!function_exists('get_mobile_menu_groups')) {
    function get_mobile_menu_groups($menu_items)
    {
        $single = [];
        $grouped = [];
        foreach ($menu_items as $item) {
            if ($item['title'] === 'Logout') {
                continue;
            }
            $has_submenu = isset($item['submenu']) && is_array($item['submenu']);
            if ($has_submenu) {
                $grouped[] = [
                    'title' => $item['title'],
                    'icon' => isset($item['icon']) ? $item['icon'] : '',
                    'items' => $item['submenu']
                ];
            } else {
                $single[] = [
                    'title' => $item['title'],
                    'icon' => isset($item['icon']) ? $item['icon'] : '',
                    'url' => $item['url']
                ];
            }
        }
        return ['single' => $single, 'grouped' => $grouped];
    }
}

if (!function_exists('get_bottom_nav_quick_links')) {
    function get_bottom_nav_quick_links($menu_items, $limit = 3)
    {
        $links = [];
        foreach ($menu_items as $item) {
            if ($item['title'] === 'Logout' || $item['title'] === 'Dashboard') {
                continue;
            }
            $has_submenu = isset($item['submenu']) && is_array($item['submenu']) && count($item['submenu']) > 0;
            $url = $has_submenu ? $item['submenu'][0]['url'] : $item['url'];
            $links[] = [
                'title' => $item['title'],
                'icon' => isset($item['icon']) ? $item['icon'] : '',
                'url' => $url
            ];
            if (count($links) >= $limit) {
                break;
            }
        }
        return $links;
    }
}
?>

<div class="main-sidebar">
    <aside id="sidebar-wrapper">
        <div class="sidebar-brand">
            <a href="dashboard.php" style="line-height: 1.2; display: inline-block; padding: 12px 0;">SISTEM INFORMASI MADRASAH</a>
        </div>
        <div class="sidebar-brand sidebar-brand-sm">
            <a href="dashboard.php">SIM</a>
        </div>
        <ul class="sidebar-menu">
            <?php foreach ($menu_items as $item): ?>
                <?php if (isset($item['submenu'])): ?>
                    <li class="nav-item dropdown <?php echo $item['active'] ? 'active' : ''; ?>">
                        <a href="#" class="nav-link has-dropdown"><i class="<?php echo $item['icon']; ?>"></i><span><?php echo $item['title']; ?></span></a>
                        <ul class="dropdown-menu">
                            <?php foreach ($item['submenu'] as $subitem): ?>
                                <li><a class="nav-link <?php echo $subitem['active'] ? 'active' : ''; ?>" href="<?php echo $subitem['url']; ?>"><?php echo $subitem['title']; ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="<?php echo $item['active'] ? 'active' : ''; ?>">
                        <a class="nav-link" 
                           href="<?php echo $item['url']; ?>" 
                           <?php if (isset($item['attributes'])): ?>
                               <?php echo $item['attributes']; ?>
                           <?php endif; ?>>
                            <i class="<?php echo $item['icon']; ?>"></i> 
                            <span><?php echo $item['title']; ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </aside>
</div>
</div>
