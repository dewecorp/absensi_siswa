<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_honor.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Data Honor';


// Get all honor with pembina name
$sql = "SELECT h.*, p.nama_pembina 
        FROM honor h
        LEFT JOIN pembina p ON h.pembina_id = p.id
        ORDER BY h.id ASC";
$result = $conn->query($sql);
$honor = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

// Get all pembina for dropdown with ekstrakurikuler name
$sql_pembina = "SELECT p.*, e.jenis_ekstrakurikuler 
                FROM pembina p
                LEFT JOIN ekstrakurikuler e ON p.ekstrakurikuler_id = e.id
                ORDER BY p.nama_pembina ASC";
$result_pembina = $conn->query($sql_pembina);
$pembina_list = $result_pembina ? $result_pembina->fetch_all(MYSQLI_ASSOC) : [];
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Honor</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <div class="breadcrumb-item active">Data Honor</div>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Data Honor</h4>
                                <div class="card-header-action">
                                    <button class="btn btn-primary" data-toggle="modal" data-target="#modalTambah">
                                        <i class="fas fa-plus"></i> Tambah Honor
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tableHonor">
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Nama Pembina</th>
                                                <th>Jabatan</th>
                                                <th>Jumlah Honor</th>
                                                <th>Jumlah Pertemuan</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($honor as $h): ?>
                                                <tr>
                                                    <td><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($h['nama_pembina'] ?? '-'); ?></td>
                                                    <td><?php echo htmlspecialchars($h['jabatan']); ?></td>
                                                    <td style="text-align: right;"><?php echo formatRupiah($h['jumlah_honor']); ?></td>
                                                    <td style="text-align: center;"><?php echo $h['jumlah_pertemuan'] ?? 0; ?></td>
                                                    <td>
                                                        <span class="badge badge-<?php echo $h['aktif'] ? 'success' : 'danger'; ?>">
                                                            <?php echo $h['aktif'] ? 'Aktif' : 'Tidak Aktif'; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info btn-edit" data-id="<?php echo $h['id']; ?>" onclick="editHonor(<?php echo $h['id']; ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?php echo BASE_URL; ?>pages/honor/delete?id=<?php echo $h['id']; ?>')">
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
                            <h5 class="modal-title" id="modalTitle">Tambah Honor</h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
<form id="formHonor">
    <?php echo csrfField(); ?>
    <input type="hidden" name="id" id="honor_id">
                            <div class="modal-body">
                                <div class="form-group">
                                    <label>Nama Pembina <span class="text-danger">*</span></label>
                                    <select class="form-control" name="pembina_id" id="pembina_id" required>
                                        <option value="">-- Pilih Pembina --</option>
                                        <?php foreach ($pembina_list as $p): ?>
                                            <option 
                                                value="<?php echo $p['id']; ?>" 
                                                data-eks="<?php echo htmlspecialchars($p['jenis_ekstrakurikuler'] ?? ''); ?>"
                                            >
                                                <?php echo htmlspecialchars($p['nama_pembina']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Jabatan <span class="text-danger">*</span></label>
                                    <select class="form-control" name="jabatan_type" id="jabatan_type" required>
                                        <option value="">-- Pilih Jabatan --</option>
                                        <option value="Pembina">Pembina</option>
                                        <option value="Pelatih">Pelatih</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <input type="text" class="form-control" name="jabatan" id="jabatan" placeholder="Otomatis terisi berdasarkan pilihan" readonly required>
                                </div>
                                <div class="form-group">
                                    <label>Jumlah Honor</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Rp</span>
                                        </div>
                                        <input type="text" class="form-control currency-input" name="jumlah_honor" id="jumlah_honor" placeholder="0" required>
                                        <input type="hidden" name="jumlah_honor_hidden" id="jumlah_honor_hidden" value="0">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Jumlah Pertemuan <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" name="jumlah_pertemuan" id="jumlah_pertemuan" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="aktif" id="aktif" value="1" checked> Aktif
                                    </label>
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
    function initHonor() {
        if (typeof jQuery === 'undefined' || typeof window.jQuery === 'undefined') {
            setTimeout(initHonor, 50);
            return;
        }
        
        var $ = window.jQuery;
        
        // Format Rupiah function (must be available globally)
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
        
        // Unformat Rupiah function (must be available globally)
        function unformatRupiah(rupiah) {
            if (!rupiah || rupiah === '') return 0;
            var cleaned = rupiah.toString().replace(/\./g, '').replace(/[^0-9]/g, '');
            return parseFloat(cleaned) || 0;
        }
        
        $(document).ready(function() {
            // Currency input formatting (same as tunjangan)
            $('#jumlah_honor').on('input', function(e) {
                var value = $(this).val();
                var unformatted = unformatRupiah(value);
                var formatted = formatRupiah(unformatted);
                $(this).val(formatted);
                $('#jumlah_honor_hidden').val(unformatted);
            });
            
            // Format on blur
            $('#jumlah_honor').on('blur', function() {
                var value = $(this).val();
                var unformatted = unformatRupiah(value);
                var formatted = formatRupiah(unformatted);
                $(this).val(formatted);
                $('#jumlah_honor_hidden').val(unformatted);
            });

            var t = $('#tableHonor').DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' },
                columnDefs: [
                    {
                        searchable: false,
                        orderable: false,
                        targets: 0
                    }
                ],
                order: [[1, 'asc']]
            });

            t.on('order.dt search.dt', function () {
                t.column(0, {search:'applied', order:'applied'}).nodes().each(function (cell, i) {
                    cell.innerHTML = i + 1;
                });
            }).draw();

            function getEksByPembinaId(id) {
                var opt = $('#pembina_id').find('option[value="'+id+'"]');
                if (opt.length) {
                    return opt.data('eks') || '';
                }
                return '';
            }

            function updateJabatanString() {
                var type = $('#jabatan_type').val();
                var pembinaId = $('#pembina_id').val();
                var eks = getEksByPembinaId(pembinaId);
                var text = '';
                if (type && eks) {
                    text = type + ' ' + eks;
                }
                $('#jabatan').val(text);
            }

            $('#pembina_id').on('change', updateJabatanString);
            $('#jabatan_type').on('change', updateJabatanString);

            $('#formHonor').on('submit', function(e) {
                e.preventDefault();
                // Ensure hidden value is set before submit - same as tunjangan
                var formatted = $('#jumlah_honor').val();
                var unformatted = unformatRupiah(formatted);
                // Ensure it's a valid number
                unformatted = parseFloat(unformatted) || 0;
                $('#jumlah_honor_hidden').val(unformatted.toString());
                
                updateJabatanString();
                var jabatanVal = $('#jabatan').val();
                if (!jabatanVal) {
                    Swal.fire({icon: 'error', title: 'Validasi Gagal', text: 'Pilih Pembina dan Jabatan terlebih dahulu'});
                    return false;
                }

                // Validate jumlah honor
                if (!unformatted || unformatted <= 0) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validasi Gagal',
                        text: 'Jumlah honor harus lebih besar dari 0'
                    });
                    return false;
                }
                
                var formData = $(this).serialize();
                
                $.ajax({
                    url: '<?php echo $gaji_page_base; ?>save',
                    type: 'POST',
                    data: formData,
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

            // Format on modal shown (to ensure format is applied)
            $('#modalTambah').on('shown.bs.modal', function() {
                // Format jumlah_honor if it has value
                var currentValue = $('#jumlah_honor').val();
                if (currentValue && currentValue !== '0' && currentValue !== '') {
                    var unformatted = unformatRupiah(currentValue);
                    var formatted = formatRupiah(unformatted);
                    $('#jumlah_honor').val(formatted);
                    $('#jumlah_honor_hidden').val(unformatted);
                }
            });
            
            $('#modalTambah').on('hidden.bs.modal', function() {
                $('#formHonor')[0].reset();
                $('#honor_id').val('');
                $('#pembina_id').val('');
                $('#jabatan_type').val('');
                $('#jabatan').val('');
                $('#jumlah_honor').val('');
                $('#jumlah_honor_hidden').val('0');
                $('#jumlah_pertemuan').val('');
                $('#aktif').prop('checked', true);
                $('#modalTitle').text('Tambah Honor');
            });
        });
        
        // Make formatRupiah and unformatRupiah available globally
        window.formatRupiah = formatRupiah;
        window.unformatRupiah = unformatRupiah;
    }
    
    initHonor();
})();

