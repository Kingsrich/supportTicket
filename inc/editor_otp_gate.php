<?php

/**
 * inc/editor_otp_gate.php
 *
 * Email OTP gate for the code editor.
 * The one-time code is always sent to it@divacakes.com.ng (hard-coded,
 * never taken from user input) using the existing EmailService.
 *
 * Usage (in code_editor.php, right after the admin role check):
 *     require_once '../inc/editor_otp_gate.php';
 *     editorOtpGate($connection);
 */

require_once __DIR__ . '/EmailService.php';

const EDITOR_OTP_RECIPIENT       = 'kingsrich2019@gmail.com';
const EDITOR_OTP_TTL             = 600;   // code valid for 10 minutes
const EDITOR_OTP_MAX_ATTEMPTS    = 5;     // wrong guesses before lockout
const EDITOR_OTP_LOCKOUT         = 900;   // 15 minute lockout
const EDITOR_OTP_RESEND_COOLDOWN = 60;    // seconds between sends
const EDITOR_OTP_MAX_SENDS_HOUR  = 5;     // email sends per rolling hour
const EDITOR_OTP_IDLE_TIMEOUT    = 1200;  // access ends after 20 min idle
const EDITOR_OTP_MAX_LIFETIME    = 7200;  // access ends after 2 h regardless

