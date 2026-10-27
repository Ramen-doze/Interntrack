<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['student']);

$page_title = 'Accomplishment Reports';
$student_id = (int) $_SESSION['user_id'];
$error = '';
$form_week = '';
$form_title = '';
$form_description = '';

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

if ($internship && is_string($_GET['week'] ?? null)) {
    $edit_week = filter_var(
        $_GET['week'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
    );

    if ($edit_week !== false) {
        $revision_stmt = $conn->prepare("
            SELECT report_title, description
            FROM accomplishment_reports
            WHERE internship_id = ? AND week_number = ? AND status = 'rejected'
            LIMIT 1
        ");
        $revision_stmt->bind_param('ii', $internship['internship_id'], $edit_week);
        $revision_stmt->execute();
        $revision = $revision_stmt->get_result()->fetch_assoc();

        if ($revision) {
            $form_week = (string) $edit_week;
            $form_title = $revision['report_title'];
            $form_description = $revision['description'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $form_week = is_string($_POST['week_number'] ?? null)
        ? trim($_POST['week_number'])
        : '';
    $form_title = is_string($_POST['report_title'] ?? null)
        ? trim($_POST['report_title'])
        : '';
    $form_description = is_string($_POST['description'] ?? null)
        ? trim($_POST['description'])
        : '';
    $week_number = filter_var(
        $form_week,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]
    );

    if (!$internship) {
        $error = 'You need an ongoing internship assignment before submitting a report.';
    } elseif ($week_number === false) {
        $error = 'Enter a valid week number greater than zero.';
    } elseif ($form_title === '' || strlen($form_title) > 200) {
        $error = 'Enter a report title no longer than 200 characters.';
    } elseif ($form_description === '' || strlen($form_description) > 10000) {
        $error = 'Enter a report description no longer than 10,000 characters.';
    } else {
        $duplicate_stmt = $conn->prepare("
            SELECT report_id, status
            FROM accomplishment_reports
            WHERE internship_id = ? AND week_number = ?
            LIMIT 1
        ");
        $duplicate_stmt->bind_param('ii', $internship['internship_id'], $week_number);
        $duplicate_stmt->execute();

        $duplicate = $duplicate_stmt->get_result()->fetch_assoc();

        if ($duplicate && $duplicate['status'] !== 'rejected') {
            $error = 'A report has already been submitted for this week.';
        } elseif ($duplicate) {
            $revision_update = $conn->prepare("
                UPDATE accomplishment_reports
                SET report_title = ?, description = ?, status = 'submitted',
                    remarks = NULL, reviewed_at = NULL, submitted_at = NOW()
                WHERE report_id = ? AND internship_id = ? AND status = 'rejected'
            ");
            $revision_update->bind_param(
                'ssii',
                $form_title,
                $form_description,
                $duplicate['report_id'],
                $internship['internship_id']
            );
            $revision_update->execute();

            if ($revision_update->affected_rows !== 1) {
                $error = 'The report could not be resubmitted. Refresh the page and try again.';
            } else {
                $_SESSION['report_notice'] = 'Your revised accomplishment report was submitted.';
                header('Location: reports.php');
                exit;
            }
        } else {
            $insert_stmt = $conn->prepare("
                INSERT INTO accomplishment_reports
                    (internship_id, week_number, report_title, description)
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->bind_param(
                'iiss',
                $internship['internship_id'],
                $week_number,
                $form_title,
                $form_description
            );
            $insert_stmt->execute();

            $_SESSION['report_notice'] = 'Your accomplishment report was submitted.';
            header('Location: reports.php');
            exit;
        }
    }
}

$notice = $_SESSION['report_notice'] ?? '';
unset($_SESSION['report_notice']);

$reports_stmt = $conn->prepare("
    SELECT ar.week_number, ar.report_title, ar.description, ar.status,
        ar.submitted_at, ar.remarks
    FROM accomplishment_reports ar
    JOIN internships i ON i.internship_id = ar.internship_id
    WHERE i.student_id = ?
    ORDER BY ar.week_number DESC, ar.submitted_at DESC
");
$reports_stmt->bind_param('i', $student_id);
$reports_stmt->execute();
$reports = $reports_stmt->get_result();

require_once __DIR__ . '/../includes/header.php';
?>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Submit a Weekly Report</h2>
            <p>Record the work and learning activities completed during your OJT.</p>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert success"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($internship): ?>
        <form method="POST" class="report-form" id="report-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="report-field">
                <label for="week_number">Week number</label>
                <input
                    type="number"
                    id="week_number"
                    name="week_number"
                    min="1"
                    max="2147483647"
                    required
                    value="<?= htmlspecialchars($form_week) ?>"
                >
            </div>
            <br>
            <div class="report-field">
                <label for="report_title">Report title</label>
                <input
                    type="text"
                    id="report_title"
                    name="report_title"
                    maxlength="200"
                    required
                    value="<?= htmlspecialchars($form_title) ?>"
                >
            </div>
            <br>
            <div class="report-field full">
                <label for="description">Accomplishments and learnings</label>
                <textarea
                    id="description"
                    name="description"
                    maxlength="10000"
                    rows="6"
                    required
                ><?= htmlspecialchars($form_description) ?></textarea>
            </div>

            <div class="report-field full">
                <button class="btn primary" type="submit" style="margin-top: 10px;">Submit Report</button>
            </div>
        </form>
    <?php else: ?>
        <div class="empty-state">You need an ongoing internship assignment before submitting a report.</div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Your Accomplishment Reports</h2>
            <p>Review submitted reports and supervisor feedback.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Week</th>
                    <th>Title and Description</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Supervisor Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($reports->num_rows > 0): ?>
                    <?php while ($row = $reports->fetch_assoc()): ?>
                        <tr>
                            <td>Week <?= (int) $row['week_number'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['report_title']) ?></strong>
                                <div class="report-description"><?= nl2br(htmlspecialchars($row['description'])) ?></div>
                            </td>
                            <td>
                                <span class="badge <?= htmlspecialchars($row['status']) ?>">
                                    <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                </span>
                                <?php if ($row['status'] === 'rejected'): ?>
                                    <div><a href="reports.php?week=<?= (int) $row['week_number'] ?>#report-form">Revise this report</a></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['submitted_at'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['remarks'] ?: '-') ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="empty-state">No accomplishment reports submitted yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
