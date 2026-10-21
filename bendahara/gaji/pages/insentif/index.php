<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_insentif.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Data Insentif';


$conn->query("CREATE TABLE IF NOT EXISTS insentif (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_insentif VARCHAR(100) NOT NULL,
    jumlah_insentif DECIMAL(15,2) NOT NULL DEFAULT 0,
    kali INT UNSIGNED NOT NULL DEFAULT 1,
    aktif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$chk_kali_ins = $conn->query("SHOW COLUMNS FROM insentif LIKE 'kali'");
if ($chk_kali_ins && $chk_kali_ins->num_rows === 0) {
    $conn->query("ALTER TABLE insentif ADD COLUMN kali INT UNSIGNED NOT NULL DEFAULT 1 AFTER jumlah_insentif");
}

$conn->query("CREATE TABLE IF NOT EXISTS insentif_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guru_id INT NOT NULL,
    insentif_id INT NOT NULL,
    jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
    periode VARCHAR(7) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_guru_id (guru_id),
    INDEX idx_insentif_id (insentif_id),
    CONSTRAINT fk_insentif_detail_guru FOREIGN KEY (guru_id) REFERENCES guru(id) ON DELETE CASCADE,
    CONSTRAINT fk_insentif_detail_insentif FOREIGN KEY (insentif_id) REFERENCES insentif(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$settings_sql = "SELECT nama_madrasah, tahun_ajaran, logo FROM settings LIMIT 1";
$settings_result = $conn->query($settings_sql);
$print_settings = $settings_result ? $settings_result->fetch_assoc() : [];
$print_nama_madrasah = $print_settings['nama_madrasah'] ?? 'Madrasah Ibtidaiyah';
$print_tahun_ajaran = $print_settings['tahun_ajaran'] ?? ($tahun_ajaran ?? '');
$print_logo_url = '';
if (!empty($print_settings['logo'])) {
    $print_logo_url = BASE_URL . 'assets/img/' . $print_settings['logo'];
}

$sql = "SELECT i.*,
        (SELECT GROUP_CONCAT(DISTINCT g.nama_lengkap ORDER BY g.nama_lengkap SEPARATOR ', ')
         FROM insentif_detail idt
         JOIN guru g ON idt.guru_id = g.id
         WHERE idt.insentif_id = i.id) AS nama_guru_list
        FROM insentif i
        ORDER BY i.nama_insentif ASC";
$result = $conn->query($sql);
$insentif = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$sql = "SELECT * FROM insentif WHERE aktif = 1 ORDER BY nama_insentif ASC";
$result = $conn->query($sql);
$insentif_aktif = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$sql = "SELECT id, nama_lengkap FROM guru ORDER BY nama_lengkap ASC";
$all_guru = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

$sql = "SELECT DISTINCT g.id AS guru_id, g.nama_lengkap
        FROM insentif_detail idt
        JOIN guru g ON idt.guru_id = g.id
        JOIN insentif i ON idt.insentif_id = i.id
        WHERE i.aktif = 1
        GROUP BY g.id, g.nama_lengkap
        HAVING COALESCE(SUM(idt.jumlah), 0) > 0
        ORDER BY LOWER(TRIM(g.nama_lengkap)) ASC, g.nama_lengkap ASC";
$result = $conn->query($sql);
$legger_guru = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$sql = "SELECT guru_id, insentif_id, SUM(jumlah) AS jumlah
        FROM insentif_detail idt
        JOIN insentif i ON idt.insentif_id = i.id
        WHERE i.aktif = 1
        GROUP BY guru_id, insentif_id";
$result = $conn->query($sql);
$legger_detail_rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$legger_detail_map = [];
foreach ($legger_detail_rows as $row) {
    $gid = intval($row['guru_id']);
    $iid = intval($row['insentif_id']);
    if (!isset($legger_detail_map[$gid])) {
        $legger_detail_map[$gid] = [];
    }
    $legger_detail_map[$gid][$iid] = floatval($row['jumlah'] ?? 0);
}
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Insentif</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item active">Data Insentif</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Data Insentif</h4>
                                <div class="card-header-action" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <div id="tableInsentif_buttons" style="display: flex; gap: 5px; flex-wrap: wrap;"></div>
                                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalTambah">
                                        <i class="fas fa-plus"></i> Tambah Insentif
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tableInsentif">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Insentif</th>
                                                <th>Jumlah Satuan</th>
                                                <th>Kali (×)</th>
                                                <th>Total Insentif</th>
                                                <th>Nama Guru</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($insentif as $i): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($i['nama_insentif']); ?></td>
                                                    <?php
                                                    $__ji = floatval($i['jumlah_insentif'] ?? 0);
                                                    $__kali = max(1, (int)($i['kali'] ?? 1));
                                                    $__tot = $__ji * $__kali;
                                                    ?>
                                                    <td><?php echo formatRupiah((int)$__ji); ?></td>
                                                    <td><?php echo number_format($__kali, 0, ',', '.'); ?></td>
                                                    <td><strong><?php echo formatRupiah((float)$__tot); ?></strong></td>
                                                    <td class="nama-guru-cell">
                                                        <?php
                                                        $nama_guru = $i['nama_guru_list'] ?? '';
                                                        if (empty($nama_guru)) {
                                                            echo '<span class="text-muted">-</span>';
                                                        } else {
                                                            echo '<span class="nama-guru-text">' . htmlspecialchars($nama_guru) . '</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $i['aktif'] ? 'success' : 'danger'; ?>">
                                                            <?php echo $i['aktif'] ? 'Aktif' : 'Tidak Aktif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info btn-edit-insentif" data-id="<?php echo $i['id']; ?>" data-toggle="tooltip" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?php echo BASE_URL; ?>pages/insentif/delete?id=<?php echo $i['id']; ?>')" data-toggle="tooltip" title="Hapus">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h4>Legger Insentif</h4>
                                <div class="card-header-action" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <div id="tableLeggerInsentif_buttons" style="display: flex; gap: 5px; flex-wrap: wrap;"></div>
                                    <a href="<?php echo $gaji_page_base; ?>cetak_slip_semua?v=<?php echo (int)@filemtime(__DIR__ . '/cetak_slip_semua.php'); ?>" class="btn btn-warning" target="_blank">
                                        <i class="fas fa-file-invoice"></i> Cetak Slip Semua
                                    </a>
                                    <a href="<?php echo $gaji_page_base; ?>cetak_legger?v=<?php echo (int)@filemtime(__DIR__ . '/cetak_legger.php'); ?>" class="btn btn-primary" target="_blank">
                                        <i class="fas fa-print"></i> Cetak Legger Insentif
                                    </a>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tableLeggerInsentif">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Guru</th>
                                                <?php foreach ($insentif_aktif as $i): ?>
                                                    <th><?php echo htmlspecialchars($i['nama_insentif']); ?></th>
                                                <?php endforeach; ?>
                                                <th>Total Insentif</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($legger_guru)): ?>
                                                <tr>
                                                    <td colspan="<?php echo 4 + count($insentif_aktif); ?>" class="text-center">
                                                        <p class="text-muted mb-0">Tidak ada data leger insentif.</p>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php $no2 = 1; foreach ($legger_guru as $l): ?>
                                                    <?php
                                                    $gid = intval($l['guru_id']);
                                                    $row_total = 0;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo $no2++; ?></td>
                                                        <td><strong><?php echo htmlspecialchars($l['nama_lengkap']); ?></strong></td>
                                                        <?php foreach ($insentif_aktif as $i): ?>
                                                            <?php
                                                            $iid = intval($i['id']);
                                                            $val = floatval($legger_detail_map[$gid][$iid] ?? 0);
                                                            $row_total += $val;
                                                            ?>
                                                            <td style="text-align: right; white-space: nowrap;"><?php echo $val > 0 ? formatRupiahTanpaRp($val) : '-'; ?></td>
                                                        <?php endforeach; ?>
                                                        <td style="text-align: right; white-space: nowrap; font-weight: bold;"><?php echo formatRupiahTanpaRp($row_total); ?></td>
                                                        <td>
                                                            <a href="<?php echo $gaji_page_base; ?>cetak_slip?guru_id=<?php echo intval($l['guru_id']); ?>" class="btn btn-sm btn-info" target="_blank">
                                                                <i class="fas fa-print"></i> Cetak Slip
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="modal fade" id="modalTambah" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalTitle">Tambah Insentif</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
<form id="formInsentif" method="POST" action="<?php echo $gaji_page_base; ?>save">
    <?php echo csrfField(); ?>
    <input type="hidden" name="id" id="insentif_id">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Nama Insentif <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="nama_insentif" id="nama_insentif" required>
                                </div>
                                <div class="form-group">
                                    <label>Jumlah Insentif (satuan) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Rp</span>
                                        </div>
                                        <input type="text" class="form-control" name="jumlah_insentif" id="jumlah_insentif" placeholder="0" required autocomplete="off">
                                        <input type="hidden" name="jumlah_insentif_hidden" id="jumlah_insentif_hidden" value="0">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Kali (×) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">×</span>
                                        </div>
                                        <input type="text" class="form-control" id="kali_insentif" placeholder="1" maxlength="14" autocomplete="off">
                                        <input type="hidden" name="kali_hidden" id="kali_hidden" value="1">
                                    </div>
                                    <small class="text-muted">Pengali jumlah satuan untuk setiap guru</small>
                                </div>
                                <div class="form-group">
                                    <label>Total Insentif</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Rp</span>
                                        </div>
                                        <input type="text" class="form-control bg-light" id="total_insentif_display" value="0" readonly tabindex="-1">
                                    </div>
                                    <small class="text-muted">Jumlah satuan × kali</small>
                                </div>
                                <div class="form-group">
                                    <label>Nama Guru <span class="text-danger">*</span></label>
                                    <div class="dropdown-checkbox-wrapper" style="position: relative;">
                                        <button type="button" class="btn btn-secondary dropdown-toggle w-100 text-left" id="guruDropdownBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="text-align: left;">
                                            <span id="guruSelectedText">Pilih Guru</span>
                                            <span class="float-right"><i class="fas fa-chevron-down"></i></span>
                                        </button>
                                        <div class="dropdown-menu w-100" id="guruDropdownMenu" style="max-height: 300px; overflow-y: auto;">
                                            <div class="px-3 py-2 border-bottom">
                                                <input type="text" class="form-control form-control-sm" id="guruSearchInput" placeholder="Cari nama guru..." autocomplete="off">
                                            </div>
                                            <div class="px-3 py-2 border-bottom">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="selectAllGuru">
                                                    <label class="form-check-label font-weight-bold" for="selectAllGuru">
                                                        Pilih Semua
                                                    </label>
                                                </div>
                                            </div>
                                            <div id="guruListContainer">
                                                <?php foreach ($all_guru as $guru): ?>
                                                <div class="dropdown-item guru-item" data-name="<?php echo strtolower(htmlspecialchars($guru['nama_lengkap'])); ?>">
                                                    <div class="form-check">
                                                        <input class="form-check-input guru-checkbox" type="checkbox" name="guru_ids[]" value="<?php echo $guru['id']; ?>" id="guru_<?php echo $guru['id']; ?>">
                                                        <label class="form-check-label" for="guru_<?php echo $guru['id']; ?>">
                                                            <?php echo htmlspecialchars($guru['nama_lengkap']); ?>
                                                        </label>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div id="guruNoResults" class="px-3 py-2 text-muted text-center" style="display: none;">
                                                <small>Tidak ada guru yang ditemukan</small>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="text-muted">Pilih guru yang mendapat insentif ini</small>
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="aktif" id="aktif" value="1" checked>
                                        <label class="form-check-label" for="aktif">Aktif</label>
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
            </div>

            <style>
.dropdown-checkbox-wrapper .dropdown-menu {
    padding: 0;
    border: 1px solid #ddd;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.dropdown-checkbox-wrapper .dropdown-item {
    padding: 8px 15px;
    cursor: pointer;
}

.dropdown-checkbox-wrapper .dropdown-item:hover {
    background-color: #f8f9fa;
}

.dropdown-checkbox-wrapper .dropdown-item .form-check {
    margin: 0;
}

.dropdown-checkbox-wrapper .dropdown-item .form-check-label {
    cursor: pointer;
    width: 100%;
    margin-left: 5px;
}

.dropdown-checkbox-wrapper .dropdown-item:first-child {
    border-bottom: 1px solid #ddd;
    background-color: #f8f9fa;
}

#guruDropdownBtn {
    position: relative;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-right: 35px !important;
}

#guruSelectedText {
    display: inline-block;
    max-width: calc(100% - 30px);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}

