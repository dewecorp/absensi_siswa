<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

$page_title = 'Nilai Ujian';

require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="main-content">
    <section class="section">
        <div class="section-header">
            <h1><?= $page_title ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="dashboard.php">Dashboard</a></div>
                <div class="breadcrumb-item"><a href="#">Nilai Siswa</a></div>
                <div class="breadcrumb-item">Nilai Ujian</div>
            </div>
        </div>

        <div class="section-body">
            <div class="card">
                <div class="card-body">
                    <p>Fitur Nilai Ujian (Khusus Kelas 6) sedang dalam pengembangan.</p>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../templates/footer.php'; ?>
