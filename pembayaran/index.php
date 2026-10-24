<?php
$title = 'Ringkasan Pembayaran';
$pembayaran_dashboard_home = true;
include __DIR__ . '/../templates/pembayaran_header.php';

function dashboard_kelas_sort_key($nama_kelas) {
    $nama = strtoupper(trim((string) $nama_kelas));
    if (strpos($nama, 'ALUMNI') !== false) {
        return 99;
    }

    $roman_map = [
        'I' => 1,
        'II' => 2,
        'III' => 3,
        'IV' => 4,
        'V' => 5,
        'VI' => 6,
    ];

    if (isset($roman_map[$nama])) {
        return $roman_map[$nama];
    }

    if (preg_match('/\b([1-6])\b/', $nama, $m) || preg_match('/([1-6])/', $nama, $m)) {
        return (int) $m[1];
    }

    return 50;
}

$tahun_ini = date('Y');
$tahun_ajaran_aktif_dashboard = get_tahun_ajaran_aktif($koneksi);
$tahun_ajaran_sebelumnya_dashboard = tahun_ajaran_sebelumnya($tahun_ajaran_aktif_dashboard);
$tahun_ajaran_aktif_dashboard_esc = mysqli_real_escape_string($koneksi, $tahun_ajaran_aktif_dashboard);

