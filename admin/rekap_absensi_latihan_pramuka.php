<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/pramuka_latihan.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'guru', 'wali'])) {
    redirect('../login.php');
}

try {
    pramukaLatihanEnsureSchema($pdo);
} catch (Throwable $e) {
}

$scope = pramukaLatihanScope($pdo);
$tingkat_list = pramukaLatihanTingkatList($pdo, $scope);
$tingkat_ids = array_map(static fn($t) => (int)$t['id_tingkat_barung'], $tingkat_list);

$page_title = 'Rekap Kehadiran Latihan Pramuka';

$css_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js',
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];

$tingkat_id = isset($_POST['tingkat_id']) ? (int)$_POST['tingkat_id'] : 0;
if ($tingkat_id > 0 && !pramukaLatihanCanAccessTingkat($scope, $tingkat_id)) {
    $tingkat_id = 0;
}
$filter_type = $_POST['filter_type'] ?? 'daily';
$selected_date = $_POST['attendance_date'] ?? date('Y-m-d');
$selected_month = $_POST['month_picker'] ?? date('Y-m');
$selected_peserta = isset($_POST['peserta_id']) ? (int)$_POST['peserta_id'] : 0;

$daily_results = [];
$monthly_results = [];
$semester_results = [];
$student_results = [];
$student_attendance_summary = [];
$absent_summary = [];
$holidays = [];
$year = (int)date('Y');
$month = (int)date('m');
$month_num = (int)substr((string)$selected_month, 5, 2);

$month_names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$js_month_name = $month_names[$month_num] ?? '';
$js_month_year = substr((string)$selected_month, 0, 4);

$school_profile = getSchoolProfile($pdo);
$active_semester = $school_profile['semester'] ?? 'Semester 1';
$periode_ta = getRentangTanggalTahunAjaran($school_profile['tahun_ajaran'] ?? null);

$tingkat_info = [];
foreach ($tingkat_list as $t) {
    if ((int)$t['id_tingkat_barung'] === $tingkat_id) {
        $tingkat_info = $t;
        break;
    }
}

