<?php
/**
 * Pelaksanaan Supervisi (Administrasi / Akademik / Manajerial).
 * Wrapper menetapkan $sv_jenis sebelum meng-include file ini:
 *   supervisi_administrasi.php, supervisi_akademik.php, supervisi_manajerial.php
 */
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$sv_jenis = $sv_jenis ?? trim((string)($_GET['jenis'] ?? 'Akademik'));
if (!in_array($sv_jenis, sv_jenis_list(), true)) {
    $sv_jenis = 'Akademik';
}
$is_manajerial = ($sv_jenis === 'Manajerial');

$page_title = 'Supervisi ' . $sv_jenis;
$current_page = basename(__FILE__);
$can_manage = sv_is_supervisor($pdo);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$css_libs = ['https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'];
$js_libs = [
    'assets/js/supervisi.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$guru_list = sv_guru_list($pdo);
$program_all_raw = sv_program_options($pdo);
$program_list = array_values(array_filter($program_all_raw, function ($p) use ($sv_jenis) {
    return strcasecmp(trim((string)($p['jenis_supervisi'] ?? '')), $sv_jenis) === 0;
}));
$jadwal_list = [];
try {
    $stmt = $pdo->prepare("SELECT id_jadwal, id_guru, id_program, id_instrumen, nama_guru, jenis_supervisi, tanggal FROM tb_sv_jadwal WHERE jenis_supervisi = ? ORDER BY tanggal DESC LIMIT 200");
    $stmt->execute([$sv_jenis]);
    $jadwal_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}
$jadwal_json = json_encode($jadwal_list, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

$programAll = $pdo->query("SELECT id_program, kode_program, nama_program, jenis_supervisi FROM tb_sv_program WHERE jenis_supervisi = " . $pdo->quote($sv_jenis) . " ORDER BY kode_program ASC")->fetchAll(PDO::FETCH_ASSOC);
$programByInstrumen = [];
foreach ($programAll as $pr) {
    $kode = substr((string)$pr['kode_program'], 0, 3);
    $mapKode = ['AKD' => 'Akademik', 'ADM' => 'Administrasi', 'MJR' => 'Manajerial'];
    $jenisPr = $mapKode[$kode] ?? $pr['jenis_supervisi'];
    $programByInstrumen[$jenisPr][] = $pr;
}
$instrumen_list = sv_instrumen_options($pdo, $sv_jenis);
$mapel_by_guru = sv_mapel_guru($pdo);
$mapel_by_guru_json = json_encode($mapel_by_guru, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

// Bangun data instrumen (komponen + indikator) untuk form penilaian dinamis.
$instrumen_data = [];
foreach ($instrumen_list as $ins) {
    $komponenRows = [];
    try {
        $stmt = $pdo->prepare("SELECT * FROM tb_sv_komponen WHERE id_instrumen = ? ORDER BY urutan ASC, id_komponen ASC");
        $stmt->execute([$ins['id_instrumen']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $k) {
            $indikatorRows = [];
            $st2 = $pdo->prepare("SELECT * FROM tb_sv_indikator WHERE id_komponen = ? ORDER BY urutan ASC, id_indikator ASC");
            $st2->execute([$k['id_komponen']]);
            foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $indikatorRows[] = [
                    'id_indikator' => (int)$i['id_indikator'],
                    'kode_indikator' => $i['kode_indikator'],
                    'indikator' => $i['indikator'],
                    'bobot' => (float)$i['bobot'],
                    'skor_minimal' => (float)$i['skor_minimal'],
                    'skor_maksimal' => (float)$i['skor_maksimal'],
                ];
            }
            $komponenRows[] = [
                'id_komponen' => (int)$k['id_komponen'],
                'kode_komponen' => $k['kode_komponen'],
                'nama_komponen' => $k['nama_komponen'],
                'bobot' => (float)$k['bobot'],
                'indikator' => $indikatorRows,
            ];
        }
    } catch (Throwable $e) {
    }
    $instrumen_data[(string)$ins['id_instrumen']] = [
        'id_instrumen' => (int)$ins['id_instrumen'],
        'nama_instrumen' => $ins['nama_instrumen'],
        'skala_penilaian' => $ins['skala_penilaian'],
        'komponen' => $komponenRows,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $aksi = (string)($_POST['aksi'] ?? '');
    try {
        if ($aksi === 'simpan') {
            $id = (int)($_POST['id_pelaksanaan'] ?? 0);
            $id_instrumen = (int)($_POST['id_instrumen'] ?? 0);
            $id_guru = (int)($_POST['id_guru'] ?? 0);
            $nama_guru = '';
            foreach ($guru_list as $g) {
                if ((int)$g['id_guru'] === $id_guru) {
                    $nama_guru = $g['nama_guru'];
                    break;
                }
            }
            $unit_bagian = trim((string)($_POST['unit_bagian'] ?? ''));
            $penanggung_jawab = trim((string)($_POST['penanggung_jawab'] ?? ''));
            $mapel_di_supervisi = trim((string)($_POST['mapel_di_supervisi'] ?? ''));
            if (!$is_manajerial) {
                $allow = $mapel_by_guru[$id_guru] ?? [];
                if ($allow && $mapel_di_supervisi !== '' && !in_array($mapel_di_supervisi, $allow, true)) {
                    throw new RuntimeException('Mapel yang dipilih bukan mapel akademik guru tersebut.');
                }
            } else {
                $mapel_di_supervisi = '';
            }
            $tanggal = trim((string)($_POST['tanggal'] ?? '')) ?: null;
            $supervisor = trim((string)($_POST['supervisor'] ?? sv_current_user_name($pdo)));
            $status = trim((string)($_POST['status'] ?? 'Draft'));

            $nilai = null;
            $predikat = null;

            if (!$is_manajerial) {
                // Ambil indikator master berdasarkan instrumen terpilih
                $indikatorMaster = [];
                if ($id_instrumen > 0) {
                    $stmt = $pdo->prepare("SELECT n.*, k.id_komponen, k.nama_komponen, k.kode_komponen FROM tb_sv_indikator n
                        JOIN tb_sv_komponen k ON k.id_komponen = n.id_komponen
                        WHERE k.id_instrumen = ? ORDER BY k.urutan ASC, n.urutan ASC");
                    $stmt->execute([$id_instrumen]);
                    $indikatorMaster = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $skalaMax = 4.0;
                foreach ($instrumen_list as $ins) {
                    if ((int)$ins['id_instrumen'] === $id_instrumen) {
                        $skalaMax = sv_skala_max($ins['skala_penilaian']);
                        break;
                    }
                }

                $inputPenilaian = $_POST['penilaian'] ?? [];
                $items = [];
                $rowsToSave = [];
                foreach ($indikatorMaster as $ind) {
                    $iid = (int)$ind['id_indikator'];
                    $skor = isset($inputPenilaian[$iid]['skor']) ? (float)$inputPenilaian[$iid]['skor'] : (float)$ind['skor_minimal'];
                    $catatan = trim((string)($inputPenilaian[$iid]['catatan'] ?? ''));
                    $bobot = (float)$ind['bobot'];
                    $skorMin = (float)$ind['skor_minimal'];
                    $skorMaks = (float)$ind['skor_maksimal'] ?: $skalaMax;
                    $items[] = ['bobot' => $bobot, 'skor' => $skor, 'skor_minimal' => $skorMin, 'skor_maksimal' => $skorMaks];
                    $rowsToSave[] = [
                        'id_komponen' => (int)$ind['id_komponen'],
                        'id_indikator' => $iid,
                        'komponen' => (string)($ind['nama_komponen'] ?? ''),
                        'indikator' => $ind['indikator'],
                        'bobot' => $bobot,
                        'skor' => $skor,
                        'skor_minimal' => $skorMin,
                        'skor_maksimal' => $skorMaks,
                        'catatan' => $catatan,
                    ];
                }
                $hasil = sv_hitung_nilai($items, $skalaMax);
                $nilai = $hasil['nilai'];
                $predikat = $hasil['predikat'];
            } else {
                // Manajerial: nilai dari rata-rata skor detail (0-100)
                $detail = $_POST['manajerial'] ?? [];
                $total = 0.0;
                $count = 0;
                foreach ($detail as $d) {
                    if (trim((string)($d['indikator'] ?? '')) === '' && trim((string)($d['unit_bagian'] ?? '')) === '') {
                        continue;
                    }
                    $skor = (float)($d['skor'] ?? 0);
                    $total += $skor;
                    $count++;
                }
                $nilai = $count > 0 ? round($total / $count, 2) : 0.0;
                $predikat = sv_predikat($nilai);
            }

            $data = [
                (int)($_POST['id_jadwal'] ?? 0) ?: null,
                (int)($_POST['id_program'] ?? 0) ?: null,
                $id_instrumen ?: null,
                $id_guru ?: null,
                $nama_guru,
                $unit_bagian,
                $penanggung_jawab,
                $mapel_di_supervisi,
                $sv_jenis,
                $tanggal,
                $supervisor,
                $nilai,
                $predikat,
                trim((string)($_POST['kekuatan'] ?? '')),
                trim((string)($_POST['kelemahan'] ?? '')),
                trim((string)($_POST['temuan'] ?? '')),
                trim((string)($_POST['rekomendasi'] ?? '')),
                trim((string)($_POST['prioritas_perbaikan'] ?? '')),
                $status,
                trim((string)($_POST['keterangan'] ?? '')),
            ];

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE tb_sv_pelaksanaan SET id_jadwal=?, id_program=?, id_instrumen=?, id_guru=?, nama_guru=?,
                    unit_bagian=?, penanggung_jawab=?, mapel_di_supervisi=?, jenis_supervisi=?, tanggal=?, supervisor=?, nilai=?, predikat=?,
                    kekuatan=?, kelemahan=?, temuan=?, rekomendasi=?, prioritas_perbaikan=?, status=?, keterangan=?
                    WHERE id_pelaksanaan=?");
                $stmt->execute(array_merge($data, [$id]));
            } else {
                $stmt = $pdo->prepare("INSERT INTO tb_sv_pelaksanaan
                    (id_jadwal, id_program, id_instrumen, id_guru, nama_guru, unit_bagian, penanggung_jawab, mapel_di_supervisi, jenis_supervisi,
                     tanggal, supervisor, nilai, predikat, kekuatan, kelemahan, temuan, rekomendasi, prioritas_perbaikan, status, keterangan)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute($data);
                $id = (int)$pdo->lastInsertId();
            }

            // Simpan detail penilaian
            $pdo->prepare("DELETE FROM tb_sv_penilaian WHERE id_pelaksanaan = ?")->execute([$id]);
            if (!$is_manajerial) {
                $ins = $pdo->prepare("INSERT INTO tb_sv_penilaian
                    (id_pelaksanaan, id_komponen, id_indikator, komponen, indikator, bobot, skor, skor_minimal, skor_maksimal, nilai, catatan)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($rowsToSave as $r) {
                    $skorMaksI = (int)round((float)$r['skor_maksimal']);
                    $skorBulat = (int)round((float)$r['skor']);
                    $skorBulat = max(0, min($skorMaksI > 0 ? $skorMaksI : 1, $skorBulat));
                    $rNilai = $skorMaksI > 0 ? round(($skorBulat / $skorMaksI) * 100, 2) : 0;
                    $ins->execute([$id, $r['id_komponen'], $r['id_indikator'], $r['komponen'], $r['indikator'], $r['bobot'], $skorBulat, $r['skor_minimal'], $r['skor_maksimal'], $rNilai, $r['catatan']]);
                }
            } else {
                $pdo->prepare("DELETE FROM tb_sv_manajerial_detail WHERE id_pelaksanaan = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO tb_sv_manajerial_detail
                    (id_pelaksanaan, unit_bagian, penanggung_jawab, program, indikator, target, realisasi, skor, temuan, kendala, rekomendasi, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($detail as $d) {
                    if (trim((string)($d['indikator'] ?? '')) === '' && trim((string)($d['unit_bagian'] ?? '')) === '') {
                        continue;
                    }
                    $ins->execute([
                        $id,
                        trim((string)($d['unit_bagian'] ?? '')),
                        trim((string)($d['penanggung_jawab'] ?? '')),
                        trim((string)($d['program'] ?? '')),
                        trim((string)($d['indikator'] ?? '')),
                        trim((string)($d['target'] ?? '')),
                        trim((string)($d['realisasi'] ?? '')),
                        (float)($d['skor'] ?? 0),
                        trim((string)($d['temuan'] ?? '')),
                        trim((string)($d['kendala'] ?? '')),
                        trim((string)($d['rekomendasi'] ?? '')),
                        trim((string)($d['status'] ?? '')),
                    ]);
                }
            }

            // Sinkronkan status jadwal & sasaran
            if (!empty($_POST['id_jadwal'])) {
                $pdo->prepare("UPDATE tb_sv_jadwal SET status = 'Terlaksana' WHERE id_jadwal = ?")->execute([(int)$_POST['id_jadwal']]);
            }
            if ($status === 'Selesai' && $id_guru > 0) {
                $upd = $pdo->prepare("UPDATE tb_sv_sasaran SET status_supervisi = 'Sudah Disupervisi' WHERE id_guru = ?");
                $upd->execute([$id_guru]);
            }

            sv_log($pdo, 'Simpan Pelaksanaan ' . $sv_jenis, ($nama_guru ?: $unit_bagian) . ' - nilai ' . $nilai);
            sv_flash('success', 'Pelaksanaan supervisi berhasil disimpan.');
        } elseif ($aksi === 'hapus') {
            $id = (int)($_POST['id_pelaksanaan'] ?? 0);
            $pdo->prepare("DELETE FROM tb_sv_penilaian WHERE id_pelaksanaan = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tb_sv_manajerial_detail WHERE id_pelaksanaan = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tb_sv_pelaksanaan WHERE id_pelaksanaan = ?")->execute([$id]);
            sv_log($pdo, 'Hapus Pelaksanaan', 'ID ' . $id);
            sv_flash('success', 'Data pelaksanaan berhasil dihapus.');
        }
    } catch (Throwable $e) {
        sv_flash('danger', 'Gagal memproses data: ' . $e->getMessage());
    }
    redirect(basename(__FILE__) . '?jenis=' . urlencode($sv_jenis));
}

$filter_guru = trim((string)($_GET['guru'] ?? ''));
$filter_status = trim((string)($_GET['status'] ?? ''));
$filter_dari = trim((string)($_GET['tanggal_dari'] ?? ''));
$filter_sampai = trim((string)($_GET['tanggal_sampai'] ?? ''));

$where = ['p.jenis_supervisi = ?'];
$params = [$sv_jenis];
if ($filter_guru !== '') {
    $where[] = 'p.id_guru = ?';
    $params[] = (int)$filter_guru;
}
if ($filter_status !== '') {
    $where[] = 'p.status = ?';
    $params[] = $filter_status;
}
if ($filter_dari !== '') {
    $where[] = 'p.tanggal >= ?';
    $params[] = $filter_dari;
}
if ($filter_sampai !== '') {
    $where[] = 'p.tanggal <= ?';
    $params[] = $filter_sampai;
}

$rows = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, pr.nama_program, i.nama_instrumen
        FROM tb_sv_pelaksanaan p
        LEFT JOIN tb_sv_program pr ON pr.id_program = p.id_program
        LEFT JOIN tb_sv_instrumen i ON i.id_instrumen = p.id_instrumen
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.tanggal DESC, p.id_pelaksanaan DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$js_page = [];
$flash = sv_render_flash_js();
if ($flash !== '') {
    $js_page[] = $flash;
}
$js_page[] = 'var svInstrumenData = ' . json_encode($instrumen_data) . ';';
$js_page[] = 'var svIsManajerial = ' . ($is_manajerial ? 'true' : 'false') . ';';
$js_page[] = 'var svMapelByGuru = ' . $mapel_by_guru_json . ';';
$js_page[] = 'var svJadwalList = ' . $jadwal_json . ';';
$js_page[] = <<<'JS'
var svEditPenilaian = {};
var svPredikat = function (n) {
    if (n >= 91) return 'A - Amat Baik';
    if (n >= 76) return 'B - Baik';
    if (n >= 61) return 'C - Cukup';
    return 'D - Kurang';
};

function svPopulateMapel(guruId, keepValue) {
    var $sel = $('#sv-mapel-select');
    if (!$sel.length || svIsManajerial) return;
    var prev = keepValue ? $sel.val() : '';
    $sel.empty();
    if (!guruId) {
        $sel.append('<option value="">Pilih Guru dulu</option>');
        return;
    }
    var list = svMapelByGuru[String(guruId)] || svMapelByGuru[parseInt(guruId, 10)] || [];
    if (!list.length) {
        $sel.append('<option value="">(Tidak ada mapel akademik untuk guru ini)</option>');
        return;
    }
    $sel.append('<option value="">Pilih Mapel</option>');
    list.forEach(function (nm) {
        $sel.append('<option value="' + $('<div>').text(nm).html() + '">' + $('<div>').text(nm).html() + '</option>');
    });
    if (prev && list.indexOf(prev) !== -1) { $sel.val(prev); }
}

function svApplyJadwal(jadwalId, keepMapel) {
    var row = svJadwalList.find(function (r) { return String(r.id_jadwal) === String(jadwalId); });
    if (!row) return;
    if (!svIsManajerial && row.id_guru) {
        $('#sv-guru-select').val(String(row.id_guru));
        svPopulateMapel(row.id_guru, keepMapel);
        if (keepMapel) {
            var cur = $('#sv-mapel-select').val();
            if (cur) { svPopulateMapel(row.id_guru, true); $('#sv-mapel-select').val(cur); }
        }
    }
    if (row.id_program) { $('#sv-program-select').val(String(row.id_program)); }
    if (row.id_instrumen) {
        $('#sv-instrumen-select').val(String(row.id_instrumen));
        svEditPenilaian = {};
        svRenderPenilaian();
    } else if (row.id_program && programToInstrumen[String(row.id_program)]) {
        $('#sv-instrumen-select').val(programToInstrumen[String(row.id_program)]);
        svEditPenilaian = {};
        svRenderPenilaian();
    }
}

function svRenderPenilaian() {
    var id = $('#form-pelaksanaan [name=id_instrumen]').val();
    var box = $('#sv-penilaian-box');
    box.empty();
    if (!id || !svInstrumenData[id]) {
        box.html('<div class="alert alert-light border mb-0">Pilih instrumen untuk menampilkan indikator penilaian.</div>');
        $('#sv-skor-hint').hide();
        $('#sv-nilai-preview').text('0.00');
        $('#sv-predikat-preview').text('-');
        return;
    }
    var ins = svInstrumenData[id];
    var html = '';
    ins.komponen.forEach(function (k) {
        html += '<div class="card mb-2"><div class="card-header py-2"><strong>' + (k.kode_komponen ? k.kode_komponen + ' - ' : '') + k.nama_komponen + '</strong> <span class="badge badge-light">Bobot ' + k.bobot + '</span></div>';
        html += '<div class="card-body p-2"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Indikator</th><th width="90">Bobot</th><th width="90">Skor</th><th width="200">Catatan</th></tr></thead><tbody>';
        k.indikator.forEach(function (i) {
            var saved = svEditPenilaian[i.id_indikator];
            var val = saved && saved.skor != null ? saved.skor : i.skor_minimal;
            var note = saved && saved.catatan ? $('<div>').text(saved.catatan).html() : '';
            html += '<tr>' +
                '<td>' + (i.kode_indikator ? '<small class="text-muted">' + i.kode_indikator + '</small> ' : '') + $('<div>').text(i.indikator).html() + '</td>' +
                '<td>' + i.bobot + '</td>' +
                '<td><input type="number" step="1" min="' + i.skor_minimal + '" max="' + i.skor_maksimal + '" class="form-control form-control-sm sv-skor" name="penilaian[' + i.id_indikator + '][skor]" value="' + val + '" data-bobot="' + i.bobot + '" data-min="' + i.skor_minimal + '" data-max="' + i.skor_maksimal + '"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="penilaian[' + i.id_indikator + '][catatan]" value="' + note + '"></td>' +
                '</tr>';
        });
        html += '</tbody></table></div></div></div>';
    });
    box.html(html);
    if (id && svInstrumenData[id]) {
        var skala = svInstrumenData[id].skala_penilaian || '1-4';
        $('#sv-skor-rentang').text(skala);
        $('#sv-skor-hint').show();
    } else {
        $('#sv-skor-hint').hide();
    }
    svHitungNilai();
}

function svHitungNilai() {
    var totalBobot = 0, total = 0;
    $('.sv-skor').each(function () {
        var b = parseFloat($(this).data('bobot')) || 0;
        var max = parseInt($(this).data('max'), 10) || 1;
        var skor = Math.round(parseFloat($(this).val()) || 0);
        max = max > 0 ? max : 1;
        skor = Math.max(0, Math.min(max, skor));
        var rasio = skor / max;
        total += b * rasio;
        totalBobot += b;
    });
    var nilai = totalBobot > 0 ? (total / totalBobot * 100) : 0;
    $('#sv-nilai-preview').text(nilai.toFixed(2));
    $('#sv-predikat-preview').text(svPredikat(nilai));
}

$(document).ready(function () {
    SV.autoSubmitFilters('form');
    SV.initDataTable('#table-pelaksanaan');
    var programJenis = {};
    $('#sv-program-select option').each(function () { var v = $(this).val(); if (v) programJenis[v] = $(this).data('jenis'); });
    var instrumenJenis = {};
    $('#sv-instrumen-select option').each(function () { var v = $(this).val(); if (v) instrumenJenis[v] = $(this).data('jenis'); });
    var programToInstrumen = {};
    $('#sv-program-select option').each(function () {
        var pid = $(this).val();
        if (!pid) return;
        var pj = programJenis[pid];
        $('#sv-instrumen-select option').each(function () {
            if ($(this).data('jenis') === pj && !programToInstrumen[pid]) programToInstrumen[pid] = $(this).val();
        });
    });
    $('#sv-program-select').on('change', function () {
        var pid = $(this).val();
        if (pid && programToInstrumen[pid]) {
            $('#sv-instrumen-select').val(programToInstrumen[pid]);
            svEditPenilaian = {};
            svRenderPenilaian();
        }
    });
    $('#sv-guru-select').on('change', function () {
        if (!svIsManajerial) svPopulateMapel($(this).val(), false);
    });
    $('#form-pelaksanaan [name=id_jadwal]').on('change', function () {
        svApplyJadwal($(this).val(), false);
    });
    $('#form-pelaksanaan [name=id_instrumen]').on('change', svRenderPenilaian);
    $(document).on('input', '.sv-skor', svHitungNilai);

    $('#btn-tambah').on('click', function () {
        $('#form-pelaksanaan')[0].reset();
        svPopulateMapel('', false);
        $('#form-pelaksanaan [name=aksi]').val('simpan');
        $('#form-pelaksanaan [name=id_pelaksanaan]').val('');
        $('#sv-penilaian-box').html('<div class="alert alert-light border mb-0">Pilih instrumen untuk menampilkan indikator penilaian.</div>');
        $('#sv-nilai-preview').text('0.00');
        $('#modal-pelaksanaan .modal-title').text('Tambah Pelaksanaan Supervisi');
        $('#modal-pelaksanaan').modal('show');
    });

    $(document).on('click', '.btn-edit', function () {
        var d = $(this).data('row');
        svEditPenilaian = {};
        if (d.id_pelaksanaan) {
            $.ajax({
                url: '../config/supervisi_ajax.php',
                data: { action: 'get_penilaian', id_pelaksanaan: d.id_pelaksanaan },
                dataType: 'json',
                async: false,
                success: function (res) {
                    if (res && res.ok && res.rows) {
                        res.rows.forEach(function (r) {
                            svEditPenilaian[r.id_indikator] = { skor: parseInt(r.skor, 10) || 0, catatan: r.catatan || '' };
                        });
                    }
                }
            });
        }
        $('#form-pelaksanaan')[0].reset();
        $('#form-pelaksanaan [name=aksi]').val('simpan');
        Object.keys(d).forEach(function (k) {
            var el = $('#form-pelaksanaan [name="' + k + '"]');
            if (el.length) { el.val(d[k]); }
        });
        if (!svIsManajerial) {
            svPopulateMapel(d.id_guru || $('#sv-guru-select').val(), false);
            if (d.mapel_di_supervisi) { $('#sv-mapel-select').val(d.mapel_di_supervisi); }
        }
        svRenderPenilaian();
        $('#modal-pelaksanaan .modal-title').text('Edit Pelaksanaan Supervisi');
        $('#modal-pelaksanaan').modal('show');
    });

    $(document).on('click', '.btn-hapus', function () {
        var id = $(this).data('id');
        SV.confirmDelete({ text: 'Data pelaksanaan supervisi akan dihapus.', onConfirm: function () { SV.submitPost(location.href, { aksi: 'hapus', id_pelaksanaan: id }); } });
    });
    $('#btn-excel').on('click', function () { SV.exportExcel('table-pelaksanaan', 'Supervisi <?= $sv_jenis ?>', 'supervisi_<?= strtolower($sv_jenis) ?>', true); });
    $('#btn-pdf').on('click', function () { SV.printPdf('table-pelaksanaan', 'Supervisi <?= $sv_jenis ?>', true); });

    if (svIsManajerial) {
        $('#btn-tambah-detail').on('click', function () {
            var tpl = '<tr>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][unit_bagian]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][penanggung_jawab]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][program]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][indikator]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][target]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][realisasi]"></td>' +
                '<td><input type="number" step="1" class="form-control form-control-sm" name="manajerial[__i__][skor]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][temuan]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][kendala]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][rekomendasi]"></td>' +
                '<td><input type="text" class="form-control form-control-sm" name="manajerial[__i__][status]"></td>' +
                '<td><button type="button" class="btn btn-danger btn-sm btn-remove-detail"><i class="fas fa-times"></i></button></td>' +
                '</tr>';
            var idx = $('#sv-manajerial-body tr').length;
            $('#sv-manajerial-body').append(tpl.replace(/__i__/g, idx));
        });
        $(document).on('click', '.btn-remove-detail', function () { $(this).closest('tr').remove(); });
    }
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Supervisi <?= htmlspecialchars($sv_jenis) ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>
        <div class="section-body">
            <input type="hidden" id="svSchoolName" value="<?= htmlspecialchars($school_profile['nama_madrasah'] ?? 'MADRASAH', ENT_QUOTES) ?>">
            <input type="hidden" id="svSchoolLogo" value="<?= !empty($school_profile['logo']) ? '../assets/img/' . htmlspecialchars($school_profile['logo'], ENT_QUOTES) : '' ?>">
            <input type="hidden" id="svAcademicYear" value="<?= htmlspecialchars($periode['tahun_ajaran'], ENT_QUOTES) ?>">
            <input type="hidden" id="svSemester" value="<?= htmlspecialchars($periode['semester'], ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadName" value="<?= htmlspecialchars($school_profile['nama_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svHeadNip" value="<?= htmlspecialchars($school_profile['nip_kepala'] ?? '-', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintPlace" value="<?= htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES) ?>">
            <input type="hidden" id="svPrintDate" value="<?= date('d F Y') ?>">

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="form-row align-items-end">
                        <input type="hidden" name="jenis" value="<?= htmlspecialchars($sv_jenis, ENT_QUOTES) ?>">
                        <div class="form-group col-md-3 mb-2">
                            <label class="small font-weight-bold">Guru/PTK</label>
                            <select class="form-control" name="guru">
                                <option value="">Semua</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>" <?= (string)$g['id_guru'] === $filter_guru ? 'selected' : '' ?>><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2">
                            <label class="small font-weight-bold">Status</label>
                            <select class="form-control" name="status">
                                <option value="">Semua</option>
                                <option value="Draft" <?= $filter_status === 'Draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="Selesai" <?= $filter_status === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2"><label class="small font-weight-bold">Dari</label><input type="date" class="form-control" name="tanggal_dari" value="<?= htmlspecialchars($filter_dari, ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-2 mb-2"><label class="small font-weight-bold">Sampai</label><input type="date" class="form-control" name="tanggal_sampai" value="<?= htmlspecialchars($filter_sampai, ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            <a href="<?= htmlspecialchars(basename(__FILE__), ENT_QUOTES) ?>?jenis=<?= urlencode($sv_jenis) ?>" class="btn btn-light">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Daftar Pelaksanaan Supervisi <?= htmlspecialchars($sv_jenis) ?></h4>
                    <div class="card-header-action">
                        <button class="btn btn-success" id="btn-excel" type="button"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> PDF</button>
                        <?php if ($can_manage): ?>
                        <button class="btn btn-primary" id="btn-tambah" type="button"><i class="fas fa-plus"></i> Tambah</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <style>#table-pelaksanaan{font-size:13px}#table-pelaksanaan th{font-size:13px;white-space:nowrap}#table-pelaksanaan td{vertical-align:middle}#table-pelaksanaan th:last-child,#table-pelaksanaan td:last-child{white-space:nowrap;text-align:center;min-width:92px}#table-pelaksanaan td:last-child .btn{margin:1px}</style>
                    <div class="table-responsive">
                        <table class="table table-striped" id="table-pelaksanaan">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th><?= $is_manajerial ? 'Unit/Bagian' : 'Guru' ?></th>
                                    <?php if (!$is_manajerial): ?><th>Mata Pelajaran</th><?php endif; ?>
                                    <th>Program</th>
                                    <th>Tanggal</th>
                                    <th>Supervisor</th>
                                    <th>Instrumen</th>
                                    <th>Nilai</th>
                                    <th>Predikat</th>
                                    <th>Temuan</th>
                                    <th>Rekomendasi</th>
                                    <th>Status</th>
                                    <?php if ($can_manage): ?><th width="10%">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td class="text-center"></td>
                                        <td><?= htmlspecialchars($is_manajerial ? ($r['unit_bagian'] ?: '-') : ($r['nama_guru'] ?: '-')) ?></td>
                                        <?php if (!$is_manajerial): ?><td><?= htmlspecialchars((string)($r['mapel_di_supervisi'] ?? '')) ?></td><?php endif; ?>
                                        <td><?= htmlspecialchars((string)$r['nama_program']) ?></td>
                                        <td><?= $r['tanggal'] ? date('d/m/Y', strtotime($r['tanggal'])) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['supervisor']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['nama_instrumen']) ?></td>
                                        <td><?= $r['nilai'] !== null ? htmlspecialchars(number_format((float)$r['nilai'], 2)) : '-' ?></td>
                                        <td><?= htmlspecialchars((string)$r['predikat']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['temuan']) ?></td>
                                        <td><?= htmlspecialchars((string)$r['rekomendasi']) ?></td>
                                        <td><span class="badge badge-<?= $r['status'] === 'Selesai' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                        <?php if ($can_manage): ?>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary" href="hasil_supervisi_detail.php?id=<?= (int)$r['id_pelaksanaan'] ?>" title="Detail"><i class="fas fa-eye"></i></a>
                                            <button class="btn btn-warning btn-sm btn-edit" type="button" data-row='<?= htmlspecialchars(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>'><i class="fas fa-edit"></i></button>
                                            <button class="btn btn-danger btn-sm btn-hapus" type="button" data-id="<?= (int)$r['id_pelaksanaan'] ?>"><i class="fas fa-trash"></i></button>
                                        </td>
                                        <?php endif; ?>
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

<style>#modal-pelaksanaan .form-control{max-width:100%}#modal-pelaksanaan .modal-body{overflow:visible}#modal-pelaksanaan select.form-control{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}</style>
<?php if ($can_manage): ?>
<div class="modal fade" id="modal-pelaksanaan" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <form method="POST" action="<?= htmlspecialchars(basename(__FILE__), ENT_QUOTES) ?>?jenis=<?= urlencode($sv_jenis) ?>" id="form-pelaksanaan">
                <input type="hidden" name="aksi" value="simpan">
                <input type="hidden" name="id_pelaksanaan" value="">
                <input type="hidden" name="jenis" value="<?= htmlspecialchars($sv_jenis, ENT_QUOTES) ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Pelaksanaan Supervisi</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label>Jadwal (opsional)</label>
                            <select class="form-control" name="id_jadwal">
                                <option value="">- Tanpa Jadwal -</option>
                                <?php foreach ($jadwal_list as $j): ?>
                                    <option value="<?= (int)$j['id_jadwal'] ?>"><?= htmlspecialchars(($j['nama_guru'] ?: '-') . ' - ' . ($j['tanggal'] ? date('d/m/Y', strtotime($j['tanggal'])) : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Program <small class="text-muted">→ Instrumen auto</small></label>
                            <select class="form-control" name="id_program" id="sv-program-select">
                                <option value="">- Pilih Program -</option>
                                <?php foreach ($program_list as $p): ?><option value="<?= (int)$p['id_program'] ?>" data-jenis="<?= htmlspecialchars($p['jenis_supervisi'], ENT_QUOTES) ?>"><?= htmlspecialchars($p['nama_program']) ?> (<?= htmlspecialchars($p['kode_program'] ?? $p['jenis_supervisi']) ?>)</option><?php endforeach; ?>
                                <?php if (!$program_list): ?>
                                    <?php foreach (($programByInstrumen[$sv_jenis] ?? []) as $p): ?><option value="<?= (int)$p['id_program'] ?>"><?= htmlspecialchars($p['nama_program']) ?></option><?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label>Instrumen <small class="text-muted">auto dari Program</small></label>
                            <select class="form-control" name="id_instrumen" id="sv-instrumen-select">
                                <option value="">- Tanpa Instrumen -</option>
                                <?php foreach ($instrumen_list as $i): ?><option value="<?= (int)$i['id_instrumen'] ?>" data-jenis="<?= htmlspecialchars($i['jenis_supervisi'], ENT_QUOTES) ?>"><?= htmlspecialchars($i['nama_instrumen']) ?> (<?= htmlspecialchars($i['kode_instrumen']) ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($is_manajerial): ?>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Unit/Bagian</label><input type="text" class="form-control" name="unit_bagian" placeholder="cth: Perpustakaan"></div>
                        <div class="form-group col-md-6"><label>Penanggung Jawab</label><input type="text" class="form-control" name="penanggung_jawab"></div>
                    </div>
                    <?php else: ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Guru/PTK</label>
                            <select class="form-control" name="id_guru" id="sv-guru-select" required>
                                <option value="">Pilih Guru</option>
                                <?php foreach ($guru_list as $g): ?><option value="<?= (int)$g['id_guru'] ?>"><?= htmlspecialchars($g['nama_guru']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Mapel yang Disupervisi <span class="text-danger">*</span> <small class="text-muted">pilih satu mapel akademik guru</small></label>
                            <select class="form-control" name="mapel_di_supervisi" id="sv-mapel-select" required>
                                <option value="">Pilih Guru dulu</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group col-md-4"><label>Tanggal</label><input type="date" class="form-control" name="tanggal" required></div>
                        <div class="form-group col-md-4"><label>Supervisor</label><input type="text" class="form-control" name="supervisor" value="<?= htmlspecialchars(sv_current_user_name($pdo), ENT_QUOTES) ?>"></div>
                        <div class="form-group col-md-4">
                            <label>Status</label>
                            <select class="form-control" name="status"><option value="Draft">Draft</option><option value="Selesai">Selesai</option></select>
                        </div>
                    </div>

                    <?php if ($is_manajerial): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="mb-0">Detail Supervisi Manajerial</label>
                            <div class="d-flex align-items-center">
                                <small class="text-muted mr-2">Nilai = rata-rata Skor; predikat: A ≥91, B 76–90, C 61–75, D &lt;61</small>
                                <button type="button" class="btn btn-sm btn-primary" id="btn-tambah-detail"><i class="fas fa-plus"></i> Baris</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead>
                                    <tr>
                                        <th>Unit/Bagian</th><th>Penanggung Jawab</th><th>Program</th><th>Indikator</th>
                                        <th>Target</th><th>Realisasi</th><th>Skor</th><th>Temuan</th><th>Kendala</th><th>Rekomendasi</th><th>Status</th><th></th>
                                    </tr>
                                </thead>
                                <tbody id="sv-manajerial-body"></tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="mb-0">Detail Penilaian</label>
                            <div class="d-flex align-items-center">
                                <small class="text-muted mr-2" id="sv-skor-hint" style="display:none;">Rentang skor: <span id="sv-skor-rentang"></span> · Nilai = Σ(bobot×skor/maks) ÷ Σbobot × 100</small>
                                <span class="badge badge-primary">Nilai: <span id="sv-nilai-preview">0.00</span> <small>·</small> <span id="sv-predikat-preview" class="font-weight-bold">-</span></span>
                            </div>
                        </div>
                        <div id="sv-penilaian-box"><div class="alert alert-light border mb-0">Pilih instrumen untuk menampilkan indikator penilaian.</div></div>
                    <?php endif; ?>

                    <hr>
                    <div class="form-row">
                        <div class="form-group col-md-6"><label>Kekuatan</label><textarea class="form-control" name="kekuatan" rows="2"></textarea></div>
                        <div class="form-group col-md-6"><label>Kelemahan</label><textarea class="form-control" name="kelemahan" rows="2"></textarea></div>
                        <div class="form-group col-md-6"><label>Temuan</label><textarea class="form-control" name="temuan" rows="2"></textarea></div>
                        <div class="form-group col-md-6"><label>Rekomendasi</label><textarea class="form-control" name="rekomendasi" rows="2"></textarea></div>
                        <div class="form-group col-md-6"><label>Prioritas Perbaikan</label><input type="text" class="form-control" name="prioritas_perbaikan"></div>
                        <div class="form-group col-md-6"><label>Keterangan</label><input type="text" class="form-control" name="keterangan"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include '../templates/footer.php'; ?>
