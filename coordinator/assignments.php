<?php

require_once __DIR__ . '/../includes/auth.php';
require_role(['coordinator']);

$page_title = 'Assignments';
$action_error = '';
$action_notice = $_SESSION['assignment_action_notice'] ?? '';
unset($_SESSION['assignment_action_notice']);

$students_for_assignment = $conn->query("
    SELECT user_id, full_name, student_number
    FROM users
    WHERE role = 'student'
    ORDER BY full_name
");
$companies_for_assignment = $conn->query("
    SELECT company_id, company_name
    FROM companies
    ORDER BY company_name
");
$supervisors_for_assignment = $conn->query("
    SELECT user_id, full_name
    FROM users
    WHERE role = 'supervisor'
    ORDER BY full_name
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id_value = is_string($_POST['internship_id'] ?? null) ? $_POST['internship_id'] : '';
    $student_value = is_string($_POST['student_id'] ?? null) ? $_POST['student_id'] : '';
    $company_value = is_string($_POST['company_id'] ?? null) ? $_POST['company_id'] : '';
    $supervisor_value = is_string($_POST['supervisor_id'] ?? null) ? $_POST['supervisor_id'] : '';
    $start_date = is_string($_POST['start_date'] ?? null) ? $_POST['start_date'] : '';
    $end_date = is_string($_POST['end_date'] ?? null) ? $_POST['end_date'] : '';
    $hours_value = is_string($_POST['required_hours'] ?? null) ? $_POST['required_hours'] : '';
    $status_value = is_string($_POST['assignment_status'] ?? null) ? $_POST['assignment_status'] : '';
    $internship_id = filter_var($id_value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $student_id = filter_var($student_value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $company_id = filter_var($company_value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $supervisor_id = $supervisor_value === '' ? null : filter_var($supervisor_value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $required_hours = filter_var($hours_value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    $valid_date = static function (string $value): bool {
        if ($value === '') {
            return true;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };
    $valid_action = in_array($action, ['create', 'update'], true);
    $valid_status = in_array($status_value, ['pending', 'ongoing', 'completed', 'cancelled'], true);

    if (!$valid_action || $student_id === false || $company_id === false
        || $required_hours === false || !$valid_status
        || ($action === 'update' && $internship_id === false)
        || ($supervisor_value !== '' && $supervisor_id === false)
        || !$valid_date($start_date) || !$valid_date($end_date)
        || ($start_date !== '' && $end_date !== '' && $end_date < $start_date)) {
        $action_error = 'Enter a student, company, valid date range, required hours, supervisor, and status.';
    } else {
        $active_only = $action === 'create' ? " AND status = 'active'" : '';
        $student_check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'student'{$active_only}");
        $student_check->bind_param('i', $student_id);
        $student_check->execute();
        $company_check = $conn->prepare("SELECT company_id FROM companies WHERE company_id = ?{$active_only}");
        $company_check->bind_param('i', $company_id);
        $company_check->execute();
        $valid_supervisor = true;
        if ($supervisor_id !== null) {
            $supervisor_check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'supervisor'{$active_only}");
            $supervisor_check->bind_param('i', $supervisor_id);
            $supervisor_check->execute();
            $valid_supervisor = (bool) $supervisor_check->get_result()->fetch_assoc();
        }

        if (!$student_check->get_result()->fetch_assoc() || !$company_check->get_result()->fetch_assoc() || !$valid_supervisor) {
            $action_error = 'Choose an active student, company, and supervisor.';
        } else {
            if ($action === 'create') {
                $duplicate_stmt = $conn->prepare("
                    SELECT internship_id FROM internships
                    WHERE student_id = ? AND status IN ('pending', 'ongoing')
                    LIMIT 1
                ");
                $duplicate_stmt->bind_param('i', $student_id);
                $duplicate_stmt->execute();
                if ($duplicate_stmt->get_result()->fetch_assoc()) {
                    $action_error = 'This student already has a pending or ongoing internship assignment.';
                } else {
                    $create_stmt = $conn->prepare("
                        INSERT INTO internships
                            (student_id, company_id, supervisor_id, coordinator_id, start_date, end_date, required_hours, status)
                        VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)
                    ");
                    $coordinator_id = (int) $_SESSION['user_id'];
                    $create_stmt->bind_param('iiiissis', $student_id, $company_id, $supervisor_id, $coordinator_id, $start_date, $end_date, $required_hours, $status_value);
                    if ($create_stmt->execute()) {
                        $_SESSION['assignment_action_notice'] = 'Internship assignment created.';
                        header('Location: assignments.php');
                        exit;
                    }
                    $action_error = 'Could not create the internship assignment.';
                }
            } else {
                $duplicate_stmt = $conn->prepare("
                    SELECT internship_id FROM internships
                    WHERE student_id = ? AND status IN ('pending', 'ongoing')
                        AND internship_id <> ?
                    LIMIT 1
                ");
                $duplicate_stmt->bind_param('ii', $student_id, $internship_id);
                $duplicate_stmt->execute();
                $duplicate_assignment = $duplicate_stmt->get_result()->fetch_assoc();

                if ($status_value !== 'cancelled' && $status_value !== 'completed'
                    && $duplicate_assignment) {
                    $action_error = 'This student already has another pending or ongoing internship assignment.';
                } else {
                    $update_stmt = $conn->prepare("
                        UPDATE internships
                        SET student_id = ?, company_id = ?, supervisor_id = ?, start_date = NULLIF(?, ''),
                            end_date = NULLIF(?, ''), required_hours = ?, status = ?
                        WHERE internship_id = ?
                    ");
                    $update_stmt->bind_param('iiissisi', $student_id, $company_id, $supervisor_id, $start_date, $end_date, $required_hours, $status_value, $internship_id);
                    if ($update_stmt->execute()) {
                        $_SESSION['assignment_action_notice'] = 'Internship assignment updated.';
                        header('Location: assignments.php');
                        exit;
                    }
                    $action_error = 'Could not update the internship assignment.';
                }
            }
        }
    }
}

$student_options = $students_for_assignment ? $students_for_assignment->fetch_all(MYSQLI_ASSOC) : [];
$company_options = $companies_for_assignment ? $companies_for_assignment->fetch_all(MYSQLI_ASSOC) : [];
$supervisor_options = $supervisors_for_assignment ? $supervisors_for_assignment->fetch_all(MYSQLI_ASSOC) : [];

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$allowed_statuses = [
    'pending',
    'ongoing',
    'completed',
    'cancelled'
];

$sql = "
    SELECT
        i.internship_id,
        i.student_id,
        i.company_id,
        i.supervisor_id,
        i.required_hours,
        i.status,
        i.start_date,
        i.end_date,
        i.created_at,
        u.full_name AS student_name,
        u.email AS student_email,
        c.company_name
    FROM internships i
    JOIN users u
        ON u.user_id = i.student_id
    JOIN companies c
        ON c.company_id = i.company_id
    WHERE 1=1
";

$params = [];
$types = '';


if ($search !== '') {

    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR c.company_name LIKE ?
        )
    ";

    $search_value = '%' . $search . '%';

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= 'sss';
}


if (in_array($status, $allowed_statuses, true)) {

    $sql .= " AND i.status = ?";

    $params[] = $status;

    $types .= 's';
}


$sql .= "
    ORDER BY i.created_at DESC
";

$stmt = $conn->prepare($sql);

$assignments = [];

if ($stmt) {

    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );

    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $assignments[] = $row;
    }

}

function assignment_count(
    mysqli $conn,
    string $condition = ''
): int {

    $sql = "SELECT COUNT(*) FROM internships";

    if ($condition !== '') {
        $sql .= " WHERE " . $condition;
    }

    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_row();

    return (int) ($row[0] ?? 0);
}


$total_assignments = assignment_count($conn);

$pending_assignments = assignment_count(
    $conn,
    "status = 'pending'"
);

$ongoing_assignments = assignment_count(
    $conn,
    "status = 'ongoing'"
);

$completed_assignments = assignment_count(
    $conn,
    "status = 'completed'"
);

require_once __DIR__ . '/../includes/header.php';

?>


<style>

    .filter-form {
        display: grid;
        grid-template-columns: 1.5fr 1fr auto auto;
        gap: 10px;
        margin-bottom: 20px;
    }

    .filter-form input,
    .filter-form select {
        width: 100%;
        padding: 11px 13px;
        border: 1px solid #dce2eb;
        border-radius: 8px;
        background: #fff;
        font-size: 14px;
        color: #374151;
        box-sizing: border-box;
    }

    .filter-form button,
    .clear-btn {
        border: none;
        border-radius: 8px;
        padding: 11px 16px;
        cursor: pointer;
        text-decoration: none;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .filter-form button {
        background: #3677ed;
        color: #fff;
    }

    .clear-btn {
        background: #eef1f6;
        color: #49566d;
    }

    .assignment-count {
        color: #748099;
        font-size: 13px;
        margin-top: 4px;
    }

    .assignment-badge {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        background: #eef4ff;
        color: #3677ed;
    }

    @media (max-width: 800px) {

        .filter-form {
            grid-template-columns: 1fr;
        }

    }

</style>

<div class="cards">

    <div class="stat-card">

        <div class="stat-icon">A</div>

        <div>

            <span>Total Assignments</span>

            <strong>
                <?= $total_assignments ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">P</div>

        <div>

            <span>Pending</span>

            <strong>
                <?= $pending_assignments ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">O</div>

        <div>

            <span>Ongoing</span>

            <strong>
                <?= $ongoing_assignments ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">C</div>

        <div>

            <span>Completed</span>

            <strong>
                <?= $completed_assignments ?>
            </strong>

        </div>

    </div>

</div>

<section class="panel" style="margin-top:24px;">

    <div class="panel-head">

        <div>

            <h2>Internship Assignments</h2>

            <p>
                View and monitor student-to-company internship assignments.
            </p>


        </div>


        <div class="assignment-badge">
            Coordinator View
        </div>

    </div>

    <?php if ($action_notice !== ''): ?><div class="alert success"><?= htmlspecialchars($action_notice) ?></div><?php endif; ?>
    <?php if ($action_error !== ''): ?><div class="alert"><?= htmlspecialchars($action_error) ?></div><?php endif; ?>

    <details class="crud-editor" open>
        <summary>Create internship assignment</summary>
        <br>
        <form method="POST" class="crud-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Student<select name="student_id" required><option value="">Select student</option><?php foreach ($student_options as $option): ?><option value="<?= (int) $option['user_id'] ?>"><?= htmlspecialchars($option['full_name'] . ($option['student_number'] ? ' (' . $option['student_number'] . ')' : '')) ?></option><?php endforeach; ?></select></label>
            <label>Company<select name="company_id" required><option value="">Select company</option><?php foreach ($company_options as $option): ?><option value="<?= (int) $option['company_id'] ?>"><?= htmlspecialchars($option['company_name']) ?></option><?php endforeach; ?></select></label>
            <label>Supervisor<select name="supervisor_id"><option value="">Not assigned</option><?php foreach ($supervisor_options as $option): ?><option value="<?= (int) $option['user_id'] ?>"><?= htmlspecialchars($option['full_name']) ?></option><?php endforeach; ?></select></label>
            <label>Start date<input type="date" name="start_date"></label>
            <label>End date<input type="date" name="end_date"></label>
            <label>Required hours<input type="number" name="required_hours" min="1" max="100000" required></label>
            <label>Status<select name="assignment_status"><option value="pending">Pending</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></label>
            <button class="btn primary" type="submit" style="margin-top: 10px;">Create assignment</button>
        </form>
    </details>
    <br>
    <form
        method="GET"
        class="filter-form"
    >

        <input
            type="text"
            name="search"
            placeholder="Search student or company..."
            value="<?= htmlspecialchars($search) ?>"
        >


        <select name="status">

            <option value="">
                All Statuses
            </option>

            <option
                value="pending"
                <?= $status === 'pending' ? 'selected' : '' ?>
            >
                Pending
            </option>

            <option
                value="ongoing"
                <?= $status === 'ongoing' ? 'selected' : '' ?>
            >
                Ongoing
            </option>

            <option
                value="completed"
                <?= $status === 'completed' ? 'selected' : '' ?>
            >
                Completed
            </option>

            <option
                value="cancelled"
                <?= $status === 'cancelled' ? 'selected' : '' ?>
            >
                Cancelled
            </option>

        </select>


        <button type="submit">
            Filter
        </button>


        <a
            href="/interntrack/coordinator/assignments.php"
            class="clear-btn"
        >
            Clear
        </a>

    </form>

    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Student</th>

                    <th>Company</th>

                    <th>Status</th>

                    <th>Start</th>

                    <th>End</th>

                    <th>Created</th>
                    <th>Manage</th>

                </tr>

            </thead>


            <tbody>

            <?php if ($assignments): ?>

                <?php foreach ($assignments as $assignment): ?>

                    <tr>

                        <td>

                            <strong>
                                <?= htmlspecialchars(
                                    $assignment['student_name']
                                ) ?>
                            </strong>

                            <div style="font-size:12px;color:#748099;margin-top:3px;">

                                <?= htmlspecialchars(
                                    $assignment['student_email']
                                ) ?>

                            </div>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $assignment['company_name']
                            ) ?>

                        </td>


                        <td>

                            <span class="badge
                                <?php
                                if ($assignment['status'] === 'completed') {
                                    echo 'approved';
                                } elseif ($assignment['status'] === 'cancelled') {
                                    echo 'rejected';
                                }
                                ?>
                            ">

                                <?= htmlspecialchars(
                                    ucfirst($assignment['status'])
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <?php if ($assignment['start_date']): ?>

                                <?= htmlspecialchars(
                                    date(
                                        'M d, Y',
                                        strtotime(
                                            $assignment['start_date']
                                        )
                                    )
                                ) ?>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php if ($assignment['end_date']): ?>

                                <?= htmlspecialchars(
                                    date(
                                        'M d, Y',
                                        strtotime(
                                            $assignment['end_date']
                                        )
                                    )
                                ) ?>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $assignment['created_at']
                                    )
                                )
                            ) ?>

                        </td>

                        <td>
                            <details class="crud-editor">
                                <summary>Edit</summary>
                                <form method="POST" class="crud-form stacked">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="internship_id" value="<?= (int) $assignment['internship_id'] ?>">
                                    <label>Student<select name="student_id" required><?php foreach ($student_options as $option): ?><option value="<?= (int) $option['user_id'] ?>" <?= (int) $option['user_id'] === (int) $assignment['student_id'] ? 'selected' : '' ?>><?= htmlspecialchars($option['full_name']) ?></option><?php endforeach; ?></select></label>
                                    <label>Company<select name="company_id" required><?php foreach ($company_options as $option): ?><option value="<?= (int) $option['company_id'] ?>" <?= (int) $option['company_id'] === (int) $assignment['company_id'] ? 'selected' : '' ?>><?= htmlspecialchars($option['company_name']) ?></option><?php endforeach; ?></select></label>
                                    <label>Supervisor<select name="supervisor_id"><option value="">Not assigned</option><?php foreach ($supervisor_options as $option): ?><option value="<?= (int) $option['user_id'] ?>" <?= (int) $option['user_id'] === (int) $assignment['supervisor_id'] ? 'selected' : '' ?>><?= htmlspecialchars($option['full_name']) ?></option><?php endforeach; ?></select></label>
                                    <label>Start date<input type="date" name="start_date" value="<?= htmlspecialchars($assignment['start_date'] ?? '') ?>"></label>
                                    <label>End date<input type="date" name="end_date" value="<?= htmlspecialchars($assignment['end_date'] ?? '') ?>"></label>
                                    <label>Required hours<input type="number" name="required_hours" min="1" max="100000" required value="<?= (int) $assignment['required_hours'] ?>"></label>
                                    <label>Status<select name="assignment_status"><?php foreach ($allowed_statuses as $option_status): ?><option value="<?= $option_status ?>" <?= $assignment['status'] === $option_status ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($option_status)) ?></option><?php endforeach; ?></select></label>
                                    <button class="btn primary" type="submit">Save changes</button>
                                </form>
                            </details>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No internship assignments found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>