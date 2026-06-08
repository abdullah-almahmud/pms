<?php
// /modules/admin/flags.php
require_once '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

require_login();
require_role(['admin']);

// Filter parameters
$filter_type = $_GET['flag_type'] ?? '';
$filter_status = $_GET['status'] ?? 'unresolved';

// Get enum values for dropdown
$stmt = $pdo->query("SHOW COLUMNS FROM employee_flags LIKE 'flag_type'");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
$enum_values = explode("','", $matches[1]);

// Query
$sql = "
    SELECT
        ef.id,
        ef.flag_type,
        ef.threshold_value,
        ef.actual_value,
        ef.flagged_at,
        ef.resolved_at,
        ef.resolve_note,
        u_flagged.name  AS flagged_user,
        u_flagged.email AS flagged_email,
        r.label         AS flagged_role,
        u_resolver.name AS resolved_by_name
    FROM employee_flags ef
    JOIN  users u_flagged  ON u_flagged.id  = ef.user_id
    JOIN  roles r          ON r.id          = u_flagged.role_id
    LEFT JOIN users u_resolver ON u_resolver.id = ef.resolved_by
    WHERE (:flag_type = '' OR ef.flag_type = :flag_type2)
      AND (
          (:status = 'unresolved' AND ef.resolved_at IS NULL)
       OR (:status2 = 'resolved'   AND ef.resolved_at IS NOT NULL)
       OR (:status3 = 'all')
      )
    ORDER BY ef.flagged_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':flag_type'  => $filter_type,
    ':flag_type2' => $filter_type,
    ':status'     => $filter_status,
    ':status2'    => $filter_status,
    ':status3'    => $filter_status
]);
$flags = $stmt->fetchAll(PDO::FETCH_ASSOC);

function humanize_flag($flag) {
    return ucwords(strtolower(str_replace('_', ' ', $flag)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Flag History</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .row-unresolved td:first-child { border-left: 4px solid var(--danger); }
        .row-resolved td:first-child { border-left: 4px solid var(--success); }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Flag History</h1>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <!-- FILTERS -->
        <div class="form-card" style="padding: 15px; margin-bottom: 24px;">
            <form method="get" action="" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Flag Type</label>
                    <select name="flag_type" class="form-control" style="padding: 8px; background: var(--bg-dark); color: var(--text-primary); border: 1px solid var(--border); border-radius: var(--radius-sm);">
                        <option value="">All Types</option>
                        <?php foreach ($enum_values as $val): ?>
                            <option value="<?= htmlspecialchars($val) ?>" <?= $filter_type === $val ? 'selected' : '' ?>>
                                <?= htmlspecialchars(humanize_flag($val)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Status</label>
                    <select name="status" class="form-control" style="padding: 8px; background: var(--bg-dark); color: var(--text-primary); border: 1px solid var(--border); border-radius: var(--radius-sm);">
                        <option value="unresolved" <?= $filter_status === 'unresolved' ? 'selected' : '' ?>>Unresolved</option>
                        <option value="resolved" <?= $filter_status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Flag Type</th>
                        <th>Threshold</th>
                        <th>Actual Value</th>
                        <th>Flagged At</th>
                        <th>Status</th>
                        <th>Resolved By</th>
                        <th>Resolve Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($flags as $row): ?>
                        <?php $is_resolved = !is_null($row['resolved_at']); ?>
                        <tr class="<?= $is_resolved ? 'row-resolved' : 'row-unresolved' ?>">
                            <td>
                                <?= htmlspecialchars($row['flagged_user']) ?><br>
                                <small style="background: rgba(255,255,255,0.08); color: var(--text-secondary); padding: 2px 6px; border-radius: 10px; font-size: 0.8em; margin-top: 4px; display: inline-block;">
                                    <?= htmlspecialchars($row['flagged_role']) ?>
                                </small>
                            </td>
                            <td><?= htmlspecialchars(humanize_flag($row['flag_type'])) ?></td>
                            <td><?= htmlspecialchars($row['threshold_value'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['actual_value'] ?? '-') ?></td>
                            <td><?= format_dt($row['flagged_at']) ?></td>
                            <td><?= $is_resolved ? status_badge('resolved') : status_badge('unresolved') ?></td>
                            <td><?= htmlspecialchars($row['resolved_by_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($row['resolve_note'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($flags)): ?>
                        <tr><td colspan="8" style="text-align: center;">No flags match the criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
