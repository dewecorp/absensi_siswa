<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_crud = !in_array($user_level, ['admin', 'kepala_madrasah'], true);
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
if ($user_level === 'admin' && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Admin hanya mode lihat.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $sumber = in_array($_POST['sumber'] ?? '', ['Pembinaan', 'Konseling'], true) ? $_POST['sumber'] : 'Pembinaan';
        $id_pembinaan = ($sumber === 'Pembinaan') ? (int)($_POST['id_pembinaan'] ?? 0) : null;
        $id_konseling = ($sumber === 'Konseling') ? (int)($_POST['id_konseling'] ?? 0) : null;
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $penanggung_jawab = trim((string)($_POST['penanggung_jawab'] ?? ''));
        $target_selesai = !empty($_POST['target_selesai']) ? date('Y-m-d', strtotime($_POST['target_selesai'])) : null;
        $tanggal_selesai = !empty($_POST['tanggal_selesai']) ? date('Y-m-d', strtotime($_POST['tanggal_selesai'])) : null;
        $status = in_array($_POST['status'] ?? '', ['Rencana', 'Proses', 'Selesai', 'Dibatalkan'], true) ? $_POST['status'] : 'Rencana';

        $sumber_id = ($sumber === 'Pembinaan') ? $id_pembinaan : $id_konseling;
        if ($id_siswa <= 0 || ($sumber_id ?? 0) <= 0 || $tindakan === '' || $penanggung_jawab === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, pilih sumber ' . $sumber . ', isi Tindakan, dan Penanggung Jawab.'];
        } else {
            // Validasi kepemilikan siswa
            $boleh = true;
            try {
                if ($sumber === 'Pembinaan') {
                    $stCek = $pdo->prepare("SELECT id_siswa FROM tb_pembinaan_siswa WHERE id = ?" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : ""));
                    $stCek->execute([$id_pembinaan]);
                    $idOwner = (int)$stCek->fetchColumn();
                    if ($idOwner <= 0) {
                        $message = ['type' => 'warning', 'text' => 'Data pembinaan sumber tidak ditemukan.'];
                        $boleh = false;
                    } elseif ($idOwner !== $id_siswa) {
                        $message = ['type' => 'warning', 'text' => 'Pembinaan sumber milik siswa lain. Pilih ulang pembinaan.'];
                        $boleh = false;
                    }
                } else {
                    $stCek = $pdo->prepare("SELECT id_siswa FROM tb_konseling_awal WHERE id = ?" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : ""));
                    $stCek->execute([$id_konseling]);
                    $idOwner = (int)$stCek->fetchColumn();
                    if ($idOwner <= 0) {
                        $message = ['type' => 'warning', 'text' => 'Data konseling sumber tidak ditemukan.'];
                        $boleh = false;
                    } elseif ($idOwner !== $id_siswa) {
                        $message = ['type' => 'warning', 'text' => 'Konseling sumber milik siswa lain. Pilih ulang konseling.'];
                        $boleh = false;
                    }
                }
            } catch (Throwable $e) {}

            // Anti-ganda: 1 pembinaan / 1 konseling hanya boleh di-TL 1x
            if ($boleh) {
                try {
                    if ($sumber === 'Pembinaan') {
                        $sqlDup = "SELECT id FROM tb_tindak_lanjut_wali WHERE id_pembinaan = ?" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : "");
                    } else {
                        $sqlDup = "SELECT id FROM tb_tindak_lanjut_wali WHERE id_konseling = ?" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : "");
                    }
                    if ($action === 'edit' && $id > 0) {
                        $sqlDup .= " AND id <> " . (int)$id;
                    }
                    $stDup = $pdo->prepare($sqlDup);
                    $stDup->execute([$sumber === 'Pembinaan' ? $id_pembinaan : $id_konseling]);
                    if ($stDup->fetchColumn()) {
                        $message = ['type' => 'warning', 'text' => 'Sumber ' . $sumber . ' ini SUDAH ditindaklanjuti. Pilih yang belum di-TL agar tidak ganda.'];
                        $boleh = false;
                    }
                } catch (Throwable $e) {}
            }

            if ($boleh) try {
                if ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_tindak_lanjut_wali (
                            id_wali, id_siswa, id_kelas, tanggal, sumber,
                            tindakan, penanggung_jawab, target_selesai, tanggal_selesai, status, id_pembinaan, id_konseling
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $sumber,
                        $tindakan, $penanggung_jawab, $target_selesai, $tanggal_selesai, $status, $id_pembinaan, $id_konseling
                    ]);
                    $message = ['type' => 'success', 'text' => 'Program tindak lanjut berhasil dicatat.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_tindak_lanjut_wali SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, sumber = ?,
                            tindakan = ?, penanggung_jawab = ?, target_selesai = ?,
                            tanggal_selesai = ?, status = ?, id_pembinaan = ?, id_konseling = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $sumber,
                        $tindakan, $penanggung_jawab, $target_selesai,
                        $tanggal_selesai, $status, $id_pembinaan, $id_konseling, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Tindak lanjut berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_tindak_lanjut_wali WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data tindak lanjut berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
    }
}

