<?php
$title = 'Transaksi Pembayaran';
include __DIR__ . '/template/header.php';
include __DIR__ . '/template/sidebar.php';

// Include Select2 CSS
?>
<link rel="stylesheet" href="<?= base_url('assets/vendors/select2/select2.min.css') ?>">
<style>
    .select2-container {
        width: 100% !important;
    }

    #modalTambah .select2-container,
    #modalEdit .select2-container {
        display: block !important;
        width: 100% !important;
        font-family: inherit !important;
    }

    body.overflow-hidden {
        overflow: hidden !important;
    }

    #modalTambah,
    #modalEdit {
        overflow-x: hidden !important;
        overscroll-behavior: contain !important;
    }

    #modalTambah .select2-container--default .select2-selection,
    #modalEdit .select2-container--default .select2-selection {
        min-height: 42px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 8px !important;
        background: #ffffff !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04) !important;
        transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease !important;
    }

    #modalTambah .select2-container--default.select2-container--focus .select2-selection,
    #modalTambah .select2-container--default.select2-container--open .select2-selection,
    #modalEdit .select2-container--default.select2-container--focus .select2-selection,
    #modalEdit .select2-container--default.select2-container--open .select2-selection {
        border-color: #10b981 !important;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.13), 0 1px 2px rgba(15, 23, 42, 0.05) !important;
    }

    #modalTambah .select2-container--default .select2-selection--single,
    #modalEdit .select2-container--default .select2-selection--single {
        height: 42px !important;
        padding: 0 2.5rem 0 0.25rem !important;
    }

    #modalTambah .select2-container--default .select2-selection--single .select2-selection__rendered,
    #modalEdit .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #334155 !important;
        font-size: 0.925rem !important;
        font-weight: 600 !important;
        line-height: 40px !important;
        padding-left: 0.55rem !important;
        padding-right: 0 !important;
    }

    #modalTambah .select2-container--default .select2-selection--single .select2-selection__arrow,
    #modalEdit .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 40px !important;
        right: 0.65rem !important;
    }

    #modalTambah .select2-container--default .select2-selection--single .select2-selection__arrow b,
    #modalEdit .select2-container--default .select2-selection--single .select2-selection__arrow b {
        border-color: #94a3b8 transparent transparent transparent !important;
        border-width: 5px 4px 0 4px !important;
    }

    #modalTambah .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b,
    #modalEdit .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
        border-color: transparent transparent #10b981 transparent !important;
        border-width: 0 4px 5px 4px !important;
    }

    #modalTambah .select2-container--default .select2-selection--multiple,
    #modalEdit .select2-container--default .select2-selection--multiple {
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        min-height: 42px !important;
        padding: 4px 2.15rem 4px 0.35rem !important;
    }

    #modalTambah .select2-container--default .select2-selection--multiple .select2-selection__rendered,
    #modalEdit .select2-container--default .select2-selection--multiple .select2-selection__rendered {
        display: flex !important;
        flex-wrap: wrap !important;
        align-items: center !important;
        gap: 5px !important;
        width: 100% !important;
        padding: 0 !important;
        line-height: 1.3 !important;
    }

    #modalTambah .select2-container--default .select2-selection--multiple .select2-selection__choice,
    #modalEdit .select2-container--default .select2-selection--multiple .select2-selection__choice {
        display: inline-flex !important;
        align-items: center !important;
        min-height: 28px !important;
        margin: 0 !important;
        padding: 0 0.65rem 0 0.45rem !important;
        border: 1px solid #bbf7d0 !important;
        border-radius: 6px !important;
        background: #ecfdf5 !important;
        color: #047857 !important;
        font-size: 0.875rem !important;
        font-weight: 700 !important;
    }

    #modalTambah .select2-container--default .select2-selection--multiple .select2-selection__choice__remove,
    #modalEdit .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        margin-right: 0.35rem !important;
        color: #059669 !important;
        font-size: 1rem !important;
        font-weight: 800 !important;
    }

    #modalTambah .select2-container--default .select2-search--inline,
    #modalEdit .select2-container--default .select2-search--inline {
        flex: 1 1 9rem !important;
        min-width: 8rem !important;
        margin: 0 !important;
    }

    #modalTambah .select2-container--default .select2-search--inline .select2-search__field,
    #modalEdit .select2-container--default .select2-search--inline .select2-search__field {
        width: 100% !important;
        min-width: 7rem !important;
        height: 28px !important;
        margin: 0 !important;
        border: 0 !important;
        outline: 0 !important;
        box-shadow: none !important;
        color: #334155 !important;
        font-family: inherit !important;
        font-size: 0.925rem !important;
    }

    #modalTambah .select2-container--default .select2-selection__clear,
    #modalEdit .select2-container--default .select2-selection__clear {
        position: absolute !important;
        top: 50% !important;
        right: 0.8rem !important;
        transform: translateY(-50%) !important;
        margin: 0 !important;
        color: #64748b !important;
        font-size: 1rem !important;
        font-weight: 800 !important;
    }

    #modalTambah .select2-container--default .select2-selection__placeholder,
    #modalEdit .select2-container--default .select2-selection__placeholder {
        color: #94a3b8 !important;
        font-weight: 500 !important;
    }

    #modalTambah .select2-dropdown,
    #modalEdit .select2-dropdown,
    body > .select2-container--open .select2-dropdown {
        overflow: hidden !important;
        margin-top: 6px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 8px !important;
        padding: 4px !important;
        background: #ffffff !important;
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.18) !important;
    }

    #modalTambah .select2-search--dropdown,
    #modalEdit .select2-search--dropdown,
    body > .select2-container--open .select2-search--dropdown {
        padding: 0.6rem !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }

    #modalTambah .select2-search--dropdown .select2-search__field,
    #modalEdit .select2-search--dropdown .select2-search__field,
    body > .select2-container--open .select2-search--dropdown .select2-search__field {
        height: 38px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 8px !important;
        outline: 0 !important;
        padding: 0 0.75rem !important;
        color: #334155 !important;
        font-size: 0.925rem !important;
    }

    #modalTambah .select2-results__options,
    #modalEdit .select2-results__options,
    body > .select2-container--open .select2-results__options {
        max-height: 220px !important;
        padding: 0.35rem !important;
    }

    #modalTambah .select2-results__option,
    #modalEdit .select2-results__option,
    body > .select2-container--open .select2-results__option {
        border-radius: 6px !important;
        margin: 2px 0 !important;
        padding: 0.65rem 0.75rem !important;
        color: #334155 !important;
        font-size: 0.925rem !important;
        font-weight: 600 !important;
    }

    #modalTambah .select2-container--default .select2-results__option--highlighted[aria-selected],
    #modalEdit .select2-container--default .select2-results__option--highlighted[aria-selected],
    body > .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background: #10b981 !important;
        color: #ffffff !important;
    }

    #modalTambah .select2-container--default .select2-results__option[aria-selected="true"],
    #modalEdit .select2-container--default .select2-results__option[aria-selected="true"],
    body > .select2-container--default .select2-results__option[aria-selected="true"] {
        background: #d1fae5 !important;
        color: #065f46 !important;
    }

    #modalTambah .select2-container--open,
    #modalEdit .select2-container--open,
    .select2-dropdown {
        z-index: 1065 !important;
    }

    body > .select2-container--open {
        z-index: 1066 !important;
    }
