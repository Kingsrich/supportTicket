<?php

$host = "localhost";
$user = "divacake_ticket";
$password = "Support@12345";
$database = "divacake_support";

$connection = mysqli_connect(
    $host,
    $user,
    $password,
    $database
);

if (!$connection) {
    die("Database connection failed: " . mysqli_connect_error());
}

/* Use Nigerian time in PHP */
date_default_timezone_set('Africa/Lagos');

/* Use Nigerian time for MySQL NOW() and CURRENT_TIMESTAMP */
mysqli_query($connection, "SET time_zone = '+01:00'");

/* Ensure correct character encoding */
mysqli_set_charset($connection, 'utf8mb4');