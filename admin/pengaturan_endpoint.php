<?php
error_reporting(E_ALL & ~E_DEPRECATED);

require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/endpoint_registry.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'Pengaturan Endpoint';
$message = null;

endpoint_registry_schema($pdo);
endpoint_seed_all($pdo);

$api_key = endpoint_api_key($pdo);

// --- Simpan API key global SIMAD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_api_key'])) {
    $new_key = trim((string)($_POST['api_key'] ?? ''));
    if ($new_key === '') {
        $message = ['type' => 'danger', 'text' => 'API key tidak boleh kosong.'];
    } else {
        try {
            $pdo->prepare("UPDATE tb_pengaturan_api SET api_key = ?, updated_at = NOW() WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM tb_pengaturan_api) t)")->execute([$new_key]);
            $api_key = $new_key;
            $message = ['type' => 'success', 'text' => 'API key berhasil disimpan. URL endpoint di bawah otomatis memakai key baru.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menyimpan API key: ' . $e->getMessage()];
        }
    }
}

// --- Tambah endpoint keluar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_keluar'])) {
    $nama = trim((string)($_POST['nama'] ?? ''));
    $path = trim((string)($_POST['path'] ?? ''));
    $metode = strtoupper(trim((string)($_POST['metode'] ?? 'GET')));
    $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));
    if ($nama === '' || $path === '') {
        $message = ['type' => 'warning', 'text' => 'Nama dan path endpoint wajib diisi.'];
    } else {
        if (!in_array($metode, ['GET', 'POST', 'PUT', 'DELETE'], true)) $metode = 'GET';
        try {
            $pdo->prepare("INSERT INTO tb_endpoint_keluar (nama, path, metode, deskripsi, aktif, updated_at) VALUES (?, ?, ?, ?, 1, NOW())")->execute([$nama, $path, $metode, $deskripsi]);
            $message = ['type' => 'success', 'text' => 'Endpoint keluar berhasil ditambahkan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menambah endpoint: ' . $e->getMessage()];
        }
    }
}

// --- Hapus endpoint keluar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_keluar'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM tb_endpoint_keluar WHERE id = ?")->execute([$id]);
        $message = ['type' => 'success', 'text' => 'Endpoint keluar dihapus.'];
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
    }
}

// --- Toggle aktif endpoint keluar ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_keluar'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $pdo->prepare("UPDATE tb_endpoint_keluar SET aktif = 1 - aktif, updated_at = NOW() WHERE id = ?")->execute([$id]);
        $message = ['type' => 'success', 'text' => 'Status endpoint diperbarui.'];
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal mengubah status: ' . $e->getMessage()];
    }
}

// --- Simpan endpoint masuk (sigaji/sibayar/etab/dll) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_masuk'])) {
    $rows = $_POST['masuk'] ?? [];
    try {
        foreach ((array)$rows as $id => $r) {
            $id = (int)$id;
            $base = trim((string)($r['base_url'] ?? ''));
            $key = trim((string)($r['api_key'] ?? ''));
            $aktif = isset($r['aktif']) ? 1 : 0;
            $pdo->prepare("UPDATE tb_endpoint_masuk SET base_url = ?, api_key = ?, aktif = ?, updated_at = NOW() WHERE id = ?")->execute([$base, $key !== '' ? $key : null, $aktif, $id]);
        }
        $message = ['type' => 'success', 'text' => 'Endpoint masuk berhasil disimpan.'];
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal menyimpan endpoint masuk: ' . $e->getMessage()];
    }
}

