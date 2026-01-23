<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Get school profile
$school_profile = getSchoolProfile($pdo);

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_user'])) {
        $username = sanitizeInput($_POST['username']);
        $password = hashPassword($_POST['password']);
        $level = sanitizeInput($_POST['level']);
        
        // Handle photo upload
        $foto = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_extension, $allowed_extensions)) {
                $foto_filename = 'user_' . time() . '_' . basename($_FILES['foto']['name']);
                $target_path = '../assets/img/' . $foto_filename;
                
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_path)) {
                    $foto = $foto_filename;
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal mengunggah foto!'];
                }
            } else {
                $message = ['type' => 'danger', 'text' => 'Format foto tidak didukung!'];
            }
        }
        
        if (!$message || $message['type'] !== 'danger') {
            $sql = "INSERT INTO tb_pengguna (username, password, level";
            $params = [$username, $password, $level];
            
            if ($foto !== null) {
                $sql .= ", foto";
                $params[] = $foto;
            }
            
            $sql .= ") VALUES (?, ?, ?";
            if ($foto !== null) {
                $sql .= ", ?";
            }
            $sql .= ")";
            
            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                $message = ['type' => 'success', 'text' => 'Data pengguna berhasil ditambahkan!'];
                $log_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $log_username, 'Tambah Pengguna', "Menambahkan pengguna baru: $username (level: $level)");
                if (!$log_result) error_log("Failed to log activity for Tambah Pengguna: $username");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data pengguna!'];
            }
        }
    } elseif (isset($_POST['update_user'])) {
        $id_pengguna = (int)$_POST['id_pengguna'];
        $username = sanitizeInput($_POST['username']);
        $level = sanitizeInput($_POST['level']);
        
        // Handle photo upload
        $foto = null;
        $update_foto = false;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $file_extension = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
            
            if (in_array($file_extension, $allowed_extensions)) {
                $foto_filename = 'user_' . time() . '_' . basename($_FILES['foto']['name']);
                $target_path = '../assets/img/' . $foto_filename;
                
                if (move_uploaded_file($_FILES['foto']['tmp_name'], $target_path)) {
                    // Delete old photo if exists
                    $current_user = $pdo->prepare("SELECT foto FROM tb_pengguna WHERE id_pengguna = ?");
                    $current_user->execute([$id_pengguna]);
                    $current_foto = $current_user->fetchColumn();
                    
                    if ($current_foto && file_exists('../assets/img/' . $current_foto)) {
                        unlink('../assets/img/' . $current_foto);
                    }
                    
                    $foto = $foto_filename;
                    $update_foto = true;
                } else {
                    $message = ['type' => 'danger', 'text' => 'Gagal mengunggah foto!'];
                }
            } else {
                $message = ['type' => 'danger', 'text' => 'Format foto tidak didukung!'];
            }
        }
        
        if (!$message || $message['type'] !== 'danger') {
            if (!empty($_POST['password'])) {
                // Update with new password
                $password = hashPassword($_POST['password']);
                
                if ($update_foto) {
                    $stmt = $pdo->prepare("UPDATE tb_pengguna SET username=?, password=?, level=?, foto=? WHERE id_pengguna=?");
                    $params = [$username, $password, $level, $foto, $id_pengguna];
                } else {
                    $stmt = $pdo->prepare("UPDATE tb_pengguna SET username=?, password=?, level=? WHERE id_pengguna=?");
                    $params = [$username, $password, $level, $id_pengguna];
                }
            } else {
                // Update without changing password
                if ($update_foto) {
                    $stmt = $pdo->prepare("UPDATE tb_pengguna SET username=?, level=?, foto=? WHERE id_pengguna=?");
                    $params = [$username, $level, $foto, $id_pengguna];
                } else {
                    $stmt = $pdo->prepare("UPDATE tb_pengguna SET username=?, level=? WHERE id_pengguna=?");
                    $params = [$username, $level, $id_pengguna];
                }
            }
            
            if ($stmt->execute($params)) {
                $message = ['type' => 'success', 'text' => 'Data pengguna berhasil diupdate!'];
                $log_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $log_result = logActivity($pdo, $log_username, 'Update Pengguna', "Memperbarui data pengguna: $username (level: $level)");
                if (!$log_result) error_log("Failed to log activity for Update Pengguna: $username");
            } else {
                $message = ['type' => 'danger', 'text' => 'Gagal mengupdate data pengguna!'];
            }
        }
    } elseif (isset($_POST['delete_user'])) {
        $id_pengguna = (int)$_POST['id_pengguna'];
        // Delete user's photo if exists
        $current_user = $pdo->prepare("SELECT foto FROM tb_pengguna WHERE id_pengguna = ?");
        $current_user->execute([$id_pengguna]);
        $current_foto = $current_user->fetchColumn();
        
        if ($current_foto && file_exists('../assets/img/' . $current_foto)) {
            unlink('../assets/img/' . $current_foto);
        }
        
        $stmt = $pdo->prepare("DELETE FROM tb_pengguna WHERE id_pengguna=?");
        if ($stmt->execute([$id_pengguna])) {
            $message = ['type' => 'success', 'text' => 'Data pengguna berhasil dihapus!'];
            $log_username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $log_username, 'Hapus Pengguna', "Menghapus pengguna ID: $id_pengguna");
            if (!$log_result) error_log("Failed to log activity for Hapus Pengguna: $id_pengguna");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus data pengguna!'];
        }
    }
}

