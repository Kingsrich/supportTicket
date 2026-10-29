<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function renderWeeklyReportHtml(array $report): string
{
    $statusBreakdown = json_decode($report['status_breakdown'] ?? '', true) ?: [];
    $categoryBreakdown = json_decode($report['category_breakdown'] ?? '', true) ?: [];
    $priorityBreakdown = json_decode($report['priority_breakdown'] ?? '', true) ?: [];
    $outstanding = json_decode($report['outstanding_snapshot'] ?? '', true) ?: [];
    $nextTargets = json_decode($report['next_week_targets'] ?? '', true) ?: [];
    $ticketRegister = json_decode($report['ticket_register_snapshot'] ?? '', true) ?: [];

    $weekLabel = date('M d', strtotime($report['week_start'])) . ' – ' . date('M d, Y', strtotime($report['week_end']));
    $generatedBy = h($report['generated_by_name'] ?? 'ICT Department');
    $generatedAt = h(date('M d, Y g:i A', strtotime($report['created_at'])));
    $partsCost = (float) ($report['total_parts_cost'] ?? 0);
    $vendorCost = (float) ($report['total_vendor_cost'] ?? 0);
    $totalCost = (float) ($report['total_support_cost'] ?? 0);

    // Simple derived signal for the exec summary strip — resolution rate colour cue.
    $slaValue = (float) ($report['sla_percent'] ?? 0);
    $slaTone = $slaValue >= 90 ? '#0f7a4f' : ($slaValue >= 75 ? '#b7791f' : '#a3231f');

    ob_start();
    ?>
    <html>

    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 28px 30px 46px 30px;
            }

            * {
                box-sizing: border-box;
            }

            body {
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                font-size: 11.8px;
                color: #1e2733;
                line-height: 1.45;
            }

            h1 {
                font-family: Georgia, 'Times New Roman', serif;
                font-size: 25px;
                font-weight: normal;
                letter-spacing: 0.01em;
                margin: 0;
                color: #10192b;
            }

            .sub {
                color: #64748b;
                font-size: 10.8px;
                margin-bottom: 16px;
            }

            /* ---------- Executive letterhead header ---------- */
            .report-header {
                width: 100%;
                border-bottom: 2.5px solid #10192b;
                padding-bottom: 12px;
                margin-bottom: 18px;
            }

            .report-header table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }

            .report-header td {
                border: none;
                padding: 0;
                vertical-align: bottom;
            }

            .eyebrow {
                font-size: 9px;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: #a3852f;
                font-weight: bold;
                margin-bottom: 4px;
            }

            .header-meta {
                text-align: right;
                font-size: 10px;
                color: #64748b;
            }

            .header-meta .week-range {
                font-size: 13px;
                color: #10192b;
                font-weight: bold;
                margin-bottom: 3px;
            }

            .confidential-tag {
                display: inline-block;
                margin-top: 6px;
                padding: 2px 8px;
                border: 1px solid #cbd5e1;
                border-radius: 3px;
                font-size: 8.5px;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #64748b;
            }

            /* ---------- Section titles ---------- */
            .section-title {
                font-size: 11.2px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: #10192b;
                border-bottom: 1px solid #dbe1ea;
                padding-bottom: 5px;
                margin: 18px 0 9px;
            }

            /* ---------- Executive summary callout ---------- */
            .exec-summary-box {
                background: #f8f7f2;
                border-left: 3px solid #a3852f;
                padding: 12px 14px;
                margin-bottom: 4px;
                font-size: 12px;
                color: #2b3446;
            }

            /* ---------- Base tables ---------- */
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 6px;
            }

            th,
            td {
                border-bottom: 1px solid #eef2f7;
                padding: 7px 8px;
                font-size: 11px;
                text-align: left;
            }

            th {
                background: #10192b;
                color: #f4f6f9;
                font-size: 9.6px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                font-weight: bold;
            }

            tr:nth-child(even) td {
                background: #fafbfc;
            }

            /* ---------- KPI strip ---------- */
            .kpi-table {
                border: none;
            }

            .kpi-table td {
                text-align: center;
                border: 1px solid #dbe1ea;
                background: #ffffff;
                padding: 12px 6px 10px;
            }

            .kpi-table tr td:first-child {
                border-left: 3px solid #10192b;
            }

            .kpi-table .num {
                font-size: 19px;
                font-weight: bold;
                color: #10192b;
                font-family: Georgia, 'Times New Roman', serif;
            }

            .kpi-table .label {
                font-size: 9px;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-top: 2px;
            }

            /* ---------- Financial impact ---------- */
            .finance-table th {
                background: #a3852f;
                color: #fff;
            }

            .finance-table td {
                font-size: 13px;
                text-align: left;
                padding: 12px 8px;
            }

            .finance-table strong {
                color: #10192b;
                font-size: 15px;
            }

            /* ---------- Footer ---------- */
            .footer {
                margin-top: 22px;
                padding-top: 8px;
                border-top: 1px solid #dbe1ea;
                font-size: 9px;
                color: #94a3b8;
                text-align: center;
            }

            /* ---------- Badges ---------- */
            .badge {
                display: inline-block;
                padding: 2px 9px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
                letter-spacing: 0.02em;
            }

            .priority-low {
                background: #f1f5f9;
                color: #475569;
            }

            .priority-medium {
                background: #dbeafe;
                color: #1d4ed8;
            }

            .priority-high {
                background: #ffedd5;
                color: #c2410c;
            }

            .priority-critical {
                background: #fee2e2;
                color: #991b1b;
            }

            .status-new {
                background: #e0e7ff;
                color: #4338ca;
            }

            .status-open {
                background: #dbeafe;
                color: #1d4ed8;
            }

            .status-assigned {
                background: #ede9fe;
                color: #6d28d9;
            }

            .status-in-progress {
                background: #fef9c3;
                color: #854d0e;
            }

            .status-waiting-for-user {
                background: #ffedd5;
                color: #c2410c;
            }

            .status-pending-vendor {
                background: #fce7f3;
                color: #a21caf;
            }

            .status-resolved {
                background: #dcfce7;
                color: #166534;
            }

            .status-closed {
                background: #f1f5f9;
                color: #475569;
            }

            .status-cancelled {
                background: #fee2e2;
                color: #991b1b;
            }

            .description-cell {
                white-space: normal;
                overflow-wrap: anywhere;
                word-break: break-word;
                line-height: 1.5;
                min-width: 150px;
            }

            .ticket-subject-cell {
                min-width: 120px;
                white-space: normal;
                overflow-wrap: anywhere;
            }

            .outlet-cell {
                min-width: 90px;
                white-space: normal;
                overflow-wrap: anywhere;
            }

            /* ---------- Two-column layout ---------- */
            .col-layout {
                width: 100%;
            }

            .col-layout td {
                vertical-align: top;
                width: 50%;
                padding-right: 12px;
                border: none;
            }

            .col-layout td:last-child {
                padding-right: 0;
                padding-left: 12px;
            }

            .col-layout table th,
            .col-layout table td {
                border-bottom: 1px solid #eef2f7;
            }
        </style>
    </head>

    <body>
        <div class="report-header">
            <table>
                <tr>
                    <td>
                        <div class="eyebrow">Executive Briefing</div>
                        <h1>Weekly IT Support Report</h1>
                    </td>
                    <td class="header-meta">
                        <div class="week-range"><?= h($weekLabel) ?></div>
                        Prepared by <?= $generatedBy ?><br>
                        Generated <?= $generatedAt ?>
                        <div><span class="confidential-tag">Confidential &middot; Management Use Only</span></div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section-title">Executive Summary</div>
        <div class="exec-summary-box"><?= strip_tags($report['summary_text'], '<strong><b><em><i>') ?></div>

        <div class="section-title">Key Metrics</div>
        <table class="kpi-table">
            <tr>
                <td>
                    <div class="num"><?= (int) $report['total_raised'] ?></div>
                    <div class="label">Raised</div>
                </td>
                <td>
                    <div class="num"><?= (int) $report['total_resolved'] ?></div>
                    <div class="label">Resolved</div>
                </td>
                <td>
                    <div class="num"><?= (int) $report['total_pending'] ?></div>
                    <div class="label">Pending</div>
                </td>
                <td>
                    <div class="num"><?= (int) $report['total_critical'] ?></div>
                    <div class="label">Critical</div>
                </td>
                <td>
                    <div class="num"><?= h($report['avg_response_hours']) ?>h</div>
                    <div class="label">Avg Response</div>
                </td>
                <td>
                    <div class="num" style="color: <?= $slaTone ?>;"><?= h($report['sla_percent']) ?>%</div>
                    <div class="label">Completion Rate</div>
                </td>
            </tr>
        </table>

        <div class="section-title">Financial Impact</div>
        <table class="finance-table">
            <tr>
                <th>Parts / Materials</th>
                <th>External Vendor</th>
                <th>Total IT Support Cost</th>
            </tr>
            <tr>
                <td>₦<?= number_format($partsCost, 2) ?></td>
                <td>₦<?= number_format($vendorCost, 2) ?></td>
                <td><strong>₦<?= number_format($totalCost, 2) ?></strong></td>
            </tr>
        </table>

        <table class="col-layout">
            <tr>
                <td>
                    <div class="section-title">By Category</div>
                    <table>
                        <tr>
                            <th>Category</th>
                            <th>Count</th>
                        </tr>
                        <?php if (count($categoryBreakdown)):
                            foreach ($categoryBreakdown as $cat => $count): ?>
                                <tr>
                                    <td><?= h($cat) ?></td>
                                    <td><?= (int) $count ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="2">No tickets this week.</td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </td>
                <td>
                    <div class="section-title">By Priority</div>
                    <table>
                        <tr>
                            <th>Priority</th>
                            <th>Count</th>
                        </tr>
                        <?php foreach ($priorityBreakdown as $pr => $count): ?>
                            <tr>
                                <td><span class="badge <?= priorityBadgeClass($pr) ?>"><?= h($pr) ?></span></td>
                                <td><?= (int) $count ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </td>
            </tr>
        </table>

        <div class="section-title">Weekly Ticket Register</div>
        <table>
            <tr>
                <th>#</th>
                <th>Ticket</th>
                <th>Date</th>
                <th>Outlet</th>
                <th>Subject</th>
                <th>Issue Description</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Resolution</th>
                <th>Parts Cost</th>
                <th>Vendor Cost</th>
                <th>Total Cost</th>
            </tr>
            <?php if (count($ticketRegister)):
                $n = 1;
                foreach ($ticketRegister as $tr): ?>
                    <tr>
                        <td><?= $n++ ?></td>
                        <td><strong><?= h($tr['ticket_number'] ?? '') ?></strong></td>
                        <td><?= !empty($tr['created_at']) ? h(date('d M', strtotime($tr['created_at']))) : '—' ?></td>
                        <td class="outlet-cell"><?= h($tr['location_office'] ?? '—') ?></td>
                        <td class="ticket-subject-cell"><?= h($tr['subject'] ?? '—') ?></td>
                        <td class="description-cell"><?= nl2br(h($tr['description'] ?? '—')) ?></td>
                        <td><span
                                class="badge <?= priorityBadgeClass($tr['priority'] ?? 'Medium') ?>"><?= h($tr['priority'] ?? '—') ?></span>
                        </td>
                        <td><span class="badge <?= statusBadgeClass($tr['status'] ?? '') ?>"><?= h($tr['status'] ?? '—') ?></span>
                        </td>
                        <td><?= h(formatDuration($tr['resolution_minutes'] ?? 0)) ?></td>
                        <td>₦<?= number_format((float) ($tr['parts_cost'] ?? 0), 2) ?></td>
                        <td>₦<?= number_format((float) ($tr['vendor_cost'] ?? 0), 2) ?></td>
                        <td><strong>₦<?= number_format((float) ($tr['total_cost'] ?? 0), 2) ?></strong></td>
                    </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="12">No tickets raised this week.</td>
                </tr>
            <?php endif; ?>
        </table>

        <div class="section-title">Outstanding Tickets</div>
        <table>
            <tr>
                <th>#</th>
                <th>Ticket</th>
                <th>Outlet</th>
                <th>Subject</th>
                <th>Issue Description</th>
                <th>Status</th>
                <th>Reason Pending</th>
                <th>Next Action</th>
            </tr>
            <?php if (count($outstanding)):
                $n = 1;
                foreach ($outstanding as $o): ?>
                    <tr>
                        <td><?= $n++ ?></td>
                        <td><strong><?= h($o['ticket_number'] ?? '') ?></strong></td>
                        <td class="outlet-cell"><?= h($o['location_office'] ?? '—') ?></td>
                        <td class="ticket-subject-cell"><?= h($o['subject'] ?? '—') ?></td>
                        <td class="description-cell"><?= nl2br(h($o['description'] ?? '—')) ?></td>
                        <td><span class="badge <?= statusBadgeClass($o['status'] ?? '') ?>"><?= h($o['status'] ?? '—') ?></span>
                        </td>
                        <td class="description-cell"><?= h($o['reason'] ?? 'Not provided') ?></td>
                        <td class="description-cell"><?= h($o['next_action'] ?? 'Not defined') ?></td>
                    </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="8">No outstanding tickets — clean backlog.</td>
                </tr>
            <?php endif; ?>
        </table>

        <div class="section-title">Targets for Next Week</div>

        <table>
            <tr>
                <th>Ticket / Location</th>
                <th>Task / Action</th>
                <th>Reason Carried Forward</th>
                <th>Target Date</th>
            </tr>

            <?php if ($nextTargets): ?>
                <?php foreach ($nextTargets as $t): ?>
                    <tr>
                        <td>
                            <strong><?= h($t['ticket_number'] ?? '') ?></strong><br>
                            <small><?= h($t['subject'] ?? '') ?></small><br><br>

                            <strong>Company:</strong><br>
                            <?= h($t['company'] ?? '—') ?><br><br>

                            <strong>Outlet:</strong><br>
                            <?= h($t['outlet'] ?? '—') ?>
                        </td>

                        <td class="description-cell">
                            <?= h($t['next_action'] ?? 'Follow up and resolve') ?>
                        </td>

                        <td class="description-cell">
                            <?= h($t['reason'] ?? 'Not provided') ?>
                        </td>

                        <td>
                            <?= !empty($t['target_date'])
                                ? h(date('d M Y', strtotime($t['target_date'])))
                                : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No tasks carried forward to next week.</td>
                </tr>
            <?php endif; ?>
        </table>

        <?php if (!empty($report['recommendations'])): ?>
            <div class="section-title">Recommendations</div>
            <ul>
                <?php foreach (preg_split('/\r\n|\r|\n/', trim($report['recommendations'])) as $line): ?>
                    <?php if (trim($line) !== ''): ?>
                        <li><?= h($line) ?></li><?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="footer">Prepared by ICT Department &middot; Confidential – Management Use Only</div>
    </body>

    </html>
    <?php
    return ob_get_clean();
}

function buildWeeklyReportPdf(array $report): string
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(renderWeeklyReportHtml($report));
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    return $dompdf->output();
}