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
                'url' => 'dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Master Data',
                'icon' => 'fas fa-database',
                'submenu' => [
                    ['title' => 'Data Guru', 'url' => 'data_guru.php', 'active' => $current_page === 'data_guru.php'],
                    ['title' => 'Data Kelas', 'url' => 'data_kelas.php', 'active' => $current_page === 'data_kelas.php'],
                    ['title' => 'Data Siswa', 'url' => 'data_siswa.php', 'active' => $current_page === 'data_siswa.php'],
                    ['title' => 'Mata Pelajaran', 'url' => 'mata_pelajaran.php', 'active' => $current_page === 'mata_pelajaran.php'],
                    ['title' => 'Jam Mengajar', 'url' => 'jam_mengajar.php', 'active' => $current_page === 'jam_mengajar.php']
                ],
                'active' => in_array($current_page, ['data_guru.php', 'data_kelas.php', 'data_siswa.php', 'mata_pelajaran.php', 'jam_mengajar.php'])
            ],
            [
                'title' => 'Absensi',
                'icon' => 'fas fa-calendar-check',
                'submenu' => [
                    ['title' => 'Absensi Guru', 'url' => 'absensi_guru.php', 'active' => $current_page === 'absensi_guru.php'],
                    ['title' => 'Rekap Absensi Guru', 'url' => 'rekap_absensi_guru.php', 'active' => $current_page === 'rekap_absensi_guru.php'],
                    ['title' => 'Absensi Siswa', 'url' => 'absensi_harian.php', 'active' => $current_page === 'absensi_harian.php'],
                    ['title' => 'Rekap Absensi Siswa', 'url' => 'rekap_absensi.php', 'active' => $current_page === 'rekap_absensi.php']
                ],
                'active' => in_array($current_page, ['absensi_guru.php', 'rekap_absensi_guru.php', 'absensi_harian.php', 'rekap_absensi.php'])
            ],
            [
                'title' => 'Pengaturan',
                'icon' => 'fas fa-school',
                'url' => 'profil_madrasah.php',
                'active' => $current_page === 'profil_madrasah.php'
            ],
            [
                'title' => 'Pengguna',
                'icon' => 'fas fa-users',
                'url' => 'pengguna.php',
                'active' => $current_page === 'pengguna.php'
            ],
            [
                'title' => 'Backup & Restore',
                'icon' => 'fas fa-hdd',
                'url' => 'backup_restore.php',
                'active' => $current_page === 'backup_restore.php'
            ],
            [
                'title' => 'Log Aktivitas',
                'icon' => 'fas fa-history',
                'url' => 'activity_log.php',
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
        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => 'dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Absensi Kelas',
                'icon' => 'fas fa-calendar-check',
                'url' => 'absensi_kelas.php',
                'active' => $current_page === 'absensi_kelas.php'
            ],
            [
                'title' => 'Profil',
                'icon' => 'fas fa-user',
                'url' => 'profil.php',
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
        $menu_items = [
            [
                'title' => 'Dashboard',
                'icon' => 'fas fa-fire',
                'url' => 'dashboard.php',
                'active' => $current_page === 'dashboard.php'
            ],
            [
                'title' => 'Absensi Harian',
                'icon' => 'fas fa-calendar-check',
                'url' => 'absensi_kelas.php',
                'active' => $current_page === 'absensi_kelas.php'
            ],
            [
                'title' => 'Rekap',
                'icon' => 'fas fa-book',
                'url' => 'rekap_absensi.php',
                'active' => $current_page === 'rekap_absensi.php'
            ],
            [
                'title' => 'Profil & Pengaturan',
                'icon' => 'fas fa-user-cog',
                'url' => 'profil.php',
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
}
?>

<div class="main-sidebar">
    <aside id="sidebar-wrapper">
        <div class="sidebar-brand">
            <a href="dashboard.php">Sistem Absensi Siswa</a>
        </div>
        <div class="sidebar-brand sidebar-brand-sm">
            <a href="dashboard.php">SA</a>
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