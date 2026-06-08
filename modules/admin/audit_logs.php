<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin']); // Only admins can see audit logs

// GET Data & Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$module_filter = $_GET['module'] ?? 'all';
$action_filter = $_GET['action_type'] ?? 'all';
$user_filter = $_GET['user_id'] ?? 'all';

$where = [];
$params = [];

if ($module_filter !== 'all') {
    $where[] = "a.module = ?";
    $params[] = $module_filter;
}
if ($action_filter !== 'all') {
    $where[] = "a.action = ?";
    $params[] = $action_filter;
}
if ($user_filter !== 'all') {
    $where[] = "a.user_id = ?";
    $params[] = $user_filter;
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Count total
$count_sql = "SELECT COUNT(*) FROM audit_log a $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_rows = $stmt->fetchColumn();
$total_pages = max(1, ceil($total_rows / $limit));

// Fetch logs
$query = "
    SELECT a.*, u.name as user_name, u.email
    FROM audit_log a
    JOIN users u ON a.user_id = u.id
    $where_clause
    ORDER BY a.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Filters Data
$modules = $pdo->query("SELECT DISTINCT module FROM audit_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();
// If AJAX request, just return the table rows
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    if (empty($logs)) {
        echo '';
        exit;
    }
    foreach ($logs as $log) {
        $cls = 'badge-neutral';
        if ($log['action'] === 'CREATE') $cls = 'badge-success';
        if ($log['action'] === 'DELETE' || $log['action'] === 'REJECT') $cls = 'badge-danger';
        if ($log['action'] === 'UPDATE' || $log['action'] === 'APPROVE') $cls = 'badge-primary';
        
        $ts = date('Y-m-d H:i:s', strtotime($log['created_at']));
        $userName = htmlspecialchars($log['user_name']);
        $email = htmlspecialchars($log['email']);
        $action = htmlspecialchars($log['action']);
        $mod = htmlspecialchars(ucfirst($log['module']));
        $recId = htmlspecialchars($log['record_id']);
        $desc = htmlspecialchars($log['description'] ?? '');
        
        echo "<tr>
                <td style='white-space: nowrap;'>{$ts}</td>
                <td><strong>{$userName}</strong><br><small>{$email}</small></td>
                <td><span class='badge {$cls}'>{$action}</span></td>
                <td>{$mod} <br><small style='color:var(--text-muted)'>ID: {$recId}</small></td>
                <td>{$desc}</td>
              </tr>";
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Audit Logs</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">System Audit Logs</h1>
        </header>

        <!-- FILTERS -->
        <div class="form-card" style="padding: 16px; margin-bottom: 24px; max-width: 100%;">
            <form method="get" action="<?= BASE_URL ?>/modules/admin/audit_logs.php" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 150px;">
                    <label class="form-label">Module</label>
                    <select name="module" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $module_filter === 'all' ? 'selected' : '' ?>>All Modules</option>
                        <?php foreach ($modules as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= $module_filter === $m ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label class="form-label">Action</label>
                    <select name="action_type" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $action_filter === 'all' ? 'selected' : '' ?>>All Actions</option>
                        <option value="CREATE" <?= $action_filter === 'CREATE' ? 'selected' : '' ?>>CREATE</option>
                        <option value="UPDATE" <?= $action_filter === 'UPDATE' ? 'selected' : '' ?>>UPDATE</option>
                        <option value="DELETE" <?= $action_filter === 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                        <option value="APPROVE" <?= $action_filter === 'APPROVE' ? 'selected' : '' ?>>APPROVE</option>
                        <option value="REJECT" <?= $action_filter === 'REJECT' ? 'selected' : '' ?>>REJECT</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $user_filter === 'all' ? 'selected' : '' ?>>All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $user_filter == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <a href="<?= BASE_URL ?>/modules/admin/audit_logs.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Security & Action Log</h2>
                <span style="color: var(--text-muted); font-size: 0.85rem;"><?= number_format($total_rows) ?> total entries</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module / Ref ID</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody id="auditTableBody">
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" style="text-align: center;">No logs found</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="white-space: nowrap;"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($log['user_name']) ?></strong><br><small><?= htmlspecialchars($log['email']) ?></small></td>
                                <td>
                                    <?php 
                                        $cls = 'badge-neutral';
                                        if ($log['action'] === 'CREATE') $cls = 'badge-success';
                                        if ($log['action'] === 'DELETE' || $log['action'] === 'REJECT') $cls = 'badge-danger';
                                        if ($log['action'] === 'UPDATE' || $log['action'] === 'APPROVE') $cls = 'badge-primary';
                                    ?>
                                    <span class="badge <?= $cls ?>"><?= htmlspecialchars($log['action']) ?></span>
                                </td>
                                <td><?= htmlspecialchars(ucfirst($log['module'])) ?> <br><small style="color:var(--text-muted)">ID: <?= htmlspecialchars($log['record_id']) ?></small></td>
                                <td><?= htmlspecialchars($log['description'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1 && $page < $total_pages): ?>
                <div style="padding: 16px; border-top: 1px solid var(--border); text-align: center;">
                    <button id="showMoreBtn" class="btn btn-secondary" onclick="loadMore()">Show More</button>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        let currentPage = <?= $page ?>;
        const totalPages = <?= $total_pages ?>;
        
        function loadMore() {
            if (currentPage >= totalPages) return;
            
            const btn = document.getElementById('showMoreBtn');
            btn.innerText = 'Loading...';
            btn.disabled = true;
            
            currentPage++;
            
            // Build URL with current filters
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('page', currentPage);
            urlParams.set('ajax', '1');
            
            fetch(`<?= BASE_URL ?>/modules/admin/audit_logs.php?${urlParams.toString()}`)
                .then(response => response.text())
                .then(html => {
                    if (html.trim() !== '') {
                        document.getElementById('auditTableBody').insertAdjacentHTML('beforeend', html);
                    }
                    if (currentPage >= totalPages) {
                        btn.style.display = 'none';
                    } else {
                        btn.innerText = 'Show More';
                        btn.disabled = false;
                    }
                })
                .catch(err => {
                    console.error('Error fetching logs:', err);
                    btn.innerText = 'Show More';
                    btn.disabled = false;
                });
        }
    </script>
</body>
</html>

