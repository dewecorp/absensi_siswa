<?php
require_once '../config/functions.php';
require_once '../config/database.php';
if (!isAuthorized(['guru'])) {
    redirect('../login.php');
}
require_once '../admin/jadwal_ramadhan.php';