#guruDropdownBtn .float-right {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    flex-shrink: 0;
}

.nama-guru-cell {
    min-width: 200px;
    max-width: 400px;
    white-space: normal;
    word-wrap: break-word;
    vertical-align: top;
    padding: 10px 8px !important;
}

.nama-guru-text {
    display: block;
    line-height: 1.6;
    word-break: break-word;
}

#tableInsentif tbody tr {
    height: auto !important;
}

#tableInsentif tbody td {
    height: auto !important;
}

/* Sticky columns for Legger Insentif */
#tableLeggerInsentif {
    border-collapse: separate;
    border-spacing: 0;
}
#tableLeggerInsentif thead th {
    text-align: center;
}
#tableLeggerInsentif tbody td {
    white-space: nowrap;
}
#tableLeggerInsentif thead th:nth-child(1),
#tableLeggerInsentif tbody td:nth-child(1) {
    position: sticky;
    left: 0;
    z-index: 3;
    background-color: #fff;
}
#tableLeggerInsentif thead th:nth-child(2),
#tableLeggerInsentif tbody td:nth-child(2) {
    position: sticky;
    left: 45px;
    z-index: 2;
    background-color: #fff;
    white-space: nowrap;
}
#tableLeggerInsentif thead th:nth-child(1),
#tableLeggerInsentif thead th:nth-child(2) {
    z-index: 4;
}
#tableLeggerInsentif tbody td:nth-child(1)::after,
#tableLeggerInsentif tbody td:nth-child(2)::after {
    content: '';
    position: absolute;
    top: 0;
    right: -1px;
    height: 100%;
    width: 1px;
    background: #dee2e6;
}
/* DataTable wrapper needs to allow sticky */
#tableLeggerInsentif_wrapper {
    overflow-x: auto;
    overflow-y: visible;
}
            </style>

            <div id="print-config-insentif"
                 data-nama="<?php echo htmlspecialchars($print_nama_madrasah, ENT_QUOTES); ?>"
                 data-tahun="<?php echo htmlspecialchars($print_tahun_ajaran, ENT_QUOTES); ?>"
                 data-logo="<?php echo htmlspecialchars($print_logo_url, ENT_QUOTES); ?>"></div>

            <script>
