<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'operations']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign_storage') {
        $container_id = $_POST['container_id'] ?? '';
        $location_id = $_POST['location_id'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Validate container is cleared
            $c_stmt = $pdo->prepare("SELECT status, container_type FROM containers WHERE id = ? FOR UPDATE");
            $c_stmt->execute([$container_id]);
            $container = $c_stmt->fetch();
            
            // Validate location is available and matches type
            $l_stmt = $pdo->prepare("SELECT status, slot_type FROM storage_locations WHERE id = ? FOR UPDATE");
            $l_stmt->execute([$location_id]);
            $location = $l_stmt->fetch();
            
            if (!$container || $container['status'] !== 'cleared') {
                throw new Exception("Container is not in 'cleared' status.");
            }
            if (!$location || $location['status'] !== 'available') {
                throw new Exception("Storage slot is not available.");
            }
            if ($container['container_type'] !== $location['slot_type']) {
                throw new Exception("Type mismatch. Container is {$container['container_type']}, Slot is {$location['slot_type']}.");
            }
            
            // Insert assignment
            $stmt = $pdo->prepare("INSERT INTO container_assignments (container_id, storage_location_id, assigned_by) VALUES (?, ?, ?)");
            $stmt->execute([$container_id, $location_id, $_SESSION['user_id']]);
            $assignment_id = $pdo->lastInsertId();
            
            // Update statuses
            $pdo->prepare("UPDATE containers SET status = 'stored' WHERE id = ?")->execute([$container_id]);
            $pdo->prepare("UPDATE storage_locations SET status = 'occupied' WHERE id = ?")->execute([$location_id]);
            
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
                $cost = 100.00; // Flat fee for storage assignment
                record_cost($pdo, $c_data['company_id'], $c_data['company_name'], 'storage', $assignment_id, "Storage assignment for container {$c_data['container_code']}", $cost);
            }
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'cargo', $assignment_id, "Assigned container {$container_id} to storage {$location_id}");
            $_SESSION['flash'] = "Container successfully assigned to storage slot. Cost recorded.";
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to assign storage: " . $e->getMessage();
        }
        
        header("Location: " . BASE_URL . "/modules/cargo/storage.php");
        exit;
    }
}

// GET Data
// Left panel: Cleared containers waiting for storage
$cleared_containers = $pdo->query("SELECT id, container_code, container_type FROM containers WHERE status = 'cleared' ORDER BY created_at ASC")->fetchAll();

// Right panel: Storage grid
$storage_locations = $pdo->query("SELECT * FROM storage_locations ORDER BY zone, slot_code")->fetchAll();

// Fetch occupied slot details
$occupied_slots = [];
$stmt = $pdo->query("
    SELECT ca.storage_location_id, c.container_code, ca.assigned_at
    FROM container_assignments ca
    JOIN containers c ON ca.container_id = c.id
    WHERE ca.vacated_at IS NULL
");
foreach ($stmt->fetchAll() as $row) {
    $occupied_slots[$row['storage_location_id']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Warehouse Storage</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Warehouse Storage</h1>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-error"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div style="display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap;">
            
            <!-- Assign Form (Left) -->
            <div class="form-card" style="flex: 1; min-width: 300px; margin: 0; position: sticky; top: 40px;">
                <h2 style="margin-top: 0; margin-bottom: 24px;">Assign to Storage</h2>
                
                <form method="post" action="<?= BASE_URL ?>/modules/cargo/storage.php">
                    <input type="hidden" name="action" value="assign_storage">
                    
                    <div class="form-group">
                        <label class="form-label">Select Cleared Container</label>
                        <select name="container_id" class="form-control" required>
                            <option value="">-- Select Container --</option>
                            <?php foreach ($cleared_containers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['container_code']) ?> (<?= htmlspecialchars($c['container_type']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($cleared_containers)): ?>
                            <small style="color: var(--text-muted); display: block; margin-top: 4px;">No cleared containers waiting for storage.</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Select Available Slot</label>
                        <select name="location_id" class="form-control" required>
                            <option value="">-- Select Slot --</option>
                            <?php foreach ($storage_locations as $loc): ?>
                                <?php if ($loc['status'] === 'available'): ?>
                                    <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['slot_code']) ?> - <?= htmlspecialchars($loc['zone']) ?> (<?= htmlspecialchars($loc['slot_type']) ?>)</option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">Must match container type.</small>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;" <?= empty($cleared_containers) ? 'disabled' : '' ?>>Assign Slot</button>
                </form>
            </div>

            <!-- Storage Grid (Right) -->
            <div style="flex: 2; min-width: 400px;">
                <h2 style="margin-top: 0; margin-bottom: 24px; color: var(--primary);">Storage Overview</h2>
                
                <?php 
                // Group by zone
                $zones = [];
                foreach ($storage_locations as $loc) {
                    $zones[$loc['zone']][] = $loc;
                }
                ?>

                <?php foreach ($zones as $zone_name => $locations): ?>
                    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm);">
                        <h3 style="margin-top: 0; margin-bottom: 16px; font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 8px;"><?= htmlspecialchars($zone_name) ?></h3>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px;">
                            <?php foreach ($locations as $loc): ?>
                                <?php 
                                    $is_occupied = $loc['status'] === 'occupied'; 
                                    $bg_color = $is_occupied ? 'var(--warning-bg)' : 'var(--success-bg)';
                                    $border_color = $is_occupied ? '#fcd34d' : '#6ee7b7';
                                ?>
                                <div style="background: <?= $bg_color ?>; border: 1px solid <?= $border_color ?>; border-radius: var(--radius-md); padding: 12px; text-align: center; position: relative; overflow: hidden;">
                                    
                                    <div style="font-weight: 700; color: var(--primary); margin-bottom: 4px;"><?= htmlspecialchars($loc['slot_code']) ?></div>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px;"><?= htmlspecialchars($loc['slot_type']) ?></div>
                                    
                                    <?php if ($is_occupied && isset($occupied_slots[$loc['id']])): ?>
                                        <div style="background: rgba(255,255,255,0.7); padding: 4px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
                                            <?= htmlspecialchars($occupied_slots[$loc['id']]['container_code']) ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: var(--success-text); font-size: 0.8rem; font-weight: 500;">AVAILABLE</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>

