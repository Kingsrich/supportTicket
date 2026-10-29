<?php

use Dompdf\Dompdf;
use Dompdf\Options;


/**
 * Escape HTML for the email / PDF report helper.
 */
function facilityReportHtmlEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * Render the saved Facility weekly report as standalone HTML.
 */
function renderFacilityWeeklyReportHtml(array $report): string
{
    $statusBreakdown =
        json_decode(
            $report['status_breakdown']
            ?? '',
            true
        ) ?: [];

    $typeBreakdown =
        json_decode(
            $report['request_type_breakdown']
            ?? '',
            true
        ) ?: [];

    $priorityBreakdown =
        json_decode(
            $report['priority_breakdown']
            ?? '',
            true
        ) ?: [];

    $outstanding =
        json_decode(
            $report['outstanding_snapshot']
            ?? '',
            true
        ) ?: [];

    $failingAssets =
        json_decode(
            $report['failing_assets_snapshot']
            ?? '',
            true
        ) ?: [];

    $technicians =
        json_decode(
            $report['technician_performance_snapshot']
            ?? '',
            true
        ) ?: [];

    $vendors =
        json_decode(
            $report['vendor_performance_snapshot']
            ?? '',
            true
        ) ?: [];

    $register =
        json_decode(
            $report['maintenance_register_snapshot']
            ?? '',
            true
        ) ?: [];


    $weekLabel =
        date(
            'M d',
            strtotime(
                $report['week_start']
            )
        )
        . ' – '
        . date(
            'M d, Y',
            strtotime(
                $report['week_end']
            )
        );


    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: DejaVu Sans, Arial, sans-serif;
                color: #1f2937;
                font-size: 11px;
                line-height: 1.45;
            }

            h1 {
                color: #431407;
                font-size: 22px;
                margin: 0 0 5px;
            }

            h2 {
                color: #111827;
                font-size: 14px;
                margin: 22px 0 8px;
                padding-bottom: 5px;
                border-bottom: 1px solid #e5e7eb;
            }

            .muted {
                color: #64748b;
            }

            .summary {
                padding: 12px;
                background: #fff7ed;
                border-left: 3px solid #9a3412;
            }

            .metrics {
                width: 100%;
                border-collapse: separate;
                border-spacing: 6px;
                margin: 12px 0;
            }

            .metrics td {
                padding: 10px;
                background: #f8fafc;
                border: 1px solid #e5e7eb;
                text-align: center;
            }

            .metric-number {
                display: block;
                color: #111827;
                font-size: 16px;
                font-weight: bold;
            }

            .metric-label {
                display: block;
                color: #64748b;
                font-size: 9px;
                margin-top: 2px;
            }

            table.data {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0 18px;
            }

            table.data th {
                background: #f8fafc;
                color: #475569;
                font-size: 8px;
                text-transform: uppercase;
                padding: 6px;
                border: 1px solid #e5e7eb;
                text-align: left;
            }

            table.data td {
                padding: 6px;
                border: 1px solid #e5e7eb;
                vertical-align: top;
            }

            .footer {
                margin-top: 25px;
                padding-top: 8px;
                border-top: 1px solid #e5e7eb;
                color: #94a3b8;
                font-size: 8px;
                text-align: center;
            }
        </style>
    </head>
    <body>';

    $html .= '
        <h1>Weekly Facility Maintenance Report</h1>

        <div class="muted">
            ' . facilityReportHtmlEscape($weekLabel) . '
            &middot; Prepared by
            ' . facilityReportHtmlEscape(
                $report['generated_by_name']
                ?? 'Facility Department'
            ) . '
        </div>

        <h2>Executive Summary</h2>

        <div class="summary">'
            . strip_tags(
                $report['summary_text']
                ?? '',
                '<strong><em><br>'
            )
        . '</div>';


    $html .= '
        <h2>Key Metrics</h2>

        <table class="metrics">
            <tr>
                <td>
                    <span class="metric-number">'
                    . (int) $report['total_raised']
                    . '</span>
                    <span class="metric-label">Raised</span>
                </td>

                <td>
                    <span class="metric-number">'
                    . (int) $report['total_closed']
                    . '</span>
                    <span class="metric-label">Closed</span>
                </td>

                <td>
                    <span class="metric-number">'
                    . (int) $report['total_pending']
                    . '</span>
                    <span class="metric-label">Pending</span>
                </td>

                <td>
                    <span class="metric-number">'
                    . (int) $report['total_critical']
                    . '</span>
                    <span class="metric-label">Critical</span>
                </td>
            </tr>

            <tr>
                <td>
                    <span class="metric-number">'
                    . facilityReportHtmlEscape(
                        $report['avg_response_hours']
                    )
                    . 'h</span>
                    <span class="metric-label">Avg Response</span>
                </td>

                <td>
                    <span class="metric-number">'
                    . facilityReportHtmlEscape(
                        $report['avg_resolution_hours']
                    )
                    . 'h</span>
                    <span class="metric-label">Avg Resolution</span>
                </td>

                <td>
                    <span class="metric-number">'
                    . facilityReportHtmlEscape(
                        $report['downtime_hours']
                    )
                    . 'h</span>
                    <span class="metric-label">Downtime</span>
                </td>

                <td>
                    <span class="metric-number">'
                    . facilityReportHtmlEscape(
                        $report['pm_compliance']
                    )
                    . '%</span>
                    <span class="metric-label">PM Compliance</span>
                </td>
            </tr>
        </table>

        <p>
            <strong>Total Maintenance Cost:</strong>
            ₦'
            . number_format(
                (float) $report['total_cost'],
                2
            )
            . '
        </p>';


    if (!empty($statusBreakdown)) {

        $html .= '
            <h2>Request Status Overview</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>';

        foreach (
            $statusBreakdown
            as $status => $count
        ) {
            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape($status)
                    . '</td>
                    <td>'
                    . (int) $count
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($typeBreakdown)) {

        $html .= '
            <h2>Request Type Breakdown</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Request Type</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>';

        foreach (
            $typeBreakdown
            as $type => $count
        ) {
            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape(
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                $type
                            )
                        )
                    )
                    . '</td>
                    <td>'
                    . (int) $count
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($outstanding)) {

        $html .= '
            <h2>Outstanding Maintenance Requests</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Outlet</th>
                        <th>Asset</th>
                        <th>Priority</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($outstanding as $item) {

            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape(
                        $item['request_number']
                    )
                    . '<br>'
                    . facilityReportHtmlEscape(
                        $item['subject']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $item['warehouse_name']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $item['asset_code']
                        ?: 'General Facility'
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $item['priority']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $item['status']
                    )
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($failingAssets)) {

        $html .= '
            <h2>Frequently Failing Assets — 90 Day Snapshot</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Asset Tag / ID</th>
                        <th>Asset</th>
                        <th>Outlet</th>
                        <th>Failures</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($failingAssets as $asset) {

            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape(
                        $asset['asset_code']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $asset['asset_name']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $asset['warehouse_name']
                    )
                    . '</td>

                    <td>'
                    . (int) $asset['failures']
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($technicians)) {

        $html .= '
            <h2>Technician Productivity</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Technician</th>
                        <th>Assigned</th>
                        <th>Completed</th>
                        <th>Avg Resolution (min)</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($technicians as $tech) {

            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape(
                        $tech['fullname']
                    )
                    . '</td>

                    <td>'
                    . (int) $tech['assigned']
                    . '</td>

                    <td>'
                    . (int) $tech['completed']
                    . '</td>

                    <td>'
                    . round(
                        (float) (
                            $tech['avg_resolution_minutes']
                            ?? 0
                        ),
                        1
                    )
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($vendors)) {

        $html .= '
            <h2>Vendor Performance</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Jobs</th>
                        <th>Completed</th>
                        <th>Vendor Cost</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($vendors as $vendor) {

            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape(
                        $vendor['vendor_name']
                    )
                    . '</td>

                    <td>'
                    . (int) $vendor['jobs']
                    . '</td>

                    <td>'
                    . (int) $vendor['completed']
                    . '</td>

                    <td>₦'
                    . number_format(
                        (float) $vendor['total_cost'],
                        2
                    )
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($register)) {

        $html .= '
            <h2>Weekly Maintenance Register</h2>

            <table class="data">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Date</th>
                        <th>Outlet</th>
                        <th>Asset</th>
                        <th>Subject</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Cost</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($register as $entry) {

            $html .= '
                <tr>
                    <td>'
                    . facilityReportHtmlEscape(
                        $entry['request_number']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        date(
                            'd M',
                            strtotime(
                                $entry['created_at']
                            )
                        )
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $entry['warehouse_name']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $entry['asset_code']
                        ?: 'General'
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $entry['subject']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $entry['priority']
                    )
                    . '</td>

                    <td>'
                    . facilityReportHtmlEscape(
                        $entry['status']
                    )
                    . '</td>

                    <td>₦'
                    . number_format(
                        (float) $entry['total_cost'],
                        2
                    )
                    . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>';
    }


    if (!empty($report['recommendations'])) {

        $html .= '
            <h2>Recommendations</h2>
            <ul>';

        foreach (
            preg_split(
                '/\r\n|\r|\n/',
                trim(
                    $report['recommendations']
                )
            )
            as $line
        ) {
            if (trim($line) !== '') {
                $html .= '
                    <li>'
                    . facilityReportHtmlEscape($line)
                    . '</li>';
            }
        }

        $html .= '</ul>';
    }


    $html .= '
        <div class="footer">
            Prepared by Facility & Projects Department
            &middot;
            Confidential – Management Use Only
        </div>

    </body>
    </html>';

    return $html;
}


/**
 * Build PDF bytes for EmailService::sendReportPdf().
 */
function buildFacilityWeeklyReportPdf(array $report): string
{
    if (!class_exists(Dompdf::class)) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    $options =
        new Options();

    $options->set(
        'isRemoteEnabled',
        true
    );

    $dompdf =
        new Dompdf($options);

    $dompdf->loadHtml(
        renderFacilityWeeklyReportHtml(
            $report
        )
    );

    $dompdf->setPaper(
        'A4',
        'landscape'
    );

    $dompdf->render();

    return $dompdf->output();
}
