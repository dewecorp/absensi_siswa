<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah', 'tata_usaha'])) {
    redirect('../login.php');
}

$user_level = getUserLevel();
$can_manage = ($user_level === 'admin');
$school_profile = getSchoolProfile($pdo);
$periode_ta = getRentangTanggalTahunAjaran($school_profile['tahun_ajaran'] ?? null);

// Handle Delete Action
if ($can_manage && isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id = ?");
        $stmt->execute([$id_jurnal]);
        
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Hapus Jurnal Les', "Admin menghapus jurnal les ID: $id_jurnal");
        
        $message = ['type' => 'success', 'text' => 'Data jurnal les berhasil dihapus!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
    }
}

// Handle Multiple Delete Action
if ($can_manage && isset($_POST['delete_multiple_journal'])) {
    // Get IDs from JSON string in selected-ids field
    $ids_json = $_POST['selected-ids'] ?? '';
    $ids = !empty($ids_json) ? json_decode($ids_json, true) : [];
    
    if (!empty($ids)) {
        try {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM tb_jurnal_les WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $count = count($ids);
            logActivity($pdo, $username, 'Hapus Jurnal Les Massal', "Admin menghapus $count jurnal les");
            
            $message = ['type' => 'success', 'text' => "$count data jurnal les berhasil dihapus!"];
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
        }
    }
}

// Get journal entries
$journal_entries = [];
$filter_title = '';

// Build filter conditions - only guru filter
$where_clauses = [];
$params = [];

if ($periode_ta) {
    $where_clauses[] = "jl.tanggal >= ? AND jl.tanggal <= ?";
    $params[] = $periode_ta['mulai'];
    $params[] = $periode_ta['sampai'];
}

if (isset($_GET['guru']) && !empty($_GET['guru'])) {
    $where_clauses[] = "jl.id_guru = ?";
    $params[] = $_GET['guru'];
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$_GET['guru']]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ' - ' . $guru_name;
}

// Always execute query - if no filters, show all journals
$query = "SELECT jl.*, g.nama_guru, k.nama_kelas 
          FROM tb_jurnal_les jl 
          LEFT JOIN tb_guru g ON jl.id_guru = g.id_guru 
          LEFT JOIN tb_kelas k ON jl.id_kelas = k.id_kelas";

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(' AND ', $where_clauses);
}

$query .= " ORDER BY jl.tanggal DESC, jl.waktu";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set page title
$page_title = 'Jurnal Les';

// Define CSS libraries
$css_libs = [];

// Define JS libraries
$js_libs = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

// Page specific JS - prepare message variables first
$js_page = [];
if (isset($message)) {
    $msg_type = $message['type'];
    $msg_icon = ($msg_type == 'success') ? 'success' : 'error';
    $msg_title = ($msg_type == 'success') ? 'Berhasil!' : 'Error';
    $msg_text = addslashes($message['text']);
    $js_page[] = <<<JS
    $(document).ready(function() {
        Swal.fire({
            icon: '$msg_icon',
            title: '$msg_title',
            text: '$msg_text',
            timer: 2000,
            showConfirmButton: false
        });
    });
JS;
}

$column_defs = '';
if ($can_manage) {
    $column_defs = "{ 'orderable': false, 'targets': [0, 7] }";
}

$js_page[] = <<<JS
$(document).ready(function() {
    function toggleLesBulkBtn() {
        var n = $('input[name="ids[]"]:checked').length;
        if (n > 0) { $('#delete-selected-btn').show(); } else { $('#delete-selected-btn').hide(); }
    }
    toggleLesBulkBtn();
    
    // Delete single confirmation
    $(document).on('click', '.btn-delete', function(e) {
        e.preventDefault();
        var form = $(this).closest('.delete-form');
        var journalId = form.find('input[name="id_jurnal"]').val();
        
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Data jurnal les yang dihapus tidak dapat dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create and submit form to ensure proper submission
                var submitForm = $('<form>').attr({
                    method: 'POST',
                    action: window.location.href
                });
                
                submitForm.append($('<input>').attr({
                    type: 'hidden',
                    name: 'id_jurnal',
                    value: journalId
                }));
                
                submitForm.append($('<input>').attr({
                    type: 'hidden',
                    name: 'delete_journal',
                    value: '1'
                }));
                
                $('body').append(submitForm);
                submitForm.submit();
            }
        });
    });
    
    // Delete multiple confirmation
    $('#delete-selected-btn').on('click', function(e) {
        e.preventDefault();
        var selectedIds = [];

        $('input[name="ids[]"]:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            Swal.fire('Info', 'Pilih minimal satu data untuk dihapus', 'info');
            return;
        }
        
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: selectedIds.length + ' data jurnal les akan dihapus!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Hapus Semua!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#selected-ids').val(JSON.stringify(selectedIds));
                $('<input>').attr({
                    type: 'hidden',
                    name: 'delete_multiple_journal',
                    value: '1'
                }).appendTo('#delete-multiple-form');
                $('#delete-multiple-form').submit();
            }
        });
    });
    
    // Select all checkbox
    $('#select-all').on('click', function() {
        var isChecked = $(this).prop('checked');
        $('input[name="ids[]"]').prop('checked', isChecked);
        toggleLesBulkBtn();
    });
    $(document).on('change', 'input[name="ids[]"]', function() {
        toggleLesBulkBtn();
    });
});
JS;

