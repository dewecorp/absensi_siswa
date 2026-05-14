<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

$params = ['session_type' => 'wali'];
if (isset($_GET['nilai_mode']) && $_GET['nilai_mode'] === 'praktik') {
    $params['nilai_mode'] = 'praktik';
}

redirect('../guru/nilai_ujian.php?' . http_build_query($params));
