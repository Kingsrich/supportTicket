<?php

ob_start();
session_start();

require "../inc/connection.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: ../pages/login.php");
    exit;
}

$email = trim($_POST['email']);
$password = trim($_POST['password']);

if (empty($email) || empty($password)) {

    $_SESSION['error'] = "All fields are required.";

    header("Location: ../pages/login.php");
    exit;
}

try {

    $stmt = $pdo->prepare("
        SELECT * FROM users
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->execute([$email]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {

        $_SESSION['error'] = "Invalid email or password.";

        header("Location: ../pages/login.php");
        exit;
    }

    if ($user['status'] !== 'active') {

        $_SESSION['error'] = "Your account is inactive.";

        header("Location: ../pages/login.php");
        exit;
    }

    if (!password_verify($password, $user['password'])) {

        $_SESSION['error'] = "Invalid email or password.";

        header("Location: ../pages/login.php");
        exit;
    }

    // REGENERATE SESSION
    session_regenerate_id(true);

    // STORE SESSION
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    // UPDATE LAST LOGIN
    $update = $pdo->prepare("
        UPDATE users
        SET last_login = NOW()
        WHERE id = ?
    ");

    $update->execute([$user['id']]);

    // REDIRECT
    header("Location: ../pages/dashboard.php");
    exit;

} catch (PDOException $e) {

    $_SESSION['error'] = "Something went wrong.";

    header("Location: ../pages/login.php");
    exit;
}