// Get all users
$stmt = $pdo->query("
    SELECT p.*
    FROM tb_pengguna p 
    ORDER BY p.username ASC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.datatables.net/1.10.25/css/dataTables.bootstrap4.min.css'
];

// Define JS libraries for this page - only DataTables since others are loaded by default
$js_libs = [
    'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.10.25/js/dataTables.bootstrap4.min.js'
];

// Set page title
echo '<!-- Page Title: Data Pengguna -->';
$page_title = 'Data Pengguna';

// Define page-specific JS
$js_page = [
    "
    \$(document).ready(function() {
        // Reinitialize sidebar dropdown functionality
        if($('.main-sidebar .sidebar-menu li a.has-dropdown').length) {
            $('.main-sidebar .sidebar-menu li a.has-dropdown').off('click').on('click', function() {
                var me     = \$(this);
                var active = false;
                if(me.parent().hasClass('active')){
                    active = true;
                }

                $('.main-sidebar .sidebar-menu li.active > .dropdown-menu').slideUp(500, function() {
                    // update sidebar nicescroll
                    if(\$(\".main-sidebar\").length) {
                        var sidebar_nicescroll_opts = {
                            cursoropacitymin: 0,
                            cursoropacitymax: .8,
                            zindex: 892
                        };
                        \$(\".main-sidebar\").niceScroll(sidebar_nicescroll_opts);
                        var sidebar_nicescroll = \$(\".main-sidebar\").getNiceScroll();
                        sidebar_nicescroll.resize();
                    }
                    return false;
                });

                $('.main-sidebar .sidebar-menu li.active.dropdown').removeClass('active');

                if(active==true) {
                    me.parent().removeClass('active');
                    me.parent().find('> .dropdown-menu').slideUp(500, function() {
                        // update sidebar nicescroll
                        if(\$(\".main-sidebar\").length) {
                            var sidebar_nicescroll_opts = {
                                cursoropacitymin: 0,
                                cursoropacitymax: .8,
                                zindex: 892
                            };
                            \$(\".main-sidebar\").niceScroll(sidebar_nicescroll_opts);
                            var sidebar_nicescroll = \$(\".main-sidebar\").getNiceScroll();
                            sidebar_nicescroll.resize();
                        }
                        return false;
                    });
                }else{
                    me.parent().addClass('active');
                    me.parent().find('> .dropdown-menu').slideDown(500, function() {
                        // update sidebar nicescroll
                        if(\$(\".main-sidebar\").length) {
                            var sidebar_nicescroll_opts = {
                                cursoropacitymin: 0,
                                cursoropacitymax: .8,
                                zindex: 892
                            };
                            \$(\".main-sidebar\").niceScroll(sidebar_nicescroll_opts);
                            var sidebar_nicescroll = \$(\".main-sidebar\").getNiceScroll();
                            sidebar_nicescroll.resize();
                        }
                        return false;
                    });
                }

                return false;
            });
        }
        
        // Initialize DataTable
        \$('#table-1').DataTable({
            \"columnDefs\": [
                { \"sortable\": false, \"targets\": [3] }
            ],
            \"paging\": true,
            \"lengthChange\": true,
            \"pageLength\": 10,
            \"lengthMenu\": [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'Semua']],
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

        // Delete confirmation
        \$(document).on('click', '.delete-btn', function(e) {
            e.preventDefault();
            
            var id = \$(this).data('id');
            var name = \$(this).data('name');
            
            Swal.fire({
                title: 'Konfirmasi Hapus',
                text: 'Apakah Anda yakin ingin menghapus pengguna ' + name + \"?\",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a temporary form and submit it
                    var form = \$('<form method=\"POST\" action=\"\"><input type=\"hidden\" name=\"id_pengguna\" value=\"' + id + '\"><input type=\"hidden\" name=\"delete_user\" value=\"1\"></form>');
                    \$('body').append(form);
                    form.submit();
                }
            });
        });
    });
    "
];

include '../templates/header.php';
include '../templates/sidebar.php';
?>

            <div class="main-content">
                <section class="section">
                    <div class="section-header">
                        <h1>Data Pengguna</h1>
                        <div class="section-header-breadcrumb">
                            <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                            <div class="breadcrumb-item">Data Pengguna</div>
                        </div>
                    </div>

                    <?php if ($message): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                title: '<?php echo $message['type'] == 'success' ? 'Berhasil' : 'Gagal'; ?>',
                                text: '<?php echo $message['text']; ?>',
                                icon: '<?php echo $message['type'] == 'danger' ? 'error' : $message['type']; ?>',
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
                                    <h4>Data Pengguna</h4>
                                    <div class="card-header-action">
                                        <a href="#" class="btn btn-success" data-toggle="modal" data-target="#addModal"><i class="fas fa-plus"></i> Tambah Data</a>
                                        <a href="#" class="btn btn-info"><i class="fas fa-file-excel"></i> Excel</a>
                                        <a href="#" class="btn btn-warning"><i class="fas fa-file-pdf"></i> PDF</a>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped" id="table-1">
                                            <thead>
                                                <tr>
                                                    <th class="text-center">#</th>
                                                    <th>Username</th>
                                                    <th>Level</th>
                                                    <th>Foto</th>
                                                    <th>Aksi</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $no = 1; foreach ($users as $user): ?>
                                                <tr>
                                                    <td class="text-center"><?php echo $no++; ?></td>
                                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = '';
                                                        switch ($user['level']) {
                                                            case 'admin':
                                                                $badge_class = 'badge-primary';
                                                                break;
                                                            case 'kepala_madrasah':
                                                                $badge_class = 'badge-success';
                                                                break;
                                                            default:
                                                                $badge_class = 'badge-secondary';
                                                                break;
                                                        }
                                                        ?>
                                                        <div class="badge <?php echo $badge_class; ?>"><?php echo ucwords(str_replace('_', ' ', $user['level'])); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php echo getUserAvatarImage($user, 40); ?>
                                                    </td>
                                                    <td>
                                                        <a href="#" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#editModal<?php echo $user['id_pengguna']; ?>"><i class="fas fa-edit"></i></a>
                                                        <?php if (strtolower($user['username']) !== 'admin' && $user['id_pengguna'] != 1): ?>
                                                        <a href="#" class="btn btn-danger btn-sm delete-btn" data-id="<?php echo $user['id_pengguna']; ?>" data-name="<?php echo htmlspecialchars($user['username']); ?>" data-action="delete_user"><i class="fas fa-trash"></i></a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Edit Modal -->
                                                <div class="modal fade edit-modal" id="editModal<?php echo $user['id_pengguna']; ?>" tabindex="-1" role="dialog" aria-labelledby="editModalLabel<?php echo $user['id_pengguna']; ?>" aria-hidden="true">
                                                    <div class="modal-dialog" role="document">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title" id="editModalLabel<?php echo $user['id_pengguna']; ?>">Edit Data Pengguna</h5>
                                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                    <span aria-hidden="true">&times;</span>
                                                                </button>
                                                            </div>
                                                            <form method="POST" action="" enctype="multipart/form-data">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="id_pengguna" value="<?php echo $user['id_pengguna']; ?>">
                                                                    <input type="hidden" name="update_user" value="1">
                                                                    <div class="form-group">
                                                                        <label>Username</label>
                                                                        <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label>Password (kosongkan jika tidak ingin diubah)</label>
                                                                        <input type="password" class="form-control" name="password">
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label>Level</label>
                                                                        <select class="form-control" name="level" required>
                                                                            <option value="admin" <?php echo $user['level'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                                            <option value="guru" <?php echo $user['level'] === 'guru' ? 'selected' : ''; ?>>Guru</option>
                                                                            <option value="wali" <?php echo $user['level'] === 'wali' ? 'selected' : ''; ?>>Wali</option>
                                                                            <option value="kepala_madrasah" <?php echo $user['level'] === 'kepala_madrasah' ? 'selected' : ''; ?>>Kepala Madrasah</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label>Foto Saat Ini</label><br>
                                                                        <?php echo getUserAvatarImage($user, 100); ?>
                                                                    </div>
                                                                    <div class="form-group">
                                                                        <label>Upload Foto Baru (opsional)</label>
                                                                        <input type="file" class="form-control" name="foto" accept="image/*">
                                                                        <small class="form-text text-muted">Format: JPG, JPEG, PNG, GIF. Kosongkan jika tidak ingin mengganti foto.</small>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer bg-whitesmoke br">
                                                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                                                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            
            <!-- Add Modal -->
            <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addModalLabel">Tambah Data Pengguna</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="modal-body">
                                <input type="hidden" name="add_user" value="1">
                                <div class="form-group">
                                    <label>Username</label>
                                    <input type="text" class="form-control" name="username" required>
                                </div>
                                <div class="form-group">
                                    <label>Password</label>
                                    <input type="password" class="form-control" name="password" required>
                                </div>
                                <div class="form-group">
                                    <label>Level</label>
                                    <select class="form-control" name="level" required>
                                        <option value="admin">Admin</option>
                                        <option value="guru">Guru</option>
                                        <option value="wali">Wali</option>
                                        <option value="kepala_madrasah">Kepala Madrasah</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Foto (opsional)</label>
                                    <input type="file" class="form-control" name="foto" accept="image/*">
                                    <small class="form-text text-muted">Format: JPG, JPEG, PNG, GIF. Ukuran maksimal disarankan.</small>
                                </div>
                            </div>
                            <div class="modal-footer bg-whitesmoke br">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Tutup</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
<?php
include '../templates/footer.php';
?>