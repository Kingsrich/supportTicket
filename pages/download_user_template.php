<?php
require '../inc/auth.php';

$filename = "user_bulk_upload_template.csv";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

$output = fopen("php://output", "w");

/*
|--------------------------------------------------------------------------
| CSV HEADERS
|--------------------------------------------------------------------------
*/
fputcsv($output, [
    "employee_id",
    "fullname",
    "email",
    "phone",
    "company",
    "department",
    "designation",
    "line_manager_email",
    "role",
    "status",
    "requires_weekly_report"
]);

/*
|--------------------------------------------------------------------------
| SAMPLE ROW 1
|--------------------------------------------------------------------------
| Regular reporting staff
*/
fputcsv($output, [
    "EMP001",
    "John Doe",
    "john.doe@example.com",
    "08012345678",
    "Diva Cakes & Confections Ltd",
    "Sales",
    "Sales Executive",
    "jane.manager@example.com",
    "staff",
    "active",
    "yes"
]);

/*
|--------------------------------------------------------------------------
| SAMPLE ROW 2
|--------------------------------------------------------------------------
| Department manager
*/
fputcsv($output, [
    "EMP002",
    "Jane Manager",
    "jane.manager@example.com",
    "08098765432",
    "Diva Cakes & Confections Ltd",
    "Production",
    "Production Manager",
    "",
    "manager",
    "active",
    "yes"
]);

/*
| SAMPLE ROW 3
*/
fputcsv($output, [
    "EMP003",
    "Office Cleaner",
    "cleaner@example.com",
    "08022223333",
    "Diva Cakes & Confections Ltd",
    "Operations",
    "Cleaner",
    "jane.manager@example.com",
    "staff",
    "active",
    "no"
]);

fclose($output);
exit;