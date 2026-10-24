<?php
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

$nisn = isset($_GET['nisn']) ? trim($_GET['nisn']) : '';
$id_jenis_bayar = isset($_GET['id_jenis_bayar']) ? (int)$_GET['id_jenis_bayar'] : 0;
$tahun_ajaran = isset($_GET['tahun_ajaran']) && trim($_GET['tahun_ajaran']) !== ''
    ? trim($_GET['tahun_ajaran'])
    : get_tahun_ajaran_aktif($koneksi);
$exclude_trx = isset($_GET['exclude_trx']) ? trim($_GET['exclude_trx']) : '';

if ($nisn === '' || $id_jenis_bayar <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

$months = bulan_akademik_list();
$paid_months = ambil_bulan_bayar_tersimpan($koneksi, $nisn, $id_jenis_bayar, $tahun_ajaran);

// Jika mode edit transaksi, kecualikan bulan yang sedang ada di transaksi ini
if ($exclude_trx !== '') {
    $exclude_trx_esc = mysqli_real_escape_string($koneksi, $exclude_trx);
    $nisn_esc = mysqli_real_escape_string($koneksi, $nisn);
    $q_cur = mysqli_query($koneksi, "SELECT bulan_bayar FROM pembayaran WHERE no_transaksi = '$exclude_trx_esc' AND nisn = '$nisn_esc' AND id_jenis_bayar = '$id_jenis_bayar'");
    $cur_months = [];
    while ($row = mysqli_fetch_assoc($q_cur)) {
        $cur_months = array_merge($cur_months, normalisasi_bulan_bayar_list($row['bulan_bayar'] ?? ''));
    }
    $cur_months = array_unique($cur_months);
    $paid_months = array_values(array_diff($paid_months, $cur_months));
}

$limit_index = limit_index_bulan_tahun_ajaran($koneksi, $tahun_ajaran);
$min_index = get_bulan_masuk_siswa($koneksi, $nisn, $tahun_ajaran);

$month_data = [];
$unpaid_past_months = [];
$current_academic_index = (new DateTime())->format('Y-m-d') >= get_tanggal_mulai_tahun_ajaran_aktif($koneksi)
    ? min(11, max(0, $limit_index))
    : -1;

foreach ($months as $idx => $m) {
    $is_paid = in_array($m, $paid_months, true);
    $is_past_or_current = ($idx <= $limit_index && $idx >= $min_index);
    if (!$is_paid && $is_past_or_current) {
        $unpaid_past_months[] = $m;
    }
    $month_data[] = [
        'bulan' => $m,
        'index' => $idx,
        'paid' => $is_paid,
        'is_due' => (!$is_paid && $is_past_or_current),
    ];
}

echo json_encode([
    'success' => true,
    'nisn' => $nisn,
    'id_jenis_bayar' => $id_jenis_bayar,
    'tahun_ajaran' => $tahun_ajaran,
    'paid_months' => $paid_months,
    'unpaid_past_months' => $unpaid_past_months,
    'months' => $month_data
]);
