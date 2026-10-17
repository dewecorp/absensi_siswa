<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$can_crud = !$is_admin_or_kepala;
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$teacher_name = $_SESSION['nama_guru'] ?? '';
if (empty($teacher_name) && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT id_guru, nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $tg = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($tg) {
        $teacher_name = $tg['nama_guru'];
        $guru_id = (int)$tg['id_guru'];
    } else {
        $stmt = $pdo->prepare("SELECT g.id_guru, g.nama_guru FROM tb_guru g JOIN tb_pengguna p ON g.id_guru = p.id_guru WHERE p.id_pengguna = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $tg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tg) {
            $teacher_name = $tg['nama_guru'];
            $guru_id = (int)$tg['id_guru'];
        }
    }
}
if (empty($teacher_name)) {
    $teacher_name = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? '';
}

$wali_kelas_id = 0;
$wali_kelas_name = '';
$wali_class = null;

if (!empty($teacher_name)) {
    $stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
    $stmtWali->execute([$teacher_name]);
    $wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
    if (!$wali_class) {
        $base_name = trim(explode(',', $teacher_name)[0]);
        $stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas LIKE ?");
        $stmtWali->execute([$base_name . '%']);
        $wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
    }
}
if (!$wali_class && $guru_id > 0) {
    $stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
    $stmtWali->execute([$guru_id]);
    $wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
}
if (!$wali_class && isset($_SESSION['username'])) {
    $stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ?");
    $stmtWali->execute([$_SESSION['username']]);
    $wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
}
if (!$wali_class && isset($_SESSION['id_kelas']) && (int)$_SESSION['id_kelas'] > 0) {
    $stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE id_kelas = ?");
    $stmtWali->execute([(int)$_SESSION['id_kelas']]);
    $wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
}
if (!$wali_class && $guru_id > 0) {
    try {
        $stK = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE id_kelas IN (SELECT DISTINCT kelas_id FROM tb_jadwal_pelajaran WHERE guru_id = ?) LIMIT 1");
        $stK->execute([$guru_id]);
        $wali_class = $stK->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {
        $wali_class = null;
    }
}

if ($wali_class) {
    $wali_kelas_id = (int)$wali_class['id_kelas'];
    $wali_kelas_name = $wali_class['nama_kelas'];
}

$all_classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_kelas_id = $wali_kelas_id;
if ($is_admin_or_kepala && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

// Daftar Data Projek (Master) untuk dipilih guru saat membuat projek
$master_projek_list = [];
try {
    $master_projek_list = $pdo->query("SELECT id, nama_projek, tema, tujuan FROM tb_data_projek ORDER BY nama_projek ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {
    $master_projek_list = [];
}

$message = null;

// Query string untuk ekspor (semua projek / per projek)
$qs_projek_export = [];
if (!empty($_GET['session_type'])) {
    $qs_projek_export['session_type'] = (string)$_GET['session_type'];
}
if ($selected_kelas_id > 0) {
    $qs_projek_export['kelas'] = (int)$selected_kelas_id;
}
$url_projek_xls = 'export_projek_excel.php' . (!empty($qs_projek_export) ? '?' . http_build_query($qs_projek_export) : '');
$qs_projek_pdf_all = $qs_projek_export;
$qs_projek_pdf_all['mode'] = 'print';
$url_projek_pdf = 'export_projek_pdf.php?' . http_build_query($qs_projek_pdf_all);

// Handle CRUD Projek & Anggota
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Pengguna hanya memiliki akses lihat (monitoring).'];
    } else {
        $action = $_POST['action'] ?? '';

    // 1. Projek CRUD
    if ($action === 'tambah_projek' || $action === 'edit_projek') {
        $id = (int)($_POST['id'] ?? 0);
        $nama_projek = trim((string)($_POST['nama_projek'] ?? ''));
        $tema = trim((string)($_POST['tema'] ?? ''));
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $pembimbing = trim((string)($_POST['pembimbing'] ?? ''));
        $tgl_mulai = !empty($_POST['tgl_mulai']) ? date('Y-m-d', strtotime($_POST['tgl_mulai'])) : null;
        $tgl_selesai = !empty($_POST['tgl_selesai']) ? date('Y-m-d', strtotime($_POST['tgl_selesai'])) : null;
        $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Perencanaan', 'Berjalan', 'Selesai', 'Arsip'], true) ? $_POST['status'] : 'Perencanaan';

        if ($nama_projek === '' || $tema === '') {
            $message = ['type' => 'warning', 'text' => 'Nama Projek dan Tema wajib diisi.'];
        } else {
            try {
                if ($action === 'tambah_projek') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_projek_kokurikuler (
                            id_wali, nama_projek, tema, id_kelas, pembimbing,
                            tgl_mulai, tgl_selesai, deskripsi, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $nama_projek, $tema, $id_kelas, $pembimbing,
                        $tgl_mulai, $tgl_selesai, $deskripsi, $status
                    ]);
                    $message = ['type' => 'success', 'text' => 'Projek kokurikuler berhasil ditambahkan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_projek_kokurikuler SET
                            nama_projek = ?, tema = ?, id_kelas = ?, pembimbing = ?,
                            tgl_mulai = ?, tgl_selesai = ?, deskripsi = ?, status = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $nama_projek, $tema, $id_kelas, $pembimbing,
                        $tgl_mulai, $tgl_selesai, $deskripsi, $status, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Projek berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus_projek') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_projek_kokurikuler WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $pdo->prepare("DELETE FROM tb_projek_anggota WHERE id_projek = ?")->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Projek dan anggotanya berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }

    // 2. Anggota Projek CRUD (Fitur 19)
    elseif ($action === 'tambah_anggota') {
        $id_projek = (int)($_POST['id_projek'] ?? 0);
        $raw_siswa = $_POST['id_siswa'] ?? [];
        $id_siswa_list = [];
        if (is_array($raw_siswa)) {
            foreach ($raw_siswa as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) $id_siswa_list[] = $sid;
            }
        } else {
            $sid = (int)$raw_siswa;
            if ($sid > 0) $id_siswa_list[] = $sid;
        }

        $peran = in_array($_POST['peran'] ?? '', ['Ketua', 'Sekretaris', 'Anggota', 'Presentator', 'Lainnya'], true) ? $_POST['peran'] : 'Anggota';
        $kelompok = trim((string)($_POST['kelompok'] ?? 'Kelompok 1'));
        $catatan = trim((string)($_POST['catatan'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Aktif', 'Selesai', 'Keluar'], true) ? $_POST['status'] : 'Aktif';

        if ($id_projek <= 0 || empty($id_siswa_list)) {
            $message = ['type' => 'warning', 'text' => 'Pilih minimal satu siswa untuk ditambahkan ke projek.'];
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO tb_projek_anggota (id_projek, id_siswa, peran, kelompok, catatan, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE peran = VALUES(peran), kelompok = VALUES(kelompok), catatan = VALUES(catatan), status = VALUES(status)
                ");
                foreach ($id_siswa_list as $sid) {
                    $stmt->execute([$id_projek, $sid, $peran, $kelompok, $catatan, $status]);
                }
                $pdo->commit();
                $cnt = count($id_siswa_list);
                $message = ['type' => 'success', 'text' => ($cnt > 1 ? "$cnt siswa" : "Anggota projek") . ' berhasil disimpan ke projek.'];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = ['type' => 'danger', 'text' => 'Gagal menambah anggota: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus_anggota') {
        $id_anggota = (int)($_POST['id_anggota'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_projek_anggota WHERE id = ?")->execute([$id_anggota]);
            $message = ['type' => 'success', 'text' => 'Anggota dikeluarkan dari projek.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus anggota: ' . $e->getMessage()];
        }
    }
}
}

// Siswa kelas ini
$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch projek rows + hitung peserta
$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if (!$is_admin_or_kepala) {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, c.nama_kelas,
           (SELECT COUNT(*) FROM tb_projek_anggota pa WHERE pa.id_projek = p.id AND pa.status = 'Aktif') AS jumlah_peserta
    FROM tb_projek_kokurikuler p
    LEFT JOIN tb_kelas c ON c.id_kelas = p.id_kelas
    WHERE $where_sql
    ORDER BY p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Endpoint JSON untuk detail anggota projek & daftar siswa
if (isset($_GET['ajax_anggota']) && (int)$_GET['ajax_anggota'] === 1) {
    // Bersihkan buffer output agar JSON murni tanpa whitespace/warning
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    $pid = (int)($_GET['id_projek'] ?? 0);
    
    // Cari id_kelas dari projek ini jika ada
    $projek_kelas_id = (int)($_GET['id_kelas'] ?? 0);
    if ($projek_kelas_id <= 0 && $pid > 0) {
        $stPK = $pdo->prepare("SELECT id_kelas FROM tb_projek_kokurikuler WHERE id = ?");
        $stPK->execute([$pid]);
        $pkRow = $stPK->fetch(PDO::FETCH_ASSOC);
        if ($pkRow && !empty($pkRow['id_kelas'])) {
            $projek_kelas_id = (int)$pkRow['id_kelas'];
        }
    }
    if ($projek_kelas_id <= 0) {
        $projek_kelas_id = $selected_kelas_id;
    }

    $stA = $pdo->prepare("
        SELECT pa.*, s.nama_siswa, s.nisn
        FROM tb_projek_anggota pa
        JOIN tb_siswa s ON s.id_siswa = pa.id_siswa
        WHERE pa.id_projek = ?
        ORDER BY pa.kelompok ASC, pa.peran ASC, s.nama_siswa ASC
    ");
    $stA->execute([$pid]);
    $anggota = $stA->fetchAll(PDO::FETCH_ASSOC);

    // Ambil daftar siswa untuk dropdown
    $siswa_modal = [];
    if ($projek_kelas_id > 0) {
        $stSM = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stSM->execute([$projek_kelas_id]);
        $siswa_modal = $stSM->fetchAll(PDO::FETCH_ASSOC);
    }
    // Fallback 1: ambil siswa dari selected_kelas_id jika projek_kelas_id kosong
    if (empty($siswa_modal) && $selected_kelas_id > 0) {
        $stSM = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
        $stSM->execute([$selected_kelas_id]);
        $siswa_modal = $stSM->fetchAll(PDO::FETCH_ASSOC);
    }
    // Fallback 2: jika masih kosong (misal projek lintas/umum), ambil seluruh siswa aktif
    if (empty($siswa_modal)) {
        $stSM = $pdo->query("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa ORDER BY nama_siswa ASC LIMIT 100");
        $siswa_modal = $stSM->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => 'success',
        'id_projek' => $pid,
        'id_kelas' => $projek_kelas_id,
        'anggota' => $anggota,
        'siswa' => $siswa_modal
    ]);
    exit;
}

// Ambil data kelompok dan siswa per projek untuk kartu
$kelompok_by_projek = [];
$projek_ids = array_column($rows, 'id');
if (!empty($projek_ids)) {
    $in_ids = implode(',', array_map('intval', $projek_ids));
    try {
        $stK = $pdo->query("
            SELECT pa.id_projek, pa.kelompok, pa.peran, s.nama_siswa, s.nisn
            FROM tb_projek_anggota pa
            JOIN tb_siswa s ON s.id_siswa = pa.id_siswa
            WHERE pa.id_projek IN ($in_ids) AND pa.status = 'Aktif' AND pa.kelompok IS NOT NULL AND pa.kelompok != ''
            ORDER BY pa.kelompok ASC, FIELD(pa.peran, 'Ketua', 'Sekretaris', 'Anggota', 'Presentator', 'Lainnya'), s.nama_siswa ASC
        ");
        while ($krow = $stK->fetch(PDO::FETCH_ASSOC)) {
            $kelompok_by_projek[$krow['id_projek']][$krow['kelompok']][] = [
                'nama' => $krow['nama_siswa'],
                'peran' => $krow['peran'],
                'nisn' => $krow['nisn']
            ];
        }
    } catch (Throwable $e) {}
}

$status_projek_options = ['Perencanaan', 'Berjalan', 'Selesai', 'Arsip'];
$peran_options = ['Ketua', 'Sekretaris', 'Anggota', 'Presentator', 'Lainnya'];

if (!function_exists('getProjekTemaStyle')) {
    function getProjekTemaStyle(string $tema): array {
        $t = strtolower(trim($tema));
        $dpl_dimensi = [
            'keimanan dan ketakwaan kepada tuhan yme', 'kewargaan', 'penalaran kritis',
            'kreativitas', 'kolaborasi', 'kemandirian', 'kesehatan', 'komunikasi',
        ];
        if (strpos($t, 'cinta') !== false) {
            return ['bg' => '#ffe4e6', 'color' => '#be123c', 'icon' => 'bi bi-heart-fill'];
        } elseif (in_array($t, $dpl_dimensi, true)) {
            return ['bg' => '#ede9fe', 'color' => '#6d28d9', 'icon' => 'bi bi-mortarboard-fill'];
        } elseif (strpos($t, 'hidup') !== false || strpos($t, 'lingkungan') !== false || strpos($t, 'alam') !== false) {
            return ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi bi-tree-fill'];
        } elseif (strpos($t, 'kearifan') !== false || strpos($t, 'budaya') !== false) {
            return ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'bi bi-flower1'];
        } elseif (strpos($t, 'teknologi') !== false || strpos($t, 'rekayasa') !== false) {
            return ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'bi bi-cpu-fill'];
        } elseif (strpos($t, 'wirausaha') !== false || strpos($t, 'bisnis') !== false) {
            return ['bg' => '#ccfbf1', 'color' => '#0f766e', 'icon' => 'bi bi-shop-window'];
        } elseif (strpos($t, 'bhinneka') !== false || strpos($t, 'demokrasi') !== false) {
            return ['bg' => '#f3e8ff', 'color' => '#7e22ce', 'icon' => 'bi bi-people-fill'];
        } elseif (strpos($t, 'jiwa') !== false || strpos($t, 'kesehatan') !== false || strpos($t, 'raga') !== false) {
            return ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => 'bi bi-heart-pulse-fill'];
        }
        return ['bg' => '#f0fdf4', 'color' => '#16a34a', 'icon' => 'bi bi-journal-code'];
    }
}

if (!function_exists('getProjekStatusStyle')) {
    function getProjekStatusStyle(string $status): array {
        if ($status === 'Selesai') {
            return ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi bi-check-circle-fill'];
        } elseif ($status === 'Berjalan') {
            return ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'bi bi-play-circle-fill'];
        } elseif ($status === 'Perencanaan') {
            return ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'bi bi-clock-history'];
        }
        return ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'bi bi-archive-fill'];
    }
}

$page_title = 'Daftar Projek / Kokurikuler';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-projek').length) {
        $('#table-projek').DataTable({
            'order': [[0, 'asc']],
            'columnDefs': [{ 'sortable': false, 'targets': [9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada projek ditemukan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    $('#btnTambahProjek').on('click', function() {
        $('#formProjekAction').val('tambah_projek');
        $('#projekId').val('');
        $('#modalProjekTitle').text('Tambah Projek / Kokurikuler Baru');
        $('#formProjek')[0].reset();
        if ($('#inp_pilih_data_projek').length) { $('#inp_pilih_data_projek').val(''); }
        $('#modalProjek').modal('show');
    });

    // Auto-isi Nama, Tema & Tujuan dari Data Projek (Master)
    $(document).on('change', '#inp_pilih_data_projek', function() {
        var opt = $(this).find('option:selected');
        if (!$(this).val()) return;
        $('#inp_nama').val(opt.data('nama') || '');
        $('#inp_tema').val(opt.data('tema') || '');
        if (opt.data('tujuan')) {
            $('#inp_deskripsi').val(opt.data('tujuan'));
        }
    });

    $(document).on('click', '.btn-edit-projek', function() {
        var data = $(this).data('json');
        $('#formProjekAction').val('edit_projek');
        $('#projekId').val(data.id);
        $('#modalProjekTitle').text('Edit Projek / Kokurikuler');
        $('#inp_nama').val(data.nama_projek);
        $('#inp_tema').val(data.tema);
        $('#inp_pembimbing').val(data.pembimbing || '');
        $('#inp_mulai').val(data.tgl_mulai || '');
        $('#inp_selesai').val(data.tgl_selesai || '');
        $('#inp_deskripsi').val(data.deskripsi || '');
        $('#inp_status').val(data.status);
        if ($('#inp_pilih_data_projek').length) { $('#inp_pilih_data_projek').val(''); }
        $('#modalProjek').modal('show');
    });

    // Auto-tinggi textarea Deskripsi / Tujuan agar teks panjang tidak terpotong
    function simadAutoGrowDeskripsi() {
        var el = document.getElementById('inp_deskripsi');
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.max(140, el.scrollHeight + 2) + 'px';
    }
    $(document).on('input', '#inp_deskripsi', simadAutoGrowDeskripsi);
    $('#modalProjek').on('shown.bs.modal', simadAutoGrowDeskripsi);

    // Detail & Anggota Projek (Fitur 19)
    $(document).on('click', '.btn-anggota-projek', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e){}
        }
        var pid = data.id;
        var pkelas = data.id_kelas || 0;
        $('#mdl_projek_id_anggota').val(pid);
        $('#mdl_projek_nama').text(data.nama_projek);
        $('#mdl_projek_tema').text(data.tema);

        resetFormAnggota();
        loadTabelAnggota(pid, pkelas);
        $('#modalAnggotaProjek').modal('show');
    });

    function initSelect2Siswa() {
        if ($.fn.select2) {
            if ($('#mdl_id_siswa').hasClass('select2-hidden-accessible')) {
                $('#mdl_id_siswa').select2('destroy');
            }
            $('#mdl_id_siswa').select2({
                dropdownParent: $('#modalAnggotaProjek'),
                placeholder: '-- Pilih satu atau lebih siswa --',
                width: '100%',
                closeOnSelect: false
            });
        }
    }

    $('#modalAnggotaProjek').on('shown.bs.modal', function() {
        initSelect2Siswa();
    });

    $('#btnSelectAllSiswa').on('click', function(e) {
        e.preventDefault();
        var allVals = [];
        $('#mdl_id_siswa option').each(function() {
            if (this.value) allVals.push(this.value);
        });
        $('#mdl_id_siswa').val(allVals).trigger('change');
    });

    $('#btnUnselectAllSiswa').on('click', function(e) {
        e.preventDefault();
        $('#mdl_id_siswa').val([]).trigger('change');
    });

    function resetFormAnggota() {
        $('#formAnggotaAction').val('tambah_anggota');
        $('#titleFormAnggota').text('Tambah / Atur Siswa ke Projek');
        $('#btnBatalEditAnggota').addClass('d-none');
        $('#btnSubmitAnggota').html('<i class="bi bi-plus-circle mr-1"></i> Simpan Siswa ke Projek');
        $('#mdl_id_siswa').prop('disabled', false).val([]).trigger('change');
        $('#mdl_kelompok').val('Kelompok 1');
        $('#mdl_peran').val('Anggota');
        $('#mdl_status_anggota').val('Aktif');
        $('#mdl_catatan_anggota').val('');
    }

    $('#btnBatalEditAnggota').on('click', function() {
        resetFormAnggota();
    });

    function loadTabelAnggota(pid, pkelas) {
        $('#bodyTabelAnggota').html('<tr><td colspan="8" class="text-center p-3"><i class="fas fa-spinner fa-spin"></i> Memuat anggota...</td></tr>');
        var urlParams = new URLSearchParams(window.location.search);
        var sessionType = urlParams.get('session_type');
        var reqUrl = 'projek_kokurikuler.php?ajax_anggota=1&id_projek=' + pid;
        if (pkelas) {
            reqUrl += '&id_kelas=' + pkelas;
        }
        if (sessionType) {
            reqUrl += '&session_type=' + encodeURIComponent(sessionType);
        }

        $.ajax({
            url: reqUrl,
            dataType: 'json',
            cache: false,
            success: function(resp) {
                var list = Array.isArray(resp) ? resp : (resp.anggota || []);
                var siswa = resp.siswa || [];

                // Refresh dropdown siswa jika data siswa dikirim dari server
                if (siswa && siswa.length > 0) {
                    var curVal = $('#mdl_id_siswa').val() || [];
                    var sOptions = '';
                    siswa.forEach(function(s) {
                        sOptions += '<option value="' + s.id_siswa + '">' + s.nama_siswa + (s.nisn ? ' (' + s.nisn + ')' : '') + '</option>';
                    });
                    $('#mdl_id_siswa').html(sOptions);
                    initSelect2Siswa();
                    if (curVal.length > 0) {
                        $('#mdl_id_siswa').val(curVal).trigger('change');
                    }
                }

                if (!list || !list.length) {
                    $('#bodyTabelAnggota').html('<tr><td colspan="8" class="text-center text-muted py-3">Belum ada anggota yang terdaftar pada projek ini.</td></tr>');
                    return;
                }
                var html = '';
                list.forEach(function(item, idx) {
                    var st_badge = item.status === 'Aktif' ? 'success' : (item.status === 'Selesai' ? 'info' : 'secondary');
                    var itemJson = JSON.stringify(item).replace(/'/g, "&#39;");
                    html += '<tr>';
                    html += '<td class="text-center">' + (idx + 1) + '</td>';
                    html += '<td><strong>' + item.nama_siswa + '</strong></td>';
                    html += '<td>' + (item.nisn || '-') + '</td>';
                    html += '<td><span class="badge badge-light border">' + item.peran + '</span></td>';
                    html += '<td><span class="badge badge-primary">' + (item.kelompok || '-') + '</span></td>';
                    html += '<td><small>' + (item.catatan || '-') + '</small></td>';
                    html += '<td class="text-center"><span class="badge badge-' + st_badge + '">' + item.status + '</span></td>';
                    html += '<td class="text-center">';
                    html += '  <button type="button" class="btn btn-warning btn-sm py-0 px-2 mr-1 btn-edit-anggota" data-json=\'' + itemJson + '\' title="Edit Anggota"><i class="fas fa-edit"></i></button>';
                    html += '  <button type="button" class="btn btn-danger btn-sm py-0 px-2 btn-hapus-anggota" data-id="' + item.id + '" title="Keluarkan Siswa"><i class="fas fa-times"></i></button>';
                    html += '</td>';
                    html += '</tr>';
                });
                $('#bodyTabelAnggota').html(html);
            },
            error: function(xhr, status, err) {
                console.error('AJAX Error:', status, err);
                $('#bodyTabelAnggota').html('<tr><td colspan="8" class="text-center text-danger py-3">Gagal memuat data anggota. Silakan muat ulang halaman.</td></tr>');
            }
        });
    }

    $(document).on('click', '.btn-edit-anggota', function() {
        var item = $(this).data('json');
        if (typeof item === 'string') {
            try { item = JSON.parse(item); } catch(e){}
        }
        $('#formAnggotaAction').val('tambah_anggota'); // ON DUPLICATE KEY UPDATE akan update record
        $('#titleFormAnggota').text('Edit Siswa di Projek: ' + item.nama_siswa);
        $('#btnBatalEditAnggota').removeClass('d-none');
        $('#btnSubmitAnggota').html('<i class="bi bi-save mr-1"></i> Perbarui Data Siswa');

        $('#mdl_id_siswa').val([String(item.id_siswa)]).trigger('change');
        $('#mdl_kelompok').val(item.kelompok);
        $('#mdl_peran').val(item.peran);
        $('#mdl_status_anggota').val(item.status);
        $('#mdl_catatan_anggota').val(item.catatan);

        // Scroll form anggota ke pandangan
        $('#titleFormAnggota')[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    $(document).on('click', '.btn-hapus-anggota', function() {
        var id = $(this).data('id');
        var pid = $('#mdl_projek_id_anggota').val();
        Swal.fire({
            title: 'Keluarkan siswa?',
            text: 'Siswa akan dikeluarkan dari projek ini.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Keluarkan',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusAnggotaId').val(id);
                $('#formHapusAnggota').submit();
            }
        });
    });

    $(document).on('click', '.btn-hapus-projek', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Projek?',
            text: 'Projek "' + nama + '" dan seluruh data kelompok/anggota akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (res.isConfirmed) {
                $('#formHapusId').val(id);
                $('#formHapus').submit();
            }
        });
    });

    $('#quick-search-projek').on('input', function() {
        var val = $(this).val().toLowerCase().trim();
        var count = 0;
        $('.projek-card-item').each(function() {
            var text = $(this).text().toLowerCase();
            var show = text.indexOf(val) > -1;
            $(this).toggle(show);
            if (show) count++;
        });
        $('#count-projek').text(count);
    });
});
JS
];

if (!empty($message)) {
    $swal_icon = $message['type'] === 'danger' ? 'error' : $message['type'];
    $swal_icon = in_array($swal_icon, ['success', 'error', 'warning', 'info'], true) ? $swal_icon : 'info';
    $swal_title = $message['type'] === 'success' ? 'Berhasil' : 'Perhatian';
    $js_page[] = "if (window.Swal) { Swal.fire({ icon: " . json_encode($swal_icon) . ", title: " . json_encode($swal_title) . ", text: " . json_encode($message['text']) . ", timer: 2400, timerProgressBar: true, showConfirmButton: false }); } else { alert(" . json_encode($message['text']) . "); }";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Styling Kartu Modern Projek Kokurikuler */
.simad-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}
.simad-item-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s ease;
    overflow: hidden;
    position: relative;
}
.simad-item-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.09);
    border-color: #e2e8f0;
}
.simad-card-header {
    padding: 16px 18px 12px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.simad-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
}
.simad-card-main {
    flex: 1;
    min-width: 0;
}
.simad-card-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    margin-bottom: 4px;
    flex-wrap: wrap;
}
.simad-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 999px;
    line-height: 1.2;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
}
.simad-card-name {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.35;
    word-break: break-word;
    overflow-wrap: break-word;
    white-space: normal;
    margin-bottom: 2px;
}
.simad-card-sub {
    font-size: 11.5px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.simad-card-body {
    padding: 0 18px 14px;
    flex: 1;
}
.simad-info-box {
    background: #f8fafc;
    border-radius: 14px;
    padding: 10px 12px;
    font-size: 12.5px;
    color: #334155;
    line-height: 1.5;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.simad-info-row {
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.simad-info-label {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
}
.simad-info-val {
    word-break: break-word;
    overflow-wrap: break-word;
}
.simad-card-footer {
    padding: 12px 18px;
    background: #ffffff;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.simad-card-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.simad-btn-act {
    width: 34px;
    height: 34px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    cursor: pointer;
    font-size: 13.5px;
    transition: transform 0.15s ease, background-color 0.15s ease;
    text-decoration: none !important;
    -webkit-appearance: none;
    appearance: none;
}
.simad-btn-act:active {
    transform: scale(0.92);
}
.simad-btn-act:focus,
.simad-btn-act:focus-visible {
    outline: none !important;
    box-shadow: none !important;
}
.simad-btn-anggota {
    background: #e0f2fe;
    color: #0284c7;
    width: auto;
    padding: 0 12px;
    font-size: 12px;
    font-weight: 700;
    gap: 5px;
}
.simad-btn-anggota:hover { background: #bae6fd; color: #0369a1; }
.simad-btn-excel { background: #dcfce7; color: #15803d; }
.simad-btn-excel:hover { background: #bbf7d0; color: #166534; }
.simad-btn-pdf { background: #fee2e2; color: #dc2626; }
.simad-btn-pdf:hover { background: #fecaca; color: #b91c1c; }
.simad-btn-edit { background: #fef3c7; color: #b45309; }
.simad-btn-edit:hover { background: #fde68a; color: #92400e; }
.simad-btn-del { background: #fee2e2; color: #b91c1c; }
.simad-btn-del:hover { background: #fecaca; color: #991b1b; }

.simad-kelompok-container {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 6px;
    max-height: 280px;
    overflow-y: auto;
    padding-right: 4px;
}
.simad-kelompok-container::-webkit-scrollbar {
    width: 4px;
}
.simad-kelompok-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}
.simad-group-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 8px 10px;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
}
.simad-group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px dashed #e2e8f0;
    font-size: 12px;
}
.simad-group-count {
    background: #f1f5f9;
    color: #475569;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
}
.simad-group-students {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.simad-student-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    font-size: 11px;
    color: #334155;
    background: #f8fafc;
    padding: 4px 7px;
    border-radius: 6px;
}
.simad-student-name {
    font-weight: 600;
    word-break: break-word;
    overflow-wrap: break-word;
}

@media (max-width: 575.98px) {
    .simad-card-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .simad-item-card {
        border-radius: 16px;
    }
    .simad-card-header {
        padding: 14px 14px 10px;
    }
    .simad-card-icon {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        font-size: 22px;
    }
    .simad-card-body {
        padding: 0 14px 12px;
    }
    .simad-card-footer {
        padding: 10px 14px;
    }
}
/* Modal fix for modalAnggotaProjek */
#modalAnggotaProjek .modal-dialog {
    max-width: 1000px;
    margin: 1.75rem auto;
}
#modalAnggotaProjek .modal-content {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 16px;
    border: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}
#modalAnggotaProjek .modal-header {
    flex-shrink: 0;
    border-bottom: 1px solid #e2e8f0;
    background: #ffffff;
    padding: 16px 20px;
}
#modalAnggotaProjek .modal-body {
    flex: 1 1 auto;
    max-height: none !important;
    overflow-y: auto !important;
    padding: 20px;
}
#modalAnggotaProjek .modal-footer {
    flex-shrink: 0;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    padding: 12px 20px;
}
#modalAnggotaProjek .select2-container {
    width: 100% !important;
}
#modalAnggotaProjek .select2-container .select2-selection--multiple {
    min-height: 40px !important;
    border: 1px solid #ced4da !important;
    border-radius: 8px !important;
    padding: 3px 6px !important;
    background: #ffffff !important;
}
#modalAnggotaProjek .select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important;
}
#modalAnggotaProjek .select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #e0e7ff !important;
    border: 1px solid #c7d2fe !important;
    color: #4338ca !important;
    font-weight: 600 !important;
    border-radius: 6px !important;
    padding: 2px 8px !important;
    margin: 3px 4px 3px 0 !important;
    font-size: 12px !important;
}
#modalAnggotaProjek .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: #6366f1 !important;
    margin-right: 5px !important;
    font-weight: bold !important;
}
#modalAnggotaProjek .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #b91c1c !important;
}
#modalAnggotaProjek .select2-dropdown {
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15) !important;
    z-index: 1060 !important;
}

