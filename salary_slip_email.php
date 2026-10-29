<?php set_time_limit(300);
ini_set('memory_limit', '256M');
require __DIR__ . '/inc/connection.php';
require __DIR__ . '/inc/EmailService.php';
require __DIR__ . '/inc/erpnext_config.php';
$emailService = new EmailService($connection);
$batchLimit = 20; /* * TEST PAYROLL * Keep this while testing. * Change to "" when ready for automatic latest payroll. */
$testPayrollEntry = "HR-PRUN-2026-00052";
function logSalary($msg)
{
    file_put_contents(__DIR__ . '/salary_slip_email.log', date('Y-m-d H:i:s') . " - " . $msg . PHP_EOL, FILE_APPEND);
}
function erpRequest($url)
{
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: token " . ERPNEXT_API_KEY . ":" . ERPNEXT_API_SECRET, "Content-Type: application/json"], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        logSalary("ERPNext CURL error: " . $error);
        return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        logSalary("ERPNext HTTP error: " . $httpCode);
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        logSalary("Invalid ERPNext JSON response");
        return null;
    }
    return $data;
}
function salarySlipAlreadySent($connection, $salarySlip)
{
    $stmt = mysqli_prepare($connection, "SELECT id FROM salary_slip_email_logs WHERE salary_slip=? LIMIT 1");
    if (!$stmt) {
        logSalary("Duplicate check prepare failed: " . mysqli_error($connection));
        return false;
    }
    mysqli_stmt_bind_param($stmt, "s", $salarySlip);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}
