<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Log Aktivitas';

// Pagination variables
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20; // Number of records per page
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_clause = '';
$params = array();

if (!empty($search)) {
    $where_clause = "WHERE username LIKE ? OR action LIKE ? OR description LIKE ?";
    $search_param = '%' . $search . '%';
    $params = array($search_param, $search_param, $search_param);
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM tb_activity_log";
if (!empty($where_clause)) {
    $count_query .= " " . $where_clause;
}
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Get activity logs with pagination
$teacher_map = [];
$guru_stmt = $pdo->query("SELECT nuptk, nama_guru, id_guru FROM tb_guru");
$gurus = $guru_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($gurus as $guru) {
    $teacher_map[$guru['nuptk']] = $guru['nama_guru'];
    $teacher_map[$guru['nama_guru']] = $guru['nama_guru'];
    $teacher_map[$guru['id_guru']] = $guru['nama_guru'];
}

if (!empty($where_clause)) {
    $query = "SELECT 
            id,
            username, 
            action, 
            description, 
            ip_address,
            created_at
        FROM tb_activity_log 
        " . $where_clause . " ORDER BY created_at DESC LIMIT ?, ?";
    $stmt = $pdo->prepare($query);
    $params[] = (int)$offset;
    $params[] = (int)$limit;
    $stmt->execute($params);
} else {
    $query = "SELECT 
            id,
            username, 
            action, 
            description, 
            ip_address,
            created_at
        FROM tb_activity_log 
        ORDER BY created_at DESC LIMIT ?, ?";
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(1, $offset, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
}

$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add display_name to each activity
foreach ($activities as &$activity) {
    $display_name = $teacher_map[$activity['username']] ?? $activity['username'];
    $activity['display_name'] = $display_name;
}

// Define CSS libraries for this page
$css_libs = array(
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css',
    'node_modules/datatables.net-select-bs4/css/select.bootstrap4.min.css'
);

// Define JS libraries for this page
$js_libs = array(
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js',
    'node_modules/datatables.net-select-bs4/js/select.bootstrap4.min.js'
);

// Define page-specific JS
$js_page = array();

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Log Aktivitas</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item">Log Aktivitas</div>
            </div>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Aktivitas Pengguna</h4>
                            <div class="card-header-action">
                                <form method="GET" class="form-inline">
                                    <div class="input-group mr-2">
                                        <input type="text" class="form-control" name="search" placeholder="Cari aktivitas..." value="<?php echo htmlspecialchars($search); ?>">
                                        <div class="input-group-btn">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                                            <?php if (!empty($search)): ?>
                                                <a href="activity_log.php" class="btn btn-secondary">Reset</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped" id="activity-log-table">
                                    <thead>
                                        <tr>
                                            <th>Tanggal & Waktu</th>
                                            <th>Username</th>
                                            <th>Aksi</th>
                                            <th>Deskripsi</th>
                                            <th>IP Address</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activities as $activity): ?>
                                        <tr>
                                            <td><?php echo date('d M Y H:i:s', strtotime($activity['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($activity['display_name']); ?></td>
                                            <td>
                                                <span class="badge <?php echo function_exists('getActivityColor') ? str_replace('bg-', 'badge-', getActivityColor(htmlspecialchars($activity['action']))) : 'badge-info'; ?>"><?php echo htmlspecialchars($activity['action']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                            <td><?php echo htmlspecialchars(isset($activity['ip_address']) ? $activity['ip_address'] : '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                <nav>
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">Previous</a>
                                        </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                                        </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">Next</a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <p>Total aktivitas: <?php echo $total_records; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php
// Add JavaScript for DataTables
$js_page[] = "
\$(document).ready(function() {
    // Initialize DataTables with pagination and show entries
    if (\$.fn.DataTable) {
        \$('#activity-log-table').DataTable({
            \"columnDefs\": [
                { \"sortable\": false, \"targets\": [3] } // Disable sorting for description column
            ],
            \"order\": [[ 0, \"desc\" ]], // Sort by date descending by default
            \"paging\": false, // Disable built-in pagination since we have our own
            \"lengthChange\": true,
            \"pageLength\": 20,
            \"lengthMenu\": [[10, 20, 50, 100, -1], [10, 20, 50, 100, 'Semua']],
            \"dom\": 'lfrtip',
            \"info\": true,
            \"language\": {
                \"lengthMenu\": \"Tampilkan _MENU_ entri\",
                \"zeroRecords\": \"Tidak ada data yang ditemukan\",
                \"info\": \"Menampilkan _START_ sampai _END_ dari _TOTAL_ entri\",
                \"infoEmpty\": \"Menampilkan 0 sampai 0 dari 0 entri\",
                \"infoFiltered\": \"(disaring dari _MAX_ total entri)\",
                \"search\": \"Cari:\",
                \"paginate\": {
                    \"first\": \"Pertama\",
                    \"last\": \"Terakhir\",
                    \"next\": \"Selanjutnya\",
                    \"previous\": \"Sebelumnya\"
                }
            }
        });
    }
});
";
include '../templates/footer.php'; 
?>