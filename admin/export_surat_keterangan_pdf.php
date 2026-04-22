<?php
// Dedicated export endpoint (kept for compatibility).
// Reuses the print engine with data-table PRINT mode (window print).
$_GET['mode'] = 'data';
$_GET['format'] = 'print';
$_GET['auto'] = $_GET['auto'] ?? '1';
require __DIR__ . '/print_surat_keterangan.php';

