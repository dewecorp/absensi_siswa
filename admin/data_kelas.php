<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started for activity logging
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has admin level
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

// Set page title
$page_title = 'Data Kelas';

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_kelas'])) {
        $nama_kelas = sanitizeInput($_POST['nama_kelas']);
        $wali_kelas = sanitizeInput($_POST['wali_kelas']);
        
        $stmt = $pdo->prepare("INSERT INTO tb_kelas (nama_kelas, wali_kelas) VALUES (?, ?)");
        if ($stmt->execute([$nama_kelas, $wali_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data kelas berhasil ditambahkan!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Tambah Kelas', "Menambahkan data kelas: $nama_kelas");
            if (!$log_result) error_log("Failed to log activity for Tambah Kelas: $nama_kelas");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menambahkan data kelas!'];
        }
    } elseif (isset($_POST['update_kelas'])) {
        $id_kelas = (int)$_POST['id_kelas'];
        $nama_kelas = sanitizeInput($_POST['nama_kelas']);
        $wali_kelas = sanitizeInput($_POST['wali_kelas']);
        
        $stmt = $pdo->prepare("UPDATE tb_kelas SET nama_kelas=?, wali_kelas=? WHERE id_kelas=?");
        if ($stmt->execute([$nama_kelas, $wali_kelas, $id_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data kelas berhasil diupdate!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Update Kelas', "Memperbarui data kelas: $nama_kelas");
            if (!$log_result) error_log("Failed to log activity for Update Kelas: $nama_kelas");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal mengupdate data kelas!'];
        }
    } elseif (isset($_POST['delete_kelas'])) {
        $id_kelas = (int)$_POST['id_kelas'];
        // Get class name before deletion
        $name_stmt = $pdo->prepare("SELECT nama_kelas FROM tb_kelas WHERE id_kelas = ?");
        $name_stmt->execute([$id_kelas]);
        $nama_kelas = $name_stmt->fetchColumn();
        
        $stmt = $pdo->prepare("DELETE FROM tb_kelas WHERE id_kelas=?");
        if ($stmt->execute([$id_kelas])) {
            $message = ['type' => 'success', 'text' => 'Data kelas berhasil dihapus!'];
            $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
            $log_result = logActivity($pdo, $username, 'Hapus Kelas', "Menghapus data kelas: $nama_kelas");
            if (!$log_result) error_log("Failed to log activity for Hapus Kelas: $nama_kelas");
        } else {
            $message = ['type' => 'danger', 'text' => 'Gagal menghapus data kelas!'];
        }
    }
}

// Get all classes with wali info and student count
$query_classes = "
    SELECT k.*, g.foto AS foto_wali, g.nuptk,
           (SELECT COUNT(*) FROM tb_siswa s WHERE s.id_kelas = k.id_kelas) AS total_siswa
    FROM tb_kelas k
    LEFT JOIN tb_guru g ON LOWER(TRIM(g.nama_guru)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(k.wali_kelas)) COLLATE utf8mb4_unicode_ci
    ORDER BY k.nama_kelas ASC
";
$stmt = $pdo->query($query_classes);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all teachers for wali kelas dropdown
$teacherStmt = $pdo->query("SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC");
$teachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

// Define CSS libraries for this page
$css_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css'
];

// Define JS libraries for this page
$js_libs = [
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js'
];

// Define page-specific JS
$js_page = [];

// Add JavaScript for delete confirmation and live search
$js_page[] = "
\$(document).ready(function() {
    // Live search filter kartu kelas
    $('#kelas-search-input').on('input', function() {
        var q = $(this).val().toLowerCase().trim();
        var visible = 0;
        $('#kelas-grid .kelas-app-card').each(function() {
            var text = $(this).text().toLowerCase();
            var match = (q === '' || text.indexOf(q) !== -1);
            $(this).toggle(match);
            if (match) visible++;
        });
        $('#kelas-total-count').text(visible);
    });

    // Initialize delete button handlers
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        
        var id = \$(this).data('id');
        var name = \$(this).data('name');
        
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: 'Apakah Anda yakin ingin menghapus kelas ' + name + '?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                var form = \$('<form method=\\\"POST\\\" action=\\\"\\\">' +
                    '<input type=\\\"hidden\\\" name=\\\"id_kelas\\\" value=\\\"' + id + '\\\">' +
                    '<input type=\\\"hidden\\\" name=\\\"delete_kelas\\\" value=\\\"1\\\">' +
                    '</form>');
                \$('body').append(form);
                form.submit();
            }
        });
    });
});

