<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin', 'kepala_madrasah'])) {
    redirect('../login.php');
}

// Handle Delete Action
if (isset($_POST['delete_journal'])) {
    $id_jurnal = (int)$_POST['id_jurnal'];
    $current_class = isset($_GET['kelas']) ? $_GET['kelas'] : '';
    
    try {
        $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE id = ?");
        $stmt->execute([$id_jurnal]);
        
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
        logActivity($pdo, $username, 'Hapus Jurnal', "Admin menghapus jurnal ID: $id_jurnal");
        
        $message = ['type' => 'success', 'text' => 'Data jurnal berhasil dihapus!'];
    } catch (Exception $e) {
        $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
    }
}

// Handle Multiple Delete Action
if (isset($_POST['delete_multiple_journal'])) {
    $ids = $_POST['ids'] ?? [];
    if (!empty($ids)) {
        try {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM tb_jurnal WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $count = count($ids);
            logActivity($pdo, $username, 'Hapus Jurnal Massal', "Admin menghapus $count jurnal");
            
            $message = ['type' => 'success', 'text' => "$count data jurnal berhasil dihapus!"];
        } catch (Exception $e) {
            $message = ['type' => 'error', 'text' => 'Gagal menghapus data: ' . $e->getMessage()];
        }
    }
}

