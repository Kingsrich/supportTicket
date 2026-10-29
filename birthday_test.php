<?php

define('ERPNEXT_BASE_URL', 'https://erp.divacakes.com.ng');
define('ERPNEXT_API_KEY', '856d877bb94d506');
define('ERPNEXT_API_SECRET', '729d0bc05663b68');
// Today's month and day
$today = date("m-d");
// $today = "02-12";
// Fetch Employees
$url = ERPNEXT_BASE_URL .
    "/api/resource/Employee?limit_page_length=1000&fields=[\"name\",\"employee_name\",\"date_of_birth\",\"company_email\",\"status\"]";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: token " . ERPNEXT_API_KEY . ":" . ERPNEXT_API_SECRET,
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
curl_close($ch);
$data = json_decode($response, true);
echo "<h2>Today's Birthday Check</h2>";
if (isset($data['data'])) {
    foreach ($data['data'] as $employee) {
        if (empty($employee['date_of_birth'])) {
            continue;
        }

        if ($employee['status'] !== 'Active') {
            continue;
        }
        // Extract month-day from ERPNext birthday
        $birthday = date(
            "m-d",
            strtotime($employee['date_of_birth'])
        );
        if ($birthday == $today) {
            echo "<p>";
            echo "🎂 Birthday Today: ";
            echo $employee['employee_name'];
            echo " - ";
            echo $employee['company_email'];
            echo "</p>";
        }
    }
}
?>