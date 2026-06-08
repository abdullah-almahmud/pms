<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'operations']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_delivery') {
        $transport_id = $_POST['transport_assignment_id'] ?? '';
        $expected_delivery = $_POST['expected_delivery'] ?? '';
        $actual_delivery = !empty($_POST['actual_delivery']) ? $_POST['actual_delivery'] : null;
        $delay_reason = $_POST['delay_reason'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Check if log already exists
            $stmt = $pdo->prepare("SELECT id FROM delivery_logs WHERE transport_assignment_id = ?");
            $stmt->execute([$transport_id]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update
                $upd = $pdo->prepare("UPDATE delivery_logs SET expected_delivery=?, actual_delivery=?, delay_reason=?, updated_by=?, updated_at=NOW() WHERE id=?");
                $upd->execute([$expected_delivery, $actual_delivery, $delay_reason, $_SESSION['user_id'], $existing['id']]);
                audit_log_entry($pdo, $_SESSION['user_id'], 'UPDATE', 'logistics', $existing['id'], "Updated delivery log for transport {$transport_id}");
            } else {
                // Insert
                $ins = $pdo->prepare("INSERT INTO delivery_logs (transport_assignment_id, expected_delivery, actual_delivery, delay_reason, updated_by) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$transport_id, $expected_delivery, $actual_delivery, $delay_reason, $_SESSION['user_id']]);
                audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'logistics', $pdo->lastInsertId(), "Created delivery log for transport {$transport_id}");
            }
            
            // Sync status back to transport & container
            if ($actual_delivery && empty(trim($delay_reason))) {
                $pdo->prepare("UPDATE transport_assignments SET status = 'delivered' WHERE id = ?")->execute([$transport_id]);
                // update container
                $c_stmt = $pdo->prepare("SELECT container_id FROM transport_assignments WHERE id = ?");
                $c_stmt->execute([$transport_id]);
                $c = $c_stmt->fetch();
                if ($c) {
                    $pdo->prepare("UPDATE containers SET status = 'delivered' WHERE id = ?")->execute([$c['container_id']]);
                }
            } elseif (!empty(trim($delay_reason))) {
                $pdo->prepare("UPDATE transport_assignments SET status = 'delayed' WHERE id = ?")->execute([$transport_id]);
            }
            
            $pdo->commit();
            $_SESSION['flash'] = "Delivery log saved successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to save delivery log: " . $e->getMessage();
        }
        
        header("Location: " . BASE_URL . "/modules/logistics/delivery.php");
        exit;
    }
}

// GET Data
// List all transports that are dispatched (not scheduled)
$query = "
    SELECT ta.id as transport_id, c.container_code, ta.carrier_name, ta.destination, ta.status as transport_status,
           dl.id as log_id, dl.expected_delivery, dl.actual_delivery, dl.delay_reason
    FROM transport_assignments ta
    JOIN containers c ON ta.container_id = c.id
    LEFT JOIN delivery_logs dl ON ta.id = dl.transport_assignment_id
    WHERE ta.status IN ('in_transit', 'delayed', 'delivered')
    ORDER BY ta.created_at DESC
";
$deliveries = $pdo->query($query)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Delivery Logs</title>
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
            <h1 class="page-title">Delivery Logs</h1>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Tracking</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Container</th>
                        <th>Carrier</th>
                        <th>Destination</th>
                        <th>Expected Delivery</th>
                        <th>Actual Delivery</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="7" style="text-align: center;">No dispatched transports found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $d): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($d['container_code']) ?></strong></td>
                                <td><?= htmlspecialchars($d['carrier_name']) ?></td>
                                <td><?= htmlspecialchars($d['destination']) ?></td>
                                <td><?= $d['expected_delivery'] ? format_dt($d['expected_delivery']) : '<span style="color:var(--text-muted)">Not Set</span>' ?></td>
                                <td><?= $d['actual_delivery'] ? format_dt($d['actual_delivery']) : '-' ?></td>
                                <td><?= status_badge($d['transport_status']) ?>
                                    <?php if ($d['delay_reason']): ?>
                                        <br><small style="color:var(--danger-text)">Delayed</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        // Pass data to modal
                                        $ed = $d['expected_delivery'] ? date('Y-m-d\TH:i', strtotime($d['expected_delivery'])) : '';
                                        $ad = $d['actual_delivery'] ? date('Y-m-d\TH:i', strtotime($d['actual_delivery'])) : '';
                                        $reason = htmlspecialchars($d['delay_reason'] ?? '', ENT_QUOTES);
                                    ?>
                                    <button class="btn btn-sm btn-secondary" onclick="openLogModal(<?= $d['transport_id'] ?>, '<?= htmlspecialchars($d['container_code'], ENT_QUOTES) ?>', '<?= $ed ?>', '<?= $ad ?>', '<?= $reason ?>')">
                                        <?= $d['log_id'] ? 'Edit Log' : 'Create Log' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- LOG MODAL -->
    <div id="logModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Delivery Log: <span id="logCodeDisplay"></span></h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/logistics/delivery.php">
                <input type="hidden" name="action" value="save_delivery">
                <input type="hidden" name="transport_assignment_id" id="logTransportId">
                
                <div class="form-group">
                    <label class="form-label">Expected Delivery Time *</label>
                    <input type="datetime-local" name="expected_delivery" id="logExp" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Actual Delivery Time</label>
                    <input type="datetime-local" name="actual_delivery" id="logAct" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Delay Reason (if applicable)</label>
                    <textarea name="delay_reason" id="logReason" class="form-control" rows="3"></textarea>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Filling this marks the transport as 'delayed'.</small>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Save Log</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        function openLogModal(transportId, code, exp, act, reason) {
            document.getElementById('logTransportId').value = transportId;
            document.getElementById('logCodeDisplay').innerText = code;
            document.getElementById('logExp').value = exp;
            document.getElementById('logAct').value = act;
            document.getElementById('logReason').value = reason;
            document.getElementById('logModal').classList.add('active');
        }
    </script>
</body>
</html>

