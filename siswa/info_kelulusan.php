<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isAuthorized(['siswa'])) {
    redirect('../login.php');
}

$id_siswa = (int) ($_SESSION['user_id'] ?? 0);
if ($id_siswa <= 0) {
    redirect('../login.php');
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_kelulusan_jadwal (
        tahun_ajaran VARCHAR(30) NOT NULL,
        waktu_mulai_tampil DATETIME DEFAULT NULL,
        siswa_lihat_kelulusan TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (tahun_ajaran)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
}

try {
    $pdo->exec("ALTER TABLE tb_kelulusan_jadwal ADD COLUMN siswa_lihat_kelulusan TINYINT(1) NOT NULL DEFAULT 0 AFTER waktu_mulai_tampil");
} catch (PDOException $e) {
}

foreach ([
    'is_lulus' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER nomor_ujian',
    'keterangan_kelulusan' => 'VARCHAR(500) DEFAULT NULL AFTER is_lulus',
    'tampil_di_akun_siswa' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER keterangan_kelulusan',
] as $col => $definition) {
    try {
        $pdo->exec("ALTER TABLE tb_peserta_ujian ADD COLUMN {$col} {$definition}");
    } catch (PDOException $e) {
    }
}

$page_title = 'Info Kelulusan';

$sql = "
    SELECT pu.tahun_ajaran, pu.nomor_ujian, pu.is_lulus, pu.keterangan_kelulusan,
           j.waktu_mulai_tampil
    FROM tb_peserta_ujian pu
    INNER JOIN tb_kelulusan_jadwal j ON j.tahun_ajaran = pu.tahun_ajaran AND j.siswa_lihat_kelulusan = 1
    WHERE pu.id_siswa = ?
    ORDER BY pu.tahun_ajaran DESC
";
$st = $pdo->prepare($sql);
$st->execute([$id_siswa]);
$daftar = $st->fetchAll(PDO::FETCH_ASSOC);

$now = new DateTimeImmutable('now');

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><?= htmlspecialchars($page_title) ?></div>
            </div>
        </div>

        <div class="section-body">
            <?php if (empty($daftar)): ?>
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-0">Belum ada informasi kelulusan yang ditampilkan untuk akun Anda. Jika Anda peserta ujian, hubungi Tata Usaha atau admin apabila seharusnya sudah tayang.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($daftar as $row): ?>
                    <?php
                    $ta = (string) ($row['tahun_ajaran'] ?? '');
                    $jadwal_raw = $row['waktu_mulai_tampil'] ?? null;
                    $belum_waktunya = false;
                    $tanggal_jadwal_teks = '';
                    if ($jadwal_raw !== null && $jadwal_raw !== '') {
                        try {
                            $jadwalDt = new DateTimeImmutable((string) $jadwal_raw);
                            $belum_waktunya = $now < $jadwalDt;
                            $tanggal_jadwal_teks = $jadwalDt->format('d/m/Y H:i');
                        } catch (Exception $e) {
                            $belum_waktunya = false;
                        }
                    }
                    ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h4>Kelulusan — Tahun Ajaran <?= htmlspecialchars($ta) ?></h4>
                        </div>
                        <div class="card-body">
                            <?php if ($belum_waktunya): ?>
                                <p class="text-primary mb-0">
                                    <i class="fas fa-clock mr-1"></i>
                                    Pengumuman kelulusan untuk tahun ajaran ini akan ditampilkan pada <strong><?= htmlspecialchars($tanggal_jadwal_teks) ?></strong>.
                                </p>
                            <?php else: ?>
                                <p class="mb-2">
                                    <strong>Status:</strong>
                                    <?php if (!empty($row['is_lulus'])): ?>
                                        <span class="badge badge-success">Lulus</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Tidak lulus</span>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($row['nomor_ujian'])): ?>
                                    <p class="mb-2"><strong>Nomor ujian:</strong> <?= htmlspecialchars((string) $row['nomor_ujian']) ?></p>
                                <?php endif; ?>
                                <?php
                                $ket = trim((string) ($row['keterangan_kelulusan'] ?? ''));
                                if ($ket === '') {
                                    $ket = !empty($row['is_lulus']) ? 'Lulus' : 'Tidak lulus';
                                }
                                $badgeLulus = !empty($row['is_lulus']);
                                ?>
                                <p class="mb-0"><strong>Keterangan:</strong>
                                    <span class="badge <?= $badgeLulus ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($ket, ENT_QUOTES, 'UTF-8') ?></span>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
require_once '../templates/footer.php';
