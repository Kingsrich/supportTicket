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
    <link rel="icon" type="image/x-icon" href="../assets/images/customer-service.ico">
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
                <div class="pool-booking-card">
                    <div class="pool-booking-copy">
                        <span class="pool-booking-label">STAFF TRANSPORT</span>
                        <h2>Need a ride for work?</h2>
                        <p>
                            Heading to an outlet, meeting or official assignment?
                            Start your pool car booking here.
                        </p>
                    </div>

                    <a href="https://ticket.divacakes.com.ng/pool.php" class="pool-booking-link">
                        Book a pool car
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

            </section>
            <aside class="hero-card steps-card">

                <div class="card-header">
                    <h3>How It Works</h3>
                    <span class="status-pill">6 Simple Steps</span>
                </div>

                <ol class="steps">

                    <li class="step">
                        <span class="step-num">1</span>
                        <div class="step-body">
                            <strong>Click Staff Login</strong>
                            <span>Use the login button at the top of this page.</span>
                        </div>
                    </li>

                    <li class="step">
                        <span class="step-num">2</span>
                        <div class="step-body">
                            <strong>Search for your name</strong>
                            <span>Find and select your name from the staff list.</span>
                        </div>
                    </li>

                    <li class="step">
                        <span class="step-num">3</span>
                        <div class="step-body">
                            <strong>Enter your password</strong>
                            <span>First time? Use the default password provided by IT.</span>
                        </div>
                    </li>

                    <li class="step">
                        <span class="step-num">4</span>
                        <div class="step-body">
                            <strong>Navigate to Support Ticket</strong>
                            <span>Open the Support Ticket section from your dashboard.</span>
                        </div>
                    </li>

                    <li class="step">
                        <span class="step-num">5</span>
                        <div class="step-body">
                            <strong>Click New IT Ticket Or Facility Maintenance</strong>
                            <span>Describe the issue you are experiencing.</span>
                        </div>
                    </li>

                    <li class="step step-final">
                        <span class="step-num">&#10003;</span>
                        <div class="step-body">
                            <strong>Submit</strong>
                            <span>Send your ticket and track its progress from your dashboard.</span>
                        </div>
                    </li>

                </ol>

                <button type="button" class="steps-video-btn" id="openVideoHero">
                    <span>&#9658;</span> Watch the tutorial video
                </button>

            </aside>

        </main>
        <!-- <section class="guide" id="guide">

            <div class="guide-header">
                <div class="eyebrow">Staff Guide</div>
                <h2>How to Submit an IT Support Ticket</h2>
                <p>Watch the short tutorial or follow the written steps below.</p>
            </div>

        </section> -->

        <!-- Video modal -->
        <dialog id="videoModal" class="video-modal">
            <button type="button" class="modal-close" id="closeVideo" aria-label="Close video">&times;</button>
            <video id="tutorialVideo" controls preload="none" playsinline>
                <source src="../assets/media/tutorial.mp4" type="video/mp4">
                Your browser does not support video playback.
            </video>
        </dialog>

        <script>
            const modal = document.getElementById('videoModal');
            const video = document.getElementById('tutorialVideo');

            document.querySelectorAll('#openVideo, #openVideoHero').forEach(btn => {
                btn.addEventListener('click', () => {
                    modal.showModal();
                    video.play();
                });
            });

            function closeModal() {
                video.pause();
                modal.close();
            }

            document.getElementById('closeVideo').addEventListener('click', closeModal);

            // Close when clicking the dark backdrop
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            // Pause if closed with the Esc key
            modal.addEventListener('close', () => video.pause());
        </script>

        <footer class="footer">
            &copy; <?= date('Y') ?> Diva Cakes & Confections Ltd. All rights reserved.
        </footer>
    </div>
</body>

</html>