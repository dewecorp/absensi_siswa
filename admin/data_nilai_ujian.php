<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha', 'wali'])) {
    redirect('../login.php');
}

$page_title = 'Data Nilai Ujian';
$current_level = getUserLevel();
$can_crud = in_array($current_level, ['admin', 'tata_usaha'], true);
$message = '';
$error = '';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_rekap_nilai_ujian (
        id_rekap_nilai_ujian INT NOT NULL AUTO_INCREMENT,
        tahun_ajaran VARCHAR(30) NOT NULL,
        nilai_terendah DECIMAL(5,2) NOT NULL DEFAULT 0,
        nilai_tertinggi DECIMAL(5,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id_rekap_nilai_ujian),
        UNIQUE KEY uniq_rekap_nilai_ujian_ta (tahun_ajaran)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('tb_rekap_nilai_ujian: ' . $e->getMessage());
    $error = 'Gagal menyiapkan tabel data nilai ujian.';
}

try {
    $has_rekap = (int)$pdo->query('SELECT COUNT(*) FROM tb_rekap_nilai_ujian')->fetchColumn();
    if ($has_rekap === 0) {
        $pdo->exec("INSERT INTO tb_rekap_nilai_ujian (tahun_ajaran, nilai_terendah, nilai_tertinggi)
            SELECT tahun_ajaran, MIN(nilai_ujian), MAX(nilai_ujian)
            FROM tb_data_nilai_ujian
            WHERE tahun_ajaran IS NOT NULL AND tahun_ajaran <> ''
            GROUP BY tahun_ajaran");
    }
} catch (PDOException $e) {
    // Abaikan migrasi otomatis jika tabel input lama belum ada.
}

$school_profile = getSchoolProfile($pdo);
$profile_ta = $school_profile['tahun_ajaran'] ?? '';
$selected_ta = isTahunAjaranFormatValid($profile_ta) ? $profile_ta : '';
$school_name = trim((string)($school_profile['nama_madrasah'] ?? 'Sistem Informasi Madrasah'));
$school_foundation = trim((string)($school_profile['nama_yayasan'] ?? ''));
$school_address = trim((string)($school_profile['alamat'] ?? ''));
$school_email = trim((string)($school_profile['email_madrasah'] ?? ''));
$school_website = trim((string)($school_profile['website_madrasah'] ?? ''));
$school_logo = !empty($school_profile['logo']) ? basename((string)$school_profile['logo']) : 'logo.png';
$kepala_madrasah = trim((string)($school_profile['kepala_madrasah'] ?? 'Kepala Madrasah'));
$nip_kepala = trim((string)($school_profile['nip_kepala'] ?? ''));
$tempat_ttd = trim((string)($school_profile['tempat_jadwal'] ?? ''));
if ($tempat_ttd === '') {
    $tempat_ttd = 'Jepara';
}
$tanggal_ttd = function_exists('formatDateIndonesia') ? formatDateIndonesia(date('Y-m-d')) : date('d-m-Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_crud) {
        $error = 'Anda tidak memiliki izin mengubah data nilai ujian.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'tambah' || $action === 'edit') {
                $id_rekap = (int)($_POST['id_rekap_nilai_ujian'] ?? 0);
                $tahun_ajaran = trim((string)($_POST['tahun_ajaran'] ?? ''));
                $nilai_terendah = str_replace(',', '.', trim((string)($_POST['nilai_terendah'] ?? '')));
                $nilai_tertinggi = str_replace(',', '.', trim((string)($_POST['nilai_tertinggi'] ?? '')));

                if (!isTahunAjaranFormatValid($tahun_ajaran)) {
                    throw new RuntimeException('Format tahun ajaran tidak valid. Contoh: 2025/2026.');
                }
                if (!is_numeric($nilai_terendah) || !is_numeric($nilai_tertinggi)) {
                    throw new RuntimeException('Nilai terendah dan nilai tertinggi wajib berupa angka.');
                }
                $nilai_terendah = round((float)$nilai_terendah, 2);
                $nilai_tertinggi = round((float)$nilai_tertinggi, 2);
                if ($nilai_terendah < 0 || $nilai_terendah > 100 || $nilai_tertinggi < 0 || $nilai_tertinggi > 100) {
                    throw new RuntimeException('Nilai wajib berada pada rentang 0 sampai 100.');
                }
                if ($nilai_terendah > $nilai_tertinggi) {
                    throw new RuntimeException('Nilai terendah tidak boleh lebih besar dari nilai tertinggi.');
                }

                if ($action === 'tambah') {
                    $stmt = $pdo->prepare('INSERT INTO tb_rekap_nilai_ujian (tahun_ajaran, nilai_terendah, nilai_tertinggi) VALUES (?, ?, ?)');
                    $stmt->execute([$tahun_ajaran, $nilai_terendah, $nilai_tertinggi]);
                    logActivity($pdo, $_SESSION['username'] ?? $current_level, 'Tambah Data Nilai Ujian', 'Menambahkan rekap nilai ujian tahun ajaran ' . $tahun_ajaran);
                    $message = 'Data nilai ujian berhasil ditambahkan.';
                } else {
                    if ($id_rekap <= 0) {
                        throw new RuntimeException('Data nilai ujian tidak valid.');
                    }
                    $stmt = $pdo->prepare('UPDATE tb_rekap_nilai_ujian SET tahun_ajaran = ?, nilai_terendah = ?, nilai_tertinggi = ? WHERE id_rekap_nilai_ujian = ?');
                    $stmt->execute([$tahun_ajaran, $nilai_terendah, $nilai_tertinggi, $id_rekap]);
                    logActivity($pdo, $_SESSION['username'] ?? $current_level, 'Edit Data Nilai Ujian', 'Mengubah rekap nilai ujian tahun ajaran ' . $tahun_ajaran);
                    $message = 'Data nilai ujian berhasil diperbarui.';
                }
            } elseif ($action === 'hapus') {
                $id_rekap = (int)($_POST['id_rekap_nilai_ujian'] ?? 0);
                if ($id_rekap <= 0) {
                    throw new RuntimeException('Data nilai ujian tidak valid.');
                }
                $stmt_info = $pdo->prepare('SELECT tahun_ajaran FROM tb_rekap_nilai_ujian WHERE id_rekap_nilai_ujian = ?');
                $stmt_info->execute([$id_rekap]);
                $tahun_ajaran = (string)($stmt_info->fetchColumn() ?: '');

                $stmt = $pdo->prepare('DELETE FROM tb_rekap_nilai_ujian WHERE id_rekap_nilai_ujian = ?');
                $stmt->execute([$id_rekap]);
                logActivity($pdo, $_SESSION['username'] ?? $current_level, 'Hapus Data Nilai Ujian', 'Menghapus rekap nilai ujian tahun ajaran ' . $tahun_ajaran);
                $message = 'Data nilai ujian berhasil dihapus.';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'Tahun ajaran tersebut sudah ada.';
            } else {
                error_log('data_nilai_ujian POST: ' . $e->getMessage());
                $error = 'Gagal menyimpan data nilai ujian.';
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$stmt = $pdo->query('SELECT * FROM tb_rekap_nilai_ujian ORDER BY tahun_ajaran ASC');
$nilai_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = [];
$chart_terendah = [];
$chart_tertinggi = [];
foreach ($nilai_data as $data) {
    $chart_labels[] = $data['tahun_ajaran'];
    $chart_terendah[] = round((float)$data['nilai_terendah'], 2);
    $chart_tertinggi[] = round((float)$data['nilai_tertinggi'], 2);
}

$js_libs = [
    'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'
];

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Grafik Nilai Ujian Per Tahun Ajaran</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-success btn-sm" onclick="exportChartToPDF()">
                                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div style="height: 400px; position: relative;">
                                <canvas id="nilaiUjianChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Tabel Data Nilai Ujian</h4>
                            <div class="card-header-action">
                                <?php if ($can_crud): ?>
                                    <button type="button" class="btn btn-primary btn-sm mr-2" data-toggle="modal" data-target="#modalTambahNilai">
                                        <i class="fas fa-plus"></i> Tambah
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-success btn-sm mr-2" onclick="exportTableToExcel()">
                                    <i class="fas fa-file-excel"></i> Ekspor Excel
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" onclick="exportTableToPDF()">
                                    <i class="fas fa-file-pdf"></i> Ekspor PDF
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="table-nilai-ujian">
                                    <thead>
                                        <tr>
                                            <th class="text-center" style="width: 60px;">No</th>
                                            <th>Tahun Ajaran</th>
                                            <th class="text-center">Nilai Terendah</th>
                                            <th class="text-center">Nilai Tertinggi</th>
                                            <?php if ($can_crud): ?>
                                                <th class="text-center" style="width: 130px;">Aksi</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($nilai_data)): ?>
                                            <tr>
                                                <td colspan="<?= $can_crud ? 5 : 4 ?>" class="text-center text-muted py-4">Belum ada data nilai ujian</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php $no = 1; foreach ($nilai_data as $data): ?>
                                                <tr>
                                                    <td class="text-center"><?= $no++ ?></td>
                                                    <td><?= htmlspecialchars($data['tahun_ajaran']) ?></td>
                                                    <td class="text-center"><span class="badge badge-danger"><?= number_format((float)$data['nilai_terendah'], 2) ?></span></td>
                                                    <td class="text-center"><span class="badge badge-success"><?= number_format((float)$data['nilai_tertinggi'], 2) ?></span></td>
                                                    <?php if ($can_crud): ?>
                                                        <td class="text-center">
                                                            <button type="button" class="btn btn-warning btn-sm btn-edit-rekap" data-toggle="modal" data-target="#modalEditNilai"
                                                                data-id="<?= (int)$data['id_rekap_nilai_ujian'] ?>"
                                                                data-tahun-ajaran="<?= htmlspecialchars($data['tahun_ajaran'], ENT_QUOTES) ?>"
                                                                data-nilai-terendah="<?= htmlspecialchars($data['nilai_terendah'], ENT_QUOTES) ?>"
                                                                data-nilai-tertinggi="<?= htmlspecialchars($data['nilai_tertinggi'], ENT_QUOTES) ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data nilai ujian ini?')">
                                                                <input type="hidden" name="action" value="hapus">
                                                                <input type="hidden" name="id_rekap_nilai_ujian" value="<?= (int)$data['id_rekap_nilai_ujian'] ?>">
                                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                                            </form>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div id="exportTableContainer" style="display:none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>No</th>
                                            <th>Tahun Ajaran</th>
                                            <th>Nilai Terendah</th>
                                            <th>Nilai Tertinggi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; foreach ($nilai_data as $data): ?>
                                            <tr>
                                                <td><?= $no++ ?></td>
                                                <td><?= htmlspecialchars($data['tahun_ajaran']) ?></td>
                                                <td><?= number_format((float)$data['nilai_terendah'], 2) ?></td>
                                                <td><?= number_format((float)$data['nilai_tertinggi'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php if ($can_crud): ?>
<div class="modal fade" id="modalTambahNilai" tabindex="-1" role="dialog" aria-labelledby="modalTambahNilaiLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="tambah">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTambahNilaiLabel">Tambah Nilai Ujian</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Tahun Ajaran</label>
                        <input type="text" name="tahun_ajaran" class="form-control" value="<?= htmlspecialchars($selected_ta) ?>" placeholder="Tahun Ajaran" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Nilai Terendah</label>
                        <input type="number" name="nilai_terendah" class="form-control" min="0" max="100" step="0.01" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Nilai Tertinggi</label>
                        <input type="number" name="nilai_tertinggi" class="form-control" min="0" max="100" step="0.01" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEditNilai" tabindex="-1" role="dialog" aria-labelledby="modalEditNilaiLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id_rekap_nilai_ujian" id="edit_id_rekap_nilai_ujian">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditNilaiLabel">Edit Nilai Ujian</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Tahun Ajaran</label>
                        <input type="text" name="tahun_ajaran" id="edit_tahun_ajaran" class="form-control" placeholder="2025/2026" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Nilai Terendah</label>
                        <input type="number" name="nilai_terendah" id="edit_nilai_terendah" class="form-control" min="0" max="100" step="0.01" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Nilai Tertinggi</label>
                        <input type="number" name="nilai_tertinggi" id="edit_nilai_tertinggi" class="form-control" min="0" max="100" step="0.01" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
var pageMessage = <?= json_encode($message) ?>;
var pageError = <?= json_encode($error) ?>;
var reportProfile = {
    name: <?= json_encode($school_name) ?>,
    foundation: <?= json_encode($school_foundation) ?>,
    address: <?= json_encode($school_address) ?>,
    email: <?= json_encode($school_email) ?>,
    website: <?= json_encode($school_website) ?>,
    logo: <?= json_encode('../assets/img/' . $school_logo) ?>,
    headName: <?= json_encode($kepala_madrasah) ?>,
    headNip: <?= json_encode($nip_kepala) ?>,
    signPlace: <?= json_encode($tempat_ttd) ?>,
    signDate: <?= json_encode($tanggal_ttd) ?>
};
var nilaiUjianSummary = <?= json_encode(array_map(static function ($data) {
    $nilaiTerendah = round((float)$data['nilai_terendah'], 2);
    $nilaiTertinggi = round((float)$data['nilai_tertinggi'], 2);
    return [
        'tahun_ajaran' => (string)$data['tahun_ajaran'],
        'nilai_terendah' => $nilaiTerendah,
        'nilai_tertinggi' => $nilaiTertinggi,
        'rentang' => round($nilaiTertinggi - $nilaiTerendah, 2),
    ];
}, $nilai_data)) ?>;
var nilaiUjianChartInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('nilaiUjianChart').getContext('2d');
    nilaiUjianChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'Nilai Terendah',
                    data: <?= json_encode($chart_terendah) ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.8)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Nilai Tertinggi',
                    data: <?= json_encode($chart_tertinggi) ?>,
                    backgroundColor: 'rgba(75, 192, 192, 0.8)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                onComplete: function() {
                    window.nilaiUjianChartReady = true;
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: { stepSize: 10 }
                }
            },
            plugins: {
                legend: { position: 'top' }
            }
        }
    });

    document.querySelectorAll('.btn-edit-rekap').forEach(function(button) {
        button.addEventListener('click', function() {
            document.getElementById('edit_id_rekap_nilai_ujian').value = this.dataset.id || '';
            document.getElementById('edit_tahun_ajaran').value = this.dataset.tahunAjaran || '';
            document.getElementById('edit_nilai_terendah').value = this.dataset.nilaiTerendah || '';
            document.getElementById('edit_nilai_tertinggi').value = this.dataset.nilaiTertinggi || '';
        });
    });
});

