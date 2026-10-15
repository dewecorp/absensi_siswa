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
$allowed_levels = ['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali', 'siswa'];
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
    redirect($role_dir . '/dashboard.php');
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

        <?php if (empty($tab_data['groups'])): ?>
            <div class="mapp-tab-list d-lg-none"><div class="mapp-empty">Kategori tidak ditemukan. <a href="<?php echo htmlspecialchars(app_url($role_dir . '/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Kembali ke Beranda</a>.</div></div>
        <?php else: ?>
            <?php echo mapp_render_tab_list($tab_data, $search_id); ?>
        <?php endif; ?>

        <!-- Desktop fallback: tabel sederhana -->
        <div class="row d-none d-lg-block">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead><tr><th>Grup</th><th>Menu</th><th>Aksi</th></tr></thead>
                                <tbody>
                                    <?php foreach (($tab_data['groups'] ?? []) as $g): ?>
                                        <?php foreach ($g['items'] as $sub): ?>
                                            <?php
                                            $u = (string)($sub['url'] ?? '#');
                                            $su = (strpos($u, 'http') === 0 || strpos($u, '#') === 0) ? $u : app_url($u);
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars((string)($sub['title'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><a href="<?php echo htmlspecialchars($su, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-primary">Buka</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <?php if (empty($tab_data['groups'])): ?>
                                        <tr><td colspan="3" class="text-center text-muted">Kategori tidak ditemukan.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
if ($is_user_template) {
    include __DIR__ . '/user_footer.php';
} else {
    include __DIR__ . '/footer.php';
}
