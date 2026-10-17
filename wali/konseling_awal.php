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

$wali_kelas_id = 0;
$wali_kelas_name = '';
$stmtWali = $pdo->prepare("SELECT id_kelas, nama_kelas FROM tb_kelas WHERE wali_kelas = ? OR wali_kelas = (SELECT nama_guru FROM tb_guru WHERE id_guru = ?)");
$stmtWali->execute([$guru_id, $guru_id]);
$wali_class = $stmtWali->fetch(PDO::FETCH_ASSOC);
if ($wali_class) {
    $wali_kelas_id = (int)$wali_class['id_kelas'];
    $wali_kelas_name = $wali_class['nama_kelas'];
}

$all_classes = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_kelas_id = $wali_kelas_id;
if ($is_admin_or_kepala && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Pengguna hanya memiliki akses lihat (monitoring).'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $topik = trim((string)($_POST['topik'] ?? ''));
        $ringkasan_masalah = trim((string)($_POST['ringkasan_masalah'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Pembinaan', 'Selesai'], true) ? $_POST['status'] : 'Selesai';
        $follow_up = trim((string)($_POST['follow_up'] ?? ''));

        if ($id_siswa <= 0 || $topik === '' || $ringkasan_masalah === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Topik, dan Ringkasan Masalah.'];
        } else {
            try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_konseling_awal (
                            id_wali, id_siswa, id_kelas, tanggal, topik,
                            ringkasan_masalah, tindak_lanjut, status, follow_up
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $topik,
                        $ringkasan_masalah, $tindak_lanjut, $status, $follow_up
                    ]);
                    if (function_exists('sync_konseling_statuses')) { sync_konseling_statuses($pdo); }
                    $message = ['type' => 'success', 'text' => $status === 'Pembinaan' ? 'Konseling status Pembinaan tersimpan. Silakan catat pembinaan di menu Pembinaan.' : 'Data konseling awal berhasil disimpan.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_konseling_awal SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, topik = ?,
                            ringkasan_masalah = ?, tindak_lanjut = ?, status = ?, follow_up = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $topik,
                        $ringkasan_masalah, $tindak_lanjut, $status, $follow_up, $id
                    ]);
                    if (function_exists('sync_konseling_statuses')) { sync_konseling_statuses($pdo); }
                    $message = ['type' => 'success', 'text' => 'Data konseling berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_konseling_awal WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data konseling berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}
}

$siswa_list = [];
if ($selected_kelas_id > 0) {
    $stS = $pdo->prepare("SELECT id_siswa, nama_siswa, nisn FROM tb_siswa WHERE id_kelas = ? ORDER BY nama_siswa ASC");
    $stS->execute([$selected_kelas_id]);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
}

$f_topik = trim((string)($_GET['f_topik'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "k.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if (!$is_admin_or_kepala) {
    $where[] = "k.id_wali = ?";
    $params[] = $guru_id;
}
if ($f_topik !== '') {
    $where[] = "k.topik = ?";
    $params[] = $f_topik;
}
if ($f_status !== '') {
    $where[] = "k.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT k.*, s.nama_siswa, s.nisn, c.nama_kelas
    FROM tb_konseling_awal k
    JOIN tb_siswa s ON s.id_siswa = k.id_siswa
    LEFT JOIN tb_kelas c ON c.id_kelas = k.id_kelas
    WHERE $where_sql
    ORDER BY k.tanggal DESC, k.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (function_exists('sync_konseling_statuses')) { sync_konseling_statuses($pdo); }

$status_options = ['Pembinaan', 'Selesai'];
$topik_options = ['Motivasi Belajar', 'Penyesuaian Sosial', 'Keluarga', 'Kedisiplinan', 'Kecemasan / Emosi', 'Minat & Bakat', 'Ibadah & Spiritual', 'Lainnya'];

// Master template konseling (sinkron dengan menu Data Konseling)
$stMasterKons = $pdo->prepare("
    SELECT id, topik, ringkasan, tindak_lanjut, follow_up
    FROM tb_master_konseling
    WHERE id_guru = ? OR id_guru IS NULL OR id_guru = 0
    ORDER BY topik ASC, id ASC
");
$stMasterKons->execute([$guru_id]);
$master_konseling = $stMasterKons->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Konseling Siswa';

if (!function_exists('getKonselingTopikStyle')) {
    function getKonselingTopikStyle(string $topik): array {
        $t = strtolower(trim($topik));
        if (strpos($t, 'belajar') !== false || strpos($t, 'akademik') !== false || strpos($t, 'bakat') !== false) {
            return ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'bi bi-mortarboard-fill'];
        } elseif (strpos($t, 'emosi') !== false || strpos($t, 'cemas') !== false || strpos($t, 'keluarga') !== false) {
            return ['bg' => '#ffe4e6', 'color' => '#e11d48', 'icon' => 'bi bi-heart-pulse-fill'];
        } elseif (strpos($t, 'disiplin') !== false || strpos($t, 'tertib') !== false) {
            return ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'bi bi-shield-check'];
        } elseif (strpos($t, 'sosial') !== false || strpos($t, 'teman') !== false) {
            return ['bg' => '#ccfbf1', 'color' => '#0f766e', 'icon' => 'bi bi-people-fill'];
        } elseif (strpos($t, 'ibadah') !== false || strpos($t, 'spiritual') !== false || strpos($t, 'agama') !== false) {
            return ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi bi-book-half'];
        }
        return ['bg' => '#f3e8ff', 'color' => '#7e22ce', 'icon' => 'bi bi-chat-heart-fill'];
    }
}

if (!function_exists('getKonselingStatusStyle')) {
    function getKonselingStatusStyle(string $status): array {
        if ($status === 'Selesai') {
            return ['bg' => '#dcfce7', 'color' => '#15803d', 'icon' => 'bi bi-check-circle-fill'];
        } elseif ($status === 'Pembinaan') {
            return ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'bi bi-exclamation-circle-fill'];
        }
        return ['bg' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'bi bi-clock-history'];
    }
}

$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
var masterKonseling =
JS
. json_encode($master_konseling) . ";\n" . <<<'JS'
$(document).ready(function() {
    if ($('#table-konseling').length) {
        $('#table-konseling').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data konseling',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function shortKons(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 100;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function autogrowKons($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalKonseling textarea', function() { autogrowKons($(this)); });
    $('#modalKonseling').on('shown.bs.modal', function() {
        $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
    });

    function populateKons(topik) {
        $('#sel_kons_masalah').empty().append('<option value="">-- Pilih template ringkasan --</option>');
        $('#sel_kons_tl').empty().append('<option value="">-- Pilih template tindak lanjut --</option>');
        $('#sel_kons_fu').empty().append('<option value="">-- Pilih template rencana follow up --</option>');
        if (!topik) return;
        masterKonseling.filter(function(m) {
            return (m.topik || '').toLowerCase() === topik.toLowerCase();
        }).forEach(function(m) {
            var o1 = $('<option>').val(m.ringkasan).text(shortKons(m.ringkasan, 110));
            o1.data('item', m);
            $('#sel_kons_masalah').append(o1);
            if (m.tindak_lanjut) {
                var o2 = $('<option>').val(m.tindak_lanjut).text(shortKons(m.tindak_lanjut, 110));
                o2.data('item', m);
                $('#sel_kons_tl').append(o2);
            }
            if (m.follow_up) {
                var o3 = $('<option>').val(m.follow_up).text(shortKons(m.follow_up, 110));
                o3.data('item', m);
                $('#sel_kons_fu').append(o3);
            }
        });
    }

    $('#inp_topik').on('change', function() {
        populateKons($(this).val());
    });

    $('#sel_kons_masalah').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_masalah').val(v); autogrowKons($('#inp_masalah')); }
    });
    $('#sel_kons_tl').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_tindak_lanjut').val(v); autogrowKons($('#inp_tindak_lanjut')); }
    });
    $('#sel_kons_fu').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_follow_up').val(v); autogrowKons($('#inp_follow_up')); }
    });

    $('#btnTambahKonseling').on('click', function() {
        $('#formKonselingAction').val('tambah');
        $('#konselingId').val('');
        $('#modalKonselingTitle').text('Catat Sesi Konseling Siswa');
        $('#formKonseling')[0].reset();
        var curTopik = $('#inp_topik').val() || '';
        populateKons(curTopik);
        $('#modalKonseling').modal('show');
        setTimeout(function() {
            $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-konseling', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        $('#formKonselingAction').val('edit');
        $('#konselingId').val(data.id);
        $('#modalKonselingTitle').text('Edit Konseling Siswa');
        $('#inp_siswa').val(data.id_siswa ? String(data.id_siswa) : '');
        $('#inp_tanggal').val(data.tanggal || '');
        $('#inp_topik').val(data.topik || '');
        populateKons(data.topik || '');
        $('#sel_kons_masalah option').each(function() {
            if ($(this).val() === (data.ringkasan_masalah || '')) $(this).prop('selected', true);
        });
        $('#sel_kons_tl option').each(function() {
            if ($(this).val() === (data.tindak_lanjut || '')) $(this).prop('selected', true);
        });
        $('#sel_kons_fu option').each(function() {
            if ($(this).val() === (data.follow_up || '')) $(this).prop('selected', true);
        });
        $('#inp_masalah').val(data.ringkasan_masalah || '');
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');
        $('#inp_follow_up').val(data.follow_up || '');
        $('#inp_status').val(data.status === 'Pembinaan' ? 'Pembinaan' : 'Selesai');
        $('#modalKonseling').modal('show');
        $('#modalKonseling').one('shown.bs.modal', function() {
            $('#inp_siswa').trigger('change');
            $('#inp_topik').trigger('change');
            $('#inp_status').trigger('change');
            if (window.GDSRefresh) { try { window.GDSRefresh($('#modalKonseling')[0]); } catch(e2) {} }
        });
        setTimeout(function() {
            $('#modalKonseling textarea').each(function() { autogrowKons($(this)); });
        }, 120);
    });

    function escKs(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    function fmtTglKs(v) {
        v = (v || '').toString().substring(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return v || '-';
        var p = v.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    $(document).on('click', '.btn-detail-konseling', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        $('#det_tanggal').text(fmtTglKs(data.tanggal));
        $('#det_nama').text(data.nama_siswa || '-');
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_topik').html('<span class="badge badge-info" style="font-size:13px;"><i class="fas fa-comments mr-1"></i>' + escKs(data.topik || '-') + '</span>');
        var stCls = (data.status === 'Selesai') ? 'success' : 'warning';
        $('#det_status').html('<span class="badge badge-' + stCls + '">' + escKs(data.status || '-') + '</span>');
        $('#det_masalah').html(data.ringkasan_masalah ? escKs(data.ringkasan_masalah) : '<span class="text-muted">-</span>');
        $('#det_tindak_lanjut').html(data.tindak_lanjut ? escKs(data.tindak_lanjut) : '<span class="text-muted">-</span>');
        $('#det_follow_up').html(data.follow_up ? escKs(data.follow_up) : '<span class="text-muted">-</span>');
        $('#modalDetailKonseling').modal('show');
    });

    $(document).on('click', '.btn-hapus-konseling', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Sesi Konseling?',
            text: 'Catatan konseling untuk ' + nama + ' akan dihapus.',
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

    $('#quick-search-konseling').on('input', function() {
        var val = $(this).val().toLowerCase().trim();
        var count = 0;
        $('.konseling-card-item').each(function() {
            var text = $(this).text().toLowerCase();
            var show = text.indexOf(val) > -1;
            $(this).toggle(show);
            if (show) count++;
        });
        $('#count-konseling').text(count);
    });
});
JS
];

if (!empty($message)) {
    $js_page[] = "Swal.fire({ icon: '" . ($message['type'] === 'danger' ? 'error' : $message['type']) . "', title: '" . ($message['type'] === 'success' ? 'Berhasil' : 'Perhatian') . "', text: " . json_encode($message['text']) . ", timer: 2200, showConfirmButton: false });";
}

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Styling Kartu Modern Konseling Siswa */
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
.simad-meta-date {
    font-size: 11.5px;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    gap: 4px;
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
.simad-btn-detail { background: #e0f2fe; color: #0284c7; }
.simad-btn-detail:hover { background: #bae6fd; color: #0369a1; }
.simad-btn-edit { background: #fef3c7; color: #b45309; }
.simad-btn-edit:hover { background: #fde68a; color: #92400e; }
.simad-btn-del { background: #fee2e2; color: #b91c1c; }
.simad-btn-del:hover { background: #fecaca; color: #991b1b; }
.simad-btn-pdf { background: #fee2e2; color: #dc2626; }
.simad-btn-pdf:hover { background: #fecaca; color: #b91c1c; }
.simad-btn-excel { background: #dcfce7; color: #15803d; }
.simad-btn-excel:hover { background: #bbf7d0; color: #166534; }
.simad-btn-proc { background: #f3e8ff; color: #7e22ce; padding: 0 10px; width: auto; font-size: 11.5px; font-weight: 700; gap: 4px; }
.simad-btn-proc:hover { background: #e9d5ff; color: #6b21a8; }

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
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Konseling Siswa <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row align-items-end">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <?php if ($is_admin_or_kepala): ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Kelas</label>
                            <select name="kelas" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($all_classes as $c): ?>
                                    <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Topik Konseling</label>
                            <select name="f_topik" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Topik --</option>
                                <?php foreach ($topik_options as $t): ?>
                                    <option value="<?= $t ?>" <?= $f_topik === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Status --</option>
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Catatan Konseling Pribadi & Wawancara Awal</h4>
                    <div>
                        <a href="data_konseling.php" class="btn btn-info btn-sm mr-1">
                            <i class="fas fa-database mr-1"></i> Data Konseling
                        </a>
                        <?php
                        $qs_kons = [];
                        if ($selected_kelas_id > 0) { $qs_kons['kelas'] = $selected_kelas_id; }
                        $url_kons_cetak = 'export_konseling_pdf.php?' . http_build_query(array_merge($qs_kons, ['mode' => 'print']));
                        $url_kons_xls = 'export_konseling_excel.php?' . http_build_query($qs_kons);
                        ?>
                        <a href="<?= htmlspecialchars($url_kons_cetak) ?>" target="_blank" class="btn btn-danger btn-sm mr-1" title="Cetak / Simpan PDF">
                            <i class="fas fa-print mr-1"></i> Cetak / PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_kons_xls) ?>" class="btn btn-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahKonseling" <?= empty($siswa_list) && !$is_admin_or_kepala ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Sesi Konseling Baru
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
                                <input type="text" id="quick-search-konseling" class="form-control border-left-0" style="border-radius: 0 14px 14px 0; border-color: #e2e8f0; box-shadow: none;" placeholder="Cari nama siswa, topik, atau kata kunci...">
                            </div>
                        </div>
                        <div class="col-md-6 col-12 text-md-right text-muted small">
                            Menampilkan <span id="count-konseling" class="font-weight-bold text-dark"><?= count($rows) ?></span> sesi konseling
                        </div>
                    </div>

                    <?php if (!empty($rows)): ?>
                        <div class="simad-card-grid">
                            <?php foreach ($rows as $i => $r): ?>
                                <?php
                                $topikStyle = getKonselingTopikStyle($r['topik'] ?? '');
                                $statusStyle = getKonselingStatusStyle($r['status'] ?? '');
                                $raw_m = strip_tags($r['ringkasan_masalah'] ?? '');
                                $raw_tl = strip_tags($r['tindak_lanjut'] ?? '');
                                $raw_fu = strip_tags($r['follow_up'] ?? '');
                                ?>
                                <div class="simad-item-card konseling-card-item">
                                    <div class="simad-card-header">
                                        <div class="simad-card-icon" style="background: <?= $topikStyle['bg'] ?>; color: <?= $topikStyle['color'] ?>;">
                                            <i class="<?= $topikStyle['icon'] ?>"></i>
                                        </div>
                                        <div class="simad-card-main">
                                            <div class="simad-card-meta">
                                                <span class="simad-meta-date">
                                                    <i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($r['tanggal'])) ?>
                                                </span>
                                                <span class="simad-badge-pill" style="background: <?= $statusStyle['bg'] ?>; color: <?= $statusStyle['color'] ?>;">
                                                    <i class="<?= $statusStyle['icon'] ?>"></i> <?= htmlspecialchars($r['status']) ?>
                                                </span>
                                            </div>
                                            <div class="simad-card-name" title="<?= htmlspecialchars($r['nama_siswa']) ?>">
                                                <?= htmlspecialchars($r['nama_siswa']) ?>
                                            </div>
                                            <div class="simad-card-sub">
                                                <span>NISN: <?= !empty($r['nisn']) ? htmlspecialchars($r['nisn']) : '-' ?></span>
                                                <span>&bull;</span>
                                                <span>Kelas: <?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="simad-card-body">
                                        <div class="mb-2">
                                            <span class="simad-badge-pill" style="background: <?= $topikStyle['bg'] ?>; color: <?= $topikStyle['color'] ?>;">
                                                <i class="bi bi-tag-fill"></i> <?= htmlspecialchars($r['topik']) ?>
                                            </span>
                                        </div>
                                        <div class="simad-info-box">
                                            <div class="simad-info-row">
                                                <span class="simad-info-label">Ringkasan Masalah</span>
                                                <div class="simad-info-val text-dark font-weight-500">
                                                    <?= htmlspecialchars(mb_strimwidth($raw_m, 0, 95, '...')) ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($raw_tl)): ?>
                                            <div class="simad-info-row">
                                                <span class="simad-info-label">Tindak Lanjut</span>
                                                <div class="simad-info-val text-success">
                                                    <?= htmlspecialchars(mb_strimwidth($raw_tl, 0, 85, '...')) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($raw_fu)): ?>
                                            <div class="simad-info-row">
                                                <span class="simad-info-label">Follow Up</span>
                                                <div class="simad-info-val text-muted">
                                                    <?= htmlspecialchars(mb_strimwidth($raw_fu, 0, 85, '...')) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="simad-card-footer">
                                        <div class="simad-card-actions">
                                            <button type="button" class="simad-btn-act simad-btn-detail btn-detail-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail Rahasia">
                                                <i class="bi bi-eye-fill"></i>
                                            </button>
                                            <?php if ($can_crud && $r['status'] === 'Pembinaan'): ?>
                                            <a href="pembinaan_siswa.php?konseling_id=<?= (int)$r['id'] ?>" class="simad-btn-act simad-btn-proc" title="Proses ke Pembinaan Siswa">
                                                <i class="bi bi-person-gear"></i> Bina
                                            </a>
                                            <?php endif; ?>
                                            <?php if ($can_crud): ?>
                                            <button type="button" class="simad-btn-act simad-btn-edit btn-edit-konseling" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                <i class="bi bi-pencil-fill"></i>
                                            </button>
                                            <button type="button" class="simad-btn-act simad-btn-del btn-hapus-konseling" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="simad-card-actions">
                                            <a href="export_konseling_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="simad-btn-act simad-btn-pdf" title="Cetak / PDF">
                                                <i class="bi bi-printer-fill"></i>
                                            </a>
                                            <a href="export_konseling_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="simad-btn-act simad-btn-excel" title="Ekspor Excel">
                                                <i class="bi bi-file-earmark-excel-fill"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-chat-heart mb-2" style="font-size: 2.5rem; display: block; color: #cbd5e1;"></i>
                            <div>Belum ada data konseling yang sesuai kriteria.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formKonseling">
                <input type="hidden" name="action" id="formKonselingAction" value="tambah">
                <input type="hidden" name="id" id="konselingId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalKonselingTitle">Sesi Konseling Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Siswa <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control" required>
                                <option value="">-- Pilih Siswa --</option>
                                <?php foreach ($siswa_list as $s): ?>
                                    <option value="<?= (int)$s['id_siswa'] ?>">
                                        <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Status <span class="text-danger">*</span></label>
                            <select name="status" id="inp_status" class="form-control" required>
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st === 'Pembinaan' ? 'Pembinaan (kasus berat/berulang)' : 'Selesai (kasus ringan/sekali)' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Berat/berulang &rarr; Pembinaan. Ringan/sekali &rarr; Selesai.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Topik / Fokus Konseling <span class="text-danger">*</span></label>
                            <select name="topik" id="inp_topik" class="form-control" required>
                                <option value="">-- Pilih Topik --</option>
                                <?php foreach ($topik_options as $t): ?>
                                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 form-group">
                            <label>Ringkasan Masalah / Hasil Konseling <span class="text-danger">*</span></label>
                            <select id="sel_kons_masalah" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template ringkasan sesuai kondisi siswa --</option>
                            </select>
                            <textarea name="ringkasan_masalah" id="inp_masalah" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Tindak Lanjut / Solusi yang Disepakati</label>
                            <select id="sel_kons_tl" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template tindak lanjut --</option>
                            </select>
                            <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="5" style="min-height:120px;" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Rencana Follow Up</label>
                            <select id="sel_kons_fu" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template rencana follow up --</option>
                            </select>
                            <textarea name="follow_up" id="inp_follow_up" class="form-control" rows="5" style="min-height:120px;" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Konseling</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Rahasia -->
<div class="modal fade" id="modalDetailKonseling" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-shield mr-2"></i>Rincian Konseling Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center mb-3 p-2 rounded" style="background:#f0f7ff; border:1px solid #cfe3ff;">
                    <div class="mr-3 text-center" style="min-width:64px;">
                        <div class="font-weight-bold" id="det_tanggal" style="font-size:14px;"></div>
                        <small class="text-muted"><i class="fas fa-calendar-alt mr-1"></i>Sesi</small>
                    </div>
                    <div>
                        <div class="font-weight-bold" id="det_nama" style="font-size:15px;"></div>
                        <div><small class="text-muted">NISN: <span id="det_nisn"></span> &bull; Kelas: <span id="det_kelas"></span></small></div>
                        <div class="mt-1"><span id="det_topik"></span> <span id="det_status" class="ml-1"></span></div>
                    </div>
                </div>
                <div class="card mb-2 border-danger">
                    <div class="card-header py-1 px-2 bg-danger text-white" style="font-size:12px;"><i class="fas fa-exclamation-circle mr-1"></i>Ringkasan Masalah / Hasil Diskusi</div>
                    <div class="card-body p-2" id="det_masalah" style="white-space: pre-wrap; font-size:13.5px;"></div>
                </div>
                <div class="card mb-2 border-success">
                    <div class="card-header py-1 px-2 bg-success text-white" style="font-size:12px;"><i class="fas fa-check-circle mr-1"></i>Tindak Lanjut yang Disepakati</div>
                    <div class="card-body p-2" id="det_tindak_lanjut" style="white-space: pre-wrap; font-size:13.5px;"></div>
                </div>
                <div class="card mb-0 border-info">
                    <div class="card-header py-1 px-2 bg-info text-white" style="font-size:12px;"><i class="fas fa-forward mr-1"></i>Rencana Follow Up</div>
                    <div class="card-body p-2" id="det_follow_up" style="white-space: pre-wrap; font-size:13.5px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="formHapus" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusId">
</form>

<?php include '../templates/footer.php'; ?>


