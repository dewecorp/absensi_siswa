<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

redirect('../guru/nilai_kokurikuler.php?session_type=wali');
