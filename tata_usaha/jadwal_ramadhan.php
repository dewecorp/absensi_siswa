<?php
require_once '../config/functions.php';
require_once '../config/database.php';
if (!isAuthorized(['tata_usaha'])) {
    redirect('../login.php');
}
require_once '../admin/jadwal_ramadhan.php';
