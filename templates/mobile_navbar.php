<?php
// Navbar mobile terpusat (mobile only): SIMAD + nama sekolah + bel + avatar.
// Dipakai semua dashboard via include dari file dashboard masing-masing role.
$__nav_logo = trim((string)($school_profile['logo'] ?? ''));
$__nav_school = trim((string)($school_profile['nama_madrasah'] ?? ''));
if ($__nav_school === '') $__nav_school = 'SIMAD';
$__nav_name = isset($display_name) ? trim((string)$display_name) : '';
if ($__nav_name === '') {
    foreach (['nama', 'nama_guru', 'nama_siswa', 'username'] as $__k) {
        if (isset($_SESSION[$__k]) && trim((string)$_SESSION[$__k]) !== '') { $__nav_name = trim((string)$_SESSION[$__k]); break; }
    }
}
if ($__nav_name === '') $__nav_name = 'S';
$__nav_initial = strtoupper(mb_substr($__nav_name, 0, 1, 'UTF-8'));
if ($__nav_initial === '') $__nav_initial = 'S';
// Foto asli bila ada (kalau tidak ada, pakai inisial).
$__nav_level = function_exists('getUserLevel') ? getUserLevel() : '';
$__nav_photo = '';
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        if (in_array($__nav_level, ['guru', 'wali'], true)) {
            $__st = null;
            if (isset($_SESSION['nama_guru']) && trim((string)$_SESSION['nama_guru']) !== '') {
                $__st = $pdo->prepare("SELECT foto FROM tb_guru WHERE nama_guru = ? LIMIT 1");
                $__st->execute([trim((string)$_SESSION['nama_guru'])]);
            } elseif (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
                $__st = $pdo->prepare("SELECT foto FROM tb_guru WHERE id_guru = ? LIMIT 1");
                $__st->execute([(int)$_SESSION['user_id']]);
            }
            if ($__st) {
                $__foto = trim((string)($__st->fetchColumn() ?: ''));
                if ($__foto !== '' && is_file(dirname(__DIR__) . '/uploads/' . $__foto)) {
                    $__nav_photo = '../uploads/' . $__foto;
                }
            }
        } elseif ($__nav_level === 'siswa' && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            $__st = $pdo->prepare("SELECT foto FROM tb_siswa WHERE id_siswa = ? LIMIT 1");
            $__st->execute([(int)$_SESSION['user_id']]);
            $__foto = trim((string)($__st->fetchColumn() ?: ''));
            if ($__foto !== '' && is_file(dirname(__DIR__) . '/assets/img/siswa/' . $__foto)) {
                $__nav_photo = '../assets/img/siswa/' . $__foto;
            }
        } elseif (isset($_SESSION['username']) && trim((string)$_SESSION['username']) !== '') {
            $__st = $pdo->prepare("SELECT foto FROM tb_pengguna WHERE username = ? LIMIT 1");
            $__st->execute([trim((string)$_SESSION['username'])]);
            $__foto = trim((string)($__st->fetchColumn() ?: ''));
            if ($__foto !== '' && is_file(dirname(__DIR__) . '/assets/img/' . $__foto)) {
                $__nav_photo = '../assets/img/' . $__foto;
            }
        }
    }
} catch (Throwable $__e) {
    $__nav_photo = '';
}
// Label jenis dashboard.
$__nav_dash = [
    'admin' => 'Dashboard Admin',
    'kepala_madrasah' => 'Dashboard Kepala Madrasah',
    'tata_usaha' => 'Dashboard Tata Usaha',
    'guru' => 'Dashboard Guru',
    'wali' => 'Dashboard Wali Kelas',
    'siswa' => 'Dashboard Siswa',
][$__nav_level] ?? '';
// Lonceng: samakan dengan modal notifikasi yang tersedia di footer.
$__nav_modal = '';
$__nav_count = 0;
if (isset($unread_notifs_u) || isset($unread_count_u)) {
    $__nav_modal = '#mobileNotificationModalU';
    $__nav_count = isset($unread_count_u) ? (int)$unread_count_u : 0;
} elseif (in_array($__nav_level, ['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'], true)) {
    $__nav_modal = '#mobileNotificationModal';
    $__nav_count = isset($unread_count) ? (int)$unread_count : 0;
}
$__nav_label = $__nav_count > 99 ? '99+' : (string)$__nav_count;
?>
<div class="d-lg-none mapp-navbar">
    <div class="mapp-brand">
        <div class="mapp-logo">
            <?php if ($__nav_logo !== ''): ?>
                <img src="../assets/img/<?php echo htmlspecialchars($__nav_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
            <?php else: ?>
                S
            <?php endif; ?>
        </div>
        <div class="mapp-brand-text">
            <div class="mapp-app-name">SIMAD</div>
            <div class="mapp-school-name"><?php echo htmlspecialchars($__nav_school, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($__nav_dash !== ''): ?>
                <div class="mapp-dash-name"><?php echo htmlspecialchars($__nav_dash, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="mapp-top-actions">
        <?php if ($__nav_modal !== ''): ?>
            <button type="button" class="mapp-icon-btn" data-toggle="modal" data-target="<?php echo htmlspecialchars($__nav_modal, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Notifikasi">
                <i class="far fa-bell"></i>
                <?php if ($__nav_count > 0): ?>
                    <span class="notif-count-badge" data-count="<?php echo (int)$__nav_count; ?>"><?php echo htmlspecialchars($__nav_label, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </button>
        <?php endif; ?>
        <span class="mapp-avatar">
            <?php if ($__nav_photo !== ''): ?>
                <img src="<?php echo htmlspecialchars($__nav_photo, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto profil">
            <?php else: ?>
                <?php echo htmlspecialchars($__nav_initial, ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </span>
    </div>
</div>
