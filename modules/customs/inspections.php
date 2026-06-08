<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'operations']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'inspect') {
        $container_id = $_POST['container_id'] ?? '';
        $result = $_POST['result'] ?? '';
        $hold_reason = $_POST['hold_reason'] ?? '';
        
        // Basic validation
        if (!in_array($result, ['cleared', 'held', 'rejected'])) {
            $_SESSION['flash_error'] = "Invalid inspection result.";
            header("Location: " . BASE_URL . "/modules/customs/inspections.php");
            exit;
        }
        
        if ($result === 'held' && empty(trim($hold_reason))) {
            $_SESSION['flash_error'] = "Hold reason is required when holding a container.";
            header("Location: " . BASE_URL . "/modules/customs/inspections.php");
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Validate container is eligible
            $stmt = $pdo->prepare("SELECT status, vessel_id, size_teu FROM containers WHERE id = ? FOR UPDATE");
            $stmt->execute([$container_id]);
            $container = $stmt->fetch();
            
            if ($container && in_array($container['status'], ['arrived', 'inspecting'])) {
                // Insert inspection record
                $ins_stmt = $pdo->prepare("INSERT INTO customs_inspections (container_id, inspected_by, result, hold_reason) VALUES (?, ?, ?, ?)");
                $ins_stmt->execute([$container_id, $_SESSION['user_id'], $result, $hold_reason]);
                $inspection_id = $pdo->lastInsertId();
                
                // Update container status
                $pdo->prepare("UPDATE containers SET status = ? WHERE id = ?")->execute([$result, $container_id]);
                
                // Generate Invoice if cleared
                if ($result === 'cleared') {
                    $v_stmt = $pdo->prepare("SELECT v.company_id, v.company_name, c.name as client_name FROM vessels v LEFT JOIN companies c ON v.company_id = c.id WHERE v.id = ?");
                    $v_stmt->execute([$container['vessel_id']]);
                    $vessel_info = $v_stmt->fetch();
                    
                    if ($vessel_info) {
                        $client_name = $vessel_info['client_name'] ?: ($vessel_info['company_name'] ?: 'Unknown Client');
                        $company_id = $vessel_info['company_id'] ?: null;
                        
                        $teu = floatval($container['size_teu']);
                        $amount = $teu > 0 ? $teu * 150.00 : 150.00; // $150 per TEU handling fee
                        $due_date = date('Y-m-d', strtotime('+30 days'));
                        
                        $inv_stmt = $pdo->prepare("INSERT INTO invoices (invoice_type, ref_id, client_name, company_id, amount, due_date, status, generated_by) VALUES ('storage', ?, ?, ?, ?, ?, 'unpaid', ?)");
                        $inv_stmt->execute([$container_id, $client_name, $company_id, $amount, $due_date, $_SESSION['user_id']]);
                        $invoice_id = $pdo->lastInsertId();
                        audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'finance', $invoice_id, "Auto-generated handling invoice for container {$container_id}");
                    }
                }
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'customs', $inspection_id, "Container {$container_id} inspected: {$result}");
                $_SESSION['flash'] = "Inspection recorded successfully: " . strtoupper($result);
            } else {
                $_SESSION['flash_error'] = "Container is not eligible for inspection (current status: {$container['status']}).";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to record inspection: " . $e->getMessage();
        }
        
        header("Location: " . BASE_URL . "/modules/customs/inspections.php");
        exit;
    }
}

// GET Data
$result_filter = $_GET['result'] ?? 'all';
$vessel_filter = $_GET['vessel_id'] ?? 'all';

$query = "
    SELECT c.*, v.vessel_name, ci.result as inspection_result, ci.hold_reason 
    FROM containers c
    JOIN vessels v ON c.vessel_id = v.id
    LEFT JOIN customs_inspections ci ON c.id = ci.container_id
";

$where = [];
$params = [];

if ($result_filter === 'pending') {
    $where[] = "c.status IN ('arrived', 'inspecting') AND ci.id IS NULL";
} elseif ($result_filter !== 'all') {
    $where[] = "ci.result = ?";
    $params[] = $result_filter;
}

