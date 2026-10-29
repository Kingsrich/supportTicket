<?php
require __DIR__ . '/inc/erpnext_config.php';
$salarySlip = "Sal Slip/DC-EMP-00555/00028";
$url = rtrim(ERPNEXT_BASE_URL, "/")
    . "/api/resource/"
    . rawurlencode("Salary Slip")
    . "/"
    . rawurlencode($salarySlip);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
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
echo "<pre>";
print_r(
    json_decode($response, true)
);
echo "</pre>";
?>