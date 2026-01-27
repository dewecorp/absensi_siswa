<?php
require_once '../config/functions.php';
require_once '../config/database.php';
if (!isAuthorized(['kepala_madrasah'])) {
    redirect('../login.php');
}
require_once '../admin/jadwal_reguler.php';