/* Select2 mobile: panel center (atas-bawah ada jarak), tanpa overlay backdrop */
@media (max-width: 767.98px) {
    #modalAnggotaProjek .select2-dropdown {
        position: fixed !important;
        top: 50% !important;
        left: 50% !important;
        bottom: auto !important;
        right: auto !important;
        transform: translate(-50%, -50%) !important;
        width: calc(100% - 32px) !important;
        max-width: 420px !important;
        max-height: 72vh !important;
        margin: 0 !important;
        padding: 14px !important;
        animation: none !important;
        border: none !important;
        border-radius: 18px !important;
        background: #ffffff !important;
        box-shadow: 0 16px 48px rgba(15, 23, 42, 0.28) !important;
        z-index: 99999 !important;
        display: flex !important;
        flex-direction: column !important;
        overflow: hidden !important;
    }
    #modalAnggotaProjek .select2-dropdown::before {
        display: none !important;
    }
    #modalAnggotaProjek .select2-search--dropdown {
        padding: 0 0 10px 0 !important;
        flex: 0 0 auto;
    }
    #modalAnggotaProjek .select2-search--dropdown .select2-search__field {
        height: 44px !important;
        padding: 8px 14px !important;
        font-size: 16px !important;
        font-weight: 600 !important;
        border-radius: 12px !important;
        border: 1.5px solid #e2e8f0 !important;
        background: #f8fafc !important;
    }
    #modalAnggotaProjek .select2-results {
        flex: 1 1 auto;
        min-height: 0;
    }
    #modalAnggotaProjek .select2-results > .select2-results__options {
        max-height: calc(72vh - 100px) !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    #modalAnggotaProjek .select2-container--default .select2-results__option {
        padding: 11px 12px !important;
        font-size: 13.5px !important;
        min-height: 44px !important;
        display: flex !important;
        align-items: center !important;
        border-radius: 12px !important;
        margin-bottom: 4px !important;
    }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Projek / Kokurikuler <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($is_admin_or_kepala): ?>
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="form-inline">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <label class="mr-2">Pilih Kelas:</label>
                        <select name="kelas" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Semua Kelas --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                    <h4 class="mb-0">Projek Kokurikuler Kelas</h4>
                    <div class="d-flex align-items-center" style="gap: 6px; flex-wrap: wrap;">
                        <a href="<?= htmlspecialchars($url_projek_xls) ?>" class="btn btn-success btn-sm" title="Ekspor Semua Projek ke Excel">
                            <i class="bi bi-file-earmark-excel mr-1"></i> Excel
                        </a>
                        <a href="<?= htmlspecialchars($url_projek_pdf) ?>" target="_blank" class="btn btn-danger btn-sm" title="Cetak / PDF Semua Projek">
                            <i class="bi bi-file-earmark-pdf mr-1"></i> PDF
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahProjek">
                            <i class="fas fa-plus mr-1"></i> Buat Projek Baru
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Quick Search Bar -->
                    <div class="row align-items-center mb-3">
                        <div class="col-md-6 col-12 mb-2 mb-md-0">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-white border-right-0" style="border-radius: 14px 0 0 14px; border-color: #e2e8f0;"><i class="bi bi-search text-muted"></i></span>
                                </div>
                                <input type="text" id="quick-search-projek" class="form-control border-left-0" style="border-radius: 0 14px 14px 0; border-color: #e2e8f0; box-shadow: none;" placeholder="Cari nama projek, tema, pembimbing, atau status...">
                            </div>
                        </div>
                        <div class="col-md-6 col-12 text-md-right text-muted small">
                            Menampilkan <span id="count-projek" class="font-weight-bold text-dark"><?= count($rows) ?></span> projek kokurikuler
                        </div>
                    </div>

                    <?php if (!empty($rows)): ?>
                        <div class="simad-card-grid">
                            <?php foreach ($rows as $i => $r): ?>
                                <?php
                                $temaStyle = getProjekTemaStyle($r['tema'] ?? '');
                                $statusStyle = getProjekStatusStyle($r['status'] ?? '');
                                $tgl_m = !empty($r['tgl_mulai']) ? date('d/m/Y', strtotime($r['tgl_mulai'])) : '-';
                                $tgl_s = !empty($r['tgl_selesai']) ? date('d/m/Y', strtotime($r['tgl_selesai'])) : '-';
                                $raw_d = strip_tags($r['deskripsi'] ?? '');
                                ?>
                                <div class="simad-item-card projek-card-item">
                                    <div class="simad-card-header">
                                        <div class="simad-card-icon" style="background: <?= $temaStyle['bg'] ?>; color: <?= $temaStyle['color'] ?>;">
                                            <i class="<?= $temaStyle['icon'] ?>"></i>
                                        </div>
                                        <div class="simad-card-main">
                                            <div class="simad-card-meta">
                                                <span class="simad-badge-pill" style="background: #e0f2fe; color: #0284c7;">
                                                    <i class="bi bi-people-fill"></i> <?= (int)$r['jumlah_peserta'] ?> Siswa
                                                </span>
                                                <?php
                                                $list_k = $kelompok_by_projek[$r['id']] ?? [];
                                                if (!empty($list_k)):
                                                ?>
                                                <span class="simad-badge-pill" style="background: #fef3c7; color: #b45309;">
                                                    <i class="bi bi-grid-fill"></i> <?= count($list_k) ?> Kelompok
                                                </span>
                                                <?php endif; ?>
                                                <span class="simad-badge-pill" style="background: <?= $statusStyle['bg'] ?>; color: <?= $statusStyle['color'] ?>;">
                                                    <i class="<?= $statusStyle['icon'] ?>"></i> <?= htmlspecialchars($r['status']) ?>
                                                </span>
                                            </div>
                                            <div class="simad-card-name" title="<?= htmlspecialchars($r['nama_projek']) ?>">
                                                <?= htmlspecialchars($r['nama_projek']) ?>
                                            </div>
                                            <div class="simad-card-sub">
                                                <span>Kelas: <?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></span>
                                                <span>&bull;</span>
                                                <span>Pembimbing: <?= htmlspecialchars($r['pembimbing'] ?: '-') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="simad-card-body">
                                        <div class="mb-2">
                                            <span class="simad-badge-pill" style="background: <?= $temaStyle['bg'] ?>; color: <?= $temaStyle['color'] ?>;">
                                                <i class="bi bi-bookmark-star-fill"></i> <?= htmlspecialchars($r['tema']) ?>
                                            </span>
                                        </div>
                                        <div class="simad-info-box">
                                            <div class="simad-info-row">
                                                <span class="simad-info-label">Periode Pelaksanaan</span>
                                                <div class="simad-info-val text-dark font-weight-500">
                                                    <i class="bi bi-calendar-range mr-1 text-muted"></i><?= $tgl_m ?> s/d <?= $tgl_s ?>
                                                </div>
                                            </div>
                                            <div class="simad-info-row">
                                                <span class="simad-info-label">Kelompok & Anggota Siswa <?= !empty($list_k) ? '(' . count($list_k) . ' Kelompok)' : '' ?></span>
                                                <?php if (!empty($list_k)): ?>
                                                    <div class="simad-kelompok-container">
                                                        <?php foreach ($list_k as $nama_klp => $anggota_klp): ?>
                                                            <div class="simad-group-box">
                                                                <div class="simad-group-head">
                                                                    <div>
                                                                        <i class="bi bi-people-fill text-primary mr-1"></i>
                                                                        <strong><?= htmlspecialchars($nama_klp) ?></strong>
                                                                    </div>
                                                                    <span class="simad-group-count"><?= count($anggota_klp) ?> siswa</span>
                                                                </div>
                                                                <div class="simad-group-students">
                                                                    <?php foreach ($anggota_klp as $m): ?>
                                                                        <div class="simad-student-item">
                                                                            <span class="simad-student-name">
                                                                                <i class="bi bi-person text-muted mr-1"></i><?= htmlspecialchars($m['nama']) ?>
                                                                            </span>
                                                                            <?php if ($m['peran'] === 'Ketua'): ?>
                                                                                <span class="badge badge-warning text-dark font-weight-bold" style="font-size: 9.5px; padding: 2px 6px; border-radius: 4px;">Ketua</span>
                                                                            <?php elseif ($m['peran'] === 'Sekretaris'): ?>
                                                                                <span class="badge badge-info font-weight-bold" style="font-size: 9.5px; padding: 2px 6px; border-radius: 4px;">Sekretaris</span>
                                                                            <?php elseif ($m['peran'] !== 'Anggota'): ?>
                                                                                <span class="badge badge-light border font-weight-bold" style="font-size: 9.5px; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($m['peran']) ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="simad-info-val text-muted small font-italic">
                                                        <i class="bi bi-info-circle mr-1"></i>Belum ada kelompok dibentuk
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($raw_d)): ?>
                                            <div class="simad-info-row">
                                                <span class="simad-info-label">Deskripsi</span>
                                                <div class="simad-info-val text-muted">
                                                    <?= htmlspecialchars(mb_strimwidth($raw_d, 0, 95, '...')) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="simad-card-footer">
                                        <?php
                                        $qs_one = $qs_projek_export;
                                        $qs_one['id'] = (int)$r['id'];
                                        $qs_one_pdf = $qs_one;
                                        $qs_one_pdf['mode'] = 'print';
                                        ?>
                                        <div class="simad-card-actions">
                                            <button type="button" class="simad-btn-act simad-btn-anggota btn-anggota-projek" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Lihat/Kelola Anggota & Kelompok">
                                                <i class="bi bi-people-fill"></i> Kelola Anggota
                                            </button>
                                            <a href="export_projek_excel.php?<?= htmlspecialchars(http_build_query($qs_one)) ?>" class="simad-btn-act simad-btn-excel" title="Ekspor Excel Projek Ini">
                                                <i class="bi bi-file-earmark-excel"></i>
                                            </a>
                                            <a href="export_projek_pdf.php?<?= htmlspecialchars(http_build_query($qs_one_pdf)) ?>" target="_blank" class="simad-btn-act simad-btn-pdf" title="Cetak / PDF Projek Ini">
                                                <i class="bi bi-file-earmark-pdf"></i>
                                            </a>
                                        </div>
                                        <?php if ($can_crud): ?>
                                        <div class="simad-card-actions">
                                            <button type="button" class="simad-btn-act simad-btn-edit btn-edit-projek" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit Projek">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            <button type="button" class="simad-btn-act simad-btn-del btn-hapus-projek" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_projek'], ENT_QUOTES) ?>" title="Hapus Projek">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-journal-code mb-2" style="font-size: 2.5rem; display: block; color: #cbd5e1;"></i>
                            <div>Belum ada projek / kokurikuler yang terdaftar.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit Projek -->
