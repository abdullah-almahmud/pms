<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'harbor_master', 'operations', 'finance']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'edit_schedule') {
        $allocation_id = $_POST['allocation_id'] ?? '';
        $new_start = $_POST['docking_start'] ?? '';
        $new_end = $_POST['expected_departure'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Get current berth_id
            $stmt = $pdo->prepare("SELECT berth_id FROM berth_allocations WHERE id = ?");
            $stmt->execute([$allocation_id]);
            $alloc = $stmt->fetch();
            if ($alloc) {
                // Propagate and validate
                propagate_berth_time($pdo, $allocation_id, $alloc['berth_id'], $new_start, $new_end, null);
                
                // Update allocation
                $pdo->prepare("UPDATE berth_allocations SET docking_start = ?, expected_departure = ? WHERE id = ?")->execute([$new_start, $new_end, $allocation_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'UPDATE', 'vessel', $allocation_id, "Updated schedule for allocation {$allocation_id}");
                $_SESSION['flash'] = "Schedule updated successfully. Downstream records have been adjusted.";
            } else {
                $_SESSION['flash_error'] = "Allocation not found.";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to update schedule: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/vessel/schedule.php");
        exit;
    }
}

// GET Data
$berth_filter = $_GET['berth_id'] ?? 'all';

$query = "
    SELECT ba.*, v.vessel_name, v.company_name, b.berth_name 
    FROM berth_allocations ba
    JOIN arrival_requests ar ON ba.arrival_request_id = ar.id
    JOIN vessels v ON ar.vessel_id = v.id
    JOIN berths b ON ba.berth_id = b.id
";
$params = [];

if ($berth_filter !== 'all') {
    $query .= " WHERE ba.berth_id = ?";
    $params[] = $berth_filter;
}
$query .= " ORDER BY ba.docking_start DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$allocations = $stmt->fetchAll();

$berths = $pdo->query("SELECT id, berth_name FROM berths ORDER BY berth_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Docking Schedule</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Docking Schedule</h1>
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
            <form method="get" action="<?= BASE_URL ?>/modules/vessel/schedule.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Filter by Berth</label>
                    <select name="berth_id" class="form-control" onchange="this.form.submit()">
                        <option value="all">All Berths</option>
                        <?php foreach ($berths as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $berth_filter == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['berth_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Allocation History</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Vessel</th>
                        <th>Company</th>
                        <th>Berth</th>
                        <th>Docking Start</th>
                        <th>Exp. Departure</th>
                        <th>Actual Arrival</th>
                        <th>Actual Departure</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allocations)): ?>
                        <tr><td colspan="8" style="text-align: center;">No records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($allocations as $al): ?>
                            <?php 
                                $status = 'expected';
                                if ($al['actual_departure']) $status = 'departed';
                                elseif ($al['actual_arrival']) $status = 'docked';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($al['vessel_name']) ?></strong></td>
                                <td><?= htmlspecialchars($al['company_name']) ?></td>
                                <td><?= htmlspecialchars($al['berth_name']) ?></td>
                                <td><?= format_dt($al['docking_start']) ?></td>
                                <td><?= format_dt($al['expected_departure']) ?></td>
                                <td><?= format_dt($al['actual_arrival']) ?></td>
                                <td><?= format_dt($al['actual_departure']) ?></td>
                                <td>
                                    <?php if ($status === 'departed'): ?>
                                        <span class="badge badge-available">Departed</span>
                                    <?php elseif ($status === 'docked'): ?>
                                        <span class="badge badge-occupied">Docked</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">Expected</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($status === 'expected'): ?>
                                        <button class="btn btn-sm btn-secondary" onclick="openEditModal(<?= $al['id'] ?>, '<?= htmlspecialchars($al['vessel_name'], ENT_QUOTES) ?>', '<?= date('Y-m-d\TH:i', strtotime($al['docking_start'])) ?>', '<?= date('Y-m-d\TH:i', strtotime($al['expected_departure'])) ?>')">Edit Time</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- EDIT SCHEDULE MODAL -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Schedule: <span id="editVesselDisplay"></span></h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/vessel/schedule.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="edit_schedule">
                <input type="hidden" name="allocation_id" id="editAllocationId">
                
                <div class="form-group">
                    <label class="form-label">Docking Start *</label>
                    <input type="datetime-local" name="docking_start" id="editDockingStart" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Expected Departure *</label>
                    <input type="datetime-local" name="expected_departure" id="editExpectedDeparture" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Save Changes</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        function openEditModal(id, vesselName, start, end) {
            document.getElementById('editAllocationId').value = id;
            document.getElementById('editVesselDisplay').innerText = vesselName;
            document.getElementById('editDockingStart').value = start;
            document.getElementById('editExpectedDeparture').value = end;
            document.getElementById('editModal').classList.add('active');
        }
    </script>
</body>
</html>
