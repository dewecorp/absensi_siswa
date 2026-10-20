<?php
require_once '../config/database.php';
require_once '../config/functions.php';
require_once __DIR__ . '/_summary.php';
require_once __DIR__ . '/_gaji.php';

if (!isAuthorized(['bendahara', 'admin'])) {
    redirect('../login.php');
}

$page_title = 'Honor & Insentif';
$school_profile = getSchoolProfile($pdo);
$display_name = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Bendahara';

/* tabel lokal */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_honor_insentif (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_guru INT NULL,
        nama_guru VARCHAR(150) NOT NULL,
        jenis VARCHAR(20) NOT NULL DEFAULT 'honor',
        kategori VARCHAR(120) NULL,
        periode CHAR(7) NOT NULL,
        nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'belum',
        keterangan TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_periode (periode),
        KEY idx_jenis (jenis)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // biarkan, error ditampilkan lewat pesan kosong
}

$message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$daftar_guru = [];
try {
    foreach ($pdo->query("SELECT id_guru, nama_guru FROM tb_guru ORDER BY nama_guru ASC") as $g) {
        $daftar_guru[] = ['id_guru' => (int)$g['id_guru'], 'nama_guru' => (string)$g['nama_guru']];
    }
} catch (Throwable $e) {
    // abaikan
}

/* aksi */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'honor_insentif.php';
    $aksi = (string)($_POST['aksi'] ?? '');

    $nama = trim((string)($_POST['nama_guru'] ?? ''));
    $jenis = strtolower(trim((string)($_POST['jenis'] ?? 'honor')));
    if (!in_array($jenis, ['honor', 'insentif'], true)) $jenis = 'honor';
    $kategori = trim((string)($_POST['kategori'] ?? ''));
    $periode = trim((string)($_POST['periode'] ?? ''));
    $nominal = (float)str_replace(',', '.', (string)($_POST['nominal'] ?? '0'));
    $status = (string)($_POST['status'] ?? 'belum');
    if (!in_array($status, ['belum', 'sudah'], true)) $status = 'belum';
    $keterangan = trim((string)($_POST['keterangan'] ?? ''));

    $id_guru = null;
    foreach ($daftar_guru as $g) {
        if (strcasecmp($g['nama_guru'], $nama) === 0) {
            $id_guru = $g['id_guru'];
            break;
        }
    }

    $valid = ($nama !== '' && preg_match('/^\d{4}-\d{2}$/', $periode) && $nominal > 0);

    if ($aksi === 'tambah') {
        if (!$valid) {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Lengkapi nama, periode, dan nominal (lebih dari 0).'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO tb_honor_insentif (id_guru, nama_guru, jenis, kategori, periode, nominal, status, keterangan, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $ok = $stmt->execute([$id_guru, $nama, $jenis, $kategori !== '' ? $kategori : null, $periode, $nominal, $status, $keterangan !== '' ? $keterangan : null]);
            if ($ok) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Data ' . $jenis . ' berhasil ditambahkan.'];
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Tambah Honor/Insentif', $nama . ' • ' . $periode . ' • ' . bendahara_rupiah($nominal));
            } else {
                $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Gagal menyimpan data.'];
            }
        }
    } elseif ($aksi === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0 || !$valid) {
            $_SESSION['flash_message'] = ['type' => 'danger', 'text' => 'Data tidak valid.'];
        } else {
            $stmt = $pdo->prepare("UPDATE tb_honor_insentif SET id_guru = ?, nama_guru = ?, jenis = ?, kategori = ?, periode = ?, nominal = ?, status = ?, keterangan = ?, updated_at = NOW() WHERE id = ?");
            $ok = $stmt->execute([$id_guru, $nama, $jenis, $kategori !== '' ? $kategori : null, $periode, $nominal, $status, $keterangan !== '' ? $keterangan : null, $id]);
            $_SESSION['flash_message'] = $ok
                ? ['type' => 'success', 'text' => 'Data berhasil diperbarui.']
                : ['type' => 'danger', 'text' => 'Gagal memperbarui data.'];
            if ($ok) {
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Update Honor/Insentif', $nama . ' • ' . $periode . ' • ' . bendahara_rupiah($nominal));
            }
        }
    } elseif ($aksi === 'hapus') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM tb_honor_insentif WHERE id = ?");
            $ok = $stmt->execute([$id]);
            $_SESSION['flash_message'] = $ok
                ? ['type' => 'success', 'text' => 'Data dihapus.']
                : ['type' => 'danger', 'text' => 'Gagal menghapus data.'];
            if ($ok) {
                logActivity($pdo, $_SESSION['username'] ?? 'system', 'Hapus Honor/Insentif', 'ID ' . $id);
            }
        }
    } elseif ($aksi === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE tb_honor_insentif SET status = IF(status = 'sudah', 'belum', 'sudah'), updated_at = NOW() WHERE id = ?");
            $ok = $stmt->execute([$id]);
            $_SESSION['flash_message'] = $ok
                ? ['type' => 'success', 'text' => 'Status pembayaran diperbarui.']
                : ['type' => 'danger', 'text' => 'Gagal mengubah status.'];
        }
    }

    header('Location: ' . $back);
    exit;
}

