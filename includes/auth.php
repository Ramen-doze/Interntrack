<?php
require_once __DIR__ . '/../config/config.php';

function require_login(): void {
    global $conn;

    if (!isset($_SESSION['user_id'])) {
        header('Location: /interntrack/index.php');
        exit;
    }

    $user_id = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT status
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Could not validate the current account.');
    }

    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not validate the current account.');
    }

    $account = $stmt->get_result()->fetch_assoc();
    if (!$account || $account['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        header('Location: /interntrack/index.php');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();

    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function csrf_token(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(mixed $token): bool {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
?>
