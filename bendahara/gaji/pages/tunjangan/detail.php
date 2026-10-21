<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_tunjangan_detail.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Detail Tunjangan';


$tunjangan_id = $_GET['tunjangan_id'] ?? 0;
$sql = "SELECT * FROM tunjangan WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $tunjangan_id);
$stmt->execute();
$tunjangan = $stmt->get_result()->fetch_assoc();

// Get tunjangan details (tidak bergantung periode)
$sql = "SELECT td.*, g.nama_lengkap 
        FROM tunjangan_detail td 
        JOIN guru g ON td.guru_id = g.id 
        WHERE td.tunjangan_id = ?
        ORDER BY g.nama_lengkap";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $tunjangan_id);
$stmt->execute();
$details = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all teachers
$sql = "SELECT * FROM guru ORDER BY nama_lengkap";
$guru = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Detail Tunjangan: <?php echo htmlspecialchars((string)($tunjangan['nama_tunjangan'] ?? '')); ?></h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>pages/tunjangan">Tunjangan</a></div>
                            <div class="breadcrumb-item active">Detail</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Data Tunjangan (Tidak Bergantung Periode)</h4>
                                <div class="card-header-action">
                                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalTambah">
                                        <i class="fas fa-plus"></i> Tambah Detail
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped datatable" id="tableDetail">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Guru</th>
                                                <th>Jumlah</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($details as $d): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($d['nama_lengkap']); ?></td>
                                                    <td><?php echo formatRupiah($d['jumlah']); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info" onclick="editDetail(<?php echo $d['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?php echo BASE_URL; ?>pages/tunjangan/delete_detail?id=<?php echo $d['id']; ?>')">
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
                    </div>
                </section>
            </div>

            <!-- Modal -->
            <div class="modal fade" id="modalTambah" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalTitle">Tambah Detail Tunjangan</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
<form method="POST" action="<?php echo $gaji_page_base; ?>save_detail">
    <?php echo csrfField(); ?>
    <input type="hidden" name="id" id="detail_id">
                            <input type="hidden" name="tunjangan_id" value="<?php echo $tunjangan_id; ?>">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Guru</label>
                                    <select class="form-select" name="guru_id" id="guru_id" required>
                                        <option value="">Pilih Guru</option>
                                        <?php foreach ($guru as $g): ?>
                                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['nama_lengkap']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Jumlah</label>
                                    <input type="text" class="form-control currency-input" name="jumlah" id="jumlah" required>
                                </div>
                                <div class="form-group">
                                    <label>Periode</label>
                                    <input type="month" class="form-control" name="periode" id="periode" value="<?php echo $periode_aktif; ?>" required>
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

<script>
function editDetail(id) {
    $.ajax({
        url: '<?php echo $gaji_page_base; ?>get_detail?id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(data) {
            $('#detail_id').val(data.id);
            $('#guru_id').val(data.guru_id);
            $('#jumlah').val(data.jumlah);
            $('#periode').val(data.periode);
            $('#modalTitle').text('Edit Detail Tunjangan');
            $('#modalTambah').modal('show');
        }
    });
}

$('#modalTambah').on('hidden.bs.modal', function () {
    $('#modalTambah form')[0].reset();
    $('#detail_id').val('');
    $('#modalTitle').text('Tambah Detail Tunjangan');
});
</script>
