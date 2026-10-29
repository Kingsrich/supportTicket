<?php

require __DIR__ . '/inc/connection.php';
require __DIR__ . '/inc/EmailService.php';
require __DIR__ . '/inc/erpnext_config.php';

$emailService = new EmailService($connection);
$logFile = __DIR__ . '/birthday_email.log';

function birthdayLog($message)
{
    global $logFile;
    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . " - " . $message . PHP_EOL,
        FILE_APPEND
    );
}

function birthdayAlreadySent($connection, $email, $date)
{
    $stmt = mysqli_prepare(
        $connection,
        "SELECT id FROM birthday_email_logs
         WHERE employee_email=? AND birthday_date=? LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $email, $date);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $exists;
}

function saveBirthdayEmailLog($connection, $name, $email, $date)
{
    $stmt = mysqli_prepare(
        $connection,
        "INSERT IGNORE INTO birthday_email_logs
        (employee_name,employee_email,birthday_date)
        VALUES(?,?,?)"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "sss", $name, $email, $date);
    $result = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    return $result;
}

function birthdayStaffAlertAlreadySent($connection, $email, $date)
{
    $stmt = mysqli_prepare(
        $connection,
        "SELECT id
         FROM birthday_staff_alert_logs
         WHERE employee_email=?
         AND alert_date=?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $email, $date);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $exists;
}

function saveBirthdayStaffAlertLog(
    $connection,
    $employeeEmail,
    $celebrants,
    $date
) {
    $stmt = mysqli_prepare(
        $connection,
        "INSERT IGNORE INTO birthday_staff_alert_logs
        (
            birthday_employee,
            employee_email,
            alert_date
        )
        VALUES (?, ?, ?)"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sss",
        $celebrants,
        $employeeEmail,
        $date
    );

    $result = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    return $result;
}

function formatCelebrantNames($names)
{
    $count = count($names);

    if ($count === 0) {
        return "";
    }

    if ($count === 1) {
        return $names[0];
    }

    if ($count === 2) {
        return $names[0] . " and " . $names[1];
    }

    $last = array_pop($names);

    return implode(", ", $names) . " and " . $last;
}

birthdayLog("Birthday checker started");

$today = date("m-d");
$logDate = date("Y-m-d");

// TESTING ONLY
// $today = "09-02";

$url = ERPNEXT_BASE_URL .
    "/api/resource/Employee?" .
    "filters=[[\"status\",\"=\",\"Active\"]]" .
    "&limit_page_length=1000" .
    "&fields=[\"name\",\"employee_name\",\"date_of_birth\",\"personal_email\",\"status\"]";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: token " . ERPNEXT_API_KEY . ":" . ERPNEXT_API_SECRET,
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    birthdayLog("ERPNext CURL Error: " . curl_error($ch));
    curl_close($ch);
    exit;
}

curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['data'])) {
    birthdayLog("Invalid ERPNext response");
    exit;
}

birthdayLog("Employees loaded: " . count($data['data']));

echo "<pre>";
print_r($data['data']);
echo "</pre>";
exit;

$todayCelebrants = [];
$celebrantEmails = [];

/*
 * FIND TODAY'S ACTIVE CELEBRANTS
 * AND SEND THEIR PERSONAL EMAILS
 */