window.addEventListener('load', function() {
    if (pageMessage && typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'success',
            title: 'Berhasil',
            text: pageMessage,
            timer: 1600,
            showConfirmButton: false,
            timerProgressBar: true
        });
    }
    if (pageError && typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: pageError,
            timer: 2200,
            showConfirmButton: false,
            timerProgressBar: true
        });
    }
});

function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function(char) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char];
    });
}

function reportLogoUrl() {
    try {
        return new URL(reportProfile.logo, window.location.href).href;
    } catch (e) {
        return reportProfile.logo;
    }
}

function reportKopHtml() {
    var contact = [];
    if (reportProfile.email) contact.push('Email: ' + escapeHtml(reportProfile.email));
    if (reportProfile.website) contact.push('Website: ' + escapeHtml(reportProfile.website));
    return '' +
        '<div class="report-kop">' +
            '<div class="report-logo"><img src="' + reportLogoUrl() + '" alt="Logo"></div>' +
            '<div class="report-school">' +
                (reportProfile.foundation ? '<div class="report-foundation">' + escapeHtml(reportProfile.foundation).toUpperCase() + '</div>' : '') +
                '<div class="report-name">' + escapeHtml(reportProfile.name).toUpperCase() + '</div>' +
                (reportProfile.address ? '<div class="report-line">' + escapeHtml(reportProfile.address) + '</div>' : '') +
                (contact.length ? '<div class="report-line">' + contact.join(' &nbsp; ') + '</div>' : '') +
            '</div>' +
        '</div>' +
        '<div class="report-divider"></div>';
}