include '../templates/header.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jurnal Les</h1>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header">
                    <h4>Data Jurnal Les 
                        <?php 
                        if (!empty($filter_title)) {
                            echo '- ' . $filter_title;
                        } else {
                            echo 'Semua Guru Kelas 6';
                        }
                        ?>
                    </h4>
                    <div class="card-header-action">
                        <div class="btn-group">
                            <a href="../config/export_jurnal_les_pdf.php?session_type=admin&guru=<?php echo $_GET['guru'] ?? ''; ?>" target="_blank" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> PDF
                            </a>
                            <a href="../config/export_jurnal_les_excel.php?session_type=admin&guru=<?php echo $_GET['guru'] ?? ''; ?>" target="_blank" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Excel
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filter Section -->
                    <form method="GET" class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Filter Guru</label>
                                    <select name="guru" class="form-control" onchange="this.form.submit()">
                                        <option value="">Semua Guru Kelas 6</option>
                                        <?php 
                                        // Get ALL grade 6 teachers from schedule (tb_jadwal_pelajaran)
                                        // This includes teachers who may not have journal entries yet
                                        $stmt_guru_kelas_6 = $pdo->prepare("
                                            SELECT DISTINCT g.id_guru, g.nama_guru
                                            FROM tb_guru g
                                            INNER JOIN tb_jadwal_pelajaran jp ON g.id_guru = jp.guru_id
                                            INNER JOIN tb_kelas k ON jp.kelas_id = k.id_kelas
                                            WHERE k.nama_kelas LIKE '%6%' OR k.nama_kelas LIKE '%VI%'
                                            UNION
                                            SELECT id_guru, nama_guru
                                            FROM tb_guru
                                            WHERE nama_guru IN (SELECT wali_kelas FROM tb_kelas WHERE nama_kelas LIKE '%6%' OR nama_kelas LIKE '%VI%')
                                            ORDER BY nama_guru ASC
                                        ");
                                        $stmt_guru_kelas_6->execute();
                                        $guru_kelas_6 = $stmt_guru_kelas_6->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($guru_kelas_6 as $t): 
                                        ?>
                                            <option value="<?php echo $t['id_guru']; ?>" <?php echo (isset($_GET['guru']) && $_GET['guru'] == $t['id_guru']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['nama_guru']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <?php if (!empty($journal_entries)): ?>
                    <style>
                    .jurnal-timeline { position: relative; padding: 6px 0 6px 8px; }
                    .jurnal-timeline::before { content: ''; position: absolute; left: 57px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, #20c997, #3abaf4); opacity: .35; }
                    .jurnal-month { position: relative; z-index: 1; display: inline-block; margin: 14px 0 14px 34px; padding: 4px 16px; border-radius: 20px; background: #20c997; color: #fff; font-weight: 800; font-size: 12px; letter-spacing: .3px; box-shadow: 0 4px 10px rgba(32,201,151,.35); }
                    .jurnal-month:first-child { margin-top: 0; }
                    .jurnal-item { position: relative; display: flex; gap: 14px; margin-bottom: 14px; }
                    .jurnal-date { flex: 0 0 76px; text-align: center; z-index: 1; }
                    .jurnal-date .d { display: block; width: 46px; height: 46px; margin: 0 auto; border-radius: 50%; background: #fff; border: 3px solid #20c997; color: #20c997; font-weight: 800; font-size: 17px; line-height: 40px; box-shadow: 0 3px 8px rgba(0,0,0,.10); }
                    .jurnal-date .m { display: block; margin-top: 3px; font-size: 10.5px; font-weight: 800; color: #6c757d; text-transform: uppercase; }
                    .jurnal-card { flex: 1; min-width: 0; background: #fff; border: 1px solid #e9edf5; border-radius: 12px; box-shadow: 0 4px 14px rgba(20,30,70,.07); padding: 12px 14px; border-left: 4px solid #20c997; transition: box-shadow .2s ease, transform .2s ease; }
                    .jurnal-card:hover { box-shadow: 0 10px 26px rgba(20,30,70,.13); transform: translateY(-2px); }
                    @media (max-width: 575.98px) {
                        .jurnal-timeline::before { left: 47px; }
                        .jurnal-item { gap: 10px; }
                        .jurnal-date { flex-basis: 60px; }
                        .jurnal-date .d { width: 38px; height: 38px; font-size: 15px; line-height: 32px; }
                    }
                    </style>
                    <form method="POST" id="delete-multiple-form">
                        <input type="hidden" name="selected-ids" id="selected-ids">

                        <?php if ($can_manage): ?>
                        <div class="mb-3 d-flex flex-wrap align-items-center" style="gap: 8px;">
                            <div class="custom-checkbox custom-control mr-1" title="Pilih semua">
                                <input type="checkbox" class="custom-control-input" id="select-all">
                                <label class="custom-control-label font-weight-bold small" for="select-all">Pilih semua</label>
                            </div>
                            <button type="button" class="btn btn-danger btn-sm" id="delete-selected-btn" style="display: none;">
                                <i class="fas fa-trash"></i> Hapus Dipilih
                            </button>
                        </div>
                        <?php endif; ?>

                        <?php
                        $bulan_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        $cur_month = '';
                        ?>
                        <div class="jurnal-timeline">
                            <?php foreach ($journal_entries as $entry): ?>
                                <?php
                                $mk = date('Y-m', strtotime($entry['tanggal']));
                                if ($mk !== $cur_month) {
                                    $cur_month = $mk;
                                    echo '<div class="jurnal-month"><i class="far fa-calendar-alt mr-1"></i>' . $bulan_id[(int)date('n', strtotime($entry['tanggal']))] . ' ' . date('Y', strtotime($entry['tanggal'])) . '</div>';
                                }
                                ?>
                                <div class="jurnal-item">
                                    <div class="jurnal-date">
                                        <span class="d"><?= date('d', strtotime($entry['tanggal'])) ?></span>
                                        <span class="m"><?= mb_substr($bulan_id[(int)date('n', strtotime($entry['tanggal']))], 0, 3) ?></span>
                                    </div>
                                    <div class="jurnal-card">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap mb-1" style="gap: 6px;">
                                            <span class="badge px-2 py-1 font-weight-bold" style="background: #20c9971a; color: #0d9463; font-size: 11px; border-radius: 6px;">
                                                <i class="fas fa-book-open mr-1"></i><?= htmlspecialchars($entry['mapel']) ?>
                                            </span>
                                            <?php if ($can_manage): ?>
                                                <div class="custom-checkbox custom-control">
                                                    <input type="checkbox" class="custom-control-input les-check" id="lescheck-<?php echo $entry['id']; ?>" name="ids[]" value="<?php echo $entry['id']; ?>">
                                                    <label class="custom-control-label" for="lescheck-<?php echo $entry['id']; ?>"></label>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-dark mb-2" style="font-size: 13.5px; line-height: 1.6; white-space: pre-wrap;"><?= htmlspecialchars($entry['materi']) ?></p>
                                        <div class="small text-muted mb-2" style="font-size: 11.5px; line-height: 1.8;">
                                            <span class="mr-3"><i class="far fa-calendar-alt mr-1"></i><?= date('d/m/Y', strtotime($entry['tanggal'])) ?></span>
                                            <span class="mr-3"><i class="far fa-clock mr-1"></i><?= htmlspecialchars($entry['waktu']) ?></span>
                                            <span class="mr-3"><i class="fas fa-user mr-1"></i><?= htmlspecialchars($entry['nama_guru'] ?? '-') ?></span>
                                            <?php if (!empty($entry['nama_kelas'])): ?>
                                                <span><i class="fas fa-door-open mr-1"></i><?= htmlspecialchars($entry['nama_kelas']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($can_manage): ?>
                                            <div class="pt-2 border-top d-flex flex-wrap" style="gap: 6px;">
                                                <form method="POST" style="display:inline;" class="delete-form">
                                                    <input type="hidden" name="id_jurnal" value="<?php echo $entry['id']; ?>">
                                                    <button type="submit" name="delete_journal" class="btn btn-sm btn-danger px-2 font-weight-bold btn-delete" title="Hapus" style="border-radius: 6px; font-size: 11.5px;">
                                                        <i class="fas fa-trash mr-1"></i>Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <?php if (!empty($where_clauses)): ?>
                            Belum ada data jurnal les untuk filter yang dipilih. Silakan ubah filter atau reset untuk melihat semua data.
                        <?php else: ?>
                            Belum ada data jurnal les. Pastikan guru/wali sudah mengisi jurnal les terlebih dahulu.
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
