<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'operations']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_transport') {
        $container_id = $_POST['container_id'] ?? '';
        $transport_mode = $_POST['transport_mode'] ?? '';
        $carrier_name = $_POST['carrier_name'] ?? '';
        $vehicle_id = $_POST['vehicle_id'] ?? '';
        $destination = $_POST['destination'] ?? '';
        $scheduled_pickup = $_POST['scheduled_pickup'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Validate container is stored
            $c_stmt = $pdo->prepare("SELECT status FROM containers WHERE id = ? FOR UPDATE");
            $c_stmt->execute([$container_id]);
            $container = $c_stmt->fetch();
            
            if (!$container || $container['status'] !== 'stored') {
                throw new Exception("Container is not currently stored.");
            }
            
            // Insert transport assignment
            $stmt = $pdo->prepare("INSERT INTO transport_assignments (container_id, transport_mode, carrier_name, vehicle_id, destination, scheduled_pickup, assigned_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$container_id, $transport_mode, $carrier_name, $vehicle_id, $destination, $scheduled_pickup, $_SESSION['user_id']]);
            $transport_id = $pdo->lastInsertId();
            
            // Update container status
            $pdo->prepare("UPDATE containers SET status = 'in_transit' WHERE id = ?")->execute([$container_id]);
            
            // Free the storage slot
            // Find the active container assignment and vacate it
            $ca_stmt = $pdo->prepare("SELECT id, storage_location_id FROM container_assignments WHERE container_id = ? AND vacated_at IS NULL FOR UPDATE");
            $ca_stmt->execute([$container_id]);
            $ca = $ca_stmt->fetch();
            
            if ($ca) {
                $pdo->prepare("UPDATE container_assignments SET vacated_at = NOW() WHERE id = ?")->execute([$ca['id']]);
                $pdo->prepare("UPDATE storage_locations SET status = 'available' WHERE id = ?")->execute([$ca['storage_location_id']]);
            }
            
            // Record cost
            $c_info = $pdo->prepare("
                SELECT c.container_code, v.company_id, v.company_name
                FROM containers c
                JOIN vessels v ON c.vessel_id = v.id
                WHERE c.id = ?
            ");
            $c_info->execute([$container_id]);
            $c_data = $c_info->fetch();
            if ($c_data) {
                $cost = 250.00; // Flat fee for transport
                record_cost($pdo, $c_data['company_id'], $c_data['company_name'], 'transport', $transport_id, "Transport schedule for container {$c_data['container_code']}", $cost);
            }
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'logistics', $transport_id, "Scheduled transport for container {$container_id}");
            $_SESSION['flash'] = "Transport scheduled. Container marked as in_transit and storage slot freed.";
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to schedule transport: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/logistics/transport.php");
        exit;
    }
    
    if ($action === 'update_status') {
        $transport_id = $_POST['transport_id'] ?? '';
        $new_status = $_POST['status'] ?? ''; // in_transit or delivered
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE transport_assignments SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $transport_id]);
            
            if ($new_status === 'delivered') {
                // Also update container status
                $t_stmt = $pdo->prepare("SELECT container_id FROM transport_assignments WHERE id = ?");
                $t_stmt->execute([$transport_id]);
                $t = $t_stmt->fetch();
                if ($t) {
                    $pdo->prepare("UPDATE containers SET status = 'delivered' WHERE id = ?")->execute([$t['container_id']]);
                }
            }
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'UPDATE', 'logistics', $transport_id, "Updated transport status to {$new_status}");
            $_SESSION['flash'] = "Transport status updated to {$new_status}.";
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to update status.";
        }
        header("Location: " . BASE_URL . "/modules/logistics/transport.php");
        exit;
    }
}

// GET Data
$status_filter = $_GET['status'] ?? 'all';
$mode_filter = $_GET['mode'] ?? 'all';

$query = "
    SELECT ta.*, c.container_code, v.vessel_name 
    FROM transport_assignments ta
    JOIN containers c ON ta.container_id = c.id
    JOIN vessels v ON c.vessel_id = v.id