// Alur 3: dropdown HIBRID (bisa dari Pembinaan ATAU Konseling).
// Peta item yang SUDAH di-TL (anti-ganda)
$tl_used_bina = [];
$tl_used_kons = [];
try {
    $stU = $pdo->prepare("SELECT id_pembinaan, id_konseling FROM tb_tindak_lanjut_wali WHERE 1=1" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : ""));
    $stU->execute();
    foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $ur) {
        if (!empty($ur['id_pembinaan'])) $tl_used_bina[(int)$ur['id_pembinaan']] = true;
        if (!empty($ur['id_konseling'])) $tl_used_kons[(int)$ur['id_konseling']] = true;
    }
} catch (Throwable $e) {}

// Dropdown siswa: hanya yang punya Pembinaan BELUM di-TL ATAU Konseling BELUM di-TL
$siswa_list = [];
try {
    $sqlS = "
        SELECT s.id_siswa, s.nama_siswa, s.nisn,
               COALESCE(bn.n_bina_belum, 0) AS n_bina_belum,
               COALESCE(ks.n_kons_belum, 0) AS n_kons_belum
        FROM tb_siswa s
        LEFT JOIN (
            SELECT b.id_siswa, COUNT(b.id) AS n_bina_belum
            FROM tb_pembinaan_siswa b
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_pembinaan = b.id" . ($user_level !== 'admin' ? " AND t.id_wali = " . (int)$guru_id : "") . "
            WHERE t.id IS NULL" . ($user_level !== 'admin' ? " AND b.id_wali = " . (int)$guru_id : "") . "
            GROUP BY b.id_siswa
        ) bn ON bn.id_siswa = s.id_siswa
        LEFT JOIN (
            SELECT c.id_siswa, COUNT(c.id) AS n_kons_belum
            FROM tb_konseling_awal c
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_konseling = c.id" . ($user_level !== 'admin' ? " AND t.id_wali = " . (int)$guru_id : "") . "
            WHERE t.id IS NULL" . ($user_level !== 'admin' ? " AND c.id_wali = " . (int)$guru_id : "") . "
            GROUP BY c.id_siswa
        ) ks ON ks.id_siswa = s.id_siswa
        WHERE 1=1
    ";
    $parS = [];
    if ($selected_kelas_id > 0) {
        $sqlS .= " AND s.id_kelas = ?";
        $parS[] = $selected_kelas_id;
    }
    $sqlS .= " HAVING (n_bina_belum > 0 OR n_kons_belum > 0) ORDER BY s.nama_siswa ASC";
    $stS = $pdo->prepare($sqlS);
    $stS->execute($parS);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $siswa_list = [];
}

