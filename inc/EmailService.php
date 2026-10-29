<?php

/** * EmailService * * Sends email through Microsoft Graph API using app-only OAuth2. * * Supports: * - Separate IT and Facility sender mailboxes * - Microsoft Graph token caching * - HTML email * - PDF / file attachments * - Delivery logging * - Detailed Microsoft Graph failure reasons * - Manual retry of failed emails * * Database: * - email_settings * - email_delivery_logs */ class EmailService
{
    private $connection;
    private $config;
    private $tokenCacheFile;
    private $lastError = '';
    private $lastHttpCode = null;
    private $lastGraphResponse = null; /* ============================================================ CONSTRUCTOR ============================================================ */
    public function __construct($connection)
    {
        $this->connection = $connection;
        $this->config = $this->loadConfig();
        /* * Separate cache per Microsoft application. * Prevents a stale token from another configuration * being reused after credentials change. */
        $cacheKey = 'default';
        if ($this->config) {
            $cacheKey = md5(($this->config['tenant_id'] ?? '') . '|' . ($this->config['client_id'] ?? ''));
        }
        $this->tokenCacheFile = sys_get_temp_dir() . '/ms365_token_cache_' . $cacheKey . '.json';
    } /* ============================================================ LOAD CONFIGURATION ============================================================ */
    private function loadConfig()
    {
        if ($this->connection) {
            $result = @mysqli_query($this->connection, " SELECT * FROM email_settings ORDER BY id DESC LIMIT 1 ");
            if ($result && ($row = mysqli_fetch_assoc($result))) {
                if (!empty($row['tenant_id']) && !empty($row['client_id']) && !empty($row['client_secret']) && !empty($row['primary_mailbox']) && (!empty($row['it_sender_email']) || !empty($row['facility_sender_email']) || !empty($row['hr_sender_email']))) {
                    return [
                        'tenant_id' => trim($row['tenant_id']),
                        'client_id' => trim($row['client_id']),
                        'client_secret' => trim($row['client_secret']),
                        'primary_mailbox' => trim($row['primary_mailbox'] ?? ''),

                        /* IT */
                        'it_sender_email' => trim($row['it_sender_email'] ?? ''),
                        'it_sender_name' => trim($row['it_sender_name'] ?? '') ?: 'Diva Cakes IT Support',

                        /* FACILITY */
                        'facility_sender_email' => trim($row['facility_sender_email'] ?? ''),
                        'facility_sender_name' => trim($row['facility_sender_name'] ?? '') ?: 'Diva Cakes Facility & Projects',

                        /* HR */
                        'hr_sender_email' => trim($row['hr_sender_email'] ?? ''),
                        'hr_sender_name' => trim($row['hr_sender_name'] ?? '') ?: 'Human Resources - Diva Cakes',

                        'it_primary_mailbox' => trim($row['it_primary_mailbox'] ?? ''),
                        'facility_primary_mailbox' => trim($row['facility_primary_mailbox'] ?? ''),
                        'hr_primary_mailbox' => trim($row['hr_primary_mailbox'] ?? ''),

                    ];
                }
            }
        } /* * Optional fallback config file. */
        $fileConfig = __DIR__ . '/mail_config.php';
        if (file_exists($fileConfig)) {
            $config = require $fileConfig;
            if (is_array($config)) { /* * Backward compatibility for an older * single-sender mail_config.php. */
                if (empty($config['it_sender_email']) && !empty($config['sender_email'])) {
                    $config['it_sender_email'] = $config['sender_email'];
                }
                if (empty($config['it_sender_name']) && !empty($config['sender_name'])) {
                    $config['it_sender_name'] = $config['sender_name'];
                }
                return $config;
            }
        }
        return null;
    } /* ============================================================ GET SENDER ============================================================ */
    private function getSenderForChannel(string $channel): array
    {
        if ($channel === 'facility') {
            return ['channel' => 'facility', 'email' => trim($this->config['facility_sender_email'] ?? ''), 'name' => trim($this->config['facility_sender_name'] ?? '') ?: 'Diva Cakes Facility & Projects'];
        }
        if ($channel === 'hr') {

            return [
                'channel' => 'hr',
                'email' => trim($this->config['hr_sender_email'] ?? ''),
                'name' => trim($this->config['hr_sender_name'] ?? '')
                    ?: 'Human Resources - Diva Cakes'
            ];
        }
        return ['channel' => 'it', 'email' => trim($this->config['it_sender_email'] ?? ''), 'name' => trim($this->config['it_sender_name'] ?? '') ?: 'Diva Cakes IT Support'];
    } /* ============================================================ ACCESS TOKEN ============================================================ */
    private function getAccessToken()
    {
        if (!$this->config) {
            $this->lastError = 'Microsoft 365 email configuration is unavailable.';
            return null;
        } /* -------------------------------------------------------- CACHED TOKEN -------------------------------------------------------- */
        if (file_exists($this->tokenCacheFile)) {
            $cached = json_decode(file_get_contents($this->tokenCacheFile), true);
            if ($cached && !empty($cached['access_token']) && isset($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
                return $cached['access_token'];
            }
        } /* -------------------------------------------------------- REQUEST NEW TOKEN -------------------------------------------------------- */
        $tenantId = trim($this->config['tenant_id'] ?? '');
        $clientId = trim($this->config['client_id'] ?? '');
        $clientSecret = trim($this->config['client_secret'] ?? '');
        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            $this->lastError = 'Microsoft 365 Tenant ID, Client ID or Client Secret is missing.';
            return null;
        }
        $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
        $postFields = http_build_query(['client_id' => $clientId, 'client_secret' => $clientSecret, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postFields, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $this->lastHttpCode = $httpCode;
        $this->lastGraphResponse = (string) $response; /* -------------------------------------------------------- TOKEN ERROR -------------------------------------------------------- */
        if ($curlError !== '' || $httpCode !== 200) {
            $decoded = json_decode((string) $response, true);
            $errorDescription = '';
            if (is_array($decoded)) {
                $errorDescription = $decoded['error_description'] ?? $decoded['error'] ?? '';
            }
            if ($curlError !== '') {
                $this->lastError = 'Microsoft authentication network error: ' . $curlError;
            } elseif ($errorDescription !== '') {
                $this->lastError = 'Microsoft authentication failed: ' . $errorDescription;
            } else {
                $this->lastError = "Microsoft authentication failed with HTTP {$httpCode}.";
            }
            error_log('EmailService token error: ' . $this->lastError);
            return null;
        }
        $data = json_decode((string) $response, true);
        if (empty($data['access_token'])) {
            $this->lastError = 'Microsoft authentication response did not contain an access token.';
            error_log('EmailService token error: ' . $this->lastError);
            return null;
        } /* -------------------------------------------------------- SAVE TOKEN CACHE -------------------------------------------------------- */
        @file_put_contents($this->tokenCacheFile, json_encode(['access_token' => $data['access_token'], 'expires_at' => time() + (int) ($data['expires_in'] ?? 3300),]));
        return $data['access_token'];
    } /* ============================================================ ATTACHMENT STORAGE FOR RETRY ============================================================ */
    private function encodeAttachments(array $attachments): ?string
    {
        if (!$attachments) {
            return null;
        }
        $stored = [];
        foreach ($attachments as $attachment) {
            $stored[] = ['name' => $attachment['name'] ?? 'attachment', 'contentType' => $attachment['contentType'] ?? 'application/octet-stream', 'contentBase64' => base64_encode($attachment['content'] ?? ''),];
        }
        return json_encode($stored);
    }
    private function decodeAttachments(?string $payload): array
    {
        if (!$payload) {
            return [];
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }
        $attachments = [];
        foreach ($decoded as $attachment) {
            $attachments[] = ['name' => $attachment['name'] ?? 'attachment', 'contentType' => $attachment['contentType'] ?? 'application/octet-stream', 'content' => base64_decode($attachment['contentBase64'] ?? '', true) ?: '',];
        }
        return $attachments;
    } /* ============================================================ LOG DELIVERY ATTEMPT ============================================================ */
    private function logDelivery(string $channel, string $sender, string $senderName, string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments, string $status, ?string $failureReason, ?int $httpCode, ?string $graphResponse, ?int $retryOfId = null, ?int $retryBy = null): int
    {
        if (!$this->connection) {
            return 0;
        }
        $attachmentPayload = $this->encodeAttachments($attachments);
        $hasAttachments = count($attachments) > 0 ? 1 : 0;
        $attemptType = $retryOfId ? 'retry' : 'initial';
        $sql = " INSERT INTO email_delivery_logs ( channel, sender_email, sender_name, recipient_email, recipient_name, subject, body_html, attachment_payload, has_attachments, status, failure_reason, http_code, graph_response, attempt_type, retried_from_id, retry_triggered_by, sent_at ) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'sent' THEN NOW() ELSE NULL END ) ";
        $stmt = mysqli_prepare($this->connection, $sql);
        if (!$stmt) {
            error_log('EmailService logging prepare error: ' . mysqli_error($this->connection));
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'ssssssssississiis', $channel, $sender, $senderName, $toEmail, $toName, $subject, $htmlBody, $attachmentPayload, $hasAttachments, $status, $failureReason, $httpCode, $graphResponse, $attemptType, $retryOfId, $retryBy, $status);
        if (!mysqli_stmt_execute($stmt)) {
            error_log('EmailService logging execute error: ' . mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            return 0;
        }
        $logId = mysqli_insert_id($this->connection);
        mysqli_stmt_close($stmt);
        return $logId;
    } /* ============================================================ GRAPH FAILURE MESSAGE ============================================================ */
    private function extractGraphError(string $response, int $httpCode): string
    {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $message = trim($decoded['error']['message']);
            $code = trim($decoded['error']['code'] ?? '');
            if ($code !== '') {
                return "Microsoft Graph HTTP {$httpCode}: {$code} — {$message}";
            }
            return "Microsoft Graph HTTP {$httpCode}: {$message}";
        }
        return "Microsoft Graph returned HTTP {$httpCode}.";
    } /* ============================================================ SEND EMAIL ============================================================ */
    private function send($toEmail, $toName, $subject, $htmlBody, array $attachments = [], $channel = 'it', ?int $retryOfId = null, ?int $retryBy = null, ?string $senderOverride = null, ?string $senderNameOverride = null)
    {
        $this->lastError = '';
        $this->lastHttpCode = null;
        $this->lastGraphResponse = null;
        $toEmail = trim((string) $toEmail);
        $toName = trim((string) $toName);
        $subject = trim((string) $subject);
        $htmlBody = (string) $htmlBody;
        if ($toName === '') {
            $toName = 'Recipient';
        } /* -------------------------------------------------------- CONFIGURATION -------------------------------------------------------- */
        if (!$this->config) {
            $this->lastError = 'Microsoft 365 email configuration is unavailable.';
            $this->logDelivery($channel, '', '', $toEmail, $toName, $subject, $htmlBody, $attachments, 'failed', $this->lastError, null, null, $retryOfId, $retryBy);
            return false;
        } /* -------------------------------------------------------- SENDER -------------------------------------------------------- */
        $senderConfig = $this->getSenderForChannel($channel);
        $channel = $senderConfig['channel'];
        $sender = $senderConfig['email'];
        $senderName = $senderConfig['name'];

        if ($senderOverride && filter_var($senderOverride, FILTER_VALIDATE_EMAIL)) {
            $sender = trim($senderOverride);
            $senderName = trim($senderNameOverride ?: $senderOverride);
        }
        /* -------------------------------------------------------- RECIPIENT VALIDATION -------------------------------------------------------- */
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = "Invalid recipient email address: {$toEmail}";
            $this->logDelivery($channel, $sender, $senderName, $toEmail, $toName, $subject, $htmlBody, $attachments, 'failed', $this->lastError, null, null, $retryOfId, $retryBy);
            return false;
        } /* -------------------------------------------------------- SENDER VALIDATION -------------------------------------------------------- */
        if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
            if ($channel === 'facility') {
                $this->lastError =
                    'No valid Facility Support sender email is configured.';
            } elseif ($channel === 'hr') {
                $this->lastError =
                    'No valid HR sender email is configured.';
            } else {
                $this->lastError =
                    'No valid IT Support sender email is configured.';
            }
            $this->logDelivery($channel, $sender, $senderName, $toEmail, $toName, $subject, $htmlBody, $attachments, 'failed', $this->lastError, null, null, $retryOfId, $retryBy);
            return false;
        } /* -------------------------------------------------------- ACCESS TOKEN -------------------------------------------------------- */
        $token = $this->getAccessToken();
        if (!$token) {
            if ($this->lastError === '') {
                $this->lastError = 'Unable to obtain Microsoft Graph access token.';
            }
            $this->logDelivery($channel, $sender, $senderName, $toEmail, $toName, $subject, $htmlBody, $attachments, 'failed', $this->lastError, $this->lastHttpCode, $this->lastGraphResponse, $retryOfId, $retryBy);
            return false;
        } /* -------------------------------------------------------- GRAPH URL -------------------------------------------------------- */
        $senderConfig = $this->getSenderForChannel($channel);
        $channelKey = $senderConfig['channel']; // This will be 'it', 'facility', or 'hr'

        // Logic: Use the specific department primary mailbox if it exists,
        // otherwise fallback to the global primary mailbox, then finally the sender email.
        $deptMailbox = trim($this->config[$channelKey . '_primary_mailbox'] ?? '');
        $globalMailbox = trim($this->config['primary_mailbox'] ?? '');

        if ($deptMailbox !== '') {
            $primaryMailbox = $deptMailbox;
        } elseif ($globalMailbox !== '') {
            $primaryMailbox = $globalMailbox;
        } else {
            $primaryMailbox = $senderConfig['email'];
        }


        if ($primaryMailbox === '' || !filter_var($primaryMailbox, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Primary Microsoft 365 mailbox is not configured.';
            $this->logDelivery(
                $channel,
                $sender,
                $senderName,
                $toEmail,
                $toName,
                $subject,
                $htmlBody,
                $attachments,
                'failed',
                $this->lastError,
                null,
                null,
                $retryOfId,
                $retryBy
            );
            return false;
        }

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($primaryMailbox) . '/sendMail';
        /* -------------------------------------------------------- ATTACHMENTS -------------------------------------------------------- */
        $graphAttachments = [];
        foreach ($attachments as $attachment) {
            $graphAttachments[] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $attachment['name'] ?? 'attachment', 'contentType' => $attachment['contentType'] ?? 'application/octet-stream', 'contentBytes' => base64_encode($attachment['content'] ?? ''),];
        } /* -------------------------------------------------------- MESSAGE -------------------------------------------------------- */
        $message = [
            'subject' => $subject,

            'body' => [
                'contentType' => 'HTML',
                'content' => $htmlBody
            ],

            'toRecipients' => [
                [
                    'emailAddress' => [
                        'address' => $toEmail,
                        'name' => $toName
                    ]
                ]
            ],

            'from' => [
                'emailAddress' => [
                    'address' => $sender,
                    'name' => $senderName
                ]
            ],
        ];
        if ($graphAttachments) {
            $message['attachments'] = $graphAttachments;
        }
        $payload = ['message' => $message, 'saveToSentItems' => true]; /* -------------------------------------------------------- CURL -------------------------------------------------------- */
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $this->lastHttpCode = $httpCode;
        $this->lastGraphResponse = (string) $response; /* -------------------------------------------------------- FAILURE -------------------------------------------------------- */
        if ($curlError !== '' || $httpCode >= 300) {
            if ($curlError !== '') {
                $this->lastError = 'Network/CURL error: ' . $curlError;
            } else {
                $this->lastError = $this->extractGraphError((string) $response, $httpCode);
            }
            error_log('EmailService send error: ' . $this->lastError);
            $this->logDelivery($channel, $sender, $senderName, $toEmail, $toName, $subject, $htmlBody, $attachments, 'failed', $this->lastError, $httpCode, (string) $response, $retryOfId, $retryBy);
            return false;
        } /* -------------------------------------------------------- SUCCESS -------------------------------------------------------- */
        $this->logDelivery($channel, $sender, $senderName, $toEmail, $toName, $subject, $htmlBody, $attachments, 'sent', null, $httpCode, (string) $response, $retryOfId, $retryBy);
        return true;
    } /* ============================================================ RETRY FAILED EMAIL ============================================================ */
    public function retryLoggedEmail(int $logId, int $userId): bool
    {
        $this->lastError = '';
        if (!$this->connection || $logId <= 0) {
            $this->lastError = 'Invalid email log record.';
            return false;
        }
        $stmt = mysqli_prepare($this->connection, " SELECT * FROM email_delivery_logs WHERE id = ? LIMIT 1 ");
        if (!$stmt) {
            $this->lastError = 'Unable to read email log: ' . mysqli_error($this->connection);
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $logId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $email = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if (!$email) {
            $this->lastError = 'Email log record was not found.';
            return false;
        }
        if ($email['status'] !== 'failed') {
            $this->lastError = 'Only failed emails can be retried.';
            return false;
        }
        $attachments = $this->decodeAttachments($email['attachment_payload'] ?? null);
        return $this->send($email['recipient_email'], $email['recipient_name'] ?: 'Recipient', $email['subject'], $email['body_html'] ?? '', $attachments, $email['channel'] ?: 'it', $logId, $userId);
    } /* ============================================================ ERROR INFORMATION ============================================================ */
    public function getLastError(): string
    {
        return $this->lastError;
    }
    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }
    public function getLastGraphResponse(): ?string
    {
        return $this->lastGraphResponse;
    } /* ============================================================ IT TICKET CREATED ============================================================ */
    public function sendTicketCreated($toEmail, $toName, array $ticket)
    {
        $ticketNumber = htmlspecialchars($ticket['ticket_number'] ?? '');
        $ticketSubject = htmlspecialchars($ticket['subject'] ?? '');
        $category = htmlspecialchars($ticket['category'] ?? '—');
        $priority = htmlspecialchars($ticket['priority'] ?? 'Medium');
        $status = htmlspecialchars($ticket['status'] ?? 'Open');
        $company = htmlspecialchars($ticket['company'] ?? '—');
        $outlet = htmlspecialchars($ticket['location_office'] ?? '—');
        $description = nl2br(htmlspecialchars($ticket['description'] ?? ''));
        $subject = "Ticket {$ticketNumber} — {$ticketSubject}";
        $body = " <p> Hello " . htmlspecialchars($toName) . ", </p> <p> IT Support ticket <strong>{$ticketNumber}</strong> has been created. </p> <table cellpadding='7' cellspacing='0' style=' border-collapse:collapse; width:100%; max-width:650px; ' > <tr> <td><strong>Subject</strong></td> <td>{$ticketSubject}</td> </tr> <tr> <td><strong>Category</strong></td> <td>{$category}</td> </tr>
        <tr>
    <td><strong>Company</strong></td>
    <td>{$company}</td>
</tr>
<tr>
    <td><strong>Outlet</strong></td>
    <td>{$outlet}</td>
</tr>
        <tr> <td><strong>Priority</strong></td> <td>{$priority}</td> </tr> <tr> <td><strong>Status</strong></td> <td>{$status}</td> </tr> </table> <p> {$description} </p> ";
        return $this->send($toEmail, $toName, $subject, $body, [], 'it');
    } /* ============================================================ GENERIC NOTIFICATION ============================================================ */
    /* ============================================================ GENERIC NOTIFICATION ============================================================ */
    public function sendNotification($toEmail, $toName, $subject, $htmlMessage, $channel = 'it', $fromEmail = null, $fromName = null)
    {
        $body = '<p>Hello ' . htmlspecialchars($toName) . ',</p>' . $htmlMessage;

        return $this->send(
            $toEmail,
            $toName,
            $subject,
            $body,
            [],
            $channel,
            null, // retryOfId
            null, // retryBy
            $fromEmail,
            $fromName
        );
    }

    /* ============================================================ PDF REPORT ============================================================ */
    public function sendReportPdf($toEmail, $toName, $subject, $htmlMessage, $pdfContent, $pdfFilename, $channel = 'it', $senderOverride = null, $senderNameOverride = null)
    {
        $body = '<p>Hello ' . htmlspecialchars($toName) . ',</p>' . $htmlMessage;
        return $this->send($toEmail, $toName, $subject, $body, [
            [
                'name' => $pdfFilename,
                'contentType' => 'application/pdf',
                'content' => $pdfContent
            ]
        ], $channel, null, null, $senderOverride, $senderNameOverride);
    } /* ============================================================ HTML REPORT ============================================================ */
    public function sendReportHtml($toEmail, $toName, $subject, $htmlBody, $channel = 'it', $senderOverride = null, $senderNameOverride = null)
    {
        return $this->send($toEmail, $toName, $subject, $htmlBody, [], $channel, null, null, $senderOverride, $senderNameOverride);
    }
}