<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_pengaturan.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Pengaturan Gaji';


// Get settings
$sql = "SELECT * FROM settings WHERE id=1 LIMIT 1";
$result = $conn->query($sql);
$settings = $result ? $result->fetch_assoc() : [];

// Ensure default values if settings is empty
if (empty($settings)) {
    $settings = [
        'tahun_ajaran' => '',
        'honor_per_jam' => 0,
        'jumlah_periode' => 1,
        'periode_aktif' => date('Y-m'),
        'periode_mulai' => date('Y-m'),
        'periode_akhir' => date('Y-m')
    ];
}

// Ensure values are properly set
$settings['tahun_ajaran'] = isset($settings['tahun_ajaran']) ? $settings['tahun_ajaran'] : '';
$settings['honor_per_jam'] = isset($settings['honor_per_jam']) ? floatval($settings['honor_per_jam']) : 0;
$settings['jumlah_periode'] = isset($settings['jumlah_periode']) ? intval($settings['jumlah_periode']) : 1;
$settings['periode_aktif'] = isset($settings['periode_aktif']) ? $settings['periode_aktif'] : date('Y-m');
$settings['periode_mulai'] = isset($settings['periode_mulai']) ? $settings['periode_mulai'] : date('Y-m');
$settings['periode_akhir'] = isset($settings['periode_akhir']) ? $settings['periode_akhir'] : date('Y-m');

// Tahun ajaran berjalan: Juli-Juni (contoh: Jul 2026 -> 2026/2027, Mar 2026 -> 2025/2026)
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$taStartYear = $currentMonth >= 7 ? $currentYear : ($currentYear - 1);
$tahunAjaranOptions = [];
for ($i = 0; $i <= 3; $i++) {
    $start = $taStartYear + $i;
    $tahunAjaranOptions[] = $start . '/' . ($start + 1);
}
$savedTahunAjaran = trim((string)($settings['tahun_ajaran'] ?? ''));
if ($savedTahunAjaran !== '' && !in_array($savedTahunAjaran, $tahunAjaranOptions, true)) {
    $tahunAjaranOptions[] = $savedTahunAjaran;
}

// Identitas madrasah & pejabat diambil dari Pengaturan Admin (tb_profil_madrasah).
// Lihat helper gajiProfilMadrasah() di bendahara/gaji/config/helpers.php
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Pengaturan</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <span class="breadcrumb-item active">Pengaturan</span>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Pengaturan Sistem</h4>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="<?php echo $gaji_page_base; ?>save">
                                    <?php echo csrfField(); ?>
                                    
                                    <!-- Periode & Tahun Ajaran -->
                                    <h6 class="section-title mb-3 mt-4" style="color: #667eea; font-weight: 600; border-bottom: 2px solid #667eea; padding-bottom: 8px;">
                                        <i class="fas fa-calendar-alt"></i> Periode & Tahun Ajaran
                                    </h6>
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Jumlah Periode</strong></label>
                                                <input type="number" class="form-control" id="jumlah_periode" name="jumlah_periode" value="<?php echo $settings['jumlah_periode'] ?? 1; ?>" min="1" required>
                                                <small class="text-muted">1 periode = 1 bulan</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Tahun Ajaran</strong></label>
                                                <select class="form-control" id="tahun_ajaran" name="tahun_ajaran" required>
                                                    <?php foreach ($tahunAjaranOptions as $ta): ?>
                                                        <option value="<?php echo htmlspecialchars($ta); ?>" <?php echo (($settings['tahun_ajaran'] ?? '') === $ta) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($ta); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Field bulan untuk 1 periode -->
                                    <div class="row mb-4" id="periode_single_row" style="display: none;">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Bulan Periode Aktif</strong></label>
                                                <input type="month" class="form-control" id="periode_single" name="periode_single" value="<?php echo $settings['periode_aktif'] ?? date('Y-m'); ?>">
                                                <input type="hidden" name="periode_aktif" id="periode_aktif_hidden" value="<?php echo $settings['periode_aktif'] ?? date('Y-m'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Field bulan untuk 2 periode atau lebih -->
                                    <div class="row mb-4" id="periode_range_row" style="display: none;">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Bulan Mulai</strong></label>
                                                <input type="month" class="form-control" id="periode_mulai" name="periode_mulai" value="<?php echo htmlspecialchars($settings['periode_mulai'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Bulan Akhir</strong></label>
                                                <input type="month" class="form-control" id="periode_akhir" name="periode_akhir" value="<?php echo htmlspecialchars($settings['periode_akhir'] ?? ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Pengaturan Keuangan -->
                                    <h6 class="section-title mb-3 mt-4" style="color: #667eea; font-weight: 600; border-bottom: 2px solid #667eea; padding-bottom: 8px;">
                                        <i class="fas fa-money-bill-wave"></i> Pengaturan Keuangan
                                    </h6>
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Jumlah Honor Per Jam</strong></label>
                                                <div class="input-group">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text">Rp</span>
                                                    </div>
                                                    <input type="text" class="form-control currency-input" id="honor_per_jam" data-value="<?php echo $settings['honor_per_jam']; ?>" placeholder="0" required>
                                                    <input type="hidden" name="honor_per_jam" id="honor_per_jam_hidden" value="<?php echo $settings['honor_per_jam']; ?>">
                                                </div>
                                                <small class="text-muted">Honor per jam mengajar</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Pengaturan Slip Gaji -->
                                    <h6 class="section-title mb-3 mt-4" style="color: #667eea; font-weight: 600; border-bottom: 2px solid #667eea; padding-bottom: 8px;">
                                        <i class="fas fa-file-invoice"></i> Pengaturan Slip Gaji
                                    </h6>
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Tempat</strong></label>
                                                <input type="text" class="form-control" name="tempat" value="<?php echo htmlspecialchars($settings['tempat'] ?? ''); ?>" placeholder="Contoh: Sukosono">
                                                <small class="text-muted">Tempat untuk slip gaji</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label><strong>Hari, Tanggal</strong></label>
                                                <input type="date" class="form-control" name="hari_tanggal" value="<?php echo (!empty($settings['hari_tanggal']) && ($settings['hari_tanggal'] ?? '') !== '0000-00-00') ? htmlspecialchars($settings['hari_tanggal']) : ''; ?>">
                                                <small class="text-muted">Tanggal untuk slip gaji (akan ditampilkan dalam format Indonesia)</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Tombol Simpan -->
                                    <div class="form-group mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-save"></i> Simpan Pengaturan
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