// --- Tes koneksi endpoint masuk ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_masuk'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $row = $pdo->prepare("SELECT base_url, api_key FROM tb_endpoint_masuk WHERE id = ?");
        $row->execute([$id]);
        $ep = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $base = (string)($ep['base_url'] ?? '');
        $key = (string)($ep['api_key'] ?? '');
        // Ganti placeholder {nis} dengan NISN sample agar endpoint berparameter bisa dites.
        if (stripos($base, '{nis}') !== false) {
            $sample = '';
            try {
                $sample = (string)$pdo->query("SELECT nisn FROM tb_siswa WHERE nisn IS NOT NULL AND TRIM(nisn) <> '' ORDER BY id_siswa ASC LIMIT 1")->fetchColumn();
            } catch (Exception $e) { /* ignore */ }
            if ($sample === '') $sample = '12345';
            $base = str_ireplace('{nis}', $sample, $base);
        }
        [$ok, $code, $note] = endpoint_test_url($base, $key);
        $pdo->prepare("UPDATE tb_endpoint_masuk SET last_test_at = NOW(), last_test_status = ?, last_test_note = ?, updated_at = NOW() WHERE id = ?")->execute([$ok ? 'OK' : 'GAGAL', $note, $id]);
        if (!$ok && $code === 401 && $key === '') {
            $note .= ' Isi API key lalu Simpan Semua sebelum Tes.';
        } elseif (!$ok && $code === 401) {
            $note .= ' Cek API key tersimpan sudah sama dengan key di aplikasi tujuan.';
        }
        $message = ['type' => $ok ? 'success' : 'danger', 'text' => $ok ? ("Koneksi OK (HTTP $code).") : ("Koneksi gagal: $note")];
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Tes gagal: ' . $e->getMessage()];
    }
}

// --- Hapus endpoint masuk ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_masuk'])) {
    $id = (int)($_POST['id'] ?? 0);
    try {
        $pdo->prepare("DELETE FROM tb_endpoint_masuk WHERE id = ?")->execute([$id]);
        $message = ['type' => 'success', 'text' => 'Endpoint masuk dihapus.'];
    } catch (Exception $e) {
        $message = ['type' => 'danger', 'text' => 'Gagal menghapus: ' . $e->getMessage()];
    }
}

// --- Edit endpoint masuk (nama + deskripsi + base_url + key) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_masuk'])) {
    $id = (int)($_POST['id'] ?? 0);
    $nama = strtolower(trim((string)($_POST['nama_aplikasi'] ?? '')));
    $base = trim((string)($_POST['base_url'] ?? ''));
    $key = trim((string)($_POST['api_key'] ?? ''));
    $deskripsi = trim((string)($_POST['deskripsi'] ?? ''));
    if ($id <= 0 || $nama === '') {
        $message = ['type' => 'warning', 'text' => 'Nama aplikasi wajib diisi.'];
    } else {
        try {
            $pdo->prepare("UPDATE tb_endpoint_masuk SET nama_aplikasi = ?, base_url = ?, api_key = ?, deskripsi = ?, updated_at = NOW() WHERE id = ?")->execute([$nama, $base, $key !== '' ? $key : null, $deskripsi, $id]);
            $message = ['type' => 'success', 'text' => 'Endpoint masuk diperbarui.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal mengedit: ' . $e->getMessage()];
        }
    }
}

// --- Tambah endpoint masuk custom ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_masuk'])) {
    $nama = strtolower(trim((string)($_POST['nama_aplikasi'] ?? '')));
    if ($nama === '') {
        $message = ['type' => 'warning', 'text' => 'Nama aplikasi wajib diisi.'];
    } else {
        try {
            $pdo->prepare("INSERT INTO tb_endpoint_masuk (nama_aplikasi, base_url, deskripsi, aktif, updated_at) VALUES (?, '', '', 1, NOW())")->execute([$nama]);
            $message = ['type' => 'success', 'text' => 'Aplikasi endpoint masuk ditambahkan.'];
        } catch (Exception $e) {
            $message = ['type' => 'danger', 'text' => 'Gagal menambah: ' . $e->getMessage()];
        }
    }
}