function saveSalarySlipLog($connection, $salarySlip, $payrollEntry, $employee, $email)
{
    $stmt = mysqli_prepare($connection, "INSERT IGNORE INTO salary_slip_email_logs (salary_slip,payroll_entry,employee,employee_email) VALUES (?,?,?,?)");
    if (!$stmt) {
        logSalary("Log insert prepare failed: " . mysqli_error($connection));
        return false;
    }
    mysqli_stmt_bind_param($stmt, "ssss", $salarySlip, $payrollEntry, $employee, $email);
    $saved = mysqli_stmt_execute($stmt);
    if (!$saved) {
        logSalary("Log insert failed: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
    return $saved;
}
function getSalarySlipPdf($salarySlip)
{
    $url = rtrim(ERPNEXT_BASE_URL, "/") . "/api/method/frappe.utils.print_format.download_pdf";
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(["doctype" => "Salary Slip", "name" => $salarySlip, "format" => "Salary Slip Email"]), CURLOPT_HTTPHEADER => ["Authorization: token " . ERPNEXT_API_KEY . ":" . ERPNEXT_API_SECRET], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60]);
    $pdf = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error || $httpCode < 200 || $httpCode >= 300 || empty($pdf)) {
        logSalary("PDF generation failed for {$salarySlip}. HTTP {$httpCode}" . ($error ? " - {$error}" : ""));
        return false;
    }
    if (stripos($contentType, 'application/pdf') === false && substr($pdf, 0, 4) !== '%PDF') {
        logSalary("Invalid PDF response for " . $salarySlip);
        return false;
    }
    return $pdf;
} /* Prevent overlapping cron runs */
$lockHandle = fopen(__DIR__ . '/salary_slip_email.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    logSalary("Another salary slip process is already running. Exiting.");
    exit;
}
register_shutdown_function(function () use ($lockHandle) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});
logSalary("Salary slip checker started");
echo "<h3>Salary Slip Email Automation</h3>"; /* GET PAYROLL ENTRY */
if (!empty($testPayrollEntry)) {
    $payrollEntry = $testPayrollEntry;
    logSalary("TEST payroll selected: " . $payrollEntry);
} else {
    $params = ["fields" => json_encode(["name", "posting_date", "company"]), "order_by" => "creation desc", "limit_page_length" => 1];
    $url = rtrim(ERPNEXT_BASE_URL, "/") . "/api/resource/" . rawurlencode("Payroll Entry") . "?" . http_build_query($params);
    $payrollResult = erpRequest($url);
    if (empty($payrollResult['data'][0]['name'])) {
        logSalary("No payroll entry found");
        exit("No payroll entry found");
    }
    $payrollEntry = $payrollResult['data'][0]['name'];
}
echo "Payroll: " . $payrollEntry . "<br>";
logSalary("Payroll detected: " . $payrollEntry); /* GET SUBMITTED SALARY SLIPS */
$params = ["fields" => json_encode(["name", "employee", "employee_name", "posting_date", "payroll_entry"]), "filters" => json_encode([["payroll_entry", "=", $payrollEntry], ["docstatus", "=", 1]]), "order_by" => "name asc", "limit_page_length" => 1000];
$url = rtrim(ERPNEXT_BASE_URL, "/") . "/api/resource/" . rawurlencode("Salary Slip") . "?" . http_build_query($params);
$salaryResult = erpRequest($url);
if (empty($salaryResult['data'])) {
    logSalary("No submitted salary slips found for " . $payrollEntry);
    exit("No submitted salary slips found");
}
$processed = 0;
$sentCount = 0;
$failedCount = 0;
foreach ($salaryResult['data'] as $slip) {
    if ($processed >= $batchLimit) {
        break;
    }
    $salarySlip = $slip['name'];
    $employeeId = $slip['employee'];
    $employeeName = $slip['employee_name']; /* Already-sent slips DO NOT count against batch limit */
    if (salarySlipAlreadySent($connection, $salarySlip)) {
        continue;
    }
    echo "<hr>Processing: " . htmlspecialchars($employeeName) . "<br>"; /* GET PERSONAL EMAIL */
    $employeeUrl = rtrim(ERPNEXT_BASE_URL, "/") . "/api/resource/" . rawurlencode("Employee") . "/" . rawurlencode($employeeId);
    $employeeResult = erpRequest($employeeUrl);
    $employee = $employeeResult['data'] ?? null;
    if (!$employee) {
        echo "Employee not found<br>";
        logSalary("Employee not found: " . $employeeId);
        continue;
    }
    $email = trim($employee['personal_email'] ?? '');
    if (empty($email)) {
        echo "No personal email<br>";
        logSalary("No personal email: " . $employeeName . " (" . $employeeId . ")");
        continue;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "Invalid personal email<br>";
        logSalary("Invalid email: " . $employeeName . " - " . $email);
        continue;
    } /* * Count only a valid, unsent salary slip that is actually * about to be processed. */
    $processed++; /* GENERATE PDF */
    $pdf = getSalarySlipPdf($salarySlip);
    if ($pdf === false) {
        echo "PDF generation failed<br>";
        $failedCount++;
        continue;
    } /* SEND EMAIL */
    $postingDate = $slip['posting_date'] ?? '';
    $subject = "Your Salary Slip - " . $postingDate;
    $message = " <p>Please find attached your salary slip.</p> <p>Thank you for your commitment and contribution to Diva Cakes.</p> <p>Regards,<br>Human Resources<br>Diva Cakes</p> ";
    $sent = $emailService->sendReportPdf($email, $employeeName, $subject, $message, $pdf, "Salary_Slip_" . $employeeId . ".pdf", "it", "hradminmgr@divacakes.com.ng", "Diva Cakes Payroll");
    if ($sent) {
        saveSalarySlipLog($connection, $salarySlip, $payrollEntry, $employeeId, $email);
        $sentCount++;
        echo "Email sent successfully<br>";
        logSalary("Sent: " . $employeeName . " - " . $email);
        sleep(2);
    } else {
        $failedCount++;
        $error = $emailService->getLastError();
        echo "Email failed: " . htmlspecialchars($error) . "<br>";
        logSalary("Failed: " . $employeeName . " - " . $error);
    }
}
echo "<br>Batch completed";
echo "<br>Processed: " . $processed;
echo "<br>Sent: " . $sentCount;
echo "<br>Failed: " . $failedCount;
logSalary("Batch completed - Payroll: {$payrollEntry}, " . "Processed: {$processed}, Sent: {$sentCount}, Failed: {$failedCount}"); ?>