foreach ($data['data'] as $employee) {

    // Inactive employees are completely ignored
    if (($employee['status'] ?? '') !== 'Active') {
        continue;
    }

    if (empty($employee['date_of_birth'])) {
        continue;
    }

    $birthday = date(
        "m-d",
        strtotime($employee['date_of_birth'])
    );

    // Not celebrating today
    if ($birthday !== $today) {
        continue;
    }

    // Only NOW add employee as today's celebrant
    $employeeName = trim($employee['employee_name'] ?? '');
    $employeeEmail = trim($employee['personal_email'] ?? '');

    if (empty($employeeName)) {
        continue;
    }

    $todayCelebrants[] = $employeeName;

    if (!empty($employeeEmail)) {
        $celebrantEmails[] = strtolower($employeeEmail);
    }

    if (empty($employeeEmail)) {
        birthdayLog("Skipped {$employeeName}: No email");
        continue;
    }

    if (
        birthdayAlreadySent(
            $connection,
            $employeeEmail,
            $logDate
        )
    ) {
        birthdayLog(
            "Skipped {$employeeName}: Birthday email already sent today"
        );

        continue;
    }

    $subject = "ðŸŽ‚ Happy Birthday {$employeeName}";

    $message = "
        <h2>Happy Birthday {$employeeName}! ðŸŽ‰</h2>

        <p>Dear {$employeeName},</p>

        <p>
        On behalf of Diva Cakes, we wish you a wonderful birthday.
        </p>

        <p>
        Thank you for your commitment, dedication and contribution
        to the growth of the organization.
        </p>

        <p>
        May your new year bring you success, happiness,
        good health and many achievements.
        </p>

        <p>
        Best Regards,<br>
        Human Resources<br>
        Diva Cakes
        </p>
    ";

    $sent = $emailService->sendNotification(
        $employeeEmail,
        $employeeName,
        $subject,
        $message,
        'hr'
    );

    if ($sent) {

        saveBirthdayEmailLog(
            $connection,
            $employeeName,
            $employeeEmail,
            $logDate
        );

        birthdayLog(
            "HR Birthday email sent successfully to {$employeeEmail}"
        );

    } else {

        birthdayLog(
            "Birthday email failed for {$employeeEmail}: " .
            $emailService->getLastError()
        );
    }
}

/*
 * SEND ONE COMBINED ANNOUNCEMENT
 * TO OTHER ACTIVE EMPLOYEES
 */
if (!empty($todayCelebrants)) {

    $celebrantsList = formatCelebrantNames($todayCelebrants);

    birthdayLog(
        "Today's celebrants: " . $celebrantsList
    );
    $teamSubject =
        "ðŸŽ‰ Let's Celebrate {$celebrantsList} Today!";

    $teamMessage = "
            <h2>ðŸŽ‰ Birthday Celebration</h2>

            <p>Dear Team,</p>

            <p>
            Today we celebrate the birthdays of our colleagues:
            </p>

            <h3>ðŸŽ‚ {$celebrantsList}</h3>

            <p>
            Please join us in wishing them a happy birthday
            and a wonderful year ahead.
            </p>

            <p>
            Your kind words, prayers and good wishes
            will help make their day special.
            </p>

            <p>
            Best Regards,<br>
            Human Resources<br>
            Diva Cakes
            </p>
        ";

    $teamSent = 0;
    $teamFailed = 0;

    foreach ($data['data'] as $staff) {

        // NEVER send to inactive employees
        if (($staff['status'] ?? '') !== 'Active') {
            continue;
        }

        $staffName = trim(
            $staff['employee_name'] ?? ''
        );

        $staffEmail = trim(
            $staff['personal_email'] ?? ''
        );

        if (empty($staffEmail)) {
            continue;
        }

        // Do not send team alert to any celebrant
        if (
            in_array(
                strtolower($staffEmail),
                $celebrantEmails,
                true
            )
        ) {
            continue;
        }

        if (
            birthdayStaffAlertAlreadySent(
                $connection,
                $staffEmail,
                $logDate
            )
        ) {
            birthdayLog(
                "Skipped {$staffEmail}: Birthday team alert already sent today"
            );

            continue;
        }

        $sent = $emailService->sendNotification(
            $staffEmail,
            $staffName,
            $teamSubject,
            $teamMessage,
            'hr'
        );

        if ($sent) {
            saveBirthdayStaffAlertLog(
                $connection,
                $staffEmail,
                $celebrantsList,
                $logDate
            );

            $teamSent++;

            birthdayLog(
                "Birthday team alert sent successfully to {$staffEmail}"
            );

        } else {

            $teamFailed++;

            birthdayLog(
                "Birthday team alert failed for {$staffEmail}: " .
                $emailService->getLastError()
            );
        }
    }
}
birthdayLog("Birthday checker completed");
?>