<div class="modal fade" id="modalProjek" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formProjek">
                <input type="hidden" name="action" id="formProjekAction" value="tambah_projek">
                <input type="hidden" name="id" id="projekId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalProjekTitle">Projek / Kokurikuler</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($master_projek_list)): ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-primary"><i class="bi bi-list-check mr-1"></i>Pilih dari Data Projek</label>
                        <select id="inp_pilih_data_projek" class="form-control">
                            <option value="">-- Pilih Projek dari Data (opsional) --</option>
                            <?php foreach ($master_projek_list as $mp): ?>
                                <option value="<?= (int)$mp['id'] ?>"
                                    data-nama="<?= htmlspecialchars($mp['nama_projek'], ENT_QUOTES) ?>"
                                    data-tema="<?= htmlspecialchars($mp['tema'], ENT_QUOTES) ?>"
                                    data-tujuan="<?= htmlspecialchars((string)$mp['tujuan'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($mp['nama_projek']) ?> &mdash; <?= htmlspecialchars($mp['tema']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Pilih projekt untuk mengisi Nama, Tema, dan Tujuan otomatis. Anda tetap dapat mengubahnya.</small>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-light border mb-3 py-2 px-3" style="border-radius: 10px; font-size: 13px;">
                        <i class="bi bi-info-circle text-primary mr-1"></i>
                        Belum ada <strong>Data Projek</strong>. Tambahkan pada menu <a href="data_projek.php<?= isset($_GET['session_type']) ? '?session_type=' . htmlspecialchars($_GET['session_type']) : '' ?>">Data Projek Kokurikuler</a> agar dapat dipilih di sini.
                    </div>
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label>Nama Projek <span class="text-danger">*</span></label>
                            <input type="text" name="nama_projek" id="inp_nama" class="form-control" required placeholder="Contoh: Pembuatan Pupuk Kompos Organik">
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Tema <span class="text-danger">*</span></label>
                            <input type="text" name="tema" id="inp_tema" class="form-control" required placeholder="Gaya Hidup Berkelanjutan">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Pembimbing / Fasilitator</label>
                            <input type="text" name="pembimbing" id="inp_pembimbing" class="form-control" placeholder="Nama Guru Pembimbing">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal Mulai</label>
                            <input type="date" name="tgl_mulai" id="inp_mulai" class="form-control">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal Selesai</label>
                            <input type="date" name="tgl_selesai" id="inp_selesai" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_projek_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Deskripsi / Tujuan Projek</label>
                            <textarea name="deskripsi" id="inp_deskripsi" class="form-control" rows="6" style="min-height: 140px; resize: vertical;" placeholder="Deskripsi aktivitas atau dimensi profil lulusan yang dikembangkan..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Projek</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Anggota Projek (Fitur 19) -->
