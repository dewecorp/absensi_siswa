<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

// Deteksi kelas wali
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

// Admin bisa pilih kelas, wali default ke kelasnya
$selected_kelas_id = $wali_kelas_id;
if ($user_level === 'admin' && isset($_GET['kelas'])) {
    $selected_kelas_id = (int)$_GET['kelas'];
}

$message = null;

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $id_siswa = (int)($_POST['id_siswa'] ?? 0);
        $id_pelanggaran = (int)($_POST['id_pelanggaran'] ?? 0);
        $id_kelas = (int)($_POST['id_kelas'] ?? $selected_kelas_id);
        $tanggal = !empty($_POST['tanggal']) ? date('Y-m-d', strtotime($_POST['tanggal'])) : date('Y-m-d');
        $jenis = in_array($_POST['jenis_pembinaan'] ?? '', ['Akademik', 'Kedisiplinan', 'Sikap', 'Kehadiran', 'Sosial', 'Lainnya'], true) ? $_POST['jenis_pembinaan'] : 'Akademik';
        $permasalahan = trim((string)($_POST['permasalahan'] ?? ''));
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $tindak_lanjut = trim((string)($_POST['tindak_lanjut'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Berjalan', 'Selesai', 'Dalam Pemantauan'], true) ? $_POST['status'] : 'Berjalan';

        if ($id_siswa <= 0 || $permasalahan === '' || $tindakan === '') {
            $message = ['type' => 'warning', 'text' => 'Pilih Siswa, isi Permasalahan, dan Tindakan yang diambil.'];
        } else {
            // HIBRID: sumber pelanggaran OPSIONAL. Mandiri = tanpa pelanggaran (kesulitan belajar/fokus/disiplin/adab).
            $boleh = true;
            // Bila sumber tidak dipilih: jenis manual dari form (tidak dikunci).
            if ($boleh) try {
                // Ambil data pelanggaran sumber bila dipilih (untuk validasi + auto-isi)
                $srcLanggar = null;
                if ($id_pelanggaran > 0) {
                    $stSrc = $pdo->prepare("SELECT * FROM tb_pelanggaran_siswa WHERE id = ?" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : ""));
                    $stSrc->execute([$id_pelanggaran]);
                    $srcLanggar = $stSrc->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($srcLanggar && (int)$srcLanggar['id_siswa'] !== $id_siswa) {
                        $message = ['type' => 'warning', 'text' => 'Pelanggaran sumber milik siswa lain. Pilih ulang pelanggaran.'];
                        $boleh = false;
                    }
                }
                // KUNCI mapping: jenis pembinaan WAJIB ikut jenis_binaan pelanggaran sumber.
                // Cegah bocor seperti 'tidak memakai atribut seragam' jadi Akademik.
                if ($boleh && $srcLanggar) {
                    $exp = trim((string)($srcLanggar['jenis_binaan'] ?? ''));
                    if ($exp === '' && function_exists('pelanggaran_deteksi_jenis')) {
                        $det = pelanggaran_deteksi_jenis((string)($srcLanggar['jenis_pelanggaran'] ?? ''), (string)($srcLanggar['kategori'] ?? ''));
                        $exp = $det['jenis'];
                        try {
                            $pdo->prepare("UPDATE tb_pelanggaran_siswa SET jenis_binaan = ? WHERE id = ?")->execute([$exp, (int)$srcLanggar['id']]);
                        } catch (Throwable $e) {}
                    }
                    if ($exp !== '' && $jenis !== $exp) {
                        $jenis = $exp; // override otomatis, bukan tolak
                    }
                }
                // Anti-ganda: 1 pelanggaran hanya boleh dibina 1x (kecuali edit data itu sendiri).
                if ($boleh && $id_pelanggaran > 0) {
                    try {
                        $sqlDup = "SELECT id FROM tb_pembinaan_siswa WHERE id_pelanggaran = ?" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : "");
                        if ($action === 'edit' && $id > 0) {
                            $sqlDup .= " AND id <> " . (int)$id;
                        }
                        $stDup = $pdo->prepare($sqlDup);
                        $stDup->execute([$id_pelanggaran]);
                        if ($stDup->fetchColumn()) {
                            $message = ['type' => 'warning', 'text' => 'Pelanggaran ini SUDAH dibina. Pilih pelanggaran lain yang berstatus Belum dibina agar tidak ganda/tumpang tindih.'];
                            $boleh = false;
                        }
                    } catch (Throwable $e) {}
                }
                if (!$boleh) {
                    // batal simpan, pesan sudah diset
                } elseif ($action === 'tambah') {
                    $stmt = $pdo->prepare("
                        INSERT INTO tb_pembinaan_siswa (
                            id_wali, id_siswa, id_kelas, tanggal, jenis_pembinaan,
                            permasalahan, tindakan, tindak_lanjut, status, id_pelanggaran
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $guru_id, $id_siswa, $id_kelas, $tanggal, $jenis,
                        $permasalahan, $tindakan, $tindak_lanjut, $status, ($id_pelanggaran > 0 ? $id_pelanggaran : null)
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data pembinaan siswa berhasil dicatat.'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE tb_pembinaan_siswa SET
                            id_siswa = ?, id_kelas = ?, tanggal = ?, jenis_pembinaan = ?,
                            permasalahan = ?, tindakan = ?, tindak_lanjut = ?, status = ?, id_pelanggaran = ?
                        WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : "") . "
                    ");
                    $stmt->execute([
                        $id_siswa, $id_kelas, $tanggal, $jenis,
                        $permasalahan, $tindakan, $tindak_lanjut, $status, ($id_pelanggaran > 0 ? $id_pelanggaran : null), $id
                    ]);
                    $message = ['type' => 'success', 'text' => 'Data pembinaan berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM tb_pembinaan_siswa WHERE id = ? " . ($user_level !== 'admin' ? "AND id_wali = $guru_id" : ""))->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Data pembinaan berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// HIBRID: dropdown HANYA tampilkan siswa yang BELUM ditindaklanjuti (n_bina == 0 ATAU n_bina > n_tl).
// Siswa yang semua pembinaannya sudah ditindaklanjuti (n_bina > 0 && n_bina <= n_tl) otomatis disembunyikan agar tidak tumpang tindih.
$siswa_list = [];
try {
    $sqlS = "
        SELECT s.id_siswa, s.nama_siswa, s.nisn,
               COALESCE(pl.total_poin, 0) AS total_poin, COALESCE(pl.jml, 0) AS jml,
               COALESCE(bn.n_bina, 0) AS n_bina,
               COALESCE(tl.n_tl, 0) AS n_tl
        FROM tb_siswa s
        LEFT JOIN (
            SELECT id_siswa, COALESCE(SUM(poin),0) AS total_poin, COUNT(*) AS jml
            FROM tb_pelanggaran_siswa WHERE 1=1" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : "") . "
            GROUP BY id_siswa
        ) pl ON pl.id_siswa = s.id_siswa
        LEFT JOIN (
            SELECT id_siswa, COUNT(*) AS n_bina FROM tb_pembinaan_siswa WHERE 1=1" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : "") . "
            GROUP BY id_siswa
        ) bn ON bn.id_siswa = s.id_siswa
        LEFT JOIN (
            SELECT id_siswa, COUNT(DISTINCT id_pembinaan) AS n_tl FROM tb_tindak_lanjut_wali WHERE id_pembinaan IS NOT NULL" . ($user_level !== 'admin' ? " AND id_wali = " . (int)$guru_id : "") . "
            GROUP BY id_siswa
        ) tl ON tl.id_siswa = s.id_siswa
        WHERE 1=1
    ";
    $parS = [];
    if ($selected_kelas_id > 0) {
        $sqlS .= " AND s.id_kelas = ?";
        $parS[] = $selected_kelas_id;
    }
    // Filter out siswa yang sudah seluruh pembinaannya ditindaklanjuti (n_bina > 0 AND n_bina <= n_tl)
    $sqlS .= " HAVING (n_bina = 0 OR n_bina > n_tl OR jml > n_bina) ORDER BY s.nama_siswa ASC";
    $stS = $pdo->prepare($sqlS);
    $stS->execute($parS);
    $siswa_list = $stS->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $siswa_list = [];
}

// Info pelanggaran per siswa untuk konteks modal (total poin + pelanggaran terakhir)
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

// Peta pelanggaran yang SUDAH dibina (anti-ganda/tumpang tindih): id_pelanggaran => id_bina
$bina_used = [];
try {
    $sqlU = "SELECT id_pelanggaran, id FROM tb_pembinaan_siswa WHERE id_pelanggaran IS NOT NULL";
    $parU = [];
    if ($user_level !== 'admin') {
        $sqlU .= " AND id_wali = ?";
        $parU[] = $guru_id;
    }
    $stU = $pdo->prepare($sqlU);
    $stU->execute($parU);
    foreach ($stU->fetchAll(PDO::FETCH_ASSOC) as $ur) {
        $bina_used[(int)$ur['id_pelanggaran']] = (int)$ur['id'];
    }
} catch (Throwable $e) { $bina_used = []; }
// Hitung sudah-dibina per siswa untuk tanda dropdown
$siswa_bina_count = [];
try {
    $sqlB = "SELECT b.id_siswa, COUNT(*) AS n FROM tb_pembinaan_siswa b WHERE 1=1";
    $parB = [];
    if ($selected_kelas_id > 0) {
        $sqlB .= " AND b.id_kelas = ?";
        $parB[] = $selected_kelas_id;
    }
    if ($user_level !== 'admin') {
        $sqlB .= " AND b.id_wali = ?";
        $parB[] = $guru_id;
    }
    $sqlB .= " GROUP BY b.id_siswa";
    $stB = $pdo->prepare($sqlB);
    $stB->execute($parB);
    foreach ($stB->fetchAll(PDO::FETCH_ASSOC) as $br) {
        $siswa_bina_count[(int)$br['id_siswa']] = (int)$br['n'];
    }
} catch (Throwable $e) { $siswa_bina_count = []; }

// Daftar pelanggaran per siswa untuk dropdown sumber (Alur 1 -> 2)
// jenis_binaan ikut terkirim agar modal pembinaan auto-kunci jenisnya.
$pelanggaran_list = [];
try {
    $sqlL = "SELECT p.id, p.id_siswa, p.tanggal, p.jenis_pelanggaran, p.kategori, p.poin, p.jenis_binaan FROM tb_pelanggaran_siswa p WHERE 1=1";
    $parL = [];
    if ($selected_kelas_id > 0) {
        $sqlL .= " AND p.id_kelas = ?";
        $parL[] = $selected_kelas_id;
    }
    if ($user_level !== 'admin') {
        $sqlL .= " AND p.id_wali = ?";
        $parL[] = $guru_id;
    }
    $sqlL .= " ORDER BY p.tanggal DESC, p.id DESC";
    $stL = $pdo->prepare($sqlL);
    $stL->execute($parL);
    $pelanggaran_list = $stL->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $pelanggaran_list = []; }

// Fetch rows pembinaan
$where = ["1=1"];
$params = [];
if ($selected_kelas_id > 0) {
    $where[] = "p.id_kelas = ?";
    $params[] = $selected_kelas_id;
}
if ($user_level !== 'admin') {
    $where[] = "p.id_wali = ?";
    $params[] = $guru_id;
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT p.*, s.nama_siswa, s.nisn, k.nama_kelas,
           lg.tanggal AS lg_tanggal, lg.jenis_pelanggaran AS lg_jenis, lg.kategori AS lg_kategori, lg.poin AS lg_poin
    FROM tb_pembinaan_siswa p
    JOIN tb_siswa s ON s.id_siswa = p.id_siswa
    LEFT JOIN tb_kelas k ON k.id_kelas = p.id_kelas
    LEFT JOIN tb_pelanggaran_siswa lg ON lg.id = p.id_pelanggaran
    WHERE $where_sql
    ORDER BY p.tanggal DESC, p.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$jenis_options = ['Akademik', 'Kedisiplinan', 'Sikap', 'Kehadiran', 'Sosial', 'Lainnya'];
$status_options = ['Berjalan', 'Dalam Pemantauan', 'Selesai'];

// Master template pembinaan (sinkron dengan menu Data Pembinaan)
$stMasterBina = $pdo->prepare("
    SELECT id, jenis, permasalahan, tindakan, tindak_lanjut
    FROM tb_master_pembinaan
    WHERE id_guru = ? OR id_guru IS NULL OR id_guru = 0
    ORDER BY jenis ASC, id ASC
");
$stMasterBina->execute([$guru_id]);
$master_pembinaan = $stMasterBina->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Daftar Pembinaan Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
];

$js_page = [<<<'JS'
var masterPembinaan =
JS
. json_encode($master_pembinaan) . ";\n" . <<<'JS'
var pelanggaranList =
JS
. json_encode($pelanggaran_list) . ";\n" . <<<'JS'
var pelanggarInfoBina =
JS
. json_encode($pelanggar_info) . ";\n" . <<<'JS'
var binaUsedMap =
JS
. json_encode($bina_used) . ";\n" . <<<'JS'
var siswaBinaCount =
JS
. json_encode($siswa_bina_count) . ";\n" . <<<'JS'
$(document).ready(function() {
    // Dropdown siswa berwarna per status + grup + cari cepat (Select2).
    function siswaFmt(opt) {
        if (!opt.id) return opt.text;
        var sts = '';
        try { sts = $(opt.element).data('sts') || ''; } catch (e) {}
        var txt = String(opt.text || '');
        var parts = txt.split(' — ');
        var nama = parts.shift();
        var tag = parts.join(' — ');
        var $w = $('<span>').addClass('st-' + sts);
        $w.append($('<span>').addClass('siswa-dot'));
        $w.append($('<span>').text(nama));
        if (tag) $w.append($('<span>').addClass('siswa-tag').text(tag));
        return $w;
    }
    function initSiswaSelect() {
        var $s = $('#inp_siswa');
        if (!$s.length || !$.fn.select2) return;
        if ($s.hasClass('select2-hidden-accessible')) $s.select2('destroy');
        $s.select2({
            dropdownParent: $('#modalPembinaan'),
            width: '100%',
            placeholder: '-- Pilih Siswa --',
            allowClear: true,
            templateResult: siswaFmt,
            templateSelection: siswaFmt
        });
    }
    initSiswaSelect();
    $('#modalPembinaan').on('shown.bs.modal', initSiswaSelect);
    if ($('#table-pembinaan').length) {
        $('#table-pembinaan').DataTable({
            'order': [[1, 'desc']],
            'columnDefs': [{ 'sortable': false, 'targets': [10] }],
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada data pembinaan',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 sampai 0 dari 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function shortBina(s, n) {
        s = (s || '').toString().replace(/\s+/g, ' ').trim();
        n = n || 100;
        return s.length > n ? s.substr(0, n) + '...' : s;
    }

    function autogrowBina($ta) {
        if (!$ta || !$ta.length) return;
        $ta.css('height', 'auto');
        $ta.css('height', ($ta[0].scrollHeight + 4) + 'px');
    }
    $(document).on('input', '#modalPembinaan textarea', function() { autogrowBina($(this)); });
    $('#modalPembinaan').on('shown.bs.modal', function() {
        $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
    });

    // Isi dropdown template sesuai jenis terpilih.
    // Prioritas: template yang teksnya cocok dengan pelanggaran sumber tampil paling atas + badge [Sesuai sumber].
    var currentLanggarItem = null;
    function normBina(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9\u00c0-\u024f\u1e00-\u1eff ]+/gi, ' ').replace(/\s+/g, ' ').trim();
    }
    function scoreCocok(templateText, langgarText) {
        if (!langgarText) return 0;
        var a = normBina(templateText).split(' ').filter(function(w) { return w.length > 3; });
        var b = ' ' + normBina(langgarText) + ' ';
        var hit = 0;
        a.forEach(function(w) { if (b.indexOf(' ' + w + ' ') !== -1) hit++; });
        return hit;
    }
    function populateBina(jenis, langgarText) {
        $('#sel_bina_masalah').empty().append('<option value="">-- Pilih template permasalahan --</option>');
        $('#sel_bina_tindakan').empty().append('<option value="">-- Pilih template tindakan --</option>');
        $('#sel_bina_tl').empty().append('<option value="">-- Pilih template rencana tindak lanjut --</option>');
        if (!jenis) return;
        var list = masterPembinaan.filter(function(m) {
            return (m.jenis || '').toLowerCase() === jenis.toLowerCase();
        });
        // Urutkan: skor cocok dengan pelanggaran sumber tertinggi dulu.
        if (langgarText) {
            list = list.slice().sort(function(x, y) {
                return scoreCocok(y.permasalahan, langgarText) - scoreCocok(x.permasalahan, langgarText);
            });
        }
        list.forEach(function(m) {
            var sc = langgarText ? scoreCocok(m.permasalahan, langgarText) : 0;
            var tag = sc > 0 ? ' [Sesuai sumber]' : '';
            var o1 = $('<option>').val(m.permasalahan).text(shortBina(m.permasalahan, 100) + tag);
            o1.data('item', m);
            if (sc > 0) o1.css('font-weight', 'bold');
            $('#sel_bina_masalah').append(o1);
            var o2 = $('<option>').val(m.tindakan).text(shortBina(m.tindakan, 110));
            o2.data('item', m);
            $('#sel_bina_tindakan').append(o2);
            if (m.tindak_lanjut) {
                var o3 = $('<option>').val(m.tindak_lanjut).text(shortBina(m.tindak_lanjut, 110));
                o3.data('item', m);
                $('#sel_bina_tl').append(o3);
            }
        });
        // Auto-pilih template paling cocok bila skornya kuat (>=2 kata kunci sama).
        if (langgarText && list.length) {
            var best = list[0];
            if (scoreCocok(best.permasalahan, langgarText) >= 2) {
                $('#sel_bina_masalah').val(best.permasalahan);
                $('#inp_masalah').val(best.permasalahan);
                $('#sel_bina_tindakan').val(best.tindakan);
                $('#inp_tindakan').val(best.tindakan);
                if (best.tindak_lanjut) {
                    $('#sel_bina_tl').val(best.tindak_lanjut);
                    $('#inp_tindak_lanjut').val(best.tindak_lanjut);
                }
                autogrowBina($('#inp_masalah'));
                autogrowBina($('#inp_tindakan'));
                autogrowBina($('#inp_tindak_lanjut'));
            }
        }
    }

    $('#inp_jenis').on('change', function() {
        populateBina($(this).val(), currentLanggarItem ? currentLanggarItem.jenis_pelanggaran : '');
    });

    // Alur 2 dari 1: filter pelanggaran per siswa + auto-isi dari pelanggaran sumber
    function pelanggaranBySiswa(idSiswa) {
        return pelanggaranList.filter(function(p) {
            return String(p.id_siswa) === String(idSiswa);
        });
    }
    function showKonteksBina(idSiswa) {
        var box = $('#konteksLanggarBina');
        if (!idSiswa) {
            box.html('<small class="text-muted">Hibrid: pilih sumber pelanggaran bila ada, atau kosongkan untuk mandiri (kesulitan belajar/fokus/disiplin/adab).</small>');
            return;
        }
        var nBina = parseInt((typeof siswaBinaCount !== 'undefined' && siswaBinaCount[idSiswa]) ? siswaBinaCount[idSiswa] : 0, 10);
        var info = (typeof pelanggarInfoBina !== 'undefined') ? pelanggarInfoBina[idSiswa] : null;
        if (!info) {
            box.html('<div class="alert alert-secondary mb-0 py-2"><strong>Mandiri</strong> (tanpa riwayat pelanggaran) &bull; ' + nBina + 'x sudah dibina'
                + '<div class="small">Kosongkan sumber pelanggaran + pilih jenis manual sesuai kondisi siswa.</div></div>');
            return;
        }
        var tot = parseInt(info.total_poin || 0, 10);
        var lvl = tot >= 100 ? 'Dikeluarkan (DO)' : (tot >= 75 ? 'Skorsing' : (tot >= 50 ? 'SP 1' : (tot >= 25 ? 'Dalam Pemantauan' : 'Pembinaan Ringan')));
        var nLanggar = parseInt(info.jml || 0, 10);
        var sisa = Math.max(0, nLanggar - nBina);
        var stTag = sisa === 0
            ? '<span class="badge badge-success">Semua sudah dibina</span>'
            : '<span class="badge badge-warning">' + sisa + ' belum dibina</span>';
        box.html('<div class="alert alert-info mb-0 py-2"><strong>' + tot + ' poin &bull; ' + lvl + '</strong> dari ' + nLanggar + ' pelanggaran &bull; ' + nBina + ' sudah dibina ' + stTag
            + '<div class="small">Terakhir: ' + $('<div>').text(info.jenis_pelanggaran || '-').html() + ' (' + (info.kategori || '-') + ', +' + (info.poin || 0) + ' poin)</div></div>');
    }
    $('#inp_siswa').on('change', function() {
        var sid = $(this).val();
        showKonteksBina(sid);
        fillLanggarDropdown(sid, '');
    });
    // Deteksi jenis binaan di browser (mirror helper PHP, anti-bocor Ringan->Akademik).
    // 'Tidak memakai atribut seragam' WAJIB Kedisiplinan; 'terlambat masuk kelas' juga Kedisiplinan.
    function deteksiJenisBinaan(teks, kategori) {
        var t = ' ' + String(teks || '').toLowerCase().replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim() + ' ';
        function has(keys) {
            for (var i = 0; i < keys.length; i++) {
                var k = ' ' + String(keys[i]).toLowerCase().replace(/\s+/g, ' ').trim() + ' ';
                if (k.trim() !== '' && t.indexOf(k) !== -1) return keys[i];
            }
            return null;
        }
        var m;
        // 1. Kehadiran murni (tanpa 'terlambat': itu disiplin waktu).
        if ((m = has(['alpa', 'bolos', 'membolos', 'tidak masuk', 'absen tanpa', 'tanpa keterangan']))) return { jenis: 'Kehadiran', via: m };
        // 2. Sikap duluan (agar 'berkelahi dengan teman' tidak jadi Sosial).
        if ((m = has(['berkelahi', 'tawuran', 'memukul', 'menendang', 'merokok', 'rokok', 'vape', 'mencuri', 'mengambil milik', 'berbohong', 'bohong', 'dusta', 'melawan guru', 'membentak', 'berkata kasar', 'berkata kotor', 'mengumpat', 'mengejek', 'membully', 'bully', 'mencaci', 'caci', 'fitnah', 'tidak sopan', 'tidak santun', 'kurang sopan', 'kasar', 'sholat', 'shalat', 'ibadah', 'mengaji', 'puasa', 'jujur', 'sopan', 'santun', 'adab', 'akhlak']))) return { jenis: 'Sikap', via: m };
        // 3. Sosial.
        if ((m = has(['berselisih', 'bertengkar', 'cekcok', 'menyendiri', 'mengucilkan', 'dikucilkan', 'kerjasama', 'kerja sama', 'gotong royong', 'bergaul']))) return { jenis: 'Sosial', via: m };
        // 4. Akademik (tanpa kata umum 'kelas'/'belajar').
        if ((m = has(['menyontek', 'nyontek', 'contekan', 'tidak mengerjakan tugas', 'tidak mengerjakan pr', 'ulangan', 'asesmen', 'ujian', 'nilai harian', 'tugas']))) return { jenis: 'Akademik', via: m };
        // 5. Kedisiplinan (termasuk 'terlambat masuk kelas' + 'tidak memakai atribut seragam').
        if ((m = has(['terlambat', 'telat', 'seragam', 'atribut', 'pakaian', 'berseragam', 'sepatu', 'rambut', 'kuku', 'gondrong', 'tata tertib', 'disiplin', 'apel', 'upacara', 'baris', 'piket', 'sampah', 'kebersihan', 'lupa membawa', 'tidak membawa', 'gawai', 'handphone', 'gadget', 'main hp', 'bermain gawai', 'keluar kelas', 'tanpa izin', 'gaduh', 'ribut', 'berisik', 'mengganggu', 'buku', 'alat tulis', 'izin']))) return { jenis: 'Kedisiplinan', via: m };
        var kat = String(kategori || '').toLowerCase();
        if (kat === 'berat') return { jenis: 'Sikap', via: 'fallback-berat' };
        return { jenis: 'Kedisiplinan', via: 'fallback' };
    }
    function kunciJenisBinaan(jenis, viaText) {
        $('#inp_jenis').val(jenis);
        $('#inp_jenis_hidden').val(jenis);
        populateBina(jenis, currentLanggarItem ? currentLanggarItem.jenis_pelanggaran : '');
        var note = $('#jenisAutoNote');
        if (note.length) {
            note.html('Terkunci otomatis dari pelanggaran: <strong>' + jenis + '</strong>' + (viaText ? ' <span class="text-muted">(' + viaText + ')</span>' : ''));
        }
    }
    // HIBRID: kunci jenis saat ada sumber; bebaskan manual saat mandiri (tanpa sumber).
    function setJenisMode(locked) {
        $('#inp_jenis').prop('disabled', !!locked);
        var note = $('#jenisAutoNote');
        if (locked) return; // teks note diisi kunciJenisBinaan
        if (note.length) note.html('Mandiri (tanpa sumber): pilih jenis manual sesuai kondisi siswa.');
    }
    function fillLanggarDropdown(idSiswa, selectedId) {
        $('#inp_pelanggaran').empty().append('<option value="">-- Mandiri (tanpa pelanggaran) --</option>');
        // Belum dibina dulu, sudah dibina di bawah + disabled (kecuali yang sedang diedit).
        var list = pelanggaranBySiswa(idSiswa).slice().sort(function(a, b) {
            var au = (typeof binaUsedMap !== 'undefined' && binaUsedMap[a.id]) ? 1 : 0;
            var bu = (typeof binaUsedMap !== 'undefined' && binaUsedMap[b.id]) ? 1 : 0;
            return au - bu;
        });
        list.forEach(function(p) {
            var used = (typeof binaUsedMap !== 'undefined' && binaUsedMap[p.id] && String(binaUsedMap[p.id]) !== String(selectedId));
            var jb = p.jenis_binaan || deteksiJenisBinaan(p.jenis_pelanggaran, p.kategori).jenis;
            var tag = used ? ' [SUDAH DIBINA]' : ' [Belum dibina]';
            var lbl = p.tanggal + ' - ' + p.jenis_pelanggaran + ' (' + p.kategori + ', +' + p.poin + ' poin, ' + jb + ')' + tag;
            var o = $('<option>').val(p.id).text(lbl.length > 115 ? lbl.substr(0, 115) + '...' : lbl);
            o.data('item', p);
            if (used) o.prop('disabled', true);
            if (selectedId && String(selectedId) === String(p.id)) o.prop('selected', true);
            $('#inp_pelanggaran').append(o);
        });
        // Warning bila semua sudah dibina.
        var sisa = list.filter(function(p) { return !(typeof binaUsedMap !== 'undefined' && binaUsedMap[p.id] && String(binaUsedMap[p.id]) !== String(selectedId)); });
        if (idSiswa && list.length && sisa.length === 0 && !selectedId) {
            $('#inp_pelanggaran').after('<div class="small text-success mt-1" id="semuaBinaNote">Semua pelanggaran siswa ini sudah dibina — tidak ada yang bisa dipilih ganda.</div>');
            setTimeout(function() { $('#semuaBinaNote').remove(); }, 4000);
        } else {
            $('#semuaBinaNote').remove();
        }
    }
    // Pilih pelanggaran sumber -> auto-isi permasalahan + KUNCI jenis binaan.
    // Kosongkan (=Mandiri): jenis dibebaskan manual + template tampil semua.
    $('#inp_pelanggaran').on('change', function() {
        var item = $('#inp_pelanggaran option:selected').data('item');
        currentLanggarItem = item || null;
        if (!item) {
            setJenisMode(false);
            $('#inp_jenis_hidden').val($('#inp_jenis').val());
            return;
        }
        setJenisMode(true);
        if (!$('#inp_masalah').val()) {
            $('#inp_masalah').val(item.jenis_pelanggaran + ' (' + item.kategori + ', +' + item.poin + ' poin, ' + item.tanggal + ')');
            autogrowBina($('#inp_masalah'));
        }
        // Prioritas: jenis_binaan tersimpan DB; fallback deteksi keyword agar tidak bocor.
        var det = deteksiJenisBinaan(item.jenis_pelanggaran, item.kategori);
        var jb = item.jenis_binaan || det.jenis;
        kunciJenisBinaan(jb, (item.jenis_binaan ? 'tersimpan' : 'deteksi: ' + det.via));
    });
    // Mandiri: jenis dipilih manual -> template ikut + hidden sinkron.
    $('#inp_jenis').on('change', function() {
        var v = $(this).val() || '';
        $('#inp_jenis_hidden').val(v);
        if (!currentLanggarItem) {
            populateBina(v, '');
            var note = $('#jenisAutoNote');
            if (note.length && v) note.html('Mandiri: jenis <strong>' + v + '</strong> dipilih manual.');
        } else {
            populateBina(v, currentLanggarItem.jenis_pelanggaran);
        }
    });

    // Guru tinggal pilih sesuai kondisi siswa -> isi textarea masing-masing (bisa diubah manual)
    $('#sel_bina_masalah').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_masalah').val(v); autogrowBina($('#inp_masalah')); }
    });
    $('#sel_bina_tindakan').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_tindakan').val(v); autogrowBina($('#inp_tindakan')); }
    });
    $('#sel_bina_tl').on('change', function() {
        var v = $(this).val() || '';
        if (v) { $('#inp_tindak_lanjut').val(v); autogrowBina($('#inp_tindak_lanjut')); }
    });
    $('#btnTambahPembinaan').on('click', function() {
        $('#formPembinaanAction').val('tambah');
        $('#pembinaanId').val('');
        $('#modalPembinaanTitle').text('Catat Pembinaan Siswa Baru');
        $('#formPembinaan')[0].reset();
        $('#inp_siswa').val('').trigger('change.select2');
        currentLanggarItem = null;
        showKonteksBina('');
        fillLanggarDropdown('', '');
        // Jenis dikunci: kosongkan sampai pelanggaran sumber dipilih (cegah default Akademik bocor).
        $('#inp_jenis').val('');
        $('#inp_jenis_hidden').val('');
        populateBina('');
        $('#sel_bina_masalah').empty().append('<option value="">-- Pilih pelanggaran sumber dulu --</option>');
        $('#sel_bina_tindakan').empty().append('<option value="">-- Pilih pelanggaran sumber dulu --</option>');
        $('#sel_bina_tl').empty().append('<option value="">-- Pilih pelanggaran sumber dulu --</option>');
        var note = $('#jenisAutoNote');
        if (note.length) note.html('Pilih pelanggaran sumber dulu &mdash; jenis terkunci otomatis.');
        $('#modalPembinaan').modal('show');
        setTimeout(function() {
            $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-edit-pembinaan', function() {
        var data = $(this).data('json');
        $('#formPembinaanAction').val('edit');
        $('#pembinaanId').val(data.id);
        $('#modalPembinaanTitle').text('Edit Pembinaan Siswa');
        if ($('#inp_siswa option[value="' + data.id_siswa + '"]').length === 0) {
            var optEdit = new Option(data.nama_siswa + (data.nisn ? ' (' + data.nisn + ')' : ''), data.id_siswa, true, true);
            $('#inp_siswa').append(optEdit);
        }
        $('#inp_siswa').val(data.id_siswa).trigger('change.select2');
        showKonteksBina(data.id_siswa);
        fillLanggarDropdown(data.id_siswa, data.id_pelanggaran);
        $('#inp_tanggal').val(data.tanggal);
        // Kunci ulang dari sumber (bukan dari nilai lama yang bisa bocor).
        var jbEdit = data.jenis_pembinaan || '';
        var selItem = null;
        try {
            pelanggaranBySiswa(data.id_siswa).forEach(function(p) {
                if (String(p.id) === String(data.id_pelanggaran)) selItem = p;
            });
            if (selItem) {
                var d = deteksiJenisBinaan(selItem.jenis_pelanggaran, selItem.kategori);
                jbEdit = selItem.jenis_binaan || d.jenis;
            }
        } catch (e) {}
        currentLanggarItem = selItem;
        $('#inp_jenis').val(jbEdit);
        $('#inp_jenis_hidden').val(jbEdit);
        populateBina(jbEdit, selItem ? selItem.jenis_pelanggaran : '');
        // Samakan dropdown dengan nilai tersimpan bila cocok persis
        $('#sel_bina_masalah option').each(function() {
            if ($(this).val() === (data.permasalahan || '')) $(this).prop('selected', true);
        });
        $('#sel_bina_tindakan option').each(function() {
            if ($(this).val() === (data.tindakan || '')) $(this).prop('selected', true);
        });
        $('#sel_bina_tl option').each(function() {
            if ($(this).val() === (data.tindak_lanjut || '')) $(this).prop('selected', true);
        });
        $('#inp_masalah').val(data.permasalahan);
        $('#inp_tindakan').val(data.tindakan);
        $('#inp_tindak_lanjut').val(data.tindak_lanjut || '');
        $('#inp_status').val(data.status);
        $('#modalPembinaan').modal('show');
        setTimeout(function() {
            $('#modalPembinaan textarea').each(function() { autogrowBina($(this)); });
        }, 120);
    });

    $(document).on('click', '.btn-detail-pembinaan', function() {
        var data = $(this).data('json');
        $('#det_tanggal').text(data.tanggal);
        $('#det_nama').text(data.nama_siswa);
        $('#det_nisn').text(data.nisn || '-');
        $('#det_kelas').text(data.nama_kelas || '-');
        $('#det_jenis').html('<span class="badge badge-info">' + data.jenis_pembinaan + '</span>');
        $('#det_status').text(data.status);
        var srcTxt = '-';
        if (data.lg_jenis) {
            srcTxt = data.lg_jenis + ' (' + (data.lg_kategori || '-') + ', +' + (data.lg_poin || 0) + ' poin, ' + (data.lg_tanggal || '-') + ')';
        } else if (data.id_pelanggaran) {
            srcTxt = 'Pelanggaran #' + data.id_pelanggaran;
        }
        $('#det_sumber').text(srcTxt);
        $('#det_masalah').text(data.permasalahan);
        $('#det_tindakan').text(data.tindakan);
        $('#det_tindak_lanjut').text(data.tindak_lanjut || '-');
        $('#modalDetailPembinaan').modal('show');
    });

    $(document).on('click', '.btn-hapus-pembinaan', function() {
        var id = $(this).data('id');
        var nama = $(this).data('nama');
        Swal.fire({
            title: 'Hapus Catatan?',
            text: 'Data pembinaan untuk ' + nama + ' akan dihapus.',
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
#table-pembinaan td:last-child { white-space: nowrap; }
/* Status siswa di dropdown pembinaan: dot warna + label jelas */
.siswa-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 7px; vertical-align: baseline; }
.st-belum .siswa-dot, .siswa-dot.dot-belum { background: #dc3545; box-shadow: 0 0 0 3px rgba(220,53,69,.18); }
.st-kurang .siswa-dot, .siswa-dot.dot-kurang { background: #fd7e14; box-shadow: 0 0 0 3px rgba(253,126,20,.18); }
.st-sudah .siswa-dot, .siswa-dot.dot-sudah { background: #28a745; }
.st-kosong .siswa-dot, .siswa-dot.dot-kosong { background: #adb5bd; }
.st-mandiri .siswa-dot, .siswa-dot.dot-mandiri { background: #17a2b8; }
.siswa-tag { display: inline-block; font-size: 11px; font-weight: 700; border-radius: 10px; padding: 1px 8px; margin-left: 6px; color: #fff; }
.st-belum .siswa-tag { background: #dc3545; }
.st-kurang .siswa-tag { background: #fd7e14; }
.st-sudah .siswa-tag { background: #28a745; }
.st-kosong .siswa-tag { background: #6c757d; }
.st-mandiri .siswa-tag { background: #17a2b8; }
.select2-container--default .select2-results__option[aria-disabled="true"] { color: #aaa; }
#modalPembinaan .select2-container { width: 100% !important; }
</style>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Daftar Pembinaan Siswa <?= !empty($wali_kelas_name) ? '- Kelas ' . htmlspecialchars($wali_kelas_name) : '' ?></h1>
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

            <div class="alert alert-light border small text-muted mb-3">
                <i class="fas fa-stream mr-1 text-primary"></i> <strong>Hibrid:</strong> Pembinaan bisa dari
                <strong>1. Pelanggaran Siswa</strong> (pilih sumbernya, jenis terkunci otomatis) ATAU <strong>mandiri</strong>
                (kosongkan sumber: kesulitan belajar, sulit fokus, kurang disiplin, adab) + pilih jenis manual.
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Catatan Pembinaan Wali Kelas</h4>
                    <div>
                        <a href="data_pembinaan.php" class="btn btn-info btn-sm mr-1">
                            <i class="fas fa-database mr-1"></i> Data Pembinaan
                        </a>
                        <?php
                        $qs_bina = [];
                        if ($selected_kelas_id > 0) { $qs_bina['kelas'] = $selected_kelas_id; }
                        $url_bina_cetak = 'export_pembinaan_pdf.php?' . http_build_query(array_merge($qs_bina, ['mode' => 'print']));
                        $url_bina_xls = 'export_pembinaan_excel.php?' . http_build_query($qs_bina);
                        ?>
                        <a href="<?= htmlspecialchars($url_bina_cetak) ?>" target="_blank" class="btn btn-danger btn-sm mr-1" title="Cetak / Simpan PDF">
                            <i class="fas fa-print mr-1"></i> Cetak / PDF
                        </a>
                        <a href="<?= htmlspecialchars($url_bina_xls) ?>" class="btn btn-success btn-sm mr-2" title="Ekspor Excel">
                            <i class="fas fa-file-excel mr-1"></i> Excel
                        </a>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahPembinaan" <?= empty($siswa_list) && $user_level !== 'admin' ? 'disabled' : '' ?>>
                            <i class="fas fa-plus mr-1"></i> Catat Pembinaan
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered table-sm" id="table-pembinaan">
                            <thead>
                                <tr>
                                    <th width="4%">No</th>
                                    <th>Tanggal</th>
                                    <th>Nama Siswa</th>
                                    <th>NIS/NISN</th>
                                    <th>Kelas</th>
                                    <th>Jenis Pembinaan</th>
                                    <th>Permasalahan</th>
                                    <th>Tindakan</th>
                                    <th>Tindak Lanjut</th>
                                    <th>Status</th>
                                    <th style="width:180px;min-width:180px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php
                                    $st_badge = $r['status'] === 'Selesai' ? 'success' : ($r['status'] === 'Dalam Pemantauan' ? 'warning' : 'primary');
                                    $masalah_cut = mb_strlen($r['permasalahan']) > 35 ? mb_substr($r['permasalahan'], 0, 35) . '...' : $r['permasalahan'];
                                    $tindakan_cut = mb_strlen($r['tindakan']) > 35 ? mb_substr($r['tindakan'], 0, 35) . '...' : $r['tindakan'];
                                    $tl_cut = mb_strlen($r['tindak_lanjut'] ?? '') > 35 ? mb_substr($r['tindak_lanjut'], 0, 35) . '...' : ($r['tindak_lanjut'] ?: '-');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $i + 1 ?></td>
                                        <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                                        <td><strong><?= htmlspecialchars($r['nama_siswa']) ?></strong>
                                            <?php if (!empty($r['lg_jenis'])): ?>
                                                <div class="mt-1"><small class="badge badge-light border" title="Sumber Alur 1">Dari: <?= htmlspecialchars(mb_strimwidth($r['lg_jenis'], 0, 30, '...')) ?> (<?= htmlspecialchars($r['lg_kategori'] ?? '-') ?>, +<?= (int)($r['lg_poin'] ?? 0) ?>)</small></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['nisn'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($r['nama_kelas'] ?? '-') ?></td>
                                        <td><span class="badge badge-info"><?= htmlspecialchars($r['jenis_pembinaan']) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($r['permasalahan']) ?>"><?= htmlspecialchars($masalah_cut) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($r['tindakan']) ?>"><?= htmlspecialchars($tindakan_cut) ?></span></td>
                                        <td><span title="<?= htmlspecialchars($r['tindak_lanjut'] ?? '') ?>"><?= htmlspecialchars($tl_cut) ?></span></td>
                                        <td class="text-center"><span class="badge badge-<?= $st_badge ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <td class="text-center align-middle">
                                            <div class="aksi-satu-baris">
                                                <button type="button" class="btn btn-info btn-sm btn-detail-pembinaan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-warning btn-sm btn-edit-pembinaan" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm btn-hapus-pembinaan" data-id="<?= (int)$r['id'] ?>" data-nama="<?= htmlspecialchars($r['nama_siswa'], ENT_QUOTES) ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <a href="export_pembinaan_pdf.php?id_siswa=<?= (int)$r['id_siswa'] ?>&mode=print" target="_blank" class="btn btn-danger btn-sm" title="Cetak / Simpan PDF laporan siswa ini">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="export_pembinaan_excel.php?id_siswa=<?= (int)$r['id_siswa'] ?>" class="btn btn-success btn-sm" title="Ekspor Excel siswa ini">
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
<div class="modal fade" id="modalPembinaan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formPembinaan">
                <input type="hidden" name="action" id="formPembinaanAction" value="tambah">
                <input type="hidden" name="id" id="pembinaanId" value="">
                <input type="hidden" name="id_kelas" value="<?= (int)$selected_kelas_id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalPembinaanTitle">Catat Pembinaan Siswa</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Siswa <span class="text-danger">*</span></label>
                            <select name="id_siswa" id="inp_siswa" class="form-control siswa-select" required style="width:100%;">
                                <option value="">-- Pilih Siswa --</option>
                                <?php
                                // Kelompokkan agar status jelas: pelanggar belum dibina -> pelanggar sudah dibina -> belum pernah dibina -> mandiri.
                                $grp = ['langgar_belum' => [], 'langgar_sudah' => [], 'belum' => [], 'mandiri' => []];
                                foreach ($siswa_list as $s) {
                                    $sid = (int)$s['id_siswa'];
                                    $nL = (int)($s['jml'] ?? 0);
                                    $nB = (int)($s['n_bina'] ?? 0);
                                    if ($nL > 0) {
                                        $sisaB = max(0, $nL - $nB);
                                        if ($nB > 0 && $sisaB === 0) { $k = 'langgar_sudah'; $cls = 'st-sudah'; $tagB = 'Pelanggar, sudah dibina | ' . (int)$s['total_poin'] . ' poin'; }
                                        elseif ($nB > 0) { $k = 'langgar_belum'; $cls = 'st-kurang'; $tagB = "Pelanggar, $sisaB belum dibina | " . (int)$s['total_poin'] . ' poin'; }
                                        else { $k = 'langgar_belum'; $cls = 'st-belum'; $tagB = 'Pelanggar, belum dibina | ' . (int)$s['total_poin'] . ' poin'; }
                                    } else {
                                        if ($nB > 0) { $k = 'mandiri'; $cls = 'st-mandiri'; $tagB = "Mandiri, sudah dibina {$nB}x"; }
                                        else { $k = 'belum'; $cls = 'st-kosong'; $tagB = 'Belum pernah dibina'; }
                                    }
                                    $s['tagB'] = $tagB; $s['cls'] = $cls;
                                    $grp[$k][] = $s;
                                }
                                $grpLabel = [
                                    'langgar_belum' => '🔴 Pelanggar — belum dibina',
                                    'langgar_sudah' => '🟢 Pelanggar — sudah dibina',
                                    'belum' => '⚪ Belum pernah dibina',
                                    'mandiri' => '🔵 Mandiri — sudah dibina',
                                ];
                                foreach ($grp as $gk => $gs):
                                    if (empty($gs)) continue;
                                ?>
                                    <optgroup label="<?= $grpLabel[$gk] ?> (<?= count($gs) ?>)">
                                        <?php foreach ($gs as $s): ?>
                                            <option value="<?= (int)$s['id_siswa'] ?>" data-sts="<?= $s['cls'] ?>">
                                                <?= htmlspecialchars($s['nama_siswa']) ?> (<?= htmlspecialchars($s['nisn'] ?? '-') ?>) — <?= $s['tagB'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-2 small">
                                <span class="siswa-dot dot-belum"></span>Pelanggar belum dibina
                                <span class="siswa-dot dot-kurang ml-2"></span>Sebagian belum
                                <span class="siswa-dot dot-sudah ml-2"></span>Sudah dibina
                                <span class="siswa-dot dot-kosong ml-2"></span>Belum pernah
                                <span class="siswa-dot dot-mandiri ml-2"></span>Mandiri
                            </div>
                            <div id="konteksLanggarBina" class="mt-2"><small class="text-muted">Hibrid: bisa dari pelanggaran ATAU mandiri (kesulitan belajar/fokus/disiplin/adab).</small></div>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Pelanggaran Sumber (opsional)</label>
                            <select name="id_pelanggaran" id="inp_pelanggaran" class="form-control">
                                <option value="">-- Mandiri (tanpa pelanggaran) --</option>
                            </select>
                            <small class="text-muted">Opsional: pilih bila pembinaan berasal dari pelanggaran. Kosongkan untuk mandiri.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Tanggal</label>
                            <input type="date" name="tanggal" id="inp_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Jenis Pembinaan</label>
                            <select name="jenis_pembinaan" id="inp_jenis" class="form-control">
                                <?php foreach ($jenis_options as $j): ?>
                                    <option value="<?= $j ?>"><?= $j ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="inp_jenis_hidden" value="">
                            <small class="text-muted" id="jenisAutoNote">Bila ada sumber: terkunci otomatis. Mandiri: pilih manual.</small>
                        </div>
                        <div class="col-12 form-group">
                            <label>Permasalahan / Kasus <span class="text-danger">*</span></label>
                            <select id="sel_bina_masalah" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih permasalahan sesuai kondisi siswa --</option>
                            </select>
                            <textarea name="permasalahan" id="inp_masalah" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-12 form-group">
                            <label>Tindakan Pembinaan <span class="text-danger">*</span></label>
                            <select id="sel_bina_tindakan" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih tindakan sesuai kondisi siswa --</option>
                            </select>
                            <textarea name="tindakan" id="inp_tindakan" class="form-control" rows="5" style="min-height:120px;" required placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>Rencana Tindak Lanjut</label>
                            <select id="sel_bina_tl" class="form-control form-control-sm mb-1">
                                <option value="">-- Pilih rencana tindak lanjut --</option>
                            </select>
                            <textarea name="tindak_lanjut" id="inp_tindak_lanjut" class="form-control" rows="5" style="min-height:120px;" placeholder="Pilih dari dropdown di atas sesuai kondisi siswa, atau ketik manual..."></textarea>
                        </div>
                        <div class="col-md-4 form-group">
                            <label>Status</label>
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
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Simpan Pembinaan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail -->
<div class="modal fade" id="modalDetailPembinaan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle mr-2"></i>Rincian Pembinaan Siswa</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <table class="table table-bordered table-sm">
                    <tr><th width="30%">Tanggal</th><td id="det_tanggal"></td></tr>
                    <tr><th>Nama Siswa</th><td id="det_nama" class="font-weight-bold"></td></tr>
                    <tr><th>NIS/NISN</th><td id="det_nisn"></td></tr>
                    <tr><th>Kelas</th><td id="det_kelas"></td></tr>
                    <tr><th>Jenis Pembinaan</th><td id="det_jenis"></td></tr>
                    <tr><th>Status</th><td id="det_status"></td></tr>
                    <tr><th>Pelanggaran Sumber (Alur 1)</th><td id="det_sumber"></td></tr>
                    <tr><th colspan="2">Permasalahan:</th></tr>
                    <tr><td colspan="2" id="det_masalah" style="white-space: pre-wrap;" class="bg-light p-2"></td></tr>
                    <tr><th colspan="2">Tindakan Pembinaan:</th></tr>
                    <tr><td colspan="2" id="det_tindakan" style="white-space: pre-wrap;" class="bg-light p-2 text-primary"></td></tr>
                    <tr><th colspan="2">Tindak Lanjut:</th></tr>
                    <tr><td colspan="2" id="det_tindak_lanjut" style="white-space: pre-wrap;" class="bg-light p-2 text-success"></td></tr>
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