<script>
// Wait for jQuery to be loaded
(function() {
    function initPengaturanScripts() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initPengaturanScripts, 50);
            return;
        }
        
        var $ = jQuery;
        
        $(document).ready(function() {
            // Format Rupiah function (Indonesian format: 1.000.000) - harus didefinisikan dulu
            function formatRupiah(angka) {
                var number_string = angka.toString().replace(/[^\d]/g, '');
                if (number_string === '' || number_string === '0') return '0';
                
                var sisa = number_string.length % 3;
                var rupiah = number_string.substr(0, sisa);
                var ribuan = number_string.substr(sisa).match(/\d{3}/g);
                
                if (ribuan) {
                    var separator = sisa ? '.' : '';
                    rupiah += separator + ribuan.join('.');
                }
                
                return rupiah;
            }
            
            // Store original honor_per_jam value from database (tidak tergantung periode)
            var originalHonorPerJam = parseFloat($('#honor_per_jam').data('value')) || parseFloat($('#honor_per_jam_hidden').val()) || 0;
            
            // Function to ensure honor_per_jam tetap tampil (tidak ter-reset saat periode berubah)
            function ensureHonorPerJamVisible() {
                // Ambil nilai saat ini dari input
                var currentValue = parseFloat($('#honor_per_jam_hidden').val()) || 0;
                
                // Jika nilai kosong atau 0, gunakan nilai original dari database
                if (currentValue === 0 || isNaN(currentValue)) {
                    currentValue = originalHonorPerJam;
                }
                
                // Pastikan honor_per_jam tetap tampil dengan format yang benar
                var honorFormatted = formatRupiah(currentValue.toString());
                $('#honor_per_jam').val(honorFormatted);
                $('#honor_per_jam_hidden').val(currentValue);
                $('input[name="honor_per_jam"]').val(currentValue);
            }
            
            // Handle jumlah periode change
            function updatePeriodeFields() {
                var jumlahPeriode = parseInt($('#jumlah_periode').val()) || 1;
                
                if (jumlahPeriode === 1) {
                    // Tampilkan 1 field bulan
                    $('#periode_single_row').show();
                    $('#periode_range_row').hide();
                } else {
                    // Tampilkan 2 field bulan (mulai dan akhir)
                    $('#periode_single_row').hide();
                    $('#periode_range_row').show();
                }
                
                // Pastikan honor_per_jam tetap tampil setelah periode berubah (karena honor tetap tidak tergantung periode)
                ensureHonorPerJamVisible();
            }
            
            // Initialize periode fields on page load
            updatePeriodeFields();
            
            // Update periode fields when jumlah_periode changes
            $('#jumlah_periode').on('change input', function() {
                updatePeriodeFields();
            });
            
            // Update hidden periode_aktif when single periode changes
            $('#periode_single').on('change', function() {
                $('#periode_aktif_hidden').val($(this).val());
                // Pastikan honor_per_jam tetap tampil setelah periode berubah
                ensureHonorPerJamVisible();
            });
            
            // Update hidden periode_aktif when periode_mulai changes
            $('#periode_mulai').on('change', function() {
                var jumlahPeriode = parseInt($('#jumlah_periode').val()) || 1;
                if (jumlahPeriode > 1) {
                    $('#periode_aktif_hidden').val($(this).val());
                }
                // Pastikan honor_per_jam tetap tampil setelah periode berubah
                ensureHonorPerJamVisible();
            });
            
            // Update hidden periode_aktif when periode_akhir changes
            $('#periode_akhir').on('change', function() {
                // Pastikan honor_per_jam tetap tampil setelah periode berubah
                ensureHonorPerJamVisible();
            });
            
            // Unformat Rupiah function (remove dots and convert to number)
            function unformatRupiah(rupiah) {
                if (!rupiah || rupiah === '') return 0;
                // Remove all dots (thousand separators) and keep only numbers
                var cleaned = rupiah.toString().replace(/\./g, '').replace(/[^0-9]/g, '');
                return parseFloat(cleaned) || 0;
            }
            
            // Initialize honor_per_jam value - get from data-value attribute and hidden input value
            // Honor per jam tidak tergantung periode, jadi selalu ambil dari database
            var honorDataValue = parseFloat($('#honor_per_jam').data('value')) || 0;
            var honorHiddenValue = parseFloat($('#honor_per_jam_hidden').val()) || 0;
            
            // Use the non-zero value or data-value (prioritize data-value, but accept any value including 0)
            var honorValue = (!isNaN(honorDataValue) && honorDataValue !== null) ? honorDataValue : 
                             (!isNaN(honorHiddenValue) && honorHiddenValue !== null) ? honorHiddenValue : 0;
            
            // Update originalHonorPerJam dengan nilai dari database (untuk restore saat periode berubah)
            originalHonorPerJam = honorValue;
            
            // Format and display honor_per_jam, and update hidden inputs
            // Always format, even if 0
            var honorFormatted = formatRupiah(honorValue.toString());
            
            $('#honor_per_jam').val(honorFormatted);
            $('#honor_per_jam_hidden').val(honorValue);
            $('input[name="honor_per_jam"]').val(honorValue);
            
            // Format on input - update hidden input immediately
            $('.currency-input').on('input', function() {
                var $this = $(this);
                var value = $this.val().replace(/\./g, '');
                $this.val(formatRupiah(value));
                
                // Get numeric value
                var numericValue = unformatRupiah($this.val());
                numericValue = isNaN(numericValue) ? 0 : parseFloat(numericValue);
                
                // Update hidden input by name and ID
                var fieldName = $this.attr('id');
                $('input[name="' + fieldName + '"]').val(numericValue);
                $('#' + fieldName + '_hidden').val(numericValue);
                
            });
            
            // Format on blur - final update
            $('.currency-input').on('blur', function() {
                var $this = $(this);
                var value = $this.val().replace(/\./g, '');
                
                if (value === '' || value === '0') {
                    $this.val('0');
                    var numericValue = 0;
                } else {
                    $this.val(formatRupiah(value));
                    var numericValue = unformatRupiah($this.val());
                    numericValue = isNaN(numericValue) ? 0 : parseFloat(numericValue);
                }
                
                // Update hidden input by name and ID
                var fieldName = $this.attr('id');
                $('input[name="' + fieldName + '"]').val(numericValue);
                $('#' + fieldName + '_hidden').val(numericValue);
                
            });
            
            // Prevent non-numeric input (except backspace, delete, arrow keys)
            $('.currency-input').on('keydown', function(e) {
                // Allow: backspace, delete, tab, escape, enter, decimal point
                if ([46, 8, 9, 27, 13, 110, 190].indexOf(e.keyCode) !== -1 ||
                    // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
                    (e.keyCode === 65 && e.ctrlKey === true) ||
                    (e.keyCode === 67 && e.ctrlKey === true) ||
                    (e.keyCode === 86 && e.ctrlKey === true) ||
                    (e.keyCode === 88 && e.ctrlKey === true) ||
                    // Allow: home, end, left, right
                    (e.keyCode >= 35 && e.keyCode <= 39)) {
                    return;
                }
                // Ensure that it is a number and stop the keypress
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            });
            
            // Handle form submit - ensure hidden values are set
            $('form').on('submit', function(e) {
                // Force update honor_per_jam before submit
                var honorRaw = $('#honor_per_jam').val();
                
                // Unformat and convert
                var honorValue = unformatRupiah(honorRaw);
                
                // Ensure valid number
                honorValue = (isNaN(honorValue) || honorValue === '' || honorValue === null) ? 0 : parseFloat(honorValue);
                
                // Update ALL hidden inputs with the same name (there might be multiple)
                $('input[name="honor_per_jam"]').each(function() {
                    $(this).val(honorValue);
                });
                
                // Also update by ID
                $('#honor_per_jam_hidden').val(honorValue);
                
                // Update periode_aktif based on jumlah_periode
                var jumlahPeriode = parseInt($('#jumlah_periode').val()) || 1;
                if (jumlahPeriode === 1) {
                    // Use single periode value
                    $('#periode_aktif_hidden').val($('#periode_single').val());
                } else {
                    // For multiple periode, use periode_mulai as periode_aktif
                    $('#periode_aktif_hidden').val($('#periode_mulai').val());
                }
                
                // Verify values are set - create a small delay to ensure DOM is updated
                setTimeout(function() {
                    // Values are ready for submission
                }, 50);
            });
        });
    }
    
    // Start initialization
    initPengaturanScripts();
})();
</script>

