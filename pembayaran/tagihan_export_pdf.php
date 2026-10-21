<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/include/laporan_helper.php';

if (!isset($_SESSION['login'])) {
    header("Location: " . simad_url('login.php'));
    exit;
}

if (!isset($_GET['nisn']) || !isset($_GET['id_kelas'])) {
    die("Parameter tidak lengkap");
}

$nisn = $_GET['nisn'];
$id_kelas = $_GET['id_kelas'];

// Get Data Siswa
$q_siswa = mysqli_query($koneksi, "SELECT siswa.*, kelas.nama_kelas FROM siswa JOIN kelas ON siswa.id_kelas = kelas.id_kelas WHERE siswa.nisn = '$nisn'");
$d_siswa = mysqli_fetch_assoc($q_siswa);
$nama_kelas = $d_siswa['nama_kelas'];

$q_jb = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY tipe_bayar ASC, nama_pembayaran ASC");
$months = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];

// Get School Settings
$q_setting = mysqli_query($koneksi, "SELECT * FROM pengaturan WHERE id_pengaturan = 1");
$d_setting = mysqli_fetch_assoc($q_setting);
$nama_sekolah = $d_setting['nama_sekolah'] ?? 'SMK NEGERI 1 CONTOH';
$nama_bendahara = $d_setting['nama_bendahara'] ?? 'Bendahara Sekolah';
$tahun_ajaran = $d_setting['tahun_ajaran'] ?? '';
$boleh_ditagihkan = tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Laporan Tagihan - <?= $d_siswa['nama'] ?></title>
    <style>
        body { font-family: sans-serif; font-size: 12pt; margin: 0; }
        .header { 
            text-align: center; 
            margin-bottom: 20px; 
            position: relative;
            min-height: 80px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .header img {
            position: absolute;
            left: 0;
            top: 0;
            max-height: 80px;
            max-width: 80px;
        }
        .header-content {
            margin-left: 90px;
        }
        .header h2, .header h3, .header p { margin: 2px 0; }
        .info-siswa { margin-bottom: 20px; }
        .info-siswa table { width: auto; border: none; }
        .info-siswa td { border: none; padding: 2px 10px 2px 0; font-size: 11pt; }
        
        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data th, table.data td { border: 1px solid #000; padding: 6px; text-align: left; vertical-align: top; font-size: 11pt; }
        table.data th { background-color: #f2f2f2; }
        table.data thead th { text-align: center; }
        table.data tbody tr td:first-child:not([colspan]) { text-align: center; }
        
        .status-grid { display: flex; flex-wrap: wrap; }
        .status-item { width: 33%; margin-bottom: 5px; }
        
        .text-success { color: green; font-weight: bold; }
        .text-danger { color: red; font-weight: bold; }
        
        .signature { margin-top: 24px; float: right; text-align: center; width: 200px; page-break-inside: avoid; break-inside: avoid; font-size: 11pt; }
        
        @page {
            size: 215mm 330mm;              /* F4 */
            margin: 6mm 3mm 10mm 3mm;      /* atas 6mm, kanan-kiri 3mm, bawah 10mm */
        }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <?php if (!empty($d_setting['logo'])): ?>
            <img src="../assets/img/pembayaran/<?= $d_setting['logo'] ?>" alt="Logo">
        <?php endif; ?>
        <div class="header-content">
            <h2>LAPORAN TAGIHAN SISWA</h2>
            <h3><?= strtoupper($nama_sekolah) ?></h3>
            <p>TAHUN AJARAN <?= strtoupper($tahun_ajaran) ?></p>
        </div>
    </div>

    <div class="info-siswa">
        <table>
            <tr>
                <td>Nama Siswa</td>
                <td>: <?= $d_siswa['nama'] ?></td>
            </tr>
            <tr>
                <td>Kelas</td>
                <td>: <?= $nama_kelas ?></td>
            </tr>
            <tr>
                <td>NISN</td>
                <td>: <?= $d_siswa['nisn'] ?></td>
            </tr>
        </table>
    </div>

    <table class="data">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="25%">Jenis Pembayaran</th>
                <th width="20%">Nominal / Tagihan</th>
                <th>Status Pembayaran</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            $total_tagihan = 0;

            // Bulan yang ditampilkan: bulan berjalan saja utk tahun ajaran aktif
            $months = daftar_bulan_tagihan_tampil($koneksi, $tahun_ajaran);

            // Calculate current month index (relative to school year starting July)
            $limit_index = limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran);
            
            while ($jb = mysqli_fetch_assoc($q_jb)) {
                if (!$boleh_ditagihkan) {
                    continue;
                }

                // Filter by Class
                if (!empty($jb['tagihan_kelas'])) {
                    $allowed_kelas = array_map('trim', explode(',', $jb['tagihan_kelas']));
                    if (!in_array($nama_kelas, $allowed_kelas)) {
                        continue;
                    }
                }

                // Check status before displaying
                $is_fully_paid = false;
                $paid_months = [];
                $total_bayar = 0;
                $sisa = 0;

                if ($jb['tipe_bayar'] == 'Bulanan') {
                    $q_bayar = mysqli_query($koneksi, "SELECT bulan_bayar FROM pembayaran WHERE nisn='$nisn' AND id_jenis_bayar='" . $jb['id_jenis_bayar'] . "'");
                    while ($r = mysqli_fetch_assoc($q_bayar)) {
                        if (!empty($r['bulan_bayar'])) {
                            $ms = array_map('trim', explode(',', $r['bulan_bayar']));
                            $paid_months = array_merge($paid_months, $ms);
                        }
                    }
                    
                    $is_extracurricular = stripos($jb['nama_pembayaran'], 'ekstrakurikuler') !== false;
                    $has_unpaid = false;
                    foreach ($months as $m) {
                        if (!in_array($m, $paid_months)) {
                            $has_unpaid = true;
                            break;
                        }
                    }
                    
                    if (!$has_unpaid) {
                        $is_fully_paid = true;
                    }

                } else {
                    $q_total = mysqli_query($koneksi, "SELECT SUM(jumlah_bayar) as total FROM pembayaran WHERE nisn='$nisn' AND id_jenis_bayar='" . $jb['id_jenis_bayar'] . "'");
                    $d_total = mysqli_fetch_assoc($q_total);
                    $total_bayar = $d_total['total'] ?? 0;
                    $sisa = $jb['nominal'] - $total_bayar;
                    
                    if ($sisa <= 0) {
                        $is_fully_paid = true;
                    }
                }

                // Skip if fully paid
                if ($is_fully_paid) {
                    continue;
                }
                
                echo "<tr>";
                echo "<td>" . $no++ . "</td>";
                echo "<td>" . $jb['nama_pembayaran'] . "</td>";
                echo "<td>";
                echo "Rp " . number_format($jb['nominal'], 0, ',', '.');
                if ($jb['tipe_bayar'] == 'Bulanan' && stripos($jb['nama_pembayaran'], 'ekstrakurikuler') !== false) {
                    $unpaid_count_nominal = 0;
                    foreach ($months as $month_nominal) {
                        if (!in_array($month_nominal, $paid_months)) {
                            $unpaid_count_nominal++;
                        }
                    }
                    $tagihan_ekskul_nominal = $unpaid_count_nominal * $jb['nominal'];
                    echo "<br><span class='text-danger'>Jumlah Tagihan Ekskul: Rp " . number_format($tagihan_ekskul_nominal, 0, ',', '.') . "</span>";
                }
                echo "</td>";
                echo "<td>";

                if ($jb['tipe_bayar'] == 'Bulanan') {
                    $is_extracurricular = stripos($jb['nama_pembayaran'], 'ekstrakurikuler') !== false;
                    $unpaid_count = 0;
                    echo '<div style="display: table; width: 100%;">';
                    $counter = 0;
                    echo '<div style="display: table-row;">';
                    foreach ($months as $m) {
                        $is_paid = in_array($m, $paid_months);
                        $symbol = $is_paid ? '&#10004;' : '&#10006;';
                        $color = $is_paid ? 'green' : 'red';
                        if (!$is_paid) {
                            $unpaid_count++;
                        }
                        
                        echo '<div style="display: table-cell; width: 25%; padding-bottom: 5px;">';
                        echo '<span style="color: '.$color.';">'.$symbol.'</span> ' . $m;
                        echo '</div>';
                        
                        $counter++;
                        if ($counter % 4 == 0 && $counter < 12) {
                            echo '</div><div style="display: table-row;">';
                        }
                    }
                    echo '</div></div>';
                    $tagihan_ekskul = $unpaid_count * $jb['nominal'];
                    $total_tagihan += $tagihan_ekskul;
                    if ($is_extracurricular) {
                        echo '<div class="text-danger" style="margin-top:6px;">Jumlah Tagihan Ekskul: Rp ' . number_format($tagihan_ekskul, 0, ',', '.') . '</div>';
                    }
                    
                } else {
                    if ($sisa > 0) {
                        $total_tagihan += $sisa;
                    }

                    // echo "Sudah Bayar: Rp " . number_format($total_bayar, 0, ',', '.') . "<br>";
                    if ($sisa <= 0) {
                        echo '<span class="text-success">&#10004; LUNAS</span>';
                    } else {
                        echo '<span class="text-danger">&#10006; Kurang: Rp ' . number_format($sisa, 0, ',', '.') . '</span>';
                    }
                }

                echo "</td>";
                echo "</tr>";
            }
            ?>
            <tr>
                <td colspan="3" style="text-align: right; font-weight: bold;">Total Tagihan Belum Dibayar</td>
                <td style="font-weight: bold; color: red;">Rp <?= number_format($total_tagihan, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>

    <div class="signature">
        <?php
        $tgl = date('d');
        $bulan = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April', '05' => 'Mei', '06' => 'Juni',
            '07' => 'Juli', '08' => 'Agustus', '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
        ];
        $bln = $bulan[date('m')];
        $thn = date('Y');
        $qr_src_bendahara = generate_qr_bendahara($nama_bendahara, $nama_sekolah, 60);
        ?>
        <p><?= $tgl . ' ' . $bln . ' ' . $thn ?></p>
        <p>Bendahara,</p>
        <img src="<?= $qr_src_bendahara ?>" alt="QR Bendahara" style="width:60px;height:60px;margin:6px 0;">
        <p><b><?= $nama_bendahara ?></b></p>
    </div>
</body>
</html>
