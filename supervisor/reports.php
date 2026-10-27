<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['supervisor']);

$page_title = 'Reports';

$supervisor_id = (int) ($_SESSION['user_id'] ?? 0);

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

$report_table_exists = false;

$table_check = $conn->query("
    SHOW TABLES LIKE 'accomplishment_reports'
");

if ($table_check && $table_check->num_rows > 0) {
    $report_table_exists = true;
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$total_reports = 0;
$submitted_reports = 0;
$reviewed_reports = 0;
$rejected_reports = 0;

$reports = [];

$reports_ready = (
    $report_table_exists &&
    $has_supervisor_id
);

$review_error = '';
$notice = $_SESSION['report_review_notice'] ?? '';
unset($_SESSION['report_review_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $report_id_raw = $_POST['report_id'] ?? null;
    $report_id = is_string($report_id_raw)
        ? filter_var($report_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
    $remarks = is_string($_POST['remarks'] ?? null) ? trim($_POST['remarks']) : '';

    if (!$reports_ready) {
        $review_error = 'Report review is not available until the report and supervisor assignment tables are set up.';
    } elseif ($report_id === false) {
        $review_error = 'The selected report is invalid.';
    } elseif (!in_array($decision, ['reviewed', 'rejected'], true)) {
        $review_error = 'Choose a valid report decision.';
    } elseif ($remarks === '' || strlen($remarks) > 10000) {
        $review_error = 'Enter feedback no longer than 10,000 characters.';
    } else {
        $review_stmt = $conn->prepare("
            UPDATE accomplishment_reports ar
            JOIN internships i ON i.internship_id = ar.internship_id
            SET ar.status = ?, ar.remarks = ?, ar.reviewed_at = NOW()
            WHERE ar.report_id = ? AND i.supervisor_id = ? AND ar.status = 'submitted'
        ");
        $review_stmt->bind_param('ssii', $decision, $remarks, $report_id, $supervisor_id);
        $review_stmt->execute();

        if ($review_stmt->affected_rows !== 1) {
            $review_error = 'This report is unavailable, has already been reviewed, or is not assigned to you.';
        } else {
            $_SESSION['report_review_notice'] = 'The report review was saved.';
            header('Location: reports.php');
            exit;
        }
    }
}


if ($reports_ready) {

    $summary_result = $conn->query("
    SELECT
        COUNT(*) AS total_reports,

        SUM(
            CASE
                WHEN ar.status = 'submitted'
                THEN 1
                ELSE 0
            END
        ) AS submitted_reports,

        SUM(
            CASE
                WHEN ar.status = 'reviewed'
                THEN 1
                ELSE 0
            END
        ) AS reviewed_reports,

        SUM(
            CASE
                WHEN ar.status = 'rejected'
                THEN 1
                ELSE 0
            END
        ) AS rejected_reports

    FROM accomplishment_reports ar

    JOIN internships i
        ON i.internship_id = ar.internship_id

    WHERE i.supervisor_id = {$supervisor_id}
");

    if ($summary_result) {

        $summary = $summary_result->fetch_assoc();

        $total_reports = (int) ($summary['total_reports'] ?? 0);
        $submitted_reports = (int) ($summary['submitted_reports'] ?? 0);
        $reviewed_reports = (int) ($summary['reviewed_reports'] ?? 0);
        $rejected_reports = (int) ($summary['rejected_reports'] ?? 0);
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
                OR ar.report_title LIKE '%{$safe_search}%'
            )
        ";
    }


    if (
        $status !== '' &&
        in_array(
            $status,
            ['submitted', 'reviewed', 'rejected'],
            true
        )
    ) {

        $safe_status = $conn->real_escape_string($status);

        $conditions[] = "
            ar.status = '{$safe_status}'
        ";
    }


    $where_sql = implode(' AND ', $conditions);

    $reports_result = $conn->query("
        SELECT
            ar.report_id,
            ar.week_number,
            ar.report_title,
            ar.description,
            ar.status,
            ar.remarks,
            ar.submitted_at,
            ar.reviewed_at,

            u.full_name AS student_name,
            u.email AS student_email,

            c.company_name

        FROM accomplishment_reports ar

        JOIN internships i
            ON i.internship_id = ar.internship_id

        JOIN users u
            ON u.user_id = i.student_id

        JOIN companies c
            ON c.company_id = i.company_id

        WHERE {$where_sql}

        ORDER BY ar.submitted_at DESC
    ");


    if ($reports_result) {

        while ($row = $reports_result->fetch_assoc()) {
            $reports[] = $row;
        }
    }
}


require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($notice !== ''): ?>
    <div class="alert success"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if ($review_error !== ''): ?>
    <div class="alert"><?= htmlspecialchars($review_error) ?></div>
<?php endif; ?>

<?php if (!$reports_ready): ?>

    <div class="panel">

        <div class="panel-head">

            <div>
                <h2>Reports Setup</h2>

                <p>
                    The accomplishment reports table is available,
                    but internship records are not yet linked to
                    supervisors in the database.
                </p>
            </div>

        </div>


        <div class="info-grid">

            <div>
                <small>Accomplishment Reports</small>
                <strong>
                    <?php echo $report_table_exists ? 'Found' : 'Not Found'; ?>
                </strong>
            </div>

            <div>
                <small>Supervisor Assignment</small>
                <strong>
                    <?php echo $has_supervisor_id ? 'Available' : 'Not Set'; ?>
                </strong>
            </div>

            <div>
                <small>Reports</small>
                <strong>0</strong>
            </div>

            <div>
                <small>Page Status</small>
                <strong>Waiting for Assignment</strong>
            </div>

        </div>

    </div>


<?php else: ?>


    <div class="cards">

        <div class="stat-card">
            <div class="stat-icon">R</div>
            <div>
                <span>Total Reports</span>
                <strong><?php echo $total_reports; ?></strong>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon">S</div>
            <div>
                <span>Submitted</span>
                <strong><?php echo $submitted_reports; ?></strong>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon">V</div>
            <div>
                <span>Reviewed</span>
                <strong><?php echo $reviewed_reports; ?></strong>
            </div>
        </div>


        <div class="stat-card">
            <div class="stat-icon">X</div>
            <div>
                <span>Rejected</span>
                <strong><?php echo $rejected_reports; ?></strong>
            </div>
        </div>

    </div>


    <div class="panel">

        <div class="panel-head">

            <div>
                <h2>Accomplishment Reports</h2>

                <p>
                    Review accomplishment reports submitted
                    by your assigned interns.
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
                    placeholder="Search by student, company, or report..."
                >

            </div>


            <div class="filter-group">

                <label for="status">Status</label>

                <select id="status" name="status">

                    <option value="">
                        All Statuses
                    </option>

                    <option
                        value="submitted"
                        <?php echo $status === 'submitted' ? 'selected' : ''; ?>
                    >
                        Submitted
                    </option>

                    <option
                        value="reviewed"
                        <?php echo $status === 'reviewed' ? 'selected' : ''; ?>
                    >
                        Reviewed
                    </option>

                    <option
                        value="rejected"
                        <?php echo $status === 'rejected' ? 'selected' : ''; ?>
                    >
                        Rejected
                    </option>

                </select>

            </div>


            <div class="filter-actions">

                <button
                    type="submit"
                    class="filter-submit"
                >
                    Filter
                </button>

                <a
                    href="reports.php"
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
                        <th>Week</th>
                        <th>Report</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Reviewed</th>
                        <th>Review</th>
                    </tr>

                </thead>


                <tbody>

                    <?php if ($reports): ?>

                        <?php foreach ($reports as $report): ?>

                            <tr>

                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $report['student_name']
                                    );
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $report['company_name']
                                    );
                                    ?>
                                </td>


                                <td>
                                    Week
                                    <?php
                                    echo htmlspecialchars(
                                        $report['week_number']
                                    );
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $report['report_title']
                                    );
                                    ?>
                                    <details class="report-details">
                                        <summary>View description</summary>
                                        <p><?= nl2br(htmlspecialchars($report['description'])) ?></p>
                                    </details>
                                </td>


                                <td>

                                    <span class="badge">

                                        <?php
                                        echo ucfirst(
                                            htmlspecialchars(
                                                $report['status']
                                            )
                                        );
                                        ?>

                                    </span>

                                </td>


                                <td>
                                    <?php
                                    echo !empty($report['submitted_at'])
                                        ? htmlspecialchars(
                                            date(
                                                'M d, Y h:i A',
                                                strtotime(
                                                    $report['submitted_at']
                                                )
                                            )
                                        )
                                        : '—';
                                    ?>
                                </td>


                                <td>
                                    <?php
                                    echo !empty($report['reviewed_at'])
                                        ? htmlspecialchars(
                                            date(
                                                'M d, Y h:i A',
                                                strtotime(
                                                    $report['reviewed_at']
                                                )
                                            )
                                        )
                                        : '—';
                                    ?>
                                </td>

                                <td>
                                    <?php if ($report['status'] === 'submitted'): ?>
                                        <form method="POST" class="review-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="report_id" value="<?= (int) $report['report_id'] ?>">
                                            <label class="sr-only" for="remarks-<?= (int) $report['report_id'] ?>">Feedback</label>
                                            <textarea
                                                id="remarks-<?= (int) $report['report_id'] ?>"
                                                name="remarks"
                                                maxlength="10000"
                                                rows="3"
                                                placeholder="Feedback for the student"
                                                required
                                            ></textarea>
                                            <div class="review-actions">
                                                <button class="btn primary" type="submit" name="decision" value="reviewed">Mark reviewed</button>
                                                <button class="btn danger" type="submit" name="decision" value="rejected">Request revision</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <?= htmlspecialchars($report['remarks'] ?: 'No feedback') ?>
                                    <?php endif; ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td colspan="8" class="empty-state">
                                No accomplishment reports found.
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
    grid-template-columns: minmax(220px, 1fr) 220px auto;
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

.empty-state {
    padding: 35px 20px;
    text-align: center;
    color: #777;
}

@media (max-width: 900px) {
    .filter-form {
        grid-template-columns: 1fr;
    }

    .filter-actions {
        justify-content: flex-start;
    }
}

</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>