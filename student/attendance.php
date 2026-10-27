<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['student']);

$page_title = 'Attendance';
$student_id = (int) $_SESSION['user_id'];
$error = '';
$notice = $_SESSION['attendance_notice'] ?? '';
unset($_SESSION['attendance_notice']);

$internship_stmt = $conn->prepare("
    SELECT internship_id
    FROM internships
    WHERE student_id = ? AND status = 'ongoing'
    ORDER BY start_date DESC, internship_id DESC
    LIMIT 1
");
$internship_stmt->bind_param('i', $student_id);
$internship_stmt->execute();
$internship = $internship_stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!$internship || !in_array($action, ['check_in', 'check_out'], true)) {
        $error = 'Attendance action is not available for your account.';
    } elseif ($action === 'check_in') {
        $check_in_stmt = $conn->prepare("
            INSERT INTO attendance (internship_id, attendance_date, time_in, status)
            VALUES (?, CURDATE(), CURTIME(), 'pending')
        ");
        $check_in_stmt->bind_param('i', $internship['internship_id']);
        if ($check_in_stmt->execute()) {
            $_SESSION['attendance_notice'] = 'You are checked in. Remember to check out at the end of your workday.';
            header('Location: attendance.php');
            exit;
        }
        $error = 'You already have an attendance record for today or the check-in could not be saved.';
    } else {
        $today_stmt = $conn->prepare("
            SELECT attendance_id, time_in,
                TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date, ' ', time_in), NOW()) AS elapsed_minutes
            FROM attendance
            WHERE internship_id = ? AND attendance_date = CURDATE() AND time_out IS NULL
            LIMIT 1
        ");
        $today_stmt->bind_param('i', $internship['internship_id']);
        $today_stmt->execute();
        $today = $today_stmt->get_result()->fetch_assoc();

        if (!$today) {
            $error = 'Check in before checking out.';
        } elseif ((int) $today['elapsed_minutes'] < 1 || (int) $today['elapsed_minutes'] > 1440) {
            $error = 'The recorded work duration is invalid. Contact your coordinator for help.';
        } else {
            $hours = round((int) $today['elapsed_minutes'] / 60, 2);
            $check_out_stmt = $conn->prepare("
                UPDATE attendance
                SET time_out = CURTIME(), hours_rendered = ?
                WHERE attendance_id = ? AND internship_id = ? AND time_out IS NULL
            ");
            $check_out_stmt->bind_param('dii', $hours, $today['attendance_id'], $internship['internship_id']);
            $check_out_stmt->execute();
            if ($check_out_stmt->affected_rows === 1) {
                $_SESSION['attendance_notice'] = 'You are checked out. Your attendance is awaiting supervisor verification.';
                header('Location: attendance.php');
                exit;
            }
            $error = 'Your attendance could not be checked out. Refresh the page and try again.';
        }
    }
}

$today_record = null;
if ($internship) {
    $today_stmt = $conn->prepare("
        SELECT time_in, time_out, status
        FROM attendance
        WHERE internship_id = ? AND attendance_date = CURDATE()
        LIMIT 1
    ");
    $today_stmt->bind_param('i', $internship['internship_id']);
    $today_stmt->execute();
    $today_record = $today_stmt->get_result()->fetch_assoc();
}

$records_stmt = $conn->prepare("
    SELECT a.attendance_date, a.time_in, a.time_out, a.hours_rendered, a.status
    FROM attendance a
    JOIN internships i ON i.internship_id = a.internship_id
    WHERE i.student_id = ?
    ORDER BY a.attendance_date DESC
");
$records_stmt->bind_param('i', $student_id);
$records_stmt->execute();
$records = $records_stmt->get_result();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="panel">
    <div class="panel-head">
        <div><h2>Daily Attendance</h2><p>Check in at work and check out at the end of your shift.</p></div>
    </div>
    <?php if ($notice !== ''): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!$internship): ?>
        <div class="empty-state">Attendance actions are available when you have an ongoing internship assignment.</div>
    <?php elseif (!$today_record): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_in">
            <button class="btn primary" type="submit">Check in now</button>
        </form>
    <?php elseif ($today_record['time_out'] === null): ?>
        <p>Checked in at <strong><?= htmlspecialchars($today_record['time_in']) ?></strong>. Your record is pending supervisor verification.</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="check_out">
            <button class="btn primary" type="submit">Check out now</button>
        </form>
    <?php else: ?>
        <p>Today's attendance: <strong><?= htmlspecialchars(ucfirst($today_record['status'])) ?></strong>. Check in/out: <?= htmlspecialchars($today_record['time_in']) ?>–<?= htmlspecialchars($today_record['time_out']) ?>.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head"><div><h2>Attendance Records</h2><p>Hours are counted from check-in through check-out.</p></div></div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr></thead>
            <tbody>
                <?php if ($records->num_rows > 0): ?>
                    <?php while ($row = $records->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['attendance_date']) ?></td>
                            <td><?= htmlspecialchars($row['time_in'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['time_out'] ?? '—') ?></td>
                            <td><?= number_format((float) $row['hours_rendered'], 2) ?></td>
                            <td><span class="badge"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="empty-state">No attendance records yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