function editorOtpFingerprint(): string
{
    // Bind verification to the admin and their browser.
    return hash('sha256', (int) ($_SESSION['user_id'] ?? 0) . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function editorOtpRevoke(): void
{
    unset($_SESSION['editor_otp_ok'], $_SESSION['editor_otp_pending']);
}

function editorOtpIsVerified(): bool
{
    $ok = $_SESSION['editor_otp_ok'] ?? null;

    if (!is_array($ok)) {
        return false;
    }

    $now = time();

    if (
        !hash_equals((string) ($ok['fp'] ?? ''), editorOtpFingerprint()) ||
        ($now - (int) ($ok['last'] ?? 0)) > EDITOR_OTP_IDLE_TIMEOUT ||
        ($now - (int) ($ok['since'] ?? 0)) > EDITOR_OTP_MAX_LIFETIME
    ) {
        unset($_SESSION['editor_otp_ok']);
        return false;
    }

    $_SESSION['editor_otp_ok']['last'] = $now; // sliding idle window
    return true;
}

function editorOtpMsg(string $text, string $type = 'error'): void
{
    $_SESSION['editor_otp_msg'] = ['text' => $text, 'type' => $type];
}

function editorOtpIsLocked(): int
{
    return max(0, (int) ($_SESSION['editor_otp_lock_until'] ?? 0) - time());
}

/**
 * Generate and email a new code. Returns true on success.
 */
function editorOtpSend(mysqli $connection): bool
{
    $now = time();

    if (editorOtpIsLocked() > 0) {
        return false;
    }

    $pending = $_SESSION['editor_otp_pending'] ?? null;
    if (is_array($pending) && ($now - (int) ($pending['sent_at'] ?? 0)) < EDITOR_OTP_RESEND_COOLDOWN) {
        editorOtpMsg('Please wait a moment before requesting another code.', 'warning');
        return false;
    }

    // Rolling hourly send limit.
    $sends = array_filter(
        (array) ($_SESSION['editor_otp_sends'] ?? []),
        static fn($t) => ($now - (int) $t) < 3600
    );
    if (count($sends) >= EDITOR_OTP_MAX_SENDS_HOUR) {
        editorOtpMsg('Too many codes requested. Try again later.', 'error');
        return false;
    }

    if (empty($_SESSION['editor_otp_key'])) {
        $_SESSION['editor_otp_key'] = bin2hex(random_bytes(32));
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $admin = (string) ($_SESSION['fullname'] ?? $_SESSION['username'] ?? ('User #' . (int) ($_SESSION['user_id'] ?? 0)));
    $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ua    = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    $when  = date('D, d M Y H:i:s T');

    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $subject = 'Code Editor Access Code';
    $body = "
    <div style='font-family:Arial,sans-serif;color:#1f2937;line-height:1.6'>
        <h2 style='margin:0 0 12px;color:#7c2d12'>Code Editor Access Request</h2>
        <p>An administrator is requesting access to the system code editor.</p>
        <p style='font-size:30px;font-weight:bold;letter-spacing:8px;margin:18px 0;color:#111827'>{$code}</p>
        <p>This code expires in " . (int) (EDITOR_OTP_TTL / 60) . " minutes and can be used once.</p>
        <table cellpadding='6' cellspacing='0' style='border-collapse:collapse;width:100%;max-width:650px'>
            <tr><td style='font-weight:bold;width:160px'>Requested by</td><td>{$h($admin)}</td></tr>
            <tr><td style='font-weight:bold'>IP address</td><td>{$h($ip)}</td></tr>
            <tr><td style='font-weight:bold'>Browser</td><td>{$h($ua)}</td></tr>
            <tr><td style='font-weight:bold'>Time</td><td>{$h($when)}</td></tr>
        </table>
        <p style='margin-top:18px;color:#b91c1c'>
            If you did not expect this request, do NOT share the code and report it immediately.
        </p>
        <p style='margin-top:18px;color:#64748b;font-size:13px'>
            This message was generated automatically by the Support Ticket System.
        </p>
    </div>";

    try {
        $emailService = new EmailService($connection);
        $sent = $emailService->sendNotification(
            EDITOR_OTP_RECIPIENT,
            'IT Security',
            $subject,
            $body,
            'it'
        );
    } catch (Throwable $e) {
        error_log('Code editor OTP email exception: ' . $e->getMessage());
        $sent = false;
    }

    if (!$sent) {
        // Fail closed: no email means no access.
        error_log('Code editor OTP email failed for admin ' . $admin);
        unset($_SESSION['editor_otp_pending']);
        editorOtpMsg('The verification email could not be sent. Check the email configuration and try again.', 'error');
        return false;
    }

    $sends[] = $now;
    $_SESSION['editor_otp_sends'] = array_values($sends);

    $_SESSION['editor_otp_pending'] = [
        'hash'     => hash_hmac('sha256', $code, $_SESSION['editor_otp_key']),
        'expires'  => $now + EDITOR_OTP_TTL,
        'sent_at'  => $now,
        'attempts' => 0,
    ];

    editorOtpMsg('A verification code has been sent to ' . EDITOR_OTP_RECIPIENT . '.', 'success');
    return true;
}

function editorOtpVerify(string $input): bool
{
    $pending = $_SESSION['editor_otp_pending'] ?? null;

    if (!is_array($pending)) {
        editorOtpMsg('No active code. Request a new one.', 'error');
        return false;
    }

    if (time() > (int) $pending['expires']) {
        unset($_SESSION['editor_otp_pending']);
        editorOtpMsg('That code has expired. Request a new one.', 'error');
        return false;
    }

    $input = preg_replace('/\D/', '', $input) ?? '';

    $expected = (string) $pending['hash'];
    $given    = hash_hmac('sha256', $input, (string) ($_SESSION['editor_otp_key'] ?? ''));

    if (strlen($input) === 6 && hash_equals($expected, $given)) {
        // Single use: destroy the code, then grant access.
        unset(
            $_SESSION['editor_otp_pending'],
            $_SESSION['editor_otp_key'],
            $_SESSION['editor_otp_lock_until']
        );
        session_regenerate_id(true);

        $_SESSION['editor_otp_ok'] = [
            'fp'    => editorOtpFingerprint(),
            'since' => time(),
            'last'  => time(),
        ];

        // Fresh CSRF token for the editor itself.
        $_SESSION['code_editor_csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    $_SESSION['editor_otp_pending']['attempts'] = (int) $pending['attempts'] + 1;

    if ($_SESSION['editor_otp_pending']['attempts'] >= EDITOR_OTP_MAX_ATTEMPTS) {
        unset($_SESSION['editor_otp_pending']);
        $_SESSION['editor_otp_lock_until'] = time() + EDITOR_OTP_LOCKOUT;
        error_log('Code editor OTP lockout for user ' . (int) ($_SESSION['user_id'] ?? 0)
            . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        editorOtpMsg('Too many incorrect attempts. Access is locked for ' . (int) (EDITOR_OTP_LOCKOUT / 60) . ' minutes.', 'error');
        return false;
    }

    $left = EDITOR_OTP_MAX_ATTEMPTS - $_SESSION['editor_otp_pending']['attempts'];
    editorOtpMsg("Incorrect code. {$left} attempt(s) remaining.", 'error');
    return false;
}

function editorOtpGate(mysqli $connection): void
{
    if (editorOtpIsVerified()) {
        return;
    }

    if (empty($_SESSION['editor_otp_csrf'])) {
        $_SESSION['editor_otp_csrf'] = bin2hex(random_bytes(32));
    }

    // ---- Handle OTP form posts (never the editor's own save posts) ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_action'])) {
        $token = is_string($_POST['otp_csrf'] ?? null) ? $_POST['otp_csrf'] : '';

        if (!hash_equals((string) $_SESSION['editor_otp_csrf'], $token)) {
            editorOtpMsg('Security token expired. Please try again.', 'error');
        } else {
            $action = (string) $_POST['otp_action'];

            if ($action === 'verify' && editorOtpIsLocked() === 0) {
                if (editorOtpVerify(is_string($_POST['otp_code'] ?? null) ? $_POST['otp_code'] : '')) {
                    header('Location: code_editor.php');
                    exit;
                }
            } elseif ($action === 'resend') {
                editorOtpSend($connection);
            } elseif ($action === 'cancel') {
                editorOtpRevoke();
                header('Location: dashboard.php');
                exit;
            }
        }

        header('Location: code_editor.php');
        exit;
    }

    // ---- Auto-send a code on first visit ----
    $locked  = editorOtpIsLocked();
    $pending = $_SESSION['editor_otp_pending'] ?? null;

    if ($locked === 0 && (!is_array($pending) || time() > (int) $pending['expires'])) {
        editorOtpSend($connection);
        $pending = $_SESSION['editor_otp_pending'] ?? null;
    }

    $msg = $_SESSION['editor_otp_msg'] ?? null;
    unset($_SESSION['editor_otp_msg']);

    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    header('Cache-Control: no-store');
    include __DIR__ . '/header.php';
?>
<style>
.otp-card {
    max-width: 420px;
    margin: 60px auto;
    padding: 28px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0, 0, 0, .06);
}

.otp-card h3 {
    margin: 0 0 6px;
    color: #7c2d12;
}

.otp-card p {
    margin: 0 0 16px;
    color: #475569;
    font-size: 14px;
}

.otp-card input[type=text] {
    width: 100%;
    box-sizing: border-box;
    padding: 12px;
    font-size: 24px;
    letter-spacing: 8px;
    text-align: center;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
}

.otp-btn {
    width: 100%;
    margin-top: 12px;
    padding: 11px;
    border: 0;
    border-radius: 8px;
    background: #7c2d12;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}

.otp-btn.light {
    background: #f1f5f9;
    color: #334155;
}

.otp-msg {
    padding: 10px 12px;
    border-radius: 6px;
    margin-bottom: 14px;
    font-size: 13px;
}

.otp-msg.success {
    background: #d1e7dd;
    color: #0f5132;
}

.otp-msg.error {
    background: #f8d7da;
    color: #842029;
}

.otp-msg.warning {
    background: #fff3cd;
    color: #664d03;
}
</style>

<div class="otp-card">
    <h3>Verification required</h3>
    <p>
        For security, enter the 6-digit code sent to
        <strong><?= $h(EDITOR_OTP_RECIPIENT) ?></strong> to open the code editor.
    </p>

    <?php if ($msg): ?>
    <div class="otp-msg <?= $h($msg['type']) ?>" role="alert"><?= $h($msg['text']) ?></div>
    <?php endif; ?>

    <?php if ($locked > 0): ?>
    <div class="otp-msg error">Locked. Try again in <?= (int) ceil($locked / 60) ?> minute(s).</div>
    <?php else: ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="otp_csrf" value="<?= $h($_SESSION['editor_otp_csrf']) ?>">
        <input type="hidden" name="otp_action" value="verify">
        <input type="text" name="otp_code" inputmode="numeric" pattern="\d{6}" maxlength="6"
            autocomplete="one-time-code" placeholder="••••••" required autofocus>
        <button type="submit" class="otp-btn">Verify and continue</button>
    </form>

    <form method="POST">
        <input type="hidden" name="otp_csrf" value="<?= $h($_SESSION['editor_otp_csrf']) ?>">
        <input type="hidden" name="otp_action" value="resend">
        <button type="submit" class="otp-btn light">Send a new code</button>
    </form>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="otp_csrf" value="<?= $h($_SESSION['editor_otp_csrf']) ?>">
        <input type="hidden" name="otp_action" value="cancel">
        <button type="submit" class="otp-btn light">Cancel</button>
    </form>
</div>
<?php
    include __DIR__ . '/footer.php';
    exit;
}