</style>

<?php
// Fetch Data Siswa & Jenis Bayar for Modal
$q_siswa = mysqli_query($koneksi, "SELECT siswa.*, kelas.nama_kelas FROM siswa JOIN kelas ON siswa.id_kelas = kelas.id_kelas ORDER BY siswa.nama ASC");
$siswa_list = [];
while ($s = mysqli_fetch_assoc($q_siswa)) {
    $siswa_list[] = $s;
}

$q_jb = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' ORDER BY nama_pembayaran ASC");
$jb_list = [];
while ($jb = mysqli_fetch_assoc($q_jb)) {
    $jb_list[] = $jb;
}

// Proses Tambah
if (isset($_POST['tambah'])) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    $id_petugas = isset($_SESSION['id_pengguna']) ? (int)$_SESSION['id_pengguna'] : 0;
    $nisn = isset($_POST['nisn']) ? trim($_POST['nisn']) : '';
    $tgl_bayar = isset($_POST['tgl_bayar']) ? $_POST['tgl_bayar'] : '';
    $tahun_bayar = date('Y', strtotime($tgl_bayar));
    $tahun_ajaran = get_tahun_ajaran_aktif($koneksi);
    $payment_data = isset($_POST['payment']) && is_array($_POST['payment']) ? $_POST['payment'] : [];
    $payment_items_by_id = [];
    foreach ($payment_data as $payment_key => $payment_item) {
        if (!is_array($payment_item)) {
            continue;
        }

        $candidate_ids = [];
        $payment_key_str = trim((string)$payment_key);
        if ($payment_key_str !== '' && ctype_digit($payment_key_str) && (int)$payment_key_str >= 0) {
            $candidate_ids[] = (int)$payment_key_str;
        }

        if (isset($payment_item['id_jenis_bayar'])) {
            $payment_id_str = trim((string)$payment_item['id_jenis_bayar']);
            if ($payment_id_str !== '' && ctype_digit($payment_id_str) && (int)$payment_id_str >= 0) {
                $candidate_ids[] = (int)$payment_id_str;
            }
        }

        foreach (array_unique($candidate_ids) as $candidate_id) {
            $payment_items_by_id[$candidate_id] = $payment_item;
        }
    }
    if (!empty($payment_data)) {
        $payment_scan_stack = [[$payment_data, null]];
        while (!empty($payment_scan_stack)) {
            $payment_scan_node = array_pop($payment_scan_stack);
            $payment_scan_val = $payment_scan_node[0];
            $payment_scan_key = $payment_scan_node[1];
            if (!is_array($payment_scan_val)) {
                continue;
            }

            $found_ids = [];
            if (isset($payment_scan_val['id_jenis_bayar'])) {
                $scan_id_str = trim((string)$payment_scan_val['id_jenis_bayar']);
                if ($scan_id_str !== '' && ctype_digit($scan_id_str) && (int)$scan_id_str >= 0) {
                    $found_ids[] = (int)$scan_id_str;
                }
            }

            if ($payment_scan_key !== null) {
                $scan_key_str = trim((string)$payment_scan_key);
                if ($scan_key_str !== '' && ctype_digit($scan_key_str) && (int)$scan_key_str >= 0) {
                    $has_expected_shape = array_key_exists('bulan_bayar', $payment_scan_val) || array_key_exists('cicilan_ke', $payment_scan_val) || array_key_exists('nominal', $payment_scan_val);
                    if ($has_expected_shape) {
                        $found_ids[] = (int)$scan_key_str;
                    }
                }
            }

            foreach (array_unique($found_ids) as $found_id) {
                $payment_items_by_id[$found_id] = $payment_scan_val;
            }

            foreach ($payment_scan_val as $child_key => $child_val) {
                if (is_array($child_val)) {
                    $payment_scan_stack[] = [$child_val, $child_key];
                }
            }
        }
    }

    $id_jenis_bayar_post = isset($_POST['id_jenis_bayar']) ? $_POST['id_jenis_bayar'] : [];
    if (!is_array($id_jenis_bayar_post)) {
        $id_jenis_bayar_post = [$id_jenis_bayar_post];
    }
    $normalized_id_jenis_bayar = [];
    foreach ($id_jenis_bayar_post as $id_jb_value) {
        if (!is_scalar($id_jb_value)) {
            continue;
        }
        $id_jb_clean = trim((string)$id_jb_value);
        if ($id_jb_clean !== '' && ctype_digit($id_jb_clean) && (int)$id_jb_clean >= 0) {
            $normalized_id_jenis_bayar[] = (int)$id_jb_clean;
        }
    }
    if (!empty($payment_items_by_id)) {
        foreach (array_keys($payment_items_by_id) as $payment_item_id) {
            $normalized_id_jenis_bayar[] = (int)$payment_item_id;
        }
    }
    $id_jenis_bayar_input = array_values(array_unique($normalized_id_jenis_bayar));
    $normalize_kelas = static function ($kelas_value) {
        $kelas = strtoupper(trim((string)$kelas_value));
        $kelas = preg_replace('/\s+/', '', $kelas);
        if ($kelas === null) {
            $kelas = '';
        }
        $roman_map = [
            'I' => '1',
            'II' => '2',
            'III' => '3',
            'IV' => '4',
            'V' => '5',
            'VI' => '6',
        ];
        if (isset($roman_map[$kelas])) {
            return $roman_map[$kelas];
        }
        if (preg_match('/([1-6])/', $kelas, $kelas_match)) {
            return $kelas_match[1];
        }
        return $kelas;
    };

    $error_tambah = '';
    $success_count = 0;

    if ($id_petugas <= 0 || $nisn === '' || $tgl_bayar === '' || (empty($id_jenis_bayar_input) && empty($payment_data))) {
        $error_tambah = 'Input transaksi belum lengkap.';
    } elseif (!tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran)) {
        $error_tambah = 'Transaksi ditolak. Tahun ajaran aktif belum memasuki tanggal mulai (' . get_tanggal_mulai_tahun_ajaran_aktif($koneksi) . ').';
    } else {
        $tunggakan_lama = cek_tunggakan_tahun_ajaran_lama($koneksi, $nisn, $tahun_ajaran);
        if ($tunggakan_lama) {
            $tahun_tunggakan = implode(', ', array_keys($tunggakan_lama));
            $error_tambah = 'Transaksi ditolak. Siswa masih memiliki tunggakan tahun ajaran lama: ' . $tahun_tunggakan . '. Lunasi melalui menu Tagihan terlebih dahulu.';
        }
    }

    if ($error_tambah === '') {
            mysqli_begin_transaction($koneksi);
            try {
                // Generate no transaksi yang konsisten per bulan (TRX-YYYYMM-NNN).
                $prefix_trx = 'TRX-' . date('Ym', strtotime($tgl_bayar)) . '-';
                $prefix_len = strlen($prefix_trx) + 1;
                $safe_prefix = mysqli_real_escape_string($koneksi, $prefix_trx);
                $q_last_trx = mysqli_query($koneksi, "SELECT COALESCE(MAX(CAST(SUBSTRING(no_transaksi, $prefix_len) AS UNSIGNED)), 0) AS last_urut FROM pembayaran WHERE no_transaksi LIKE '$safe_prefix%'");

                if (!$q_last_trx) {
                    throw new Exception('Gagal membaca nomor transaksi terakhir: ' . mysqli_error($koneksi));
                }

                $d_last_trx = mysqli_fetch_assoc($q_last_trx);
                $next_urut = ((int)$d_last_trx['last_urut']) + 1;
                $no_transaksi = $prefix_trx . sprintf("%03d", $next_urut);

                $stmt_kelas_siswa = mysqli_prepare($koneksi, "SELECT k.nama_kelas FROM siswa s INNER JOIN kelas k ON s.id_kelas = k.id_kelas WHERE s.nisn = ? LIMIT 1");
                if (!$stmt_kelas_siswa) {
                    throw new Exception('Gagal menyiapkan query data siswa: ' . mysqli_error($koneksi));
                }
                mysqli_stmt_bind_param($stmt_kelas_siswa, 's', $nisn);
                mysqli_stmt_execute($stmt_kelas_siswa);
                $res_ks = mysqli_stmt_get_result($stmt_kelas_siswa);
                $row_ks = $res_ks ? mysqli_fetch_assoc($res_ks) : null;
                $kelas_siswa_nama = $row_ks ? trim((string)$row_ks['nama_kelas']) : '';
                $kelas_siswa_normalized = $normalize_kelas($kelas_siswa_nama);

                $stmt_cek_jb = mysqli_prepare($koneksi, "SELECT id_jenis_bayar, tipe_bayar, nominal, IFNULL(tagihan_kelas,'') AS tagihan_kelas FROM jenis_bayar WHERE id_jenis_bayar = ? LIMIT 1");
                $stmt_insert = mysqli_prepare($koneksi, "INSERT INTO pembayaran (id_petugas, nisn, tgl_bayar, id_jenis_bayar, jumlah_bayar, cicilan_ke, ket, bulan_bayar, tahun_bayar, tahun_ajaran, no_transaksi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                if (!$stmt_cek_jb || !$stmt_insert) {
                    throw new Exception('Gagal menyiapkan query simpan transaksi: ' . mysqli_error($koneksi));
                }

                foreach ($id_jenis_bayar_input as $id_jb_raw) {
                    $id_jb = (int)$id_jb_raw;
                    if ($id_jb < 0) {
                        continue;
                    }

                    $detail = isset($payment_items_by_id[$id_jb]) ? $payment_items_by_id[$id_jb] : [];
                    if (!is_array($detail)) {
                        $detail = [];
                    }

                    mysqli_stmt_bind_param($stmt_cek_jb, 'i', $id_jb);
                    mysqli_stmt_execute($stmt_cek_jb);
                    $res_jb = mysqli_stmt_get_result($stmt_cek_jb);
                    $d_jb = $res_jb ? mysqli_fetch_assoc($res_jb) : null;

                if (!$d_jb) {
                    if ($id_jb === 0) {
                        continue;
                    }
                    throw new Exception("Jenis pembayaran ID $id_jb tidak ditemukan.");
                }

                $tagihan_raw = trim((string)$d_jb['tagihan_kelas']);
                if ($tagihan_raw !== '') {
                    if ($kelas_siswa_nama === '') {
                        throw new Exception('Data kelas siswa tidak ditemukan untuk NISN ini.');
                    }
                    $allowed_kelas = array_map('trim', explode(',', $tagihan_raw));
                    $allowed_kelas_normalized = array_map($normalize_kelas, $allowed_kelas);
                    $kelas_match = in_array($kelas_siswa_nama, $allowed_kelas, true) || in_array($kelas_siswa_normalized, $allowed_kelas_normalized, true);
                    if (!$kelas_match) {
                        throw new Exception('Jenis pembayaran tidak tersedia untuk kelas siswa ini.');
                    }
                }

                $cicilan_ke = 0;
                $jumlah_bayar = 0;
                $ket = '';
                $bulan_bayar_str = '';

                if ($d_jb['tipe_bayar'] === 'Cicilan') {
                    $cicilan_ke = isset($detail['cicilan_ke']) ? (int)$detail['cicilan_ke'] : 0;
                    $nominal_val = isset($detail['nominal']) ? (int)str_replace('.', '', (string)$detail['nominal']) : 0;

                    if ($cicilan_ke <= 0 || $nominal_val <= 0) {
                        throw new Exception('Data cicilan belum valid. Pastikan cicilan ke dan nominal terisi.');
                    }

                    $jumlah_bayar = $nominal_val;
                    $ket = "Cicilan ke-$cicilan_ke";
                } else {
                    $bulan_bayar_arr = isset($detail['bulan_bayar']) && is_array($detail['bulan_bayar']) ? $detail['bulan_bayar'] : [];
                    if (empty($bulan_bayar_arr)) {
                        throw new Exception('Bulan bayar wajib dipilih untuk pembayaran bulanan.');
                    }

                    $bulan_bayar_str = implode(', ', $bulan_bayar_arr);
                    $jumlah_bayar = ((int)$d_jb['nominal']) * count($bulan_bayar_arr);
                    $ket = "Lunas (Bulanan) - " . $bulan_bayar_str;
                }

                mysqli_stmt_bind_param($stmt_insert, 'issiiisssss', $id_petugas, $nisn, $tgl_bayar, $id_jb, $jumlah_bayar, $cicilan_ke, $ket, $bulan_bayar_str, $tahun_bayar, $tahun_ajaran, $no_transaksi);
                if (!mysqli_stmt_execute($stmt_insert)) {
                    throw new Exception('Gagal menyimpan data pembayaran: ' . mysqli_stmt_error($stmt_insert));
                }
                $success_count++;
            }

            if ($success_count <= 0) {
                throw new Exception('Tidak ada item pembayaran yang valid untuk disimpan.');
            }

            pembayaran_log_activity($koneksi, 'Create', "Menambah $success_count transaksi pembayaran NISN: $nisn");
            mysqli_commit($koneksi);
            mysqli_stmt_close($stmt_kelas_siswa);
            mysqli_stmt_close($stmt_cek_jb);
            mysqli_stmt_close($stmt_insert);
        } catch (Exception $e) {
            mysqli_rollback($koneksi);
            $error_tambah = $e->getMessage();
        }
    }

    if ($success_count > 0 && $error_tambah === '') {
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Data berhasil ditambahkan',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
        </script>";
    } else {
        $error_safe = htmlspecialchars($error_tambah !== '' ? $error_tambah : 'Data gagal ditambahkan', ENT_QUOTES);
        echo "<script>Swal.fire('Gagal', '$error_safe', 'error');</script>";
    }
}

