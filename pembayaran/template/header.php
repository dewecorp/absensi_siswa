<?php
/**
 * Pembungkus halaman Pembayaran Siswa dengan shell native SIMAD.
 * File halaman berada satu level di bawah root SIMAD (/pembayaran),
 * sehingga path relatif ../assets di header/footer SIMAD tetap valid.
 */
require_once __DIR__ . '/../config/config.php';

$title = $title ?? 'Pembayaran Siswa';
$page_title = $title . ' - Pembayaran Siswa';

// Penanda untuk templates/header.php dan templates/footer.php.
$pembayaran_module = true;

include __DIR__ . '/../../templates/header.php';
include_once __DIR__ . '/../../templates/sidebar.php';
?>
<div class="main-content pembayaran-app">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item">
                    <a href="<?= htmlspecialchars(simad_url('bendahara/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
                </div>
                <div class="breadcrumb-item">
                    <a href="<?= htmlspecialchars(base_url('index.php'), ENT_QUOTES, 'UTF-8') ?>">Pembayaran Siswa</a>
                </div>
                <div class="breadcrumb-item active"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="section-body">