// 1. Hitung Jumlah Siswa aktif. Kelas Alumni hanya alat bantu tagihan, bukan siswa aktif.
$q_siswa = mysqli_query($koneksi, "
    SELECT COUNT(*) as total
    FROM siswa s
    JOIN kelas k ON s.id_kelas = k.id_kelas
    WHERE LOWER(k.nama_kelas) NOT LIKE '%alumni%'
");
$d_siswa = mysqli_fetch_assoc($q_siswa);
$jml_siswa = $d_siswa['total'];

// 2. Hitung Jumlah Jenis Bayar (Aktif)
$q_jenis = mysqli_query($koneksi, "SELECT COUNT(*) as total FROM jenis_bayar WHERE status = 'Aktif'");
$d_jenis = mysqli_fetch_assoc($q_jenis);
$jml_jenis = $d_jenis['total'];

// 3. Hitung Total Pembayaran tahun ajaran aktif
$q_bayar = mysqli_query($koneksi, "SELECT SUM(jumlah_bayar) as total FROM pembayaran WHERE tahun_ajaran = '$tahun_ajaran_aktif_dashboard_esc'");
$d_bayar = mysqli_fetch_assoc($q_bayar);
$total_bayar = $d_bayar['total'] ?? 0;

// 3b. Total bayar per jenis tahun ajaran aktif (dinamis, mengikuti jenis bayar aktif)
$jenis_totals = [];
$q_jenis_total = mysqli_query($koneksi, "
    SELECT
        jb.id_jenis_bayar,
        jb.nama_pembayaran,
        COALESCE(SUM(p.jumlah_bayar), 0) AS total_bayar_jenis
    FROM jenis_bayar jb
    LEFT JOIN pembayaran p ON p.id_jenis_bayar = jb.id_jenis_bayar AND p.tahun_ajaran = '$tahun_ajaran_aktif_dashboard_esc'
    WHERE jb.status = 'Aktif'
    GROUP BY jb.id_jenis_bayar, jb.nama_pembayaran
    ORDER BY
        CASE
            WHEN LOWER(jb.nama_pembayaran) LIKE '%ekstrakurikuler%' THEN 1
            WHEN LOWER(jb.nama_pembayaran) LIKE '%lks%' THEN 2
            WHEN LOWER(jb.nama_pembayaran) LIKE '%ujian%' THEN 3
            WHEN LOWER(jb.nama_pembayaran) LIKE '%rekreasi%' THEN 4
            ELSE 5
        END,
        jb.nama_pembayaran ASC
");
while ($row = mysqli_fetch_assoc($q_jenis_total)) {
    $jenis_totals[] = $row;
}

$total_tagihan_aktif = 0;
$total_tunggakan = 0;
$kelas_tunggakan = [];
$q_kelas_dashboard = mysqli_query($koneksi, "SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas ASC");
while ($kelas_dashboard = mysqli_fetch_assoc($q_kelas_dashboard)) {
    $id_kelas_dashboard = (int) $kelas_dashboard['id_kelas'];
    $kelas_tunggakan[$id_kelas_dashboard] = [
        'id_kelas' => $id_kelas_dashboard,
        'nama_kelas' => $kelas_dashboard['nama_kelas'],
        'total_siswa' => 0,
        'siswa_tunggakan' => 0,
        'total_tagihan' => 0,
        'total_tagihan_aktif' => 0,
        'siswa' => [],
    ];
}

$q_siswa_tagihan = mysqli_query($koneksi, "
    SELECT s.nisn, s.nama, k.id_kelas, k.nama_kelas
    FROM siswa s
    JOIN kelas k ON s.id_kelas = k.id_kelas
    ORDER BY k.nama_kelas ASC, s.nama ASC
");
while ($siswa_tagihan = mysqli_fetch_assoc($q_siswa_tagihan)) {
    $id_kelas_dashboard = (int) $siswa_tagihan['id_kelas'];
    if (!isset($kelas_tunggakan[$id_kelas_dashboard])) {
        $kelas_tunggakan[$id_kelas_dashboard] = [
            'id_kelas' => $id_kelas_dashboard,
            'nama_kelas' => $siswa_tagihan['nama_kelas'],
            'total_siswa' => 0,
            'siswa_tunggakan' => 0,
            'total_tagihan' => 0,
            'total_tagihan_aktif' => 0,
            'siswa' => [],
        ];
    }

    $kelas_tunggakan[$id_kelas_dashboard]['total_siswa']++;

    // Tagihan tahun aktif
    $tagihan_aktif = cek_tagihan_tunggakan($koneksi, $siswa_tagihan['nisn'], $tahun_ajaran_aktif_dashboard);
    if ($tagihan_aktif) {
        $total_siswa_aktif = 0;
        foreach ($tagihan_aktif as $item) {
            $total_siswa_aktif += (int) ($item['sisa'] ?? 0);
        }
        $total_tagihan_aktif += $total_siswa_aktif;
        $kelas_tunggakan[$id_kelas_dashboard]['total_tagihan_aktif'] += $total_siswa_aktif;
    }

    // Tunggakan tahun lampau
    $tagihan_siswa_per_tahun = cek_tunggakan_tahun_ajaran_lama($koneksi, $siswa_tagihan['nisn'], $tahun_ajaran_aktif_dashboard);
    if (!$tagihan_siswa_per_tahun) {
        continue;
    }

    $total_tagihan_siswa = 0;
    $tahun_tunggakan_siswa = [];
    foreach ($tagihan_siswa_per_tahun as $tahun_tunggakan => $tagihan_siswa) {
        $tahun_tunggakan_siswa[] = $tahun_tunggakan;
        foreach ($tagihan_siswa as $item_tagihan) {
            $total_tagihan_siswa += (int) ($item_tagihan['sisa'] ?? 0);
        }
    }

    if ($total_tagihan_siswa <= 0) {
        continue;
    }

    $total_tunggakan += $total_tagihan_siswa;
    $kelas_tunggakan[$id_kelas_dashboard]['siswa_tunggakan']++;
    $kelas_tunggakan[$id_kelas_dashboard]['total_tagihan'] += $total_tagihan_siswa;
    $kelas_tunggakan[$id_kelas_dashboard]['siswa'][] = [
        'nisn' => $siswa_tagihan['nisn'],
        'nama' => $siswa_tagihan['nama'],
        'total_tagihan' => $total_tagihan_siswa,
        'tahun_ajaran' => implode(', ', array_unique($tahun_tunggakan_siswa)),
    ];
}

$kelas_tunggakan = array_values(array_filter($kelas_tunggakan, static function ($kelas) {
    return (int) $kelas['siswa_tunggakan'] > 0;
}));
usort($kelas_tunggakan, static function ($a, $b) {
    $sort_a = dashboard_kelas_sort_key($a['nama_kelas']);
    $sort_b = dashboard_kelas_sort_key($b['nama_kelas']);
    if ($sort_a === $sort_b) {
        return strnatcasecmp((string) $a['nama_kelas'], (string) $b['nama_kelas']);
    }
    return $sort_a <=> $sort_b;
});

// Data Grafik Pembayaran per Bulan (Tahun Ini)
$chart_labels = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
$chart_data = array_fill(0, 12, 0);
$start_year = $tahun_ini . '-01-01';
$next_year = ((int)$tahun_ini + 1) . '-01-01';
$q_chart = mysqli_query($koneksi, "SELECT MONTH(tgl_bayar) AS bulan, COALESCE(SUM(jumlah_bayar), 0) AS total FROM pembayaran WHERE tgl_bayar >= '$start_year' AND tgl_bayar < '$next_year' GROUP BY MONTH(tgl_bayar)");
while ($r = mysqli_fetch_assoc($q_chart)) {
    $idx = ((int)($r['bulan'] ?? 0)) - 1;
    if ($idx >= 0 && $idx < 12) {
        $chart_data[$idx] = $r['total'] ?? 0;
    }
}

?>

<style>
.pembayaran-stat-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 8px;
}
@media (min-width: 992px) {
    .pembayaran-stat-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}
.pembayaran-stat-grid .dash-stat-card {
    padding: 14px 16px;
    gap: 12px;
    align-items: center;
}
.pembayaran-stat-grid .dash-stat-body {
    min-width: 0;
    flex: 1 1 0%;
}
.pembayaran-stat-grid .dash-stat-value {
    white-space: nowrap !important;
    word-break: keep-all !important;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 1.25rem;
    line-height: 1.2;
}
@media (min-width: 1200px) and (max-width: 1439.98px) {
    .pembayaran-stat-grid .dash-stat-value {
        font-size: 1.1rem;
    }
}
@media (max-width: 575.98px) {
    .pembayaran-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .pembayaran-stat-grid .dash-stat-card {
        padding: 10px 10px;
        gap: 8px;
    }
    .pembayaran-stat-grid .dash-stat-ico {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        font-size: .85rem;
    }
    .pembayaran-stat-grid .dash-stat-label {
        font-size: .62rem;
    }
    .pembayaran-stat-grid .dash-stat-value {
        font-size: 0.95rem !important;
    }
    .pembayaran-stat-grid .dash-stat-sub span {
        font-size: .6rem;
    }
    .pembayaran-jenis-grid { gap: 10px; }
    .pembayaran-jenis { padding: 11px 12px; gap: 10px; }
    .pembayaran-jenis-ico { width: 36px; height: 36px; border-radius: 11px; font-size: .82rem; }
    .pembayaran-jenis-total { font-size: .8rem; }
}
</style>
<?php
// Pembayaran Jenis Grid Styles
?>
<style>
.pembayaran-class-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 12px;
}
.pembayaran-jenis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 12px;
}
.pembayaran-jenis {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fff;
    border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 16px;
    padding: 13px 14px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
    transition: transform .18s, box-shadow .18s, border-color .18s;
}
.pembayaran-jenis:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(15, 23, 42, .1); border-color: #bfdbfe; }
.pembayaran-jenis-ico {
    flex: 0 0 auto; width: 42px; height: 42px; border-radius: 13px;
    display: grid; place-items: center; font-size: .95rem;
}
.pembayaran-jenis-body { flex: 1 1 auto; min-width: 0; }
.pembayaran-jenis-nama {
    font-size: .84rem; font-weight: 800; color: #0f172a; line-height: 1.25;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.pembayaran-jenis-meta { font-size: .68rem; font-weight: 600; color: #94a3b8; }
.pembayaran-jenis-total { flex: 0 0 auto; font-size: .88rem; font-weight: 900; color: #0f172a; white-space: nowrap; }
.pembayaran-jenis.tint-blue   { background: #f0f7ff; border-color: rgba(59,130,246,.18); }
.pembayaran-jenis.tint-teal   { background: #f0fdff; border-color: rgba(6,182,212,.18); }
.pembayaran-jenis.tint-amber  { background: #fffdf0; border-color: rgba(245,158,11,.18); }
.pembayaran-jenis.tint-green  { background: #f3fdf5; border-color: rgba(34,197,94,.18); }
.pembayaran-jenis.tint-indigo { background: #f3f4ff; border-color: rgba(99,102,241,.18); }
.pembayaran-jenis.tint-violet { background: #f7f5ff; border-color: rgba(167,139,250,.18); }
</style>

<div class="dash-section-head">
    <div class="dsh-title"><span class="dsh-dot" style="background:#3b82f6;"></span>Statistik Pembayaran</div>
    <span class="dsh-date">TA <?= htmlspecialchars($tahun_ajaran_aktif_dashboard !== '' ? $tahun_ajaran_aktif_dashboard : '-') ?> &middot; <?= $tahun_ini ?></span>
</div>
<div class="pembayaran-stat-grid">
    <?php
    $summary_cards = [
        [
            'label' => 'Siswa Aktif',
            'value' => number_format((int) $jml_siswa, 0, ',', '.'),
            'sub' => 'Di luar kelas alumni',
            'icon' => 'fas fa-users',
            'tone' => 'blue',
        ],
        [
            'label' => 'Jenis Bayar',
            'value' => number_format((int) $jml_jenis, 0, ',', '.') . ' jenis',
            'sub' => 'Tagihan berjalan',
            'icon' => 'fas fa-tags',
            'tone' => 'teal',
        ],
        [
            'label' => 'Total Pembayaran',
            'value' => 'Rp ' . number_format((int) $total_bayar, 0, ',', '.'),
            'sub' => 'Tahun ajaran aktif',
            'icon' => 'fas fa-money-bill-wave',
            'tone' => 'green',
        ],
        [
            'label' => 'Total Tagihan',
            'value' => 'Rp ' . number_format((int) $total_tagihan_aktif, 0, ',', '.'),
            'sub' => 'Sisa tahun aktif',
            'icon' => 'fas fa-file-invoice-dollar',
            'tone' => 'amber',
        ],
    ];
    foreach ($summary_cards as $card) :
    ?>
    <div>
        <div class="dash-stat-card tint-<?= htmlspecialchars($card['tone'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="dash-stat-ico dsi-<?= htmlspecialchars($card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="<?= htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
            </div>
            <div class="dash-stat-body">
                <div class="dash-stat-label"><?= htmlspecialchars($card['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="dash-stat-value"><?= htmlspecialchars($card['value'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="dash-stat-sub"><span><?= htmlspecialchars($card['sub'], ENT_QUOTES, 'UTF-8') ?></span></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="dash-section-head mt-2">
    <div class="dsh-title"><span class="dsh-dot" style="background:#16a34a;"></span>Pembayaran per Jenis</div>
    <a href="<?= htmlspecialchars(base_url('laporan.php'), ENT_QUOTES, 'UTF-8') ?>" class="small font-weight-bold">Lihat laporan</a>
</div>
<?php if (empty($jenis_totals)) : ?>
    <div class="card mb-3">
        <div class="card-body text-center text-muted py-4">Belum ada jenis pembayaran aktif.</div>
    </div>
<?php else : ?>
    <?php
    $jenis_icons = [
        'ekstrakurikuler' => 'fas fa-futbol',
        'lks' => 'fas fa-book-open',
        'ujian' => 'fas fa-file-alt',
        'rekreasi' => 'fas fa-bus',
    ];
    $jenis_tones = ['green', 'blue', 'amber', 'violet'];
    ?>
    <div class="pembayaran-jenis-grid mb-3">
        <?php foreach ($jenis_totals as $jenis_index => $jenis_item) :
            $nama_jenis = (string) $jenis_item['nama_pembayaran'];
            $nama_jenis_tampil = preg_replace('/ekstrakurikuler/i', 'Ekskul', $nama_jenis);
            $nama_lower = strtolower($nama_jenis);
            $jenis_icon = 'fas fa-money-bill';
            foreach ($jenis_icons as $keyword => $mapped_icon) {
                if (strpos($nama_lower, $keyword) !== false) {
                    $jenis_icon = $mapped_icon;
                    break;
                }
            }
            $jenis_tone = $jenis_tones[$jenis_index % count($jenis_tones)];
        ?>
            <div class="pembayaran-jenis tint-<?= htmlspecialchars($jenis_tone, ENT_QUOTES, 'UTF-8') ?>">
                <span class="pembayaran-jenis-ico dsi-<?= htmlspecialchars($jenis_tone, ENT_QUOTES, 'UTF-8') ?>">
                    <i class="<?= htmlspecialchars($jenis_icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                </span>
                <div class="pembayaran-jenis-body">
                    <div class="pembayaran-jenis-nama" title="<?= htmlspecialchars($nama_jenis_tampil, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($nama_jenis_tampil, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="pembayaran-jenis-meta">Total dibayar</div>
                </div>
                <div class="pembayaran-jenis-total">Rp <?= number_format((int) $jenis_item['total_bayar_jenis'], 0, ',', '.') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="dash-section-head mt-2">
    <div class="dsh-title"><span class="dsh-dot" style="background:#f59e0b;"></span>Tunggakan per Kelas</div>
    <span class="badge badge-warning">Rp <?= number_format((int) $total_tunggakan, 0, ',', '.') ?></span>
</div>
<?php if (empty($kelas_tunggakan)) : ?>
    <div class="alert alert-success mb-3">
        <i class="fas fa-check-circle mr-1"></i> Belum ada tunggakan tahun ajaran lama.
    </div>
<?php else : ?>
    <div class="pembayaran-class-grid">
        <?php foreach ($kelas_tunggakan as $kelas_item) :
            $total_siswa_kelas = max(1, (int) $kelas_item['total_siswa']);
            $jumlah_tunggakan_kelas = (int) $kelas_item['siswa_tunggakan'];
            $persen_siswa_tunggakan = min(100, round(($jumlah_tunggakan_kelas / $total_siswa_kelas) * 100));
        ?>
        <div>
            <div class="card h-100 mb-0">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between mb-3" style="gap:10px;">
                        <div>
                            <div class="small text-muted font-weight-bold text-uppercase">Kelas</div>
                            <h5 class="mb-0 font-weight-bold"><?= htmlspecialchars($kelas_item['nama_kelas'], ENT_QUOTES, 'UTF-8') ?></h5>
                        </div>
                        <a href="<?= htmlspecialchars(base_url('tagihan.php?id_kelas=' . (int) $kelas_item['id_kelas']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light btn-sm" title="Buka tagihan kelas <?= htmlspecialchars($kelas_item['nama_kelas'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted font-weight-bold">Total tagihan</span>
                        <span class="font-weight-bold">Rp <?= number_format((int) $kelas_item['total_tagihan'], 0, ',', '.') ?></span>
                    </div>
                    <div class="d-flex justify-content-between small mb-2">
                        <span class="text-muted font-weight-bold">Siswa menunggak</span>
                        <span class="font-weight-bold"><?= number_format($jumlah_tunggakan_kelas, 0, ',', '.') ?> / <?= number_format((int) $kelas_item['total_siswa'], 0, ',', '.') ?></span>
                    </div>
                    <div class="progress" style="height:7px;">
                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $persen_siswa_tunggakan ?>%" aria-valuenow="<?= $persen_siswa_tunggakan ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="small text-muted mt-1"><?= $persen_siswa_tunggakan ?>% siswa kelas ini menunggak</div>

                    <details class="mt-3">
                        <summary class="small font-weight-bold text-primary" style="cursor:pointer;">Daftar siswa menunggak (<?= number_format($jumlah_tunggakan_kelas, 0, ',', '.') ?>)</summary>
                        <div class="list-group list-group-flush mt-2" style="max-height:240px;overflow:auto;">
                            <?php foreach ($kelas_item['siswa'] as $siswa_preview) : ?>
                            <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center" style="gap:10px;">
                                <div style="min-width:0;">
                                    <div class="font-weight-bold text-truncate"><?= htmlspecialchars($siswa_preview['nama'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted">NISN <?= htmlspecialchars($siswa_preview['nisn'], ENT_QUOTES, 'UTF-8') ?><?= $siswa_preview['tahun_ajaran'] !== '' ? ' &middot; ' . htmlspecialchars($siswa_preview['tahun_ajaran'], ENT_QUOTES, 'UTF-8') : '' ?></div>
                                </div>
                                <span class="badge badge-warning text-nowrap">Rp <?= number_format((int) $siswa_preview['total_tagihan'], 0, ',', '.') ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header bg-white">
        <h6 class="mb-0 font-weight-bold">Grafik Pembayaran Tahun <?= $tahun_ini ?></h6>
    </div>
    <div class="card-body">
        <div style="position:relative;height:280px;width:100%;">
            <canvas id="pembayaranChart"></canvas>
        </div>
    </div>
</div>

<?php
$chart_labels_json = json_encode($chart_labels);
$chart_data_json = json_encode($chart_data);
$dashboard_js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        return;
    }
    var canvas = document.getElementById('pembayaranChart');
    if (!canvas) {
        return;
    }
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: {$chart_labels_json},
            datasets: [{
                label: 'Total Pembayaran (Rp)',
                data: {$chart_data_json},
                backgroundColor: 'rgba(59, 130, 246, 0.16)',
                borderColor: '#3b82f6',
                borderRadius: 8,
                borderSkipped: false,
                borderWidth: 1,
                maxBarThickness: 42
            }]
        },
        options: {
            animation: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#475569',
                        font: { weight: '600' }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#e2e8f0' },
                    ticks: {
                        color: '#64748b',
                        callback: function (value) {
                            return 'Rp ' + value.toString().replace(/\\B(?=(\\d{3})+(?!\\d))/g, '.');
                        }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b' }
                }
            },
            responsive: true,
            maintainAspectRatio: false
        }
    });
});
JS;
$js_page = $js_page ?? [];
$js_page[] = $dashboard_js;
include __DIR__ . '/../templates/pembayaran_footer.php';
