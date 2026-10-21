<?php
require_once __DIR__ . '/../../config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $_SESSION['error'] = 'Sesi berakhir. Silakan muat ulang halaman dan coba lagi.'; header('Location: ' . BASE_URL . 'pages/pengaturan'); exit(); }
    // Identitas madrasah & pejabat TIDAK disimpan di sini.
    // Sumber tunggal: Pengaturan Admin SIMAD (tb_profil_madrasah) - lihat gajiProfilMadrasah().
    $jumlah_periode = $_POST['jumlah_periode'] ?? 1;
    $tahun_ajaran = $_POST['tahun_ajaran'] ?? '';
    $tempat = $_POST['tempat'] ?? '';

    // Nilai tanggal cetak sip: pakai apa yang dikirim form SAJA — jangan isi pakai tanggal sistem
    // (prior bug: kosong/not set → tertulis "hari ini", jadi cetakan selalu mengikuti tanggal kini)
    $existing_row = null;
    $existing_q = $conn->query('SELECT hari_tanggal FROM settings WHERE id=1 LIMIT 1');
    if ($existing_q && $existing_q->num_rows > 0) {
        $existing_row = $existing_q->fetch_assoc();
    }
    $hari_tanggal_existing = $existing_row !== null ? ($existing_row['hari_tanggal'] ?? null) : null;

    if (array_key_exists('hari_tanggal', $_POST)) {
        $raw_ht = trim((string)$_POST['hari_tanggal']);
        if ($raw_ht === '' || $raw_ht === '0000-00-00') {
            $hari_tanggal = null;
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_ht)) {
            $hari_tanggal = $raw_ht;
        } else {
            $hari_tanggal = $hari_tanggal_existing;
        }
    } else {
        $hari_tanggal = $hari_tanggal_existing;
    }
    
    // Handle periode based on jumlah_periode
    if ($jumlah_periode == 1) {
        $periode_aktif = $_POST['periode_aktif'] ?? $_POST['periode_single'] ?? date('Y-m');
        $periode_mulai = '';
        $periode_akhir = '';
    } else {
        $periode_aktif = $_POST['periode_aktif'] ?? $_POST['periode_mulai'] ?? date('Y-m');
        $periode_mulai = $_POST['periode_mulai'] ?? date('Y-m');
        $periode_akhir = $_POST['periode_akhir'] ?? date('Y-m');
    }
    
    // Get and validate numeric values for honor_per_jam
    $honor_per_jam = 0;
    $post_honor = $_POST['honor_per_jam'] ?? '';
    
    // Clean and convert honor_per_jam to float
    if (!empty($post_honor) && $post_honor !== '' && $post_honor !== null) {
        // Remove any non-numeric characters except decimal point
        $cleaned = preg_replace('/[^0-9.]/', '', (string)$post_honor);
        if ($cleaned !== '' && $cleaned !== '.') {
            $honor_per_jam = floatval($cleaned);
        }
    }
    
    // Ensure columns exist (for backward compatibility)
    // Check and add tahun_ajaran column if it doesn't exist
    $check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'tahun_ajaran'");
    if ($check_columns->num_rows == 0) {
        // If insentif_masa_bakti exists, rename it to tahun_ajaran
        $check_old = $conn->query("SHOW COLUMNS FROM settings LIKE 'insentif_masa_bakti'");
        if ($check_old->num_rows > 0) {
            $conn->query("ALTER TABLE settings CHANGE COLUMN insentif_masa_bakti tahun_ajaran VARCHAR(20) DEFAULT ''");
        } else {
            $conn->query("ALTER TABLE settings ADD COLUMN tahun_ajaran VARCHAR(20) DEFAULT ''");
        }
    }
    
    $check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'honor_per_jam'");
    if ($check_columns->num_rows == 0) {
        $conn->query("ALTER TABLE settings ADD COLUMN honor_per_jam DECIMAL(15,2) DEFAULT 0");
    }
    
    // Check and add periode_mulai and periode_akhir columns if they don't exist
    $check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'periode_mulai'");
    if ($check_columns->num_rows == 0) {
        $conn->query("ALTER TABLE settings ADD COLUMN periode_mulai VARCHAR(7) DEFAULT ''");
    }
    $check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'periode_akhir'");
    if ($check_columns->num_rows == 0) {
        $conn->query("ALTER TABLE settings ADD COLUMN periode_akhir VARCHAR(7) DEFAULT ''");
    }
    
    // Check and add tempat and hari_tanggal columns if they don't exist
    $check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'tempat'");
    if ($check_columns->num_rows == 0) {
        $conn->query("ALTER TABLE settings ADD COLUMN tempat VARCHAR(100) DEFAULT ''");
    }
    $check_columns = $conn->query("SHOW COLUMNS FROM settings LIKE 'hari_tanggal'");
    if ($check_columns->num_rows == 0) {
        $conn->query("ALTER TABLE settings ADD COLUMN hari_tanggal DATE DEFAULT NULL");
    }

    // Update kolom pengaturan gaji saja.
    // Kolom nama_madrasah / nama_kepala / nama_bendahara / logo tidak disentuh:
    // identitas madrasah & pejabat dikelola di Pengaturan Admin (tb_profil_madrasah).
    $sql = "UPDATE settings SET periode_aktif=?, jumlah_periode=?, periode_mulai=?, periode_akhir=?, tahun_ajaran=?, honor_per_jam=?, tempat=?, hari_tanggal=? WHERE id=1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sisssdss', $periode_aktif, $jumlah_periode, $periode_mulai, $periode_akhir, $tahun_ajaran, $honor_per_jam, $tempat, $hari_tanggal);
    
    if ($stmt->execute()) {
        gajiLogActivity($conn, "Mengubah pengaturan sistem", 'success');
        $_SESSION['success'] = "Pengaturan berhasil disimpan";
    } else {
        $_SESSION['error'] = "Gagal menyimpan pengaturan: " . $stmt->error;
    }
}

header('Location: ' . BASE_URL . 'pages/pengaturan');
exit();
?>