// Daftar pembinaan BELUM di-TL untuk dropdown sumber Pembinaan
$pembinaan_list = [];
try {
    $sqlB = "SELECT b.id, b.id_siswa, b.tanggal, b.jenis_pembinaan, b.permasalahan, b.status FROM tb_pembinaan_siswa b WHERE 1=1";
    $parB = [];
    if ($selected_kelas_id > 0) {
        $sqlB .= " AND b.id_kelas = ?";
        $parB[] = $selected_kelas_id;
    }
    if ($user_level !== 'admin') {
        $sqlB .= " AND b.id_wali = ?";
        $parB[] = $guru_id;
    }
    if (!empty($tl_used_bina)) {
        $sqlB .= " AND b.id NOT IN (" . implode(',', array_map('intval', array_keys($tl_used_bina))) . ")";
    }
    $sqlB .= " ORDER BY b.tanggal DESC, b.id DESC";
    $stB = $pdo->prepare($sqlB);
    $stB->execute($parB);
    $pembinaan_list = $stB->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $pembinaan_list = []; }

// Daftar konseling BELUM di-TL untuk dropdown sumber Konseling
$konseling_list = [];
try {
    $sqlK = "SELECT c.id, c.id_siswa, c.tanggal, c.topik, c.ringkasan_masalah, c.status FROM tb_konseling_awal c WHERE 1=1";
    $parK = [];
    if ($selected_kelas_id > 0) {
        $sqlK .= " AND c.id_kelas = ?";
        $parK[] = $selected_kelas_id;
    }
    if ($user_level !== 'admin') {
        $sqlK .= " AND c.id_wali = ?";
        $parK[] = $guru_id;
    }
    if (!empty($tl_used_kons)) {
        $sqlK .= " AND c.id NOT IN (" . implode(',', array_map('intval', array_keys($tl_used_kons))) . ")";
    }
    $sqlK .= " ORDER BY c.tanggal DESC, c.id DESC";
    $stK = $pdo->prepare($sqlK);
    $stK->execute($parK);
    $konseling_list = $stK->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $konseling_list = []; }

