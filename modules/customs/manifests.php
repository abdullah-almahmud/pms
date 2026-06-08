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
    
    if ($action === 'create_manifest') {
        $arrival_request_id = $_POST['arrival_request_id'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        $container_codes = $_POST['container_code'] ?? [];
        $cargo_types = $_POST['cargo_type'] ?? [];
        $weights = $_POST['declared_weight'] ?? [];
        $values = $_POST['declared_value'] ?? [];
        $importers = $_POST['importer_name'] ?? [];
        $exporters = $_POST['exporter_name'] ?? [];
        
        $non_empty_codes = array_filter($container_codes, function($c) { return trim($c) !== ''; });
        
        if (empty($non_empty_codes) || empty($arrival_request_id)) {
            $_SESSION['flash_error'] = "Manifest must have an arrival request and at least one item.";
            header("Location: " . BASE_URL . "/modules/customs/manifests.php");
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Insert manifest
            $stmt = $pdo->prepare("INSERT INTO cargo_manifests (arrival_request_id, submitted_by, notes) VALUES (?, ?, ?)");
            $stmt->execute([$arrival_request_id, $_SESSION['user_id'], $notes]);
            $manifest_id = $pdo->lastInsertId();
            
            // Insert items
            $item_stmt = $pdo->prepare("INSERT INTO manifest_items (manifest_id, container_code, cargo_type, declared_weight, declared_value, importer_name, exporter_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($container_codes as $index => $code) {
                if (trim($code) === '') continue; // skip empty rows
                $item_stmt->execute([
                    $manifest_id,
                    $code,
                    $cargo_types[$index] ?? '',
                    $weights[$index] ?: 0,
                    $values[$index] ?: 0,
                    $importers[$index] ?? '',
                    $exporters[$index] ?? ''
                ]);
            }
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'customs', $manifest_id, "Submitted cargo manifest for arrival {$arrival_request_id}");
            $_SESSION['flash'] = "Cargo manifest submitted successfully.";
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to submit manifest: " . $e->getMessage();
        }
        
        header("Location: " . BASE_URL . "/modules/customs/manifests.php");
        exit;
    }
}

