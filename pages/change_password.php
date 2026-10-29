<?php require '../inc/auth.php';
require '../inc/connection.php';
mysqli_report(MYSQLI_REPORT_OFF);
$pageTitle = "Change Password";
$userId = (int) ($_SESSION['user_id'] ?? 0);
$message = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = "Please complete all password fields.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "New password and confirmation password do not match.";
    } elseif (strlen($newPassword) < 5) {
        $error = "Password must be at least 8 characters.";
    } else {
        $stmt = mysqli_prepare($connection, "SELECT password FROM users WHERE id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        if (!$user || !password_verify($currentPassword, $user['password'])) {
            $error = "Your current password is incorrect.";
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($connection, "UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $hashedPassword, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['must_change_password'] = 0;
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Unable to update password. Please try again.";
            }
        }
    }
}
include '../inc/header.php'; ?>
<style>
.password-wrapper {
    max-width: 560px;
    margin: 30px auto;
}

.password-card {
    background: #fff;
    border: 1px solid #eef2f7;
    border-radius: 26px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
}

.password-card h3 {
    color: #1e293b;
    font-size: 22px;
    margin-bottom: 8px;
}

.password-card p {
    color: #64748b;
    line-height: 1.7;
    font-size: 14px;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 18px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 800;
    color: #475569;
}

.form-control {
    width: 100%;
    height: 35px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 0 14px;
    outline: none;
    font-size: 14px;
}

.form-control:focus {
    border-color: #db2777;
    box-shadow: 0 0 0 4px rgba(219, 39, 119, 0.10);
}

.btn-primary {
    width: 100%;
    border: none;
    cursor: pointer;
    border-radius: 14px;
    padding: 14px 18px;
    font-weight: 900;
    font-size: 14px;
    color: #fff;
    background: linear-gradient(135deg, #9a3412, #db2777);
}

.message {
    padding: 14px 16px;
    border-radius: 14px;
    margin-bottom: 18px;
    font-weight: 700;
    font-size: 14px;
}

.message.error {
    background: linear-gradient(135deg, #fee2e2, #fff1f2);
    color: #991b1b;
    border: 1px solid #fecaca;
}

.message.success {
    background: linear-gradient(135deg, #dcfce7, #f0fdf4);
    color: #166534;
    border: 1px solid #bbf7d0;
}

.password-rules {
    margin-top: 18px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 16px;
    padding: 14px 16px;
    color: #7c2d12;
    font-size: 13px;
    line-height: 1.7;
}

.password-input-wrap {
    position: relative;
}

.password-input-wrap .form-control {
    padding-right: 48px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 14px;
    transform: translateY(-50%);
    width: 34px;
    height: 34px;
    border: none;
    background: transparent;
    color: #94a3b8;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 15px;
}

.password-toggle:hover {
    color: #9a3412;
    background: #fff7ed;
}

.password-toggle:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(154, 52, 18, 0.10);
}
</style>
<div class="password-wrapper">
    <div class="password-card">
        <h3>Change Your Password</h3>
        <p> For security reasons, new users must change their default password before continuing. </p>
        <?php if ($error !== ''): ?>
        <div class="message error"> <?= htmlspecialchars($error) ?> </div> <?php endif; ?>
        <?php if ($message !== ''): ?>
        <div class="message success"> <?= htmlspecialchars($message) ?> </div> <?php endif; ?>
        <form method="POST">
            <div class="form-group"> <label>Current Password</label>
                <div class="password-input-wrap"> <input type="password" name="current_password" id="current_password"
                        class="form-control" autocomplete="current-password" required> <button type="button"
                        class="password-toggle" data-target="current_password" aria-label="Show current password"
                        title="Show password"> <i class="fa-solid fa-eye"></i> </button> </div>
            </div>
            <div class="form-group"> <label>New Password</label>
                <div class="password-input-wrap"> <input type="password" name="new_password" id="new_password"
                        class="form-control" autocomplete="new-password" required> <button type="button"
                        class="password-toggle" data-target="new_password" aria-label="Show new password"
                        title="Show password"> <i class="fa-solid fa-eye"></i> </button> </div>
            </div>
            <div class="form-group"> <label>Confirm New Password</label>
                <div class="password-input-wrap"> <input type="password" name="confirm_password" id="confirm_password"
                        class="form-control" autocomplete="new-password" required> <button type="button"
                        class="password-toggle" data-target="confirm_password" aria-label="Show confirmed password"
                        title="Show password"> <i class="fa-solid fa-eye"></i> </button> </div>
            </div> <button type="submit" class="btn-primary"> Update Password </button>
        </form>
        <div class="password-rules"> Password must be at least 5 characters. Use a strong password that is different
            from your default password. </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.password-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            if (!input || !icon) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            icon.classList.toggle('fa-eye', showing);
            icon.classList.toggle('fa-eye-slash', !showing);
            this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            this.setAttribute('title', showing ? 'Show password' : 'Hide password');
        });
    });
});
</script>
<?php include '../inc/footer.php'; ?>