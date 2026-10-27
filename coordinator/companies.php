<?php

require_once __DIR__ . '/../includes/auth.php';
require_role(['coordinator']);

$page_title = 'Companies';
$action_error = '';
$action_notice = $_SESSION['company_action_notice'] ?? '';
unset($_SESSION['company_action_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid form submission. Refresh the page and try again.');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $company_id_raw = $_POST['company_id'] ?? null;
    $company_id = is_string($company_id_raw)
        ? filter_var($company_id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $company_name = is_string($_POST['company_name'] ?? null) ? trim($_POST['company_name']) : '';
    $address = is_string($_POST['address'] ?? null) ? trim($_POST['address']) : '';
    $contact_person = is_string($_POST['contact_person'] ?? null) ? trim($_POST['contact_person']) : '';
    $contact_email = is_string($_POST['contact_email'] ?? null) ? trim($_POST['contact_email']) : '';
    $contact_number = is_string($_POST['contact_number'] ?? null) ? trim($_POST['contact_number']) : '';
    $company_status = is_string($_POST['company_status'] ?? null) ? $_POST['company_status'] : 'active';

    if (!in_array($action, ['create', 'update'], true) || $company_name === '' || strlen($company_name) > 150
        || strlen($address) > 255 || strlen($contact_person) > 150 || strlen($contact_number) > 50
        || ($contact_email !== '' && (!filter_var($contact_email, FILTER_VALIDATE_EMAIL) || strlen($contact_email) > 150))
        || !in_array($company_status, ['active', 'inactive'], true)
        || ($action === 'update' && $company_id === false)) {
        $action_error = 'Enter a company name and valid contact details.';
    } else {
        if ($action === 'create') {
            $save_stmt = $conn->prepare("
                INSERT INTO companies (company_name, address, contact_person, contact_email, contact_number, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $save_stmt->bind_param('ssssss', $company_name, $address, $contact_person, $contact_email, $contact_number, $company_status);
        } else {
            $save_stmt = $conn->prepare("
                UPDATE companies
                SET company_name = ?, address = ?, contact_person = ?, contact_email = ?, contact_number = ?, status = ?
                WHERE company_id = ?
            ");
            $save_stmt->bind_param('ssssssi', $company_name, $address, $contact_person, $contact_email, $contact_number, $company_status, $company_id);
        }

        if ($save_stmt->execute()) {
            $_SESSION['company_action_notice'] = $action === 'create' ? 'Company created.' : 'Company updated.';
            header('Location: companies.php');
            exit;
        }
        $action_error = 'Could not save this company. Check for invalid or duplicate details.';
    }
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';

$allowed_statuses = [
    'active',
    'inactive'
];

$sql = "
    SELECT
        c.company_id,
        c.company_name,
        c.status,
        c.address,
        c.contact_person,
        c.contact_email,
        c.contact_number,
        COUNT(i.internship_id) AS internship_count
    FROM companies c
    LEFT JOIN internships i
        ON i.company_id = c.company_id
    WHERE 1=1
";

$params = [];
$types = '';


if ($search !== '') {

    $sql .= "
        AND c.company_name LIKE ?
    ";

    $search_value = '%' . $search . '%';

    $params[] = $search_value;

    $types .= 's';
}


if (in_array($status, $allowed_statuses, true)) {

    $sql .= " AND c.status = ?";

    $params[] = $status;

    $types .= 's';
}


$sql .= "
    GROUP BY
        c.company_id,
        c.company_name,
        c.status,
        c.address,
        c.contact_person,
        c.contact_email,
        c.contact_number
    ORDER BY c.company_name ASC
";

$stmt = $conn->prepare($sql);

$companies = [];

if ($stmt) {

    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );

    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

}

function company_count(
    mysqli $conn,
    string $condition = ''
): int {

    $sql = "SELECT COUNT(*) FROM companies";

    if ($condition !== '') {
        $sql .= " WHERE " . $condition;
    }

    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_row();

    return (int) ($row[0] ?? 0);
}


$total_companies = company_count($conn);

$active_companies = company_count(
    $conn,
    "status = 'active'"
);

$inactive_companies = company_count(
    $conn,
    "status = 'inactive'"
);

require_once __DIR__ . '/../includes/header.php';

?>


<style>

    .filter-form {
        display: grid;
        grid-template-columns: 1.5fr 1fr auto auto;
        gap: 10px;
        margin-bottom: 20px;
    }

    .filter-form input,
    .filter-form select {
        width: 100%;
        padding: 11px 13px;
        border: 1px solid #dce2eb;
        border-radius: 8px;
        background: #fff;
        font-size: 14px;
        color: #374151;
        box-sizing: border-box;
    }

    .filter-form button,
    .clear-btn {
        border: none;
        border-radius: 8px;
        padding: 11px 16px;
        cursor: pointer;
        text-decoration: none;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .filter-form button {
        background: #3677ed;
        color: #fff;
    }

    .clear-btn {
        background: #eef1f6;
        color: #49566d;
    }

    .company-count {
        color: #748099;
        font-size: 13px;
        margin-top: 4px;
    }

    .company-badge {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        background: #eef4ff;
        color: #3677ed;
    }

    @media (max-width: 800px) {

        .filter-form {
            grid-template-columns: 1fr;
        }

    }

</style>

<div class="cards">

    <div class="stat-card">

        <div class="stat-icon">C</div>

        <div>

            <span>Total Companies</span>

            <strong>
                <?= $total_companies ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">A</div>

        <div>

            <span>Active Companies</span>

            <strong>
                <?= $active_companies ?>
            </strong>

        </div>

    </div>


    <div class="stat-card">

        <div class="stat-icon">I</div>

        <div>

            <span>Inactive Companies</span>

            <strong>
                <?= $inactive_companies ?>
            </strong>

        </div>

    </div>


</div>

<section class="panel" style="margin-top:24px;">

    <div class="panel-head">

        <div>

            <h2>Company Management</h2>

            <p>
                View and monitor partner companies registered in InternTrack.
            </p>

        </div>

        <div class="company-badge">
            Coordinator View
        </div>

    </div>

    <?php if ($action_notice !== ''): ?><div class="alert success"><?= htmlspecialchars($action_notice) ?></div><?php endif; ?>
    <?php if ($action_error !== ''): ?><div class="alert"><?= htmlspecialchars($action_error) ?></div><?php endif; ?>

    <details class="crud-editor" open>
        <summary>Add partner company</summary>
        <br>
        <form method="POST" class="crud-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label>Company name<input name="company_name" maxlength="150" required></label>
            <label>Address<input name="address" maxlength="255"></label>
            <label>Contact person<input name="contact_person" maxlength="150"></label>
            <label>Contact email<input type="email" name="contact_email" maxlength="150"></label>
            <label>Contact number<input name="contact_number" maxlength="50"></label>
            <button class="btn primary" type="submit" style="margin-top: 10px;">Add company</button>
        </form>
    </details>
    <br>
    <form
        method="GET"
        class="filter-form"
    >

        <input
            type="text"
            name="search"
            placeholder="Search company name..."
            value="<?= htmlspecialchars($search) ?>"
        >


        <select name="status">

            <option value="">
                All Statuses
            </option>

            <option
                value="active"
                <?= $status === 'active' ? 'selected' : '' ?>
            >
                Active
            </option>

            <option
                value="inactive"
                <?= $status === 'inactive' ? 'selected' : '' ?>
            >
                Inactive
            </option>

        </select>


        <button type="submit">
            Filter
        </button>


        <a
            href="/interntrack/coordinator/companies.php"
            class="clear-btn"
        >
            Clear
        </a>

    </form>

    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Company</th>

                    <th>Status</th>

                    <th>Internships</th>
                    <th>Manage</th>

                </tr>

            </thead>


            <tbody>

            <?php if ($companies): ?>

                <?php foreach ($companies as $company): ?>

                    <tr>

                        <td>

                            <strong>
                                <?= htmlspecialchars(
                                    $company['company_name']
                                ) ?>
                            </strong>

                        </td>

                        <td>

                            <span class="badge
                                <?= $company['status'] === 'active'
                                    ? 'approved'
                                    : 'rejected'
                                ?>
                            ">

                                <?= htmlspecialchars(
                                    ucfirst($company['status'])
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <span class="company-badge">

                                <?= (int) $company['internship_count'] ?>

                            </span>

                        </td>

                        <td>
                            <details class="crud-editor">
                                <summary>Edit</summary>
                                <form method="POST" class="crud-form stacked">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="company_id" value="<?= (int) $company['company_id'] ?>">
                                    <label>Name<input name="company_name" maxlength="150" required value="<?= htmlspecialchars($company['company_name']) ?>"></label>
                                    <label>Address<input name="address" maxlength="255" value="<?= htmlspecialchars($company['address'] ?? '') ?>"></label>
                                    <label>Contact person<input name="contact_person" maxlength="150" value="<?= htmlspecialchars($company['contact_person'] ?? '') ?>"></label>
                                    <label>Contact email<input type="email" name="contact_email" maxlength="150" value="<?= htmlspecialchars($company['contact_email'] ?? '') ?>"></label>
                                    <label>Contact number<input name="contact_number" maxlength="50" value="<?= htmlspecialchars($company['contact_number'] ?? '') ?>"></label>
                                    <label>Status<select name="company_status"><option value="active" <?= $company['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $company['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
                                    <button class="btn primary" type="submit">Save changes</button>
                                </form>
                            </details>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>

                    <td
                        colspan="4"
                        class="empty-state"
                    >
                        No companies found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>