// Proses Update Transaksi
if (isset($_POST['update_transaksi'])) {
    ensure_pembayaran_tahun_ajaran_column($koneksi);
    $tahun_ajaran = get_tahun_ajaran_aktif($koneksi);
    $no_transaksi = $_POST['no_transaksi'];
    $tgl_bayar = $_POST['tgl_bayar'];
    $nisn = $_POST['nisn']; 
    $payments_input = isset($_POST['payment']) ? $_POST['payment'] : [];

    // 1. Get existing IDs for this transaction to track what to keep/delete
    $q_exist = mysqli_query($koneksi, "SELECT id_pembayaran FROM pembayaran WHERE no_transaksi='$no_transaksi'");
    $existing_ids = [];
    while($row = mysqli_fetch_assoc($q_exist)) {
        $existing_ids[] = $row['id_pembayaran'];
    }

    $submitted_ids = [];
    $success_count = 0;
    $error_update = '';

    if (!tahun_ajaran_boleh_ditagihkan($koneksi, $tahun_ajaran)) {
        $error_update = 'Transaksi ditolak. Tahun ajaran aktif belum memasuki tanggal mulai (' . get_tanggal_mulai_tahun_ajaran_aktif($koneksi) . ').';
    }

    foreach ($payments_input as $id_jb => $data) {
        if ($error_update !== '') {
            break;
        }

        $id_pembayaran = isset($data['id_pembayaran']) ? $data['id_pembayaran'] : null;
        
        // Get Info Jenis Bayar
        $q_jb = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE id_jenis_bayar='$id_jb'");
        $d_jb = mysqli_fetch_assoc($q_jb);

        // Prepare common vars
        $cicilan_ke = 0;
        $jumlah_bayar = 0;
        $ket = '';
        $bulan_bayar_str = '';

        if ($d_jb['tipe_bayar'] == 'Cicilan') {
            $cicilan_ke = isset($data['cicilan_ke']) ? $data['cicilan_ke'] : 0;
            $nominal = isset($data['nominal']) ? str_replace('.', '', $data['nominal']) : 0;
            $jumlah_bayar = $nominal;
            $ket = "Cicilan ke-$cicilan_ke";
        } else {
            // Bulanan
            $bulan_bayar_arr = isset($data['bulan_bayar']) ? $data['bulan_bayar'] : [];
            $bulan_bayar_str = implode(', ', $bulan_bayar_arr);
            $jumlah_bayar = $d_jb['nominal'] * count($bulan_bayar_arr);
            $ket = "Lunas (Bulanan) - " . $bulan_bayar_str;
        }

        if ($id_pembayaran && in_array($id_pembayaran, $existing_ids)) {
            // UPDATE EXISTING ITEM
            $query = mysqli_query($koneksi, "UPDATE pembayaran SET 
                tgl_bayar='$tgl_bayar', 
                jumlah_bayar='$jumlah_bayar', 
                cicilan_ke='$cicilan_ke', 
                ket='$ket', 
                bulan_bayar='$bulan_bayar_str' 
                WHERE id_pembayaran='$id_pembayaran'");
            
            if ($query) {
                $submitted_ids[] = $id_pembayaran;
                $success_count++;
            }
        } else {
            // INSERT NEW ITEM (User added a new payment type in edit modal)
            $id_petugas = $_SESSION['id_pengguna'];
            $tahun_bayar = date('Y', strtotime($tgl_bayar));
            
            $query = mysqli_query($koneksi, "INSERT INTO pembayaran 
                (id_petugas, nisn, tgl_bayar, id_jenis_bayar, jumlah_bayar, cicilan_ke, ket, bulan_bayar, tahun_bayar, tahun_ajaran, no_transaksi)
                VALUES 
                ('$id_petugas', '$nisn', '$tgl_bayar', '$id_jb', '$jumlah_bayar', '$cicilan_ke', '$ket', '$bulan_bayar_str', '$tahun_bayar', '$tahun_ajaran', '$no_transaksi')");
            
            if ($query) $success_count++;
        }
    }

    // DELETE REMOVED ITEMS (User deselected a payment type)
    $ids_to_delete = array_diff($existing_ids, $submitted_ids);
    if ($error_update === '' && !empty($ids_to_delete)) {
        $ids_str = implode(',', $ids_to_delete);
        mysqli_query($koneksi, "DELETE FROM pembayaran WHERE id_pembayaran IN ($ids_str)");
    }

    if ($error_update !== '') {
        $error_safe = htmlspecialchars($error_update, ENT_QUOTES);
        echo "<script>Swal.fire('Gagal', '$error_safe', 'error');</script>";
    } elseif ($success_count > 0 || !empty($ids_to_delete)) {
        pembayaran_log_activity($koneksi, 'Update', "Mengedit transaksi No: $no_transaksi");
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Data transaksi berhasil diperbarui',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
        </script>";
    } else {
         echo "<script>Swal.fire('Gagal', 'Tidak ada perubahan data', 'info');</script>";
    }
}

// Proses Hapus
if (isset($_GET['hapus_transaksi'])) {
    $no_transaksi = $_GET['hapus_transaksi'];
    
    if (empty($no_transaksi)) {
        $query = mysqli_query($koneksi, "DELETE FROM pembayaran WHERE no_transaksi IS NULL OR no_transaksi = ''");
        $log_desc = "Menghapus transaksi tanpa nomor (Legacy/Null)";
    } else {
        $query = mysqli_query($koneksi, "DELETE FROM pembayaran WHERE no_transaksi='$no_transaksi'");
        $log_desc = "Menghapus transaksi No: $no_transaksi";
    }

    if ($query) {
        pembayaran_log_activity($koneksi, 'Delete', $log_desc);
        echo "<script>
            Swal.fire({
                title: 'Berhasil',
                text: 'Data berhasil dihapus',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
            });
        </script>";
    } else {
        echo "<script>Swal.fire('Gagal', 'Data gagal dihapus', 'error');</script>";
    }
}
?>

<div class="app-grid">
    <div class="app-col-full app-section-gap app-stretch">
        <div class="app-panel">
            <div class="app-panel-body">
                <h4 class="app-panel-title">Data Transaksi Pembayaran</h4>
                <div class="toolbar flex justify-between mb-3 items-center gap-3">
                    <div class="flex items-center gap-3">
                        <button type="button" class="app-button app-button-primary" data-tailwind-modal-target="#modalTambah">
                            <i class="mdi mdi-plus"></i> Tambah Transaksi
                        </button>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="transaksi_export_excel.php" class="app-button app-button-success" target="_blank">
                            <i class="mdi mdi-file-excel"></i> Export Excel
                        </a>
                        <a href="transaksi_export_pdf.php" class="app-button app-button-danger" target="_blank">
                            <i class="mdi mdi-file-pdf"></i> Export PDF
                        </a>
                    </div>
                </div>

                <div class="app-table-scroll">
                    <table class="app-data-table app-table-striped" id="table-transaksi">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>Kelas</th>
                                <th>Jenis Bayar</th>
                                <th>Bulan Bayar</th>
                                <th>Cicilan Ke</th>
                                <th>Nominal</th>
                                <th>Keterangan</th>
                                <th>Tanggal</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $no = 1;
                            $query = mysqli_query($koneksi, "SELECT 
                                                                p.no_transaksi,
                                                                MAX(p.id_pembayaran) as id_pembayaran,
                                                                MAX(p.tgl_bayar) as tgl_bayar,
                                                                MAX(s.nama) as nama_siswa,
                                                                MAX(k.nama_kelas) as nama_kelas,
                                                                GROUP_CONCAT(DISTINCT jb.nama_pembayaran SEPARATOR '<br>') as nama_pembayaran,
                                                                GROUP_CONCAT(DISTINCT p.bulan_bayar SEPARATOR '<br>') as bulan_bayar,
                                                                GROUP_CONCAT(DISTINCT p.cicilan_ke SEPARATOR ', ') as cicilan_ke,
                                                                GROUP_CONCAT(DISTINCT p.ket SEPARATOR '<br>') as ket,
                                                                SUM(p.jumlah_bayar) as jumlah_bayar
                                                             FROM pembayaran p
                                                             JOIN siswa s ON p.nisn = s.nisn 
                                                             JOIN kelas k ON s.id_kelas = k.id_kelas 
                                                             JOIN jenis_bayar jb ON p.id_jenis_bayar = jb.id_jenis_bayar 
                                                             GROUP BY p.no_transaksi
                                                             ORDER BY tgl_bayar DESC, id_pembayaran DESC");
                            while ($row = mysqli_fetch_assoc($query)) :
                            ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= $row['no_transaksi'] ? $row['no_transaksi'] : '<span class="app-badge app-badge-warning">Old/Null</span>' ?></td>
                                    <td><?= $row['nama_siswa'] ?></td>
                                    <td><?= $row['nama_kelas'] ?></td>
                                    <td><?= $row['nama_pembayaran'] ?></td>
                                    <td><?= $row['bulan_bayar'] ?></td>
                                    <td><?= $row['cicilan_ke'] == '0' ? '-' : $row['cicilan_ke'] ?></td>
                                    <td>Rp <?= number_format($row['jumlah_bayar'], 0, ',', '.') ?></td>
                                    <td><?= $row['ket'] ? $row['ket'] : '-' ?></td>
                                    <td><?= date('d/m/Y', strtotime($row['tgl_bayar'])) ?></td>
                                    <td class="flex gap-2 items-center">
                                        <a href="cetak_transaksi.php?no_transaksi=<?= $row['no_transaksi'] ?>" class="app-button app-button-info app-button-sm" target="_blank">
                                            <i class="mdi mdi-printer"></i>
                                        </a>
                                        <button type="button" class="app-button app-button-warning app-button-sm btn-edit-transaksi" data-id="<?= $row['no_transaksi'] ?>" data-tailwind-modal-target="#modalEdit">
                                            <i class="mdi mdi-pencil"></i>
                                        </button>
                                        <a href="<?= base_url('transaksi.php?hapus_transaksi=' . $row['no_transaksi']) ?>" class="app-button app-button-danger app-button-sm btn-hapus">
                                            <i class="mdi mdi-delete"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah -->
<div class="fixed inset-0 z-[1055] hidden overflow-y-auto bg-slate-950/60 px-4 py-6 backdrop-blur-sm" id="modalTambah" tabindex="-1" role="dialog" aria-hidden="true" data-tailwind-modal>
    <div class="mx-auto flex min-h-full w-full max-w-2xl items-start">
        <form class="flex max-h-[calc(100vh-3rem)] w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl" action="<?= base_url('transaksi.php') ?>" method="post">
            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
                <h5 class="text-base font-extrabold text-slate-900">Tambah Transaksi</h5>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" data-tailwind-modal-close aria-label="Close">
                    <i class="mdi mdi-close"></i>
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div class="app-field">
                        <label>Tanggal Bayar</label>
                        <input type="date" name="tgl_bayar" class="app-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="app-field">
                        <label>Nama Siswa</label>
                        <select name="nisn" id="nisnTambah" class="app-control select2-modal" style="width: 100%;" required>
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($siswa_list as $s) : ?>
                                <option value="<?= $s['nisn'] ?>" data-kelas="<?= $s['nama_kelas'] ?>">
                                    <?= $s['nama'] ?> - <?= $s['nama_kelas'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="app-field">
                        <label>Jenis Bayar</label>
                        <select name="id_jenis_bayar[]" class="app-control select2-multiple" id="jbTambah" multiple="multiple" style="width: 100%;" required data-placeholder="Pilih Jenis Bayar">
                            <?php foreach ($jb_list as $jb) : ?>
                                <option value="<?= $jb['id_jenis_bayar'] ?>" 
                                    data-tipe="<?= $jb['tipe_bayar'] ?>" 
                                    data-nominal="<?= $jb['nominal'] ?>"
                                    data-tagihan="<?= $jb['tagihan_kelas'] ?>"
                                    data-nama="<?= $jb['nama_pembayaran'] ?>">
                                    <?= $jb['nama_pembayaran'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="extraTambah">
                        <!-- Dynamic Content -->
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" class="app-button app-button-secondary" data-tailwind-modal-close>Batal</button>
                    <button type="submit" name="tambah" class="app-button app-button-primary">Simpan</button>
                </div>
        </form>
    </div>
</div>

<!-- Modal Edit -->
<div class="fixed inset-0 z-[1055] hidden overflow-y-auto bg-slate-950/60 px-4 py-6 backdrop-blur-sm" id="modalEdit" tabindex="-1" role="dialog" aria-hidden="true" data-tailwind-modal>
    <div class="mx-auto flex min-h-full w-full max-w-2xl items-start">
        <form class="flex max-h-[calc(100vh-3rem)] w-full flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl" action="<?= base_url('transaksi.php') ?>" method="post">
            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
                <h5 class="text-base font-extrabold text-slate-900">Edit Transaksi</h5>
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" data-tailwind-modal-close aria-label="Close">
                    <i class="mdi mdi-close"></i>
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5" id="modalEditBody">
                    <div class="flex justify-center py-5">
                        <div class="h-8 w-8 animate-spin rounded-full border-2 border-emerald-100 border-t-emerald-600" role="status" aria-label="Loading"></div>
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                    <button type="button" class="app-button app-button-secondary" data-tailwind-modal-close>Batal</button>
                    <button type="submit" name="update_transaksi" class="app-button app-button-primary">Simpan Perubahan</button>
                </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/template/footer.php'; ?>
<!-- Select2 JS -->
<script src="<?= base_url('assets/vendors/select2/select2.min.js') ?>"></script>

<script>
    $(document).ready(function() {
        function getSelect2Parent($element) {
            return $(document.body);
        }

        function initSelect2Field($element, extraOptions) {
            if (!$element.length || !$.fn.select2) return;
            if ($element.hasClass('select2-hidden-accessible')) {
                $element.select2('destroy');
            }
            $element.select2($.extend({
                width: '100%',
                dropdownParent: getSelect2Parent($element),
                placeholder: $element.data('placeholder') || "Pilih Options",
                allowClear: true
            }, extraOptions || {}));
        }

        function formatRibuan(value) {
            var onlyDigits = String(value || '').replace(/\D/g, '');
            if (!onlyDigits) return '';
            return onlyDigits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function normalizeKelas(value) {
            var kelas = String(value || '').trim().toUpperCase().replace(/\s+/g, '');
            var romanMap = {
                'I': '1',
                'II': '2',
                'III': '3',
                'IV': '4',
                'V': '5',
                'VI': '6'
            };

            if (romanMap[kelas]) {
                return romanMap[kelas];
            }

            var numberMatch = kelas.match(/[1-6]/);
            if (numberMatch) {
                return numberMatch[0];
            }

            return kelas;
        }

        /**
         * Jenis bayar dengan tagihan_kelas: hanya aktif jika kelas siswa masuk daftar.
         * Jika siswa belum dipilih, opsi yang punya pembatasan kelas dinonaktifkan.
         */
        function syncJenisBayarByKelas() {
            var kelasSiswa = $('#nisnTambah option:selected').attr('data-kelas');
            kelasSiswa = kelasSiswa ? String(kelasSiswa).trim() : '';
            var kelasSiswaNormalized = normalizeKelas(kelasSiswa);
            var $jb = $('#jbTambah');

            $jb.find('option').each(function () {
                var $o = $(this);
                if (!$o.val()) {
                    return;
                }
                var tagihan = ($o.attr('data-tagihan') || '').trim();
                var allowed = true;
                if (tagihan !== '') {
                    if (kelasSiswa === '') {
                        allowed = false;
                    } else {
                        var parts = tagihan.split(',').map(function (s) { return s.trim(); });
                        var normalizedParts = parts.map(function (s) { return normalizeKelas(s); });
                        allowed = parts.indexOf(kelasSiswa) !== -1 || normalizedParts.indexOf(kelasSiswaNormalized) !== -1;
                    }
                }
                $o.prop('disabled', !allowed);
            });

            var vals = $jb.val() || [];
            if (!Array.isArray(vals)) {
                vals = vals ? [vals] : [];
            }
            var kept = vals.filter(function (v) {
                return !$jb.find('option[value="' + v + '"]').prop('disabled');
            });
            if (kept.length !== vals.length) {
                $jb.val(kept);
            }
            $jb.trigger('change');
        }

        // Initialize Select2 in Modals
        $('.select2-modal').each(function() {
            initSelect2Field($(this), { allowClear: false });
        });

        // Re-adjust Select2 when modal is shown (Crucial for width calculation)
        $('#modalTambah').on('app:modal-open', function () {
            $(this).find('.overflow-y-auto').first().scrollTop(0);
            $(this).find('.select2-modal, .select2-multiple').each(function() {
                initSelect2Field($(this), {
                    allowClear: $(this).is('[multiple]') || !!$(this).data('placeholder')
                });
            });
            syncJenisBayarByKelas();
        });

        $('#modalEdit').on('app:modal-open', function () {
            $(this).find('.overflow-y-auto').first().scrollTop(0);
            $(this).find('.select2-modal, .select2-multiple, .select-jenis-bayar').each(function() {
                initSelect2Field($(this), {
                    allowClear: $(this).is('[multiple]') || !!$(this).data('placeholder')
                });
            });
        });

        // Saat siswa berubah, sesuaikan opsi jenis bayar dengan kelas (Biaya Ujian & Rekreasi hanya kelas 6 sesuai tagihan_kelas)
        $('#nisnTambah').on('change', function() {
            syncJenisBayarByKelas();
        });

        // Handler for Edit Button Click (delegated so DataTables pagination pages 2+ work)
        $(document).on('click', '.btn-edit-transaksi', function() {
            var no_transaksi = $(this).data('id');
            var modalBody = $('#modalEditBody');
            
            // Show loading
            modalBody.html('<div class="flex justify-center py-5"><div class="h-8 w-8 animate-spin rounded-full border-2 border-emerald-100 border-t-emerald-600" role="status" aria-label="Loading"></div></div>');
            
            // Fetch Data
            $.ajax({
                url: 'get_transaksi_detail.php',
                type: 'POST',
                data: { no_transaksi: no_transaksi },
                success: function(response) {
                    modalBody.html(response);
                },
                error: function() {
                    modalBody.html('<div class="py-5 text-center font-semibold text-red-600">Gagal memuat data.</div>');
                }
            });
        });

        // Initialize Select2 Multiple for Bulan
        $('.select2-multiple').each(function() {
            var placeholder = $(this).data('placeholder') || "Pilih Bulan";
            initSelect2Field($(this), {
                placeholder: placeholder,
                allowClear: true
            });
        });

        // Handler for Tambah Transaksi (Multiselect Jenis Bayar)
        $('#jbTambah').on('change', function() {
            var selectedOptions = $(this).find('option:selected');
            var container = $('#extraTambah');
            container.empty();

            selectedOptions.each(function() {
                var opt = $(this);
                var id = opt.val();
                var nama = opt.data('nama');
                var tipe = opt.data('tipe');
                var nominal = opt.data('nominal');
                // Build HTML (opsi tidak valid sudah dicegah oleh syncJenisBayarByKelas)
                var html = '<div class="app-panel mb-2 border"><div class="app-panel-body p-2" style="background: #f8f9fa;">';
                html += '<h6 class="mb-2 text-primary">' + nama + ' (' + tipe + ')</h6>';
                
                html += '<input type="hidden" name="id_jenis_bayar[]" value="' + id + '">';
                html += '<input type="hidden" name="payment[' + id + '][id_jenis_bayar]" value="' + id + '">';

                if (tipe === 'Bulanan') {
                    html += '<div class="app-field">';
                    html += '<label>Bayar Bulan</label>';
                    html += '<select name="payment[' + id + '][bulan_bayar][]" id="bulan_bayar_' + id + '" class="app-control select2-dynamic-bulan" multiple="multiple" style="width: 100%;" required>';
                    var months = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
                    var currentMonthIndex = (new Date().getMonth() + 6) % 12;
                    months.forEach(function(m, i) {
                         var selected = (i === currentMonthIndex) ? 'selected' : '';
                         html += '<option value="' + m + '" ' + selected + '>' + m + '</option>';
                    });
                    html += '</select></div>';
                } else {
                     html += '<div class="app-field"><label>Cicilan Ke</label>';
                     html += '<input type="number" name="payment[' + id + '][cicilan_ke]" class="app-control" value="1" required></div>';
                     html += '<div class="app-field"><label>Nominal</label>';
                     html += '<input type="text" name="payment[' + id + '][nominal]" class="app-control input-nominal-tambah" placeholder="Nominal" inputmode="numeric" autocomplete="off" required></div>';
                }
                html += '</div></div>';
                container.append(html);
            });

            // Re-init Select2 for new elements
            $('.select2-dynamic-bulan').each(function() {
                initSelect2Field($(this), {
                    placeholder: "Pilih Bulan",
                    allowClear: true,
                    dropdownParent: $(document.body)
                });
            });
        });

        // Format nominal dengan pemisah ribuan pada modal tambah.
        $(document).on('input', '.input-nominal-tambah', function() {
            this.value = formatRibuan(this.value);
        });

        $(document).on('blur', '.input-nominal-tambah', function() {
            this.value = formatRibuan(this.value);
        });

        // Format nominal dengan pemisah ribuan pada modal edit.
        $(document).on('input', '.input-nominal-edit', function() {
            this.value = formatRibuan(this.value);
        });

        $(document).on('blur', '.input-nominal-edit', function() {
            this.value = formatRibuan(this.value);
        });

        // Logic for Jenis Bayar Change (Edit Modal only)
        $(document).on('change', '.select-jenis-bayar:not(#jbTambah)', function() {
            var selectedOption = $(this).find('option:selected');
            var tipe = selectedOption.data('tipe');
            var nominal = selectedOption.data('nominal');
            var tagihan = selectedOption.attr('data-tagihan');
            var targetId = $(this).data('target');
            
            // Validation Logic
            var modalId = $(this).closest('[data-tailwind-modal]').attr('id');
            var siswaSelect = $(this).closest('[data-tailwind-modal]').find('select[name="nisn"]');
            var selectedSiswa = siswaSelect.find('option:selected');
            var kelasSiswa = selectedSiswa.attr('data-kelas');
            var kelasSiswaNormalized = normalizeKelas(kelasSiswa);

            if (tagihan && tagihan !== '' && kelasSiswa) {
                var allowedKelas = tagihan.split(',').map(function (s) { return s.trim(); });
                var allowedKelasNormalized = allowedKelas.map(function (s) { return normalizeKelas(s); });
                if (!allowedKelas.includes(kelasSiswa) && !allowedKelasNormalized.includes(kelasSiswaNormalized)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: 'Jenis pembayaran ini tidak tersedia untuk Kelas ' + kelasSiswa
                    });
                    $(this).val('').trigger('change'); // Reset selection
                    return;
                }
            }

            // Show/Hide Fields (Edit Modal Logic)
            if (tipe === 'Cicilan') {
                $(targetId).find('.cicilan-group').show();
                $(targetId).find('.nominal-group').show();
                $(targetId).find('.bulan-group').hide();
                $(targetId).find('input[name="cicilan_ke"]').prop('required', true);
                $(targetId).find('input[name="nominal"]').prop('required', true);
                $(targetId).find('select[name="bulan_bayar[]"]').prop('required', false);
            } else if (tipe === 'Bulanan') {
                $(targetId).find('.cicilan-group').hide();
                $(targetId).find('.nominal-group').hide();
                $(targetId).find('.bulan-group').show();
                $(targetId).find('input[name="cicilan_ke"]').prop('required', false);
                $(targetId).find('input[name="nominal"]').prop('required', false);
                $(targetId).find('select[name="bulan_bayar[]"]').prop('required', true);
            } else {
                $(targetId).find('.cicilan-group').hide();
                $(targetId).find('.nominal-group').hide();
                $(targetId).find('.bulan-group').hide();
            }
        });
        
        // Delete Confirmation (delegated so DataTables pagination pages 2+ work)
        $(document).on('click', '.btn-hapus', function(e) {
            e.preventDefault();
            const href = $(this).attr('href');
            Swal.fire({
                title: 'Apakah anda yakin?',
                text: "Data yang dihapus tidak dapat dikembalikan!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, hapus!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            })
        });
    });
</script>
