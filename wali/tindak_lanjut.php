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
        $message = ['type' => 'danger', 'text' => 'Akses ditolak. Admin hanya mode lihat.'];
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $sumber = in_array($_POST['sumber'] ?? '', ['Pembinaan', 'Konseling'], true) ? $_POST['sumber'] : 'Pembinaan';
        $id_pembinaan = isset($_POST['id_pembinaan']) && $_POST['id_pembinaan'] !== '' ? (int)$_POST['id_pembinaan'] : null;
        $id_konseling = isset($_POST['id_konseling']) && $_POST['id_konseling'] !== '' ? (int)$_POST['id_konseling'] : null;
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $penanggung_jawab = trim((string)($_POST['penanggung_jawab'] ?? ''));
        $target_selesai = !empty($_POST['target_selesai']) ? date('Y-m-d', strtotime($_POST['target_selesai'])) : null;
        $tanggal_selesai = !empty($_POST['tanggal_selesai']) ? date('Y-m-d', strtotime($_POST['tanggal_selesai'])) : null;
        $posted_status = trim((string)($_POST['status'] ?? ''));
        $valid_status = ['Rencana', 'Proses', 'Selesai', 'Dibatalkan'];

        $oldData = null;
        if ($action === 'edit' && $id > 0) {
            $stOld = $pdo->prepare("SELECT * FROM tb_tindak_lanjut_wali WHERE id = ?" . (!$is_admin_or_kepala ? " AND id_wali = " . (int)$guru_id : ""));
            $stOld->execute([$id]);
            $oldData = $stOld->fetch(PDO::FETCH_ASSOC);
            if ($oldData) {
                if ($id_siswa <= 0) $id_siswa = (int)$oldData['id_siswa'];
                if ($id_kelas <= 0) $id_kelas = (int)$oldData['id_kelas'];
                if (empty($_POST['sumber'])) $sumber = $oldData['sumber'];
                if ($id_pembinaan === null || $id_pembinaan <= 0) $id_pembinaan = $oldData['id_pembinaan'] ? (int)$oldData['id_pembinaan'] : null;
                if ($id_konseling === null || $id_konseling <= 0) $id_konseling = $oldData['id_konseling'] ? (int)$oldData['id_konseling'] : null;
            }
        }

        if ($action === 'tambah') {
            $status = in_array($posted_status, $valid_status, true) ? $posted_status : 'Proses';
        } else {
            $status = in_array($posted_status, $valid_status, true) ? $posted_status : (string)($oldData['status'] ?? 'Proses');
            if (!in_array($status, $valid_status, true)) $status = 'Proses';
        }
        if (function_exists('tl_wali_effective_status')) {
            $status = tl_wali_effective_status([
                'status' => $status,
                'tanggal_selesai' => $tanggal_selesai,
                'target_selesai' => $target_selesai,
            ]);
        } elseif ($status !== 'Dibatalkan' && !empty($tanggal_selesai) && $tanggal_selesai <= date('Y-m-d')) {
            $status = 'Selesai';
        }

        if ($id_siswa <= 0 || $tindakan === '' || $penanggung_jawab === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Tindakan, dan Penanggung Jawab.'];
        } elseif ($action === 'tambah' && (($sumber === 'Pembinaan' && ($id_pembinaan ?? 0) <= 0) || ($sumber === 'Konseling' && ($id_konseling ?? 0) <= 0))) {
            $message = ['type' => 'warning', 'text' => 'Pilih ' . ($sumber === 'Konseling' ? 'konseling' : 'pembinaan') . ' sumber terlebih dahulu.'];
        } else {
            // Validasi kepemilikan siswa (jika terhubung ke item spesifik)
            $boleh = true;
            $sumber_id = ($sumber === 'Pembinaan') ? $id_pembinaan : $id_konseling;
            if (($sumber_id ?? 0) > 0) {
                try {
                    if ($sumber === 'Pembinaan' && $id_pembinaan > 0) {
                        $stCek = $pdo->prepare("SELECT id_siswa FROM tb_pembinaan_siswa WHERE id = ?" . (!$is_admin_or_kepala ? " AND id_wali = " . (int)$guru_id : ""));
                        $stCek->execute([$id_pembinaan]);
                        $idOwner = (int)$stCek->fetchColumn();
                        if ($idOwner > 0 && $idOwner !== $id_siswa) {
                            $message = ['type' => 'warning', 'text' => 'Pembinaan sumber milik siswa lain.'];
                            $boleh = false;
                        }
                    } elseif ($sumber === 'Konseling' && $id_konseling > 0) {
                        $stCek = $pdo->prepare("SELECT id_siswa FROM tb_konseling_awal WHERE id = ?" . (!$is_admin_or_kepala ? " AND id_wali = " . (int)$guru_id : ""));
                        $stCek->execute([$id_konseling]);
                        $idOwner = (int)$stCek->fetchColumn();
                        if ($idOwner > 0 && $idOwner !== $id_siswa) {
                            $message = ['type' => 'warning', 'text' => 'Konseling sumber milik siswa lain.'];
                            $boleh = false;
                        }
                    }
                } catch (Throwable $e) {}

                // Anti-ganda: 1 pembinaan / 1 konseling hanya boleh di-TL 1x
                if ($boleh) {
                    try {
                        if ($sumber === 'Pembinaan' && $id_pembinaan > 0) {
                            $sqlDup = "SELECT id FROM tb_tindak_lanjut_wali WHERE id_pembinaan = ?";
                            if ($action === 'edit' && $id > 0) $sqlDup .= " AND id <> " . (int)$id;
                            $stDup = $pdo->prepare($sqlDup);
                            $stDup->execute([$id_pembinaan]);
                            if ($stDup->fetchColumn()) {
                                $message = ['type' => 'warning', 'text' => 'Pembinaan ini SUDAH ditindaklanjuti.'];
                                $boleh = false;
                            }
                        } elseif ($sumber === 'Konseling' && $id_konseling > 0) {
                            $sqlDup = "SELECT id FROM tb_tindak_lanjut_wali WHERE id_konseling = ?";
                            if ($action === 'edit' && $id > 0) $sqlDup .= " AND id <> " . (int)$id;
                            $stDup = $pdo->prepare($sqlDup);
                            $stDup->execute([$id_konseling]);
                            if ($stDup->fetchColumn()) {
                                $message = ['type' => 'warning', 'text' => 'Konseling ini SUDAH ditindaklanjuti.'];
                                $boleh = false;
                            }
                        }
                    } catch (Throwable $e) {}
                }
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
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $sumber,
                        $tindakan, $penanggung_jawab, $target_selesai,
                        $tanggal_selesai, $status, $id_pembinaan, $id_konseling, $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Tindak lanjut berhasil diperbarui.'];
                }
                if (function_exists('sync_konseling_statuses')) { sync_konseling_statuses($pdo); } else { sync_pembinaan_statuses($pdo); }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_tindak_lanjut_wali WHERE id = ? " . (!$is_admin_or_kepala ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            if (function_exists('sync_konseling_statuses')) { sync_konseling_statuses($pdo); } else { sync_pembinaan_statuses($pdo); }
            $message = ['type' => 'success', 'text' => 'Data tindak lanjut berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
    }
}

