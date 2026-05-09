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

$stKelas = $pdo->prepare('SELECT k.nama_kelas FROM tb_siswa s LEFT JOIN tb_kelas k ON s.id_kelas = k.id_kelas WHERE s.id_siswa = ? LIMIT 1');
$stKelas->execute([$id_siswa]);
$nama_kelas_siswa = (string) ($stKelas->fetchColumn() ?: '');
$nkUpper = strtoupper($nama_kelas_siswa);
if (strpos($nkUpper, '6') === false && strpos($nkUpper, 'VI') === false) {
    echo "<script>alert('Halaman ini hanya untuk siswa kelas 6'); window.location.href='dashboard.php';</script>";
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tb_kelulusan_jadwal (
        tahun_ajaran VARCHAR(30) NOT NULL,
        waktu_mulai_tampil DATETIME DEFAULT NULL,
        siswa_lihat_kelulusan TINYINT(1) NOT NULL DEFAULT 0,
        tanggal_surat_kelulusan DATE DEFAULT NULL,
        kota_surat VARCHAR(80) DEFAULT NULL,
        qr_tanda_tangan_payload VARCHAR(768) DEFAULT NULL,
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
    ['tanggal_surat_kelulusan', "DATE DEFAULT NULL AFTER siswa_lihat_kelulusan"],
    ['kota_surat', 'VARCHAR(80) DEFAULT NULL AFTER tanggal_surat_kelulusan'],
    ['qr_tanda_tangan_payload', 'VARCHAR(768) DEFAULT NULL AFTER kota_surat'],
] as $_kelCol) {
    try {
        $pdo->exec("ALTER TABLE tb_kelulusan_jadwal ADD COLUMN `{$_kelCol[0]}` {$_kelCol[1]}");
    } catch (PDOException $_e) {
    }
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

$school_profile = getSchoolProfile($pdo);
$nama_madrasah_profil = trim((string) ($school_profile['nama_madrasah'] ?? 'Madrasah'));
$taBerjalan = trim((string) ($school_profile['tahun_ajaran'] ?? ''));
$filterTaBerjalan = isTahunAjaranFormatValid($taBerjalan);

$sql = '
    SELECT pu.tahun_ajaran, pu.nomor_ujian, pu.is_lulus, pu.keterangan_kelulusan,
           j.waktu_mulai_tampil, j.tanggal_surat_kelulusan
    FROM tb_peserta_ujian pu
    INNER JOIN tb_kelulusan_jadwal j ON j.tahun_ajaran = pu.tahun_ajaran AND j.siswa_lihat_kelulusan = 1
    WHERE pu.id_siswa = ?';
$paramsKel = [$id_siswa];
if ($filterTaBerjalan) {
    $sql .= ' AND pu.tahun_ajaran = ?';
    $paramsKel[] = $taBerjalan;
}
$sql .= ' ORDER BY pu.tahun_ajaran DESC';

$st = $pdo->prepare($sql);
$st->execute($paramsKel);
$daftar = $st->fetchAll(PDO::FETCH_ASSOC);

$stS = $pdo->prepare('SELECT nama_siswa, nisn FROM tb_siswa WHERE id_siswa = ? LIMIT 1');
$stS->execute([$id_siswa]);
$siswaInfo = $stS->fetch(PDO::FETCH_ASSOC) ?: ['nama_siswa' => '', 'nisn' => ''];
$nama_siswa_teks = trim((string) ($siswaInfo['nama_siswa'] ?? ''));
$nisn_teks = trim((string) ($siswaInfo['nisn'] ?? ''));

$now = new DateTimeImmutable('now');

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<style>
    .surat-kelulusan-card .surat-intro {
        text-align: justify;
        line-height: 1.75;
        font-size: 0.98rem;
    }
    .surat-kelulusan-card .surat-pembagi {
        border: 0;
        border-top: 2px dashed #dee2e6;
        margin: 1rem 0;
    }
    .surat-kelulusan-card .surat-du-item {
        display: grid;
        grid-template-columns: 11rem 1fr;
        gap: 0.35rem 1rem;
        align-items: baseline;
        padding: 0.35rem 0;
        border-bottom: 1px dashed #dee2e6;
    }
    .surat-kelulusan-card .surat-du-item:last-child {
        border-bottom: none;
    }
    .surat-kelulusan-card .surat-dinyatakan {
        letter-spacing: 0.06em;
    }
    .surat-kelulusan-card .surat-status-gede {
        font-size: clamp(1.35rem, 3.5vw, 2rem);
        font-weight: 800;
        line-height: 1.3;
        margin-top: 0.5rem;
    }
    .surat-kelulusan-card .surat-penutup {
        text-align: justify;
        line-height: 1.75;
        font-size: 0.98rem;
    }
    .surat-kelulusan-card .info-waktu-kelulusan {
        font-size: clamp(1.15rem, 2.8vw, 1.55rem);
        line-height: 1.75;
        font-weight: 700;
        padding: 0.55rem 1rem;
        background: rgba(103, 119, 239, 0.08);
        border-radius: 0.5rem;
        display: table;
        margin: 0 auto;
        text-align: center;
    }
    .kelulusan-until-reveal {
        max-width: 26rem;
        margin-left: auto;
        margin-right: auto;
    }
    .kelulusan-until-reveal-title {
        font-weight: 600;
        font-size: 0.92rem;
        color: #5a6270;
        margin-bottom: 0.65rem;
    }
    .kelulusan-until-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.45rem;
    }
    .kelulusan-until-unit {
        background: rgba(103, 119, 239, 0.1);
        border-radius: 0.5rem;
        padding: 0.6rem 0.3rem;
        text-align: center;
        border: 1px solid rgba(103, 119, 239, 0.15);
    }
    .kelulusan-until-val {
        display: block;
        font-size: clamp(1.2rem, 4vw, 1.65rem);
        font-weight: 800;
        color: #4954c9;
        line-height: 1.15;
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.02em;
    }
    .kelulusan-until-label {
        display: block;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c757d;
        margin-top: 0.3rem;
    }
    @media (max-width: 575px) {
        .surat-kelulusan-card .surat-du-item {
            grid-template-columns: 1fr;
        }
    }
    .kelulusan-countdown-overlay {
        position: fixed;
        inset: 0;
        z-index: 10050;
        background: linear-gradient(145deg, rgba(20, 30, 85, 0.92), rgba(44, 82, 130, 0.9));
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        transition: opacity 0.45s ease, visibility 0.45s ease;
    }
    .kelulusan-countdown-overlay.kelulusan-countdown--done {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }
    .kelulusan-countdown-num {
        font-size: clamp(4.5rem, 18vw, 8rem);
        font-weight: 800;
        line-height: 1;
        color: #fff;
        text-shadow: 0 8px 40px rgba(0, 0, 0, 0.35);
        display: inline-block;
    }
    .kelulusan-countdown-num.kelulusan-countdown-num--pop {
        animation: kelulusan-pop 0.55s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes kelulusan-pop {
        0% {
            transform: scale(0.35);
            opacity: 0;
        }
        60% {
            transform: scale(1.08);
            opacity: 1;
        }
        100% {
            transform: scale(1);
        }
    }
    .kelulusan-section-body--pending {
        opacity: 0;
        transition: opacity 0.55s ease 0.08s;
    }
    .kelulusan-section-body.kelulusan-section-body--ready {
        opacity: 1;
    }
</style>

<div class="main-content">
    <div id="kelulusan-countdown-overlay" class="kelulusan-countdown-overlay" aria-live="polite" aria-busy="true">
        <span id="kelulusan-countdown-num" class="kelulusan-countdown-num">5</span>
        <p class="text-white mb-0 mt-3 small" style="opacity: 0.85;">Membuka pengumuman kelulusan…</p>
    </div>
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><?= htmlspecialchars($page_title) ?></div>
            </div>
        </div>

        <div class="section-body kelulusan-section-body kelulusan-section-body--pending">
            <?php if (empty($daftar)): ?>
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted mb-0">Belum ada informasi kelulusan yang ditampilkan untuk akun Anda. Jika Anda peserta ujian kelas VI, hubungi Tata Usaha atau admin apabila pengumuman seharusnya sudah tayang.</p>
                        <?php if ($filterTaBerjalan): ?>
                            <p class="text-muted small mb-0 mt-2">Halaman ini menampilkan pengumuman untuk <strong>Tahun Ajaran <?= htmlspecialchars($taBerjalan, ENT_QUOTES, 'UTF-8') ?></strong> (tahun ajaran berjalan menurut profil madrasah). Pastikan data peserta ujian, jadwal kelulusan, dan «tampil di akun siswa» di admin sudah memakai tahun ajaran yang sama.</p>
                        <?php endif; ?>
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
                    <div class="card mb-4 shadow-sm surat-kelulusan-card">
                        <div class="card-body py-4 px-md-5">
                            <h4 class="text-center text-uppercase font-weight-bold mb-4">Keterangan Kelulusan</h4>
                            <p class="surat-intro mb-0">
                                Berdasarkan hasil Pra Asesmen Madrasah dan Asesmen Madrasah, serta hasil rapat dewan guru dan Kepala
                                <strong><?= htmlspecialchars($nama_madrasah_profil, ENT_QUOTES, 'UTF-8') ?></strong>
                                Tahun Ajaran <strong><?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?></strong>
                                di <strong><?= htmlspecialchars($nama_madrasah_profil, ENT_QUOTES, 'UTF-8') ?></strong>.
                                Maka dengan ini Kepala <strong><?= htmlspecialchars($nama_madrasah_profil, ENT_QUOTES, 'UTF-8') ?></strong> menyatakan bahwa:
                            </p>

                            <?php if ($belum_waktunya): ?>
                                <hr class="surat-pembagi">
                                <p class="text-primary text-center mb-0 info-waktu-kelulusan">
                                    <i class="fas fa-clock mr-1"></i>
                                    Isi pengumuman dapat dilihat mulai <strong><?= htmlspecialchars($tanggal_jadwal_teks) ?></strong>.
                                </p>
                                <div class="kelulusan-until-reveal mt-4" role="timer" aria-live="polite" data-target-iso="<?= htmlspecialchars($jadwalDt->format(DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8') ?>">
                                    <p class="kelulusan-until-reveal-title text-center mb-0">
                                        <i class="fas fa-hourglass-half mr-1 text-primary"></i>
                                        Hitung mundur menuju pengumuman
                                    </p>
                                    <div class="kelulusan-until-grid mt-2">
                                        <div class="kelulusan-until-unit">
                                            <span class="kelulusan-until-val js-kel-days">00</span>
                                            <span class="kelulusan-until-label">Hari</span>
                                        </div>
                                        <div class="kelulusan-until-unit">
                                            <span class="kelulusan-until-val js-kel-hours">00</span>
                                            <span class="kelulusan-until-label">Jam</span>
                                        </div>
                                        <div class="kelulusan-until-unit">
                                            <span class="kelulusan-until-val js-kel-minutes">00</span>
                                            <span class="kelulusan-until-label">Menit</span>
                                        </div>
                                        <div class="kelulusan-until-unit">
                                            <span class="kelulusan-until-val js-kel-seconds">00</span>
                                            <span class="kelulusan-until-label">Detik</span>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <hr class="surat-pembagi">

                                <div class="surat-du-item mb-0">
                                    <span class="font-weight-bold">Nama</span>
                                    <span><?= $nama_siswa_teks !== '' ? htmlspecialchars($nama_siswa_teks, ENT_QUOTES, 'UTF-8') : '—' ?></span>
                                </div>
                                <div class="surat-du-item mb-0">
                                    <span class="font-weight-bold">NISN</span>
                                    <span><?= $nisn_teks !== '' ? htmlspecialchars($nisn_teks, ENT_QUOTES, 'UTF-8') : '—' ?></span>
                                </div>
                                <div class="surat-du-item mb-3">
                                    <span class="font-weight-bold">Nomor Peserta AM</span>
                                    <span><?php
                                    $nou = trim((string) ($row['nomor_ujian'] ?? ''));
                                    echo $nou !== '' ? htmlspecialchars($nou, ENT_QUOTES, 'UTF-8') : '—';
                                    ?></span>
                                </div>

                                <hr class="surat-pembagi">

                                <div class="text-center py-2">
                                    <div class="surat-dinyatakan text-dark font-weight-bold text-uppercase" style="font-size: 1.15rem;">
                                        Dinyatakan
                                    </div>
                                    <?php
                                    $badgeLulus = !empty($row['is_lulus']);
                                    ?>
                                    <div class="surat-status-gede mt-2">
                                        <span class="badge <?= $badgeLulus ? 'badge-success' : 'badge-danger' ?> px-3 py-2" style="font-size: inherit;">
                                            <?= $badgeLulus ? 'LULUS' : 'TIDAK LULUS' ?>
                                        </span>
                                    </div>
                                </div>

                                <hr class="surat-pembagi">

                                <p class="surat-penutup mb-0">
                                    <?php if ($badgeLulus): ?>
                                        Sehingga yang bersangkutan berhak memperoleh ijazah <?= htmlspecialchars($nama_madrasah_profil, ENT_QUOTES, 'UTF-8') ?> Tahun Ajaran <strong><?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?></strong>.
                                    <?php else: ?>
                                        Yang bersangkutan tidak berhak memperoleh ijazah <?= htmlspecialchars($nama_madrasah_profil, ENT_QUOTES, 'UTF-8') ?> Tahun Ajaran <strong><?= htmlspecialchars($ta, ENT_QUOTES, 'UTF-8') ?></strong> hingga persyaratan kelulusan terpenuhi sesuai ketentuan madrasah.
                                    <?php endif; ?>
                                </p>

                                <?php $ada_tgl_surat = !empty(trim((string) ($row['tanggal_surat_kelulusan'] ?? ''))); ?>
                                <div class="text-center mt-4 no-print">
                                    <?php if ($ada_tgl_surat): ?>
                                        <a href="cetak_surat_kelulusan.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm"><i class="fas fa-print mr-1"></i> Cetak Surat Kelulusan</a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Tanggal surat belum diatur admin"><i class="fas fa-print mr-1"></i> Cetak Surat Kelulusan</button>
                                        <p class="text-muted small text-center mt-2 mb-0">Tanggal surat belum diatur admin pada menu <strong>Data Peserta Ujian</strong>.</p>
                                    <?php endif; ?>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(function() {
    var overlay = document.getElementById('kelulusan-countdown-overlay');
    var numEl = document.getElementById('kelulusan-countdown-num');
    var bodyEl = document.querySelector('.kelulusan-section-body');
    if (!overlay || !numEl || !bodyEl) {
        return;
    }
    var n = 5;
    function playPop() {
        numEl.classList.remove('kelulusan-countdown-num--pop');
        void numEl.offsetWidth;
        numEl.classList.add('kelulusan-countdown-num--pop');
    }
    function selesai() {
        overlay.classList.add('kelulusan-countdown--done');
        overlay.setAttribute('aria-busy', 'false');
        bodyEl.classList.remove('kelulusan-section-body--pending');
        bodyEl.classList.add('kelulusan-section-body--ready');
        window.setTimeout(function() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }, 500);
    }
    function tik() {
        numEl.textContent = String(n);
        playPop();
        if (n <= 1) {
            window.setTimeout(selesai, 900);
            return;
        }
        n -= 1;
        window.setTimeout(tik, 1000);
    }
    window.setTimeout(tik, 400);
})();

