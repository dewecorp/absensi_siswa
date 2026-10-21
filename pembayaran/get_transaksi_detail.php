<?php
require_once __DIR__ . '/config/config.php';

if (isset($_POST['no_transaksi'])) {
    $no_transaksi = $_POST['no_transaksi'];
    
    // Fetch Transaction Items
    $query = mysqli_query($koneksi, "SELECT 
                                        p.*,
                                        s.nama as nama_siswa,
                                        s.nisn,
                                        k.nama_kelas,
                                        jb.nama_pembayaran,
                                        jb.tipe_bayar,
                                        jb.nominal as nominal_asli,
                                        jb.tagihan_kelas
                                     FROM pembayaran p
                                     JOIN siswa s ON p.nisn = s.nisn
                                     JOIN kelas k ON s.id_kelas = k.id_kelas
                                     JOIN jenis_bayar jb ON p.id_jenis_bayar = jb.id_jenis_bayar
                                     WHERE p.no_transaksi = '$no_transaksi'");

    if (mysqli_num_rows($query) > 0) {
        $items = [];
        $existing_jb_ids = [];
        while ($row = mysqli_fetch_assoc($query)) {
            $items[] = $row;
            $existing_jb_ids[] = $row['id_jenis_bayar'];
        }
        $header = $items[0];

        // Fetch All Jenis Bayar for Dropdown
        $existing_ids_str = implode(',', $existing_jb_ids);
        $q_jb = mysqli_query($koneksi, "SELECT * FROM jenis_bayar WHERE status = 'Aktif' OR id_jenis_bayar IN ($existing_ids_str) ORDER BY nama_pembayaran ASC");
        $jb_list = [];
        while ($jb = mysqli_fetch_assoc($q_jb)) {
            $jb_list[] = $jb;
        }
?>
    <input type="hidden" name="no_transaksi" value="<?= $no_transaksi ?>">
    <input type="hidden" name="nisn" value="<?= $header['nisn'] ?>">
    <input type="hidden" id="kelasSiswaEdit" value="<?= $header['nama_kelas'] ?>">

    <div class="form-group">
        <label>Nama Siswa</label>
        <input type="text" class="form-control" value="<?= $header['nama_siswa'] ?> (<?= $header['nama_kelas'] ?>)" readonly>
    </div>
    <div class="form-group">
        <label>Tanggal Bayar</label>
        <input type="date" name="tgl_bayar" class="form-control" value="<?= $header['tgl_bayar'] ?>" required>
    </div>

    <div class="form-group">
        <label>Jenis Bayar</label>
        <select name="id_jenis_bayar[]" class="form-control select2-edit-multiple" id="jbEdit" multiple="multiple" style="width: 100%;" required>
            <?php foreach ($jb_list as $jb) : 
                $selected = in_array($jb['id_jenis_bayar'], $existing_jb_ids) ? 'selected' : '';
            ?>
                <option value="<?= $jb['id_jenis_bayar'] ?>" 
                    data-tipe="<?= $jb['tipe_bayar'] ?>" 
                    data-nominal="<?= $jb['nominal'] ?>"
                    data-tagihan="<?= $jb['tagihan_kelas'] ?>"
                    data-nama="<?= $jb['nama_pembayaran'] ?>"
                    <?= $selected ?>>
                    <?= $jb['nama_pembayaran'] ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="extraEdit">
        <?php foreach ($items as $item): 
            $id_jb = $item['id_jenis_bayar'];
        ?>
            <div class="card mb-2" id="card-<?= $id_jb ?>">
                <div class="card-body p-3" style="background: #f8f9fa;">
                    <h6 class="mb-2 text-primary"><?= $item['nama_pembayaran'] ?> (<?= $item['tipe_bayar'] ?>)</h6>
                    
                    <input type="hidden" name="payment[<?= $id_jb ?>][id_jenis_bayar]" value="<?= $id_jb ?>">
                    <input type="hidden" name="payment[<?= $id_jb ?>][id_pembayaran]" value="<?= $item['id_pembayaran'] ?>">
                    
                    <?php if ($item['tipe_bayar'] == 'Bulanan'): ?>
                        <div class="form-group">
                            <label>Bulan Bayar</label>
                            <select name="payment[<?= $id_jb ?>][bulan_bayar][]" class="form-control select2-edit-bulan" multiple="multiple" style="width: 100%;" required>
                                <?php 
                                $months = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
                                $selected_months = explode(', ', $item['bulan_bayar']);
                                foreach ($months as $m) {
                                    $selected = in_array($m, $selected_months) ? 'selected' : '';
                                    echo "<option value='$m' $selected>$m</option>";
                                }
                                ?>
                            </select>
                            <small class="text-muted">* Nominal otomatis: Rp <?= number_format($item['nominal_asli'], 0, ',', '.') ?> / bulan</small>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Cicilan Ke</label>
                                    <input type="number" name="payment[<?= $id_jb ?>][cicilan_ke]" class="form-control" value="<?= $item['cicilan_ke'] ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Nominal (Rp)</label>
                                    <input type="text" name="payment[<?= $id_jb ?>][nominal]" class="form-control input-nominal-edit" value="<?= number_format((int)$item['jumlah_bayar'], 0, ',', '.') ?>" inputmode="numeric" autocomplete="off" required>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        $(document).ready(function() {
            function getModalParent($element) {
                var $modal = $element.closest('.modal');
                return $modal.length ? $modal : $(document.body);
            }

            // Init Select2 for Main Dropdown
            $('#jbEdit').select2({
                placeholder: "Pilih Jenis Bayar",
                allowClear: true,
                width: '100%',
                dropdownParent: getModalParent($('#jbEdit'))
            });

            // Init Select2 for Existing Months
            $('.select2-edit-bulan').select2({
                placeholder: "Pilih Bulan",
                allowClear: true,
                width: '100%',
                dropdownParent: getModalParent($('.select2-edit-bulan'))
            });

            // Handle Change on Jenis Bayar
            $('#jbEdit').on('change', function() {
                var selectedOptions = $(this).find('option:selected');
                var container = $('#extraEdit');
                
                // Get current visible cards to know what to remove
                var currentIds = [];
                selectedOptions.each(function() {
                    currentIds.push($(this).val());
                });

                // Remove unselected cards
                container.children('.card').each(function() {
                    var cardId = $(this).attr('id').replace('card-', '');
                    if (!currentIds.includes(cardId)) {
                        $(this).remove();
                    }
                });

                var kelasSiswa = $('#kelasSiswaEdit').val();

                // Add new cards
                selectedOptions.each(function() {
                    var opt = $(this);
                    var id = opt.val();
                    
                    // If card already exists, skip
                    if (container.find('#card-' + id).length > 0) {
                        return;
                    }

                    var nama = opt.data('nama');
                    var tipe = opt.data('tipe');
                    var nominal = opt.data('nominal');
                    var tagihan = opt.attr('data-tagihan');

                    // Validation (Show alert but maybe still allow? Or remove? Consistent with Tambah)
                    if (tagihan && tagihan !== '' && kelasSiswa) {
                        var allowedKelas = tagihan.split(',').map(s => s.trim());
                        if (!allowedKelas.includes(kelasSiswa)) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Oops...',
                                text: 'Jenis pembayaran ' + nama + ' tidak tersedia untuk Kelas ' + kelasSiswa
                            });
                            // Optional: Deselect the option
                            // var values = $('#jbEdit').val();
                            // var index = values.indexOf(id);
                            // if (index > -1) {
                            //    values.splice(index, 1);
                            //    $('#jbEdit').val(values).trigger('change');
                            // }
                            // return; 
                        }
                    }

                    // Build HTML (Similar to Tambah but without id_pembayaran input)
                    var html = '<div class="card mb-2" id="card-' + id + '"><div class="card-body p-3" style="background: #f8f9fa;">';
                    html += '<h6 class="mb-2 text-primary">' + nama + ' (' + tipe + ')</h6>';
                    
                    html += '<input type="hidden" name="payment[' + id + '][id_jenis_bayar]" value="' + id + '">';
                    // Note: No id_pembayaran input for new items

                    if (tipe === 'Bulanan') {
                        html += '<div class="form-group">';
                        html += '<label>Bayar Bulan</label>';
                        html += '<select name="payment[' + id + '][bulan_bayar][]" class="form-control select2-dynamic-bulan-edit" multiple="multiple" style="width: 100%;" required>';
                        var months = ['Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni'];
                        var currentMonthIndex = (new Date().getMonth() + 6) % 12;
                        months.forEach(function(m, i) {
                             var selected = (i === currentMonthIndex) ? 'selected' : '';
                             html += '<option value="' + m + '" ' + selected + '>' + m + '</option>';
                        });
                        html += '</select></div>';
                    } else {
                         html += '<div class="form-group"><label>Cicilan Ke</label>';
                         html += '<input type="number" name="payment[' + id + '][cicilan_ke]" class="form-control" value="1" required></div>';
                         html += '<div class="form-group"><label>Nominal</label>';
                         html += '<input type="text" name="payment[' + id + '][nominal]" class="form-control input-nominal-edit" placeholder="Nominal" inputmode="numeric" autocomplete="off" required></div>';
                    }
                    html += '</div></div>';
                    container.append(html);
                });

                // Re-init Select2 for new elements
                $('.select2-dynamic-bulan-edit').select2({
                    placeholder: "Pilih Bulan",
                    allowClear: true,
                    width: '100%',
                    dropdownParent: getModalParent($('.select2-dynamic-bulan-edit'))
                });
            });
        });
    </script>
<?php
    } else {
        echo "Data tidak ditemukan";
    }
}
?>
