<?php
ob_start();
session_start();
require "inc/connection.php";

$error = "";

/**
 * Allow only internal redirects
 */
function isSafeRedirect($url)
{
    return is_string($url) && str_starts_with($url, '/venditium/');
}

/** Redirect user to last page or fallback*/
function redirectAfterLoginOr($default)
{
    if (
        !empty($_SESSION['redirect_after_login']) &&
        isSafeRedirect($_SESSION['redirect_after_login'])
    ) {
        $redirect = $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
        header("Location: $redirect");
        exit;
    }

    header("Location: $default");
    exit;
}

// Optional timeout message
$timeoutMessage = $_SESSION['timeout_message'] ?? null;
unset($_SESSION['timeout_message']);

if (isset($_POST['login'])) {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Fetch user (now includes is_disabled)
    $sql = "SELECT id, full_name, email, password, is_admin, status FROM users WHERE email = ?";
    $stmt = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($user = mysqli_fetch_assoc($result)) {

        // Check if disabled
        if ($user['status'] == 0) {
            $error = "Your account has been disabled. Please contact admin.";
        }
        // Verify password
        elseif (password_verify($password, $user['password'])) {

            // Base session data
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['is_admin'] = $user['is_admin'];

            // GLOBAL ADMIN
            if ($user['is_admin'] == 1) {
                $store_sql = "SELECT id, store_name FROM stores";
                $store_stmt = mysqli_query($connection, $store_sql);
                $_SESSION['user_store_list'] = mysqli_fetch_all($store_stmt, MYSQLI_ASSOC);

                redirectAfterLoginOr('admin.php');
            }

            // STORE-ASSIGNED USER
            $store_sql = "
                SELECT s.id, s.store_name, us.role
                FROM user_stores us
                JOIN stores s ON s.id = us.store_id
                WHERE us.user_id = ?
            ";
            $store_stmt = mysqli_prepare($connection, $store_sql);
            mysqli_stmt_bind_param($store_stmt, "i", $user['id']);
            mysqli_stmt_execute($store_stmt);
            $stores = mysqli_stmt_get_result($store_stmt);

            $store_count = mysqli_num_rows($stores);

            if ($store_count === 0) {
                redirectAfterLoginOr('create_store.php');
            } elseif ($store_count === 1) {

                $store = mysqli_fetch_assoc($stores);
                $_SESSION['store_id'] = $store['id'];
                $_SESSION['store_name'] = $store['store_name'];
                $_SESSION['store_role'] = $store['role'];

                redirectAfterLoginOr('pos3.php');
            } else {
                $_SESSION['user_store_list'] = mysqli_fetch_all($stores, MYSQLI_ASSOC);
                redirectAfterLoginOr('select_store.php');
            }

        } else {
            $error = "Invalid email or password.";
        }

    } else {
        $error = "Invalid email or password.";
    }
}
?>

<?php require "header.php"; ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Venditium</title>
    <link rel="stylesheet" href="css/index.css">
    <link rel="icon" type="image/png" href="image/pos.png">

</head>

<body>

    <div class="login-wrapper">
        <div class="brand">Please Login To Access The Resource</div>

        <div class="login-card">
            <h2>Welcome Back 👋</h2>

            <?php if ($timeoutMessage): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($timeoutMessage) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>

                <button type="submit" name="login" class="login-btn">
                    Sign In
                </button>

                <div class="extra-links">
                    <a href="#">Forgot password?</a>
                </div>
            </form>
        </div>
    </div>

</body>

</html>