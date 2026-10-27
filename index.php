<?php
require_once __DIR__ . '/config/config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>InternTrack - Login</title>
<link rel="stylesheet" href="/interntrack/assets/css/style.css">
</head>
<body class="login-page">
<div class="login-card">
    <div class="brand large">Intern<span>Track</span></div>
    <p class="muted">Internship Monitoring and Management System</p>

    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form action="/interntrack/login.php" method="POST">
        <label>Email</label>
        <input type="email" name="email" required placeholder="Enter your email">

        <label>Password</label>
        <input type="password" name="password" required placeholder="Enter your password">

        <button class="btn primary full" type="submit">Sign In</button>
    </form>

    <div class="demo-box">
        <strong>Demo:</strong> student@interntrack.test<br>
        Password: password123
    </div>
</div>
</body>
</html>
