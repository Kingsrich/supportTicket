<?php

$host = "localhost";
$user = "root";
$password = "";
$database = "support";
$connection = mysqli_connect($host, $user, $password, $database);
if (!$connection) {
    die("Database connection failed: " . mysqli_connect_error());
}

date_default_timezone_set("Africa/Lagos");
