<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['student']);

$page_title = 'Requirements';
$student_id = (int) $_SESSION['user_id'];
$error = '';
$notice = $_SESSION['requirement_notice'] ?? '';
unset($_SESSION['requirement_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $requirement_raw = $_POST['requirement_id'] ?? null;
    $requirement_id = is_string($requirement_raw)
        ? filter_var($requirement_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $file = $_FILES['requirement_file'] ?? null;

    if ($requirement_id === false || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Choose a requirement and upload a file.';
    } elseif (!isset($file['size'], $file['tmp_name']) || !is_int($file['size'])
        || !is_string($file['tmp_name']) || $file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        $error = 'The file must be no larger than 5 MB.';
    } elseif (!class_exists('finfo')) {
        $error = 'File type checking is unavailable on this server. Enable PHP fileinfo to submit documents.';
    } else {
        $requirement_stmt = $conn->prepare("
            SELECT r.requirement_id, r.internship_id, r.status
            FROM requirements r
            JOIN internships i ON i.internship_id = r.internship_id
            WHERE r.requirement_id = ? AND i.student_id = ? AND i.status = 'ongoing'
            LIMIT 1
        ");
        $requirement_stmt->bind_param('ii', $requirement_id, $student_id);
        $requirement_stmt->execute();
        $requirement = $requirement_stmt->get_result()->fetch_assoc();

        $allowed_mime_types = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = is_uploaded_file($file['tmp_name'])
            ? $finfo->file($file['tmp_name'])
            : false;

        if (!$requirement || !in_array($requirement['status'], ['pending', 'rejected'], true)) {
            $error = 'This requirement is not available for submission.';
        } elseif (!is_string($mime_type) || !isset($allowed_mime_types[$mime_type])) {
            $error = 'Upload a PDF, JPG, PNG, or DOCX document.';
        } else {
            $upload_dir = __DIR__ . '/../uploads/requirements';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0750, true) && !is_dir($upload_dir)) {
                $error = 'The document upload directory could not be created.';
            } else {
                $stored_name = bin2hex(random_bytes(24)) . '.' . $allowed_mime_types[$mime_type];
                $stored_path = $upload_dir . '/' . $stored_name;
                if (!move_uploaded_file($file['tmp_name'], $stored_path)) {
                    $error = 'The uploaded document could not be saved.';
                } else {
                    $relative_path = 'requirements/' . $stored_name;
                    $save_stmt = $conn->prepare("
                        UPDATE requirements
                        SET file_path = ?, status = 'submitted', remarks = NULL,
                            submitted_at = NOW(), reviewed_at = NULL
                        WHERE requirement_id = ? AND internship_id = ?
                            AND status IN ('pending', 'rejected')
                    ");
                    $save_stmt->bind_param('sii', $relative_path, $requirement_id, $requirement['internship_id']);
                    $save_stmt->execute();
                    if ($save_stmt->affected_rows === 1) {
                        $_SESSION['requirement_notice'] = 'Your document was submitted for review.';
                        header('Location: requirements.php');
                        exit;
                    }
                    $removed = !is_file($stored_path) || unlink($stored_path);
                    $error = $removed
                        ? 'The requirement changed while uploading. Refresh the page and try again.'
                        : 'The requirement changed while uploading, and the temporary file could not be removed. Contact your coordinator.';
                }
            }
        }
    }
}

$stmt = $conn->prepare("
    SELECT r.requirement_id, r.requirement_name, r.status, r.remarks, r.submitted_at, r.file_path
    FROM requirements r
    JOIN internships i ON i.internship_id = r.internship_id
    WHERE i.student_id = ?
    ORDER BY r.requirement_id
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$requirements = $stmt->get_result();

require_once __DIR__ . '/../includes/header.php';
?>
<section class="panel">
    <div class="panel-head"><div><h2>Internship Requirements</h2><p>Upload required documents and track supervisor review.</p></div></div>
    <?php if ($notice !== ''): ?><div class="alert success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Requirement</th><th>Status</th><th>Submitted</th><th>Remarks</th><th>Document</th><th>Submit</th></tr></thead>
            <tbody>
                <?php if ($requirements->num_rows > 0): ?>
                    <?php while ($row = $requirements->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['requirement_name']) ?></td>
                            <td><span class="badge <?= htmlspecialchars($row['status']) ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
                            <td><?= htmlspecialchars($row['submitted_at'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['remarks'] ?: '—') ?></td>
                            <td>
                                <?php if (!empty($row['file_path'])): ?>
                                    <a href="requirement-download.php?id=<?= (int) $row['requirement_id'] ?>">Download</a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array($row['status'], ['pending', 'rejected'], true)): ?>
                                    <form method="POST" enctype="multipart/form-data" class="crud-form stacked">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="requirement_id" value="<?= (int) $row['requirement_id'] ?>">
                                        <label>PDF, JPG, PNG, or DOCX (max 5 MB)
                                            <input type="file" name="requirement_file" accept=".pdf,.jpg,.jpeg,.png,.docx" required>
                                        </label>
                                        <button class="btn primary" type="submit">Submit document</button>
                                    </form>
                                <?php else: ?>Submitted for review<?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="empty-state">No requirements are assigned to your internship yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
