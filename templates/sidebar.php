<?php
// Sidebar template for the attendance system
// This file should be included after the header

// Determine active menu based on current page
$current_page = basename($_SERVER['PHP_SELF']);

// Define menu items based on user level
$user_level = getUserLevel();
$menu_items = [];

switch ($user_level) {
    case 'admin':
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
                    ['title' => 'Mata Pelajaran', 'url' => '../admin/mata_pelajaran.php', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Jam Mengajar', 'url' => '../admin/jam_mengajar.php', 'active' => $current_page === 'jam_mengajar.php'],
                    ['title' => 'Kenaikan Kelas', 'url' => '../admin/kenaikan_kelas.php', 'active' => $current_page === 'kenaikan_kelas.php']
                ],
                'active' => in_array($current_page, ['data_guru.php', 'data_kelas.php', 'data_siswa.php', 'mata_pelajaran.php', 'jam_mengajar.php', 'kenaikan_kelas.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    ['title' => 'Scan Absensi', 'url' => '../admin/scan_qr.php', 'active' => $current_page === 'scan_qr.php'],
                    ['title' => 'Absensi Guru', 'url' => '../admin/absensi_guru.php', 'active' => $current_page === 'absensi_guru.php'],
                    ['title' => 'Rekap Absensi Guru', 'url' => '../admin/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
                    ['title' => 'Absensi Siswa', 'url' => '../admin/absensi_harian.php', 'active' => $current_page === 'absensi_harian.php'],
                    ['title' => 'Rekap Absensi Siswa', 'url' => '../admin/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
                    ['title' => 'Sholat Berjamaah', 'url' => '../admin/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
                    ['title' => 'Rekap Sholat Berjamaah', 'url' => '../admin/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
                    ['title' => 'Sholat Dhuha', 'url' => '../admin/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
                    ['title' => 'Rekap Sholat Dhuha', 'url' => '../admin/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
                ],
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
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Jadwal Pelajaran',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../admin/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../admin/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php'])
            ],
            [
                'title' => 'Jurnal Mengajar',
                'icon' => 'fas fa-book-open',
                'url' => '../admin/jurnal_mengajar.php',
                'active' => $current_page === 'jurnal_mengajar.php'
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
                'title' => 'Log Aktivitas',
                'icon' => 'fas fa-history',
                'url' => '../admin/activity_log.php',
                'active' => $current_page === 'activity_log.php'
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
        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../kepala/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Jadwal Pelajaran',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../kepala/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../kepala/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php'])
            ],
            [
                'title' => 'Rekap Absensi',
                'icon' => 'fas fa-file-alt',
                'submenu' => [
                    ['title' => 'Rekap Absensi Guru', 'url' => '../kepala/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
                    ['title' => 'Rekap Absensi Siswa', 'url' => '../kepala/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
                    ['title' => 'Rekap Sholat Berjamaah', 'url' => '../kepala/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
                    ['title' => 'Rekap Sholat Dhuha', 'url' => '../kepala/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
                ],
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
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Jurnal Mengajar',
                'icon' => 'fas fa-book-open',
                'url' => '../kepala/jurnal_mengajar.php',
                'active' => $current_page === 'jurnal_mengajar.php'
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
        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../tata_usaha/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Jadwal Pelajaran',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../tata_usaha/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../tata_usaha/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    ['title' => 'Scan Absensi', 'url' => '../admin/scan_qr.php', 'active' => $current_page === 'scan_qr.php'],
                    ['title' => 'Absensi Guru', 'url' => '../admin/absensi_guru.php', 'active' => $current_page === 'absensi_guru.php'],
                    ['title' => 'Rekap Absensi Guru', 'url' => '../admin/rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
                    ['title' => 'Absensi Siswa', 'url' => '../admin/absensi_harian.php', 'active' => $current_page === 'absensi_harian.php'],
                    ['title' => 'Rekap Absensi Siswa', 'url' => '../admin/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
                    ['title' => 'Sholat Berjamaah', 'url' => '../admin/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
                    ['title' => 'Rekap Sholat Berjamaah', 'url' => '../admin/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
                    ['title' => 'Sholat Dhuha', 'url' => '../admin/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
                    ['title' => 'Rekap Sholat Dhuha', 'url' => '../admin/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
                ],
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
                    ['title' => 'Rekap Nilai', 'url' => '../admin/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php']
                ],
                'active' => in_array($current_page, ['nilai_harian.php', 'nilai_uts.php', 'nilai_uas.php', 'nilai_pat.php', 'nilai_kokurikuler.php', 'nilai_pra_ujian.php', 'nilai_ujian.php', 'rekap_nilai.php'])
            ],
            [
                'title' => 'Backup & Restore',
                'icon' => 'fas fa-hdd',
                'url' => '../admin/backup_restore.php',
                'active' => $current_page === 'backup_restore.php'
            ],
            [
                'title' => 'Log Aktivitas',
                'icon' => 'fas fa-history',
                'url' => '../admin/activity_log.php',
                'active' => $current_page === 'activity_log.php'
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
                'submenu' => [
                    ['title' => 'Absensi Harian', 'url' => '../guru/absensi_kelas.php', 'active' => $current_page === 'absensi_kelas.php'],
                    ['title' => 'Rekap Absensi Harian', 'url' => '../guru/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
                    ['title' => 'Sholat Berjamaah', 'url' => '../guru/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
                    ['title' => 'Rekap Sholat Berjamaah', 'url' => '../guru/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
                    ['title' => 'Sholat Dhuha', 'url' => '../guru/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
                    ['title' => 'Rekap Sholat Dhuha', 'url' => '../guru/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
                ],
                'active' => in_array($current_page, ['absensi_kelas.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => $nilai_submenu_guru,
                'active' => in_array($current_page, $nilai_urls_guru)
            ],
            [
                'title' => 'Jadwal Pelajaran',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../guru/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../guru/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php'])
            ],
            [
                'title' => 'Jurnal Mengajar',
                'icon' => 'fas fa-book-open',
                'url' => '../guru/jurnal_mengajar.php',
                'active' => $current_page === 'jurnal_mengajar.php'
            ],
            [
                'title' => 'Profil',
                'icon' => 'fas fa-user',
                'url' => '../guru/profil.php',
                'active' => $current_page === 'profil.php'
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
             ['title' => 'Nilai Harian', 'url' => '../guru/nilai_harian.php', 'active' => $current_page === 'nilai_harian.php'],
             ['title' => 'Nilai Tengah Semester', 'url' => '../guru/nilai_uts.php', 'active' => $current_page === 'nilai_uts.php'],
             ['title' => 'Nilai Akhir Semester', 'url' => '../guru/nilai_uas.php', 'active' => $current_page === 'nilai_uas.php'],
             ['title' => 'Nilai Akhir Tahun', 'url' => '../guru/nilai_pat.php', 'active' => $current_page === 'nilai_pat.php'],
             ['title' => 'Nilai Kokurikuler', 'url' => '../guru/nilai_kokurikuler.php', 'active' => $current_page === 'nilai_kokurikuler.php']
        ];
        
        if ($is_grade_6) {
             $nilai_submenu[] = ['title' => 'Nilai Pra Ujian', 'url' => '../guru/nilai_pra_ujian.php', 'active' => $current_page === 'nilai_pra_ujian.php'];
             $nilai_submenu[] = ['title' => 'Nilai Ujian', 'url' => '../guru/nilai_ujian.php', 'active' => $current_page === 'nilai_ujian.php'];
        }

        // Menu Rekap Nilai untuk wali kelas
        $nilai_submenu[] = ['title' => 'Rekap Nilai', 'url' => '../guru/rekap_nilai.php', 'active' => $current_page === 'rekap_nilai.php'];
        
        $nilai_urls = array_map(function($item) {
            return basename($item['url']);
        }, $nilai_submenu);

        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => '../wali/dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Jadwal Pelajaran',
                'icon' => 'fas fa-calendar-alt',
                'submenu' => [
                    ['title' => 'Jadwal Reguler', 'url' => '../wali/jadwal_reguler.php', 'active' => $current_page === 'jadwal_reguler.php'],
                    ['title' => 'Jadwal Ramadhan', 'url' => '../wali/jadwal_ramadhan.php', 'active' => $current_page === 'jadwal_ramadhan.php']
                ],
                'active' => in_array($current_page, ['jadwal_reguler.php', 'jadwal_ramadhan.php'])
            ],
            [
                'title' => 'Absensi Siswa',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    ['title' => 'Absensi Harian', 'url' => '../wali/absensi_kelas.php', 'active' => $current_page === 'absensi_kelas.php'],
                    ['title' => 'Rekap Absensi', 'url' => '../wali/rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php'],
                    ['title' => 'Sholat Berjamaah', 'url' => '../wali/sholat_berjamaah.php', 'active' => $current_page === 'sholat_berjamaah.php'],
                    ['title' => 'Rekap Sholat Berjamaah', 'url' => '../wali/rekap_sholat.php', 'active' => $current_page === 'rekap_sholat.php'],
                    ['title' => 'Sholat Dhuha', 'url' => '../wali/sholat_dhuha.php', 'active' => $current_page === 'sholat_dhuha.php'],
                    ['title' => 'Rekap Sholat Dhuha', 'url' => '../wali/rekap_sholat_dhuha.php', 'active' => $current_page === 'rekap_sholat_dhuha.php']
                ],
                'active' => in_array($current_page, ['absensi_kelas.php', 'rekap_absensi.php', 'sholat_berjamaah.php', 'rekap_sholat.php', 'sholat_dhuha.php', 'rekap_sholat_dhuha.php'])
            ],
            [
                'title' => 'Nilai Siswa',
                'icon' => 'fas fa-graduation-cap',
                'submenu' => $nilai_submenu,
                'active' => in_array($current_page, $nilai_urls)
            ],
            [
                'title' => 'Jurnal Mengajar',
                'icon' => 'fas fa-book-open',
                'url' => '../wali/jurnal_mengajar.php',
                'active' => $current_page === 'jurnal_mengajar.php'
            ],
            [
                'title' => 'Profil & Pengaturan',
                'icon' => 'fas fa-user-cog',
                'url' => '../wali/profil.php',
                'active' => $current_page === 'profil.php'
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
                'title' => 'Jadwal Pelajaran',
                'icon' => 'fas fa-calendar-alt',
                'url' => '../siswa/jadwal_pelajaran.php',
                'active' => $current_page === 'jadwal_pelajaran.php'
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
                'active' => in_array($current_page, ['rekap_absensi.php', 'rekap_sholat.php', 'rekap_sholat_dhuha.php'])
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

    default:
        $menu_items = [];
        break;
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
