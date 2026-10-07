<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/learning_schema.php';

ensure_learning_schema($pdo);

if (!isAuthorized(['wali', 'admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$is_admin_or_kepala = in_array($user_level, ['admin', 'kepala_madrasah'], true) || in_array($_GET['session_type'] ?? '', ['admin', 'kepala_madrasah'], true);
$guru_id = getCurrentGuruId($pdo);
if ($guru_id <= 0 && isset($_SESSION['user_id'])) {
    $guru_id = (int)$_SESSION['user_id'];
}

$message = null;

// Handle CRUD Master Pelanggaran
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $kategori = trim((string)($_POST['kategori'] ?? 'Ringan'));
        $jenis = trim((string)($_POST['jenis'] ?? ''));
        $tindakan = trim((string)($_POST['tindakan'] ?? ''));
        $poin = (int)($_POST['poin'] ?? 0);

        if ($jenis === '' || $tindakan === '') {
            $message = ['type' => 'warning', 'text' => 'Jenis pelanggaran dan tindakan/sanksi wajib diisi.'];
        } else {
            $detM = function_exists('pelanggaran_deteksi_jenis') ? pelanggaran_deteksi_jenis($jenis, $kategori) : ['jenis' => 'Kedisiplinan'];
            $jenis_binaan = trim((string)($_POST['jenis_binaan'] ?? ''));
            if (!in_array($jenis_binaan, ['Kedisiplinan', 'Kehadiran', 'Akademik', 'Sikap', 'Sosial'], true)) {
                $jenis_binaan = $detM['jenis'];
            }
            try {
                if ($action === 'tambah') {
                    $st = $pdo->prepare("
                        INSERT INTO tb_master_pelanggaran (id_guru, kategori, jenis, tindakan, poin, jenis_binaan)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $st->execute([$guru_id, $kategori, $jenis, $tindakan, $poin, $jenis_binaan]);
                    $message = ['type' => 'success', 'text' => 'Template pelanggaran berhasil ditambahkan.'];
                } else {
                    $st = $pdo->prepare("
                        UPDATE tb_master_pelanggaran SET
                            kategori = ?, jenis = ?, tindakan = ?, poin = ?, jenis_binaan = ?
                        WHERE id = ? " . (!$is_admin_or_kepala ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : "") . "
                    ");
                    $st->execute([$kategori, $jenis, $tindakan, $poin, $jenis_binaan, $id]);
                    $message = ['type' => 'success', 'text' => 'Template pelanggaran berhasil diperbarui.'];
                }
            } catch (Exception $e) {
                $message = ['type' => 'danger', 'text' => 'Gagal menyimpan: ' . $e->getMessage()];
            }
        }
    } elseif ($action === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $st = $pdo->prepare("DELETE FROM tb_master_pelanggaran WHERE id = ? " . (!$is_admin_or_kepala ? "AND (id_guru = $guru_id OR id_guru IS NULL OR id_guru = 0)" : ""));
            $st->execute([$id]);
            $message = ['type' => 'success', 'text' => 'Template pelanggaran berhasil dihapus.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
        }
    }
}

// Filter kategori
$kategori_list = ['Ringan', 'Sedang', 'Berat'];
$f_kategori = trim((string)($_GET['f_kategori'] ?? ''));
$where = ["1=1"];
$params = [];
if (!$is_admin_or_kepala) {
    $where[] = "(id_guru = ? OR id_guru IS NULL OR id_guru = 0)";
    $params[] = $guru_id;
}
if ($f_kategori !== '') {
    $where[] = "kategori = ?";
    $params[] = $f_kategori;
}
$where_sql = implode(' AND ', $where);
$st = $pdo->prepare("SELECT * FROM tb_master_pelanggaran WHERE $where_sql ORDER BY FIELD(kategori, 'Ringan', 'Sedang', 'Berat'), poin ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Data Pelanggaran Siswa';
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
];
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
];

$js_page = [<<<'JS'
$(document).ready(function() {
    if ($('#table-master-langgar').length) {
        $('#table-master-langgar').DataTable({
            'language': {
                'lengthMenu': 'Tampilkan _MENU_ entri',
                'zeroRecords': 'Tidak ada template pelanggaran',
                'info': 'Menampilkan _START_ sampai _END_ dari _TOTAL_ entri',
                'infoEmpty': 'Menampilkan 0 entri',
                'search': 'Cari:',
                'paginate': { 'first': 'Pertama', 'last': 'Terakhir', 'next': 'Selanjutnya', 'previous': 'Sebelumnya' }
            }
        });
    }

    function deteksiJenisBinaanMaster(teks, kategori) {
        var t = ' ' + String(teks || '').toLowerCase().replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim() + ' ';
        function has(keys) {
            for (var i = 0; i < keys.length; i++) {
                var k = ' ' + String(keys[i]).toLowerCase().replace(/\s+/g, ' ').trim() + ' ';
                if (k.trim() !== '' && t.indexOf(k) !== -1) return keys[i];
            }
            return null;
        }
        var m;
        if ((m = has(['alpa', 'bolos', 'membolos', 'tidak masuk', 'absen tanpa', 'tanpa keterangan']))) return 'Kehadiran';
        if ((m = has(['berkelahi', 'tawuran', 'memukul', 'menendang', 'merokok', 'rokok', 'vape', 'mencuri', 'mengambil milik', 'berbohong', 'bohong', 'dusta', 'melawan guru', 'membentak', 'berkata kasar', 'berkata kotor', 'mengumpat', 'mengejek', 'membully', 'bully', 'mencaci', 'caci', 'fitnah', 'tidak sopan', 'tidak santun', 'kurang sopan', 'kasar', 'sholat', 'shalat', 'ibadah', 'mengaji', 'puasa', 'jujur', 'sopan', 'santun', 'adab', 'akhlak']))) return 'Sikap';
        if ((m = has(['berselisih', 'bertengkar', 'cekcok', 'menyendiri', 'mengucilkan', 'dikucilkan', 'kerjasama', 'kerja sama', 'gotong royong', 'bergaul']))) return 'Sosial';
        if ((m = has(['menyontek', 'nyontek', 'contekan', 'tidak mengerjakan tugas', 'tidak mengerjakan pr', 'ulangan', 'asesmen', 'ujian', 'nilai harian', 'tugas']))) return 'Akademik';
        if ((m = has(['terlambat', 'telat', 'seragam', 'atribut', 'pakaian', 'berseragam', 'sepatu', 'rambut', 'kuku', 'gondrong', 'tata tertib', 'disiplin', 'apel', 'upacara', 'baris', 'piket', 'sampah', 'kebersihan', 'lupa membawa', 'tidak membawa', 'gawai', 'handphone', 'gadget', 'main hp', 'bermain gawai', 'keluar kelas', 'tanpa izin', 'gaduh', 'ribut', 'berisik', 'mengganggu', 'buku', 'alat tulis', 'izin']))) return 'Kedisiplinan';
        var kat = String($('#inp_l_kategori').val() || kategori || '').toLowerCase();
        if (kat === 'berat') return 'Sikap';
        return 'Kedisiplinan';
    }
    function autoBinaanMaster() {
        var v = deteksiJenisBinaanMaster($('#inp_l_jenis').val(), $('#inp_l_kategori').val());
        if (v) $('#inp_l_binaan').val(v);
    }
    $('#inp_l_jenis').on('input change', autoBinaanMaster);
    $('#inp_l_kategori').on('change', autoBinaanMaster);

    $('#btnTambahLanggar').on('click', function() {
        $('#formLanggarAction').val('tambah');
        $('#langgarId').val('');
        $('#modalLanggarTitle').text('Tambah Template Pelanggaran');
        $('#formLanggar')[0].reset();
        autoBinaanMaster();
        $('#modalLanggar').modal('show');
    });

    $(document).on('click', '.btn-edit-langgar', function() {
        var data = $(this).data('json');
        $('#formLanggarAction').val('edit');
        $('#langgarId').val(data.id);
        $('#modalLanggarTitle').text('Edit Template Pelanggaran');
        $('#inp_l_kategori').val(data.kategori);
        $('#inp_l_jenis').val(data.jenis);
        $('#inp_l_tindakan').val(data.tindakan);
        $('#inp_l_poin').val(data.poin);
        if (data.jenis_binaan) $('#inp_l_binaan').val(data.jenis_binaan);
        else autoBinaanMaster();
        $('#modalLanggar').modal('show');
    });

    $(document).on('click', '.btn-hapus-langgar', function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Hapus Template?',
            text: 'Template pelanggaran ini akan dihapus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formHapusLanggarId').val(id);
                $('#formHapusLanggar').submit();
            }
        });
    });
});
JS
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Data Pelanggaran Siswa</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <!-- Filter -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0 text-dark"><i class="fas fa-filter mr-1 text-primary"></i> Filter Kategori Pelanggaran</h6>
                </div>
                <div class="card-body py-3">
                    <form method="GET" class="form-row align-items-center">
                        <div class="col-md-5 mb-2">
                            <select name="f_kategori" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="">-- Semua Kategori --</option>
                                <?php foreach ($kategori_list as $k): ?>
                                    <option value="<?= $k ?>" <?= $f_kategori === $k ? 'selected' : '' ?>><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7 mb-2">
                            <a href="data_pelanggaran.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo mr-1"></i> Reset</a>
                            <small class="text-muted ml-2">Filter otomatis tersubmit saat diubah.</small>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-light border small text-muted mb-3">
                <i class="fas fa-gavel mr-1 text-danger"></i> <strong>Ambang sanksi akumulasi poin:</strong>
                0-24 Pembinaan Ringan &bull; 25-49 Dalam Pemantauan &bull; 50-74 SP 1 / Pembinaan Khusus &bull; 75-99 Skorsing &bull; 100+ Dikeluarkan (DO).
            </div>

            <!-- Tabel Template -->
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                    <h4>Template Jenis, Tindakan &amp; Poin Pelanggaran</h4>
                    <div>
                        <a href="pelanggaran_siswa.php" class="btn btn-secondary btn-sm mr-2">
                            <i class="fas fa-clipboard-list mr-1"></i> Pelanggaran Siswa
                        </a>
                        <button type="button" class="btn btn-primary btn-sm" id="btnTambahLanggar">
                            <i class="fas fa-plus mr-1"></i> Tambah Template
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover" id="table-master-langgar" style="width:100%;">
                            <thead class="thead-light text-center">
                                <tr>
                                    <th style="width: 40px;">No</th>
                                    <th style="width: 110px;">Kategori</th>
                                    <th style="width: 120px;">Jenis Binaan</th>
                                    <th>Jenis Pelanggaran</th>
                                    <th>Tindakan / Sanksi</th>
                                    <th style="width: 80px;">Poin</th>
                                    <th style="width: 90px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($rows as $r): ?>
                                    <?php $kb = $r['kategori'] === 'Berat' ? 'danger' : ($r['kategori'] === 'Sedang' ? 'warning' : 'info'); ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $no++ ?></td>
                                        <td class="align-middle text-center">
                                            <span class="badge badge-<?= $kb ?> px-2 py-1 font-weight-bold" style="font-size: 11.5px;">
                                                <?= htmlspecialchars($r['kategori']) ?>
                                            </span>
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="badge badge-success px-2 py-1" style="font-size: 11.5px;">
                                                <?= htmlspecialchars($r['jenis_binaan'] ?? '-') ?>
                                            </span>
                                        </td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 220px;"><?= nl2br(htmlspecialchars($r['jenis'])) ?></td>
                                        <td class="align-middle" style="font-size: 13px; min-width: 220px;"><?= nl2br(htmlspecialchars($r['tindakan'])) ?></td>
                                        <td class="text-center align-middle font-weight-bold text-danger"><?= (int)$r['poin'] ?></td>
                                        <td class="text-center align-middle">
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-warning btn-edit-langgar" data-json='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger btn-hapus-langgar" data-id="<?= (int)$r['id'] ?>" title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal Tambah / Edit Template -->
<div class="modal fade" id="modalLanggar" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" id="formLanggar">
                <input type="hidden" name="action" id="formLanggarAction" value="tambah">
                <input type="hidden" name="id" id="langgarId" value="">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title text-primary" id="modalLanggarTitle">Tambah Template Pelanggaran</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-3">
                    <style>
                        #modalLanggar textarea { min-height: 110px; line-height: 1.55; resize: vertical; overflow-y: auto; }
                    </style>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Kategori <span class="text-danger">*</span></label>
                            <select name="kategori" id="inp_l_kategori" class="form-control" required>
                                <option value="Ringan">Ringan</option>
                                <option value="Sedang">Sedang</option>
                                <option value="Berat">Berat</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Jenis Binaan (Alur 2) <span class="text-danger">*</span></label>
                            <select name="jenis_binaan" id="inp_l_binaan" class="form-control" required>
                                <option value="">-- Otomatis / Pilih --</option>
                                <?php foreach (['Kedisiplinan', 'Kehadiran', 'Akademik', 'Sikap', 'Sosial'] as $jb): ?>
                                    <option value="<?= $jb ?>"><?= $jb ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Otomatis terisi dari teks + kategori.</small>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Poin Pelanggaran <span class="text-danger">*</span></label>
                            <input type="number" name="poin" id="inp_l_poin" class="form-control" value="5" min="0" required>
                        </div>
                        <div class="col-12 form-group">
                            <label class="font-weight-bold">Jenis Pelanggaran <span class="text-danger">*</span></label>
                            <textarea name="jenis" id="inp_l_jenis" class="form-control" rows="4" required placeholder="Contoh: Datang terlambat lebih dari 15 menit..."></textarea>
                        </div>
                        <div class="col-12 form-group mb-0">
                            <label class="font-weight-bold">Tindakan / Sanksi <span class="text-danger">*</span></label>
                            <textarea name="tindakan" id="inp_l_tindakan" class="form-control" rows="4" required placeholder="Contoh: Teguran lisan dan tugas kebersihan..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save mr-1"></i> Simpan Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="formHapusLanggar" class="d-none">
    <input type="hidden" name="action" value="hapus">
    <input type="hidden" name="id" id="formHapusLanggarId">
</form>

<?php include '../templates/footer.php'; ?>
