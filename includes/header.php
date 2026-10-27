<?php
require_once __DIR__ . '/auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title ?? 'InternTrack') ?></title>
<link rel="stylesheet" href="/interntrack/assets/css/style.css">
</head>
<body>
<div class="app">
<aside class="sidebar">
    <div class="brand">Intern<span>Track</span></div>
    <p class="role"><?= htmlspecialchars(ucfirst($_SESSION['role'])) ?></p>
    <nav>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="/interntrack/admin/admin-dashboard.php">Dashboard</a>
        <?php elseif ($_SESSION['role'] === 'coordinator'): ?>
            <a href="/interntrack/coordinator/coordinator-dashboard.php">Dashboard</a>
        <?php elseif ($_SESSION['role'] === 'supervisor'): ?>
            <a href="/interntrack/supervisor/supervisor-dashboard.php">Dashboard</a>
        <?php else: ?>
            <a href="/interntrack/dashboard.php">Dashboard</a>
        <?php endif; ?>
        <?php if ($_SESSION['role'] === 'student'): ?>
            <a href="/interntrack/student/profile.php">Internship Profile</a>
            <a href="/interntrack/student/requirements.php">Requirements</a>
            <a href="/interntrack/student/attendance.php">Attendance</a>
            <a href="/interntrack/student/reports.php">Accomplishment Reports</a>
        <?php elseif ($_SESSION['role'] === 'coordinator'): ?>
            <a href="/interntrack/coordinator/students.php">Students</a><a href="/interntrack/coordinator/companies.php">Companies</a><a href="/interntrack/coordinator/assignments.php">Assignments</a><a href="/interntrack/coordinator/attendance.php">Attendance</a><a href="/interntrack/coordinator/requirements.php">Requirements</a><a href="/interntrack/coordinator/reports.php">Reports</a>
        <?php elseif ($_SESSION['role'] === 'supervisor'): ?>
            <a href="/interntrack/supervisor/my-interns.php">My Interns</a><a href="/interntrack/supervisor/attendance.php">Attendance</a><a href="/interntrack/supervisor/reports.php">Reports</a><a href="/interntrack/supervisor/evaluations.php">Evaluations</a>
        <?php else: ?>
            <a href="/interntrack/admin/users.php">Users</a><a href="/interntrack/admin/system-reports.php">System Reports</a><a href="/interntrack/admin/settings.php">Settings</a>
        <?php endif; ?>
        <a href="/interntrack/logout.php">Logout</a>
    </nav>
</aside>
<main class="content">