// Initialize Select2 when modals are shown
$(document).on('shown.bs.modal', function(e) {
    if (typeof $.fn.select2 === 'undefined') { return; }
    var modal = $(e.target);
    setTimeout(function() {
        var \$sel = modal.find('select.select2');
        if (\$sel.length > 0) {
            try {
                if (\$sel.data('select2')) {
                    \$sel.select2('destroy');
                    \$sel.removeData('select2');
                }
            } catch (err) {
                \$sel.removeData('select2');
            }
            \$sel.select2({
                placeholder: 'Pilih Wali Kelas',
                allowClear: true,
                dropdownParent: modal
            });
        }
    }, 100);
});

// Clean up Select2 when modals are hidden
$(document).on('hidden.bs.modal', function(e) {
    if (typeof $.fn.select2 === 'undefined') { return; }
    var \$sel = $(e.target).find('select.select2');
    if (\$sel.length > 0 && \$sel.data('select2')) {
        try {
            \$sel.select2('destroy');
        } catch (err) {}
        \$sel.removeData('select2');
    }
});
";

// Set page title
$page_title = 'Data Kelas';

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<style>
/* Modern Mobile App Style - Data Kelas */
.kelas-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 20px;
}
.kelas-summary-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.kelas-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    color: #1e293b;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .04);
}
.kelas-chip-count {
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    color: #ffffff;
    font-weight: 800;
}
.kelas-chip-count.c-total { background: #4f46e5; }
.kelas-chip-count.c-siswa { background: #0284c7; }

.kelas-search-box {
    position: relative;
    max-width: 320px;
    width: 100%;
}
.kelas-search-box input {
    width: 100%;
    padding: 9px 16px 9px 38px;
    border-radius: 999px;
    border: 1.5px solid #cbd5e1;
    font-size: 13px;
    font-weight: 600;
    color: #0f172a;
    background: #ffffff;
    outline: none;
    transition: all .2s ease;
}
.kelas-search-box input:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, .15);
}
.kelas-search-box i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 13px;
}

/* App Grid & Card */
.kelas-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
@media (max-width: 767.98px) {
    .kelas-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}

.kelas-app-card {
    position: relative;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 20px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 4px 18px rgba(15, 23, 42, .04);
    transition: transform .18s cubic-bezier(0.4, 0, 0.2, 1), box-shadow .18s ease, border-color .18s ease;
}
.kelas-app-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 28px rgba(15, 23, 42, .08);
    border-color: #cbd5e1;
}

.kelas-app-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #4f46e5, #06b6d4);
}