// Get all classes
$stmt = $pdo->query("SELECT * FROM tb_kelas ORDER BY nama_kelas ASC");
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teachers
$stmt_guru = $pdo->query("SELECT * FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $stmt_guru->fetchAll(PDO::FETCH_ASSOC);

// Get teaching hours mapping
$stmt_jam = $pdo->query("SELECT * FROM tb_jam_mengajar");
$jam_mengajar_rows = $stmt_jam->fetchAll(PDO::FETCH_ASSOC);
$jam_map = [];
foreach ($jam_mengajar_rows as $row) {
    $jam_map[$row['jam_ke']] = [
        'mulai' => date('H:i', strtotime($row['waktu_mulai'])),
        'selesai' => date('H:i', strtotime($row['waktu_selesai']))
    ];
}

// Get journal entries
$journal_entries = [];
$class_info = [];
$filter_title = '';

// Get unique jam_ke options
$jam_ke_options = [];
foreach ($jam_mengajar_rows as $row) {
    if (!in_array($row['jam_ke'], ['A', 'B', 'C'])) {
        $jam_ke_options[] = $row['jam_ke'];
    }
}
$jam_ke_options = array_unique($jam_ke_options);
sort($jam_ke_options, SORT_NUMERIC);

$where_clauses = ["j.mapel NOT IN ('Istirahat I', 'Istirahat II', 'Upacara Bendera', 'Asmaul Husna')", "j.jam_ke NOT IN ('A', 'B', 'C')"];
$params = [];

if (isset($_GET['kelas']) && !empty($_GET['kelas'])) {
    $where_clauses[] = "j.id_kelas = ?";
    $params[] = $_GET['kelas'];
    
    $stmt_class = $pdo->prepare("SELECT * FROM tb_kelas WHERE id_kelas = ?");
    $stmt_class->execute([$_GET['kelas']]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);
    $filter_title .= ($filter_title ? ' - ' : '') . ($class_info['nama_kelas'] ?? '');
}

if (isset($_GET['guru']) && !empty($_GET['guru'])) {
    $where_clauses[] = "j.id_guru = ?";
    $params[] = $_GET['guru'];
    
    $stmt_g = $pdo->prepare("SELECT nama_guru FROM tb_guru WHERE id_guru = ?");
    $stmt_g->execute([$_GET['guru']]);
    $guru_name = $stmt_g->fetchColumn();
    $filter_title .= ($filter_title ? ' - ' : '') . $guru_name;
}

if (isset($_GET['jam_ke']) && !empty($_GET['jam_ke'])) {
    $where_clauses[] = "FIND_IN_SET(?, j.jam_ke)";
    $params[] = $_GET['jam_ke'];
    $filter_title .= ($filter_title ? ' - ' : '') . 'Jam Ke-' . $_GET['jam_ke'];
}

if (isset($_GET['jenis']) && !empty($_GET['jenis'])) {
    $where_clauses[] = "j.jenis = ?";
    $params[] = $_GET['jenis'];
    $filter_title .= ($filter_title ? ' - ' : '') . $_GET['jenis'];
}

$school_profile = getSchoolProfile($pdo);
$periode_ta = getRentangTanggalTahunAjaran($school_profile['tahun_ajaran'] ?? null);
if ($periode_ta) {
    $where_clauses[] = 'j.tanggal >= ?';
    $where_clauses[] = 'j.tanggal <= ?';
    $params[] = $periode_ta['mulai'];
    $params[] = $periode_ta['sampai'];
}

if (!empty($params)) {
    $query = "SELECT j.*, g.nama_guru, k.nama_kelas 
              FROM tb_jurnal j 
              LEFT JOIN tb_guru g ON j.id_guru = g.id_guru 
              LEFT JOIN tb_kelas k ON j.id_kelas = k.id_kelas
              WHERE " . implode(' AND ', $where_clauses) . "
              ORDER BY j.tanggal DESC, j.jam_ke DESC";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $journal_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set page title
$page_title = 'Jurnal Mengajar';

// Define CSS libraries
$css_libs = [];

// Define JS libraries
$js_libs = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

// Page specific JS
$js_page = [
    "
    $(document).ready(function() {
        $('#check-all').click(function() {
            $('.check-item').prop('checked', this.checked);
            toggleBulkDeleteBtn();
        });

        $(document).on('change', '.check-item', function() {
            toggleBulkDeleteBtn();
            if ($('.check-item:checked').length == $('.check-item').length) {
                $('#check-all').prop('checked', true);
            } else {
                $('#check-all').prop('checked', false);
            }
        });
    });

    function toggleBulkDeleteBtn() {
        if ($('.check-item:checked').length > 0) {
            $('#btn-bulk-delete').show();
        } else {
            $('#btn-bulk-delete').hide();
        }
    }

    function bulkDelete() {
        var ids = [];
        $('.check-item:checked').each(function() {
            ids.push($(this).val());
        });

        if (ids.length > 0) {
            Swal.fire({
                title: 'Apakah Anda yakin?',
                text: ids.length + ' data jurnal akan dihapus permanen!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, hapus!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = window.location.href;

                    ids.forEach(function(id) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });

                    var inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'delete_multiple_journal';
                    inputAction.value = '1';
                    form.appendChild(inputAction);

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
    }

    function confirmDelete(id) {
        Swal.fire({
            title: 'Apakah Anda yakin?',
            text: 'Data jurnal yang dihapus tidak dapat dikembalikan!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create a form and submit it
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.href;
                
                var inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'id_jurnal';
                inputId.value = id;
                
                var inputDelete = document.createElement('input');
                inputDelete.type = 'hidden';
                inputDelete.name = 'delete_journal';
                inputDelete.value = '1';
                
                form.appendChild(inputId);
                form.appendChild(inputDelete);
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    "
];

include '../templates/header.php';
?>

<!-- Main Content -->
<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Jurnal Mengajar</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Jurnal Mengajar</div>
            </div>
        </div>

        <?php if (isset($message)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Info!'; ?>',
                    text: '<?php echo addslashes($message['text']); ?>',
                    icon: '<?php echo $message['type']; ?>',
                    timer: 3000,
                    timerProgressBar: true,
                    showConfirmButton: false
                });
            });
        </script>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Filter Data</h4>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Pilih Kelas</label>
                                        <select class="form-control select2" name="kelas" onchange="this.form.submit()">
                                            <option value="">-- Semua Kelas --</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id_kelas']; ?>" <?php echo (isset($_GET['kelas']) && $_GET['kelas'] == $class['id_kelas']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($class['nama_kelas']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Pilih Guru</label>
                                        <select class="form-control select2" name="guru" onchange="this.form.submit()">
                                            <option value="">-- Semua Guru --</option>
                                            <?php foreach ($teachers as $teacher): ?>
                                                <option value="<?php echo $teacher['id_guru']; ?>" <?php echo (isset($_GET['guru']) && $_GET['guru'] == $teacher['id_guru']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Pilih Jam Ke</label>
                                        <select class="form-control select2" name="jam_ke" onchange="this.form.submit()">
                                            <option value="">-- Semua Jam --</option>
                                            <?php foreach ($jam_ke_options as $jam): ?>
                                                <option value="<?php echo $jam; ?>" <?php echo (isset($_GET['jam_ke']) && $_GET['jam_ke'] == $jam) ? 'selected' : ''; ?>>
                                                    <?php echo $jam; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Jenis Jadwal</label>
                                        <select class="form-control select2" name="jenis" onchange="this.form.submit()">
                                            <option value="">-- Semua Jenis --</option>
                                            <option value="Reguler" <?php echo (isset($_GET['jenis']) && $_GET['jenis'] == 'Reguler') ? 'selected' : ''; ?>>Reguler</option>
                                            <option value="Ramadhan" <?php echo (isset($_GET['jenis']) && $_GET['jenis'] == 'Ramadhan') ? 'selected' : ''; ?>>Ramadhan</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($params)): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
                        <h4>Data Jurnal Mengajar <?php echo $filter_title ? '- ' . htmlspecialchars($filter_title) : ''; ?></h4>
                        <div class="d-flex align-items-center flex-wrap" style="gap: 6px;">
                            <div class="btn-group mr-1">
                                <a href="../config/export_jurnal_pdf?session_type=kepala&kelas=<?= $_GET['kelas'] ?? '' ?>&guru=<?= $_GET['guru'] ?? '' ?>&jam_ke=<?= $_GET['jam_ke'] ?? '' ?>&jenis=<?= $_GET['jenis'] ?? '' ?>" target="_blank" class="btn btn-danger btn-sm">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </a>
                                <a href="../config/export_jurnal_excel?session_type=kepala&kelas=<?= $_GET['kelas'] ?? '' ?>&guru=<?= $_GET['guru'] ?? '' ?>&jam_ke=<?= $_GET['jam_ke'] ?? '' ?>&jenis=<?= $_GET['jenis'] ?? '' ?>" target="_blank" class="btn btn-success btn-sm">
                                    <i class="fas fa-file-excel"></i> Excel
                                </a>
                            </div>
                            <div class="custom-checkbox custom-control mr-1" title="Pilih semua">
                                <input type="checkbox" class="custom-control-input" id="check-all">
                                <label class="custom-control-label font-weight-bold small" for="check-all">Pilih semua</label>
                            </div>
                            <button id="btn-bulk-delete" class="btn btn-danger btn-sm" style="display: none;" onclick="bulkDelete()" title="Hapus Terpilih">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <style>
                        .jurnal-timeline { position: relative; padding: 6px 0 6px 8px; }
                        .jurnal-timeline::before { content: ''; position: absolute; left: 57px; top: 10px; bottom: 10px; width: 3px; border-radius: 3px; background: linear-gradient(180deg, #6777ef, #3abaf4); opacity: .35; }
                        .jurnal-month { position: relative; z-index: 1; display: inline-block; margin: 14px 0 14px 34px; padding: 4px 16px; border-radius: 20px; background: #6777ef; color: #fff; font-weight: 800; font-size: 12px; letter-spacing: .3px; box-shadow: 0 4px 10px rgba(103,119,239,.35); }
                        .jurnal-month:first-child { margin-top: 0; }
                        .jurnal-item { position: relative; display: flex; gap: 14px; margin-bottom: 14px; }
                        .jurnal-date { flex: 0 0 76px; text-align: center; z-index: 1; }
                        .jurnal-date .d { display: block; width: 46px; height: 46px; margin: 0 auto; border-radius: 50%; background: #fff; border: 3px solid var(--tl-color, #6777ef); color: var(--tl-color, #6777ef); font-weight: 800; font-size: 17px; line-height: 40px; box-shadow: 0 3px 8px rgba(0,0,0,.10); }
                        .jurnal-date .m { display: block; margin-top: 3px; font-size: 10.5px; font-weight: 800; color: #6c757d; text-transform: uppercase; }
                        .jurnal-card { flex: 1; min-width: 0; background: #fff; border: 1px solid #e9edf5; border-radius: 12px; box-shadow: 0 4px 14px rgba(20,30,70,.07); padding: 12px 14px; border-left: 4px solid var(--tl-color, #6777ef); transition: box-shadow .2s ease, transform .2s ease; }
                        .jurnal-card:hover { box-shadow: 0 10px 26px rgba(20,30,70,.13); transform: translateY(-2px); }
                        @media (max-width: 575.98px) {
                            .jurnal-timeline::before { left: 47px; }
                            .jurnal-item { gap: 10px; }
                            .jurnal-date { flex-basis: 60px; }
                            .jurnal-date .d { width: 38px; height: 38px; font-size: 15px; line-height: 32px; }
                        }
                        </style>

                        <?php if (count($journal_entries) === 0): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-book fa-3x mb-3"></i><br>
                                <strong>Belum ada entri jurnal pada filter ini.</strong>
                            </div>
                        <?php else: ?>
                            <?php
                            $bulan_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                            $cur_month = '';
                            ?>
                            <div class="jurnal-timeline">
                                <?php foreach ($journal_entries as $journal): ?>
                                    <?php
                                    $mk = date('Y-m', strtotime($journal['tanggal']));
                                    if ($mk !== $cur_month) {
                                        $cur_month = $mk;
                                        echo '<div class="jurnal-month"><i class="far fa-calendar-alt mr-1"></i>' . $bulan_id[(int)date('n', strtotime($journal['tanggal']))] . ' ' . date('Y', strtotime($journal['tanggal'])) . '</div>';
                                    }
                                    $jenis_tl = $journal['jenis'] ?? 'Reguler';
                                    $tl_color = $jenis_tl === 'Ramadhan' ? '#ffa426' : '#6777ef';
                                    $jam_list = explode(',', (string)($journal['jam_ke'] ?? ''));
                                    $jam_list = array_filter(array_map(function($val) { return (int)trim($val); }, $jam_list));
                                    $waktu_str = '-';
                                    if (!empty($jam_list)) {
                                        $jam_start = min($jam_list);
                                        $jam_end = max($jam_list);
                                        $start_time = isset($jam_map[$jam_start]) ? $jam_map[$jam_start]['mulai'] : '';
                                        $end_time = isset($jam_map[$jam_end]) ? $jam_map[$jam_end]['selesai'] : '';
                                        if ($start_time && $end_time) $waktu_str = $start_time . ' - ' . $end_time;
                                        elseif ($start_time) $waktu_str = $start_time;
                                    }
                                    ?>
                                    <div class="jurnal-item">
                                        <div class="jurnal-date" style="--tl-color: <?= $tl_color ?>;">
                                            <span class="d"><?= date('d', strtotime($journal['tanggal'])) ?></span>
                                            <span class="m"><?= mb_substr($bulan_id[(int)date('n', strtotime($journal['tanggal']))], 0, 3) ?></span>
                                        </div>
                                        <div class="jurnal-card" style="--tl-color: <?= $tl_color ?>;">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap mb-1" style="gap: 6px;">
                                                <div class="d-flex flex-wrap" style="gap: 6px;">
                                                    <span class="badge px-2 py-1 font-weight-bold" style="background: <?= $tl_color ?>1a; color: <?= $tl_color ?>; font-size: 11px; border-radius: 6px;">
                                                        <i class="fas fa-book-open mr-1"></i><?= htmlspecialchars($journal['mapel']) ?>
                                                    </span>
                                                    <span class="badge badge-light border font-weight-bold" style="font-size: 11px; border-radius: 6px;">Jam ke-<?= htmlspecialchars($journal['jam_ke']) ?></span>
                                                    <span class="badge <?= $jenis_tl === 'Ramadhan' ? 'badge-warning' : 'badge-primary' ?>" style="font-size: 11px; border-radius: 6px;"><?= htmlspecialchars($jenis_tl) ?></span>
                                                </div>
                                                <div class="custom-checkbox custom-control">
                                                    <input type="checkbox" class="custom-control-input check-item" id="checkbox-<?php echo $journal['id']; ?>" value="<?php echo $journal['id']; ?>">
                                                    <label class="custom-control-label" for="checkbox-<?php echo $journal['id']; ?>"></label>
                                                </div>
                                            </div>
                                            <h6 class="font-weight-bold text-dark mb-1" style="font-size: 14px;"><?= htmlspecialchars($journal['materi']) ?></h6>
                                            <div class="small text-muted mb-2" style="font-size: 11.5px; line-height: 1.8;">
                                                <span class="mr-3"><i class="far fa-calendar-alt mr-1"></i><?= date('d-m-Y', strtotime($journal['tanggal'])) ?></span>
                                                <span class="mr-3"><i class="far fa-clock mr-1"></i><?= htmlspecialchars($waktu_str) ?></span>
                                                <span class="mr-3"><i class="fas fa-user mr-1"></i><?= htmlspecialchars($journal['nama_guru'] ?? '-') ?></span>
                                                <span class="mr-3"><i class="fas fa-door-open mr-1"></i><?= htmlspecialchars($journal['nama_kelas'] ?? '-') ?></span>
                                                <span><i class="fas fa-history mr-1"></i>Dicatat <?= date('d-m-Y H:i', strtotime($journal['created_at'])) ?></span>
                                            </div>
                                            <div class="pt-2 border-top d-flex flex-wrap" style="gap: 6px;">
                                                <button onclick="confirmDelete(<?php echo $journal['id']; ?>)" class="btn btn-danger btn-sm px-2 font-weight-bold" title="Hapus" style="border-radius: 6px; font-size: 11.5px;">
                                                    <i class="fas fa-trash mr-1"></i>Hapus
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
