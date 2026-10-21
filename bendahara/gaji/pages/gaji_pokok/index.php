<?php
require_once __DIR__ . '/../../config/config.php';
if (!defined('GAJI_EMBED')) { $__qs = $_SERVER['QUERY_STRING'] ?? ''; header('Location: ' . SIMAD_BASE_URL . '/bendahara/gaji_pokok.php' . ($__qs !== '' ? '?' . $__qs : '')); exit(); }
$page_title = 'Data Gaji Pokok';


// Get honor_per_jam from settings
$sql = "SELECT honor_per_jam FROM settings LIMIT 1";
$result = $conn->query($sql);
$settings = $result->fetch_assoc();
$honor_per_jam = floatval($settings['honor_per_jam'] ?? 0);

// Get all teachers with their jumlah_jam_mengajar
$sql = "SELECT id, nama_lengkap, jumlah_jam_mengajar 
        FROM guru 
        ORDER BY LOWER(TRIM(nama_lengkap)) ASC, nama_lengkap ASC";
$guru = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// Calculate total gaji pokok for each teacher and ensure data exists in gaji_pokok table
foreach ($guru as &$g) {
    $jumlah_jam = intval($g['jumlah_jam_mengajar'] ?? 0);
    $total_gaji = $jumlah_jam * $honor_per_jam;
    
    // Check if gaji_pokok record exists for this teacher (gaji pokok tidak tergantung periode)
    $check_sql = "SELECT id, jumlah FROM gaji_pokok WHERE guru_id = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $g['id']);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        // Update if total changed
        if (abs(floatval($existing['jumlah']) - $total_gaji) > 0.01) {
            $update_sql = "UPDATE gaji_pokok SET jumlah = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("di", $total_gaji, $existing['id']);
            $update_stmt->execute();
        }
        $g['total_gaji_pokok'] = floatval($existing['jumlah']);
    } else {
        // Insert new record (tanpa periode karena gaji pokok tetap)
        $insert_sql = "INSERT INTO gaji_pokok (guru_id, jumlah, periode) VALUES (?, ?, '')";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("id", $g['id'], $total_gaji);
        $insert_stmt->execute();
        $g['total_gaji_pokok'] = $total_gaji;
    }
    
    $g['total_gaji_pokok'] = $total_gaji; // Use calculated value
}
unset($g);
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Gaji Pokok</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item"><a href="<?php echo SIMAD_BASE_URL; ?>/bendahara/gaji_ringkasan.php">Ringkasan Gaji</a></div>
                            <span class="breadcrumb-item active">Data Gaji Pokok</span>
                        </div>
                    </div>

                    <div class="section-body">
                        <div class="card">
                            <div class="card-header">
                                <h4>Data Gaji Pokok</h4>
                            </div>
                            <div class="card-body">
                                <!-- Buttons container for DataTable export buttons -->
                                <div id="tableGajiPokok_buttons" class="mb-3" style="display: flex; gap: 5px; flex-wrap: wrap;"></div>
                                <div class="table-responsive">
                                    <table class="table table-striped" id="tableGajiPokok">
                                        <thead>
                                            <tr>
                                                <th style="text-align: center;">No</th>
                                                <th>Nama Guru</th>
                                                <th style="text-align: center;">Jumlah Jam Mengajar</th>
                                                <th style="text-align: right;">Total Gaji Pokok</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no = 1; foreach ($guru as $g): ?>
                                                <tr>
                                                    <td style="text-align: center;"><?php echo $no++; ?></td>
                                                    <td><strong><?php echo htmlspecialchars($g['nama_lengkap']); ?></strong></td>
                                                    <td style="text-align: center;"><?php echo intval($g['jumlah_jam_mengajar'] ?? 0); ?> jam</td>
                                                    <td style="text-align: right;"><strong><?php echo formatRupiah($g['total_gaji_pokok'] ?? 0); ?></strong></td>
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

