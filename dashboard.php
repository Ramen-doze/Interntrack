<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$role = $_SESSION['role'];
if ($role !== 'student') {
    $role_dashboards = [
        'admin' => 'admin/admin-dashboard.php',
        'coordinator' => 'coordinator/coordinator-dashboard.php',
        'supervisor' => 'supervisor/supervisor-dashboard.php'
    ];
    if (isset($role_dashboards[$role])) {
        header('Location: ' . $role_dashboards[$role]);
        exit;
    }
}

$page_title = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$stats = [
    ['label' => 'Internship Status', 'value' => 'Not assigned', 'icon' => '●'],
    ['label' => 'Approved Requirements', 'value' => '0 / 0', 'icon' => '✓'],
    ['label' => 'Rendered Hours', 'value' => '0 hrs', 'icon' => '◷'],
    ['label' => 'Accomplishment Reports', 'value' => '0', 'icon' => '▤']
];

if ($role === 'student') {
    $stmt = $conn->prepare("SELECT i.internship_id, i.required_hours, i.status, c.company_name,
        COALESCE(SUM(a.hours_rendered),0) AS rendered
        FROM internships i
        JOIN companies c ON c.company_id=i.company_id
        LEFT JOIN attendance a ON a.internship_id=i.internship_id
            AND a.status IN ('present', 'late')
        WHERE i.student_id=? GROUP BY i.internship_id LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $internship = $stmt->get_result()->fetch_assoc();

    if ($internship) {
        $stats[0]['value'] = ucfirst($internship['status']);
        $requirement_stmt = $conn->prepare("
            SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'approved'), 0) AS approved
            FROM requirements
            WHERE internship_id = ?
        ");
        $requirement_stmt->bind_param('i', $internship['internship_id']);
        $requirement_stmt->execute();
        $requirement_stats = $requirement_stmt->get_result()->fetch_assoc();

        $report_stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM accomplishment_reports
            WHERE internship_id = ?
        ");
        $report_stmt->bind_param('i', $internship['internship_id']);
        $report_stmt->execute();
        $report_count = $report_stmt->get_result()->fetch_assoc();

        $stats[1]['value'] = (int) $requirement_stats['approved'] . ' / ' . (int) $requirement_stats['total'];
        $stats[2]['value'] = number_format((float)$internship['rendered'], 2) . ' hrs';
        $stats[3]['value'] = (string) (int) $report_count['total'];
    }
}
?>

<div class="cards">
<?php foreach ($stats as $stat): ?>
    <div class="stat-card">
        <div class="stat-icon"><?= $stat['icon'] ?></div>
        <div>
            <span><?= htmlspecialchars($stat['label']) ?></span>
            <strong><?= htmlspecialchars($stat['value']) ?></strong>
        </div>
    </div>
<?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <h2>Welcome to InternTrack</h2>
            <p>Manage and monitor internship activities from one centralized platform.</p>
        </div>
    </div>

    <?php if ($role === 'student' && !empty($internship)): ?>
        <div class="info-grid">
            <div><small>Assigned Company</small><strong><?= htmlspecialchars($internship['company_name']) ?></strong></div>
            <div><small>Required Hours</small><strong><?= (int)$internship['required_hours'] ?> hrs</strong></div>
            <div><small>Rendered Hours</small><strong><?= number_format((float)$internship['rendered'],2) ?> hrs</strong></div>
            <div><small>Status</small><strong><?= htmlspecialchars(ucfirst($internship['status'])) ?></strong></div>
        </div>
    <?php else: ?>
        <div class="empty-state">No internship is assigned yet. Contact your OJT coordinator to get started.</div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
