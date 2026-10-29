<?php

require __DIR__ . '/inc/erpnext_config.php';

$employeeId = "DC-EMP-00555";


$employeeUrl = rtrim(ERPNEXT_BASE_URL, "/")
    . "/api/resource/"
    . rawurlencode("Employee")
    . "/"
    . rawurlencode($employeeId)
    . "?fields=[\"employee_name\",\"personal_email\"]";


$ch = curl_init();

curl_setopt_array($ch, [

    CURLOPT_URL => $employeeUrl,

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [
        "Authorization: token " . ERPNEXT_API_KEY . ":" . ERPNEXT_API_SECRET,
        "Content-Type: application/json"
    ]

]);


$response = curl_exec($ch);


if (curl_errno($ch)) {
    die(curl_error($ch));
}


curl_close($ch);


$data = json_decode($response, true);


echo "<pre>";
print_r($data);
echo "</pre>";

?>