// GET Data - List all manifests
$manifests = $pdo->query("
    SELECT cm.*, v.vessel_name, ar.eta, u.name as submitter,
           (SELECT COUNT(*) FROM manifest_items mi WHERE mi.manifest_id = cm.id) as item_count
    FROM cargo_manifests cm
    JOIN arrival_requests ar ON cm.arrival_request_id = ar.id
    JOIN vessels v ON ar.vessel_id = v.id
    JOIN users u ON cm.submitted_by = u.id
    ORDER BY cm.submitted_at DESC
")->fetchAll();

// Get eligible arrivals (approved, no manifest yet)
$eligible_arrivals = $pdo->query("
    SELECT ar.id, v.vessel_name, ar.eta 
    FROM arrival_requests ar
    JOIN vessels v ON ar.vessel_id = v.id
    WHERE ar.status = 'approved'
    AND ar.id NOT IN (SELECT arrival_request_id FROM cargo_manifests)
")->fetchAll();

// Handle viewing a specific manifest
$view_manifest_id = $_GET['view'] ?? null;
$view_items = [];
if ($view_manifest_id) {
    $stmt = $pdo->prepare("SELECT * FROM manifest_items WHERE manifest_id = ?");
    $stmt->execute([$view_manifest_id]);
    $view_items = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Cargo Manifests</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
        .item-row { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
        .item-row input { flex: 1; }
        .item-row .remove-row { color: var(--danger-text); cursor: pointer; padding: 10px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Cargo Manifests</h1>
            <button class="btn btn-primary" onclick="document.getElementById('manifestModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Submit New Manifest
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

        <?php if ($view_manifest_id && !empty($view_items)): ?>
            <div class="form-card" style="max-width: 100%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="margin: 0;">Manifest Details (ID: <?= htmlspecialchars($view_manifest_id) ?>)</h3>
                    <a href="<?= BASE_URL ?>/modules/customs/manifests.php" class="btn btn-sm btn-secondary">Close Details</a>
                </div>
                <table style="margin: 0; background: #f8fafc; border: 1px solid var(--border);">
                    <thead>
                        <tr>
                            <th>Container Code</th>
                            <th>Cargo Type</th>
                            <th>Weight (kg)</th>
                            <th>Value ($)</th>
                            <th>Importer</th>
                            <th>Exporter</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($view_items as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['container_code']) ?></strong></td>
                                <td><?= htmlspecialchars($item['cargo_type']) ?></td>
                                <td><?= number_format($item['declared_weight'], 2) ?></td>
                                <td><?= number_format($item['declared_value'], 2) ?></td>
                                <td><?= htmlspecialchars($item['importer_name']) ?></td>
                                <td><?= htmlspecialchars($item['exporter_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Submitted Manifests</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Vessel</th>
                        <th>Arrival ETA</th>
                        <th>Submitted By</th>
                        <th>Submitted At</th>
                        <th>Items</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($manifests)): ?>
                        <tr><td colspan="6" style="text-align: center;">No records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($manifests as $m): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($m['vessel_name']) ?></strong></td>
                                <td><?= format_dt($m['eta']) ?></td>
                                <td><?= htmlspecialchars($m['submitter']) ?></td>
                                <td><?= format_dt($m['submitted_at']) ?></td>
                                <td><span class="badge badge-neutral"><?= $m['item_count'] ?></span></td>
                                <td>
                                    <a href="?view=<?= $m['id'] ?>" class="btn btn-sm btn-secondary">View Items</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- NEW MANIFEST MODAL -->
    <div id="manifestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Submit Cargo Manifest</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/customs/manifests.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_manifest">
                
                <div class="form-group">
                    <label class="form-label">Arrival Request (Vessel)</label>
                    <select name="arrival_request_id" class="form-control" required>
                        <option value="">-- Select Approved Arrival --</option>
                        <?php foreach ($eligible_arrivals as $ar): ?>
                            <option value="<?= $ar['id'] ?>"><?= htmlspecialchars($ar['vessel_name']) ?> (ETA: <?= format_dt($ar['eta']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 style="margin: 0; font-size: 1.1rem;">Line Items</h3>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="addRow()">+ Add Row</button>
                </div>

                <div id="itemsContainer">
                    <div class="item-row">
                        <input type="text" name="container_code[]" class="form-control" placeholder="Container Code *" required>
                        <input type="text" name="cargo_type[]" class="form-control" placeholder="Cargo Type *" required>
                        <input type="number" step="0.01" name="declared_weight[]" class="form-control" placeholder="Weight (kg)">
                        <input type="number" step="0.01" name="declared_value[]" class="form-control" placeholder="Value ($)">
                        <input type="text" name="importer_name[]" class="form-control" placeholder="Importer">
                        <i class="fa-solid fa-trash remove-row" onclick="this.parentElement.remove()"></i>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;" <?= empty($eligible_arrivals) ? 'disabled' : '' ?>>Submit Manifest</button>
                <?php if(empty($eligible_arrivals)): ?>
                    <p style="color: var(--danger-text); text-align: center; margin-top: 10px; font-size: 0.85rem;">No pending arrivals available for manifest submission.</p>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        function addRow() {
            const container = document.getElementById('itemsContainer');
            const row = document.createElement('div');
            row.className = 'item-row';
            row.innerHTML = `
                <input type="text" name="container_code[]" class="form-control" placeholder="Container Code *" required>
                <input type="text" name="cargo_type[]" class="form-control" placeholder="Cargo Type *" required>
                <input type="number" step="0.01" name="declared_weight[]" class="form-control" placeholder="Weight (kg)">
                <input type="number" step="0.01" name="declared_value[]" class="form-control" placeholder="Value ($)">
                <input type="text" name="importer_name[]" class="form-control" placeholder="Importer">
                <i class="fa-solid fa-trash remove-row" onclick="this.parentElement.remove()"></i>
            `;
            container.appendChild(row);
        }
    </script>
</body>
</html>