if ($vessel_filter !== 'all') {
    $where[] = "c.vessel_id = ?";
    $params[] = $vessel_filter;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(' AND ', $where);
}

$query .= " ORDER BY c.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$containers = $stmt->fetchAll();

$vessels = $pdo->query("SELECT id, vessel_name FROM vessels ORDER BY vessel_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Customs Inspections</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Customs Inspections</h1>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <!-- FILTERS -->
        <div class="form-card" style="padding: 16px; margin-bottom: 24px;">
            <form method="get" action="<?= BASE_URL ?>/modules/customs/inspections.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Inspection Status</label>
                    <select name="result" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $result_filter === 'all' ? 'selected' : '' ?>>All Containers</option>
                        <option value="pending" <?= $result_filter === 'pending' ? 'selected' : '' ?>>Pending Inspection</option>
                        <option value="cleared" <?= $result_filter === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                        <option value="held" <?= $result_filter === 'held' ? 'selected' : '' ?>>Held</option>
                        <option value="rejected" <?= $result_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Vessel</label>
                    <select name="vessel_id" class="form-control" onchange="this.form.submit()">
                        <option value="all">All Vessels</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $vessel_filter == $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['vessel_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Containers</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Container Code</th>
                        <th>Vessel</th>
                        <th>Type</th>
                        <th>Weight</th>
                        <th>Current Status</th>
                        <th>Inspection Result</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($containers)): ?>
                        <tr><td colspan="7" style="text-align: center;">No records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($containers as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['container_code']) ?></strong></td>
                                <td><?= htmlspecialchars($c['vessel_name']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($c['container_type'])) ?></td>
                                <td><?= number_format($c['weight_kg']) ?> kg</td>
                                <td><?= status_badge($c['status']) ?></td>
                                <td>
                                    <?php if ($c['inspection_result']): ?>
                                        <?= status_badge($c['inspection_result']) ?>
                                        <?php if ($c['inspection_result'] === 'held'): ?>
                                            <br><small style="color: var(--danger-text);"><?= htmlspecialchars($c['hold_reason']) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">Not Inspected</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (in_array($c['status'], ['arrived', 'inspecting']) && !$c['inspection_result']): ?>
                                        <button class="btn btn-sm btn-primary" onclick="openInspectModal(<?= $c['id'] ?>, '<?= htmlspecialchars($c['container_code'], ENT_QUOTES) ?>')">Inspect</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- INSPECT MODAL -->
    <div id="inspectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Inspect Container: <span id="inspectCodeDisplay"></span></h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/customs/inspections.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="inspect">
                <input type="hidden" name="container_id" id="inspectContainerId">
                
                <div class="form-group">
                    <label class="form-label">Inspection Result *</label>
                    <select name="result" class="form-control" id="resultSelect" required>
                        <option value="">-- Select Result --</option>
                        <option value="cleared">Cleared (Pass)</option>
                        <option value="held">Held (Needs Investigation)</option>
                        <option value="rejected">Rejected (Fail)</option>
                    </select>
                </div>

                <div class="form-group" id="holdReasonGroup" style="display: none;">
                    <label class="form-label">Reason for Hold/Reject</label>
                    <textarea name="hold_reason" id="holdReasonInput" class="form-control" rows="3"></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Record Result</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        function openInspectModal(id, code) {
            document.getElementById('inspectContainerId').value = id;
            document.getElementById('inspectCodeDisplay').innerText = code;
            document.getElementById('inspectModal').classList.add('active');
        }

        document.getElementById('resultSelect').addEventListener('change', function() {
            const reasonGroup = document.getElementById('holdReasonGroup');
            const reasonInput = document.getElementById('holdReasonInput');
            if (this.value === 'held' || this.value === 'rejected') {
                reasonGroup.style.display = 'block';
                if (this.value === 'held') reasonInput.required = true;
                else reasonInput.required = false;
            } else {
                reasonGroup.style.display = 'none';
                reasonInput.required = false;
            }
        });
    </script>
</body>
</html>
