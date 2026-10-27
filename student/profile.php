<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['student']);
$page_title = 'Internship Profile';
$error = '';
$notice = $_SESSION['profile_notice'] ?? '';
unset($_SESSION['profile_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $full_name = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $student_number = is_string($_POST['student_number'] ?? null) ? trim($_POST['student_number']) : '';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

    if ($full_name === '' || strlen($full_name) > 150 || $student_number === ''
        || strlen($student_number) > 30 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 150) {
        $error = 'Enter a valid name, student number, and email address.';
    } else {
        $update_stmt = $conn->prepare("
            UPDATE users
            SET full_name = ?, student_number = ?, email = ?
            WHERE user_id = ? AND role = 'student'
        ");
        $update_stmt->bind_param('sssi', $full_name, $student_number, $email, $_SESSION['user_id']);
        if ($update_stmt->execute()) {
            $_SESSION['full_name'] = $full_name;
            $_SESSION['email'] = $email;
            $_SESSION['profile_notice'] = 'Your profile was updated.';
            header('Location: profile.php');
            exit;
        }
        $error = 'Could not update your profile. The email or student number may already be in use.';
    }
}

$stmt = $conn->prepare("SELECT u.full_name, u.student_number, u.email, c.company_name,
    i.start_date, i.end_date, i.required_hours, i.status
    FROM users u LEFT JOIN internships i ON i.student_id=u.user_id
    LEFT JOIN companies c ON c.company_id=i.company_id
    WHERE u.user_id=? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
require_once __DIR__ . '/../includes/header.php';
?>

<section class="panel">
<div class="panel-head"><div><h2>Student Information</h2><p>Keep your contact and student details current.</p></div></div>
<?php if ($notice !== ''): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST" class="crud-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <label>Full name<input name="full_name" maxlength="150" required value="<?= htmlspecialchars($data['full_name'] ?? '') ?>"></label>
    <label>Student number<input name="student_number" maxlength="30" required value="<?= htmlspecialchars($data['student_number'] ?? '') ?>"></label>
    <label>Email<input type="email" name="email" maxlength="150" required value="<?= htmlspecialchars($data['email'] ?? '') ?>"></label>
    <button class="btn primary" type="submit" style="margin-top: 10px; margin-bottom: 20px;">Save profile</button>
</form>

<div class="form-grid">
    <div><label>Company</label><input value="<?= htmlspecialchars($data['company_name'] ?? 'Not assigned') ?>" readonly></div>
    <div><label>Start Date</label><input value="<?= htmlspecialchars($data['start_date'] ?? '') ?>" readonly></div>
    <div><label>End Date</label><input value="<?= htmlspecialchars($data['end_date'] ?? '') ?>" readonly></div>
</div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