function qrCodeUrl(text) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=8&data=' + encodeURIComponent(text || '-');
}

function reportSignatureHtml() {
    var headName = reportProfile.headName || 'Kepala Madrasah';
    var qrPayload = headName;
    return '' +
        '<div class="signature-row">' +
            '<div class="signature-box">' +
                '<div>' + escapeHtml(reportProfile.signPlace || '') + ', ' + escapeHtml(reportProfile.signDate || '') + '</div>' +
                '<div>Kepala Madrasah</div>' +
                '<div class="signature-qr"><img src="' + qrCodeUrl(qrPayload) + '" alt="QR Kepala Madrasah"></div>' +
                '<div class="signature-name">' + escapeHtml(headName) + '</div>' +
                (reportProfile.headNip ? '<div class="signature-nip">NIP. ' + escapeHtml(reportProfile.headNip) + '</div>' : '') +
            '</div>' +
        '</div>';
}

function reportChartSummaryHtml() {
    var rows = nilaiUjianSummary || [];
    if (!rows.length) {
        return '<div class="summary-empty">Belum ada data nilai ujian.</div>';
    }

    var totalMin = 0;
    var totalMax = 0;
    var lowest = rows[0];
    var highest = rows[0];
    var body = rows.map(function(row, index) {
        var minVal = Number(row.nilai_terendah || 0);
        var maxVal = Number(row.nilai_tertinggi || 0);
        totalMin += minVal;
        totalMax += maxVal;
        if (minVal < Number(lowest.nilai_terendah || 0)) lowest = row;
        if (maxVal > Number(highest.nilai_tertinggi || 0)) highest = row;
        return '<tr>' +
            '<td class="text-center">' + (index + 1) + '</td>' +
            '<td>' + escapeHtml(row.tahun_ajaran) + '</td>' +
            '<td class="text-center">' + minVal.toFixed(2) + '</td>' +
            '<td class="text-center">' + maxVal.toFixed(2) + '</td>' +
            '<td class="text-center">' + Number(row.rentang || (maxVal - minVal)).toFixed(2) + '</td>' +
        '</tr>';
    }).join('');

    var avgMin = totalMin / rows.length;
    var avgMax = totalMax / rows.length;
    return '' +
        '<div class="chart-summary">' +
            '<h3>Ringkasan Data Nilai</h3>' +
            '<table class="summary-table">' +
                '<thead><tr><th>No</th><th>Tahun Ajaran</th><th>Nilai Terendah</th><th>Nilai Tertinggi</th><th>Rentang</th></tr></thead>' +
                '<tbody>' + body + '</tbody>' +
                '<tfoot><tr><th colspan="2">Rata-rata</th><th>' + avgMin.toFixed(2) + '</th><th>' + avgMax.toFixed(2) + '</th><th>' + (avgMax - avgMin).toFixed(2) + '</th></tr></tfoot>' +
            '</table>' +
            '<div class="summary-note">Nilai terendah keseluruhan: <strong>' + Number(lowest.nilai_terendah || 0).toFixed(2) + '</strong> pada tahun ajaran <strong>' + escapeHtml(lowest.tahun_ajaran) + '</strong>. ' +
            'Nilai tertinggi keseluruhan: <strong>' + Number(highest.nilai_tertinggi || 0).toFixed(2) + '</strong> pada tahun ajaran <strong>' + escapeHtml(highest.tahun_ajaran) + '</strong>.</div>' +
        '</div>';
}

