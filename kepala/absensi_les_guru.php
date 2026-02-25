<?php
require_once '../config/functions.php';
require_once '../config/database.php';

// Check if user is logged in and has allowed level
if (!isAuthorized(['kepala_madrasah'])) {
    redirect('../login.php');
}

require_once '../admin/absensi_les_guru.php';