var printConfigInsentifEl = document.getElementById('print-config-insentif') || null;
var printNamaMadrasah = printConfigInsentifEl ? (printConfigInsentifEl.getAttribute('data-nama') || '') : '';
var printTahunAjaran = printConfigInsentifEl ? (printConfigInsentifEl.getAttribute('data-tahun') || '') : '';
var printLogoUrl = printConfigInsentifEl ? (printConfigInsentifEl.getAttribute('data-logo') || '') : '';

function formatRupiah(angka) {
    var number_string = angka.toString().replace(/[^\d]/g, '');
    if (number_string === '' || number_string === '0') return '0';
    number_string = number_string.replace(/^0+/, '') || '0';
    var sisa = number_string.length % 3;
    var rupiah = number_string.substr(0, sisa);
    var ribuan = number_string.substr(sisa).match(/\d{3}/g);
    if (ribuan) {
        var separator = sisa ? '.' : '';
        rupiah += separator + ribuan.join('.');
    }
    return rupiah;
}

function refreshTotalInsentifModal() {
    var unitStr = $('#jumlah_insentif_hidden').val() || '0';
    var unit = parseFloat(unitStr.replace(',', '.'));
    if (isNaN(unit) || unit < 0) unit = 0;
    /* Jangan tulis ulang #kali_hidden di sini — itu membatalkan apa yang pengguna tik di field × */
    var kh = $('#kali_hidden').val();
    var khTrim = kh === undefined || kh === null ? '' : String(kh).trim();
    var kali = 1;
    if (khTrim !== '') {
        var parsed = parseInt(khTrim, 10);
        if (!isNaN(parsed) && parsed >= 1) kali = parsed;
    }
    var total = Math.round(unit * kali);
    if (typeof total !== 'number' || isNaN(total) || total < 0) total = 0;
    $('#total_insentif_display').val(formatRupiah(String(total)));
}

