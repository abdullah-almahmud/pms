<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'finance']);

// Handle automatic overdue update on page load
$pdo->exec("UPDATE invoices SET status = 'overdue' WHERE status = 'unpaid' AND due_date < CURDATE()");

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'auto_generate') {
        try {
            $pdo->beginTransaction();
            $unbilled = $pdo->query("SELECT company_id, client_name, SUM(amount) as total FROM cost_records WHERE invoice_id IS NULL GROUP BY company_id, client_name")->fetchAll();
            $count = 0;
            foreach ($unbilled as $u) {
                if ($u['total'] > 0) {
                    $stmt = $pdo->prepare("INSERT INTO invoices (invoice_type, client_name, company_id, amount, due_date, generated_by) VALUES ('consolidated', ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?)");
                    $stmt->execute([$u['client_name'], $u['company_id'], $u['total'], $_SESSION['user_id']]);
                    $inv_id = $pdo->lastInsertId();
                    
                    $pdo->prepare("UPDATE cost_records SET invoice_id = ? WHERE (company_id = ? OR (company_id IS NULL AND ? IS NULL)) AND client_name = ? AND invoice_id IS NULL")->execute([$inv_id, $u['company_id'], $u['company_id'], $u['client_name']]);
                    
                    audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'finance', $inv_id, "Auto-generated consolidated invoice for {$u['client_name']}");
                    $count++;
                }
            }
            $pdo->commit();
            $_SESSION['flash'] = "System automatically built {$count} invoices from accumulated costs.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to auto-generate invoices: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/finance/invoices.php");
        exit;
    }
}

// GET Data
// Live Cost Visibility (Unbilled Costs)
$live_costs = $pdo->query("SELECT client_name, SUM(amount) as total FROM cost_records WHERE invoice_id IS NULL GROUP BY client_name")->fetchAll();

// GET Data
$type_filter = $_GET['type'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

$query = "SELECT i.*, u.name as creator FROM invoices i JOIN users u ON i.generated_by = u.id";
$where = [];
$params = [];

if ($type_filter !== 'all') {
    $where[] = "i.invoice_type = ?";
    $params[] = $type_filter;
}
if ($status_filter !== 'all') {
    $where[] = "i.status = ?";
    $params[] = $status_filter;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}
$query .= " ORDER BY i.generated_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$invoices = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Invoices</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Invoices & Cost Tracking</h1>
            <form method="post" action="<?= BASE_URL ?>/modules/finance/invoices.php" style="margin: 0;">
                <input type="hidden" name="action" value="auto_generate">
                <button type="submit" class="btn btn-primary" <?= empty($live_costs) ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-robot"></i> System Auto-Build Invoices
                </button>
            </form>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <!-- LIVE COSTS -->
        <div style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="stat-card" style="flex: 1; min-width: 300px; border-left: 4px solid var(--primary-color); padding: 20px;">
                <h3>Real-Time Accumulated Costs (Unbilled)</h3>
                <?php if (empty($live_costs)): ?>
                    <p style="color: var(--text-muted); margin-top: 12px;">No unbilled costs at this time.</p>
                <?php else: ?>
                    <ul style="list-style: none; padding: 0; margin-top: 12px;">
                        <?php foreach ($live_costs as $lc): ?>
                            <li style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border);">
                                <span><?= htmlspecialchars($lc['client_name']) ?></span>
                                <strong>$<?= number_format($lc['total'], 2) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- FILTERS -->
        <div class="form-card" style="padding: 16px; margin-bottom: 24px;">
            <form method="get" action="<?= BASE_URL ?>/modules/finance/invoices.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Invoice Type</label>
                    <select name="type" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="consolidated" <?= $type_filter === 'consolidated' ? 'selected' : '' ?>>Consolidated</option>
                        <option value="berth" <?= $type_filter === 'berth' ? 'selected' : '' ?>>Berth</option>
                        <option value="storage" <?= $type_filter === 'storage' ? 'selected' : '' ?>>Storage</option>
                        <option value="transport" <?= $type_filter === 'transport' ? 'selected' : '' ?>>Transport</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="unpaid" <?= $status_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Invoice List</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client</th>
                        <th>Type / Ref</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Generated By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="7" style="text-align: center;">No invoices found</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td><strong>#INV-<?= str_pad($inv['id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                <td><?= htmlspecialchars($inv['client_name']) ?></td>
                                <td>
                                    <?= ucfirst($inv['invoice_type']) ?><br>
                                    <small style="color:var(--text-muted)">Ref ID: <?= $inv['ref_id'] ?></small>
                                </td>
                                <td>$<?= number_format($inv['amount'], 2) ?></td>
                                <td><?= date('d M Y', strtotime($inv['due_date'])) ?></td>
                                <td><?= status_badge($inv['status']) ?></td>
                                <td><?= htmlspecialchars($inv['creator']) ?></td>
                                <td>
                                    <!-- Payment Flow / View Items -->
                                    <a href="<?= BASE_URL ?>/modules/finance/view_invoice.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-secondary">View & Pay</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>

