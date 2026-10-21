<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_ekstrakurikuler.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Data Ekstrakurikuler';


// Get all ekstrakurikuler
$sql = "SELECT * FROM ekstrakurikuler ORDER BY jenis_ekstrakurikuler ASC";
$result = $conn->query($sql);
$ekstrakurikuler = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Ekstrakurikuler</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item active">Data Ekstrakurikuler</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Data Ekstrakurikuler</h4>
                                <div class="card-header-action">
                                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalTambah">
                                        <i class="fas fa-plus"></i> Tambah Ekstrakurikuler
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tableEkstrakurikuler">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Jenis Ekstrakurikuler</th>
                                                <th>Waktu</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($ekstrakurikuler as $e): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($e['jenis_ekstrakurikuler']); ?></td>
                                                    <td><?php echo htmlspecialchars($e['waktu']); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info btn-edit" data-id="<?php echo $e['id']; ?>" onclick="editEkstrakurikuler(<?php echo $e['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?php echo BASE_URL; ?>pages/ekstrakurikuler/delete?id=<?php echo $e['id']; ?>')">
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
                            <h5 class="modal-title" id="modalTitle">Tambah Ekstrakurikuler</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
<form id="formEkstrakurikuler">
    <?php echo csrfField(); ?>
    <input type="hidden" name="id" id="ekstrakurikuler_id">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Jenis Ekstrakurikuler</label>
                                    <input type="text" class="form-control" name="jenis_ekstrakurikuler" id="jenis_ekstrakurikuler" required>
                                </div>
                                <div class="form-group">
                                    <label>Waktu</label>
                                    <input type="text" class="form-control" name="waktu" id="waktu" placeholder="Contoh: Senin, 14:00-16:00" required>
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
    function initEkstrakurikuler() {
        if (typeof jQuery === 'undefined' || typeof window.jQuery === 'undefined') {
            setTimeout(initEkstrakurikuler, 50);
            return;
        }
        
        var $ = window.jQuery;
        
        $(document).ready(function() {
            // Initialize DataTable
            $('#tableEkstrakurikuler').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' },
                order: [[1, 'asc']]
            });

            // Form submit
            $('#formEkstrakurikuler').on('submit', function(e) {
                e.preventDefault();
                var formData = $(this).serialize();
                
                $.ajax({
                    url: '<?php echo $gaji_page_base; ?>save',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil!',
                                text: response.message,
                                timer: 2000
                            }).then(function() {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal!',
                                text: response.message
                            });
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

            // Reset form when modal is closed
            $('#modalTambah').on('hidden.bs.modal', function() {
                $('#formEkstrakurikuler')[0].reset();
                $('#ekstrakurikuler_id').val('');
                $('#modalTitle').text('Tambah Ekstrakurikuler');
            });
        });
    }
    
    initEkstrakurikuler();
})();

function editEkstrakurikuler(id) {
    if (typeof jQuery === 'undefined') {
        setTimeout(function() { editEkstrakurikuler(id); }, 100);
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
                $('#ekstrakurikuler_id').val(response.data.id);
                $('#jenis_ekstrakurikuler').val(response.data.jenis_ekstrakurikuler);
                $('#waktu').val(response.data.waktu);
                $('#modalTitle').text('Edit Ekstrakurikuler');
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
