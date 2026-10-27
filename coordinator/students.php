<?php

require_once __DIR__ . '/../includes/auth.php';
require_role(['coordinator']);

$page_title = 'Students';
$action_error = '';
$action_notice = $_SESSION['student_action_notice'] ?? '';
unset($_SESSION['student_action_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $student_id_raw = $_POST['student_id'] ?? null;
    $student_id = is_string($student_id_raw)
        ? filter_var($student_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $full_name = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $student_number = is_string($_POST['student_number'] ?? null) ? trim($_POST['student_number']) : '';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $status_value = is_string($_POST['student_status'] ?? null) ? $_POST['student_status'] : 'active';

    if (!in_array($action, ['create', 'update'], true) || $full_name === '' || strlen($full_name) > 150
        || $student_number === '' || strlen($student_number) > 30
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150
        || !in_array($status_value, ['active', 'inactive'], true)
        || ($action === 'update' && $student_id === false)) {
        $action_error = 'Enter a valid student name, number, email, and account status.';
    } elseif ($action === 'create' && strlen($password) < 8) {
        $action_error = 'Set a temporary password of at least 8 characters.';
    } else {
        if ($action === 'create') {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $create_stmt = $conn->prepare("
                INSERT INTO users (student_number, full_name, email, password_hash, role, status)
                VALUES (?, ?, ?, ?, 'student', ?)
            ");
            $create_stmt->bind_param('sssss', $student_number, $full_name, $email, $password_hash, $status_value);
            $save_stmt = $create_stmt;
        } else {
            $save_stmt = $conn->prepare("
                UPDATE users
                SET student_number = ?, full_name = ?, email = ?, status = ?
                WHERE user_id = ? AND role = 'student'
            ");
            $save_stmt->bind_param('ssssi', $student_number, $full_name, $email, $status_value, $student_id);
        }

        if ($save_stmt->execute()) {
            $_SESSION['student_action_notice'] = $action === 'create' ? 'Student account created.' : 'Student account updated.';
            header('Location: students.php');
            exit;
        }
        $action_error = 'Could not save this student. Check that the email and student number are not already in use.';
    }
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$allowed_statuses = [
    'active',
    'inactive'
];

$sql = "
    SELECT
        u.user_id,
        u.student_number,
        u.full_name,
        u.email,
        u.status,
        u.created_at,
        COUNT(i.internship_id) AS internship_count
    FROM users u
    LEFT JOIN internships i
        ON i.student_id = u.user_id
    WHERE u.role = 'student'
";

$params = [];
$types = '';

if ($search !== '') {

    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
        )
    ";

    $search_value = '%' . $search . '%';

    $params[] = $search_value;
    $params[] = $search_value;

    $types .= 'ss';
}


if (in_array($status, $allowed_statuses, true)) {

    $sql .= " AND u.status = ?";

    $params[] = $status;

    $types .= 's';
}


$sql .= "
    GROUP BY
        u.user_id,
        u.full_name,
        u.email,
        u.status,
        u.student_number,
        u.created_at
    ORDER BY u.created_at DESC
";

$stmt = $conn->prepare($sql);

$students = [];

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
        $students[] = $row;
    }

}

function student_count(
    mysqli $conn,
    string $condition = ''
): int {

    $sql = "
        SELECT COUNT(*)
        FROM users
        WHERE role = 'student'
    ";

    if ($condition !== '') {
        $sql .= " AND " . $condition;
    }

    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_row();

    return (int) ($row[0] ?? 0);
}


$total_students = student_count($conn);

$active_students = student_count(
    $conn,
    "status = 'active'"
);

$inactive_students = student_count(
    $conn,
    "status = 'inactive'"
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

    .student-count {
        color: #748099;
        font-size: 13px;
        margin-top: 4px;
    }

    .role-badge {
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

        <div class="stat-icon">S</div>

        <div>

            <span>Total Students</span>

            <strong>
                <?= $total_students ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">A</div>

        <div>

            <span>Active Students</span>

            <strong>
                <?= $active_students ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">I</div>

        <div>

            <span>Inactive Students</span>

            <strong>
                <?= $inactive_students ?>
            </strong>

        </div>

    </div>


</div>

<section class="panel" style="margin-top:24px;">

    <div class="panel-head">

        <div>

            <h2>Student Management</h2>

            <p>
                View and monitor student accounts and internship records.
            </p>


        </div>


        <div class="role-badge">

            Coordinator View

        </div>

    </div>

    <?php if ($action_notice !== ''): ?><div class="alert success"><?= htmlspecialchars($action_notice) ?></div><?php endif; ?>
    <?php if ($action_error !== ''): ?><div class="alert"><?= htmlspecialchars($action_error) ?></div><?php endif; ?>

    <details class="crud-editor" open>
        <summary>Add student account</summary>
        <br>
        <form method="POST" class="crud-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Full name<input name="full_name" maxlength="150" required></label>
            <label>Student number<input name="student_number" maxlength="30" required></label>
            <label>Email<input type="email" name="email" maxlength="150" required></label>
            <label>Temporary password<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
            <button class="btn primary" type="submit" style="margin-top: 10px;">Create student</button>
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
            placeholder="Search student name or email..."
            value="<?= htmlspecialchars($search) ?>"
        >


        <select name="status">

            <option value="">
                All Statuses
            </option>

            <option
                value="active"
                <?= $status === 'active' ? 'selected' : '' ?>
            >
                Active
            </option>

            <option
                value="inactive"
                <?= $status === 'inactive' ? 'selected' : '' ?>
            >
                Inactive
            </option>

        </select>


        <button type="submit">
            Filter
        </button>


        <a
            href="/interntrack/coordinator/students.php"
            class="clear-btn"
        >
            Clear
        </a>

    </form>

    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Name</th>

                    <th>Email</th>
                    <th>Student Number</th>
                    <th>Status</th>
                    <th>Internships</th>
                    <th>Registered</th>
                    <th>Manage</th>

                </tr>

            </thead>


            <tbody>

            <?php if ($students): ?>

                <?php foreach ($students as $student): ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars(
                                $student['full_name']
                            ) ?>
                        </td>


                        <td>
                            <?= htmlspecialchars(
                                $student['email']
                            ) ?>
                        </td>

                        <td><?= htmlspecialchars($student['student_number'] ?? '—') ?></td>


                        <td>

                            <span class="badge
                                <?= $student['status'] === 'active'
                                    ? 'approved'
                                    : 'rejected'
                                ?>
                            ">

                                <?= htmlspecialchars(
                                    ucfirst($student['status'])
                                ) ?>

                            </span>

                        </td>

                        <td>

                            <span class="role-badge">

                                <?= (int) $student['internship_count'] ?>

                            </span>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                date(
                                    'M d, Y',
                                    strtotime(
                                        $student['created_at']
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
                                    <input type="hidden" name="student_id" value="<?= (int) $student['user_id'] ?>">
                                    <label>Name<input name="full_name" maxlength="150" required value="<?= htmlspecialchars($student['full_name']) ?>"></label>
                                    <label>Student number<input name="student_number" maxlength="30" required value="<?= htmlspecialchars($student['student_number'] ?? '') ?>"></label>
                                    <label>Email<input type="email" name="email" maxlength="150" required value="<?= htmlspecialchars($student['email']) ?>"></label>
                                    <label>Status<select name="student_status"><option value="active" <?= $student['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $student['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
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
                        No students found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>