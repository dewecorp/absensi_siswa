<?php
require_once '../config/database.php';
require_once '../config/functions.php';

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isAuthorized(['admin'])) {
    redirect('../login.php');
}

$page_title = 'RAB Ujian';

// Get school profile
$school_profile = getSchoolProfile($pdo);
$school_name = strtoupper($school_profile['nama_madrasah'] ?? 'Sistem Absensi Siswa');

// Include header
include '../templates/header.php';
include '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1>RAB Ujian</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Keuangan</a></div>
                <div class="breadcrumb-item">RAB Ujian</div>
            </div>
        </div>

        <div class="section-body">
            <h2 class="section-title">Rencana Anggaran Biaya Ujian</h2>
            <p class="section-lead">
                Halaman ini untuk mengelola data RAB Ujian.
            </p>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Data RAB Ujian</h4>
                            <div class="card-header-action">
                                <a href="#" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Data</a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="empty-state" data-height="400">
                                <div class="empty-state-icon">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </div>
                                <h2>Belum ada data</h2>
                                <p class="lead">
                                    Silakan tambahkan data RAB Ujian baru.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
