<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/supervisi.php';

sv_ensure_schema($pdo);
sv_require_access($pdo);

$id = (int)($_GET['id'] ?? 0);
$data = sv_detail_pelaksanaan($pdo, $id);
if (!$data) {
    sv_flash('warning', 'Data supervisi tidak ditemukan.');
    redirect('hasil_supervisi.php');
}

$from = trim((string)($_GET['from'] ?? ''));
$page_title = 'Detail Hasil Supervisi';
$current_page = basename(__FILE__);

$school_profile = getSchoolProfile($pdo);
$periode = sv_periode($pdo);

$penilaian = sv_penilaian_rows($pdo, $id);
$manajerial = sv_manajerial_rows($pdo, $id);
$tindak_lanjut = sv_tindak_lanjut_by_pelaksanaan($pdo, $id);
$arsip = sv_arsip_by_pelaksanaan($pdo, $id);

$is_manajerial = ($data['jenis_supervisi'] === 'Manajerial');
$nama = $is_manajerial ? ($data['unit_bagian'] ?: '-') : ($data['nama_guru'] ?: '-');
$guruInfo = null;
if (!$is_manajerial && !empty($data['id_guru'])) {
    try {
        $st = $pdo->prepare("SELECT id_guru, nama_guru, nuptk, jenis_kelamin, pendidikan FROM tb_guru WHERE id_guru = ? LIMIT 1");
        $st->execute([(int)$data['id_guru']]);
        $guruInfo = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $guruInfo = null; }
}
$sasaranRow = null;
if (!$is_manajerial) {
    try {
        $st = $pdo->prepare("SELECT jabatan, kelas FROM tb_sv_sasaran WHERE id_guru = ? ORDER BY id_sasaran DESC LIMIT 1");
        $st->execute([(int)($data['id_guru'] ?? 0)]);
        $sasaranRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $sasaranRow = null; }
}
$tempatCetak = trim((string)($school_profile['tempat_jadwal'] ?? 'Padang'));
if ($tempatCetak === '') $tempatCetak = 'Padang';
$monthsId = ['January'=>'Januari','February'=>'Februari','March'=>'Maret','April'=>'April','May'=>'Mei','June'=>'Juni','July'=>'Juli','August'=>'Agustus','September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Desember'];
$tglCetak = date('d') . ' ' . ($monthsId[date('F')] ?? date('F')) . ' ' . date('Y');
$tempatTanggalCetak = $tempatCetak . ', ' . $tglCetak;
$namaKepala = trim((string)($school_profile['nama_kepala'] ?? '-'));
$nipKepala = trim((string)($school_profile['nip_kepala'] ?? '-'));
$qrGuruUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode('Ditandatangani oleh: ' . $nama . ' | ' . $tempatTanggalCetak);
$qrKepalaUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=' . urlencode('Ditandatangani oleh: ' . $namaKepala . ' | Kepala Madrasah | ' . $tempatTanggalCetak);

