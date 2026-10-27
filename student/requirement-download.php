<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['student', 'coordinator', 'supervisor']);

$requirement_raw = $_GET['id'] ?? null;
$requirement_id = is_string($requirement_raw)
    ? filter_var($requirement_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

if ($requirement_id === false) {
    http_response_code(400);
    exit('Invalid requirement.');
}

$role = $_SESSION['role'];
$owner_condition = match ($role) {
    'coordinator' => 'i.coordinator_id = ?',
    'supervisor' => 'i.supervisor_id = ?',
    default => 'i.student_id = ?'
};

$stmt = $conn->prepare("
    SELECT r.file_path, r.requirement_name
    FROM requirements r
    JOIN internships i ON i.internship_id = r.internship_id
    WHERE r.requirement_id = ? AND {$owner_condition}
    LIMIT 1
");
$stmt->bind_param('ii', $requirement_id, $_SESSION['user_id']);
$stmt->execute();
$requirement = $stmt->get_result()->fetch_assoc();

if (!$requirement || !$requirement['file_path']) {
    http_response_code(404);
    exit('Document not found.');
}

$filename = basename($requirement['file_path']);
$file_path = realpath(__DIR__ . '/../uploads/requirements/' . $filename);
$upload_root = realpath(__DIR__ . '/../uploads/requirements');

if (!$file_path || !$upload_root || !str_starts_with($file_path, $upload_root . DIRECTORY_SEPARATOR) || !is_file($file_path)) {
    http_response_code(404);
    exit('Document not found.');
}

$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$content_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

if (!isset($content_types[$extension])) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $content_types[$extension]);
header('Content-Length: ' . filesize($file_path));
header('Content-Disposition: attachment; filename="requirement-' . (int) $requirement_id . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
readfile($file_path);
exit;