<div class="modal fade" id="modalAnggotaProjek" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title"><i class="fas fa-users-cog mr-2"></i>Kelola Anggota & Kelompok Projek</h5>
                    <small class="text-muted"><strong id="mdl_projek_nama"></strong> | Tema: <span id="mdl_projek_tema"></span></small>
                </div>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <!-- Form Tambah Siswa ke Projek -->
                <div class="card card-primary mb-3">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0" id="titleFormAnggota">Tambah / Atur Siswa ke Projek</h6>
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btnBatalEditAnggota" style="border-radius: 8px;">
                            <i class="bi bi-x-circle mr-1"></i> Batal Edit
                        </button>
                    </div>
                    <div class="card-body p-3">
                        <form method="POST" id="formAnggotaProjek">
                            <input type="hidden" name="action" id="formAnggotaAction" value="tambah_anggota">
                            <input type="hidden" name="id_projek" id="mdl_projek_id_anggota">
                            <div class="form-row">
                                <div class="col-md-12 form-group mb-2">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="small font-weight-bold mb-0">Pilih Siswa (Multiselect / Bisa Pilih Banyak) <span class="text-danger">*</span></label>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2" id="btnSelectAllSiswa" style="font-size: 11px; border-radius: 4px 0 0 4px;">
                                                <i class="bi bi-check-all mr-1"></i>Pilih Semua
                                            </button>
                                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2" id="btnUnselectAllSiswa" style="font-size: 11px; border-radius: 0 4px 4px 0;">
                                                <i class="bi bi-x mr-1"></i>Kosongkan
                                            </button>
                                        </div>
                                    </div>
                                    <select name="id_siswa[]" id="mdl_id_siswa" class="form-control select2" multiple="multiple" style="width: 100%;" required>
                                        <?php foreach ($siswa_list as $s): ?>
                                            <option value="<?= (int)$s['id_siswa'] ?>"><?= htmlspecialchars($s['nama_siswa']) ?><?= !empty($s['nisn']) ? ' (' . htmlspecialchars($s['nisn']) . ')' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted"><i class="bi bi-info-circle mr-1"></i>Ketik nama siswa untuk mencari, atau klik "Pilih Semua" untuk memasukkan semua siswa.</small>
                                </div>
                                <div class="col-md-3 form-group mb-2">
                                    <label class="small font-weight-bold">Kelompok</label>
                                    <input type="text" name="kelompok" id="mdl_kelompok" class="form-control form-control-sm" value="Kelompok 1" placeholder="Kelompok 1">
                                </div>
                                <div class="col-md-3 form-group mb-2">
                                    <label class="small font-weight-bold">Peran</label>
                                    <select name="peran" id="mdl_peran" class="form-control form-control-sm">
                                        <?php foreach ($peran_options as $pr): ?>
                                            <option value="<?= $pr ?>"><?= $pr ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group mb-2">
                                    <label class="small font-weight-bold">Status</label>
                                    <select name="status" id="mdl_status_anggota" class="form-control form-control-sm">
                                        <option value="Aktif">Aktif</option>
                                        <option value="Selesai">Selesai</option>
                                        <option value="Keluar">Keluar</option>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group mb-2">
                                    <label class="small font-weight-bold">Catatan Khusus</label>
                                    <input type="text" name="catatan" id="mdl_catatan_anggota" class="form-control form-control-sm" placeholder="Catatan tugas / kontribusi">
                                </div>
                            </div>
                            <div class="text-right mt-1">
                                <button type="submit" id="btnSubmitAnggota" class="btn btn-primary btn-sm px-3" style="border-radius: 8px;">
                                    <i class="bi bi-plus-circle mr-1"></i> Simpan Siswa ke Projek
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabel Anggota Projek -->
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-sm mb-0">
                        <thead>
                            <tr>
                                <th width="4%">No</th>
                                <th>Nama Siswa</th>
                                <th>NIS/NISN</th>
                                <th>Peran</th>
                                <th>Kelompok</th>
                                <th>Catatan</th>
                                <th>Status</th>
                                <th width="10%" class="text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="bodyTabelAnggota">
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus_projek">
    <input type="hidden" name="id" id="formHapusId">
</form>

<form method="POST" id="formHapusAnggota" class="d-none">
    <input type="hidden" name="action" value="hapus_anggota">
    <input type="hidden" name="id_anggota" id="formHapusAnggotaId">
</form>

<?php include '../templates/footer.php'; ?>