.kelas-card-top {
    padding: 14px 16px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.kelas-badge-name {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    color: #ffffff !important;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: .3px;
    box-shadow: 0 2px 8px rgba(79, 70, 229, .25);
}
.kelas-badge-siswa {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534 !important;
    font-size: 12px;
    font-weight: 800;
}

.kelas-card-main {
    padding: 0 16px 16px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.kelas-wali-avatar-wrap {
    position: relative;
    width: 64px;
    height: 64px;
    flex-shrink: 0;
}
.kelas-wali-img,
.kelas-wali-fallback {
    width: 64px;
    height: 64px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    object-fit: cover;
    border: 2px solid #ffffff;
    box-shadow: 0 4px 14px rgba(15, 23, 42, .12);
}
.kelas-wali-fallback {
    background: linear-gradient(135deg, #0284c7, #06b6d4);
    color: #ffffff;
    font-weight: 800;
    font-size: 20px;
    letter-spacing: .5px;
}
.kelas-wali-badge-icon {
    position: absolute;
    bottom: -3px;
    right: -3px;
    width: 22px;
    height: 22px;
    border-radius: 999px;
    background: #ffffff;
    box-shadow: 0 2px 6px rgba(0,0,0,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: #4f46e5;
}

.kelas-wali-info {
    min-width: 0;
    flex: 1 1 auto;
}
.kelas-wali-role {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 2px;
}
.kelas-wali-name {
    font-size: 14.5px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.35;
    word-break: break-word;
}
.kelas-wali-nuptk {
    font-size: 11.5px;
    font-weight: 600;
    color: #64748b;
    margin-top: 2px;
}

.kelas-card-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 16px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
}
.kelas-index-text {
    font-size: 11.5px;
    font-weight: 800;
    color: #94a3b8;
}

.kelas-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}
.kelas-app-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    transition: all .15s ease;
    text-decoration: none !important;
}
.kelas-app-btn:active { transform: scale(0.96); }

.kelas-btn-edit {
    background: linear-gradient(135deg, #4f46e5, #4338ca);
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(79, 70, 229, .25);
}
.kelas-btn-edit:hover {
    box-shadow: 0 4px 12px rgba(79, 70, 229, .35);
    color: #ffffff !important;
    transform: translateY(-1px);
}

.kelas-btn-del {
    background: #ffffff;
    color: #ef4444 !important;
    border: 1.5px solid #fecaca !important;
}
.kelas-btn-del:hover {
    background: #fef2f2;
    border-color: #f87171 !important;
    color: #dc2626 !important;
    transform: translateY(-1px);
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-chalkboard-teacher mr-2"></i>Data Kelas</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Daftar Rombongan Belajar (Kelas)</h4>
                            <div class="card-header-action">
                                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> Tambah Kelas
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($message): ?>
                            <script>
                                document.addEventListener('DOMContentLoaded', function() {
                                    Swal.fire({
                                        title: '<?php echo $message['type'] === 'success' ? 'Sukses!' : 'Error!'; ?>',
                                        text: '<?php echo addslashes($message['text']); ?>',
                                        icon: '<?php echo $message['type']; ?>',
                                        timer: <?php echo $message['type'] === 'success' ? '3000' : '5000'; ?>,
                                        timerProgressBar: true,
                                        showConfirmButton: false
                                    });
                                });
                            </script>
                            <?php endif; ?>

                            <?php 
                                $total_all_siswa = 0;
                                foreach ($classes as $c) {
                                    $total_all_siswa += (int)($c['total_siswa'] ?? 0);
                                }
                            ?>

                            <div class="kelas-toolbar">
                                <div class="kelas-summary-chips">
                                    <span class="kelas-chip">
                                        Total Kelas <span class="kelas-chip-count c-total" id="kelas-total-count"><?= count($classes) ?></span>
                                    </span>
                                    <span class="kelas-chip">
                                        Total Siswa <span class="kelas-chip-count c-siswa"><?= $total_all_siswa ?></span>
                                    </span>
                                </div>

                                <div class="kelas-search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="search" id="kelas-search-input" placeholder="Cari nama kelas atau wali..." autocomplete="off">
                                </div>
                            </div>

                            <?php if (empty($classes)): ?>
                                <div class="text-center py-5" style="color: #64748b;">
                                    <i class="fas fa-school fa-3x mb-3 text-muted"></i>
                                    <p class="mb-0 font-weight-bold">Belum ada data kelas yang terdaftar.</p>
                                </div>
                            <?php else: ?>
                                <div class="kelas-grid" id="kelas-grid">
                                    <?php foreach ($classes as $index => $class): 
                                        $wali_name = !empty($class['wali_kelas']) ? $class['wali_kelas'] : 'Belum Ditentukan';
                                        
                                        $initials = '';
                                        $words = preg_split('/\s+/', trim((string)$wali_name));
                                        foreach (array_slice($words, 0, 2) as $w) {
                                            if ($w !== '') $initials .= strtoupper(substr($w, 0, 1));
                                        }
                                        if ($initials === '') $initials = 'G';

                                        $fallbackHtml = '<div class="kelas-wali-fallback">' . htmlspecialchars($initials) . '</div>';
                                        $avatarHtml = getPersonAvatarHtml($class['foto_wali'] ?? '', $fallbackHtml, 'kelas-wali-img');
                                    ?>
                                    <div class="kelas-app-card">
                                        <div>
                                            <div class="kelas-card-top">
                                                <span class="kelas-badge-name">
                                                    <i class="fas fa-chalkboard"></i> Kelas <?= htmlspecialchars($class['nama_kelas']) ?>
                                                </span>
                                                <span class="kelas-badge-siswa">
                                                    <i class="fas fa-user-graduate"></i> <?= (int)$class['total_siswa'] ?> Siswa
                                                </span>
                                            </div>
                                            <div class="kelas-card-main">
                                                <div class="kelas-wali-avatar-wrap" title="<?= htmlspecialchars($wali_name) ?>">
                                                    <?= $avatarHtml ?>
                                                    <div class="kelas-wali-badge-icon" title="Wali Kelas">
                                                        <i class="fas fa-chalkboard-teacher"></i>
                                                    </div>
                                                </div>
                                                <div class="kelas-wali-info">
                                                    <div class="kelas-wali-role">Wali Kelas</div>
                                                    <div class="kelas-wali-name"><?= htmlspecialchars($wali_name) ?></div>
                                                    <?php if (!empty($class['nuptk'])): ?>
                                                        <div class="kelas-wali-nuptk"><i class="far fa-id-badge text-primary mr-1"></i>NUPTK: <?= htmlspecialchars($class['nuptk']) ?></div>
                                                    <?php else: ?>
                                                        <div class="kelas-wali-nuptk text-muted"><i class="fas fa-user-tie text-muted mr-1"></i>Pendidik</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="kelas-card-bottom">
                                            <span class="kelas-index-text">#<?= $index + 1 ?> Rombel</span>
                                            <div class="kelas-actions">
                                                <a href="#" class="kelas-app-btn kelas-btn-edit" data-toggle="modal" data-target="#editModal<?php echo $class['id_kelas']; ?>" title="Edit Kelas">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="#" class="kelas-app-btn kelas-btn-del delete-btn" data-id="<?php echo $class['id_kelas']; ?>" data-name="<?php echo htmlspecialchars($class['nama_kelas']); ?>" data-action="delete_kelas" title="Hapus Kelas">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </a>
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
        </div>
    </section>
</div>

<?php foreach ($classes as $index => $class): ?>
<!-- Edit Modal -->
<div class="modal fade edit-modal" id="editModal<?php echo $class['id_kelas']; ?>" tabindex="-1" role="dialog" aria-labelledby="editModalLabel<?php echo $class['id_kelas']; ?>" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editModalLabel<?php echo $class['id_kelas']; ?>">Edit Data Kelas</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="id_kelas" value="<?php echo $class['id_kelas']; ?>">
                    <input type="hidden" name="update_kelas" value="1">
                    <div class="form-group">
                        <label>Nama Kelas</label>
                        <input type="text" class="form-control" name="nama_kelas" value="<?php echo htmlspecialchars($class['nama_kelas']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Wali Kelas</label>
                        <select class="form-control select2" name="wali_kelas">
                            <option value="">Pilih Wali Kelas</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo htmlspecialchars($teacher['nama_guru']); ?>" <?php echo ($teacher['nama_guru'] == $class['wali_kelas']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-labelledby="addModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addModalLabel">Tambah Data Kelas</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="add_kelas" value="1">
                    <div class="form-group">
                        <label>Nama Kelas</label>
                        <input type="text" class="form-control" name="nama_kelas" required>
                    </div>
                    <div class="form-group">
                        <label>Wali Kelas</label>
                        <select class="form-control select2" name="wali_kelas">
                            <option value="">Pilih Wali Kelas</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo htmlspecialchars($teacher['nama_guru']); ?>">
                                <?php echo htmlspecialchars($teacher['nama_guru']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
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