// Pembersihan otomatis data ganda di tb_tindak_lanjut_wali jika ada
try {
    $pdo->exec("
        DELETE t1 FROM tb_tindak_lanjut_wali t1
        INNER JOIN tb_tindak_lanjut_wali t2
        WHERE t1.id > t2.id
          AND (
            (t1.id_pembinaan IS NOT NULL AND t1.id_pembinaan = t2.id_pembinaan)
            OR
            (t1.id_konseling IS NOT NULL AND t1.id_konseling = t2.id_konseling)
          )
    ");
} catch (Throwable $e) {}

// Alur 3: dropdown HIBRID (bisa dari Pembinaan ATAU Konseling).
// Peta item yang SUDAH di-TL (anti-ganda)
$tl_used_bina = [];
$tl_used_kons = [];
try {
    $stU = $pdo->prepare("SELECT id_pembinaan, id_konseling FROM tb_tindak_lanjut_wali WHERE id_pembinaan IS NOT NULL OR id_konseling IS NOT NULL");
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
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_pembinaan = b.id
            WHERE t.id IS NULL
            GROUP BY b.id_siswa
        ) bn ON bn.id_siswa = s.id_siswa
        LEFT JOIN (
            SELECT c.id_siswa, COUNT(c.id) AS n_kons_belum
            FROM tb_konseling_awal c
            LEFT JOIN tb_tindak_lanjut_wali t ON t.id_konseling = c.id
            WHERE t.id IS NULL
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
    if (!$is_admin_or_kepala) {
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
    if (!$is_admin_or_kepala) {
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

sync_pelanggaran_statuses($pdo);
// Tampilkan status efektif: lewat tanggal realisasi/target otomatis Selesai di layar,
// lalu kunci ke DB agar konsisten meski guru tidak mengubah manual.
$today_eff = date('Y-m-d');
try {
    $stEff = $pdo->prepare("UPDATE tb_tindak_lanjut_wali SET status = 'Selesai'
        WHERE status NOT IN ('Selesai','Dibatalkan') AND (
            (tanggal_selesai IS NOT NULL AND CAST(tanggal_selesai AS CHAR) NOT IN ('', '0000-00-00') AND tanggal_selesai <= ?)
            OR ((tanggal_selesai IS NULL OR CAST(tanggal_selesai AS CHAR) IN ('', '0000-00-00')) AND target_selesai IS NOT NULL AND CAST(target_selesai AS CHAR) NOT IN ('', '0000-00-00') AND target_selesai <= ?)
        )");
    $stEff->execute([$today_eff, $today_eff]);
} catch (Throwable $e) {
    error_log('tindak_lanjut backfill: ' . $e->getMessage());
}
$f_sumber = trim((string)($_GET['f_sumber'] ?? ''));
$f_status = trim((string)($_GET['f_status'] ?? ''));
$f_siswa = (int)($_GET['f_siswa'] ?? 0);

// Daftar semua siswa di kelas untuk filter
$filter_siswa_list = [];
try {
    $sqlFS = "SELECT s.id_siswa, s.nama_siswa, s.nisn, k.nama_kelas
              FROM tb_siswa s
              LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
              WHERE 1=1";
    $parFS = [];
    if ($selected_kelas_id > 0) {
        $sqlFS .= " AND s.id_kelas = ?";
        $parFS[] = $selected_kelas_id;
    }
    $sqlFS .= " ORDER BY s.nama_siswa ASC";
    $stFS = $pdo->prepare($sqlFS);
    $stFS->execute($parFS);
    $filter_siswa_list = $stFS->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $filter_siswa_list = [];
}

$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "t.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if (!$is_admin_or_kepala) {
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
if ($f_siswa > 0) {
    $where[] = "t.id_siswa = ?";
    $params[] = $f_siswa;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT t.*, s.nama_siswa, s.nisn, s.foto AS foto_siswa, k.nama_kelas,
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
if (function_exists('tl_wali_effective_status')) {
    foreach ($rows as &$rr) {
        $rr['status'] = tl_wali_effective_status($rr, $today_eff);
    }
    unset($rr);
}

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
    if (!$is_admin_or_kepala) {
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
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
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
    if ($.fn.select2) {
        $('#f_siswa').select2({
            placeholder: '-- Semua Siswa --',
            allowClear: true,
            width: '100%'
        }).on('change', function() {
            this.form.submit();
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
    function fillBinaDropdown(idSiswa, selectedId, extraObj) {
        $('#inp_pembinaan').empty().append('<option value="">-- Pilih pembinaan sumber --</option>');
        var list = pembinaanBySiswa(idSiswa);
        var found = false;
        list.forEach(function(b) {
            var lbl = b.tanggal + ' - ' + b.jenis_pembinaan + ' - ' + (b.permasalahan || '').substr(0, 60);
            var o = $('<option>').val(b.id).text(lbl.length > 110 ? lbl.substr(0, 110) + '...' : lbl);
            o.data('item', b);
            if (selectedId && String(selectedId) === String(b.id)) {
                o.prop('selected', true);
                found = true;
            }
            $('#inp_pembinaan').append(o);
        });
        if (selectedId && !found && extraObj) {
            var lbl = (extraObj.b_tanggal || '') + ' - ' + (extraObj.b_jenis || 'Pembinaan') + ' - ' + (extraObj.b_masalah || '');
            var oEdit = $('<option>').val(selectedId).text(lbl.length > 110 ? lbl.substr(0, 110) + '...' : lbl).prop('selected', true);
            oEdit.data('item', { permasalahan: extraObj.b_masalah || '' });
            $('#inp_pembinaan').append(oEdit);
        }
        $('#inp_pembinaan').trigger('change');
    }

    // Filter konseling sumber per siswa
    function konselingBySiswa(idSiswa) {
        return konselingList.filter(function(k) {
            return String(k.id_siswa) === String(idSiswa);
        });
    }
    function fillKonsDropdown(idSiswa, selectedId, extraObj) {
        $('#inp_konseling').empty().append('<option value="">-- Pilih konseling sumber --</option>');
        var list = konselingBySiswa(idSiswa);
        var found = false;
        list.forEach(function(k) {
            var lbl = k.tanggal + ' - ' + k.topik + ' - ' + (k.ringkasan_masalah || '').substr(0, 60);
            var o = $('<option>').val(k.id).text(lbl.length > 110 ? lbl.substr(0, 110) + '...' : lbl);
            o.data('item', k);
            if (selectedId && String(selectedId) === String(k.id)) {
                o.prop('selected', true);
                found = true;
            }
            $('#inp_konseling').append(o);
        });
        if (selectedId && !found && extraObj) {
            var lbl = (extraObj.c_tanggal || '') + ' - ' + (extraObj.c_topik || 'Konseling') + ' - ' + (extraObj.c_masalah || '');
            var oEdit = $('<option>').val(selectedId).text(lbl.length > 110 ? lbl.substr(0, 110) + '...' : lbl).prop('selected', true);
            oEdit.data('item', { topik: extraObj.c_topik || '', ringkasan_masalah: extraObj.c_masalah || '' });
            $('#inp_konseling').append(oEdit);
        }
        $('#inp_konseling').trigger('change');
    }

    function switchSumberWrap(sumber) {
        if (sumber === 'Konseling') {
            $('#wrap_pembinaan').hide();
            $('#wrap_konseling').show();
            $('#inp_pembinaan').prop('required', false);
            $('#inp_konseling').prop('required', true);
            $('#pj_default_hint').text('Penanggung Jawab biasanya: Guru BK / Wali Kelas');
        } else {
            $('#wrap_pembinaan').show();
            $('#wrap_konseling').hide();
            $('#inp_konseling').prop('required', false);
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
        if ($(this).data('skip-change')) {
            $(this).removeData('skip-change');
            return;
        }
        var sid = $(this).val();
        showKonteksSiswa(sid);
        fillBinaDropdown(sid, '');
        fillKonsDropdown(sid, '');
        var listB = pembinaanBySiswa(sid);
        var listK = konselingBySiswa(sid);
        if (listB.length === 0 && listK.length > 0) {
            $('#inp_sumber').val('Konseling').trigger('change');
        } else {
            $('#inp_sumber').val('Pembinaan').trigger('change');
        }
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
            $('#inp_tindakan').val('Tindak lanjut konseling: ' + (item.topik ? item.topik + ' - ' : '') + item.ringkasan_masalah);
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

    function svTodayStr() {
        var d = new Date();
        var m = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        return d.getFullYear() + '-' + m + '-' + day;
    }
    function svEffectiveStatus(tglSelesai, tgtSelesai, cur) {
        cur = (cur || '').toString();
        if (cur === 'Dibatalkan' || cur === 'Selesai') return cur;
        var today = svTodayStr();
        tglSelesai = (tglSelesai || '').toString();
        tgtSelesai = (tgtSelesai || '').toString();
        if (tglSelesai !== '' && tglSelesai <= today) return 'Selesai';
        if (tglSelesai === '' && tgtSelesai !== '' && tgtSelesai <= today) return 'Selesai';
        return cur || 'Rencana';
    }
    function svAutoStatus() {
        var eff = svEffectiveStatus($('#inp_selesai').val(), $('#inp_target').val(), $('#inp_status').val());
        if (eff !== $('#inp_status').val()) {
            $('#inp_status').val(eff).trigger('change');
        }
    }
    $('#inp_selesai, #inp_target').on('change', svAutoStatus);

    $('#btnTambahTL').on('click', function() {
        $('#formTLAction').val('tambah');
        $('#tlId').val('');
        $('#modalTLTitle').text('Tambah Rencana Tindak Lanjut');
        $('#formTL')[0].reset();
        $('#inp_siswa').val('');
        fillBinaDropdown('', '');
        fillKonsDropdown('', '');
        showKonteksSiswa('');
        $('#inp_sumber').val('Pembinaan');
        switchSumberWrap('Pembinaan');
        $('#inp_status').val('Rencana').trigger('change');
        $('#modalTL').modal('show');
        setTimeout(function() {
            $('#modalTL textarea').each(function() { autogrowTL($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-tl', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        $('#formTLAction').val('edit');
        $('#tlId').val(data.id);
        $('#modalTLTitle').text('Edit Tindak Lanjut');

        var optLbl = data.nama_siswa + (data.nisn ? ' (' + data.nisn + ')' : '');
        var $siswaSel = $('#inp_siswa');
        if ($siswaSel.find('option[value="' + data.id_siswa + '"]').length === 0) {
            $siswaSel.append(new Option(optLbl, data.id_siswa, true, true));
        }
        $siswaSel.val(data.id_siswa).trigger('change');
        if (window.GDSRefresh) { try { window.GDSRefresh($('#modalTL')[0]); } catch(e2) {} }
        showKonteksSiswa(data.id_siswa);

        var src = (data.sumber === 'Konseling') ? 'Konseling' : 'Pembinaan';
        fillBinaDropdown(data.id_siswa, data.id_pembinaan || '', data);
        fillKonsDropdown(data.id_siswa, data.id_konseling || '', data);

        $('#inp_sumber').val(src);
        switchSumberWrap(src);
        if (src === 'Pembinaan' && data.id_pembinaan) {
            $('#inp_pembinaan').val(String(data.id_pembinaan)).trigger('change');
        } else if (src === 'Konseling' && data.id_konseling) {
            $('#inp_konseling').val(String(data.id_konseling)).trigger('change');
        }

        $('#inp_tanggal').val(data.tanggal);
        $('#sel_tl_tindakan option').each(function() {
            if ($(this).val() === (data.tindakan || '')) $(this).prop('selected', true);
        });
        $('#inp_tindakan').val(data.tindakan || '');
        $('#inp_pj').val(data.penanggung_jawab || '');
        $('#inp_target').val(data.target_selesai || '');
        $('#inp_selesai').val(data.tanggal_selesai || '');
        $('#inp_status').val(svEffectiveStatus(data.tanggal_selesai || '', data.target_selesai || '', data.status || 'Rencana')).trigger('change');
        showKonteksSiswa(data.id_siswa);

        $('#modalTL').modal('show');
        setTimeout(function() {
            $('#modalTL textarea').each(function() { autogrowTL($(this)); });
        }, 120);
    });

    function escTl(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }
    function fmtTglTl(v) {
        v = (v || '').toString().substring(0, 10);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return v || '-';
        var p = v.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function statusBadgeTl(st) {
        var cls = 'secondary';
        if (st === 'Selesai') cls = 'success';
        else if (st === 'Proses') cls = 'warning';
        else if (st === 'Rencana') cls = 'primary';
        else if (st === 'Dibatalkan') cls = 'danger';
        return '<span class="badge badge-' + cls + '">' + escTl(st || '-') + '</span>';
    }
    $(document).on('click', '.btn-detail-tl', function() {
        var data = $(this).data('json');
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) {}
        }
        $('#det_tanggal').text(fmtTglTl(data.tanggal));
        $('#det_nama').text(data.nama_siswa || '-');
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_sumber').html('<span class="badge badge-' + (data.sumber === 'Konseling' ? 'info' : 'primary') + '">' + escTl(data.sumber || '-') + '</span>');
        var srcHtml = '<span class="text-muted">-</span>';
        if (data.sumber === 'Konseling') {
            if (data.c_topik || data.c_masalah || data.id_konseling) {
                srcHtml = '<div class="font-weight-bold text-info mb-1"><i class="fas fa-comments mr-1"></i>' + escTl(data.c_topik || 'Konseling') + '</div>';
                if (data.c_masalah) srcHtml += '<div class="mb-1" style="white-space: pre-wrap;">' + escTl(data.c_masalah) + '</div>';
                srcHtml += '<div><small class="text-muted"><i class="fas fa-calendar-alt mr-1"></i>' + escTl(fmtTglTl(data.c_tanggal)) + ' &nbsp; <span class="badge badge-light border">' + escTl(data.c_status || '-') + '</span></small></div>';
            } else if (data.id_konseling) {
                srcHtml = '<span class="badge badge-info">Konseling #' + escTl(data.id_konseling) + '</span>';
            }
            $('#lbl_det_sumber').text('Konseling Sumber');
        } else {
            if (data.b_masalah || data.b_jenis) {
                srcHtml = '<div class="font-weight-bold text-primary mb-1"><i class="fas fa-user-edit mr-1"></i>' + escTl(data.b_jenis || 'Pembinaan') + '</div>';
                if (data.b_masalah) srcHtml += '<div class="mb-1" style="white-space: pre-wrap;">' + escTl(data.b_masalah) + '</div>';
                srcHtml += '<div><small class="text-muted"><i class="fas fa-calendar-alt mr-1"></i>' + escTl(fmtTglTl(data.b_tanggal)) + ' &nbsp; <span class="badge badge-light border">' + escTl(data.b_status || '-') + '</span></small></div>';
            } else if (data.id_pembinaan) {
                srcHtml = '<span class="badge badge-primary">Pembinaan #' + escTl(data.id_pembinaan) + '</span>';
            }
            $('#lbl_det_sumber').text('Pembinaan Sumber');
        }
        $('#det_bina').html(srcHtml);
        $('#det_status').html(statusBadgeTl(data.status));
        $('#det_pj').text(data.penanggung_jawab || '-');
        $('#det_target').text(fmtTglTl(data.target_selesai));
        $('#det_selesai').text(fmtTglTl(data.tanggal_selesai));
        $('#det_tindakan').text(data.tindakan || '-');
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
.aksi-satu-baris .btn { margin: 0; flex: 0 0 auto; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; line-height: 1; border-radius: 6px; }
.aksi-satu-baris .btn i { margin: 0; font-size: 13px; line-height: 1; }

.tl-timeline { position: relative; padding: 10px 0 10px 8px; }
.tl-timeline::before { content: ''; position: absolute; left: 57px; top: 12px; bottom: 12px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, #6777ef, #3abaf4); opacity: .35; }
.tl-month-header { position: relative; z-index: 1; display: inline-block; margin: 16px 0 16px 34px; padding: 5px 18px; border-radius: 20px; background: #6777ef; color: #fff; font-weight: 800; font-size: 12.5px; letter-spacing: .3px; box-shadow: 0 4px 12px rgba(103,119,239,.35); }
.tl-month-header:first-child { margin-top: 0; }
.tl-timeline-item { position: relative; display: flex; gap: 14px; margin-bottom: 16px; }
.tl-timeline-date { flex: 0 0 76px; text-align: center; z-index: 1; }
.tl-timeline-date .d { display: block; width: 48px; height: 48px; margin: 0 auto; border-radius: 50%; background: #fff; border: 3px solid var(--tl-color, #6777ef); color: var(--tl-color, #6777ef); font-weight: 800; font-size: 18px; line-height: 42px; box-shadow: 0 3px 8px rgba(0,0,0,.10); }
.tl-timeline-date .m { display: block; margin-top: 4px; font-size: 11px; font-weight: 800; color: #6c757d; text-transform: uppercase; }
.tl-timeline-card { flex: 1; min-width: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 14px rgba(20,30,70,.06); padding: 14px 16px; border-left: 4.5px solid var(--tl-color, #6777ef); transition: box-shadow .2s ease, transform .2s ease; }
.tl-timeline-card:hover { box-shadow: 0 8px 24px rgba(20,30,70,.12); transform: translateY(-2px); }

.tl-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 800;
    flex-shrink: 0;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,.15);
}
.tl-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: inherit; }
.tl-preview-box {
    background: #f8fafc;
    border-left: 3.5px solid #6777ef;
    border-top: 1px solid #edf2f7;
    border-right: 1px solid #edf2f7;
    border-bottom: 1px solid #edf2f7;
    border-radius: 6px;
    padding: 12px 14px;
}
.tl-source-box {
    background: #f0f7ff;
    border: 1px solid #cfe3ff;
    border-radius: 6px;
    padding: 10px 12px;
}
.tl-source-box.is-konseling {
    background: #f0fdf4;
    border-color: #bbf7d0;
}

@media (max-width: 575.98px) {
    .tl-timeline::before { left: 47px; }
    .tl-timeline-item { gap: 10px; }
    .tl-timeline-date { flex-basis: 60px; }
    .tl-timeline-date .d { width: 38px; height: 38px; font-size: 15px; line-height: 32px; }
}
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Tindak Lanjut <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if ($is_admin_or_kepala): ?>
            <div class="card mb-3">
                <div class="card-body p-3">
                    <form method="GET" class="form-row align-items-end">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <div class="form-group col-md-4 mb-0" style="max-width: 340px;">
                            <label>Pilih Kelas:</label>
                            <select name="kelas" class="form-control" style="width: 100%; max-width: 320px;" onchange="this.form.submit()">
                                <option value="">-- Semua Kelas --</option>
                                <?php foreach ($all_classes as $c): ?>
                                    <option value="<?= (int)$c['id_kelas'] ?>" <?= $selected_kelas_id === (int)$c['id_kelas'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter Card -->
            <div class="card mb-3">
                <div class="card-header py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Tindak Lanjut</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="row">
                        <?php if (isset($_GET['session_type'])): ?><input type="hidden" name="session_type" value="<?= htmlspecialchars($_GET['session_type']) ?>"><?php endif; ?>
                        <?php if (isset($_GET['kelas'])): ?><input type="hidden" name="kelas" value="<?= (int)$_GET['kelas'] ?>"><?php endif; ?>
                        <div class="col-md-4 col-lg mb-2">
                            <label class="small font-weight-bold">Siswa</label>
                            <select name="f_siswa" id="f_siswa" class="form-control form-control-sm select2-filter-siswa">
                                <option value="">-- Semua Siswa --</option>
                                <?php foreach ($filter_siswa_list as $sw): ?>
                                    <option value="<?= (int)$sw['id_siswa'] ?>" <?= $f_siswa === (int)$sw['id_siswa'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sw['nama_siswa']) ?><?= !empty($sw['nisn']) ? ' (' . htmlspecialchars($sw['nisn']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg mb-2">
                            <label class="small font-weight-bold">Sumber Tindak Lanjut</label>
                            <select name="f_sumber" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Sumber --</option>
                                <?php foreach ($sumber_options as $s): ?>
                                    <option value="<?= $s ?>" <?= $f_sumber === $s ? 'selected' : '' ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-lg mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select name="f_status" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Status --</option>
                                <?php foreach ($status_options as $st): ?>
                                    <option value="<?= $st ?>" <?= $f_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-1 d-flex align-items-center">
                            <?php
                            $reset_params = [];
                            if (isset($_GET['session_type'])) $reset_params['session_type'] = $_GET['session_type'];
                            if (isset($_GET['kelas'])) $reset_params['kelas'] = (int)$_GET['kelas'];
                            $reset_url = 'tindak_lanjut.php' . (!empty($reset_params) ? '?' . http_build_query($reset_params) : '');
                            ?>
                            <a href="<?= htmlspecialchars($reset_url) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat opsi diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-light border small text-muted mb-3">
                <i class="fas fa-stream mr-1 text-primary"></i> <strong>Hibrid (Pembinaan &amp; Konseling):</strong> Tindak lanjut dapat dibuat dari
                <strong>Pembinaan Siswa</strong> maupun <strong>Konseling Siswa</strong> yang BELUM ditindaklanjuti.
            </div>

            <!-- Card Utama Timeline Monitoring Tindak Lanjut -->
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3" style="border-radius: 14px 14px 0 0; border-bottom: 1px solid #f1f5f9; gap: 10px;">
                    <div>
                        <h4 class="mb-0 font-weight-bold text-dark"><i class="fas fa-history text-primary mr-2"></i>Tindak Lanjut Pembinaan</h4>
                        <small class="text-muted">Menampilkan <span id="count-tl-timeline" class="font-weight-bold text-primary"><?= count($rows) ?></span> riwayat tindak lanjut</small>
                    </div>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                        <a href="data_tindak_lanjut.php<?= isset($_GET['session_type']) ? '?session_type=' . urlencode($_GET['session_type']) : '' ?>" class="btn btn-info btn-sm font-weight-bold" style="border-radius: 6px;">
                            <i class="fas fa-database mr-1"></i> Master Data
                        </a>
                        <?php
                        $qs_tl = [];
                        if ($selected_kelas_id > 0) { $qs_tl['kelas'] = $selected_kelas_id; }
                        if ($f_siswa > 0) { $qs_tl['f_siswa'] = $f_siswa; }
                        if ($f_sumber !== '') { $qs_tl['f_sumber'] = $f_sumber; }
                        if ($f_status !== '') { $qs_tl['f_status'] = $f_status; }
                        if (isset($_GET['session_type'])) { $qs_tl['session_type'] = $_GET['session_type']; }
                        $url_tl_cetak = 'export_tindak_lanjut_pdf.php?' . http_build_query(array_merge($qs_tl, ['mode' => 'print']));
                        $url_tl_xls = 'export_tindak_lanjut_excel.php?' . http_build_query($qs_tl);
                        ?>
                        <a href="<?= htmlspecialchars($url_tl_cetak) ?>" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF" style="border-radius: 6px;">
                            <i class="fas fa-print mr-1"></i> PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_tl_xls) ?>" class="btn btn-success btn-sm" title="Ekspor Excel" style="border-radius: 6px;">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <?php if ($can_crud): ?>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold" id="btnTambahTL" <?= empty($siswa_list) && !$is_admin_or_kepala ? 'disabled' : '' ?> style="border-radius: 6px;">
                            <i class="fas fa-plus mr-1"></i> Rencana Baru
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body bg-light p-3 p-md-4" style="border-radius: 0 0 14px 14px;">
                    <?php if (empty($rows)): ?>
                        <div class="text-center py-5 bg-white rounded shadow-sm border">
                            <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted font-weight-bold">Belum Ada Data Tindak Lanjut</h5>
                            <p class="text-muted small mb-0">Silakan buat rencana tindak lanjut baru atau sesuaikan filter di atas.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $bulan_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        $cur_month = '';
                        ?>
                        <div class="tl-timeline">
                            <?php foreach ($rows as $i => $r): ?>
                                <?php
                                $tgl_raw = $r['tanggal'] ?? date('Y-m-d');
                                $mk = date('Y-m', strtotime($tgl_raw));
                                if ($mk !== $cur_month):
                                    $cur_month = $mk;
                                ?>
                                    <div class="tl-month-header" data-month="<?= $cur_month ?>">
                                        <i class="far fa-calendar-alt mr-1"></i> <?= $bulan_id[(int)date('n', strtotime($tgl_raw))] . ' ' . date('Y', strtotime($tgl_raw)) ?>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $st = $r['status'] ?? 'Rencana';
                                $tl_color = '#6777ef';
                                $st_badge = 'badge-primary';
                                $st_icon = 'fas fa-clipboard-list';
                                if ($st === 'Selesai') {
                                    $tl_color = '#10b981';
                                    $st_badge = 'badge-success';
                                    $st_icon = 'fas fa-check-circle';
                                } elseif ($st === 'Proses') {
                                    $tl_color = '#ffa426';
                                    $st_badge = 'badge-warning';
                                    $st_icon = 'fas fa-spinner fa-spin';
                                } elseif ($st === 'Dibatalkan') {
                                    $tl_color = '#fc544b';
                                    $st_badge = 'badge-danger';
                                    $st_icon = 'fas fa-times-circle';
                                }

                                $is_konseling = ($r['sumber'] === 'Konseling');
                                $src_badge = $is_konseling ? 'badge-info' : 'badge-primary';
                                $src_icon = $is_konseling ? 'fas fa-comments' : 'fas fa-user-edit';

                                // Initials siswa
                                $parts = preg_split('/\s+/', trim((string)$r['nama_siswa']));
                                $initials = '';
                                if (!empty($parts[0])) $initials .= mb_strtoupper(mb_substr($parts[0], 0, 1));
                                if (isset($parts[1]) && !empty($parts[1])) $initials .= mb_strtoupper(mb_substr($parts[1], 0, 1));
                                if ($initials === '') $initials = 'S';
                                ?>
                                <div class="tl-timeline-item" data-month="<?= $cur_month ?>" style="--tl-color: <?= $tl_color ?>;">
                                    <div class="tl-timeline-date">
                                        <span class="d"><?= date('d', strtotime($tgl_raw)) ?></span>
                                        <span class="m"><?= mb_substr($bulan_id[(int)date('n', strtotime($tgl_raw))], 0, 3) ?></span>
                                    </div>
                                    <div class="tl-timeline-card">
                                        <!-- Header Siswa & Status -->
                                        <div class="d-flex justify-content-between align-items-start mb-2.5 pb-2 border-bottom">
                                            <div class="d-flex align-items-center" style="gap: 12px; min-width: 0;">
                                                <div class="tl-avatar" style="background: <?= $tl_color ?>;">
                                                    <?= getPersonAvatarHtml($r['foto_siswa'] ?? '', htmlspecialchars($initials)) ?>
                                                </div>
                                                <div style="min-width: 0;">
                                                    <h6 class="mb-0 font-weight-bold text-truncate" style="font-size: 15px; line-height: 1.3;" title="<?= htmlspecialchars($r['nama_siswa']) ?>">
                                                        <a href="javascript:void(0)" class="text-dark btn-detail-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' style="text-decoration: none;">
                                                            <?= htmlspecialchars($r['nama_siswa']) ?>
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex flex-wrap align-items-center small text-muted mt-1" style="gap: 6px;">
                                                        <?php if (!empty($r['nisn'])): ?>
                                                            <span class="text-muted"><i class="fas fa-id-card mr-1"></i>NISN: <?= htmlspecialchars($r['nisn']) ?></span>
                                                            <span class="text-muted">&bull;</span>
                                                        <?php endif; ?>
                                                        <span class="badge badge-light border text-dark font-weight-bold px-2 py-0.5" style="border-radius: 4px; font-size: 10.5px;">
                                                            <i class="fas fa-chalkboard text-muted mr-1"></i> Kelas <?= htmlspecialchars($r['nama_kelas'] ?? '-') ?>
                                                        </span>
                                                        <span class="badge <?= $src_badge ?> px-2 py-0.5" style="border-radius: 4px; font-size: 10.5px;">
                                                            <i class="<?= $src_icon ?> mr-1"></i> <?= htmlspecialchars($r['sumber']) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="ml-2 flex-shrink-0">
                                                <span class="badge <?= $st_badge ?> px-2.5 py-1 font-weight-bold" style="font-size: 11px; border-radius: 6px;">
                                                    <i class="<?= $st_icon ?> mr-1"></i> <?= htmlspecialchars($r['status']) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Sumber Pembinaan / Konseling Highlight -->
                                        <?php if ($is_konseling && (!empty($r['c_topik']) || !empty($r['c_masalah']))): ?>
                                            <div class="tl-source-box is-konseling mb-2.5">
                                                <div class="small font-weight-bold text-info mb-1 d-flex align-items-center">
                                                    <i class="fas fa-comments mr-1.5"></i> Kasus Konseling Terkait: <?= htmlspecialchars((string)($r['c_topik'] ?: 'Konseling')) ?>
                                                </div>
                                                <?php if (!empty($r['c_masalah'])): ?>
                                                    <div class="small text-dark" style="line-height: 1.45; padding-left: 2px;">
                                                        <?= nl2br(htmlspecialchars((string)$r['c_masalah'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif (!empty($r['b_masalah']) || !empty($r['b_jenis'])): ?>
                                            <div class="tl-source-box mb-2.5">
                                                <div class="small font-weight-bold text-primary mb-1 d-flex align-items-center">
                                                    <i class="fas fa-user-edit mr-1.5"></i> Permasalahan Pembinaan: <?= htmlspecialchars((string)($r['b_jenis'] ?: 'Pembinaan')) ?>
                                                </div>
                                                <?php if (!empty($r['b_masalah'])): ?>
                                                    <div class="small text-dark" style="line-height: 1.45; padding-left: 2px;">
                                                        <?= nl2br(htmlspecialchars((string)$r['b_masalah'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Tindakan / Rencana Perbaikan (Highlight Box dengan Padding Lega) -->
                                        <div class="tl-preview-box mb-2.5">
                                            <div class="small font-weight-bold text-dark mb-1 d-flex align-items-center">
                                                <i class="fas fa-tasks text-primary mr-2" style="font-size: 11px;"></i> Tindakan / Langkah Perbaikan:
                                            </div>
                                            <div class="text-dark small" style="line-height: 1.5; font-size: 12.5px; padding-left: 2px;">
                                                <?= nl2br(htmlspecialchars($r['tindakan'])) ?>
                                            </div>
                                        </div>

                                        <!-- Meta Info Bar & Aksi -->
                                        <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 border-top" style="gap: 8px;">
                                            <div class="d-flex flex-wrap align-items-center small text-muted" style="gap: 6px;">
                                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-user-tie mr-1 text-primary"></i> PJ: <?= htmlspecialchars($r['penanggung_jawab'] ?: '-') ?>
                                                </span>
                                                <span class="badge badge-light border text-dark px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-bullseye mr-1 text-warning"></i> Target: <?= !empty($r['target_selesai']) ? date('d/m/Y', strtotime($r['target_selesai'])) : '-' ?>
                                                </span>
                                                <?php if (!empty($r['tanggal_selesai'])): ?>
                                                <span class="badge badge-light border text-success px-2 py-1 font-weight-bold" style="font-size: 11px; border-radius: 5px;">
                                                    <i class="fas fa-check-double mr-1 text-success"></i> Selesai: <?= date('d/m/Y', strtotime($r['tanggal_selesai'])) ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="aksi-satu-baris">
                                                <button type="button" class="btn btn-info btn-sm btn-detail-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Rincian Lengkap">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($can_crud): ?>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-tl" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit Tindak Lanjut">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-tl" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                                <a href="export_tindak_lanjut_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF Siswa Ini">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_tindak_lanjut_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-success btn-sm" title="Ekspor Excel Siswa Ini">
                                                    <i class="fas fa-file-excel"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
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
                                <option value="Konseling">Konseling Siswa</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group" id="wrap_pembinaan">
                            <label class="font-weight-bold">Pembinaan Sumber <span class="text-danger">*</span></label>
                            <select name="id_pembinaan" id="inp_pembinaan" class="form-control">
                                <option value="">-- Pilih pembinaan sumber --</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group" id="wrap_konseling" style="display:none;">
                            <label class="font-weight-bold">Konseling Sumber <span class="text-danger">*</span></label>
                            <select name="id_konseling" id="inp_konseling" class="form-control">
                                <option value="">-- Pilih konseling sumber --</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Tanggal Rencana</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Penanggung Jawab <span class="text-danger">*</span></label>
                            <input type="text" name="penanggung_jawab" id="inp_pj" class="form-control" required placeholder="Wali Kelas / Guru BK / Orang Tua">
                            <small class="text-muted" id="pj_default_hint"></small>
                        </div>
                        <div class="col-12 form-group">
                            <label class="font-weight-bold">Tindakan / Langkah Perbaikan <span class="text-danger">*</span></label>
                            <select id="sel_tl_tindakan" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih template tindakan / langkah perbaikan --</option>
                            </select>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai sumber, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Target Selesai</label>
                            <input type="date" name="target_selesai" id="inp_target" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Tanggal Realisasi Selesai</label>
                            <input type="date" name="tanggal_selesai" id="inp_selesai" class="form-control">
                            <small class="text-muted">Isi tanggal realisasi bila sudah selesai. Status otomatis <strong>Selesai</strong> saat tanggal terlewati (kecuali <strong>Dibatalkan</strong>).</small>
                        </div>
                        <div class="col-md-6 form-group">
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
                <div class="d-flex align-items-center mb-3 p-2 rounded" style="background:#f0f7ff; border:1px solid #cfe3ff;">
                    <div class="mr-3 text-center" style="min-width:70px;">
                        <div class="font-weight-bold" id="det_tanggal" style="font-size:14px;"></div>
                        <small class="text-muted"><i class="fas fa-calendar-alt mr-1"></i>Rencana</small>
                    </div>
                    <div>
                        <div class="font-weight-bold" id="det_nama" style="font-size:15px;"></div>
                        <div><small class="text-muted">NISN: <span id="det_nisn"></span> &bull; Kelas: <span id="det_kelas"></span></small></div>
                        <div class="mt-1"><span id="det_sumber"></span> <span id="det_status" class="ml-1"></span></div>
                    </div>
                </div>
                <div class="card mb-2 border-info">
                    <div class="card-header py-1 px-2 bg-info text-white" style="font-size:12px;"><i class="fas fa-link mr-1"></i><span id="lbl_det_sumber">Rincian Sumber</span></div>
                    <div class="card-body p-2" id="det_bina" style="font-size:13.5px;"></div>
                </div>
                <div class="card mb-2">
                    <div class="card-body p-2" style="font-size:13px;">
                        <div class="row">
                            <div class="col-6 mb-1"><i class="fas fa-user-tie mr-1 text-muted"></i><strong>PJ:</strong> <span id="det_pj"></span></div>
                            <div class="col-6 mb-1"><i class="fas fa-bullseye mr-1 text-muted"></i><strong>Target:</strong> <span id="det_target"></span></div>
                            <div class="col-6"><i class="fas fa-check-double mr-1 text-muted"></i><strong>Realisasi:</strong> <span id="det_selesai"></span></div>
                        </div>
                    </div>
                </div>
                <div class="card mb-0 border-success">
                    <div class="card-header py-1 px-2 bg-success text-white" style="font-size:12px;"><i class="fas fa-tasks mr-1"></i>Tindakan / Langkah Perbaikan</div>
                    <div class="card-body p-2" id="det_tindakan" style="white-space: pre-wrap; font-size:13.5px;"></div>
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


