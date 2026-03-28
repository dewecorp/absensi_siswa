<?php
require_once '../config/database.php';
require_once '../config/functions.php';

if (!isAuthorized(['wali'])) {
    redirect('../login.php');
}

// Redirect to guru version with session_type parameter
redirect('../guru/nilai_ujian.php?session_type=wali');
