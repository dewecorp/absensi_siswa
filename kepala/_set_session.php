<?php
session_start();
$_SESSION['user_id'] = 999;
$_SESSION['level'] = 'kepala';
$_SESSION['username'] = 'tester';
$_SESSION['nama'] = 'Tester Uji';
$_SESSION['last_activity'] = time();
echo session_id();