(function() {
    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }
    function tickOne(root) {
        var iso = root.getAttribute('data-target-iso');
        if (!iso) {
            return;
        }
        var target = Date.parse(iso);
        if (Number.isNaN(target)) {
            return;
        }
        var now = Date.now();
        var diff = Math.max(0, target - now);
        var days = Math.floor(diff / 86400000);
        var hours = Math.floor((diff % 86400000) / 3600000);
        var minutes = Math.floor((diff % 3600000) / 60000);
        var seconds = Math.floor((diff % 60000) / 1000);
        var elD = root.querySelector('.js-kel-days');
        var elH = root.querySelector('.js-kel-hours');
        var elM = root.querySelector('.js-kel-minutes');
        var elS = root.querySelector('.js-kel-seconds');
        if (elD) {
            elD.textContent = days > 99 ? String(days) : pad2(days);
        }
        if (elH) {
            elH.textContent = pad2(hours);
        }
        if (elM) {
            elM.textContent = pad2(minutes);
        }
        if (elS) {
            elS.textContent = pad2(seconds);
        }
        if (diff <= 0) {
            if (!root.hasAttribute('data-reloading')) {
                root.setAttribute('data-reloading', '1');
                window.setTimeout(function() {
                    window.location.reload();
                }, 800);
            }
        }
    }
    function run() {
        var roots = document.querySelectorAll('.kelulusan-until-reveal[data-target-iso]');
        if (!roots.length) {
            return;
        }
        roots.forEach(tickOne);
        window.setInterval(function() {
            roots.forEach(tickOne);
        }, 1000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
</script>

<?php
require_once '../templates/footer.php';
