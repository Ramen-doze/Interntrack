<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['supervisor']);

$page_title = 'Attendance';

$supervisor_id = (int) ($_SESSION['user_id'] ?? 0);

$attendance_table_exists = false;
$attendance_columns = [];

$table_check = $conn->query("
    SHOW TABLES LIKE 'attendance'
");

if ($table_check && $table_check->num_rows > 0) {
    $attendance_table_exists = true;

    $column_result = $conn->query("
        SHOW COLUMNS FROM attendance
    ");

    if ($column_result) {
        while ($column = $column_result->fetch_assoc()) {
            $attendance_columns[] = $column['Field'];
        }
    }
}

$has_supervisor_id = false;

$supervisor_column_check = $conn->query("
    SHOW COLUMNS FROM internships LIKE 'supervisor_id'
");

if (
    $supervisor_column_check &&
    $supervisor_column_check->num_rows > 0
) {
    $has_supervisor_id = true;
}

$student_column = null;
$internship_column = null;
$date_column = null;
$time_in_column = null;
$time_out_column = null;
$status_column = null;
$hours_column = null;

$student_candidates = [
    'student_id'
];

$internship_candidates = [
    'internship_id'
];

$date_candidates = [
    'attendance_date',
    'date'
];

$time_in_candidates = [
    'time_in',
    'check_in'
];

$time_out_candidates = [
    'time_out',
    'check_out'
];

$status_candidates = [
    'status'
];

$hours_candidates = [
    'hours_rendered',
    'rendered_hours',
    'hours'
];


foreach ($student_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $student_column = $candidate;
        break;
    }
}

foreach ($internship_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $internship_column = $candidate;
        break;
    }
}

foreach ($date_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $date_column = $candidate;
        break;
    }
}

foreach ($time_in_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $time_in_column = $candidate;
        break;
    }
}

foreach ($time_out_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $time_out_column = $candidate;
        break;
    }
}

foreach ($status_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $status_column = $candidate;
        break;
    }
}

foreach ($hours_candidates as $candidate) {
    if (in_array($candidate, $attendance_columns, true)) {
        $hours_column = $candidate;
        break;
    }
}

$attendance_ready =
    $attendance_table_exists &&
    $has_supervisor_id &&
    ($internship_column !== null || $student_column !== null);
$can_verify_attendance = $attendance_ready
    && in_array('attendance_id', $attendance_columns, true)
    && in_array('verified_by', $attendance_columns, true)
    && in_array('status', $attendance_columns, true);