/* filter */
$q = trim((string)($_GET['q'] ?? ''));
$filter_jenis = trim((string)($_GET['jenis'] ?? ''));
if (!in_array($filter_jenis, ['', 'honor', 'insentif'], true)) $filter_jenis = '';
$filter_periode = trim((string)($_GET['periode'] ?? ''));
if ($filter_periode !== '' && !preg_match('/^\d{4}-\d{2}$/', $filter_periode)) $filter_periode = '';
$filter_status = trim((string)($_GET['status'] ?? ''));
if (!in_array($filter_status, ['', 'belum', 'sudah'], true)) $filter_status = '';

$rows = [];
$periode_options = [];
try {
    foreach ($pdo->query("SELECT * FROM tb_honor_insentif ORDER BY periode DESC, nama_guru ASC") as $r) {
        $periode_options[(string)$r['periode']] = true;
        $nama = (string)($r['nama_guru'] ?? '');
        if ($q !== '' && stripos($nama, $q) === false && stripos((string)($r['kategori'] ?? ''), $q) === false) continue;
        if ($filter_jenis !== '' && (string)$r['jenis'] !== $filter_jenis) continue;
        if ($filter_periode !== '' && (string)$r['periode'] !== $filter_periode) continue;
        if ($filter_status !== '' && (string)$r['status'] !== $filter_status) continue;
        $rows[] = $r;
    }
} catch (Throwable $e) {
    $rows = [];
}
$periode_options = array_keys($periode_options);
rsort($periode_options);

$sum_honor = 0;
$sum_insentif = 0;
$sum_sudah = 0;
$sum_belum = 0;
foreach ($rows as $r) {
    $nom = (float)($r['nominal'] ?? 0);
    if ((string)$r['jenis'] === 'insentif') $sum_insentif += $nom; else $sum_honor += $nom;
    if ((string)$r['status'] === 'sudah') $sum_sudah += $nom; else $sum_belum += $nom;
}

$per_page = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_rows = count($rows);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages);
$paged = array_slice($rows, ($page - 1) * $per_page, $per_page);

$filter_aktif = ($q !== '' || $filter_jenis !== '' || $filter_periode !== '' || $filter_status !== '');
$bulan_ini = date('Y-m');