function reportPrintStyles() {
    return '' +
        'body{font-family:Arial,sans-serif;color:#111;margin:22px;}' +
        '.report-kop{display:flex;align-items:center;margin-bottom:8px;}' +
        '.report-logo{width:92px;text-align:center;flex:0 0 92px;}' +
        '.report-logo img{max-width:78px;max-height:86px;object-fit:contain;}' +
        '.report-school{text-align:center;flex:1;padding-right:92px;}' +
        '.report-foundation{font-size:14px;font-weight:600;}' +
        '.report-name{font-size:20px;font-weight:700;margin-top:2px;}' +
        '.report-line{font-size:12px;margin-top:2px;}' +
        '.report-divider{border-top:3px solid #111;border-bottom:1px solid #111;height:4px;margin:8px 0 18px;}' +
        'h2{text-align:center;font-size:16px;margin:0 0 14px;text-transform:uppercase;}' +
        'table{width:100%;border-collapse:collapse;font-size:12px;}' +
        'th,td{border:1px solid #444;padding:7px;text-align:left;}' +
        'th{background:#f2f2f2;text-align:center;}' +
        '.text-center{text-align:center;}' +
        '.chart-wrap{text-align:center;margin-top:10px;}' +
        '.chart-wrap img{max-width:100%;height:auto;}' +
        '.chart-summary{margin-top:16px;page-break-inside:avoid;}' +
        '.chart-summary h3{font-size:13px;text-transform:uppercase;margin:0 0 8px;text-align:left;}' +
        '.summary-table{font-size:11.5px;}' +
        '.summary-table tfoot th{background:#fafafa;text-align:center;}' +
        '.summary-note{font-size:12px;line-height:1.45;margin-top:8px;text-align:justify;}' +
        '.summary-empty{text-align:center;color:#666;margin-top:16px;font-size:12px;}' +
        '.signature-row{display:flex;justify-content:flex-end;margin-top:28px;page-break-inside:avoid;}' +
        '.signature-box{width:250px;text-align:center;font-size:12px;}' +
        '.signature-qr{height:96px;margin:8px 0 4px;display:flex;align-items:center;justify-content:center;}' +
        '.signature-qr img{width:86px;height:86px;object-fit:contain;}' +
        '.signature-name{font-weight:700;text-decoration:underline;margin-top:2px;}' +
        '.signature-nip{margin-top:2px;}' +
        '@media print{body{margin:12mm;}.no-print{display:none;}}';
}

