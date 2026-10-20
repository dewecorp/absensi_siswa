<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/include/laporan_helper.php';
header('Content-Type: application/json');
ensure_siswa_tanggal_masuk_column($koneksi);

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['data']) || !is_array($input['data'])) {
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

$dataSiswa = $input['data'];
$success_count = 0;
$update_count = 0;
$error_count = 0;
$errors = [];
$total_api = count($dataSiswa);

function normalisasiKelas($nama_kelas) {
    if ($nama_kelas === null || trim((string)$nama_kelas) === '') {
        return 'Alumni';
    }
    $map = [
        'I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5', 'VI' => '6',
        '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'
    ];
    $trimmed = strtoupper(trim(str_replace('KELAS', '', (string)$nama_kelas)));
    $trimmed = trim($trimmed);
    if ($trimmed === '') return 'Alumni';
    return $map[$trimmed] ?? $trimmed;
}

$q_alumni = mysqli_query($koneksi, "SELECT id_kelas FROM kelas WHERE nama_kelas = 'Alumni' LIMIT 1");
if ($q_alumni && mysqli_num_rows($q_alumni) > 0) {
    $id_alumni_kelas = mysqli_fetch_assoc($q_alumni)['id_kelas'];
} else {
    mysqli_query($koneksi, "INSERT INTO kelas (nama_kelas) VALUES ('Alumni')");
    $id_alumni_kelas = mysqli_insert_id($koneksi);
}

foreach ($dataSiswa as $siswa) {
    $nama       = mysqli_real_escape_string($koneksi, $siswa['nama_siswa'] ?? '');
    $nisn       = mysqli_real_escape_string($koneksi, $siswa['nisn'] ?? '');
    $gender     = mysqli_real_escape_string($koneksi, $siswa['jenis_kelamin'] ?? '-');
    $tempat     = mysqli_real_escape_string($koneksi, $siswa['tempat_lahir'] ?? '-');
    $tanggal    = mysqli_real_escape_string($koneksi, $siswa['tanggal_lahir'] ?? '1900-01-01');
    $wali       = mysqli_real_escape_string($koneksi, $siswa['wali'] ?? '-');
    $tgl_masuk_simad = trim($siswa['tanggal_masuk'] ?? '');
    $thn_masuk = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_masuk_simad)) {
        $thn_masuk = substr($tgl_masuk_simad, 0, 4);
    } elseif (preg_match('/^\d{4}/', $tgl_masuk_simad, $m)) {
        $thn_masuk = $m[0];
    }
    $tgl_masuk_simad = mysqli_real_escape_string($koneksi, $tgl_masuk_simad);
    $thn_masuk = mysqli_real_escape_string($koneksi, $thn_masuk);

    $kelas_n = normalisasiKelas($siswa['nama_kelas'] ?? '');
    $kelas_n_esc = mysqli_real_escape_string($koneksi, $kelas_n);

    if (empty($nisn)) {
        $error_count++;
        $errors[] = "NISN kosong untuk siswa: $nama";
        continue;
    }

    if ($kelas_n === 'Alumni') {
        $id_kelas = $id_alumni_kelas;
    } else {
        $q_kelas = mysqli_query($koneksi, "SELECT id_kelas FROM kelas WHERE nama_kelas = '$kelas_n_esc' LIMIT 1");
        if ($q_kelas && mysqli_num_rows($q_kelas) > 0) {
            $id_kelas = mysqli_fetch_assoc($q_kelas)['id_kelas'];
        } else {
            $q_create = mysqli_query($koneksi, "INSERT INTO kelas (nama_kelas) VALUES ('$kelas_n_esc')");
            if ($q_create) {
                $id_kelas = mysqli_insert_id($koneksi);
            } else {
                $q_existing = mysqli_query($koneksi, "SELECT id_kelas FROM kelas WHERE nama_kelas = '$kelas_n_esc' LIMIT 1");
                $id_kelas = ($q_existing && mysqli_num_rows($q_existing) > 0) ? mysqli_fetch_assoc($q_existing)['id_kelas'] : $id_alumni_kelas;
            }
        }
    }

    $cek = mysqli_query($koneksi, "SELECT nisn FROM siswa WHERE nisn = '$nisn'");
    if (mysqli_num_rows($cek) > 0) {
        $set_tgl = $tgl_masuk_simad !== '' ? ", tanggal_masuk = '$tgl_masuk_simad'" : ", tanggal_masuk = NULL";
        $set_thn = $thn_masuk !== '' ? ", tahun_masuk = '$thn_masuk'" : ", tahun_masuk = ''";
        $sql = "UPDATE siswa SET nama = '$nama', id_kelas = '$id_kelas', jenis_kelamin = '$gender', tempat_lahir = '$tempat', tgl_lahir = '$tanggal', nama_wali = '$wali'$set_tgl$set_thn WHERE nisn = '$nisn'";
        if (mysqli_query($koneksi, $sql)) {
            $update_count++;
        } else {
            $error_count++;
            $errors[] = "Gagal Update NISN $nisn: " . mysqli_error($koneksi);
        }
    } else {
        $tgl_col = $tgl_masuk_simad !== '' ? ", tanggal_masuk" : '';
        $tgl_val = $tgl_masuk_simad !== '' ? ", '$tgl_masuk_simad'" : '';
        $tm_col  = $thn_masuk !== '' ? ", tahun_masuk" : '';
        $tm_val  = $thn_masuk !== '' ? ", '$thn_masuk'" : '';
        $sql = "INSERT INTO siswa (nisn, nama, id_kelas, alamat, no_telp, jenis_kelamin, tempat_lahir, tgl_lahir, nama_wali$tm_col$tgl_col) VALUES ('$nisn', '$nama', '$id_kelas', '-', '', '$gender', '$tempat', '$tanggal', '$wali'$tm_val$tgl_val)";
        if (mysqli_query($koneksi, $sql)) {
            $success_count++;
        } else {
            $error_count++;
            $errors[] = "Gagal Insert NISN $nisn: " . mysqli_error($koneksi);
        }
    }
}

pembayaran_log_activity($koneksi, 'Update', "Sinkronisasi Simad via Browser: $success_count baru, $update_count update, $error_count error");

echo json_encode([
    'status' => 'success',
    'new' => $success_count,
    'update' => $update_count,
    'failed' => $error_count,
    'total_api' => $total_api,
    'errors' => $errors,
    'message' => 'Sinkronisasi selesai.'
]);