$in_scope = static function (string $alias = 'p') use ($scope): array {
    if (!empty($scope['all'])) {
        return ['', []];
    }
    $ids = array_values(array_filter(array_map('intval', $scope['ids'] ?? []), static fn($v) => $v > 0));
    if (empty($ids)) {
        return [' AND 1=0', []];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return [" AND {$alias}.id_tingkat_barung IN ($in)", $ids];
};

// Ringkasan ketidakhadiran seluruh tingkatan (bila Harian)
if ($filter_type === 'daily' && $selected_date !== '') {
    [$scopeSql, $scopeBind] = $in_scope('p');
    $sqlAbs = "
        SELECT p.nama_peserta_didik AS nama_siswa, t.nama_tingkat AS nama_kelas, a.keterangan
        FROM tb_absensi_latihan_pramuka a
        JOIN tb_peserta_didik_barung p ON a.id_peserta_didik_barung = p.id_peserta_didik_barung
        JOIN tb_tingkat_barung t ON p.id_tingkat_barung = t.id_tingkat_barung
        WHERE a.tanggal = ? AND a.keterangan IN ('Sakit', 'Izin', 'Alpa')
          AND " . pramukaLatihanPesertaAktifSql('p') . $scopeSql;
    $bindAbs = array_merge([$selected_date], $scopeBind);
    if ($periode_ta) {
        $sqlAbs .= " AND a.tanggal >= ? AND a.tanggal <= ?";
        $bindAbs[] = $periode_ta['mulai'];
        $bindAbs[] = $periode_ta['sampai'];
    }
    $sqlAbs .= " ORDER BY t.nama_tingkat ASC, p.nama_peserta_didik ASC";
    try {
        $st = $pdo->prepare($sqlAbs);
        $st->execute($bindAbs);
        $absent_summary = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $absent_summary = [];
    }
}

if ($tingkat_id > 0) {
    if ($filter_type === 'daily' && $selected_date !== '') {
        $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta FROM tb_peserta_didik_barung p WHERE p.id_tingkat_barung = ? AND " . pramukaLatihanPesertaAktifSql('p') . " ORDER BY p.nama_peserta_didik ASC");
        $st->execute([$tingkat_id]);
        $all_peserta = $st->fetchAll(PDO::FETCH_ASSOC);

        $sqlD = "
            SELECT a.id_peserta_didik_barung, p.nama_peserta_didik AS nama_siswa, p.nta,
                   a.keterangan, a.tanggal, t.nama_tingkat AS nama_kelas, a.jam_masuk
            FROM tb_absensi_latihan_pramuka a
            JOIN tb_peserta_didik_barung p ON p.id_peserta_didik_barung = a.id_peserta_didik_barung
            JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
            WHERE p.id_tingkat_barung = ? AND a.tanggal = ?";
        $bindD = [$tingkat_id, $selected_date];
        if ($periode_ta) {
            $sqlD .= " AND a.tanggal >= ? AND a.tanggal <= ?";
            $bindD[] = $periode_ta['mulai'];
            $bindD[] = $periode_ta['sampai'];
        }
        $sqlD .= " ORDER BY p.nama_peserta_didik ASC";
        $st = $pdo->prepare($sqlD);
        $st->execute($bindD);
        $records = $st->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($records as $r) {
            $byId[(int)$r['id_peserta_didik_barung']] = $r;
        }
        foreach ($all_peserta as $p) {
            $pid = (int)$p['id_peserta_didik_barung'];
            if (isset($byId[$pid])) {
                $daily_results[] = $byId[$pid];
            } else {
                $daily_results[] = [
                    'id_peserta_didik_barung' => $pid,
                    'nama_siswa' => $p['nama_peserta_didik'],
                    'nta' => $p['nta'],
                    'keterangan' => 'Belum Mengisi Kehadiran',
                    'tanggal' => $selected_date,
                    'nama_kelas' => $tingkat_info['nama_tingkat'] ?? '',
                    'jam_masuk' => null,
                ];
            }
        }
    } elseif ($filter_type === 'monthly' && $selected_month !== '') {
        $year = (int)substr($selected_month, 0, 4);
        $month = (int)substr($selected_month, 5, 2);

        $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta,
                COALESCE((
                    SELECT s.foto FROM tb_siswa s
                    WHERE (
                        (p.id_siswa IS NOT NULL AND s.id_siswa = p.id_siswa)
                        OR (
                            p.id_siswa IS NULL AND TRIM(IFNULL(p.nta, '')) <> ''
                            AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                = CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                        )
                    )
                    LIMIT 1
                ), '') AS foto
            FROM tb_peserta_didik_barung p
            WHERE p.id_tingkat_barung = ? AND " . pramukaLatihanPesertaAktifSql('p') . "
            ORDER BY p.nama_peserta_didik ASC");
        $st->execute([$tingkat_id]);
        $all_peserta = $st->fetchAll(PDO::FETCH_ASSOC);

        $sqlM = "
            SELECT a.id_peserta_didik_barung, p.nama_peserta_didik, p.nta, a.keterangan, DAY(a.tanggal) AS day,
                   p.id_siswa
            FROM tb_absensi_latihan_pramuka a
            JOIN tb_peserta_didik_barung p ON p.id_peserta_didik_barung = a.id_peserta_didik_barung
            WHERE p.id_tingkat_barung = ? AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?";
        $bindM = [$tingkat_id, $year, $month];
        if ($periode_ta) {
            $sqlM .= " AND a.tanggal >= ? AND a.tanggal <= ?";
            $bindM[] = $periode_ta['mulai'];
            $bindM[] = $periode_ta['sampai'];
        }
        $sqlM .= " ORDER BY p.nama_peserta_didik, a.tanggal";
        $st = $pdo->prepare($sqlM);
        $st->execute($bindM);
        $records = $st->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($records as $r) {
            $pid = (int)$r['id_peserta_didik_barung'];
            if (!isset($byId[$pid])) {
                $byId[$pid] = [
                    'days' => array_fill(1, 31, ''),
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0],
                ];
            }
            $d = (int)$r['day'];
            $byId[$pid]['days'][$d] = $r['keterangan'];
            $key = ($r['keterangan'] === 'Terlambat') ? 'Hadir' : $r['keterangan'];
            if (isset($byId[$pid]['summary'][$key])) {
                $byId[$pid]['summary'][$key]++;
            }
        }

        foreach ($all_peserta as $p) {
            $pid = (int)$p['id_peserta_didik_barung'];
            $monthly_results[] = [
                'id_peserta_didik_barung' => $pid,
                'nama_siswa' => $p['nama_peserta_didik'],
                'nta' => $p['nta'],
                'foto' => (string)($p['foto'] ?? ''),
                'days' => $byId[$pid]['days'] ?? array_fill(1, 31, ''),
                'summary' => $byId[$pid]['summary'] ?? ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0],
            ];
        }

        $holidays = getHolidays($pdo, $year, $month);
    } elseif ($filter_type === 'student' && $selected_peserta > 0) {
        $st = $pdo->prepare("
            SELECT p.nama_peserta_didik AS nama_siswa, p.nta AS nisn, p.id_tingkat_barung, t.nama_tingkat AS nama_kelas
            FROM tb_peserta_didik_barung p
            JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
            WHERE p.id_peserta_didik_barung = ? AND p.id_tingkat_barung = ?
              AND " . pramukaLatihanPesertaAktifSql('p') . "
            LIMIT 1
        ");
        $st->execute([$selected_peserta, $tingkat_id]);
        $peserta_info = $st->fetch(PDO::FETCH_ASSOC);
        if ($peserta_info) {
            $sqlSt = "
                SELECT p.nama_peserta_didik AS nama_siswa, p.nta AS nisn, t.nama_tingkat AS nama_kelas,
                       a.keterangan, a.tanggal,
                       COALESCE((
                           SELECT s.jenis_kelamin FROM tb_siswa s
                           WHERE (
                               (p.id_siswa IS NOT NULL AND s.id_siswa = p.id_siswa)
                               OR (
                                   p.id_siswa IS NULL AND TRIM(IFNULL(p.nta, '')) <> ''
                                   AND CONVERT(TRIM(IFNULL(s.nisn, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                                       = CONVERT(TRIM(IFNULL(p.nta, '')) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                               )
                           )
                           LIMIT 1
                       ), '') AS jenis_kelamin
                FROM tb_absensi_latihan_pramuka a
                JOIN tb_peserta_didik_barung p ON p.id_peserta_didik_barung = a.id_peserta_didik_barung
                JOIN tb_tingkat_barung t ON t.id_tingkat_barung = p.id_tingkat_barung
                WHERE a.id_peserta_didik_barung = ?";
            $bindSt = [$selected_peserta];
            if ($periode_ta) {
                $sqlSt .= " AND a.tanggal >= ? AND a.tanggal <= ?";
                $bindSt[] = $periode_ta['mulai'];
                $bindSt[] = $periode_ta['sampai'];
            }
            $sqlSt .= " ORDER BY a.tanggal DESC";
            $st = $pdo->prepare($sqlSt);
            $st->execute($bindSt);
            $student_results = $st->fetchAll(PDO::FETCH_ASSOC);

            $summary = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0];
            foreach ($student_results as $r) {
                $key = ($r['keterangan'] === 'Terlambat') ? 'Hadir' : $r['keterangan'];
                if (isset($summary[$key])) {
                    $summary[$key]++;
                }
            }
            $student_attendance_summary = $summary;
        }
    } elseif ($filter_type === 'semester') {
        $academic_year = $school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1));
        $years = explode('/', (string)$academic_year);
        $start_year = (int)($years[0] ?? date('Y'));
        $end_year = (int)($years[1] ?? (date('Y') + 1));

        if ($active_semester === 'Semester 1') {
            $query_year = $start_year;
            $start_month = 7;
            $end_month = 12;
        } else {
            $query_year = $end_year;
            $start_month = 1;
            $end_month = 6;
        }

        $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.nama_peserta_didik, p.nta FROM tb_peserta_didik_barung p WHERE p.id_tingkat_barung = ? AND " . pramukaLatihanPesertaAktifSql('p') . " ORDER BY p.nama_peserta_didik ASC");
        $st->execute([$tingkat_id]);
        $all_peserta = $st->fetchAll(PDO::FETCH_ASSOC);

        $sqlSm = "
            SELECT a.id_peserta_didik_barung, p.nama_peserta_didik, p.nta, a.keterangan, a.tanggal,
                   MONTH(a.tanggal) AS month
            FROM tb_absensi_latihan_pramuka a
            JOIN tb_peserta_didik_barung p ON p.id_peserta_didik_barung = a.id_peserta_didik_barung
            WHERE p.id_tingkat_barung = ? AND YEAR(a.tanggal) = ? AND MONTH(a.tanggal) BETWEEN ? AND ?";
        $bindSm = [$tingkat_id, $query_year, $start_month, $end_month];
        if ($periode_ta) {
            $sqlSm .= " AND a.tanggal >= ? AND a.tanggal <= ?";
            $bindSm[] = $periode_ta['mulai'];
            $bindSm[] = $periode_ta['sampai'];
        }
        $sqlSm .= " ORDER BY p.nama_peserta_didik, a.tanggal";
        $st = $pdo->prepare($sqlSm);
        $st->execute($bindSm);
        $records = $st->fetchAll(PDO::FETCH_ASSOC);

        $byId = [];
        foreach ($records as $r) {
            $pid = (int)$r['id_peserta_didik_barung'];
            $m = (int)$r['month'];
            if (!isset($byId[$pid])) {
                $byId[$pid] = [
                    'monthly_totals' => [],
                    'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0],
                ];
                for ($mm = $start_month; $mm <= $end_month; $mm++) {
                    $byId[$pid]['monthly_totals'][$mm] = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0];
                }
            }
            $key = ($r['keterangan'] === 'Terlambat') ? 'Hadir' : $r['keterangan'];
            if (isset($byId[$pid]['monthly_totals'][$m][$key])) {
                $byId[$pid]['monthly_totals'][$m][$key]++;
                $byId[$pid]['summary'][$key]++;
            }
        }

        foreach ($all_peserta as $p) {
            $pid = (int)$p['id_peserta_didik_barung'];
            $data = [
                'nama_siswa' => $p['nama_peserta_didik'],
                'nta' => $p['nta'],
                'monthly_totals' => [],
                'summary' => ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0],
            ];
            for ($mm = $start_month; $mm <= $end_month; $mm++) {
                $data['monthly_totals'][$mm] = ['Hadir' => 0, 'Sakit' => 0, 'Izin' => 0, 'Alpa' => 0, 'Berhalangan' => 0];
            }
            if (isset($byId[$pid])) {
                $data['monthly_totals'] = $byId[$pid]['monthly_totals'];
                $data['summary'] = $byId[$pid]['summary'];
            }
            $semester_results[] = $data;
        }
    }
}