";
$where = [];
$params = [];

if ($status_filter !== 'all') {
    $where[] = "ta.status = ?";
    $params[] = $status_filter;
}
if ($mode_filter !== 'all') {
    $where[] = "ta.transport_mode = ?";
    $params[] = $mode_filter;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}
$query .= " ORDER BY ta.scheduled_pickup ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transports = $stmt->fetchAll();

// Eligible containers (status = 'stored')
$stored_containers = $pdo->query("SELECT id, container_code FROM containers WHERE status = 'stored' ORDER BY created_at ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Transport Assignments</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Transport & Dispatch</h1>
            <button class="btn btn-primary" onclick="document.getElementById('transportModal').classList.add('active')">
                <i class="fa-solid fa-truck-fast"></i> Schedule Transport
            </button>
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
            <form method="get" action="<?= BASE_URL ?>/modules/logistics/transport.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="scheduled" <?= $status_filter === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="in_transit" <?= $status_filter === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                        <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="delayed" <?= $status_filter === 'delayed' ? 'selected' : '' ?>>Delayed</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Mode</label>
                    <select name="mode" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $mode_filter === 'all' ? 'selected' : '' ?>>All Modes</option>
                        <option value="truck" <?= $mode_filter === 'truck' ? 'selected' : '' ?>>Truck</option>
                        <option value="train" <?= $mode_filter === 'train' ? 'selected' : '' ?>>Train</option>
                        <option value="river" <?= $mode_filter === 'river' ? 'selected' : '' ?>>River</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Active Dispatch List</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Container</th>
                        <th>Mode</th>
                        <th>Carrier</th>
                        <th>Destination</th>
                        <th>Scheduled Pickup</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transports)): ?>
                        <tr><td colspan="7" style="text-align: center;">No records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($transports as $t): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($t['container_code']) ?></strong><br><small><?= htmlspecialchars($t['vessel_name']) ?></small></td>
                                <td><?= htmlspecialchars(ucfirst($t['transport_mode'])) ?></td>
                                <td><?= htmlspecialchars($t['carrier_name']) ?><br><small><?= htmlspecialchars($t['vehicle_id']) ?></small></td>
                                <td><?= htmlspecialchars($t['destination']) ?></td>
                                <td><?= format_dt($t['scheduled_pickup']) ?></td>
                                <td><?= status_badge($t['status']) ?></td>
                                <td>
                                    <?php if ($t['status'] === 'scheduled'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="transport_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="status" value="in_transit">
                                            <button type="submit" class="btn btn-sm btn-secondary">Mark In Transit</button>
                                        </form>
                                    <?php elseif ($t['status'] === 'in_transit' || $t['status'] === 'delayed'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="transport_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="status" value="delivered">
                                            <button type="submit" class="btn btn-sm btn-primary">Mark Delivered</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- NEW TRANSPORT MODAL -->
    <div id="transportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Schedule Transport</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/logistics/transport.php">
                <input type="hidden" name="action" value="create_transport">
                
                <div class="form-group">
                    <label class="form-label">Container (Stored only) *</label>
                    <select name="container_id" class="form-control" required>
                        <option value="">-- Select Container --</option>
                        <?php foreach ($stored_containers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['container_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Mode *</label>
                        <select name="transport_mode" class="form-control" required>
                            <option value="truck">Truck</option>
                            <option value="train">Train</option>
                            <option value="river">River</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label">Carrier Name *</label>
                        <input type="text" name="carrier_name" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Vehicle ID / Plate / Train Num</label>
                    <input type="text" name="vehicle_id" class="form-control">
                </div>

                <div class="form-group">
                    <label class="form-label">Destination Address *</label>
                    <input type="text" name="destination" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Scheduled Pickup *</label>
                    <input type="datetime-local" name="scheduled_pickup" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;" <?= empty($stored_containers) ? 'disabled' : '' ?>>Dispatch Container</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>