function unformatRupiah(rupiah) {
    if (!rupiah || rupiah === '') return 0;
    var cleaned = rupiah.toString().replace(/\./g, '').replace(/[^0-9]/g, '');
    return parseFloat(cleaned) || 0;
}

function updateGuruSelectedText() {
    var checked = document.querySelectorAll('.guru-checkbox:checked');
    var textEl = document.getElementById('guruSelectedText');
    if (!textEl) return;
    if (!checked || checked.length === 0) {
        textEl.textContent = 'Pilih Guru';
        return;
    }
    if (checked.length === 1) {
        var label = document.querySelector('label[for="' + checked[0].id + '"]');
        textEl.textContent = label ? (label.textContent || '').trim() : '1 guru dipilih';
        return;
    }
    textEl.textContent = checked.length + ' guru dipilih';
}

function resetInsentifForm() {
    var form = document.getElementById('formInsentif');
    if (form) {
        form.reset();
    }
    var idEl = document.getElementById('insentif_id');
    if (idEl) idEl.value = '';
    var hiddenEl = document.getElementById('jumlah_insentif_hidden');
    if (hiddenEl) hiddenEl.value = '0';
    var jumlahEl = document.getElementById('jumlah_insentif');
    if (jumlahEl) jumlahEl.value = '';
    var kaliHidden = document.getElementById('kali_hidden');
    if (kaliHidden) kaliHidden.value = '1';
    var kaliIn = document.getElementById('kali_insentif');
    if (kaliIn) kaliIn.value = '1';
    var checkboxes = document.querySelectorAll('.guru-checkbox');
    checkboxes.forEach(function(cb) { cb.checked = false; });
    var selectAll = document.getElementById('selectAllGuru');
    if (selectAll) selectAll.checked = false;
    updateGuruSelectedText();
    if (typeof jQuery !== 'undefined') {
        refreshTotalInsentifModal();
    }
}