$keluar_rows = [];
$masuk_rows = [];
try {
    $keluar_rows = $pdo->query("SELECT * FROM tb_endpoint_keluar ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }
try {
    $masuk_rows = $pdo->query("SELECT * FROM tb_endpoint_masuk ORDER BY nama_aplikasi ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }

$js_page = [];
if ($message) {
    $swal_icon = $message['type'] === 'success' ? 'success' : ($message['type'] === 'warning' ? 'warning' : 'error');
    $js_page[] = "Swal.fire({icon:'{$swal_icon}',title:'" . ($message['type'] === 'success' ? 'Berhasil!' : 'Perhatian!') . "',text:" . json_encode($message['text']) . ",timer:2200,showConfirmButton:false});";
}
$js_page[] = <<<'JS'
function confirmDeleteEndpoint(form, text) {
    Swal.fire({
        title: 'Hapus endpoint?',
        text: text || 'Data yang dihapus tidak bisa dikembalikan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, hapus!',
        cancelButtonText: 'Batal'
    }).then(function (res) {
        if (res.isConfirmed) form.submit();
    });
}
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.classList && f.classList.contains('form-delete-endpoint')) {
        e.preventDefault();
        confirmDeleteEndpoint(f, f.getAttribute('data-text'));
    }
});
document.addEventListener('click', function (e) {
    var eb = e.target.closest ? e.target.closest('.btn-edit-masuk') : null;
    if (eb) {
        document.getElementById('editMasukId').value = eb.getAttribute('data-id') || '';
        document.getElementById('editMasukNama').value = eb.getAttribute('data-nama') || '';
        document.getElementById('editMasukBase').value = eb.getAttribute('data-base') || '';
        document.getElementById('editMasukKey').value = eb.getAttribute('data-key') || '';
        document.getElementById('editMasukDeskripsi').value = eb.getAttribute('data-deskripsi') || '';
        $('#editMasukModal').modal('show');
        return;
    }
    var b = e.target.closest ? e.target.closest('.btn-delete-masuk') : null;
    if (!b) return;
    e.preventDefault();
    var id = b.getAttribute('data-id');
    var text = b.getAttribute('data-text') || 'Hapus endpoint masuk ini?';
    Swal.fire({
        title: 'Hapus endpoint?',
        text: text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, hapus!',
        cancelButtonText: 'Batal'
    }).then(function (res) {
        if (!res.isConfirmed) return;
        var hf = document.getElementById('formDeleteMasuk');
        if (!hf) return;
        var hid = hf.querySelector('input[name="id"]');
        if (!hid) {
            hid = document.createElement('input');
            hid.type = 'hidden';
            hid.name = 'id';
            hf.appendChild(hid);
        }
        hid.value = id;
        hf.submit();
    });
});
function copyTextToClipboard(text) {
    if (!text) return;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            if (typeof toastr !== 'undefined') {
                toastr.success('URL Endpoint berhasil disalin!', 'Berhasil');
            } else {
                Swal.fire({icon:'success',title:'Disalin!',timer:1200,showConfirmButton:false});
            }
        }).catch(function() {
            fallbackCopyText(text);
        });
    } else {
        fallbackCopyText(text);
    }
}

function fallbackCopyText(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-999999px';
    ta.style.top = '-999999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        if (typeof toastr !== 'undefined') {
            toastr.success('URL Endpoint berhasil disalin!', 'Berhasil');
        } else {
            Swal.fire({icon:'success',title:'Disalin!',timer:1200,showConfirmButton:false});
        }
    } catch (e) {
        Swal.fire({icon:'error',title:'Gagal menyalin',timer:1500,showConfirmButton:false});
    }
    document.body.removeChild(ta);
}

function copyEndpoint(btn) {
    var target = document.getElementById(btn.getAttribute('data-target'));
    if (!target) return;
    copyTextToClipboard(target.value);
}
JS;

