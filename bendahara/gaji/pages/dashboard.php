<?php
require_once __DIR__ . '/../config/config.php';
if (!defined('GAJI_EMBED')) { header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_ringkasan.php'); exit(); }
$page_title = 'Ringkasan Gaji';

require_once __DIR__ . '/../config/summary.php';

$ringkasan = gaji_ringkasan_data($conn);
$stats = $ringkasan['stats'];
$periode_aktif = $ringkasan['periode_aktif'];
$jumlah_periode = $ringkasan['jumlah_periode'];
$periode_aktif_label = $ringkasan['periode_aktif_label'];
$chart_periods = $ringkasan['chart_periods'];
$chart_totals = $ringkasan['chart_totals'];
$chart_labels_json = json_encode($chart_periods);
$chart_data_json = json_encode($chart_totals);
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <div class="dashboard-heading">
                            <div>
                                <h1>Ringkasan Gaji</h1>
                                <p>Rekap gaji guru, honor dan insentif madrasah</p>
                            </div>
                        </div>
                    </div>

                    <div class="section-body">
                        <!-- Info periode aktif (dipindah dari header ke atas kartu) -->
                        <div class="dash-context-strip">
                            <div class="dcs-main">
                                <span class="dcs-ico"><i class="fas fa-calendar-alt"></i></span>
                                <span class="dcs-text">
                                    <span class="dcs-label">Periode Aktif</span>
                                    <strong class="dcs-value"><?php echo htmlspecialchars($periode_aktif_label); ?></strong>
                                </span>
                            </div>
                            <div class="dcs-meta">
                                <span class="dcs-chip dcs-chip-teal"><i class="fas fa-layer-group"></i> <?php echo (int) $jumlah_periode; ?> periode</span>
                                <span class="dcs-chip dcs-chip-indigo"><i class="fas fa-user-friends"></i> <?php echo number_format((int) ($stats['total_guru'] ?? 0), 0, ',', '.'); ?> guru</span>
                            </div>
                        </div>

                        <!-- Ringkasan Gaji & Honor -->
                        <div class="row dash-stat-row stack-mobile">
                            <?php foreach (gaji_ringkasan_cards($stats) as $gaji_card) : ?>
                                <div class="col-lg-3 col-md-6 col-sm-6 col-6">
                                    <div class="dash-stat-card tint-<?= htmlspecialchars($gaji_card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="dash-stat-ico dsi-<?= htmlspecialchars($gaji_card['tone'], ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fas <?= htmlspecialchars($gaji_card['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                                        </div>
                                        <div class="dash-stat-body">
                                            <div class="dash-stat-label"><?= htmlspecialchars($gaji_card['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="dash-stat-value dash-stat-value-sm" title="<?= htmlspecialchars($gaji_card['value'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($gaji_card['value'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="dash-stat-sub"><span><?= htmlspecialchars($gaji_card['sub'], ENT_QUOTES, 'UTF-8') ?></span></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Chart Pengeluaran per Periode -->
                        <div class="row">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">
                                        <h4><i class="fas fa-chart-bar"></i> Total Gaji per Periode</h4>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="pengeluaranChart" style="height: 300px; width: 100%;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </section>
            </div>



<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function() {
    var chartRetries = 0;
    function initChart() {
        if (typeof Chart === 'undefined') {
            chartRetries++;
            if (chartRetries < 50) {
                setTimeout(initChart, 100);
            }
            return;
        }

        var ctx = document.getElementById('pengeluaranChart');
        if (!ctx) return;

        var labels = <?php echo $chart_labels_json; ?>;
        var data = <?php echo $chart_data_json; ?>;

        if (labels.length === 0) {
            ctx.parentElement.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-chart-bar fa-3x mb-3"></i><p>Belum ada data pengeluaran</p></div>';
            return;
        }

        var bulan = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        var formattedLabels = labels.map(function(p) {
            var parts = p.split('-');
            return bulan[parseInt(parts[1])-1] + ' ' + parts[0];
        });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: formattedLabels,
                datasets: [{
                    label: 'Total Pengeluaran',
                    data: data,
                    backgroundColor: 'rgba(16, 185, 129, 0.7)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var val = context.raw;
                                return 'Rp ' + val.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000000) return 'Rp' + (value / 1000000000).toFixed(1) + 'M';
                                if (value >= 1000000) return 'Rp' + (value / 1000000).toFixed(1) + 'Jt';
                                if (value >= 1000) return 'Rp' + (value / 1000).toFixed(0) + 'Rb';
                                return 'Rp' + value;
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { setTimeout(initChart, 200); });
    } else {
        setTimeout(initChart, 200);
    }
})();
</script>