function editInsentif(id) {
    if (typeof jQuery === 'undefined') {
        setTimeout(function() { editInsentif(id); }, 100);
        return;
    }
    var $ = jQuery;
    $.ajax({
        url: '<?php echo $gaji_page_base; ?>get?id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            if (data && data.error) {
                Swal.fire('Error', data.error, 'error');
                return;
            }
            if (!data || !data.id) {
                Swal.fire('Error', 'Data tidak valid', 'error');
                return;
            }
            resetInsentifForm();
            $('#insentif_id').val(parseInt(data.id, 10).toString());
            $('#nama_insentif').val(data.nama_insentif || '');
            var jumlah = parseFloat(data.jumlah_insentif || 0);
            if (isNaN(jumlah)) jumlah = 0;
            $('#jumlah_insentif_hidden').val(jumlah.toString());
            $('#jumlah_insentif').val(formatRupiah(Math.floor(jumlah).toString()));
            var kali = parseInt(data.kali, 10);
            if (isNaN(kali) || kali < 1) kali = 1;
            $('#kali_hidden').val(kali.toString());
            $('#kali_insentif').val(formatRupiah(String(kali)));
            refreshTotalInsentifModal();
            $('#aktif').prop('checked', data.aktif == 1 || data.aktif == '1' || data.aktif === 1);
            $.ajax({
                url: '<?php echo $gaji_page_base; ?>get_guru?insentif_id=' + data.id,
                type: 'GET',
                dataType: 'json',
                success: function(guruData) {
                    $('.guru-checkbox').prop('checked', false);
                    $('#selectAllGuru').prop('checked', false);
                    if (guruData && guruData.guru_ids && guruData.guru_ids.length > 0) {
                        guruData.guru_ids.forEach(function(guruId) {
                            $('#guru_' + guruId).prop('checked', true);
                        });
                        var total = $('.guru-checkbox').length;
                        var checked = $('.guru-checkbox:checked').length;
                        $('#selectAllGuru').prop('checked', checked === total);
                    }
                    updateGuruSelectedText();
                    $('#modalTitle').text('Edit Insentif');
                    $('#modalTambah').modal('show');
                },
                error: function() {
                    updateGuruSelectedText();
                    $('#modalTitle').text('Edit Insentif');
                    $('#modalTambah').modal('show');
                }
            });
        },
        error: function(xhr, status, error) {
            Swal.fire('Error', 'Gagal memuat data insentif: ' + error, 'error');
        }
    });
}

