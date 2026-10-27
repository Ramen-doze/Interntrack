<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['coordinator']);

$page_title = 'Attendance';
$coordinator_id = (int) $_SESSION['user_id'];
$error = '';
$notice = $_SESSION['coordinator_attendance_notice'] ?? '';
unset($_SESSION['coordinator_attendance_notice']);

$internships_stmt = $conn->prepare("
    SELECT i.internship_id, u.full_name, c.company_name
    FROM internships i
    JOIN users u ON u.user_id = i.student_id
    JOIN companies c ON c.company_id = i.company_id
    WHERE i.coordinator_id = ? AND i.status = 'ongoing'
    ORDER BY u.full_name
");
$internships_stmt->bind_param('i', $coordinator_id);
$internships_stmt->execute();
$internships = $internships_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $attendance_id_raw = is_string($_POST['attendance_id'] ?? null) ? $_POST['attendance_id'] : '';
    $internship_raw = is_string($_POST['internship_id'] ?? null) ? $_POST['internship_id'] : '';
    $date = is_string($_POST['attendance_date'] ?? null) ? $_POST['attendance_date'] : '';
    $time_in = is_string($_POST['time_in'] ?? null) ? $_POST['time_in'] : '';
    $time_out = is_string($_POST['time_out'] ?? null) ? $_POST['time_out'] : '';
    $status = is_string($_POST['attendance_status'] ?? null) ? $_POST['attendance_status'] : '';
    $attendance_id = filter_var($attendance_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $internship_id = filter_var($internship_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date_object = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $valid_date = $date_object !== false && $date_object->format('Y-m-d') === $date;
    $valid_time = static fn(string $value): bool => $value === '' || (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
    $allowed_attendance_statuses = ['pending', 'present', 'late', 'absent'];
    $hours = 0.0;

    if (!in_array($action, ['create', 'update'], true) || !$valid_date
        || !$valid_time($time_in) || !$valid_time($time_out)
        || !in_array($status, $allowed_attendance_statuses, true)
        || ($action === 'create' && $internship_id === false)
        || ($action === 'update' && $attendance_id === false)
        || ($status !== 'absent' && $time_in === '')
        || (in_array($status, ['present', 'late'], true) && $time_out === '')
        || ($time_out !== '' && ($time_in === '' || $time_out <= $time_in))) {
        $error = 'Enter a valid date, time range, and attendance status. Work shifts must end on the same day.';
    } elseif ($time_out !== '') {
        $start = DateTimeImmutable::createFromFormat('!H:i', $time_in);
        $end = DateTimeImmutable::createFromFormat('!H:i', $time_out);
        $hours = round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 2);
    }

    if ($error === '') {
        if ($action === 'create') {
            $owned_stmt = $conn->prepare("
                SELECT internship_id FROM internships
                WHERE internship_id = ? AND coordinator_id = ? AND status = 'ongoing'
            ");
            $owned_stmt->bind_param('ii', $internship_id, $coordinator_id);
            $owned_stmt->execute();
            if (!$owned_stmt->get_result()->fetch_assoc()) {
                $error = 'Choose an ongoing internship assigned to you.';
            } else {
                $verified_by = $status === 'pending' ? null : $coordinator_id;
                $create_stmt = $conn->prepare("
                    INSERT INTO attendance
                        (internship_id, attendance_date, time_in, time_out, hours_rendered, status, verified_by)
                    VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?)
                ");
                $create_stmt->bind_param('isssdsi', $internship_id, $date, $time_in, $time_out, $hours, $status, $verified_by);
                if ($create_stmt->execute()) {
                    $_SESSION['coordinator_attendance_notice'] = 'Attendance record created.';
                    header('Location: attendance.php');
                    exit;
                }
                $error = 'Could not create the record. An attendance record may already exist for that intern and date.';
            }
        } else {
            $verified_by = $status === 'pending' ? null : $coordinator_id;
            $update_stmt = $conn->prepare("
                UPDATE attendance a
                JOIN internships i ON i.internship_id = a.internship_id
                SET a.attendance_date = ?, a.time_in = NULLIF(?, ''),
                    a.time_out = NULLIF(?, ''), a.hours_rendered = ?,
                    a.status = ?, a.verified_by = ?
                WHERE a.attendance_id = ? AND i.coordinator_id = ?
            ");
            $update_stmt->bind_param('sssdsiii', $date, $time_in, $time_out, $hours, $status, $verified_by, $attendance_id, $coordinator_id);
            if ($update_stmt->execute()) {
                $_SESSION['coordinator_attendance_notice'] = 'Attendance record updated.';
                header('Location: attendance.php');
                exit;
            }
            $error = 'Could not update the attendance record.';
        }
    }
}

$records_stmt = $conn->prepare("
    SELECT a.attendance_id, a.internship_id, a.attendance_date, a.time_in,
        a.time_out, a.hours_rendered, a.status, u.full_name, c.company_name
    FROM attendance a
    JOIN internships i ON i.internship_id = a.internship_id
    JOIN users u ON u.user_id = i.student_id
    JOIN companies c ON c.company_id = i.company_id
    WHERE i.coordinator_id = ?
    ORDER BY a.attendance_date DESC, a.attendance_id DESC
");
$records_stmt->bind_param('i', $coordinator_id);
$records_stmt->execute();
$records = $records_stmt->get_result();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="panel">
    <div class="panel-head"><div><h2>Manage Attendance</h2><p>Create or correct attendance records for your assigned internships.</p></div></div>
    <?php if ($notice !== ''): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($internships): ?>
        <details class="crud-editor" open>
            <summary>Record attendance</summary>
            <br>
            <form method="POST" class="crud-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Internship<select name="internship_id" required><option value="">Select intern</option><?php foreach ($internships as $internship): ?><option value="<?= (int) $internship['internship_id'] ?>"><?= htmlspecialchars($internship['full_name'] . ' — ' . $internship['company_name']) ?></option><?php endforeach; ?></select></label>
                <label>Date<input type="date" name="attendance_date" required value="<?= htmlspecialchars(date('Y-m-d')) ?>"></label>
                <label>Time in<input type="time" name="time_in"></label>
                <label>Time out<input type="time" name="time_out"></label>
                <label>Status<select name="attendance_status"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option><option value="pending">Pending verification</option></select></label>
                <button class="btn primary" type="submit" style="margin-top: 10px;">Create record</button>
            </form>
        </details>

    <?php else: ?>
        <div class="empty-state">There are no ongoing internships assigned to you.</div>
    <?php endif; ?>
    <br>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Company</th><th>Date</th><th>Time in</th><th>Time out</th><th>Hours</th><th>Status</th><th>Manage</th></tr></thead>
            <tbody>
                <?php if ($records->num_rows > 0): ?>
                    <?php while ($row = $records->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['company_name']) ?></td>
                            <td><?= htmlspecialchars($row['attendance_date']) ?></td>
                            <td><?= htmlspecialchars($row['time_in'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['time_out'] ?? '—') ?></td>
                            <td><?= number_format((float) $row['hours_rendered'], 2) ?></td>
                            <td><?= htmlspecialchars(ucfirst($row['status'])) ?></td>
                            <td>
                                <details class="crud-editor">
                                    <summary>Edit</summary>
                                    <form method="POST" class="crud-form stacked">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="attendance_id" value="<?= (int) $row['attendance_id'] ?>">
                                        <label>Date<input type="date" name="attendance_date" required value="<?= htmlspecialchars($row['attendance_date']) ?>"></label>
                                        <label>Time in<input type="time" name="time_in" value="<?= htmlspecialchars($row['time_in'] ?? '') ?>"></label>
                                        <label>Time out<input type="time" name="time_out" value="<?= htmlspecialchars($row['time_out'] ?? '') ?>"></label>
                                        <label>Status<select name="attendance_status"><?php foreach (['pending', 'present', 'late', 'absent'] as $option): ?><option value="<?= $option ?>" <?= $row['status'] === $option ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($option)) ?></option><?php endforeach; ?></select></label>
                                        <button class="btn primary" type="submit">Save changes</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="empty-state">No attendance records for your internships.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