$f_sumber = trim((string)($_GET['f_sumber'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "t.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "t.id_wali = ?";
    $params[] = $guru_id;
}
if ($f_sumber !== '') {
    $where[] = "t.sumber = ?";
    $params[] = $f_sumber;
}
if ($f_status !== '') {
    $where[] = "t.status = ?";
    $params[] = $f_status;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT t.*, s.nama_siswa, s.nisn, k.nama_kelas,
           b.tanggal AS b_tanggal, b.jenis_pembinaan AS b_jenis, b.permasalahan AS b_masalah, b.status AS b_status,
           c.tanggal AS c_tanggal, c.topik AS c_topik, c.ringkasan_masalah AS c_masalah, c.status AS c_status
    FROM tb_tindak_lanjut_wali t
    JOIN tb_siswa s ON s.id_siswa = t.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = t.id_kelas
    LEFT JOIN tb_pembinaan_siswa b ON b.id = t.id_pembinaan
    LEFT JOIN tb_konseling_awal c ON c.id = t.id_konseling
    WHERE $where_sql
    ORDER BY t.tanggal DESC, t.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sumber_options = ['Pembinaan', 'Konseling'];
$status_options = ['Rencana', 'Proses', 'Selesai', 'Dibatalkan'];

// Master template tindak lanjut (sinkron dengan menu Data Tindak Lanjut)
$stMasterTL = $pdo->prepare("
    SELECT id, sumber, tindakan, penanggung_jawab
    FROM tb_master_tindak_lanjut
    WHERE id_guru = ? OR id_guru IS NULL OR id_guru = 0
    ORDER BY sumber ASC, id ASC
");
$stMasterTL->execute([$guru_id]);
$master_tl = $stMasterTL->fetchAll(PDO::FETCH_ASSOC);

// Info pelanggaran terakhir per siswa untuk konteks modal (jenis + total poin)
$pelanggar_info = [];
try {
    $sqlPI = "SELECT id_siswa, jenis_pelanggaran, kategori, poin, tanggal FROM tb_pelanggaran_siswa WHERE 1=1";
    $parPI = [];
    if ($user_level !== 'admin') {
        $sqlPI .= " AND id_wali = ?";
        $parPI[] = $guru_id;
    }
    $sqlPI .= " ORDER BY tanggal DESC, id DESC";
    $stPI = $pdo->prepare($sqlPI);
    $stPI->execute($parPI);
    foreach ($stPI->fetchAll(PDO::FETCH_ASSOC) as $pi) {
        $sid = (int)$pi['id_siswa'];
        if (!isset($pelanggar_info[$sid])) {
            $pelanggar_info[$sid] = $pi + ['total_poin' => 0, 'jml' => 0];
        }
        $pelanggar_info[$sid]['total_poin'] += (int)$pi['poin'];
        $pelanggar_info[$sid]['jml'] += 1;
    }
} catch (Throwable $e) { $pelanggar_info = []; }

$page_title = 'Daftar Tindak Lanjut';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
var masterTL =
JS
. json_encode($master_tl) . ";\n" . <<<'JS'
var pelanggarInfo =
JS
. json_encode($pelanggar_info) . ";\n" . <<<'JS'
var pembinaanList =
JS
. json_encode($pembinaan_list) . ";\n" . <<<'JS'
var konselingList =
JS
. json_encode($konseling_list) . ";\n" . <<<'JS'
$(document).ready(function() {
    if ($('#table-tindak-lanjut').length) {
        $('#table-tindak-lanjut').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [9] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data tindak lanjut',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function shortTL(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 110;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function autogrowTL($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalTL textarea', function() { autogrowTL($(this)); });
    $('#modalTL').on('shown.bs.modal', function() {
        $('#modalTL textarea').each(function() { autogrowTL($(this)); });
    });

    // Isi dropdown template tindakan sesuai sumber terpilih
    function populateTL(sumber) {
        $('#sel_tl_tindakan').empty().append('<option value="">-- Pilih template tindakan / langkah perbaikan --</option>');
        if (!sumber) return;
        masterTL.filter(function(m) {
            return (m.sumber || '').toLowerCase() === sumber.toLowerCase();
        }).forEach(function(m) {
            var o1 = $('<option>').val(m.tindakan).text(shortTL(m.tindakan, 110));
            o1.data('item', m);
            $('#sel_tl_tindakan').append(o1);
        });
    }

    // Filter pembinaan sumber per siswa
    function pembinaanBySiswa(idSiswa) {
        return pembinaanList.filter(function(b) {
            return String(b.id_siswa) === String(idSiswa);
        });
    }
    function fillBinaDropdown(idSiswa, selectedId) {
        $('#inp_pembinaan').empty().append('<option value="">-- Pilih pembinaan sumber --</option>');
        pembinaanBySiswa(idSiswa).forEach(function(b) {
            var lbl = b.tanggal + ' - ' + b.jenis_pembinaan + ' - ' + (b.permasalahan || '').substr(0, 60) + ' (' + (b.status || '-') + ')';
            var o = $('<option>').val(b.id).text(lbl.length > 110 ? lbl.substr(0, 110) + '...' : lbl);
            o.data('item', b);
            if (selectedId && String(selectedId) === String(b.id)) o.prop('selected', true);
            $('#inp_pembinaan').append(o);
        });
    }

    // Filter konseling sumber per siswa
    function konselingBySiswa(idSiswa) {
        return konselingList.filter(function(k) {
            return String(k.id_siswa) === String(idSiswa);
        });
    }
    function fillKonsDropdown(idSiswa, selectedId) {
        $('#inp_konseling').empty().append('<option value="">-- Pilih konseling sumber --</option>');
        konselingBySiswa(idSiswa).forEach(function(k) {
            var lbl = k.tanggal + ' - ' + k.topik + ' - ' + (k.ringkasan_masalah || '').substr(0, 60) + ' (' + (k.status || '-') + ')';
            var o = $('<option>').val(k.id).text(lbl.length > 110 ? lbl.substr(0, 110) + '...' : lbl);
            o.data('item', k);
            if (selectedId && String(selectedId) === String(k.id)) o.prop('selected', true);
            $('#inp_konseling').append(o);
        });
    }

    function switchSumberWrap(sumber) {
        if (sumber === 'Konseling') {
            $('#wrap_pembinaan').addClass('d-none');
            $('#inp_pembinaan').prop('required', false);
            $('#wrap_konseling').removeClass('d-none');
            $('#inp_konseling').prop('required', true);
            $('#pj_default_hint').text('Penanggung Jawab biasanya: Guru BK / Wali Kelas');
        } else {
            $('#wrap_konseling').addClass('d-none');
            $('#inp_konseling').prop('required', false);
            $('#wrap_pembinaan').removeClass('d-none');
            $('#inp_pembinaan').prop('required', true);
            $('#pj_default_hint').text('Penanggung Jawab biasanya: Wali Kelas / Guru BK / Orang Tua');
        }
        populateTL(sumber);
    }

    function showKonteksSiswa(idSiswa) {
        var box = $('#konteksSumber');
        if (!idSiswa) {
            box.html('<small class="text-muted">Pilih siswa untuk melihat status pembinaan & konseling yang perlu di-TL.</small>');
            return;
        }
        var listB = pembinaanBySiswa(idSiswa);
        var listK = konselingBySiswa(idSiswa);
        var txtB = listB.length + ' pembinaan belum di-TL';
        var txtK = listK.length + ' konseling belum di-TL';
        box.html('<div class="alert alert-info mb-0 py-2"><strong>Status:</strong> ' + txtB + ' &bull; ' + txtK + '</div>');
    }

    $('#inp_siswa').on('change', function() {
        var sid = $(this).val();
        showKonteksSiswa(sid);
        fillBinaDropdown(sid, '');
        fillKonsDropdown(sid, '');
    });

    $('#inp_sumber').on('change', function() {
        switchSumberWrap($(this).val());
    });

    // Pilih pembinaan sumber -> auto-isi tindakan jika masih kosong
    $('#inp_pembinaan').on('change', function() {
        var item = $('#inp_pembinaan option:selected').data('item');
        if (!item) return;
        if (!$('#inp_tindakan').val() && item.permasalahan) {
            $('#inp_tindakan').val('Tindak lanjut perbaikan kasus: ' + item.permasalahan);
            autogrowTL($('#inp_tindakan'));
        }
    });

    // Pilih konseling sumber -> auto-isi tindakan jika masih kosong
    $('#inp_konseling').on('change', function() {
        var item = $('#inp_konseling option:selected').data('item');
        if (!item) return;
        if (!$('#inp_tindakan').val() && item.ringkasan_masalah) {
            $('#inp_tindakan').val('Tindak lanjut konseling: ' + item.topik + ' - ' + item.ringkasan_masalah);
            autogrowTL($('#inp_tindakan'));
        }
        if (!$('#inp_pj').val()) {
            $('#inp_pj').val('Guru BK');
        }
    });

    $('#sel_tl_tindakan').on('change', function() {
        var item = $('#sel_tl_tindakan option:selected').data('item');
        if ($('#sel_tl_tindakan').val()) {
            $('#inp_tindakan').val($('#sel_tl_tindakan').val());
            autogrowTL($('#inp_tindakan'));
        }
        if (item && item.penanggung_jawab && !$('#inp_pj').val()) $('#inp_pj').val(item.penanggung_jawab);
    });

    $('#btnTambahTL').on('click', function() {
        $('#formTLAction').val('tambah');
        $('#tlId').val('');
        $('#modalTLTitle').text('Tambah Rencana Tindak Lanjut');
        $('#formTL')[0].reset();
        $('#inp_sumber').val('Pembinaan');
        switchSumberWrap('Pembinaan');
        showKonteksSiswa('');
        fillBinaDropdown('', '');
        fillKonsDropdown('', '');
        $('#modalTL').modal('show');
        setTimeout(function() {
            $('#modalTL textarea').each(function() { autogrowTL($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-tl', function() {
        var data = $(this).data('json');
        $('#formTLAction').val('edit');
        $('#tlId').val(data.id);
        $('#modalTLTitle').text('Edit Tindak Lanjut');
        if ($('#inp_siswa option[value="' + data.id_siswa + '"]').length === 0) {
            var optEdit = new Option(data.nama_siswa + (data.nisn ? ' (' + data.nisn + ')' : ''), data.id_siswa, true, true);
            $('#inp_siswa').append(optEdit);
        }
        $('#inp_siswa').val(data.id_siswa);
        $('#inp_tanggal').val(data.tanggal);
        var src = (data.sumber === 'Konseling') ? 'Konseling' : 'Pembinaan';
        $('#inp_sumber').val(src);
        switchSumberWrap(src);
        fillBinaDropdown(data.id_siswa, data.id_pembinaan);
        fillKonsDropdown(data.id_siswa, data.id_konseling);
        $('#sel_tl_tindakan option').each(function() {
            if ($(this).val() === (data.tindakan || '')) $(this).prop('selected', true);
        });
        $('#inp_tindakan').val(data.tindakan);
        $('#inp_pj').val(data.penanggung_jawab);
        $('#inp_target').val(data.target_selesai || '');
        $('#inp_selesai').val(data.tanggal_selesai || '');
        $('#inp_status').val(data.status);
        showKonteksSiswa(data.id_siswa);
        $('#modalTL').modal('show');
        setTimeout(function() {
            $('#modalTL textarea').each(function() { autogrowTL($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-detail-tl', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_sumber').html('<span class="badge badge-' + (data.sumber === 'Konseling' ? 'info' : 'primary') + '">' + data.sumber + '</span>');
        var srcTxt = '-';
        if (data.sumber === 'Konseling') {
            if (data.c_topik) {
                srcTxt = data.c_topik + ' &mdash; ' + (data.c_masalah || '-') + ' (' + (data.c_tanggal || '-') + ', ' + (data.c_status || '-') + ')';
            } else if (data.id_konseling) {
                srcTxt = 'Konseling #' + data.id_konseling;
            }
            $('#lbl_det_sumber').text('Konseling Sumber');
        } else {
            if (data.b_masalah) {
                srcTxt = data.b_masalah + ' (' + (data.b_tanggal || '-') + ', ' + (data.b_status || '-') + ')';
            } else if (data.id_pembinaan) {
                srcTxt = 'Pembinaan #' + data.id_pembinaan;
            }
            $('#lbl_det_sumber').text('Pembinaan Sumber');
        }
        $('#det_bina').html(srcTxt);
        $('#det_status').text(data.status);
        $('#det_pj').text(data.penanggung_jawab);
        $('#det_target').text(data.target_selesai || '-');
        $('#det_selesai').text(data.tanggal_selesai || '-');
        $('#det_tindakan').text(data.tindakan);
        $('#modalDetailTL').modal('show');
    });

    $(document).on('click', '.btn-hapus-tl', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Tindak Lanjut?',
            text: 'Data tindak lanjut untuk ' + nama + ' akan dihapus.',
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
.aksi-satu-baris { display: inline-flex; flex-wrap: nowrap; gap: 4px; align-items: center; justify-content: center; white-space: nowrap; }
.aksi-satu-baris .btn { margin: 0; flex: 0 0 auto; width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; line-height: 1; }
.aksi-satu-baris .btn i { margin: 0; font-size: 13px; line-height: 1; }
#table-tindak-lanjut td:last-child { white-space: nowrap; }
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Tindak Lanjut <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($user_level === 'admin'): ?>
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="form-inline">
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

            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="row">
                        <?php if (isset($_GET['kelas'])): ?><input type="hidden" name="kelas" value="<?= (int)$_GET['kelas'] ?>"><?php endif; ?>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Sumber Tindak Lanjut</label>
                            <select name="f_sumber" class="form-control form-control-sm">
                                <option value="">-- Semua Sumber --</option>
                                <?php foreach ($sumber_options as $s): ?>
                                    <option value="<?= $s ?>" <?= $f_sumber === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm">
                                <option value="">-- Semua Status --</option>
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm mr-2"><i class="fas fa-search"></i> Filter</button>
                            <a href="tindak_lanjut.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-light border small text-muted mb-3">
                <i class="fas fa-stream mr-1 text-primary"></i> <strong>Hibrid (Pembinaan &amp; Konseling):</strong> Tindak lanjut dapat dibuat dari
                <strong>Pembinaan Siswa</strong> maupun <strong>Konseling Awal</strong> yang BELUM ditindaklanjuti.
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Monitoring Tindak Lanjut Siswa</h4>
                    <div>
                        <a href="data_tindak_lanjut.php" class="btn btn-info btn-sm mr-1">
                            <i class="fas fa-database mr-1"></i> Data Tindak Lanjut
                        </a>
                        <?php
                        $qs_tl = [];
                        if ($selected_kelas_id > 0) { $qs_tl['kelas'] = $selected_kelas_id; }
                        if ($f_sumber !== '') { $qs_tl['f_sumber'] = $f_sumber; }
                        if ($f_status !== '') { $qs_tl['f_status'] = $f_status; }
                        $url_tl_cetak = 'export_tindak_lanjut_pdf.php?' . http_build_query(array_merge($qs_tl, ['mode' => 'print']));
                        $url_tl_xls = 'export_tindak_lanjut_excel.php?' . http_build_query($qs_tl);
                        ?>
                        <a href="<?= htmlspecialchars($url_tl_cetak) ?>" target="_blank" class="btn btn-danger btn-sm mr-1" title="Cetak / Simpan PDF">
                            <i class="fas fa-print mr-1"></i> Cetak / PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_tl_xls) ?>" class="btn btn-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahTL" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Rencana Baru
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-tindak-lanjut">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>Sumber</th>
                                    <th>Tindakan</th>
                                    <th>Penanggung Jawab</th>
                                    <th>Target Selesai</th>
                                    <th>Tanggal Selesai</th>
                                    <th>Status</th>
                                    <th style="width:165px;min-width:165px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = 'secondary';
                                    if ($r['status'] === 'Selesai') $st_badge = 'success';
                                    elseif ($r['status'] === 'Proses') $st_badge = 'warning';
                                    elseif ($r['status'] === 'Rencana') $st_badge = 'primary';
                                    elseif ($r['status'] === 'Dibatalkan') $st_badge = 'danger';
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong>
                                            <?php if ($r['sumber'] === 'Konseling' && !empty($r['c_topik'])): ?>
                                                <div class="mt-1"><small class="badge badge-info" title="Sumber Konseling">Konseling: <?= htmlspecialchars(mb_strimwidth($r['c_topik'] . ' - ' . ($r['c_masalah'] ?? ''), 0, 32, '...')) ?></small></div>
                                            <?php elseif (!empty($r['b_masalah'])): ?>
                                                <div class="mt-1"><small class="badge badge-light border" title="Sumber Pembinaan">Pembinaan: <?= htmlspecialchars(mb_strimwidth($r['b_masalah'], 0, 32, '...')) ?></small></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= $r['sumber'] === 'Konseling' ? 'info' : 'primary' ?>"><?= htmlspecialchars($r['sumber']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars(mb_strimwidth($r['tindakan'], 0, 40, '...')) ?></td>
                                        <td><?= htmlspecialchars($r['penanggung_jawab']) ?></td>
                                        <td><?= !empty($r['target_selesai']) ? date('d/m/Y', strtotime($r['target_selesai'])) : '-' ?></td>
                                        <td><?= !empty($r['tanggal_selesai']) ? date('d/m/Y', strtotime($r['tanggal_selesai'])) : '-' ?></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center align-middle">
                                            <div class="aksi-satu-baris">
                                                <button type="button" class="btn btn-info btn-sm btn-detail-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-tl" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                                <a href="export_tindak_lanjut_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF laporan siswa ini">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_tindak_lanjut_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-success btn-sm" title="Ekspor Excel siswa ini">
                                                    <i class="fas fa-file-excel"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Form Tambah / Edit -->
<div class="modal fade" id="modalTL" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formTL">
                <input type="hidden" name="action" id="formTLAction" value="tambah">
                <input type="hidden" name="id" id="tlId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTLTitle">Tindak Lanjut Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <style>
                        #modalTL textarea { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Siswa (Pembinaan / Konseling) <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control" required>
                                <option value="">-- Pilih Siswa yang Belum di-TL --</option>
                                <?php foreach ($siswa_list as $s): ?>
                                    <?php
                                    $sid = (int)$s['id_siswa'];
                                    $nB = (int)($s['n_bina_belum'] ?? 0);
                                    $nK = (int)($s['n_kons_belum'] ?? 0);
                                    $tagParts = [];
                                    if ($nB > 0) $tagParts[] = "$nB pembinaan";
                                    if ($nK > 0) $tagParts[] = "$nK konseling";
                                    $tagStr = implode(', ', $tagParts);
                                    ?>
                                    <option value="<?= $sid ?>">
                                        <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>) &mdash; [<?= $tagStr ?> belum di-TL]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="konteksSumber" class="mt-2"><small class="text-muted">Pilih siswa untuk melihat status pembinaan & konseling yang perlu di-TL.</small></div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Sumber Masalah <span class="text-danger">*</span></label>
                            <select name="sumber" id="inp_sumber" class="form-control" required>
                                <option value="Pembinaan">Pembinaan Siswa</option>
                                <option value="Konseling">Konseling Awal</option>
                            </select>
                            <small class="text-muted">Pilih apakah tindak lanjut dari Pembinaan atau dari Konseling.</small>
                        </div>
                        <div class="col-md-6 form-group" id="wrap_pembinaan">
                            <label class="font-weight-bold">Pembinaan Sumber <span class="text-danger">*</span></label>
                            <select name="id_pembinaan" id="inp_pembinaan" class="form-control" required>
                                <option value="">-- Pilih pembinaan sumber --</option>
                            </select>
                            <small class="text-muted">Hanya menampilkan pembinaan yang belum ditindaklanjuti.</small>
                        </div>
                        <div class="col-md-6 form-group d-none" id="wrap_konseling">
                            <label class="font-weight-bold">Konseling Sumber <span class="text-danger">*</span></label>
                            <select name="id_konseling" id="inp_konseling" class="form-control">
                                <option value="">-- Pilih konseling sumber --</option>
                            </select>
                            <small class="text-muted">Hanya menampilkan sesi konseling yang belum ditindaklanjuti.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Tanggal Rencana</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 form-group">
                            <label class="font-weight-bold">Tindakan / Langkah Perbaikan <span class="text-danger">*</span></label>
                            <select id="sel_tl_tindakan" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template tindakan / langkah perbaikan --</option>
                            </select>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai sumber, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Penanggung Jawab <span class="text-danger">*</span></label>
                            <input type="text" name="penanggung_jawab" id="inp_pj" class="form-control" required placeholder="Wali Kelas / Guru BK / Orang Tua">
                            <small class="text-muted" id="pj_default_hint"></small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Target Selesai</label>
                            <input type="date" name="target_selesai" id="inp_target" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Tanggal Realisasi Selesai</label>
                            <input type="date" name="tanggal_selesai" id="inp_selesai" class="form-control">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Status</label>
                            <select name="status" id="inp_status" class="form-control">
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>"><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailTL" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Tindak Lanjut</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="35%">Tanggal Rencana</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NIS/NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Sumber Masalah</th><td id="det_sumber"></td></tr>
                    <tr><th id="lbl_det_sumber">Rincian Sumber</th><td id="det_bina"></td></tr>
                    <tr><th>Penanggung Jawab</th><td id="det_pj"></td></tr>
                    <tr><th>Target Selesai</th><td id="det_target"></td></tr>
                    <tr><th>Tanggal Selesai</th><td id="det_selesai"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th colspan="2">Tindakan:</th></tr>
                    <tr><td colspan="2" id="det_tindakan" style="white-space: pre-wrap;" class="bg-light p-2"></td></tr>
                </table>
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