(function initInsentifPage() {
    function init() {
        if (typeof jQuery === 'undefined') {
            setTimeout(init, 50);
            return;
        }
        var $ = jQuery;
        $(document).ready(function() {
            $(document).on('click', '.btn-edit-insentif', function() {
                var id = $(this).data('id');
                if (id) editInsentif(id);
            });

            $('#modalTambah').on('hidden.bs.modal', function() {
                resetInsentifForm();
                $('#modalTitle').text('Tambah Insentif');
            });

            $('#modalTambah').on('show.bs.modal', function(e) {
                var trigger = e.relatedTarget;
                if (trigger) {
                    $('#modalTitle').text('Tambah Insentif');
                    resetInsentifForm();
                }
            });

            $('#jumlah_insentif').on('input', function() {
                var raw = unformatRupiah(this.value);
                $('#jumlah_insentif_hidden').val(raw.toString());
                this.value = formatRupiah(raw.toString());
                refreshTotalInsentifModal();
            });

            $('#kali_insentif').on('input', function() {
                var digits = ($(this).val() || '').replace(/\./g, '').replace(/[^0-9]/g, '');
                if (digits === '') {
                    $('#kali_hidden').val('');
                    $(this).val('');
                    refreshTotalInsentifModal();
                    return;
                }
                var kali = parseInt(digits, 10);
                if (isNaN(kali)) return;
                if (kali < 1) kali = 1;
                if (kali > 2147483646) kali = 2147483646;
                $('#kali_hidden').val(kali.toString());
                $(this).val(formatRupiah(String(kali)));
                refreshTotalInsentifModal();
            });

            $('#kali_insentif').on('blur', function() {
                var k = ($('#kali_hidden').val() || '').trim();
                var kali = parseInt(k, 10);
                if (k === '' || isNaN(kali) || kali < 1) {
                    kali = 1;
                    $('#kali_hidden').val('1');
                }
                $(this).val(formatRupiah(String(kali)));
                refreshTotalInsentifModal();
            });

            $(document).on('change', '.guru-checkbox', function() {
                var total = $('.guru-checkbox').length;
                var checked = $('.guru-checkbox:checked').length;
                $('#selectAllGuru').prop('checked', checked === total);
                updateGuruSelectedText();
            });

            $('#selectAllGuru').on('change', function() {
                var checked = $(this).is(':checked');
                $('.guru-checkbox').prop('checked', checked);
                updateGuruSelectedText();
            });

            $('#guruSearchInput').on('input', function() {
                var search = (this.value || '').toLowerCase().trim();
                var anyVisible = false;
                $('.guru-item').each(function() {
                    var name = ($(this).data('name') || '').toString();
                    var visible = search === '' || name.indexOf(search) !== -1;
                    $(this).toggle(visible);
                    if (visible) anyVisible = true;
                });
                $('#guruNoResults').toggle(!anyVisible);
            });

            $('#formInsentif').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var raw = unformatRupiah($('#jumlah_insentif').val() || '');
                $('#jumlah_insentif_hidden').val(raw.toString());
                var kHi = $('#kali_hidden').val();
                var kali = parseInt(kHi || '1', 10);
                if (isNaN(kali) || kali < 1) kali = 1;
                $('#kali_hidden').val(kali.toString());
                refreshTotalInsentifModal();

                Swal.fire({
                    title: 'Menyimpan...',
                    allowOutsideClick: false,
                    didOpen: function() {
                        Swal.showLoading();
                    }
                });

                $.ajax({
                    url: $form.attr('action'),
                    type: 'POST',
                    data: $form.serialize(),
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        if (response && response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil',
                                text: response.message || 'Data insentif berhasil disimpan',
                                timer: 1200,
                                timerProgressBar: true,
                                showConfirmButton: false
                            }).then(function() {
                                window.location.reload();
                            });
                            return;
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: (response && response.message) ? response.message : 'Gagal menyimpan data insentif'
                        });
                    },
                    error: function(xhr, status, error) {
                        Swal.close();
                        var errorMsg = 'Gagal menyimpan data: ' + error;
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response && response.message) {
                                errorMsg = response.message;
                            }
                        } catch(e) {}
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: errorMsg
                        });
                    }
                });
                return false;
            });
        });
    }
    init();
})();

