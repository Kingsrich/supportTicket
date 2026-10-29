<?php

require __DIR__ . '/inc/erpnext_config.php';


$salarySlip = "Sal Slip/DC-EMP-00555/00001";


$url = rtrim(ERPNEXT_BASE_URL, "/")
    . "/api/method/frappe.utils.print_format.download_pdf";


$ch = curl_init();


$postData = [

    "doctype" => "Salary Slip",

    "name" => $salarySlip,

    "format" => "Standard"

];


curl_setopt_array($ch, [

    CURLOPT_URL => $url,

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_POST => true,

    CURLOPT_POSTFIELDS => http_build_query($postData),

    CURLOPT_HTTPHEADER => [

        "Authorization: token " . ERPNEXT_API_KEY . ":" . ERPNEXT_API_SECRET

    ]

]);


$pdf = curl_exec($ch);


if (curl_errno($ch)) {

    die(curl_error($ch));

}


curl_close($ch);



file_put_contents(
    "test_salary_slip.pdf",
    $pdf
);


echo "PDF generated";

?>