function editHonor(id) {
    if (typeof jQuery === 'undefined') {
        setTimeout(function() { editHonor(id); }, 100);
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
                $('#honor_id').val(response.data.id);
                $('#pembina_id').val(response.data.pembina_id || '');
                var jabatanStr = response.data.jabatan || '';
                var lower = jabatanStr.toLowerCase();
                if (lower.indexOf('pembina') === 0) {
                    $('#jabatan_type').val('Pembina');
                } else if (lower.indexOf('pelatih') === 0) {
                    $('#jabatan_type').val('Pelatih');
                } else {
                    $('#jabatan_type').val('');
                }
                setTimeout(function(){ 
                    $('#pembina_id').trigger('change'); 
                    $('#jabatan_type').trigger('change'); 
                }, 50);
                
                // Format jumlah_honor as Rupiah (same as tunjangan)
                var jumlah = parseFloat(response.data.jumlah_honor || 0);
                if (isNaN(jumlah)) {
                    jumlah = 0;
                }
                
                // Store raw value in hidden input
                $('#jumlah_honor_hidden').val(jumlah.toString());
                
                // Format for display (remove decimals for formatting)
                var jumlahString = Math.floor(jumlah).toString();
                // Use formatRupiah function - ensure it handles large numbers correctly
                var formatted = formatRupiah(jumlahString);
                $('#jumlah_honor').val(formatted);
                
                // Set jumlah_pertemuan
                $('#jumlah_pertemuan').val(response.data.jumlah_pertemuan || 0);
                
                $('#aktif').prop('checked', response.data.aktif == 1);
                $('#modalTitle').text('Edit Honor');
                $('#modalTambah').modal('show');
                
                // Ensure format is applied after modal is shown
                setTimeout(function() {
                    var currentValue = $('#jumlah_honor').val();
                    if (currentValue && currentValue !== '0' && currentValue !== '') {
                        // Re-format to ensure correct display
                        var unformatted = unformatRupiah(currentValue);
                        var formatted = formatRupiah(unformatted.toString());
                        $('#jumlah_honor').val(formatted);
                        $('#jumlah_honor_hidden').val(unformatted);
                    }
                }, 100);
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
