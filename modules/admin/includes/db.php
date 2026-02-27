<?php
$hostName  = 'localhost';
$dbUser    = 'root';
$dbPassword = '';
$dbName    = 'athina_eshop';

$conn = mysqli_connect($hostName, $dbUser, $dbPassword, $dbName);
if (!$conn) {
    die('<div style="font-family:sans-serif;padding:40px;color:#b91c1c;">
         <b>Database connection failed.</b><br>' . mysqli_connect_error() . '
         </div>');
}
mysqli_set_charset($conn, 'utf8mb4');
