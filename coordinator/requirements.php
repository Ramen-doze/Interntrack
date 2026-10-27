<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['coordinator']);

$page_title = 'Requirements';
$coordinator_id = (int) $_SESSION['user_id'];
$error = '';
$notice = $_SESSION['requirement_review_notice'] ?? '';
unset($_SESSION['requirement_review_notice']);

$internships_stmt = $conn->prepare("
    SELECT i.internship_id, u.full_name, c.company_name
    FROM internships i
    JOIN users u ON u.user_id = i.student_id
    JOIN companies c ON c.company_id = i.company_id
    WHERE i.coordinator_id = ? AND i.status IN ('pending', 'ongoing')
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

    $requirement_raw = $_POST['requirement_id'] ?? null;
    $requirement_id = is_string($requirement_raw)
        ? filter_var($requirement_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'review';
    $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
    $remarks = is_string($_POST['remarks'] ?? null) ? trim($_POST['remarks']) : '';

    if ($action === 'create') {
        $internship_raw = $_POST['internship_id'] ?? null;
        $internship_id = is_string($internship_raw)
            ? filter_var($internship_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $requirement_name = is_string($_POST['requirement_name'] ?? null) ? trim($_POST['requirement_name']) : '';

        if ($internship_id === false || $requirement_name === '' || strlen($requirement_name) > 150) {
            $error = 'Choose an assigned internship and enter a requirement name (up to 150 characters).';
        } else {
            $owned_stmt = $conn->prepare("
                SELECT internship_id FROM internships
                WHERE internship_id = ? AND coordinator_id = ?
                    AND status IN ('pending', 'ongoing')
            ");
            $owned_stmt->bind_param('ii', $internship_id, $coordinator_id);
            $owned_stmt->execute();
            if (!$owned_stmt->get_result()->fetch_assoc()) {
                $error = 'Choose an internship assigned to you.';
            } else {
                $duplicate_stmt = $conn->prepare("
                    SELECT requirement_id FROM requirements
                    WHERE internship_id = ? AND requirement_name = ?
                    LIMIT 1
                ");
                $duplicate_stmt->bind_param('is', $internship_id, $requirement_name);
                $duplicate_stmt->execute();
                if ($duplicate_stmt->get_result()->fetch_assoc()) {
                    $error = 'That requirement already exists for this internship.';
                } else {
                    $create_stmt = $conn->prepare("
                        INSERT INTO requirements (internship_id, requirement_name)
                        VALUES (?, ?)
                    ");
                    $create_stmt->bind_param('is', $internship_id, $requirement_name);
                    if ($create_stmt->execute()) {
                        $_SESSION['requirement_review_notice'] = 'Requirement added to the internship.';
                        header('Location: requirements.php');
                        exit;
                    }
                    $error = 'Could not add this requirement.';
                }
            }
        }
    } elseif ($action === 'rename') {
        $requirement_name = is_string($_POST['requirement_name'] ?? null) ? trim($_POST['requirement_name']) : '';
        if ($requirement_id === false || $requirement_name === '' || strlen($requirement_name) > 150) {
            $error = 'Enter a requirement name of up to 150 characters.';
        } else {
            $rename_stmt = $conn->prepare("
                UPDATE requirements r
                JOIN internships i ON i.internship_id = r.internship_id
                SET r.requirement_name = ?
                WHERE r.requirement_id = ? AND i.coordinator_id = ?
            ");
            $rename_stmt->bind_param('sii', $requirement_name, $requirement_id, $coordinator_id);
            if ($rename_stmt->execute()) {
                $_SESSION['requirement_review_notice'] = 'Requirement updated.';
                header('Location: requirements.php');
                exit;
            }
            $error = 'Could not update this requirement.';
        }
    } elseif ($action === 'review' && ($requirement_id === false
        || !in_array($decision, ['approved', 'rejected'], true)
        || $remarks === '' || strlen($remarks) > 10000)) {
        $error = 'Choose a review decision and provide feedback (up to 10,000 characters).';
    } elseif ($action === 'review') {
        $review_stmt = $conn->prepare("
            UPDATE requirements r
            JOIN internships i ON i.internship_id = r.internship_id
            SET r.status = ?, r.remarks = ?, r.reviewed_at = NOW()
            WHERE r.requirement_id = ? AND i.coordinator_id = ? AND r.status = 'submitted'
        ");
        $review_stmt->bind_param('ssii', $decision, $remarks, $requirement_id, $coordinator_id);
        $review_stmt->execute();

        if ($review_stmt->affected_rows !== 1) {
            $error = 'This requirement is not submitted or is not assigned to you.';
        } else {
            $_SESSION['requirement_review_notice'] = 'Requirement review saved.';
            header('Location: requirements.php');
            exit;
        }
    } else {
        $error = 'Choose a valid requirement action.';
    }
}

$stmt = $conn->prepare("
    SELECT r.requirement_id, r.requirement_name, r.status, r.remarks,
        r.submitted_at, r.file_path, u.full_name AS student_name,
        u.student_number, c.company_name
    FROM requirements r
    JOIN internships i ON i.internship_id = r.internship_id
    JOIN users u ON u.user_id = i.student_id
    JOIN companies c ON c.company_id = i.company_id
    WHERE i.coordinator_id = ?
    ORDER BY r.submitted_at DESC, r.requirement_id DESC
");
$stmt->bind_param('i', $coordinator_id);
$stmt->execute();
$requirements = $stmt->get_result();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="panel">
    <div class="panel-head"><div><h2>Student Requirement Reviews</h2><p>Review submitted documents for internships assigned to you.</p></div></div>
    <?php if ($notice !== ''): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($internships): ?>
        <details class="crud-editor" open>
            <summary>Add requirement</summary>
            <br>
            <form method="POST" class="crud-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Internship<select name="internship_id" required><option value="">Select intern</option><?php foreach ($internships as $internship): ?><option value="<?= (int) $internship['internship_id'] ?>"><?= htmlspecialchars($internship['full_name'] . ' — ' . $internship['company_name']) ?></option><?php endforeach; ?></select></label>
                <label>Requirement name<input name="requirement_name" maxlength="150" required></label>
                <button class="btn primary" type="submit">Add requirement</button>
            </form>
        </details>
    <?php endif; ?>
    <br>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Company</th><th>Requirement</th><th>Document</th><th>Status</th><th>Feedback</th><th>Review action</th><th>Manage</th></tr></thead>
            <tbody>
                <?php if ($requirements->num_rows > 0): ?>
                    <?php while ($row = $requirements->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_name']) ?><div class="muted"><?= htmlspecialchars($row['student_number'] ?? '') ?></div></td>
                            <td><?= htmlspecialchars($row['company_name']) ?></td>
                            <td><?= htmlspecialchars($row['requirement_name']) ?></td>
                            <td><?php if ($row['file_path']): ?><a href="../student/requirement-download.php?id=<?= (int) $row['requirement_id'] ?>">Download</a><?php else: ?>Not submitted<?php endif; ?></td>
                            <td><span class="badge <?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            <td><?= htmlspecialchars($row['remarks'] ?: '—') ?></td>
                            <td>
                                <?php if ($row['status'] === 'submitted'): ?>
                                    <form method="POST" class="crud-form stacked">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="requirement_id" value="<?= (int) $row['requirement_id'] ?>">
                                        <input type="hidden" name="action" value="review">
                                        <label>Feedback<textarea name="remarks" rows="3" maxlength="10000" required></textarea></label>
                                        <label>Decision<select name="decision"><option value="approved">Approve</option><option value="rejected">Request revision</option></select></label>
                                        <button class="btn primary" type="submit">Save review</button>
                                    </form>
                                <?php else: ?>No review needed<?php endif; ?>
                            </td>
                            <td>
                                <details class="crud-editor">
                                    <summary>Edit name</summary>
                                    <form method="POST" class="crud-form stacked">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="rename">
                                        <input type="hidden" name="requirement_id" value="<?= (int) $row['requirement_id'] ?>">
                                        <label>Requirement name<input name="requirement_name" maxlength="150" required value="<?= htmlspecialchars($row['requirement_name']) ?>"></label>
                                        <button class="btn primary" type="submit">Save name</button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="empty-state">No requirements are assigned to your internships.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
