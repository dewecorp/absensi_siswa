<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_pembina.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Data Pembina';


// Get all pembina with ekstrakurikuler name
$sql = "SELECT p.*, e.jenis_ekstrakurikuler 
        FROM pembina p
        LEFT JOIN ekstrakurikuler e ON p.ekstrakurikuler_id = e.id
        ORDER BY p.nama_pembina ASC";
$result = $conn->query($sql);
$pembina = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Get all ekstrakurikuler for dropdown
$sql_eks = "SELECT * FROM ekstrakurikuler ORDER BY jenis_ekstrakurikuler ASC";
$ekstrakurikuler_list = $conn->query($sql_eks)->fetch_all(MYSQLI_ASSOC);
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Pembina</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item active">Data Pembina</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Data Pembina</h4>
                                <div class="card-header-action">
                                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalTambah">
                                        <i class="fas fa-plus"></i> Tambah Pembina
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tablePembina">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Pembina</th>
                                                <th>Pembina Ekstrakurikuler</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($pembina as $p): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($p['nama_pembina']); ?></td>
                                                    <td><?php echo htmlspecialchars($p['jenis_ekstrakurikuler'] ?? '-'); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info btn-edit" data-id="<?php echo $p['id']; ?>" onclick="editPembina(<?php echo $p['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?php echo BASE_URL; ?>pages/pembina/delete?id=<?php echo $p['id']; ?>')">
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

            <!-- Modal Tambah/Edit -->
            <div class="modal fade" id="modalTambah" tabindex="-1" role="dialog">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="modalTitle">Tambah Pembina</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
<form id="formPembina">
    <?php echo csrfField(); ?>
    <input type="hidden" name="id" id="pembina_id">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Nama Pembina</label>
                                    <input type="text" class="form-control" name="nama_pembina" id="nama_pembina" required>
                                </div>
                                <div class="form-group">
                                    <label>Pembina Ekstrakurikuler</label>
                                    <select class="form-control" name="ekstrakurikuler_id" id="ekstrakurikuler_id" required>
                                        <option value="">Pilih Ekstrakurikuler</option>
                                        <?php foreach ($ekstrakurikuler_list as $e): ?>
                                            <option value="<?php echo $e['id']; ?>"><?php echo htmlspecialchars($e['jenis_ekstrakurikuler']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
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
// Wait for jQuery to be loaded
(function() {
    function initPembina() {
        if (typeof jQuery === 'undefined' || typeof window.jQuery === 'undefined') {
            setTimeout(initPembina, 50);
            return;
        }
        
        var $ = window.jQuery;
        
        $(document).ready(function() {
            $('#tablePembina').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' },
                order: [[1, 'asc']]
            });

            $('#formPembina').on('submit', function(e) {
                e.preventDefault();
                $.ajax({
                    url: '<?php echo $gaji_page_base; ?>save',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({icon: 'success', title: 'Berhasil!', text: response.message, timer: 2000}).then(function() {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({icon: 'error', title: 'Gagal!', text: response.message || 'Terjadi kesalahan'});
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        console.error('Response:', xhr.responseText);
                        var errorMsg = 'Terjadi kesalahan saat menyimpan data';
                        try {
                            var response = JSON.parse(xhr.responseText);
                            errorMsg = response.message || errorMsg;
                        } catch(e) {
                            errorMsg = xhr.responseText || errorMsg;
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: errorMsg
                        });
                    }
                });
            });

            $('#modalTambah').on('hidden.bs.modal', function() {
                $('#formPembina')[0].reset();
                $('#pembina_id').val('');
                $('#modalTitle').text('Tambah Pembina');
            });
        });
    }
    
    initPembina();
})();

function editPembina(id) {
    if (typeof jQuery === 'undefined') {
        setTimeout(function() { editPembina(id); }, 100);
        return;
    }
    var $ = window.jQuery;
    
    $.ajax({
        url: '<?php echo $gaji_page_base; ?>get',
        type: 'GET',
        data: { id: id },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#pembina_id').val(response.data.id);
                $('#nama_pembina').val(response.data.nama_pembina);
                $('#ekstrakurikuler_id').val(response.data.ekstrakurikuler_id);
                $('#modalTitle').text('Edit Pembina');
                $('#modalTambah').modal('show');
            }
        }
    });
}

function confirmDelete(url) {
    if (typeof Swal === 'undefined') {
        if (confirm('Apakah Anda yakin ingin menghapus data ini?')) {
            window.location.href = url;
        }
        return;
    }
    
    Swal.fire({
        title: 'Apakah Anda yakin?',
        text: "Data yang dihapus tidak dapat dikembalikan!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}
</script>
