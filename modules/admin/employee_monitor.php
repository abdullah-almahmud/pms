<?php
// /modules/admin/employee_monitor.php
require_once '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

require_login();
require_role(['admin']);

// POST action — Resolve a flag
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_flag') {
    $flag_id = $_POST['flag_id'] ?? 0;
    $resolve_note = trim($_POST['resolve_note'] ?? '');
    $user_id = $_POST['user_id'] ?? 0;

    if ($flag_id && !empty($resolve_note)) {
        $stmt = $pdo->prepare("
            UPDATE employee_flags
            SET resolved_at = NOW(),
                resolved_by = :admin_id,
                resolve_note = :note
            WHERE id = :flag_id
              AND resolved_at IS NULL
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['user_id'],
            ':note' => $resolve_note,
            ':flag_id' => $flag_id
        ]);
        audit_log_entry($pdo, $_SESSION['user_id'], 'RESOLVE_FLAG', 'admin', $flag_id, 'Resolved flag ID ' . $flag_id);
    }
    header("Location: employee_monitor.php?user_id=" . urlencode($user_id));
    exit;
}

// Fetch all rows from vw_employee_task_summary
$stmt = $pdo->query("SELECT * FROM vw_employee_task_summary ORDER BY active_flags DESC, sla_breaches DESC, employee_name ASC");
$summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_users = count($summaries);
$total_active_flags = array_sum(array_column($summaries, 'active_flags'));
$total_sla_breaches = array_sum(array_column($summaries, 'sla_breaches'));

// If a specific user is selected for viewing flags
$selected_user_id = $_GET['user_id'] ?? 0;
$user_flags = [];
$selected_user_name = '';

if ($selected_user_id) {
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = :uid");
    $stmt->execute([':uid' => $selected_user_id]);
    $u = $stmt->fetch();
    if ($u) {
        $selected_user_name = $u['name'];
        $stmt = $pdo->prepare("SELECT * FROM employee_flags WHERE user_id = :uid AND resolved_at IS NULL ORDER BY flagged_at DESC");
        $stmt->execute([':uid' => $selected_user_id]);
        $user_flags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Employee Monitor</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .summary-bar { display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: var(--surface); border-radius: var(--radius-md); border: 1px solid var(--border); }
        .summary-stat { font-size: 1.2rem; font-weight: 600; color: var(--text-primary); }
        .badge-danger { color: #fff; background-color: var(--danger); padding: 3px 8px; border-radius: 12px; font-size: 0.9em; }
        .cell-amber { background-color: rgba(255, 193, 7, 0.2) !important; color: #ffc107 !important; font-weight: bold; }
        .flag-panel { margin-top: 30px; border-top: 1px solid var(--border); padding-top: 20px; }
        .flag-card { border: 1px solid var(--border); padding: 15px; margin-bottom: 15px; border-radius: var(--radius-md); background: var(--surface); }
        .flag-card form { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; }
        .flag-card textarea { width: 100%; height: 60px; padding: 8px; background: var(--bg-dark); color: var(--text-primary); border: 1px solid var(--border); border-radius: var(--radius-md); }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Employee Monitor</h1>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <!-- SUMMARY BAR -->
        <div class="summary-bar">
            <div class="summary-stat">Total Users: <?= htmlspecialchars($total_users) ?></div>
            <div class="summary-stat">Total Active Flags: <?= htmlspecialchars($total_active_flags) ?></div>
            <div class="summary-stat">Total SLA Breaches: <?= htmlspecialchars($total_sla_breaches) ?></div>
        </div>

        <!-- EMPLOYEE TABLE -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Employee Name</th>
                        <th>Role</th>
                        <th>Tasks (Pending / Active / Done)</th>
                        <th>Avg Task Time (mins)</th>
                        <th>SLA Breaches</th>
                        <th>Active Flags</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summaries as $row): ?>
                        <tr>
                            <td>
                                <?php if ($row['active_flags'] > 0): ?>
                                    <span class="badge-danger"><?= htmlspecialchars($row['employee_name']) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['employee_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><span style="background: rgba(255,255,255,0.08); color: var(--text-secondary); padding: 2px 8px; border-radius: 12px; font-size: 0.85em;"><?= htmlspecialchars($row['role_label']) ?></span></td>
                            <td>
                                <?= (int)$row['tasks_pending'] ?> / 
                                <?= (int)$row['tasks_active'] ?> / 
                                <?= (int)$row['tasks_done'] ?>
                            </td>
                            <td><?= htmlspecialchars($row['avg_task_minutes'] ?? '-') ?></td>
                            <td class="<?= $row['sla_breaches'] > 0 ? 'cell-amber' : '' ?>">
                                <?= (int)$row['sla_breaches'] ?>
                            </td>
                            <td><?= (int)$row['active_flags'] ?></td>
                            <td>
                                <?php if ($row['active_flags'] > 0): ?>
                                    <a href="?user_id=<?= urlencode($row['user_id']) ?>" class="btn btn-secondary btn-sm">View Flags</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($summaries)): ?>
                        <tr><td colspan="7">No employee data found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- FLAG PANEL -->
        <?php if ($selected_user_id && $selected_user_name): ?>
            <div class="flag-panel">
                <h2 style="margin-bottom: 15px;">Active Flags for <?= htmlspecialchars($selected_user_name) ?></h2>
                <?php if (empty($user_flags)): ?>
                    <p style="color: var(--text-muted);">No active flags found for this user.</p>
                <?php else: ?>
                    <?php foreach ($user_flags as $flag): ?>
                        <div class="flag-card">
                            <p><strong>Type:</strong> <?= htmlspecialchars($flag['flag_type']) ?></p>
                            <p><strong>Threshold:</strong> <?= htmlspecialchars($flag['threshold_value'] ?? 'N/A') ?></p>
                            <p><strong>Actual:</strong> <?= htmlspecialchars($flag['actual_value'] ?? 'N/A') ?></p>
                            <p><strong>Flagged At:</strong> <?= format_dt($flag['flagged_at']) ?></p>
                            
                            <form method="post" action="">
                                <input type="hidden" name="action" value="resolve_flag">
                                <input type="hidden" name="flag_id" value="<?= htmlspecialchars($flag['id']) ?>">
                                <input type="hidden" name="user_id" value="<?= htmlspecialchars($selected_user_id) ?>">
                                <label style="color: var(--text-secondary); margin-top: 10px;">Resolution Note:</label>
                                <textarea name="resolve_note" required placeholder="Enter notes here..."></textarea>
                                <div style="margin-top: 10px;">
                                    <button type="submit" class="btn btn-primary btn-sm">Resolve Flag</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
