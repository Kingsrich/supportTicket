<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Diva Cakes Support & Staff Feedback System</title>
    <link rel="stylesheet" href="../assets/css/home.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <div class="landing-wrapper">
        <header class="topbar">
            <div class="brand">
                <div class="brand-badge">DC</div>
                <span>Diva Cakes</span>
            </div>
            <a href="login.php" class="login-link">
                Login
            </a>
        </header>
        <main class="hero">
            <section class="hero-content">
                <div class="eyebrow">
                    Support Ticket & Staff Feedback Portal
                </div>
                <h1>
                    Report issues, track support requests and share valuable feedback.
                </h1>
                <p>
                    A centralized support platform that allows employees to raise support
                    tickets, monitor their resolution progress and submit workplace feedback.
                    It helps management respond faster, improve internal services and create
                    a better working environment for everyone.
                </p>
                <div class="actions">
                    <a href="login.php" class="primary-btn">
                        Login to Continue
                    </a>
                    <span class="secondary-note">
                        For authorized staff only
                    </span>
                </div>
            </section>
            <aside class="hero-card">
                <div class="card-header">
                    <h3>How It Works</h3>
                    <span class="status-pill">
                        Active
                    </span>
                </div>
                <div class="metric">

                    <strong>1. Submit a Request</strong>

                    <span>
                        Raise a support ticket or submit feedback about workplace
                        services, processes and staff experience.
                    </span>

                </div>

                <div class="metric">

                    <strong>2. Track Progress</strong>

                    <span>
                        Follow the status of your request, receive updates and communicate
                        with the assigned support team.
                    </span>

                </div>

                <div class="metric">

                    <strong>3. Resolution and Improvement</strong>

                    <span>
                        Support requests are reviewed and resolved, while staff feedback
                        helps management identify opportunities for improvement.
                    </span>

                </div>

                <div class="progress-box">

                    <div class="progress-title">

                        <span>Support Service Readiness</span>

                        <span>100%</span>

                    </div>

                    <div class="progress-track">
                        <div class="progress-fill" style="width: 100%;"></div>
                    </div>

                </div>

            </aside>

        </main>

        <footer class="footer">
            &copy; <?= date('Y') ?> Diva Cakes & Confections Ltd. All rights reserved.
        </footer>
    </div>
</body>

</html>


<p>
    i want to add media on this page, such that staffs can click to view tutorial video and a readme describing procedures which is 
    1. click on Staff Login
    2. search for your name
    3. enter password default password is Welcome123@
    4. Navigate to Support ticket
    5. click on New IT Ticket
    6. submit
</p>