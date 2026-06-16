<?php
// Sidebar template for the attendance system
// This file should be included after the header

// Determine active menu based on current page
$current_page = basename($_SERVER['PHP_SELF']);
$nilai_ujian_praktik_menu_active = ($current_page === 'nilai_ujian.php' && isset($_GET['nilai_mode']) && $_GET['nilai_mode'] === 'praktik');
$nilai_ujian_biasa_menu_active = ($current_page === 'nilai_ujian.php' && !$nilai_ujian_praktik_menu_active);

// Define menu items based on user level
$user_level = getUserLevel();
global $menu_items;
$menu_items = [];

if (!function_exists('normalize_person_name_for_match')) {
    function normalize_person_name_for_match(?string $name): string {
        $v = strtolower(trim((string)$name));
        // Hapus semua selain huruf & angka agar tahan variasi: "Nur Huda, S.Pd.I." vs "Nur Huda SPdI"
        $v = preg_replace('/[^a-z0-9]+/u', '', $v);
        return (string)$v;
    }
}

if (!function_exists('is_current_guru_pembina_pramuka')) {
    function is_current_guru_pembina_pramuka(\PDO $pdo): bool
    {
        $idGuru = 0;
        $candidateNames = [];

        $sessionNames = [
            (string)($_SESSION['nama'] ?? ''),
            (string)($_SESSION['nama_guru'] ?? ''),
            (string)($_SESSION['username'] ?? ''),
        ];
        foreach ($sessionNames as $nm) {
            $nm = trim($nm);
            if ($nm !== '') {
                $candidateNames[] = $nm;
            }
        }

        if (isset($_SESSION['user_id'])) {
            $idGuru = (int)$_SESSION['user_id'];
            if (isset($_SESSION['login_source']) && $_SESSION['login_source'] === 'tb_pengguna') {
                try {
                    $st = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ? LIMIT 1");
                    $st->execute([$idGuru]);
                    $idGuru = (int)($st->fetchColumn() ?: 0);
                } catch (Exception $e) {
                    $idGuru = 0;
                }
            }
        }

        if ($idGuru > 0) {
            try {
                $stG = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ? LIMIT 1");
                $stG->execute([$idGuru]);
                $nmGuru = trim((string)($stG->fetchColumn() ?: ''));
                if ($nmGuru !== '') {
                    $candidateNames[] = $nmGuru;
                }
            } catch (Exception $e) {
            }
        }

        $normalizedCandidates = [];
        foreach ($candidateNames as $raw) {
            $n = normalize_person_name_for_match($raw);
            if ($n !== '') {
                $normalizedCandidates[] = $n;
            }
            $parts = preg_split('/\s+/', trim((string)$raw));
            if (is_array($parts) && count($parts) >= 2) {
                $firstTwo = normalize_person_name_for_match($parts[0] . ' ' . $parts[1]);
                if ($firstTwo !== '') {
                    $normalizedCandidates[] = $firstTwo;
                }
            }
        }
        $normalizedCandidates = array_values(array_unique($normalizedCandidates));

        try {
            if ($idGuru > 0) {
                $stId = $pdo->prepare("SELECT COUNT(*) FROM tb_pembina_pramuka WHERE id_guru = ?");
                $stId->execute([$idGuru]);
                if ((int)$stId->fetchColumn() > 0) {
                    return true;
                }
            }

            if ($normalizedCandidates === []) {
                return false;
            }

            $rows = $pdo->query("SELECT nama_pembina FROM tb_pembina_pramuka")->fetchAll(PDO::FETCH_COLUMN, 0);
            foreach ($rows as $nmPbRaw) {
                $nmPb = normalize_person_name_for_match((string)$nmPbRaw);
                if ($nmPb === '') {
                    continue;
                }
                foreach ($normalizedCandidates as $cand) {
                    if (
                        $cand === $nmPb ||
                        strpos($nmPb, $cand) !== false ||
                        strpos($cand, $nmPb) !== false
                    ) {
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            return false;
        }

        return false;
    }
}

// Helper function to sort menu items alphabetically, keeping Dashboard first and Logout last
if (!function_exists('sort_all_menu_items')) {
    function sort_all_menu_items(?array &$items): void {
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
                    // Do not sort submenu A–Z. Only move "Scan Absensi" to the very top
                    // while preserving the existing order of the other items.
                    $scan_index = null;
                    foreach ($item['submenu'] as $idx => $sub) {
                        $t = trim(strip_tags($sub['title'] ?? ''));
                        if (strcasecmp($t, 'Scan Absensi') === 0) {
                            $scan_index = $idx;
                            break;
                        }
                    }
                    if ($scan_index !== null) {
                        $scan_item = $item['submenu'][$scan_index];
                        array_splice($item['submenu'], $scan_index, 1);
                        array_unshift($item['submenu'], $scan_item);
                    }
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
            ['title' => 'Scan Absensi', 'url' => '../admin/scan_qr.php', 'active' => $current_page === 'scan_qr.php'],
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
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../admin/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
        ];

        $ekstrakurikuler_submenu_admin = [
            ['title' => 'Data Ekstrakurikuler', 'url' => '../admin/data_ekstrakurikuler.php', 'active' => $current_page === 'data_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Ekstra', 'url' => '../admin/data_pembina_ekstrakurikuler.php', 'active' => $current_page === 'data_pembina_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Pramuka', 'url' => '../admin/data_pembina_pramuka.php', 'active' => $current_page === 'data_pembina_pramuka.php'],
            ['title' => 'Data Tingkat Pramuka', 'url' => '../admin/data_tingkat_barung.php', 'active' => $current_page === 'data_tingkat_barung.php'],
            ['title' => 'Data Anggota Pramuka', 'url' => '../admin/data_barung.php', 'active' => $current_page === 'data_barung.php'],
            ['title' => 'Data Anggota Pencak Silat', 'url' => '../admin/data_anggota_pencak_silat.php', 'active' => $current_page === 'data_anggota_pencak_silat.php'],
            ['title' => 'Data Anggota Rebana', 'url' => '../admin/data_anggota_rebana.php', 'active' => $current_page === 'data_anggota_rebana.php'],
            ['title' => 'Pengaturan Cetak Suket', 'url' => '../admin/pengaturan_cetak_suket.php', 'active' => $current_page === 'pengaturan_cetak_suket.php'],
            ['title' => 'Syarat Kecakapan Umum', 'url' => '../admin/syarat_kecakapan_umum.php', 'active' => $current_page === 'syarat_kecakapan_umum.php'],
            ['title' => 'Surat Keterangan', 'url' => '../admin/surat_keterangan.php', 'active' => $current_page === 'surat_keterangan.php'],
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
                    ['title' => 'Data Peserta Ujian', 'url' => '../admin/data_peserta_ujian.php', 'active' => $current_page === 'data_peserta_ujian.php'],
                    ['title' => 'Data Siswa Baru', 'url' => '../admin/siswa_baru.php', 'active' => $current_page === 'siswa_baru.php'],
                    ['title' => 'Data Alumni', 'url' => '../admin/data_alumni.php', 'active' => $current_page === 'data_alumni.php'],
                    ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php', 'active' => $current_page === 'data_nilai_ujian.php'],
                    ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Jam Mengajar', 'url' => '../admin/jam_mengajar.php', 'active' => $current_page === 'jam_mengajar.php'],
                    ['title' => 'Kenaikan Kelas', 'url' => '../admin/kenaikan_kelas.php', 'active' => $current_page === 'kenaikan_kelas.php'],
                    ['title' => 'Kalender Pendidikan', 'url' => '../admin/kalender_pendidikan.php', 'active' => $current_page === 'kalender_pendidikan.php']
                ],
                'active' => in_array($current_page, ['data_guru.php', 'data_kelas.php', 'data_siswa.php', 'data_peserta_ujian.php', 'siswa_baru.php', 'data_alumni.php', 'data_nilai_ujian.php', 'mata_pelajaran.php', 'jam_mengajar.php', 'kenaikan_kelas.php', 'kalender_pendidikan.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_admin,
                'active' => in_array($current_page, ['scan_qr.php', 'absensi_guru.php', 'rekap_absensi_guru.php', 'absensi_harian.php', 'absensi_les_siswa.php', 'rekap_absensi.php', 'rekap_absensi_les_siswa.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php', 'absensi_les_guru.php', 'rekap_absensi_les_guru.php'])
            ],
            [
                'title' => 'Ekstrakurikuler',
                'icon' => 'fas fa-users',
                'submenu' => $ekstrakurikuler_submenu_admin,
                'active' => in_array($current_page, ['data_ekstrakurikuler.php', 'data_pembina_ekstrakurikuler.php', 'data_pembina_pramuka.php', 'data_barung.php', 'syarat_kecakapan_umum.php', 'data_anggota_pencak_silat.php', 'data_anggota_rebana.php', 'data_tingkat_barung.php', 'surat_keterangan.php', 'pengaturan_cetak_suket.php'])
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../admin/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../admin/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Les', 'url' => '../admin/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php', 'active' => $current_page === 'jadwal_imam.php'],
                    ['title' => 'Jadwal Seragam Guru', 'url' => '../admin/jadwal_seragam.php', 'active' => $current_page === 'jadwal_seragam.php'],
                    ['title' => 'Jadwal Seragam Siswa', 'url' => '../admin/jadwal_seragam_siswa.php', 'active' => $current_page === 'jadwal_seragam_siswa.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_les.php', 'jadwal_imam.php', 'jadwal_seragam.php', 'jadwal_seragam_siswa.php'])
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
                'title' => 'Inventaris',
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
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-chart-bar',
                'submenu' => [
                    ['title' => 'Nilai Harian', 'url' => '../admin/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
                    ['title' => 'Nilai Tengah Semester', 'url' => '../admin/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
                    ['title' => 'Nilai Akhir Semester', 'url' => '../admin/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
                    ['title' => 'Nilai Akhir Tahun', 'url' => '../admin/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
                    ['title' => 'Nilai Kokurikuler', 'url' => '../admin/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php'],
                    ['title' => 'Nilai Pra Ujian', 'url' => '../admin/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'],
                    ['title' => 'Nilai Ujian', 'url' => '../admin/nilai_ujian.php', 'active' => $nilai_ujian_biasa_menu_active],
                    ['title' => 'Nilai Ujian Praktik', 'url' => '../admin/nilai_ujian.php?nilai_mode=praktik', 'active' => $nilai_ujian_praktik_menu_active],
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Remidial',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => [
                    ['title' => 'Program Remidi', 'url' => '../admin/program_remidi.php', 'active' => $current_page === 'program_remidi.php'],
                    ['title' => 'Program Pengayaan', 'url' => '../admin/program_pengayaan.php', 'active' => $current_page === 'program_pengayaan.php']
                ],
                'active' => in_array($current_page, ['program_remidi.php', 'program_pengayaan.php'])
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
                'attributes' => 'onclick="confirmLogoutInline(\'../logout.php?level=' . htmlspecialchars($user_level, ENT_QUOTES, 'UTF-8') . '\'); return false;"'
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
        $ekstrakurikuler_submenu_kepala = [
            ['title' => 'Data Ekstrakurikuler', 'url' => '../admin/data_ekstrakurikuler.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Pramuka', 'url' => '../admin/data_pembina_pramuka.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_pembina_pramuka.php'],
            ['title' => 'Data Pembina Ekskul', 'url' => '../admin/data_pembina_ekstrakurikuler.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_pembina_ekstrakurikuler.php'],
            ['title' => 'Data Tingkat Pramuka', 'url' => '../admin/data_tingkat_barung.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_tingkat_barung.php'],
            ['title' => 'Data Anggota Pencak Silat', 'url' => '../admin/data_anggota_pencak_silat.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_anggota_pencak_silat.php'],
            ['title' => 'Data Anggota Rebana', 'url' => '../admin/data_anggota_rebana.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_anggota_rebana.php'],
            ['title' => 'Data Anggota Pramuka', 'url' => '../admin/data_barung.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_barung.php'],
        ];

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../kepala/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Data Utama',
                'icon' => 'fas fa-database',
                'submenu' => [
                    ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php?session_type=kepala_madrasah', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Kalender Pendidikan', 'url' => '../admin/kalender_pendidikan.php?session_type=kepala_madrasah', 'active' => $current_page === 'kalender_pendidikan.php'],
                    ['title' => 'Data Siswa Baru', 'url' => '../admin/siswa_baru.php?session_type=kepala_madrasah', 'active' => $current_page === 'siswa_baru.php']
                ],
                'active' => in_array($current_page, ['mata_pelajaran.php', 'kalender_pendidikan.php', 'siswa_baru.php'])
            ],
            [
                'title' => 'Ekstrakurikuler',
                'icon' => 'fas fa-users',
                'submenu' => $ekstrakurikuler_submenu_kepala,
                'active' => in_array($current_page, ['data_ekstrakurikuler.php', 'data_pembina_pramuka.php', 'data_pembina_ekstrakurikuler.php', 'data_anggota_pencak_silat.php', 'data_anggota_rebana.php', 'data_barung.php', 'data_tingkat_barung.php'])
            ],
            [
                'title' => 'Rekap Absensi',
                'icon' => 'fas fa-file-alt',
                'submenu' => $rekap_absensi_submenu_kepala,
                'active' => in_array($current_page, ['rekap_absensi_guru.php', 'rekap_absensi.php', 'rekap_sholat.php', 'rekap_sholat_dhuha.php', 'rekap_absensi_les_siswa.php', 'rekap_absensi_les_guru.php'])
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../kepala/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../kepala/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Les Kelas 6', 'url' => '../kepala/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=kepala_madrasah', 'active' => $current_page === 'jadwal_imam.php'],
                    ['title' => 'Jadwal Seragam Guru', 'url' => '../admin/jadwal_seragam.php?session_type=kepala_madrasah', 'active' => $current_page === 'jadwal_seragam.php'],
                    ['title' => 'Jadwal Seragam Siswa', 'url' => '../admin/jadwal_seragam_siswa.php?session_type=kepala_madrasah', 'active' => $current_page === 'jadwal_seragam_siswa.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_les.php', 'jadwal_imam.php', 'jadwal_seragam.php', 'jadwal_seragam_siswa.php'])
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
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-chart-bar',
                'submenu' => [
                    ['title' => 'Nilai Harian', 'url' => '../admin/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
                    ['title' => 'Nilai Tengah Semester', 'url' => '../admin/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
                    ['title' => 'Nilai Akhir Semester', 'url' => '../admin/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
                    ['title' => 'Nilai Akhir Tahun', 'url' => '../admin/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
                    ['title' => 'Nilai Kokurikuler', 'url' => '../admin/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php'],
                    ['title' => 'Nilai Pra Ujian', 'url' => '../admin/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'],
                    ['title' => 'Nilai Ujian', 'url' => '../admin/nilai_ujian.php', 'active' => $nilai_ujian_biasa_menu_active],
                    ['title' => 'Nilai Ujian Praktik', 'url' => '../admin/nilai_ujian.php?session_type=kepala_madrasah&nilai_mode=praktik', 'active' => $nilai_ujian_praktik_menu_active],
                    ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_nilai_ujian.php'],
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'data_nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Inventaris',
                'icon' => 'fas fa-boxes',
                'submenu' => [
                    ['title' => 'Data Inventaris Sarpras', 'url' => '../admin/data_inventaris.php?session_type=kepala_madrasah', 'active' => $current_page === 'data_inventaris.php']
                ],
                'active' => $current_page === 'data_inventaris.php'
            ],
            [
                'title' => 'Logout',
                'icon' => 'fas fa-sign-out-alt',
                'url' => '#',
                'active' => false,
                'attributes' => 'onclick="confirmLogoutInline(\'../logout.php?level=' . htmlspecialchars($user_level, ENT_QUOTES, 'UTF-8') . '\'); return false;"'
            ]
        ];
        break;
        
    case 'tata_usaha':
        $absensi_submenu_tu = [
            ['title' => 'Scan Absensi', 'url' => '../admin/scan_qr.php', 'active' => $current_page === 'scan_qr.php'],
            ['title' => 'Absensi Guru', 'url' => '../admin/absensi_guru.php', 'active' => $current_page === 'absensi_guru.php'],
            ['title' => 'Absensi Sholat Berjamaah', 'url' => '../admin/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
            ['title' => 'Absensi Sholat Dhuha', 'url' => '../admin/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
            ['title' => 'Absensi Siswa', 'url' => '../admin/absensi_harian.php', 'active' => $current_page === 'absensi_harian.php'],
            ['title' => 'Rekap Absensi Guru', 'url' => '../admin/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
            ['title' => 'Rekap Absensi Siswa', 'url' => '../admin/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
            ['title' => 'Rekap Sholat Berjamaah', 'url' => '../admin/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
            ['title' => 'Rekap Sholat Dhuha', 'url' => '../admin/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
        ];

        $ekstrakurikuler_submenu_tu = [
            ['title' => 'Data Ekstrakurikuler', 'url' => '../admin/data_ekstrakurikuler.php?session_type=tata_usaha', 'active' => $current_page === 'data_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Ekstra', 'url' => '../admin/data_pembina_ekstrakurikuler.php?session_type=tata_usaha', 'active' => $current_page === 'data_pembina_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Pramuka', 'url' => '../admin/data_pembina_pramuka.php?session_type=tata_usaha', 'active' => $current_page === 'data_pembina_pramuka.php'],
            ['title' => 'Data Tingkat Pramuka', 'url' => '../admin/data_tingkat_barung.php?session_type=tata_usaha', 'active' => $current_page === 'data_tingkat_barung.php'],
            ['title' => 'Data Anggota Pramuka', 'url' => '../admin/data_barung.php?session_type=tata_usaha', 'active' => $current_page === 'data_barung.php'],
            ['title' => 'Data Anggota Pencak Silat', 'url' => '../admin/data_anggota_pencak_silat.php?session_type=tata_usaha', 'active' => $current_page === 'data_anggota_pencak_silat.php'],
            ['title' => 'Data Anggota Rebana', 'url' => '../admin/data_anggota_rebana.php?session_type=tata_usaha', 'active' => $current_page === 'data_anggota_rebana.php'],
            ['title' => 'Pengaturan Cetak Suket', 'url' => '../admin/pengaturan_cetak_suket.php?session_type=tata_usaha', 'active' => $current_page === 'pengaturan_cetak_suket.php'],
            ['title' => 'Syarat Kecakapan Umum', 'url' => '../admin/syarat_kecakapan_umum.php?session_type=tata_usaha', 'active' => $current_page === 'syarat_kecakapan_umum.php'],
            ['title' => 'Surat Keterangan', 'url' => '../admin/surat_keterangan.php?session_type=tata_usaha', 'active' => $current_page === 'surat_keterangan.php'],
        ];

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../tata_usaha/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Data Utama',
                'icon' => 'fas fa-database',
                'submenu' => [
                    ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php?session_type=tata_usaha', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Kalender Pendidikan', 'url' => '../admin/kalender_pendidikan.php?session_type=' . $_SESSION['level'], 'active' => $current_page === 'kalender_pendidikan.php'],
                    ['title' => 'Data Siswa Baru', 'url' => '../admin/siswa_baru.php?session_type=' . $_SESSION['level'], 'active' => $current_page === 'siswa_baru.php'],
                    ['title' => 'Data Peserta Ujian', 'url' => '../admin/data_peserta_ujian.php?session_type=tata_usaha', 'active' => $current_page === 'data_peserta_ujian.php'],
                    ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php?session_type=tata_usaha', 'active' => $current_page === 'data_nilai_ujian.php']
                ],
                'active' => in_array($current_page, ['mata_pelajaran.php', 'kalender_pendidikan.php', 'siswa_baru.php', 'data_peserta_ujian.php', 'data_nilai_ujian.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_tu,
                'active' => in_array($current_page, ['scan_qr.php', 'absensi_guru.php', 'rekap_absensi_guru.php', 'absensi_harian.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Ekstrakurikuler',
                'icon' => 'fas fa-users',
                'submenu' => $ekstrakurikuler_submenu_tu,
                'active' => in_array($current_page, ['data_ekstrakurikuler.php', 'data_pembina_ekstrakurikuler.php', 'data_pembina_pramuka.php', 'data_tingkat_barung.php', 'data_barung.php', 'syarat_kecakapan_umum.php', 'data_anggota_pencak_silat.php', 'data_anggota_rebana.php', 'surat_keterangan.php', 'pengaturan_cetak_suket.php'])
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../tata_usaha/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../tata_usaha/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Les Kelas 6', 'url' => '../admin/jadwal_les.php?session_type=tata_usaha', 'active' => $current_page === 'jadwal_les.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=tata_usaha', 'active' => $current_page === 'jadwal_imam.php'],
                    ['title' => 'Jadwal Seragam Guru', 'url' => '../admin/jadwal_seragam.php?session_type=tata_usaha', 'active' => $current_page === 'jadwal_seragam.php'],
                    ['title' => 'Jadwal Seragam Siswa', 'url' => '../admin/jadwal_seragam_siswa.php?session_type=tata_usaha', 'active' => $current_page === 'jadwal_seragam_siswa.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_les.php', 'jadwal_imam.php', 'jadwal_seragam.php', 'jadwal_seragam_siswa.php'])
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
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-chart-bar',
                'submenu' => [
                    ['title' => 'Nilai Harian', 'url' => '../admin/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
                    ['title' => 'Nilai Tengah Semester', 'url' => '../admin/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
                    ['title' => 'Nilai Akhir Semester', 'url' => '../admin/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
                    ['title' => 'Nilai Akhir Tahun', 'url' => '../admin/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
                    ['title' => 'Nilai Kokurikuler', 'url' => '../admin/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php'],
                    ['title' => 'Nilai Pra Ujian', 'url' => '../admin/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'],
                    ['title' => 'Nilai Ujian', 'url' => '../admin/nilai_ujian.php', 'active' => $nilai_ujian_biasa_menu_active],
                    ['title' => 'Nilai Ujian Praktik', 'url' => '../admin/nilai_ujian.php?session_type=tata_usaha&nilai_mode=praktik', 'active' => $nilai_ujian_praktik_menu_active],
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Remidial',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => [
                    ['title' => 'Program Remidi', 'url' => '../tata_usaha/program_remidi.php', 'active' => $current_page === 'program_remidi.php'],
                    ['title' => 'Program Pengayaan', 'url' => '../tata_usaha/program_pengayaan.php', 'active' => $current_page === 'program_pengayaan.php']
                ],
                'active' => in_array($current_page, ['program_remidi.php', 'program_pengayaan.php'])
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
                'title' => 'Inventaris',
                'icon' => 'fas fa-boxes',
                'submenu' => [
                    ['title' => 'Data Inventaris Sarpras', 'url' => '../admin/data_inventaris.php?session_type=tata_usaha', 'active' => $current_page === 'data_inventaris.php']
                ],
                'active' => $current_page === 'data_inventaris.php'
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
                'attributes' => 'onclick="confirmLogoutInline(\'../logout.php?level=' . htmlspecialchars($user_level, ENT_QUOTES, 'UTF-8') . '\'); return false;"'
            ]
        ];
        break;

    case 'guru':
        $is_grade_6_guru = false;
        $is_guru_pembina_pramuka = is_current_guru_pembina_pramuka($pdo);
        $id_guru_login = 0;
        if (isset($_SESSION['user_id'])) {
            $id_guru_check = $_SESSION['user_id'];
            if (isset($_SESSION['login_source']) && $_SESSION['login_source'] == 'tb_pengguna') {
                $stmt_uid = $pdo->prepare("SELECT id_guru FROM tb_pengguna WHERE id_pengguna = ?");
                $stmt_uid->execute([$_SESSION['user_id']]);
                $id_guru_check = $stmt_uid->fetchColumn();
            }
            $id_guru_login = (int)$id_guru_check;
            
            if ($id_guru_check) {
                $stmt_g = $pdo->prepare("SELECT mengajar FROM tb_guru WHERE id_guru = ?");
                $stmt_g->execute([$id_guru_check]);
                $mengajar_json = (string)$stmt_g->fetchColumn();
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
        // is_guru_pembina_pramuka sudah ditentukan lewat helper yang robust.

        $ekstrakurikuler_submenu_guru = [
            ['title' => 'Data Ekstrakurikuler', 'url' => '../admin/data_ekstrakurikuler.php?session_type=guru', 'active' => $current_page === 'data_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Pramuka', 'url' => '../admin/data_pembina_pramuka.php?session_type=guru', 'active' => $current_page === 'data_pembina_pramuka.php'],
            ['title' => 'Data Pembina Ekskul', 'url' => '../admin/data_pembina_ekstrakurikuler.php?session_type=guru', 'active' => $current_page === 'data_pembina_ekstrakurikuler.php'],
            ['title' => 'Data Anggota Pencak Silat', 'url' => '../admin/data_anggota_pencak_silat.php?session_type=guru', 'active' => $current_page === 'data_anggota_pencak_silat.php'],
            ['title' => 'Data Anggota Rebana', 'url' => '../admin/data_anggota_rebana.php?session_type=guru', 'active' => $current_page === 'data_anggota_rebana.php'],
            ['title' => 'Data Anggota Pramuka', 'url' => '../admin/data_barung.php?session_type=guru', 'active' => $current_page === 'data_barung.php'],
        ];
        if ($is_guru_pembina_pramuka) {
            $ekstrakurikuler_submenu_guru[] = ['title' => 'Syarat Kecakapan Umum', 'url' => '../admin/syarat_kecakapan_umum.php?session_type=guru', 'active' => $current_page === 'syarat_kecakapan_umum.php'];
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
            $nilai_submenu_guru[] = ['title' => 'Nilai Ujian', 'url' => '../guru/nilai_ujian.php', 'active' => $nilai_ujian_biasa_menu_active];
            $nilai_submenu_guru[] = ['title' => 'Nilai Ujian Praktik', 'url' => '../guru/nilai_ujian.php?nilai_mode=praktik', 'active' => $nilai_ujian_praktik_menu_active];
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
                'title' => 'Data Utama',
                'icon' => 'fas fa-database',
                'submenu' => [
                    ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php?session_type=guru', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Kalender Pendidikan', 'url' => '../admin/kalender_pendidikan.php?session_type=guru', 'active' => $current_page === 'kalender_pendidikan.php']
                ],
                'active' => in_array($current_page, ['mata_pelajaran.php', 'kalender_pendidikan.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_guru,
                'active' => in_array($current_page, ['absensi_kelas.php', 'absensi_les_guru.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php', 'absensi_les_siswa.php', 'rekap_absensi_les_siswa.php', 'rekap_absensi_les_guru.php'])
            ],
            [
                'title' => 'Ekstrakurikuler',
                'icon' => 'fas fa-users',
                'submenu' => $ekstrakurikuler_submenu_guru,
                'active' => in_array($current_page, ['data_ekstrakurikuler.php', 'data_pembina_pramuka.php', 'data_pembina_ekstrakurikuler.php', 'data_anggota_pencak_silat.php', 'data_anggota_rebana.php', 'data_barung.php', 'syarat_kecakapan_umum.php'])
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../guru/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../guru/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=guru', 'active' => $current_page === 'jadwal_imam.php'],
                    ['title' => 'Jadwal Seragam Guru', 'url' => '../admin/jadwal_seragam.php?session_type=guru', 'active' => $current_page === 'jadwal_seragam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_imam.php', 'jadwal_seragam.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => $nilai_submenu_guru,
                'active' => in_array($current_page, $nilai_urls_guru)
            ],
            [
                'title' => 'Remidial',
                'icon' => 'fas fa-notes-medical',
                'submenu' => [
                    ['title' => 'Program Remidi', 'url' => '../guru/program_remidi.php', 'active' => $current_page === 'program_remidi.php'],
                    ['title' => 'Program Pengayaan', 'url' => '../guru/program_pengayaan.php', 'active' => $current_page === 'program_pengayaan.php']
                ],
                'active' => in_array($current_page, ['program_remidi.php', 'program_pengayaan.php'])
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
        ];

        // Jurnal menu for all teachers
        $jurnal_submenu_guru = [
            ['title' => 'Jurnal Mengajar', 'url' => '../guru/jurnal_mengajar.php', 'active' => $current_page === 'jurnal_mengajar.php']
        ];
        if ($is_grade_6_guru) {
            $jurnal_submenu_guru[] = ['title' => 'Jurnal Les', 'url' => '../guru/jurnal_les.php', 'active' => $current_page === 'jurnal_les.php'];
        }
        
        // Place Jurnal right after Jadwal (under Absensi)
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
            'attributes' => 'onclick="confirmLogoutInline(\'../logout.php?level=' . htmlspecialchars($user_level, ENT_QUOTES, 'UTF-8') . '\'); return false;"'
        ];
        break;
        
    case 'wali':
        $is_grade_6 = false;
        $is_wali_pembina_pramuka = is_current_guru_pembina_pramuka($pdo);
        
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
             $nilai_submenu[] = ['title' => 'Nilai Ujian', 'url' => '../guru/nilai_ujian.php?session_type=wali', 'active' => $nilai_ujian_biasa_menu_active];
             $nilai_submenu[] = ['title' => 'Nilai Ujian Praktik', 'url' => '../guru/nilai_ujian.php?session_type=wali&nilai_mode=praktik', 'active' => $nilai_ujian_praktik_menu_active];
        }

        // Menu Rekap Nilai untuk wali kelas
        $nilai_submenu[] = ['title' => 'Rekap Nilai', 'url' => '../guru/rekap_nilai.php?session_type=wali', 'active' => $current_page === 'rekap_nilai.php'];
        
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

        $data_utama_submenu_wali = [
            ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php?session_type=wali', 'active' => $current_page === 'mata_pelajaran.php'],
            ['title' => 'Kalender Pendidikan', 'url' => '../admin/kalender_pendidikan.php?session_type=wali', 'active' => $current_page === 'kalender_pendidikan.php']
        ];
        if ($is_grade_6) {
            $data_utama_submenu_wali[] = ['title' => 'Data Nilai Ujian', 'url' => '../admin/data_nilai_ujian.php?session_type=wali', 'active' => $current_page === 'data_nilai_ujian.php'];
            $data_utama_submenu_wali[] = ['title' => 'Data Peserta Ujian', 'url' => '../admin/data_peserta_ujian.php?session_type=wali', 'active' => $current_page === 'data_peserta_ujian.php'];
        }

        $data_utama_urls_wali = array_map(function($item) {
            return basename($item['url']);
        }, $data_utama_submenu_wali);
        $ekstrakurikuler_submenu_wali = [
            ['title' => 'Data Ekstrakurikuler', 'url' => '../admin/data_ekstrakurikuler.php?session_type=wali', 'active' => $current_page === 'data_ekstrakurikuler.php'],
            ['title' => 'Data Pembina Pramuka', 'url' => '../admin/data_pembina_pramuka.php?session_type=wali', 'active' => $current_page === 'data_pembina_pramuka.php'],
            ['title' => 'Data Pembina Ekskul', 'url' => '../admin/data_pembina_ekstrakurikuler.php?session_type=wali', 'active' => $current_page === 'data_pembina_ekstrakurikuler.php'],
            ['title' => 'Data Anggota Pencak Silat', 'url' => '../admin/data_anggota_pencak_silat.php?session_type=wali', 'active' => $current_page === 'data_anggota_pencak_silat.php'],
            ['title' => 'Data Anggota Rebana', 'url' => '../admin/data_anggota_rebana.php?session_type=wali', 'active' => $current_page === 'data_anggota_rebana.php'],
            ['title' => 'Data Anggota Pramuka', 'url' => '../admin/data_barung.php?session_type=wali', 'active' => $current_page === 'data_barung.php'],
        ];
        if ($is_wali_pembina_pramuka) {
            $ekstrakurikuler_submenu_wali[] = ['title' => 'Syarat Kecakapan Umum', 'url' => '../admin/syarat_kecakapan_umum.php?session_type=wali', 'active' => $current_page === 'syarat_kecakapan_umum.php'];
        }

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../wali/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Data Utama',
                'icon' => 'fas fa-database',
                'submenu' => $data_utama_submenu_wali,
                'active' => in_array($current_page, $data_utama_urls_wali)
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => $absensi_submenu_wali,
                'active' => in_array($current_page, ['absensi_kelas.php', 'absensi_les_guru.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Ekstrakurikuler',
                'icon' => 'fas fa-users',
                'submenu' => $ekstrakurikuler_submenu_wali,
                'active' => in_array($current_page, ['data_ekstrakurikuler.php', 'data_pembina_pramuka.php', 'data_pembina_ekstrakurikuler.php', 'data_anggota_pencak_silat.php', 'data_anggota_rebana.php', 'data_barung.php', 'syarat_kecakapan_umum.php'])
            ],
            [
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../wali/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../wali/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php'],
                    ['title' => 'Jadwal Imam Dhuha', 'url' => '../admin/jadwal_imam.php?session_type=wali', 'active' => $current_page === 'jadwal_imam.php'],
                    ['title' => 'Jadwal Seragam Guru', 'url' => '../admin/jadwal_seragam.php?session_type=wali', 'active' => $current_page === 'jadwal_seragam.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php', 'jadwal_imam.php', 'jadwal_seragam.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => $nilai_submenu,
                'active' => in_array($current_page, $nilai_urls)
            ],
            [
                'title' => 'Remidial',
                'icon' => 'fas fa-notes-medical',
                'submenu' => [
                    ['title' => 'Program Remidi', 'url' => '../guru/program_remidi.php?session_type=wali', 'active' => $current_page === 'program_remidi.php'],
                    ['title' => 'Program Pengayaan', 'url' => '../guru/program_pengayaan.php?session_type=wali', 'active' => $current_page === 'program_pengayaan.php']
                ],
                'active' => in_array($current_page, ['program_remidi.php', 'program_pengayaan.php'])
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
        ];

        // Jurnal menu for all wali
        $jurnal_submenu_wali = [
            ['title' => 'Jurnal Mengajar', 'url' => '../wali/jurnal_mengajar.php', 'active' => $current_page === 'jurnal_mengajar.php']
        ];
        if ($is_grade_6) {
            $jurnal_submenu_wali[] = ['title' => 'Jurnal Les', 'url' => '../wali/jurnal_les.php', 'active' => $current_page === 'jurnal_les.php'];
        }

        // Place Jurnal right after Jadwal (under Absensi)
        array_splice($menu_items, 4, 0, [[
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
            'attributes' => 'onclick="confirmLogoutInline(\'../logout.php?level=' . htmlspecialchars($user_level, ENT_QUOTES, 'UTF-8') . '\'); return false;"'
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
            $nilai_submenu_siswa[] = ['title' => 'Nilai Ujian', 'url' => '../siswa/nilai_ujian.php', 'active' => $nilai_ujian_biasa_menu_active];
            $nilai_submenu_siswa[] = ['title' => 'Nilai Ujian Praktik', 'url' => '../siswa/nilai_ujian.php?nilai_mode=praktik', 'active' => $nilai_ujian_praktik_menu_active];
        }

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../siswa/dashboard.php',
                'active' => $current_page === 'dashboard.php'
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
                'title' => 'Jadwal',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Pelajaran', 'url' => '../siswa/jadwal_pelajaran.php', 'active' => $current_page === 'jadwal_pelajaran.php'],
                    ['title' => 'Jadwal Seragam Siswa', 'url' => '../admin/jadwal_seragam_siswa.php?session_type=siswa', 'active' => $current_page === 'jadwal_seragam_siswa.php']
                ],
                'active' => in_array($current_page, ['jadwal_pelajaran.php', 'jadwal_les.php', 'jadwal_seragam_siswa.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-book',
                'submenu' => $nilai_submenu_siswa,
                'active' => in_array($current_page, ['rekap_nilai.php', 'nilai_pra_ujian.php', 'nilai_ujian.php'])
            ],
            [
                'title' => 'Keuangan',
                'icon' => 'fas fa-money-bill-wave',
                'submenu' => array_filter([
                    ['title' => 'Tagihan Siswa', 'url' => '../siswa/tagihan_siswa.php', 'active' => $current_page === 'tagihan_siswa.php'],
                    ['title' => 'Laporan Pembayaran', 'url' => '../siswa/laporan_pembayaran.php', 'active' => $current_page === 'laporan_pembayaran.php'],
                    $is_grade_6_siswa ? ['title' => 'Biaya Ujian', 'url' => '../siswa/biaya_ujian.php', 'active' => $current_page === 'biaya_ujian.php'] : null
                ]),
                'active' => in_array($current_page, ['tagihan_siswa.php', 'laporan_pembayaran.php', 'biaya_ujian.php'])
            ],
            [
                'title' => 'Kalender Pendidikan',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../admin/kalender_pendidikan.php?session_type=siswa',
                'active' => $current_page === 'kalender_pendidikan.php'
            ]
        ];

        if ($is_grade_6_siswa) {
            array_splice($menu_items, 1, 0, [[
                'title' => 'Info Kelulusan',
                'icon' => 'fas fa-graduation-cap',
                'url' => '../siswa/info_kelulusan.php',
                'active' => $current_page === 'info_kelulusan.php'
            ]]);
            // Add Jadwal Les into Jadwal submenu for Grade 6 Students
            foreach ($menu_items as &$m_item) {
                if ($m_item['title'] === 'Jadwal') {
                    $m_item['submenu'][] = ['title' => 'Jadwal Les Kelas 6', 'url' => '../siswa/jadwal_les.php', 'active' => $current_page === 'jadwal_les.php'];
                }
                if ($m_item['title'] === 'Absensi') {
                    $m_item['submenu'][] = ['title' => 'Rekap Absensi Les', 'url' => '../siswa/rekap_absensi_les.php', 'active' => $current_page === 'rekap_absensi_les.php'];
                }
            }
        }

        $menu_items[] = [
            'title' => 'Logout',
            'icon' => 'fas fa-sign-out-alt',
            'url' => '#',
            'active' => false,
            'attributes' => 'onclick="confirmLogoutInline(\'../logout.php?level=' . htmlspecialchars($user_level, ENT_QUOTES, 'UTF-8') . '\'); return false;"'
        ];
        break;

    default:
        $menu_items = [];
        break;
}

// Automatically set parent active if any child is active
foreach ($menu_items as &$item) {
    if (isset($item['submenu']) && is_array($item['submenu'])) {
        foreach ($item['submenu'] as $subitem) {
            if (isset($subitem['active']) && $subitem['active']) {
                $item['active'] = true;
                break;
            }
        }
    }
}

// Sort all menu items alphabetically, keeping Dashboard first and Logout last
sort_all_menu_items($menu_items);

if (!function_exists('get_mobile_menu_groups')) {
    function get_mobile_menu_groups(array $menu_items): array
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
    function get_bottom_nav_quick_links(array $menu_items, int $limit = 3): array
    {
        $links = [];
        $user_level = getUserLevel();

        foreach ($menu_items as $item) {
            if ($item['title'] === 'Logout' || $item['title'] === 'Dashboard' || $item['title'] === 'Profil' || $item['title'] === 'Profil & Pengaturan') {
                continue;
            }

            $title = $item['title'];
            $icon = isset($item['icon']) ? $item['icon'] : '';
            $has_submenu = isset($item['submenu']) && is_array($item['submenu']) && count($item['submenu']) > 0;
            $url = $has_submenu ? $item['submenu'][0]['url'] : $item['url'];

            // Custom mapping for all user levels
            if ($user_level === 'guru' || $user_level === 'wali') {
                if ($title === 'Data Utama') {
                    $title = 'Absensi';
                    $icon = 'fas fa-calendar-check';
                    $url = 'dashboard.php#menu-Absensi';
                } elseif ($title === 'Absensi') {
                    $title = 'Nilai Siswa';
                    $icon = 'fas fa-graduation-cap';
                    $url = 'dashboard.php#menu-Nilai-Siswa';
                } elseif ($title === 'Ekstrakurikuler') {
                    $url = 'dashboard.php#menu-Ekstrakurikuler';
                }
            } else {
                // For admin, kepala_madrasah, tata_usaha
                if ($has_submenu) {
                    // If menu has submenu, link to dashboard with anchor
                    $menu_anchor = 'menu-' . str_replace(' ', '-', $title);
                    $url = 'dashboard.php#' . $menu_anchor;
                }
            }

            $links[] = [
                'title' => $title,
                'icon' => $icon,
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
            <a href="dashboard.php" style="line-height: 1.2; display: inline-block; padding: 12px 0;">Sistem Informasi Madrasah</a>
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