<script>
// Wait for jQuery to be loaded
(function() {
    function initGajiPokokPage() {
        if (typeof jQuery === 'undefined') {
            setTimeout(initGajiPokokPage, 50);
            return;
        }
        
        var $ = jQuery;
        
        $(document).ready(function() {
            // Clear DataTable state from localStorage and sessionStorage
            if (typeof(Storage) !== "undefined") {
                Object.keys(localStorage).forEach(function(key) {
                    if (key.indexOf('DataTables_tableGajiPokok') === 0 || key.indexOf('DataTables_') === 0) {
                        localStorage.removeItem(key);
                    }
                });
                Object.keys(sessionStorage).forEach(function(key) {
                    if (key.indexOf('DataTables_tableGajiPokok') === 0 || key.indexOf('DataTables_') === 0) {
                        sessionStorage.removeItem(key);
                    }
                });
            }
            
            // Destroy existing DataTable instance if it exists
            if ($.fn.DataTable.isDataTable('#tableGajiPokok')) {
                $('#tableGajiPokok').DataTable().destroy();
            }
            
            // Initialize DataTable with export buttons
            var table = $('#tableGajiPokok').DataTable({
                dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>rtip',
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                pageLength: 25,
                stateSave: false,
                buttons: {
                    dom: {
                        button: {
                            className: 'btn btn-sm'
                        }
                    },
                    buttons: [
                        { 
                            extend: 'excel', 
                            text: '<i class="fas fa-file-excel"></i> Excel', 
                            className: 'btn btn-success btn-sm',
                            exportOptions: {
                                format: {
                                    body: function(data, row, column, node) {
                                        // Remove HTML tags and clean data
                                        return data ? data.replace(/<[^>]*>/g, '').trim() : '';
                                    }
                                }
                            },
                            filename: 'Data_Gaji_Pokok_' + new Date().toISOString().split('T')[0],
                            title: 'Data Gaji Pokok'
                        },
                        { 
                            extend: 'pdf', 
                            text: '<i class="fas fa-file-pdf"></i> PDF', 
                            className: 'btn btn-danger btn-sm',
                            filename: 'Data_Gaji_Pokok_' + new Date().toISOString().split('T')[0],
                            title: 'Data Gaji Pokok',
                            orientation: 'landscape',
                            pageSize: 'A4',
                            customize: function(doc) {
                                doc.defaultStyle.fontSize = 9;
                                doc.styles.tableHeader.fontSize = 10;
                                doc.styles.tableHeader.alignment = 'center';
                            }
                        },
                        { 
                            extend: 'print', 
                            text: '<i class="fas fa-print"></i> Print', 
                            className: 'btn btn-info btn-sm'
                        }
                    ]
                },
                language: { 
                    url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/id.json' 
                },
                order: [[1, 'asc']], // Sort by nama (column index 1) - ascending
                stateSave: false, // Prevent saving state to avoid sorting conflicts
                stateDuration: -1, // Never save state
                retrieve: false, // Don't retrieve existing instance
                columnDefs: [
                    {
                        targets: 0, // No column
                        orderable: false,
                        searchable: false
                    },
                    {
                        targets: [1, 2, 3], // Nama, Jumlah Jam, Total Gaji
                        orderable: true,
                        searchable: true
                    }
                ],
                drawCallback: function(settings) {
                    // Remove drawCallback to prevent infinite loop
                    // Order is already set in initial configuration
                }
            });
            
            // Move buttons to custom container
            setTimeout(function() {
                var buttonsContainer = table.buttons().container();
                var targetContainer = $('#tableGajiPokok_buttons');
                
                if (buttonsContainer.length > 0 && targetContainer.length > 0) {
                    if (buttonsContainer.parent().attr('id') !== 'tableGajiPokok_buttons') {
                        buttonsContainer.appendTo(targetContainer);
                    }
                    buttonsContainer.css({
                        'display': 'flex',
                        'flex-wrap': 'wrap',
                        'gap': '5px'
                    });
                    buttonsContainer.find('.dt-button').css({
                        'margin': '0',
                        'display': 'inline-block'
                    });
                }
                
                // Also check for default location
                var defaultButtons = $('.dt-buttons');
                if (defaultButtons.length > 0) {
                    if (defaultButtons.parent().attr('id') !== 'tableGajiPokok_buttons') {
                        defaultButtons.appendTo('#tableGajiPokok_buttons');
                    }
                    defaultButtons.css({
                        'display': 'flex',
                        'flex-wrap': 'wrap',
                        'gap': '5px'
                    });
                }
            }, 500);
        });
    }
    
    // Start initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGajiPokokPage);
    } else {
        initGajiPokokPage();
    }
})();
</script>