(function initInsentifDataTable() {
    var retryCount = 0;
    var maxRetries = 40;
    function init() {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') {
            retryCount++;
            if (retryCount < maxRetries) {
                setTimeout(init, 50);
            }
            return;
        }
        var $ = jQuery;
        $(document).ready(function() {
            if ($.fn.DataTable.isDataTable('#tableInsentif')) {
                $('#tableInsentif').DataTable().destroy();
            }
            var table = $('#tableInsentif').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        exportOptions: { columns: ':not(:last-child)' }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        title: '',
                        exportOptions: { columns: ':not(:last-child)' },
                        customize: function(win) {
                            try {
                                var doc = win.document;
                                var body = doc.body;
                                var headerDiv = doc.createElement('div');
                                headerDiv.style.marginBottom = '15px';
                                var html = '';
                                html += '<table style="width:100%; border-collapse:collapse;">';
                                html += '<tr>';
                                html += '<td style="width:80px; text-align:left; vertical-align:top;">';
                                if (printLogoUrl) {
                                    html += '<img src="' + printLogoUrl + '" style="max-height:60px; max-width:60px;" />';
                                }
                                html += '</td>';
                                html += '<td style="text-align:left; vertical-align:middle;">';
                                if (printNamaMadrasah) {
                                    html += '<div style="font-size:18px; font-weight:bold; text-transform:uppercase;">' + printNamaMadrasah + '</div>';
                                }
                                html += '<div style="font-size:14px;">Data Insentif</div>';
                                if (printTahunAjaran) {
                                    html += '<div style="font-size:12px;">Tahun Ajaran ' + printTahunAjaran + '</div>';
                                }
                                html += '</td>';
                                html += '</tr>';
                                html += '</table>';
                                headerDiv.innerHTML = html;
                                body.insertBefore(headerDiv, body.firstChild);
                            } catch (e) {}
                        }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Print',
                        className: 'btn btn-info btn-sm',
                        title: '',
                        exportOptions: { columns: ':not(:last-child)' },
                        customize: function(win) {
                            try {
                                var doc = win.document;
                                var body = doc.body;
                                var headerDiv = doc.createElement('div');
                                headerDiv.style.marginBottom = '15px';
                                var html = '';
                                html += '<table style="width:100%; border-collapse:collapse;">';
                                html += '<tr>';
                                html += '<td style="width:80px; text-align:left; vertical-align:top;">';
                                if (printLogoUrl) {
                                    html += '<img src="' + printLogoUrl + '" style="max-height:60px; max-width:60px;" />';
                                }
                                html += '</td>';
                                html += '<td style="text-align:left; vertical-align:middle;">';
                                if (printNamaMadrasah) {
                                    html += '<div style="font-size:18px; font-weight:bold; text-transform:uppercase;">' + printNamaMadrasah + '</div>';
                                }
                                html += '<div style="font-size:14px;">Data Insentif</div>';
                                if (printTahunAjaran) {
                                    html += '<div style="font-size:12px;">Tahun Ajaran ' + printTahunAjaran + '</div>';
                                }
                                html += '</td>';
                                html += '</tr>';
                                html += '</table>';
                                headerDiv.innerHTML = html;
                                body.insertBefore(headerDiv, body.firstChild);
                            } catch (e) {}
                        }
                    }
                ],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' },
                order: [[1, 'asc']],
                stateSave: false,
                retrieve: false,
                autoWidth: false,
                columnDefs: [
                    { targets: 0, orderable: false, searchable: false }
                ]
            });
            var buttonsContainer = table.buttons().container();
            var targetContainer = $('#tableInsentif_buttons');
            if (buttonsContainer.length > 0 && targetContainer.length > 0) {
                if (buttonsContainer.parent().attr('id') !== 'tableInsentif_buttons') {
                    buttonsContainer.appendTo(targetContainer);
                }
                buttonsContainer.css({ display: 'flex', 'flex-wrap': 'wrap', gap: '5px' });
                buttonsContainer.find('.dt-button').css({ margin: '0', display: 'inline-block' });
            }

            // Table legger insentif: jangan inisialisasi DataTable bila kosong.
            // Baris "tidak ada data" memakai 1 sel dengan colspan sehingga tidak cocok dengan jumlah kolom.
            var $leggerRows = $('#tableLeggerInsentif tbody tr').filter(function () {
                var $td = $(this).find('td');
                return $td.length > 0 && !$td.attr('colspan');
            });
            if ($leggerRows.length === 0) {
                return;
            }

            if ($.fn.DataTable.isDataTable('#tableLeggerInsentif')) {
                $('#tableLeggerInsentif').DataTable().destroy();
            }
            var table2 = $('#tableLeggerInsentif').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        exportOptions: { columns: ':not(:last-child)' }
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Print',
                        className: 'btn btn-info btn-sm',
                        title: '',
                        exportOptions: { columns: ':not(:last-child)' },
                        customize: function(win) {
                            try {
                                var doc = win.document;
                                var body = doc.body;
                                var headerDiv = doc.createElement('div');
                                headerDiv.style.marginBottom = '15px';
                                var html = '';
                                html += '<table style="width:100%; border-collapse:collapse;">';
                                html += '<tr>';
                                html += '<td style="width:80px; text-align:left; vertical-align:top;">';
                                if (printLogoUrl) {
                                    html += '<img src="' + printLogoUrl + '" style="max-height:60px; max-width:60px;" />';
                                }
                                html += '</td>';
                                html += '<td style="text-align:left; vertical-align:middle;">';
                                if (printNamaMadrasah) {
                                    html += '<div style="font-size:18px; font-weight:bold; text-transform:uppercase;">' + printNamaMadrasah + '</div>';
                                }
                                html += '<div style="font-size:14px;">Legger Insentif</div>';
                                if (printTahunAjaran) {
                                    html += '<div style="font-size:12px;">Tahun Ajaran ' + printTahunAjaran + '</div>';
                                }
                                html += '</td>';
                                html += '</tr>';
                                html += '</table>';
                                headerDiv.innerHTML = html;
                                body.insertBefore(headerDiv, body.firstChild);
                            } catch (e) {}
                        }
                    }
                ],
                language: { url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' },
                order: [[1, 'asc']],
                stateSave: false,
                retrieve: false,
                autoWidth: false,
                columnDefs: [
                    { targets: 0, orderable: false, searchable: false }
                ]
            });
            var buttonsContainer2 = table2.buttons().container();
            var targetContainer2 = $('#tableLeggerInsentif_buttons');
            if (buttonsContainer2.length > 0 && targetContainer2.length > 0) {
                if (buttonsContainer2.parent().attr('id') !== 'tableLeggerInsentif_buttons') {
                    buttonsContainer2.appendTo(targetContainer2);
                }
                buttonsContainer2.css({ display: 'flex', 'flex-wrap': 'wrap', gap: '5px' });
                buttonsContainer2.find('.dt-button').css({ margin: '0', display: 'inline-block' });
            }
        });
    }
    init();
})();
            </script>