include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Pengaturan Endpoint</h1>
            <?php echo render_breadcrumb(); ?>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-header"><h4>API Key SIMAD (untuk semua endpoint keluar)</h4></div>
                <div class="card-body">
                    <form method="POST" class="form-inline">
                        <input type="hidden" name="save_api_key" value="1">
                        <input type="text" name="api_key" class="form-control mr-2" style="min-width:320px;" value="<?= htmlspecialchars($api_key) ?>" placeholder="API key" autocomplete="off">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Key</button>
                        <small class="text-muted ml-3">Ganti domain/hosting? URL di bawah otomatis ikut — cukup salin ulang, tanpa bongkar backend.</small>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Endpoint Keluar — dari SIMAD ke web lain</h4>
                    <div class="card-header-action">
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addKeluarModal"><i class="fas fa-plus"></i> Tambah</button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted">Salin URL lalu tempel di web lain (sigaji/sibayar/etab). Base URL terdeteksi otomatis: <code><?= htmlspecialchars(endpoint_base_url()) ?></code></p>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>No</th><th>Nama</th><th>Metode</th><th>URL Siap Salin</th><th>Status</th><th width="15%">Aksi</th></tr></thead>
                            <tbody>
                            <?php if (empty($keluar_rows)): ?>
                                <tr><td colspan="6" class="text-center text-muted">Belum ada endpoint.</td></tr>
                            <?php else: ?>
                                <?php foreach ($keluar_rows as $i => $r): ?>
                                <?php $full = endpoint_full_url((string)$r['path'], ((int)($r['aktif'] ?? 1) === 1) ? $api_key : ''); ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($r['nama']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($r['deskripsi'] ?? '') ?></small></td>
                                    <td><span class="badge badge-info"><?= htmlspecialchars($r['metode']) ?></span></td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" class="form-control form-control-sm" id="ep<?= (int)$r['id'] ?>" value="<?= htmlspecialchars($full) ?>" readonly style="cursor:pointer;" title="Klik untuk salin URL otomatis" onclick="copyTextToClipboard(this.value)">
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-sm btn-secondary" data-target="ep<?= (int)$r['id'] ?>" onclick="copyEndpoint(this)" title="Salin URL"><i class="fas fa-copy"></i></button>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= ((int)($r['aktif'] ?? 1) === 1) ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-secondary">Nonaktif</span>' ?></td>
                                    <td>
                                        <div class="d-flex" style="gap:4px;">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="toggle_keluar" value="1">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-warning" title="Aktif/Nonaktif"><i class="fas fa-power-off"></i></button>
                                        </form>
                                        <form method="POST" class="d-inline form-delete-endpoint" data-text="Hapus endpoint keluar '<?= htmlspecialchars($r['nama'] ?? '', ENT_QUOTES) ?>'?">
                                            <input type="hidden" name="delete_keluar" value="1">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                        </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Endpoint Masuk — dari web lain ke SIMAD</h4>
                    <div class="card-header-action">
                        <button class="btn btn-primary" data-toggle="modal" data-target="#addMasukModal"><i class="fas fa-plus"></i> Tambah Aplikasi</button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted">Isi base URL + API key tiap aplikasi (sigaji, sibayar, etab). SIMAD memakai ini saat menarik data — ganti domain cukup edit di sini.</p>
                    <form method="POST" id="formSimpanMasuk">
                        <input type="hidden" name="save_masuk" value="1">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Aplikasi</th><th>Base URL</th><th>API Key</th><th>Aktif</th><th>Tes Terakhir</th><th width="12%">Aksi</th></tr></thead>
                                <tbody>
                                <?php foreach ($masuk_rows as $r): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['nama_aplikasi']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($r['deskripsi'] ?? '') ?></small></td>
                                    <td><input type="text" name="masuk[<?= (int)$r['id'] ?>][base_url]" class="form-control form-control-sm" style="min-width:220px;" value="<?= htmlspecialchars($r['base_url'] ?? '') ?>" placeholder="https://sigaji.example.com"></td>
                                    <td><input type="text" name="masuk[<?= (int)$r['id'] ?>][api_key]" class="form-control form-control-sm" style="min-width:160px;" value="<?= htmlspecialchars($r['api_key'] ?? '') ?>" placeholder="API key" autocomplete="off"></td>
                                    <td class="text-center"><input type="checkbox" name="masuk[<?= (int)$r['id'] ?>][aktif]" value="1" <?= ((int)($r['aktif'] ?? 1) === 1) ? 'checked' : '' ?>></td>
                                    <td>
                                        <?php if (!empty($r['last_test_at'])): ?>
                                            <?= ((string)($r['last_test_status'] ?? '') === 'OK') ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">GAGAL</span>' ?>
                                            <small class="text-muted d-block"><?= htmlspecialchars($r['last_test_at']) ?><br><?= htmlspecialchars($r['last_test_note'] ?? '') ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Belum dites</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex" style="gap:4px;">
                                            <button type="submit" form="formTestMasuk" name="id" value="<?= (int)$r['id'] ?>" class="btn btn-sm btn-info d-inline-flex align-items-center" style="gap:4px;line-height:1;" title="Tes koneksi"><i class="fas fa-plug"></i><span>Tes</span></button>
                                            <button type="button" class="btn btn-sm btn-warning btn-edit-masuk" title="Edit"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-nama="<?= htmlspecialchars($r['nama_aplikasi'] ?? '', ENT_QUOTES) ?>"
                                                data-base="<?= htmlspecialchars($r['base_url'] ?? '', ENT_QUOTES) ?>"
                                                data-key="<?= htmlspecialchars($r['api_key'] ?? '', ENT_QUOTES) ?>"
                                                data-deskripsi="<?= htmlspecialchars($r['deskripsi'] ?? '', ENT_QUOTES) ?>"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="btn btn-sm btn-danger btn-delete-masuk" data-id="<?= (int)$r['id'] ?>" data-text="Hapus endpoint masuk '<?= htmlspecialchars($r['nama_aplikasi'] ?? '', ENT_QUOTES) ?>'?" title="Hapus"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" form="formSimpanMasuk" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Semua</button>
                    </form>
                    <form method="POST" id="formTestMasuk" class="d-none"><input type="hidden" name="test_masuk" value="1"></form>
                    <form method="POST" id="formDeleteMasuk" class="d-none"><input type="hidden" name="delete_masuk" value="1"></form>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade" id="addKeluarModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5 class="modal-title">Tambah Endpoint Keluar</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_keluar" value="1">
                    <div class="form-group"><label>Nama</label><input type="text" name="nama" class="form-control" required placeholder="cth: Data Nilai"></div>
                    <div class="form-group"><label>Path (relatif dari root SIMAD)</label><input type="text" name="path" class="form-control" required placeholder="cth: api/v1/nilai.php"></div>
                    <div class="form-group"><label>Metode</label>
                        <select name="metode" class="form-control"><option>GET</option><option>POST</option><option>PUT</option><option>DELETE</option></select>
                    </div>
                    <div class="form-group"><label>Deskripsi</label><textarea name="deskripsi" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addMasukModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5 class="modal-title">Tambah Aplikasi (Endpoint Masuk)</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_masuk" value="1">
                    <div class="form-group"><label>Nama aplikasi</label><input type="text" name="nama_aplikasi" class="form-control" required placeholder="cth: sirapor"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editMasukModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5 class="modal-title">Edit Endpoint Masuk</h5>
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_masuk" value="1">
                    <input type="hidden" name="id" id="editMasukId" value="">
                    <div class="form-group"><label>Nama aplikasi</label><input type="text" name="nama_aplikasi" id="editMasukNama" class="form-control" required></div>
                    <div class="form-group"><label>Base URL</label><input type="text" name="base_url" id="editMasukBase" class="form-control" placeholder="https://aplikasi.example.com/api/..."></div>
                    <div class="form-group"><label>API Key</label><input type="text" name="api_key" id="editMasukKey" class="form-control" autocomplete="off"></div>
                    <div class="form-group mb-0"><label>Deskripsi</label><textarea name="deskripsi" id="editMasukDeskripsi" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