include '../templates/header.php';
include_once '../templates/sidebar.php';
?>
<style>
.bdh-chip-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
.bdh-chip {
    display: flex; align-items: center; gap: 8px;
    background: #fff; border: 1.5px solid rgba(15, 23, 42, .07);
    border-radius: 12px; padding: 8px 10px;
}
.bdh-chip .ico { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; font-size: .74rem; }
.bdh-chip .lbl { font-size: .62rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }
.bdh-chip .val { font-size: .82rem; font-weight: 900; color: #0f172a; line-height: 1.25; }

.bdh-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; }
.bdh-search { flex: 1 1 220px; display: flex; align-items: center; gap: 8px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 9px 13px; box-shadow: 0 2px 8px rgba(15,23,42,.04); }
.bdh-search i { color: #94a3b8; }
.bdh-search input { border: 0; outline: 0; width: 100%; font-size: .88rem; color: #0f172a; background: transparent; }
.bdh-select { height: 42px; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 0 12px; font-size: .84rem; font-weight: 700; color: #334155; background: #fff; box-shadow: 0 2px 8px rgba(15,23,42,.04); }

.bdh-table { background: #fff; border-radius: 14px; overflow: hidden; border: 1.5px solid rgba(15,23,42,.07); }
.bdh-table table { margin: 0; }
.bdh-table th { font-size: .66rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 800; border-top: 0; white-space: nowrap; }
.bdh-table td { font-size: .8rem; vertical-align: middle; }
.bdh-table td.num { text-align: right; font-weight: 800; white-space: nowrap; }

.bdh-badge { display: inline-flex; align-items: center; gap: 4px; font-size: .62rem; font-weight: 900; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
.bdh-badge.honor { background: #eff6ff; color: #2563eb; }
.bdh-badge.insentif { background: #ecfeff; color: #0d9488; }
.bdh-badge.ok { background: #dcfce7; color: #15803d; }
.bdh-badge.no { background: #ffe4e6; color: #be123c; }

.bdh-honor-card {
    background: #fff; border: 1.5px solid rgba(15,23,42,.07);
    border-radius: 13px; padding: 11px 13px; margin-bottom: 8px;
}
.bdh-honor-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.bdh-honor-nama { font-size: .84rem; font-weight: 800; color: #0f172a; line-height: 1.3; }
.bdh-honor-meta { font-size: .68rem; color: #94a3b8; font-weight: 600; }
.bdh-honor-amt { font-size: .9rem; font-weight: 900; color: #0f172a; white-space: nowrap; }
.bdh-honor-actions { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }

.bdh-empty { text-align: center; padding: 40px 10px; }
.bdh-pager { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
.bdh-pager a, .bdh-pager span { min-width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 800; text-decoration: none !important; border: 1.5px solid #e2e8f0; background: #fff; color: #334155; }
.bdh-pager a:hover { border-color: #bfdbfe; color: #2563eb; }
.bdh-pager .is-active { background: #2563eb; border-color: #2563eb; color: #fff; }
.bdh-pager .is-disabled { opacity: .45; pointer-events: none; }

@media (max-width: 575.98px) {
    .bdh-chip-row { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 6px; }
    .bdh-chip { padding: 7px 8px; gap: 6px; }
    .bdh-chip .ico { width: 26px; height: 26px; border-radius: 8px; font-size: .66rem; }
    .bdh-chip .lbl { font-size: .56rem; }
    .bdh-chip .val { font-size: .72rem; }
    .bdh-select { flex: 1 1 45%; }
}
</style>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>Honor &amp; Insentif</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item active">Honor &amp; Insentif</div>
            </div>
        </div>

        <div class="section-body">
            <?php if ($message): ?>
                <div class="alert alert-<?= htmlspecialchars((string)($message['type'] ?? 'info')) ?>" style="border-radius:12px;">
                    <?= htmlspecialchars((string)($message['text'] ?? '')) ?>
                </div>
            <?php endif; ?>

            <div class="bdh-chip-row">
                <div class="bdh-chip">
                    <span class="ico" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-hand-holding-usd"></i></span>
                    <span>
                        <span class="lbl d-block">Honor</span>
                        <span class="val"><?= bendahara_rupiah($sum_honor) ?></span>
                    </span>
                </div>
                <div class="bdh-chip">
                    <span class="ico" style="background:#ecfeff;color:#0d9488;"><i class="fas fa-award"></i></span>
                    <span>
                        <span class="lbl d-block">Insentif</span>
                        <span class="val"><?= bendahara_rupiah($sum_insentif) ?></span>
                    </span>
                </div>
                <div class="bdh-chip">
                    <span class="ico" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-check-circle"></i></span>
                    <span>
                        <span class="lbl d-block">Sudah Bayar</span>
                        <span class="val"><?= bendahara_rupiah($sum_sudah) ?></span>
                    </span>
                </div>
                <div class="bdh-chip">
                    <span class="ico" style="background:#fff1f2;color:#be123c;"><i class="fas fa-hourglass-half"></i></span>
                    <span>
                        <span class="lbl d-block">Belum Bayar</span>
                        <span class="val"><?= bendahara_rupiah($sum_belum) ?></span>
                    </span>
                </div>
            </div>

            <form method="GET" class="bdh-toolbar" id="bdhHonorFilter">
                <div class="bdh-search">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" id="bdhHonorSearch" value="<?= htmlspecialchars($q) ?>" placeholder="Cari nama guru atau kategori...">
                </div>
                <select name="jenis" class="bdh-select" onchange="this.form.submit()">
                    <option value="">Semua Jenis</option>
                    <option value="honor" <?= $filter_jenis === 'honor' ? 'selected' : '' ?>>Honor</option>
                    <option value="insentif" <?= $filter_jenis === 'insentif' ? 'selected' : '' ?>>Insentif</option>
                </select>
                <select name="periode" class="bdh-select" onchange="this.form.submit()">
                    <option value="">Semua Periode</option>
                    <?php foreach ($periode_options as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= $filter_periode === $p ? 'selected' : '' ?>><?= htmlspecialchars(bendahara_periode_label($p)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="bdh-select" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Bayar</option>
                    <option value="sudah" <?= $filter_status === 'sudah' ? 'selected' : '' ?>>Sudah Bayar</option>
                </select>
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#modalHonor" style="border-radius:14px;font-weight:800;height:42px;">
                    <i class="fas fa-plus mr-1"></i>Tambah
                </button>
                <?php if ($filter_aktif): ?>
                    <a href="honor_insentif.php" class="btn btn-light" style="border-radius:14px;font-weight:800;height:42px;">
                        <i class="fas fa-undo mr-1"></i>Reset
                    </a>
                <?php endif; ?>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="small text-muted font-weight-bold">Menampilkan <?= count($paged) ?> dari <?= $total_rows ?> data</div>
            </div>

            <?php if (empty($paged)): ?>
                <div class="card"><div class="card-body">
                    <div class="bdh-empty">
                        <i class="fas fa-receipt fa-2x mb-3" style="color:#cbd5e1;"></i>
                        <div style="font-weight:800;color:#334155;">Belum ada data honor / insentif</div>
                        <div class="small text-muted mt-1">Tambahkan data lewat tombol Tambah.</div>
                    </div>
                </div></div>
            <?php else: ?>
                <div class="bdh-table d-none d-md-block">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jenis</th>
                                    <th>Kategori</th>
                                    <th>Periode</th>
                                    <th class="text-right">Nominal</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paged as $r):
                                    $id = (int)$r['id'];
                                    $jenis = (string)$r['jenis'];
                                    $status = (string)$r['status'];
                                ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= htmlspecialchars((string)$r['nama_guru']) ?></td>
                                        <td><span class="bdh-badge <?= $jenis === 'insentif' ? 'insentif' : 'honor' ?>"><?= htmlspecialchars(ucfirst($jenis)) ?></span></td>
                                        <td><?= htmlspecialchars((string)($r['kategori'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars(bendahara_periode_label((string)$r['periode'])) ?></td>
                                        <td class="num"><?= bendahara_rupiah((float)$r['nominal']) ?></td>
                                        <td><span class="bdh-badge <?= $status === 'sudah' ? 'ok' : 'no' ?>"><?= $status === 'sudah' ? 'Sudah' : 'Belum' ?></span></td>
                                        <td class="text-right" style="white-space:nowrap;">
                                            <button type="button" class="btn btn-sm btn-light js-honor-edit" style="border-radius:9px;font-weight:800;"
                                                data-id="<?= $id ?>"
                                                data-nama="<?= htmlspecialchars((string)$r['nama_guru'], ENT_QUOTES) ?>"
                                                data-jenis="<?= htmlspecialchars($jenis, ENT_QUOTES) ?>"
                                                data-kategori="<?= htmlspecialchars((string)($r['kategori'] ?? ''), ENT_QUOTES) ?>"
                                                data-periode="<?= htmlspecialchars((string)$r['periode'], ENT_QUOTES) ?>"
                                                data-nominal="<?= (float)$r['nominal'] ?>"
                                                data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
                                                data-keterangan="<?= htmlspecialchars((string)($r['keterangan'] ?? ''), ENT_QUOTES) ?>">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Tandai status pembayaran berubah?');">
                                                <input type="hidden" name="aksi" value="status">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <button type="submit" class="btn btn-sm btn-light" style="border-radius:9px;font-weight:800;" title="Ubah status">
                                                    <i class="fas fa-check-circle" style="color:<?= $status === 'sudah' ? '#16a34a' : '#be123c' ?>;"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data ini?');">
                                                <input type="hidden" name="aksi" value="hapus">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <button type="submit" class="btn btn-sm btn-light" style="border-radius:9px;color:#be123c;"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="d-md-none">
                    <?php foreach ($paged as $r):
                        $id = (int)$r['id'];
                        $jenis = (string)$r['jenis'];
                        $status = (string)$r['status'];
                    ?>
                        <div class="bdh-honor-card">
                            <div class="bdh-honor-top">
                                <div style="min-width:0;">
                                    <div class="bdh-honor-nama"><?= htmlspecialchars((string)$r['nama_guru']) ?></div>
                                    <div class="bdh-honor-meta">
                                        <?= htmlspecialchars(bendahara_periode_label((string)$r['periode'])) ?>
                                        <?php if (trim((string)($r['kategori'] ?? '')) !== ''): ?>&bull; <?= htmlspecialchars((string)$r['kategori']) ?><?php endif; ?>
                                    </div>
                                    <div class="mt-1">
                                        <span class="bdh-badge <?= $jenis === 'insentif' ? 'insentif' : 'honor' ?>"><?= htmlspecialchars(ucfirst($jenis)) ?></span>
                                        <span class="bdh-badge <?= $status === 'sudah' ? 'ok' : 'no' ?>"><?= $status === 'sudah' ? 'Sudah' : 'Belum' ?></span>
                                    </div>
                                </div>
                                <div class="bdh-honor-amt"><?= bendahara_rupiah((float)$r['nominal']) ?></div>
                            </div>
                            <div class="bdh-honor-actions">
                                <button type="button" class="btn btn-sm btn-light js-honor-edit" style="border-radius:9px;font-weight:800;"
                                    data-id="<?= $id ?>"
                                    data-nama="<?= htmlspecialchars((string)$r['nama_guru'], ENT_QUOTES) ?>"
                                    data-jenis="<?= htmlspecialchars($jenis, ENT_QUOTES) ?>"
                                    data-kategori="<?= htmlspecialchars((string)($r['kategori'] ?? ''), ENT_QUOTES) ?>"
                                    data-periode="<?= htmlspecialchars((string)$r['periode'], ENT_QUOTES) ?>"
                                    data-nominal="<?= (float)$r['nominal'] ?>"
                                    data-status="<?= htmlspecialchars($status, ENT_QUOTES) ?>"
                                    data-keterangan="<?= htmlspecialchars((string)($r['keterangan'] ?? ''), ENT_QUOTES) ?>">
                                    <i class="fas fa-pen mr-1"></i>Edit
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Tandai status pembayaran berubah?');">
                                    <input type="hidden" name="aksi" value="status">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-sm btn-light" style="border-radius:9px;font-weight:800;">
                                        <i class="fas fa-check-circle mr-1" style="color:<?= $status === 'sudah' ? '#16a34a' : '#be123c' ?>;"></i>Status
                                    </button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Hapus data ini?');">
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-sm btn-light" style="border-radius:9px;font-weight:800;color:#be123c;">
                                        <i class="fas fa-trash mr-1"></i>Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <?php
                    $base_params = ['q' => $q, 'jenis' => $filter_jenis, 'periode' => $filter_periode, 'status' => $filter_status];
                    $page_url = static function (int $p) use ($base_params): string {
                        $params = array_filter(array_merge($base_params, ['page' => $p]), static fn($v) => $v !== '' && $v !== null);
                        return 'honor_insentif.php?' . http_build_query($params);
                    };
                    ?>
                    <div class="bdh-pager">
                        <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($page_url(max(1, $page - 1))) ?>"><i class="fas fa-chevron-left"></i></a>
                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="is-active"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($page_url($p)) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <a class="<?= $page >= $total_pages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($page_url(min($total_pages, $page + 1))) ?>"><i class="fas fa-chevron-right"></i></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="modalHonor" tabindex="-1" role="dialog" aria-labelledby="modalHonorLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalHonorLabel">Tambah Honor / Insentif</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="aksi" value="tambah">
                <div class="form-group">
                    <label class="small font-weight-bold">Nama Guru / Penerima</label>
                    <input type="text" name="nama_guru" class="form-control" list="bdhGuruList" required>
                    <datalist id="bdhGuruList">
                        <?php foreach ($daftar_guru as $g): ?>
                            <option value="<?= htmlspecialchars($g['nama_guru'], ENT_QUOTES) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Jenis</label>
                        <select name="jenis" class="form-control">
                            <option value="honor">Honor</option>
                            <option value="insentif">Insentif</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Periode</label>
                        <input type="month" name="periode" class="form-control" value="<?= htmlspecialchars($bulan_ini) ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold">Kategori</label>
                    <input type="text" name="kategori" class="form-control" placeholder="Contoh: Honor Mengajar, Insentif Prestasi">
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Nominal (Rp)</label>
                        <input type="number" name="nominal" class="form-control" min="1" step="1000" required>
                    </div>
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Status</label>
                        <select name="status" class="form-control">
                            <option value="belum">Belum Bayar</option>
                            <option value="sudah">Sudah Bayar</option>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="small font-weight-bold">Keterangan</label>
                    <textarea name="keterangan" class="form-control" rows="2" placeholder="Opsional"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalHonorEdit" tabindex="-1" role="dialog" aria-labelledby="modalHonorEditLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalHonorEditLabel">Edit Honor / Insentif</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="aksi" value="update">
                <input type="hidden" name="id" id="edit_honor_id">
                <div class="form-group">
                    <label class="small font-weight-bold">Nama Guru / Penerima</label>
                    <input type="text" name="nama_guru" id="edit_honor_nama" class="form-control" list="bdhGuruList" required>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Jenis</label>
                        <select name="jenis" id="edit_honor_jenis" class="form-control">
                            <option value="honor">Honor</option>
                            <option value="insentif">Insentif</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Periode</label>
                        <input type="month" name="periode" id="edit_honor_periode" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="small font-weight-bold">Kategori</label>
                    <input type="text" name="kategori" id="edit_honor_kategori" class="form-control">
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Nominal (Rp)</label>
                        <input type="number" name="nominal" id="edit_honor_nominal" class="form-control" min="1" step="1000" required>
                    </div>
                    <div class="form-group col-6">
                        <label class="small font-weight-bold">Status</label>
                        <select name="status" id="edit_honor_status" class="form-control">
                            <option value="belum">Belum Bayar</option>
                            <option value="sudah">Sudah Bayar</option>
                        </select>
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="small font-weight-bold">Keterangan</label>
                    <textarea name="keterangan" id="edit_honor_keterangan" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var search = document.getElementById('bdhHonorSearch');
    var form = document.getElementById('bdhHonorFilter');
    if (search && form) {
        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { form.submit(); }, 450);
        });
    }

    document.querySelectorAll('.js-honor-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_honor_id').value = btn.dataset.id || '';
            document.getElementById('edit_honor_nama').value = btn.dataset.nama || '';
            document.getElementById('edit_honor_jenis').value = btn.dataset.jenis || 'honor';
            document.getElementById('edit_honor_periode').value = btn.dataset.periode || '';
            document.getElementById('edit_honor_kategori').value = btn.dataset.kategori || '';
            document.getElementById('edit_honor_nominal').value = btn.dataset.nominal || '';
            document.getElementById('edit_honor_status').value = btn.dataset.status || 'belum';
            document.getElementById('edit_honor_keterangan').value = btn.dataset.keterangan || '';
            if (window.jQuery) {
                window.jQuery('#modalHonorEdit').modal('show');
            }
        });
    });
})();
</script>

<?php include '../templates/footer.php'; ?>
