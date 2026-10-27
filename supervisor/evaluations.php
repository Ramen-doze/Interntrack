<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['supervisor']);

$page_title = 'Evaluations';

$supervisor_id = (int)($_SESSION['user_id'] ?? 0);

$search = trim($_GET['search'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$action_error = '';
$action_notice = $_SESSION['evaluation_notice'] ?? '';
unset($_SESSION['evaluation_notice']);

$intern_options_result = $conn->prepare("
    SELECT i.internship_id, u.full_name, c.company_name
    FROM internships i
    JOIN users u ON u.user_id = i.student_id
    JOIN companies c ON c.company_id = i.company_id
    WHERE i.supervisor_id = ? AND i.status IN ('pending', 'ongoing', 'completed')
    ORDER BY u.full_name
");
$intern_options_result->bind_param('i', $supervisor_id);
$intern_options_result->execute();
$intern_options = $intern_options_result->get_result()->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $evaluation_id_raw = $_POST['evaluation_id'] ?? null;
    $evaluation_id = is_string($evaluation_id_raw)
        ? filter_var($evaluation_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $internship_raw = $_POST['internship_id'] ?? null;
    $internship_id = is_string($internship_raw)
        ? filter_var($internship_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $type = is_string($_POST['evaluation_type'] ?? null) ? $_POST['evaluation_type'] : '';
    $rating_value = is_string($_POST['rating'] ?? null) ? $_POST['rating'] : '';
    $rating = filter_var($rating_value, FILTER_VALIDATE_FLOAT);
    $comments = is_string($_POST['comments'] ?? null) ? trim($_POST['comments']) : '';

    if (!in_array($action, ['create', 'update'], true)
        || !in_array($type, ['midterm', 'final'], true)
        || $rating === false || $rating < 1 || $rating > 5
        || $comments === '' || strlen($comments) > 10000
        || ($action === 'create' && $internship_id === false)
        || ($action === 'update' && $evaluation_id === false)) {
        $action_error = 'Enter a rating from 1 to 5 and feedback, then choose a valid evaluation type.';
    } elseif ($action === 'create') {
        $owned_stmt = $conn->prepare("
            SELECT internship_id FROM internships
            WHERE internship_id = ? AND supervisor_id = ?
            LIMIT 1
        ");
        $owned_stmt->bind_param('ii', $internship_id, $supervisor_id);
        $owned_stmt->execute();
        if (!$owned_stmt->get_result()->fetch_assoc()) {
            $action_error = 'That internship is not assigned to you.';
        } else {
            $duplicate_stmt = $conn->prepare("
                SELECT evaluation_id FROM evaluations
                WHERE internship_id = ? AND evaluation_type = ?
                LIMIT 1
            ");
            $duplicate_stmt->bind_param('is', $internship_id, $type);
            $duplicate_stmt->execute();
            if ($duplicate_stmt->get_result()->fetch_assoc()) {
                $action_error = 'An evaluation of this type already exists for that internship.';
            } else {
                $create_stmt = $conn->prepare("
                    INSERT INTO evaluations (internship_id, evaluator_id, evaluation_type, rating, comments)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $create_stmt->bind_param('iisds', $internship_id, $supervisor_id, $type, $rating, $comments);
                if ($create_stmt->execute()) {
                    $_SESSION['evaluation_notice'] = 'Evaluation submitted.';
                    header('Location: evaluations.php');
                    exit;
                }
                $action_error = 'Could not save this evaluation.';
            }
        }
    } else {
        $duplicate_stmt = $conn->prepare("
            SELECT conflicting.evaluation_id
            FROM evaluations original_eval
            JOIN internships i ON i.internship_id = original_eval.internship_id
            JOIN evaluations conflicting
                ON conflicting.internship_id = original_eval.internship_id
                AND conflicting.evaluation_type = ?
                AND conflicting.evaluation_id <> original_eval.evaluation_id
            WHERE original_eval.evaluation_id = ? AND i.supervisor_id = ?
            LIMIT 1
        ");
        $duplicate_stmt->bind_param('sii', $type, $evaluation_id, $supervisor_id);
        $duplicate_stmt->execute();
        if ($duplicate_stmt->get_result()->fetch_assoc()) {
            $action_error = 'An evaluation of this type already exists for that internship.';
        } else {
            $update_stmt = $conn->prepare("
                UPDATE evaluations e
                JOIN internships i ON i.internship_id = e.internship_id
                SET e.evaluation_type = ?, e.rating = ?, e.comments = ?
                WHERE e.evaluation_id = ? AND i.supervisor_id = ?
            ");
            $update_stmt->bind_param('sdsii', $type, $rating, $comments, $evaluation_id, $supervisor_id);
            if ($update_stmt->execute()) {
                $_SESSION['evaluation_notice'] = 'Evaluation updated.';
                header('Location: evaluations.php');
                exit;
            }
            $action_error = 'Could not update this evaluation.';
        }
    }
}

$summary_result = $conn->query("
    SELECT
        COUNT(*) AS total_evaluations,

        SUM(
            CASE
                WHEN e.evaluation_type = 'midterm'
                THEN 1 ELSE 0
            END
        ) AS midterm_evaluations,

        SUM(
            CASE
                WHEN e.evaluation_type = 'final'
                THEN 1 ELSE 0
            END
        ) AS final_evaluations,

        COALESCE(
            AVG(
                CASE
                    WHEN e.rating > 0
                    THEN e.rating
                    ELSE NULL
                END
            ),
            0
        ) AS average_rating

    FROM evaluations e
    JOIN internships i
        ON i.internship_id = e.internship_id
    WHERE i.supervisor_id = {$supervisor_id}
");

$summary = $summary_result->fetch_assoc();

$total_evaluations = (int)($summary['total_evaluations'] ?? 0);
$midterm_evaluations = (int)($summary['midterm_evaluations'] ?? 0);
$final_evaluations = (int)($summary['final_evaluations'] ?? 0);
$average_rating = (float)($summary['average_rating'] ?? 0);

$where = [
    "i.supervisor_id = {$supervisor_id}"
];

if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);

    $where[] = "(
        u.full_name LIKE '%{$safe_search}%'
        OR u.email LIKE '%{$safe_search}%'
        OR c.company_name LIKE '%{$safe_search}%'
    )";
}

if (in_array($type_filter, ['midterm', 'final'], true)) {
    $safe_type = $conn->real_escape_string($type_filter);
    $where[] = "e.evaluation_type = '{$safe_type}'";
}

$where_sql = implode(' AND ', $where);

$evaluations_result = $conn->query("
    SELECT
        e.evaluation_id,
        e.internship_id,
        e.evaluation_type,
        e.rating,
        e.comments,
        e.submitted_at,

        u.full_name AS student_name,
        u.email AS student_email,

        c.company_name

    FROM evaluations e

    JOIN internships i
        ON i.internship_id = e.internship_id

    JOIN users u
        ON u.user_id = i.student_id

    JOIN companies c
        ON c.company_id = i.company_id

    WHERE {$where_sql}

    ORDER BY e.submitted_at DESC, e.evaluation_id DESC
");

include __DIR__ . '/../includes/header.php';
?>

<style>

.evaluation-page {
    width: 100%;
}

.evaluation-page .evaluation-filter {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) 220px auto;
    gap: 16px;
    align-items: end;
}

.evaluation-page .evaluation-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.evaluation-page .evaluation-field label {
    font-size: 13px;
    font-weight: 600;
}

.evaluation-page .evaluation-field input,
.evaluation-page .evaluation-field select {
    width: 100%;
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #d9dee7;
    border-radius: 8px;
    background: #fff;
    font-size: 14px;
    box-sizing: border-box;
}

.evaluation-page .evaluation-field input:focus,
.evaluation-page .evaluation-field select:focus {
    outline: none;
    border-color: #7a5cff;
    box-shadow: 0 0 0 3px rgba(122, 92, 255, 0.10);
}

.evaluation-page .evaluation-actions {
    display: flex;
    gap: 8px;
}

.evaluation-page .evaluation-actions button,
.evaluation-page .evaluation-actions a {
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

.evaluation-page .evaluation-actions button {
    background: #007bff;
    color: #fff;
}

.evaluation-page .evaluation-actions button:hover {
    opacity: 0.9;
}

.evaluation-page .evaluation-actions a {
    background: #f4f5f7;
    color: #333;
    border-color: #dfe2e7;
}

.evaluation-page .evaluation-actions a:hover {
    background: #e9ebef;
}

.evaluation-page .evaluation-table {
    width: 100%;
    min-width: 1050px;
}

.evaluation-page .evaluation-table th,
.evaluation-page .evaluation-table td {
    vertical-align: middle;
}

.evaluation-page .evaluation-table .student-name {
    font-weight: 600;
}

.evaluation-page .evaluation-table .email {
    white-space: nowrap;
}

.evaluation-page .evaluation-table .comments {
    max-width: 280px;
    white-space: normal;
    line-height: 1.45;
}

.evaluation-page .evaluation-table .rating {
    font-weight: 600;
    white-space: nowrap;
}

.evaluation-page .type-badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
}

.evaluation-page .empty-state {
    padding: 35px 20px;
    text-align: center;
    color: #777;
}

@media (max-width: 900px) {
    .evaluation-page .evaluation-filter {
        grid-template-columns: 1fr;
    }

    .evaluation-page .evaluation-actions {
        justify-content: flex-start;
    }
}
</style>

<div class="evaluation-page">

    <?php if ($action_notice !== ''): ?><div class="alert success"><?= htmlspecialchars($action_notice) ?></div><?php endif; ?>
    <?php if ($action_error !== ''): ?><div class="alert"><?= htmlspecialchars($action_error) ?></div><?php endif; ?>

    <div class="panel">
        <div class="panel-head"><div><h2>Create Evaluation</h2><p>Submit a midterm or final evaluation for an assigned intern.</p></div></div>
        <?php if ($intern_options): ?>
            <form method="POST" class="crud-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Internship<select name="internship_id" required><option value="">Select intern</option><?php foreach ($intern_options as $option): ?><option value="<?= (int) $option['internship_id'] ?>"><?= htmlspecialchars($option['full_name'] . ' — ' . $option['company_name']) ?></option><?php endforeach; ?></select></label>
                <label>Type<select name="evaluation_type" required><option value="midterm">Midterm</option><option value="final">Final</option></select></label>
                <label>Rating (1–5)<input type="number" name="rating" min="1" max="5" step="0.01" required></label>
                <label>Comments<textarea name="comments" maxlength="10000" rows="3" required></textarea></label>
                <button class="btn primary" type="submit">Submit evaluation</button>
            </form>
        <?php else: ?>
            <div class="empty-state">No assigned internships are available for evaluation.</div>
        <?php endif; ?>
    </div>

    <div class="cards">

        <div class="stat-card">
            <div class="stat-icon">E</div>
            <div>
                <span>Total Evaluations</span>
                <strong><?php echo $total_evaluations; ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">M</div>
            <div>
                <span>Midterm Evaluations</span>
                <strong><?php echo $midterm_evaluations; ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">F</div>
            <div>
                <span>Final Evaluations</span>
                <strong><?php echo $final_evaluations; ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">R</div>
            <div>
                <span>Average Rating</span>
                <strong><?php echo number_format($average_rating, 2); ?></strong>
            </div>
        </div>

    </div>

    <div class="panel">

        <div class="panel-head">
            <div>
                <h2>Filter Evaluations</h2>
                <p>Search by student or company, or filter by evaluation type.</p>
            </div>
        </div>

        <form method="GET">

            <div class="evaluation-filter">

                <div class="evaluation-field">
                    <label for="search">Search</label>

                    <input
                        type="text"
                        id="search"
                        name="search"
                        placeholder="Student name, email, or company"
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>


                <div class="evaluation-field">
                    <label for="type">Evaluation Type</label>

                    <select id="type" name="type">

                        <option value="">All Types</option>

                        <option
                            value="midterm"
                            <?php echo $type_filter === 'midterm' ? 'selected' : ''; ?>
                        >
                            Midterm
                        </option>

                        <option
                            value="final"
                            <?php echo $type_filter === 'final' ? 'selected' : ''; ?>
                        >
                            Final
                        </option>

                    </select>
                </div>


                <div class="evaluation-actions">
                    <button type="submit">Filter</button>

                    <a href="evaluations.php">
                        Reset
                    </a>
                </div>

            </div>

        </form>

    </div>

    <div class="panel">

        <div class="panel-head">
            <div>
                <h2>Submitted Evaluations</h2>
                <p>
                    Evaluation records submitted for your assigned interns.
                </p>
            </div>
        </div>


        <div class="table-wrap">

            <table class="evaluation-table">

                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Type</th>
                        <th>Rating</th>
                        <th>Comments</th>
                        <th>Submitted</th>
                        <th>Manage</th>
                    </tr>
                </thead>


                <tbody>

                    <?php if ($evaluations_result && $evaluations_result->num_rows > 0): ?>

                        <?php while ($evaluation = $evaluations_result->fetch_assoc()): ?>

                            <tr>

                                <td class="student-name">
                                    <?php echo htmlspecialchars($evaluation['student_name']); ?>
                                </td>

                                <td class="email">
                                    <?php echo htmlspecialchars($evaluation['student_email']); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($evaluation['company_name']); ?>
                                </td>

                                <td>
                                    <span class="type-badge">
                                        <?php echo ucfirst(htmlspecialchars($evaluation['evaluation_type'])); ?>
                                    </span>
                                </td>

                                <td class="rating">
                                    <?php
                                    $rating = (float)($evaluation['rating'] ?? 0);

                                    if ($rating > 0) {
                                        echo number_format($rating, 2);
                                    } else {
                                        echo 'Not rated';
                                    }
                                    ?>
                                </td>

                                <td class="comments">
                                    <?php
                                    $comments = trim($evaluation['comments'] ?? '');

                                    if ($comments !== '') {
                                        echo htmlspecialchars($comments);
                                    } else {
                                        echo 'No comments';
                                    }
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    if (!empty($evaluation['submitted_at'])) {
                                        echo date(
                                            'M d, Y h:i A',
                                            strtotime($evaluation['submitted_at'])
                                        );
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>

                                <td>
                                    <details class="crud-editor">
                                        <summary>Edit</summary>
                                        <form method="POST" class="crud-form stacked">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="evaluation_id" value="<?= (int) $evaluation['evaluation_id'] ?>">
                                            <label>Type<select name="evaluation_type"><option value="midterm" <?= $evaluation['evaluation_type'] === 'midterm' ? 'selected' : '' ?>>Midterm</option><option value="final" <?= $evaluation['evaluation_type'] === 'final' ? 'selected' : '' ?>>Final</option></select></label>
                                            <label>Rating<input type="number" name="rating" min="1" max="5" step="0.01" required value="<?= htmlspecialchars((string) $evaluation['rating']) ?>"></label>
                                            <label>Comments<textarea name="comments" maxlength="10000" rows="3" required><?= htmlspecialchars($evaluation['comments'] ?? '') ?></textarea></label>
                                            <button class="btn primary" type="submit">Save evaluation</button>
                                        </form>
                                    </details>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    No evaluation records found.
                                </div>
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>