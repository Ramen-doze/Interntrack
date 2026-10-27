<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['coordinator']);

$page_title = 'Reports';

$total_students = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
    WHERE role = 'student'
")->fetch_assoc()['total'];

$total_companies = $conn->query("
    SELECT COUNT(*) AS total
    FROM companies
")->fetch_assoc()['total'];

$total_internships = $conn->query("
    SELECT COUNT(*) AS total
    FROM internships
")->fetch_assoc()['total'];

$ongoing_internships = $conn->query("
    SELECT COUNT(*) AS total
    FROM internships
    WHERE status = 'ongoing'
")->fetch_assoc()['total'];

$completed_internships = $conn->query("
    SELECT COUNT(*) AS total
    FROM internships
    WHERE status = 'completed'
")->fetch_assoc()['total'];

$pending_internships = $conn->query("
    SELECT COUNT(*) AS total
    FROM internships
    WHERE status = 'pending'
")->fetch_assoc()['total'];

$pending_requirements = $conn->query("
    SELECT COUNT(*) AS total
    FROM requirements
    WHERE status = 'pending'
")->fetch_assoc()['total'];

$pending_reports = $conn->query("
    SELECT COUNT(*) AS total
    FROM accomplishment_reports
    WHERE status = 'submitted'
")->fetch_assoc()['total'];

$status_summary = $conn->query("
    SELECT
        status,
        COUNT(*) AS total
    FROM internships
    GROUP BY status
    ORDER BY total DESC
");

$recent_internships = $conn->query("
    SELECT
        i.internship_id,
        i.status,
        i.start_date,
        i.end_date,
        i.created_at,
        u.full_name AS student_name,
        c.company_name
    FROM internships i
    JOIN users u ON u.user_id = i.student_id
    JOIN companies c ON c.company_id = i.company_id
    ORDER BY i.created_at DESC
    LIMIT 10
");

require_once __DIR__ . '/../includes/header.php';
?>

<div class="cards">

    <div class="stat-card">
        <div class="stat-icon">S</div>
        <div>
            <span>Total Students</span>
            <strong><?php echo $total_students; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">C</div>
        <div>
            <span>Partner Companies</span>
            <strong><?php echo $total_companies; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">I</div>
        <div>
            <span>Total Internships</span>
            <strong><?php echo $total_internships; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">O</div>
        <div>
            <span>Ongoing Internships</span>
            <strong><?php echo $ongoing_internships; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">C</div>
        <div>
            <span>Completed Internships</span>
            <strong><?php echo $completed_internships; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">P</div>
        <div>
            <span>Pending Internships</span>
            <strong><?php echo $pending_internships; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">R</div>
        <div>
            <span>Pending Requirements</span>
            <strong><?php echo $pending_requirements; ?></strong>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">A</div>
        <div>
            <span>Pending Reports</span>
            <strong><?php echo $pending_reports; ?></strong>
        </div>
    </div>

</div>


<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Internship Status Summary</h2>
            <p>Current distribution of internship records.</p>
        </div>
    </div>

    <div class="info-grid">

        <?php if ($status_summary && $status_summary->num_rows > 0): ?>

            <?php while ($row = $status_summary->fetch_assoc()): ?>

                <div class="info-item">
                    <span class="info-label">
                        <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                    </span>

                    <span class="info-value">
                        <?php echo $row['total']; ?>
                    </span>
                </div>

            <?php endwhile; ?>

        <?php else: ?>

            <div class="info-item">
                <span class="info-label">No internship records</span>
                <span class="info-value">0</span>
            </div>

        <?php endif; ?>

    </div>
</div>


<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Recent Internship Records</h2>
            <p>Latest internship assignments recorded in the system.</p>
        </div>
    </div>

    <div class="table-wrap">

        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Company</th>
                    <th>Status</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Created</th>
                </tr>
            </thead>

            <tbody>

                <?php if ($recent_internships && $recent_internships->num_rows > 0): ?>

                    <?php while ($row = $recent_internships->fetch_assoc()): ?>

                        <tr>
                            <td>
                                <?php echo htmlspecialchars($row['student_name']); ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['company_name']); ?>
                            </td>

                            <td>
                                <span class="badge badge-<?php echo htmlspecialchars($row['status']); ?>">
                                    <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                echo !empty($row['start_date'])
                                    ? htmlspecialchars($row['start_date'])
                                    : '—';
                                ?>
                            </td>

                            <td>
                                <?php
                                echo !empty($row['end_date'])
                                    ? htmlspecialchars($row['end_date'])
                                    : '—';
                                ?>
                            </td>

                            <td>
                                <?php echo htmlspecialchars($row['created_at']); ?>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" style="text-align: center;">
                            No internship records found.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>
        </table>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>