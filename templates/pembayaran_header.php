<?php
/**
 * Header halaman Pembayaran Siswa memakai shell Stisla/SIMAD.
 * File ini berada di templates/ agar modul tidak lagi punya template sendiri.
 */
require_once __DIR__ . '/../pembayaran/config/config.php';

$title = $title ?? 'Pembayaran Siswa';
$page_title = $title . ' - Pembayaran Siswa';
$pembayaran_module = true;

include __DIR__ . '/header.php';
include_once __DIR__ . '/sidebar.php';

$dashboard_home_url = (($user_level ?? '') === 'admin') ? 'admin/dashboard.php' : 'bendahara/dashboard.php';
$pembayaran_index_url = base_url('index.php') . ((($user_level ?? '') === 'admin') ? '?session_type=admin' : '');
?>
<div class="main-content pembayaran-app">
    <section class="section">
        <div class="section-header">
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item">
                    <a href="<?= htmlspecialchars(simad_url($dashboard_home_url), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
                </div>
                <div class="breadcrumb-item">
                    <a href="<?= htmlspecialchars($pembayaran_index_url, ENT_QUOTES, 'UTF-8') ?>">Pembayaran Siswa</a>
                </div>
                <div class="breadcrumb-item active"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="section-body">