function exportTableToExcel() {
    var table = document.querySelector('#exportTableContainer table').outerHTML;
    var html = '<html><head><meta charset="UTF-8"></head><body>' + table + '</body></html>';
    var blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'data_nilai_ujian.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function exportTableToPDF() {
    var printWindow = window.open('', '_blank');
    printWindow.document.write('<html><head><title>Data Nilai Ujian</title>');
    printWindow.document.write('<style>' + reportPrintStyles() + '</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write(reportKopHtml());
    printWindow.document.write('<h2>Data Nilai Ujian</h2>');
    printWindow.document.write(document.querySelector('#exportTableContainer').innerHTML);
    printWindow.document.write(reportSignatureHtml());
    printWindow.document.write('<script>window.onload=function(){setTimeout(function(){window.print();},250);};<\/script>');
    printWindow.document.write('</body></html>');
    printWindow.document.close();
}

function exportChartToPDF() {
    var canvas = document.getElementById('nilaiUjianChart');
    if (nilaiUjianChartInstance) {
        nilaiUjianChartInstance.resize();
        nilaiUjianChartInstance.update('none');
    }
    setTimeout(function() {
        var imgData = canvas.toDataURL('image/png');
        var printWindow = window.open('', '_blank');
        printWindow.document.write('<html><head><title>Grafik Nilai Ujian</title>');
        printWindow.document.write('<style>' + reportPrintStyles() + '</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(reportKopHtml());
        printWindow.document.write('<h2>Grafik Nilai Ujian Per Tahun Ajaran</h2>');
        printWindow.document.write('<div class="chart-wrap"><img id="chartImage" src="' + imgData + '" alt="Grafik Nilai Ujian"></div>');
        printWindow.document.write(reportChartSummaryHtml());
        printWindow.document.write(reportSignatureHtml());
        printWindow.document.write('<script>window.onload=function(){var img=document.getElementById("chartImage");function p(){setTimeout(function(){window.print();},250);} if(img&& !img.complete){img.onload=p; img.onerror=p;} else {p();}};<\/script>');
        printWindow.document.write('</body></html>');
        printWindow.document.close();
    }, 250);
}
</script>

<?php require_once '../templates/footer.php'; ?>
