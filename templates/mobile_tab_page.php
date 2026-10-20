<?php
// Renderer halaman list bertumpuk satu kategori menu (mobile).
// Cara pakai (file kecil m_menu.php di tiap folder role):
//   <?php require_once __DIR__ . '/../templates/mobile_tab_page.php';
// URL: m_menu.php?g=Nama+Kategori (dibuat via mapp_tile_url()).
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/mobile_tabs.php';

if (function_exists('isLoggedIn') && !isLoggedIn()) {
    redirect('../login.php');
}
$user_level = function_exists('getUserLevel') ? getUserLevel() : '';
$allowed_levels = ['admin', 'kepala_madrasah', 'tata_usaha', 'bendahara', 'guru', 'wali', 'siswa'];
if ($user_level === '' || !in_array($user_level, $allowed_levels, true)) {
    if (function_exists('isAuthorized')) {
        if (!isAuthorized($allowed_levels)) {
            redirect('../login.php');
        }
    }
}
$role_dir = function_exists('mapp_role_dir') ? mapp_role_dir($user_level) : 'admin';

$mapp_g = isset($_GET['g']) ? trim((string)$_GET['g']) : '';
if ($mapp_g === '') {
    redirect(function_exists('app_url') ? app_url($role_dir . '/dashboard.php') : '../' . $role_dir . '/dashboard.php');
}
$mapp_g = mb_substr($mapp_g, 0, 60, 'UTF-8');
$page_title = $mapp_g;

$is_user_template = in_array($user_level, ['guru', 'wali'], true);
if ($is_user_template) {
    include __DIR__ . '/user_header.php';
} else {
    include __DIR__ . '/header.php';
}
include_once __DIR__ . '/sidebar.php';

// Cari grup kategori sesuai ?g= pada menu role ini.
$tab_data = ['groups' => [], 'singles' => []];
if (isset($menu_items) && is_array($menu_items)) {
    foreach ($menu_items as $mi) {
        if (strcasecmp(trim((string)($mi['title'] ?? '')), $mapp_g) === 0 && isset($mi['submenu']) && is_array($mi['submenu'])) {
            $tab_data = ['groups' => [[
                'title' => (string)$mi['title'],
                'icon' => $mi['icon'] ?? 'fas fa-folder',
                'items' => array_values($mi['submenu']),
            ]], 'singles' => []];
            $page_title = (string)$mi['title'];
            break;
        }
    }
}
$search_id = 'mappSearch-menu';
$total_submenus = 0;
if (!empty($tab_data['groups'])) {
    foreach ($tab_data['groups'] as $tg) {
        $total_submenus += count($tg['items'] ?? []);
    }
}
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="<?php echo htmlspecialchars(app_url($role_dir . '/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Dashboard</a></div>
                <div class="breadcrumb-item active"><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="mapp-menu-page-wrap mx-auto" style="max-width: 760px;">
            <!-- Hero Header Ala Mobile Apps -->
            <div class="mb-3">
                <div class="card border-0 text-white shadow-sm" style="background: linear-gradient(135deg, #10b981 0%, #059669 60%, #047857 100%); border-radius: 18px; overflow: hidden; position: relative;">
                    <div style="position: absolute; right: -20px; bottom: -20px; font-size: 110px; color: rgba(255,255,255,0.12); pointer-events: none;">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="card-body p-3" style="position: relative; z-index: 1;">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="badge badge-light text-success font-weight-bold px-2 py-1" style="border-radius: 999px; font-size: 10px;">
                                <i class="fas fa-layer-group mr-1"></i> Modul Akademik
                            </span>
                            <span class="small font-weight-bold" style="color: rgba(255,255,255,0.9); font-size: 11px;">
                                <?php echo $total_submenus; ?> Menu Tersedia
                            </span>
                        </div>
                        <h5 class="font-weight-bold mb-1 text-white" style="font-size: 18px;">
                            <?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                        <p class="mb-0 small" style="color: rgba(255,255,255,0.85); font-size: 11.5px; line-height: 1.4;">
                            Pilih jenis asesmen di bawah ini untuk input, verifikasi, atau rekapitulasi nilai siswa madrasah.
                        </p>
                    </div>
                </div>
            </div>

            <?php if (empty($tab_data['groups'])): ?>
                <div class="mapp-tab-list"><div class="mapp-empty">Kategori tidak ditemukan. <a href="<?php echo htmlspecialchars(app_url($role_dir . '/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Kembali ke Beranda</a>.</div></div>
            <?php else: ?>
                <?php echo mapp_render_tab_list($tab_data, $search_id); ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
if ($is_user_template) {
    include __DIR__ . '/user_footer.php';
} else {
    include __DIR__ . '/footer.php';
}