$attendance_action_error = '';
$attendance_notice = $_SESSION['attendance_review_notice'] ?? '';
unset($_SESSION['attendance_review_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $attendance_id_raw = $_POST['attendance_id'] ?? null;
    $attendance_id = is_string($attendance_id_raw)
        ? filter_var($attendance_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';

    if (!$can_verify_attendance || $attendance_id === false
        || !in_array($decision, ['present', 'late', 'absent'], true)) {
        $attendance_action_error = 'Choose a valid attendance record and verification status.';
    } else {
        $verify_stmt = $conn->prepare("
            UPDATE attendance a
            JOIN internships i ON i.internship_id = a.internship_id
            SET a.status = ?, a.verified_by = ?
            WHERE a.attendance_id = ? AND i.supervisor_id = ?
                AND a.status = 'pending' AND a.time_out IS NOT NULL
        ");
        $verify_stmt->bind_param('siii', $decision, $supervisor_id, $attendance_id, $supervisor_id);
        $verify_stmt->execute();

        if ($verify_stmt->affected_rows !== 1) {
            $attendance_action_error = 'This record is not ready for verification or is not assigned to you.';
        } else {
            $_SESSION['attendance_review_notice'] = 'Attendance verification saved.';
            header('Location: attendance.php');
            exit;
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$date = trim($_GET['date'] ?? '');

$total_records = 0;
$present_records = 0;
$absent_records = 0;
$total_hours = 0;

$attendance_records = [];

if ($attendance_ready) {

    if ($internship_column !== null) {

        $attendance_link = "
            JOIN internships i
                ON i.internship_id = a.`{$internship_column}`
        ";

    } else {

        $attendance_link = "
            JOIN internships i
                ON i.student_id = a.`{$student_column}`
                AND i.supervisor_id = {$supervisor_id}
                AND i.status IN ('pending', 'ongoing', 'completed')
        ";
    }

    $where_summary = "i.supervisor_id = {$supervisor_id}";

    $summary_sql = "
        SELECT
            COUNT(*) AS total_records
        FROM attendance a
        {$attendance_link}
        WHERE {$where_summary}
    ";

    $summary_result = $conn->query($summary_sql);

    if ($summary_result) {
        $summary = $summary_result->fetch_assoc();

        $total_records = (int) ($summary['total_records'] ?? 0);
    }

    if ($status_column !== null) {

        $present_sql = "
            SELECT COUNT(*) AS total
            FROM attendance a
            {$attendance_link}
            WHERE i.supervisor_id = {$supervisor_id}
            AND LOWER(a.`{$status_column}`) = 'present'
        ";

        $present_result = $conn->query($present_sql);

        if ($present_result) {
            $present_records = (int) (
                $present_result->fetch_assoc()['total'] ?? 0
            );
        }

        $absent_sql = "
            SELECT COUNT(*) AS total
            FROM attendance a
            {$attendance_link}
            WHERE i.supervisor_id = {$supervisor_id}
            AND LOWER(a.`{$status_column}`) = 'absent'
        ";

        $absent_result = $conn->query($absent_sql);

        if ($absent_result) {
            $absent_records = (int) (
                $absent_result->fetch_assoc()['total'] ?? 0
            );
        }
    }

    if ($hours_column !== null) {

        $hours_sql = "
            SELECT COALESCE(SUM(a.`{$hours_column}`), 0) AS total_hours
            FROM attendance a
            {$attendance_link}
            WHERE i.supervisor_id = {$supervisor_id}
        ";

        if ($status_column !== null) {
            $hours_sql .= " AND LOWER(a.`{$status_column}`) IN ('present', 'late')";
        }

        $hours_result = $conn->query($hours_sql);

        if ($hours_result) {
            $total_hours = (float) (
                $hours_result->fetch_assoc()['total_hours'] ?? 0
            );
        }
    }

    $conditions = [
        "i.supervisor_id = {$supervisor_id}"
    ];


    if ($search !== '') {

        $safe_search = $conn->real_escape_string($search);

        $conditions[] = "
            (
                u.full_name LIKE '%{$safe_search}%'
                OR u.email LIKE '%{$safe_search}%'
                OR c.company_name LIKE '%{$safe_search}%'
            )
        ";
    }


    if (
        $status !== '' &&
        $status_column !== null
    ) {

        $safe_status = $conn->real_escape_string($status);

        $conditions[] = "
            LOWER(a.`{$status_column}`) = LOWER('{$safe_status}')
        ";
    }


    if (
        $date !== '' &&
        $date_column !== null &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    ) {

        $safe_date = $conn->real_escape_string($date);

        $conditions[] = "
            DATE(a.`{$date_column}`) = '{$safe_date}'
        ";
    }


    $where_sql = implode(' AND ', $conditions);

    $select_date = $date_column !== null
        ? "a.`{$date_column}` AS attendance_date"
        : "NULL AS attendance_date";

    $select_time_in = $time_in_column !== null
        ? "a.`{$time_in_column}` AS time_in"
        : "NULL AS time_in";

    $select_time_out = $time_out_column !== null
        ? "a.`{$time_out_column}` AS time_out"
        : "NULL AS time_out";

    $select_status = $status_column !== null
        ? "a.`{$status_column}` AS attendance_status"
        : "'Recorded' AS attendance_status";

    $select_hours = $hours_column !== null
        ? "a.`{$hours_column}` AS hours_rendered"
        : "NULL AS hours_rendered";
    $select_attendance_id = in_array('attendance_id', $attendance_columns, true)
        ? 'a.attendance_id'
        : 'NULL AS attendance_id';

    $records_sql = "
        SELECT
            {$select_attendance_id},
            u.full_name AS student_name,
            u.email AS student_email,
            c.company_name,
            {$select_date},
            {$select_time_in},
            {$select_time_out},
            {$select_status},
            {$select_hours}
        FROM attendance a

        {$attendance_link}

        JOIN users u
            ON u.user_id = i.student_id

        JOIN companies c
            ON c.company_id = i.company_id

        WHERE {$where_sql}

        ORDER BY
            attendance_date DESC
    ";


    $records_result = $conn->query($records_sql);

    if ($records_result) {

        while ($row = $records_result->fetch_assoc()) {
            $attendance_records[] = $row;
        }

    }
}


require_once __DIR__ . '/../includes/header.php';
?>


<?php if (!$attendance_ready): ?>

    <div class="panel">

        <div class="panel-head">

            <div>
                <h2>Attendance Setup</h2>

                <p>
                    The attendance page is ready, but the current database
                    does not yet expose all of the relationships needed to
                    display supervisor attendance records.
                </p>
            </div>

        </div>


        <div class="info-grid">

            <div>
                <small>Attendance Table</small>

                <strong>
                    <?php
                    echo $attendance_table_exists
                        ? 'Found'
                        : 'Not Found';
                    ?>
                </strong>
            </div>


            <div>
                <small>Supervisor Assignment</small>

                <strong>
                    <?php
                    echo $has_supervisor_id
                        ? 'Available'
                        : 'Not Set';
                    ?>
                </strong>
            </div>


            <div>
                <small>Attendance Link</small>

                <strong>
                    <?php
                    echo (
                        $internship_column !== null ||
                        $student_column !== null
                    )
                        ? 'Available'
                        : 'Not Found';
                    ?>
                </strong>
            </div>


            <div>
                <small>Records</small>

                <strong>0</strong>
            </div>

        </div>

    </div>


<?php else: ?>

    <?php if ($attendance_notice !== ''): ?><div class="alert success"><?= htmlspecialchars($attendance_notice) ?></div><?php endif; ?>
    <?php if ($attendance_action_error !== ''): ?><div class="alert"><?= htmlspecialchars($attendance_action_error) ?></div><?php endif; ?>

    <div class="cards">

        <div class="stat-card">
            <div class="stat-icon">A</div>

            <div>
                <span>Attendance Records</span>
                <strong><?php echo $total_records; ?></strong>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon">P</div>

            <div>
                <span>Present</span>
                <strong><?php echo $present_records; ?></strong>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon">X</div>

            <div>
                <span>Absent</span>
                <strong><?php echo $absent_records; ?></strong>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon">H</div>

            <div>
                <span>Hours Rendered</span>
                <strong><?php echo $total_hours; ?></strong>
            </div>
        </div>

    </div>


    <div class="panel">

        <div class="panel-head">

            <div>
                <h2>Attendance Monitoring</h2>

                <p>
                    Review attendance records and rendered hours
                    for your assigned interns.
                </p>
            </div>

        </div>


        <form method="GET" class="filter-form">

            <div class="filter-group">

                <label for="search">Search</label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="Search by student or company..."
                >

            </div>


            <?php if ($status_column !== null): ?>

                <div class="filter-group">

                    <label for="status">Status</label>

                    <select id="status" name="status">

                        <option value="">
                            All Statuses
                        </option>

                        <option
                            value="present"
                            <?php echo $status === 'present' ? 'selected' : ''; ?>
                        >
                            Present
                        </option>

                        <option
                            value="late"
                            <?php echo $status === 'late' ? 'selected' : ''; ?>
                        >
                            Late
                        </option>

                        <option
                            value="absent"
                            <?php echo $status === 'absent' ? 'selected' : ''; ?>
                        >
                            Absent
                        </option>

                    </select>

                </div>

            <?php endif; ?>


            <?php if ($date_column !== null): ?>

                <div class="filter-group">

                    <label for="date">Date</label>

                    <input
                        type="date"
                        id="date"
                        name="date"
                        value="<?php echo htmlspecialchars($date); ?>"
                    >

                </div>

            <?php endif; ?>


            <div class="filter-actions">

                <button type="submit" class="filter-submit">
                    Filter
                </button>

                <a
                    href="attendance.php"
                    class="filter-reset"
                >
                    Reset
                </a>

            </div>

        </form>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Student</th>
                        <th>Company</th>
                        <th>Date</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Hours</th>
                        <th>Status</th>
                        <?php if ($can_verify_attendance): ?><th>Verification</th><?php endif; ?>

                    </tr>

                </thead>


                <tbody>

                    <?php if ($attendance_records): ?>

                        <?php foreach ($attendance_records as $record): ?>

                            <tr>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $record['student_name']
                                    );
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $record['company_name']
                                    );
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo !empty($record['attendance_date'])
                                        ? htmlspecialchars(
                                            $record['attendance_date']
                                        )
                                        : '—';
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo !empty($record['time_in'])
                                        ? htmlspecialchars(
                                            $record['time_in']
                                        )
                                        : '—';
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo !empty($record['time_out'])
                                        ? htmlspecialchars(
                                            $record['time_out']
                                        )
                                        : '—';
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo $record['hours_rendered'] !== null
                                        ? htmlspecialchars(
                                            $record['hours_rendered']
                                        )
                                        : '—';
                                    ?>
                                </td>


                                <td>

                                    <span class="badge">
                                        <?php
                                        echo ucfirst(
                                            htmlspecialchars(
                                                $record['attendance_status']
                                            )
                                        );
                                        ?>
                                    </span>

                                </td>

                                <?php if ($can_verify_attendance): ?>
                                    <td>
                                        <?php if ($record['attendance_status'] === 'pending' && $record['time_out'] !== null && $record['attendance_id'] !== null): ?>
                                            <form method="POST" class="crud-form stacked">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                <input type="hidden" name="attendance_id" value="<?= (int) $record['attendance_id'] ?>">
                                                <label>Verification status<select name="decision"><option value="present">Present</option><option value="late">Late</option><option value="absent">Absent</option></select></label>
                                                <button class="btn primary" type="submit">Verify</button>
                                            </form>
                                        <?php elseif ($record['attendance_status'] === 'pending'): ?>
                                            Waiting for student check-out
                                        <?php else: ?>Verified<?php endif; ?>
                                    </td>
                                <?php endif; ?>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="<?= $can_verify_attendance ? '8' : '7' ?>" class="empty-state">
                                No attendance records found.
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

<?php endif; ?>

<style>

.filter-form {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 220px 220px auto;
    gap: 16px;
    align-items: end;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.filter-group label {
    font-size: 13px;
    font-weight: 600;
}

.filter-group input,
.filter-group select {
    width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #d9dee7;
    border-radius: 8px;
    background: #fff;
    color: #333;
    font-size: 14px;
    box-sizing: border-box;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #7a5cff;
    box-shadow: 0 0 0 3px rgba(122, 92, 255, 0.10);
}

.filter-actions {
    display: flex;
    gap: 8px;
}

.filter-submit,
.filter-reset {
    min-height: 42px;
    padding: 10px 18px;
    border-radius: 8px;
    border: 1px solid transparent;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}

.filter-submit {
    background: #007bff;
    color: #fff;
}

.filter-submit:hover {
    background: #0069d9;
}

.filter-reset {
    background: #f4f5f7;
    color: #333;
    border-color: #dfe2e7;
}

.filter-reset:hover {
    background: #e9ebef;
}

@media (max-width: 1000px) {
    .filter-form {
        grid-template-columns: minmax(220px, 1fr) 220px;
    }

    .filter-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 700px) {
    .filter-form {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        grid-column: auto;
        justify-content: flex-start;
    }
}

.empty-state {
    padding: 35px 20px;
    text-align: center;
    color: #777;
}

</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>