$js_libs = [

    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
];
$js_page = [];
$detailId = $id;
$js_page[] = 'var svDetailId=' . (int)$detailId . ';';
$js_page[] = <<<'JS'
$(document).ready(function () {
    $('#btn-pdf').on('click', function(){ window.open('cetak_detail_hasil_supervisi.php?id='+svDetailId, '_blank'); });
});
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Detail Hasil Supervisi</h1>
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

            <div class="mb-3">
                <?php if ($from === 'laporan'): ?>
                <a href="laporan_supervisi.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Kembali ke Laporan</a>
                <?php else: ?>
                <a href="hasil_supervisi.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Kembali</a>
                <?php endif; ?>
                <button class="btn btn-warning" id="btn-pdf" type="button"><i class="fas fa-file-pdf"></i> Cetak / PDF</button>
            </div>

            <div class="row">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h4>Identitas Guru yang Disupervisi</h4></div>
                        <div class="card-body">
                            <table class="table table-sm table-borderless mb-0" id="sv-identitas-print">
                                <tr><th width="42%">Nama Guru</th><td><?= htmlspecialchars($nama) ?></td></tr>
                                <tr><th>NIP/NPK</th><td><?= htmlspecialchars((string)($guruInfo['nuptk'] ?? $data['nama_guru'] ?? '-') ?: '-') ?></td></tr>
                                <tr><th>Jabatan</th><td><?= htmlspecialchars((string)($sasaranRow['jabatan'] ?? '-')) ?></td></tr>
                                <tr><th>Mapel yang Disupervisi</th><td><?= htmlspecialchars((string)($data['mapel_di_supervisi'] ?? '-')) ?></td></tr>
                                <tr><th>Kelas</th><td><?= htmlspecialchars((string)($sasaranRow['kelas'] ?? '-')) ?></td></tr>
                                <?php if ($is_manajerial): ?>
                                    <tr><th>Unit/Bagian</th><td><?= htmlspecialchars((string)$data['unit_bagian']) ?></td></tr>
                                    <tr><th>Penanggung Jawab</th><td><?= htmlspecialchars((string)$data['penanggung_jawab']) ?></td></tr>
                                <?php endif; ?>
                                <tr><th>Jenis Supervisi</th><td><span class="badge badge-info"><?= htmlspecialchars($data['jenis_supervisi']) ?></span></td></tr>
                                <tr><th>Tanggal Supervisi</th><td><?= $data['tanggal'] ? date('d F Y', strtotime($data['tanggal'])) : '-' ?></td></tr>
                                <tr><th>Supervisor</th><td><?= htmlspecialchars((string)$data['supervisor']) ?></td></tr>
                                <tr><th>Instrumen</th><td><?= htmlspecialchars((string)($data['nama_instrumen'] ?: $data['kode_instrumen'] ?? '-')) ?></td></tr>
                                <tr><th>Nilai</th><td><strong><?= $data['nilai'] !== null ? htmlspecialchars(number_format((float)$data['nilai'], 2)) : '-' ?></strong> (<?= htmlspecialchars((string)$data['predikat'] ?: '-') ?>)</td></tr>
                                <tr><th>Predikat</th><td><?= htmlspecialchars((string)$data['predikat']) ?></td></tr>
                                <tr><th>Status</th><td><span class="badge badge-<?= $data['status'] === 'Selesai' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($data['status']) ?></span></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header"><h4>Uraian Hasil</h4></div>
                        <div class="card-body">
                            <p><strong>Kekuatan:</strong><br><?= nl2br(htmlspecialchars((string)$data['kekuatan'])) ?: '-' ?></p>
                            <p><strong>Kelemahan:</strong><br><?= nl2br(htmlspecialchars((string)$data['kelemahan'])) ?: '-' ?></p>
                            <p><strong>Rekomendasi:</strong><br><?= nl2br(htmlspecialchars((string)$data['rekomendasi'])) ?: '-' ?></p>
                            <p class="mb-0"><strong>Prioritas Perbaikan:</strong> <?= htmlspecialchars((string)$data['prioritas_perbaikan']) ?: '-' ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h4>Seluruh Indikator</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" id="table-detail">
                            <thead>
                                <tr>
                                    <th width="5%">No</th>
                                    <th>Komponen</th>
                                    <th>Indikator</th>
                                    <th>Bobot</th>
                                    <th>Skor</th>
                                    <th>Nilai</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($is_manajerial): ?>
                                    <?php foreach ($manajerial as $i => $m): ?>
                                        <tr>
                                            <td class="text-center"><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars((string)$m['unit_bagian']) ?></td>
                                            <td><?= htmlspecialchars((string)$m['indikator']) ?></td>
                                            <td>-</td>
                                            <td><?= htmlspecialchars(number_format((float)$m['skor'], 2)) ?></td>
                                            <td><?= htmlspecialchars(number_format((float)$m['skor'], 2)) ?></td>
                                            <td>
                                                <?php if ($m['target'] !== '') echo '<small>Target: ' . htmlspecialchars((string)$m['target']) . '</small><br>'; ?>
                                                <?php if ($m['realisasi'] !== '') echo '<small>Realisasi: ' . htmlspecialchars((string)$m['realisasi']) . '</small><br>'; ?>
                                                <?php if ($m['temuan'] !== '') echo '<small>Temuan: ' . htmlspecialchars((string)$m['temuan']) . '</small><br>'; ?>
                                                <?php if ($m['kendala'] !== '') echo '<small>Kendala: ' . htmlspecialchars((string)$m['kendala']) . '</small><br>'; ?>
                                                <?php if ($m['rekomendasi'] !== '') echo '<small>Rekomendasi: ' . htmlspecialchars((string)$m['rekomendasi']) . '</small>'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php elseif ($penilaian): ?>
                                    <?php foreach ($penilaian as $i => $p): ?>
                                        <tr>
                                            <td class="text-center"><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars((string)$p['komponen']) ?></td>
                                            <td><?= htmlspecialchars((string)$p['indikator']) ?></td>
                                            <td><?= htmlspecialchars(number_format((float)$p['bobot'], 2)) ?></td>
                                            <td><?= htmlspecialchars(number_format((float)$p['skor'], 2)) ?></td>
                                            <td><?= htmlspecialchars(number_format((float)$p['nilai'], 2)) ?></td>
                                            <td><?= htmlspecialchars((string)$p['catatan']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center text-muted">Tidak ada detail penilaian.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Tindak Lanjut</h4></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Bentuk</th><th>Rencana</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php if ($tindak_lanjut): foreach ($tindak_lanjut as $t): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$t['bentuk_tindak_lanjut']) ?></td>
                                            <td><?= htmlspecialchars((string)$t['rencana_tindakan']) ?></td>
                                            <td><span class="badge badge-info"><?= htmlspecialchars($t['status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="3" class="text-center text-muted">Belum ada tindak lanjut.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><h4>Arsip / Bukti</h4></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead><tr><th>Jenis</th><th>Nama Dokumen</th><th>File</th></tr></thead>
                                    <tbody>
                                    <?php if ($arsip): foreach ($arsip as $a): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)$a['jenis_dokumen']) ?></td>
                                            <td><?= htmlspecialchars((string)$a['nama_dokumen']) ?></td>
                                            <td><?php if (!empty($a['file'])): ?><a href="<?= htmlspecialchars(sv_upload_url($a['file']), ENT_QUOTES) ?>" target="_blank">Lihat</a><?php else: ?>-<?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="3" class="text-center text-muted">Belum ada arsip.</td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <div class="card">
                <div class="card-body text-right">
                    <p class="mb-1"><?= htmlspecialchars($tempatTanggalCetak) ?></p>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6">
                            <p class="mb-1">Yang Disupervisi,</p>
                            <img src="<?= htmlspecialchars($qrGuruUrl) ?>" style="width:90px;height:90px" alt="QR Guru">
                            <p class="mb-0 mt-1"><strong><?= htmlspecialchars($nama) ?></strong></p>
                        </div>
                        <div class="col-6">
                            <p class="mb-1">Kepala Madrasah,</p>
                            <img src="<?= htmlspecialchars($qrKepalaUrl) ?>" style="width:90px;height:90px" alt="QR Kepala">
                            <p class="mb-0 mt-1"><strong><?= htmlspecialchars($namaKepala) ?></strong></p>
                            <small>NIP. <?= htmlspecialchars($nipKepala) ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php include '../templates/footer.php'; ?>