// Daftar peserta untuk filter per siswa
$tingkat_peserta = [];
if ($tingkat_id > 0) {
    try {
        $st = $pdo->prepare("SELECT p.id_peserta_didik_barung, p.nama_peserta_didik FROM tb_peserta_didik_barung p WHERE p.id_tingkat_barung = ? AND " . pramukaLatihanPesertaAktifSql('p') . " ORDER BY p.nama_peserta_didik ASC");
        $st->execute([$tingkat_id]);
        $tingkat_peserta = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $tingkat_peserta = [];
    }
}

$school_name_js = htmlspecialchars($school_profile['nama_madrasah'] ?? '', ENT_QUOTES, 'UTF-8');
$madrasah_head = addslashes(htmlspecialchars($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah', ENT_QUOTES, 'UTF-8'));
$pembina_name = addslashes(htmlspecialchars((string)($_SESSION['nama_guru'] ?? ($_SESSION['username'] ?? 'Pembina Pramuka')), ENT_QUOTES, 'UTF-8'));
$school_city = addslashes(htmlspecialchars($school_profile['tempat_jadwal'] ?? 'Padang', ENT_QUOTES, 'UTF-8'));
$report_date = formatDateIndonesia(date('Y-m-d'));

$js_page = [];
$js_page[] = "
var schoolName = '$school_name_js';
var madrasahHeadName = '$madrasah_head';
var pembinaName = '$pembina_name';
var schoolCity = '$school_city';
var reportDate = '$report_date';

function toggleFilterFields() {
    var v = $('#filterType').val();
    $('.daily-filter').toggle(v === 'daily');
    $('.monthly-filter').toggle(v === 'monthly');
    $('.student-filter').toggle(v === 'student');
}

$(document).ready(function () {
    toggleFilterFields();

    $('#filterType').on('change', function () {
        toggleFilterFields();
        var filterType = $(this).val();
        var needSubmit;
        if (filterType === 'daily') {
            needSubmit = !!$('#datePicker').val();
        } else if (filterType === 'monthly') {
            needSubmit = !!$('#monthPicker').val();
        } else if (filterType === 'student') {
            needSubmit = !!$('#pesertaSelect').val();
        } else {
            needSubmit = true;
        }
        if (needSubmit) {
            var filterForm = document.getElementById('attendanceFilterForm');
            if (filterForm) {
                if (filterForm.requestSubmit) { filterForm.requestSubmit(); } else { filterForm.submit(); }
            }
        }
    });

    if ($.fn.select2) {
        $('#pesertaSelect').select2({
            placeholder: 'Pilih Siswa...',
            allowClear: true,
            width: '100%'
        });
    }

    if ($.fn.selectric) {
        try { $('#tingkatSelect').selectric('refresh'); } catch (e) {}
        try { $('#filterType').selectric('refresh'); } catch (e) {}
        try { $('#pesertaSelect').selectric('refresh'); } catch (e) {}
    }

    if ($.fn.DataTable && $('#studentTable').length) {
        $('#studentTable').DataTable({
            paging: true,
            lengthChange: true,
            pageLength: -1,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
            dom: 'lfrtip',
            info: true,
            order: [],
            language: {
                lengthMenu: 'Tampilkan _MENU_ entri',
                zeroRecords: 'Tidak ada data yang ditemukan',
                info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                infoEmpty: 'Menampilkan 0 sampai 0 dari 0 entri',
                infoFiltered: '(disaring dari _MAX_ total entri)',
                search: 'Cari:',
                paginate: {
                    first: 'Awal',
                    last: 'Akhir',
                    next: 'Berikutnya',
                    previous: 'Sebelumnya'
                }
            }
        });
    }
});

function exportVisibleRekapToExcel() {
    var title = 'Rekap Kehadiran Latihan Pramuka';
    var html = '<h2>' + title + '</h2><h3>' + schoolName + '</h3>';
    var cards = $('.att-monthly-card');
    if (cards.length) {
        html += '<table border=\"1\"><thead><tr><th>No</th><th>Nama Siswa</th><th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alpa</th></tr></thead><tbody>';
        var i = 1;
        cards.each(function () {
            var name = $(this).find('.att-monthly-name').text().trim();
            var vals = $(this).find('.att-monthly-stat-val');
            html += '<tr><td>' + (i++) + '</td><td>' + name + '</td>';
            vals.each(function () { html += '<td>' + $(this).text().trim() + '</td>'; });
            html += '</tr>';
        });
        html += '</tbody></table>';
    } else if ($('#semesterTable').length) {
        html += $('#semesterTable').prop('outerHTML');
    } else if ($('#studentTable').length) {
        html += $('#studentTable').prop('outerHTML');
    } else {
        html += '<p>Tidak ada data.</p>';
    }
    var a = document.createElement('a');
    a.href = 'data:application/vnd.ms-excel;charset=utf-8,' + encodeURIComponent(html);
    a.download = 'rekap_kehadiran_latihan_' + new Date().toISOString().slice(0, 10) + '.xls';
    a.click();
}

function openCetakRekapTab() {
    var tingkatId = $('#tingkatSelect').val() || '';
    if (!tingkatId) {
        alert('Pilih tingkatan terlebih dahulu.');
        return;
    }
    var filterType = $('#filterType').val() || 'daily';
    var params = new URLSearchParams();
    params.set('type', filterType);
    params.set('tingkat_id', tingkatId);

    if (filterType === 'daily') {
        params.set('date', $('#datePicker').val() || '');
    } else if (filterType === 'monthly') {
        params.set('month', $('#monthPicker').val() || '');
    } else if (filterType === 'student') {
        var peserta = $('#pesertaSelect').val() || '';
        if (!peserta) {
            alert('Pilih siswa terlebih dahulu.');
            return;
        }
        params.set('peserta_id', peserta);
    }

    window.open('cetak_rekap_absensi_latihan_pramuka.php?' + params.toString(), '_blank', 'noopener');
}

function exportVisibleRekapToPDF() {
    openCetakRekapTab();
}
";

include '../templates/header.php';
?>

<style>
#semesterTable { font-size: 10pt; color: black; table-layout: auto; }
#semesterTable thead th { text-align: center; vertical-align: middle; background-color: #f8f9fa; font-weight: bold; color: black; }
#semesterTable thead th:nth-child(2) { white-space: normal; text-align: center; }
#semesterTable tbody td { color: black; white-space: nowrap; }
#semesterTable tbody td:nth-child(2) { white-space: normal; text-align: left; }
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Rekap Kehadiran Latihan Pramuka<?php if ($filter_type === 'monthly') { echo ' Bulanan - ' . htmlspecialchars($js_month_name . ' ' . $js_month_year); } elseif ($filter_type === 'semester') { echo ' ' . htmlspecialchars((string)$active_semester); } ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <?php if (empty($scope['all']) && empty($tingkat_ids)): ?>
                <div class="alert alert-warning">
                    Anda belum ditetapkan sebagai pembina pada tingkatan Pramuka mana pun. Hubungi admin atau tata usaha untuk mengatur tingkatan binaan.
                </div>
            <?php else: ?>
                <?php if ($filter_type === 'daily'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card border">
                                <div class="card-header pb-0">
                                    <h4>Ringkasan Ketidakhadiran Seluruh Tingkatan (<?php echo date('d-m-Y', strtotime($selected_date)); ?>)</h4>
                                </div>
                                <div class="card-body pt-0">
                                    <?php if (!empty($absent_summary)): ?>
                                        <?php
                                        $counts = ['Sakit' => 0, 'Izin' => 0, 'Alpa' => 0];
                                        foreach ($absent_summary as $abs) {
                                            if (isset($counts[$abs['keterangan']])) $counts[$abs['keterangan']]++;
                                        }
                                        $total_absent = array_sum($counts);
                                        ?>
                                        <div class="att-monthly-stats mt-3">
                                            <div class="att-monthly-stat-box stat-total">
                                                <div class="att-monthly-stat-val"><?php echo $total_absent; ?></div>
                                                <div class="att-monthly-stat-lbl">Total Tidak Hadir</div>
                                            </div>
                                            <div class="att-monthly-stat-box stat-sakit">
                                                <div class="att-monthly-stat-val"><?php echo $counts['Sakit']; ?></div>
                                                <div class="att-monthly-stat-lbl">Sakit</div>
                                            </div>
                                            <div class="att-monthly-stat-box stat-izin">
                                                <div class="att-monthly-stat-val"><?php echo $counts['Izin']; ?></div>
                                                <div class="att-monthly-stat-lbl">Izin</div>
                                            </div>
                                            <div class="att-monthly-stat-box stat-alpa">
                                                <div class="att-monthly-stat-val"><?php echo $counts['Alpa']; ?></div>
                                                <div class="att-monthly-stat-lbl">Alpa</div>
                                            </div>
                                        </div>
                                        <div class="att-rekap-timeline mt-2">
                                            <?php
                                            $no_abs = 1;
                                            foreach ($absent_summary as $abs):
                                                $abs_tone = $abs['keterangan'] === 'Sakit' ? '#f59e0b' : ($abs['keterangan'] === 'Izin' ? '#0ea5e9' : '#ef4444');
                                                $abs_badge = $abs['keterangan'] === 'Sakit' ? 'badge-warning' : ($abs['keterangan'] === 'Izin' ? 'badge-info' : 'badge-danger');
                                            ?>
                                                <div class="att-rekap-item" style="--att-tone-color: <?php echo $abs_tone; ?>;">
                                                    <div class="att-rekap-num">
                                                        <span class="seq"><?php echo $no_abs++; ?></span>
                                                        <span class="sub"><?php echo htmlspecialchars((string)$abs['nama_kelas']); ?></span>
                                                    </div>
                                                    <div class="att-rekap-card">
                                                        <div class="att-rekap-main">
                                                            <div class="att-rekap-name"><?php echo htmlspecialchars((string)$abs['nama_siswa']); ?></div>
                                                            <div class="att-rekap-meta">
                                                                <span><i class="fas fa-school"></i> <?php echo htmlspecialchars((string)$abs['nama_kelas']); ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="att-rekap-badge">
                                                            <span class="badge <?php echo $abs_badge; ?>"><?php echo htmlspecialchars((string)$abs['keterangan']); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-light mt-3 text-center">
                                            Semua siswa hadir atau belum ada data kehadiran untuk tanggal ini.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4>Filter Rekap Kehadiran</h4>
                            </div>
                            <div class="card-body">
                                <form method="POST" class="row" id="attendanceFilterForm">
                                    <div class="form-group col-md-3">
                                        <label>Pilih Tingkatan</label>
                                        <select name="tingkat_id" class="form-control selectric" id="tingkatSelect" required onchange="this.form.submit()">
                                            <option value="">Pilih Tingkatan...</option>
                                            <?php foreach ($tingkat_list as $t): ?>
                                                <option value="<?php echo (int)$t['id_tingkat_barung']; ?>" <?php echo ($tingkat_id === (int)$t['id_tingkat_barung']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)$t['nama_tingkat']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group col-md-2">
                                        <label>Jenis Filter</label>
                                        <select name="filter_type" class="form-control selectric" id="filterType">
                                            <option value="daily" <?php echo $filter_type === 'daily' ? 'selected' : ''; ?>>Harian</option>
                                            <option value="monthly" <?php echo $filter_type === 'monthly' ? 'selected' : ''; ?>>Bulanan</option>
                                            <option value="semester" <?php echo $filter_type === 'semester' ? 'selected' : ''; ?>>Per Semester</option>
                                            <option value="student" <?php echo $filter_type === 'student' ? 'selected' : ''; ?>>Per Siswa</option>
                                        </select>
                                    </div>

                                    <div class="form-group col-md-3 daily-filter" style="<?php echo $filter_type === 'daily' ? '' : 'display:none;'; ?>">
                                        <label>Pilih Tanggal</label>
                                        <input type="date" name="attendance_date" class="form-control" value="<?php echo htmlspecialchars((string)$selected_date); ?>" id="datePicker" onchange="this.form.submit()">
                                    </div>

                                    <div class="form-group col-md-3 monthly-filter" style="<?php echo $filter_type === 'monthly' ? '' : 'display:none;'; ?>">
                                        <label>Pilih Bulan</label>
                                        <input type="month" name="month_picker" class="form-control" value="<?php echo htmlspecialchars((string)$selected_month); ?>" id="monthPicker" onchange="this.form.submit()">
                                    </div>

                                    <div class="form-group col-md-3 student-filter" style="<?php echo $filter_type === 'student' ? '' : 'display:none;'; ?>">
                                        <label>Pilih Siswa</label>
                                        <select name="peserta_id" class="form-control selectric" id="pesertaSelect">
                                            <option value="">Pilih Siswa...</option>
                                            <?php foreach ($tingkat_peserta as $p): ?>
                                                <option value="<?php echo (int)$p['id_peserta_didik_barung']; ?>" <?php echo ($selected_peserta === (int)$p['id_peserta_didik_barung']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string)$p['nama_peserta_didik']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>

                                <?php if (!empty($daily_results)): ?>
                                    <div class="mt-4">
                                        <div class="alert alert-success alert-has-icon">
                                            <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                            <div class="alert-body">
                                                <div class="alert-title">Berhasil</div>
                                                Ditemukan <?php echo count($daily_results); ?> data kehadiran untuk tanggal yang dipilih.
                                            </div>
                                        </div>

                                        <div class="att-rekap-timeline">
                                            <?php $no = 1; foreach ($daily_results as $record): ?>
                                                <?php
                                                $status_text = $record['keterangan'] ?? 'Belum Mengisi Kehadiran';
                                                $status_class = 'badge-secondary';
                                                $tone_color = '#64748b';
                                                switch ($status_text) {
                                                    case 'Hadir': $status_class = 'badge-success'; $tone_color = '#10b981'; break;
                                                    case 'Sakit': $status_class = 'badge-warning'; $tone_color = '#f59e0b'; break;
                                                    case 'Izin': $status_class = 'badge-info'; $tone_color = '#0ea5e9'; break;
                                                    case 'Alpa':
                                                    case 'Berhalangan': $status_class = 'badge-danger'; $tone_color = '#ef4444'; break;
                                                    default: $status_class = 'badge-secondary'; $tone_color = '#64748b'; break;
                                                }
                                                $jam_str = !empty($record['jam_masuk']) ? date('H:i:s', strtotime((string)$record['jam_masuk'])) : '-';
                                                $tgl_str = !empty($record['tanggal']) ? date('d M Y', strtotime((string)$record['tanggal'])) : '-';
                                                ?>
                                                <div class="att-rekap-item" style="--att-tone-color: <?= $tone_color ?>;">
                                                    <div class="att-rekap-num">
                                                        <span class="seq"><?= $no++ ?></span>
                                                        <span class="sub"><?= htmlspecialchars((string)($record['nama_kelas'] ?? '')) ?></span>
                                                    </div>
                                                    <div class="att-rekap-card">
                                                        <div class="att-rekap-main">
                                                            <div class="att-rekap-name"><?= htmlspecialchars((string)$record['nama_siswa']) ?></div>
                                                            <div class="att-rekap-meta">
                                                                <span><i class="far fa-clock"></i> <?= $jam_str ?></span>
                                                                <span><i class="far fa-calendar-alt"></i> <?= $tgl_str ?></span>
                                                                <?php if (!empty($record['nama_kelas'])): ?>
                                                                    <span><i class="fas fa-school"></i> <?= htmlspecialchars((string)$record['nama_kelas']) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="att-rekap-badge">
                                                            <span class="badge <?= $status_class ?>"><?= htmlspecialchars($status_text) ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php elseif (!empty($semester_results)): ?>
                                    <div class="mt-4">
                                        <div class="alert alert-success alert-has-icon">
                                            <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                            <div class="alert-body">
                                                <div class="alert-title">Rekap Per Semester</div>
                                                Menampilkan rekap kehadiran <?php echo htmlspecialchars((string)$active_semester); ?> Tahun Ajaran <?php echo htmlspecialchars((string)($school_profile['tahun_ajaran'] ?? (date('Y') . '/' . (date('Y') + 1)))); ?> untuk <?php echo count($semester_results); ?> siswa.
                                            </div>
                                        </div>

                                        <div class="row mb-3">
                                            <div class="col-md-12">
                                                <div class="btn-group float-right" role="group">
                                                    <button type="button" class="btn btn-success" onclick="exportVisibleRekapToExcel()"><i class="fas fa-file-excel"></i> Ekspor Excel</button>
                                                    <button type="button" class="btn btn-warning" onclick="exportVisibleRekapToPDF()"><i class="fas fa-file-pdf"></i> Ekspor PDF</button>
                                                </div>
                                            </div>
                                        </div>

                                        <div style="overflow-x: auto; width: 100%;">
                                            <table class="table table-bordered" id="semesterTable" style="min-width: 1400px; font-size: 10pt; color: black;">
                                                <thead>
                                                    <tr>
                                                        <th rowspan="2">No</th>
                                                        <th rowspan="2">Nama Siswa</th>
                                                        <?php
                                                        $start_month_hdr = ($active_semester === 'Semester 1') ? 7 : 1;
                                                        $end_month_hdr = ($active_semester === 'Semester 1') ? 12 : 6;
                                                        for ($m = $start_month_hdr; $m <= $end_month_hdr; $m++):
                                                        ?>
                                                            <th colspan="4" class="text-center"><?php echo $month_names[$m]; ?></th>
                                                        <?php endfor; ?>
                                                        <th colspan="4" class="text-center">Total Semester</th>
                                                    </tr>
                                                    <tr>
                                                        <?php
                                                        $total_months = ($end_month_hdr - $start_month_hdr) + 1;
                                                        for ($i = 0; $i < $total_months + 1; $i++):
                                                        ?>
                                                            <th>H</th><th>S</th><th>I</th><th>A</th>
                                                        <?php endfor; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($semester_results as $index => $row): ?>
                                                        <tr>
                                                            <td><?php echo $index + 1; ?></td>
                                                            <td><?php echo htmlspecialchars((string)$row['nama_siswa']); ?></td>
                                                            <?php for ($m = $start_month_hdr; $m <= $end_month_hdr; $m++): ?>
                                                                <td class="text-center"><?php echo (int)($row['monthly_totals'][$m]['Hadir'] ?? 0) ?: '-'; ?></td>
                                                                <td class="text-center"><?php echo (int)($row['monthly_totals'][$m]['Sakit'] ?? 0) ?: '-'; ?></td>
                                                                <td class="text-center"><?php echo (int)($row['monthly_totals'][$m]['Izin'] ?? 0) ?: '-'; ?></td>
                                                                <td class="text-center"><?php echo (int)($row['monthly_totals'][$m]['Alpa'] ?? 0) ?: '-'; ?></td>
                                                            <?php endfor; ?>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Hadir'] ?? 0); ?></td>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Sakit'] ?? 0); ?></td>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Izin'] ?? 0); ?></td>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Alpa'] ?? 0); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php elseif (!empty($monthly_results)): ?>
                                    <div class="mt-4">
                                        <div class="alert alert-success alert-has-icon">
                                            <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                            <div class="alert-body">
                                                <div class="alert-title">Rekap Bulanan</div>
                                                Menampilkan rekap kehadiran bulanan untuk <?php echo count($monthly_results); ?> siswa.
                                            </div>
                                        </div>

                                        <div class="row mb-3">
                                            <div class="col-md-12">
                                                <div class="btn-group float-right" role="group">
                                                    <button type="button" class="btn btn-success" onclick="exportVisibleRekapToExcel()"><i class="fas fa-file-excel"></i> Ekspor Excel</button>
                                                    <button type="button" class="btn btn-warning" onclick="exportVisibleRekapToPDF()"><i class="fas fa-file-pdf"></i> Ekspor PDF</button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="att-monthly-grid">
                                            <?php foreach ($monthly_results as $index => $row): ?>
                                                <div class="att-monthly-card">
                                                    <div class="att-monthly-head">
                                                        <div class="att-monthly-avatar"><?= getPersonAvatarHtml((string)($row['foto'] ?? ''), (string)($index + 1), 'att-monthly-avatar-img') ?></div>
                                                        <div class="att-monthly-title">
                                                            <div class="att-monthly-name"><?= htmlspecialchars((string)$row['nama_siswa']) ?></div>
                                                            <div class="att-monthly-sub">
                                                                <i class="far fa-calendar-alt"></i>
                                                                <?= htmlspecialchars($js_month_name . ' ' . $js_month_year) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="att-monthly-stats">
                                                        <div class="att-monthly-stat-box stat-hadir">
                                                            <div class="att-monthly-stat-val"><?= (int)($row['summary']['Hadir'] ?? 0) ?></div>
                                                            <div class="att-monthly-stat-lbl">Hadir</div>
                                                        </div>
                                                        <div class="att-monthly-stat-box stat-sakit">
                                                            <div class="att-monthly-stat-val"><?= (int)($row['summary']['Sakit'] ?? 0) ?></div>
                                                            <div class="att-monthly-stat-lbl">Sakit</div>
                                                        </div>
                                                        <div class="att-monthly-stat-box stat-izin">
                                                            <div class="att-monthly-stat-val"><?= (int)($row['summary']['Izin'] ?? 0) ?></div>
                                                            <div class="att-monthly-stat-lbl">Izin</div>
                                                        </div>
                                                        <div class="att-monthly-stat-box stat-alpa">
                                                            <div class="att-monthly-stat-val"><?= (int)($row['summary']['Alpa'] ?? 0) ?></div>
                                                            <div class="att-monthly-stat-lbl">Alpa</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="table-responsive d-none">
                                            <table class="table table-bordered table-md">
                                                <thead>
                                                    <tr>
                                                        <th rowspan="2">No</th>
                                                        <th rowspan="2">Nama Siswa</th>
                                                        <th colspan="31" class="text-center"><?php echo htmlspecialchars($js_month_name . ' ' . $js_month_year); ?></th>
                                                        <th colspan="4" class="text-center">Total</th>
                                                    </tr>
                                                    <tr>
                                                        <?php for ($day = 1; $day <= 31; $day++): ?><th><?php echo $day; ?></th><?php endfor; ?>
                                                        <th>Hadir</th><th>Sakit</th><th>Izin</th><th>Alpa</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($monthly_results as $index => $row): ?>
                                                        <tr>
                                                            <td><?php echo $index + 1; ?></td>
                                                            <td><?php echo htmlspecialchars((string)$row['nama_siswa']); ?></td>
                                                            <?php for ($day = 1; $day <= 31; $day++): ?>
                                                                <td>
                                                                    <?php
                                                                    $status = $row['days'][$day] ?? '';
                                                                    $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                                                    if (isset($holidays[$current_date])) {
                                                                        echo '<span style="color:red;" title="' . htmlspecialchars((string)$holidays[$current_date], ENT_QUOTES, 'UTF-8') . '">L</span>';
                                                                    } elseif ($status !== '') {
                                                                        echo '<span>' . htmlspecialchars(substr((string)$status, 0, 1)) . '</span>';
                                                                    }
                                                                    ?>
                                                                </td>
                                                            <?php endfor; ?>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Hadir'] ?? 0); ?></td>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Sakit'] ?? 0); ?></td>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Izin'] ?? 0); ?></td>
                                                            <td class="text-center"><?php echo (int)($row['summary']['Alpa'] ?? 0); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php elseif (!empty($student_results)): ?>
                                    <div class="mt-4">
                                        <div class="alert alert-success alert-has-icon">
                                            <div class="alert-icon"><i class="far fa-check-circle"></i></div>
                                            <div class="alert-body">
                                                <div class="alert-title" data-jk="<?php echo htmlspecialchars(strtoupper(trim((string)($student_results[0]['jenis_kelamin'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">Data Kehadiran Siswa</div>
                                                Menampilkan riwayat kehadiran untuk <?php echo htmlspecialchars((string)($student_results[0]['nama_siswa'] ?? '')); ?>
                                            </div>
                                        </div>

                                        <div class="row mb-4">
                                            <?php
                                            $cards = [
                                                ['Hadir', 'bg-success', 'fa-check'],
                                                ['Sakit', 'bg-warning', 'fa-medkit'],
                                                ['Izin', 'bg-info', 'fa-file-alt'],
                                                ['Alpa', 'bg-danger', 'fa-times'],
                                                ['Berhalangan', 'bg-danger', 'fa-ban'],
                                            ];
                                            foreach ($cards as $c):
                                            ?>
                                                <div class="col-md-3">
                                                    <div class="card card-statistic-1">
                                                        <div class="card-icon <?php echo $c[1]; ?>"><i class="fas <?php echo $c[2]; ?>"></i></div>
                                                        <div class="card-wrap">
                                                            <div class="card-header"><h4>Total <?php echo $c[0]; ?></h4></div>
                                                            <div class="card-body"><?php echo (int)($student_attendance_summary[$c[0]] ?? 0); ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="table-responsive">
                                            <table class="table table-striped table-md" id="studentTable">
                                                <thead>
                                                    <tr><th>No</th><th>Tanggal</th><th>Status Kehadiran</th><th>Catatan</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($student_results as $index => $record): ?>
                                                        <tr>
                                                            <td><?php echo $index + 1; ?></td>
                                                            <td><?php echo $record['tanggal'] ? date('d M Y', strtotime((string)$record['tanggal'])) : '-'; ?></td>
                                                            <td>
                                                                <?php
                                                                $status_text = $record['keterangan'] ?? 'Belum Mengisi Kehadiran';
                                                                $status_class = 'badge-secondary';
                                                                switch ($status_text) {
                                                                    case 'Hadir': $status_class = 'badge-success'; break;
                                                                    case 'Sakit': $status_class = 'badge-warning'; break;
                                                                    case 'Izin': $status_class = 'badge-info'; break;
                                                                    case 'Alpa':
                                                                    case 'Berhalangan': $status_class = 'badge-danger'; break;
                                                                }
                                                                ?>
                                                                <div class="badge <?php echo $status_class; ?> px-3 py-2"><?php echo htmlspecialchars((string)$status_text); ?></div>
                                                            </td>
                                                            <td>-</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php elseif ($tingkat_id > 0): ?>
                                    <div class="mt-4">
                                        <div class="alert alert-info alert-has-icon">
                                            <div class="alert-icon"><i class="fas fa-info-circle"></i></div>
                                            <div class="alert-body">
                                                <div class="alert-title">Informasi</div>
                                                Tidak ada data kehadiran ditemukan untuk tingkatan dan filter yang dipilih.
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
