<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$page_title = 'Settings';
$admin_id = (int) $_SESSION['user_id'];
$error = '';
$notice = $_SESSION['settings_notice'] ?? '';
unset($_SESSION['settings_notice']);

$admin_stmt = $conn->prepare("
    SELECT full_name, email, password_hash, status
    FROM users
    WHERE user_id = ? AND role = 'admin'
    LIMIT 1
");
$admin_stmt->bind_param('i', $admin_id);
$admin_stmt->execute();
$admin_account = $admin_stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'profile') {
        $full_name = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
        $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

        if ($full_name === '' || strlen($full_name) > 150
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $error = 'Enter a valid name and email address.';
        } else {
            $update_stmt = $conn->prepare("
                UPDATE users SET full_name = ?, email = ?
                WHERE user_id = ? AND role = 'admin'
            ");
            $update_stmt->bind_param('ssi', $full_name, $email, $admin_id);
            if ($update_stmt->execute()) {
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                $_SESSION['settings_notice'] = 'Administrator profile updated.';
                header('Location: settings.php');
                exit;
            }
            $error = 'Could not update the profile. The email may already be in use.';
        }
    } elseif ($action === 'password') {
        $current_password = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $new_password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirm_password = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

        if (!$admin_account || !password_verify($current_password, $admin_account['password_hash'])) {
            $error = 'The current password is incorrect.';
        } elseif (strlen($new_password) < 8 || $new_password !== $confirm_password) {
            $error = 'The new password must be at least 8 characters and both entries must match.';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $password_stmt = $conn->prepare("
                UPDATE users SET password_hash = ?
                WHERE user_id = ? AND role = 'admin'
            ");
            $password_stmt->bind_param('si', $password_hash, $admin_id);
            if ($password_stmt->execute()) {
                $_SESSION['settings_notice'] = 'Administrator password changed.';
                header('Location: settings.php');
                exit;
            }
            $error = 'Could not update the password.';
        }
    } else {
        $error = 'Choose a valid settings action.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="cards">
    <div class="stat-card"><div class="stat-icon">P</div><div><span>Profile</span><strong>Admin</strong></div></div>
    <div class="stat-card"><div class="stat-icon">R</div><div><span>Role</span><strong><?= htmlspecialchars(ucfirst($_SESSION['role'])) ?></strong></div></div>
    <div class="stat-card"><div class="stat-icon">A</div><div><span>Account Status</span><strong><?= htmlspecialchars(ucfirst($admin_account['status'] ?? 'unknown')) ?></strong></div></div>
    <div class="stat-card"><div class="stat-icon">S</div><div><span>System</span><strong>Online</strong></div></div>
</div>

<?php if ($notice !== ''): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<section class="panel">
    <div class="panel-head"><div><h2>Administrator Profile</h2><p>Update your account contact details.</p></div></div>
    <form method="POST" class="crud-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="profile">
        <label>Full name<input name="full_name" maxlength="150" required value="<?= htmlspecialchars($admin_account['full_name'] ?? '') ?>"></label>
        <label>Email<input type="email" name="email" maxlength="150" required value="<?= htmlspecialchars($admin_account['email'] ?? '') ?>"></label>
        <button class="btn primary" type="submit">Save profile</button>
    </form>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Change Password</h2><p>Confirm your current password before setting a new one.</p></div></div>
    <form method="POST" class="crud-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>New password<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>
        <button class="btn primary" type="submit">Change password</button>
    </form>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>System Preferences</h2><p>These features are informational placeholders and are not configurable yet.</p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Preference</th><th>Description</th><th>Status</th></tr></thead>
            <tbody>
                <tr><td>System Notifications</td><td>Notifications for important system activity</td><td><span class="badge approved">Enabled</span></td></tr>
                <tr><td>Review Alerts</td><td>Alerts for pending requirements and reports</td><td><span class="badge approved">Enabled</span></td></tr>
                <tr><td>Activity Logging</td><td>System activity monitoring and recording</td><td><span class="badge approved">Enabled</span></td></tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>System Information</h2><p>InternTrack development environment details.</p></div></div>
    <div class="info-grid">
        <div><small>Application</small><strong>InternTrack</strong></div>
        <div><small>System Type</small><strong>OJT Monitoring System</strong></div>
        <div><small>Backend</small><strong>PHP + MySQL</strong></div>
        <div><small>Environment</small><strong>Local Development</strong></div>
    </div>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>About InternTrack</h2><p>Internship/OJT Monitoring and Management System.</p></div></div>
    <div class="info-grid">
        <div><small>Purpose</small><strong>Internship and OJT monitoring</strong></div>
        <div><small>User roles</small><strong>Student, Coordinator, Supervisor, Admin</strong></div>
        <div><small>Technology</small><strong>PHP, MySQL, JavaScript, CSS</strong></div>
        <div><small>System type</small><strong>Web application</strong></div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
