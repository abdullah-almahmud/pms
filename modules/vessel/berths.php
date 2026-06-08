<?php
// /modules/vessel/berths.php
require_once '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

require_login();
require_role(['admin', 'harbor_master']);

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Add New Berth
    if ($_POST['action'] === 'add_berth' && $_SESSION['role'] === 'admin') {
        $name = trim($_POST['berth_name'] ?? '');
        $type = $_POST['berth_type'] ?? '';
        $max_t = $_POST['max_tonnage'] ?? null;
        $depth = $_POST['depth_metres'] ?? null;
        $length = $_POST['quay_length'] ?? null;
        $notes = trim($_POST['notes'] ?? '');

        if (!empty($name)) {
            $stmt = $pdo->prepare("
                INSERT INTO berths (berth_name, berth_type, max_tonnage, depth_metres, quay_length, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $type,
                $max_t !== '' ? $max_t : null,
                $depth !== '' ? $depth : null,
                $length !== '' ? $length : null,
                $notes !== '' ? $notes : null
            ]);
            $new_id = $pdo->lastInsertId();
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE_BERTH', 'vessel', $new_id, "Created berth $name");
            $_SESSION['flash'] = "New berth added successfully.";
        }
        header("Location: berths.php");
        exit;
    }

    // Allocate Berth
    if ($_POST['action'] === 'allocate_berth') {
        $ar_id = $_POST['arrival_request_id'] ?? 0;
        $b_id = $_POST['berth_id'] ?? 0;
        $start = $_POST['docking_start'] ?? '';
        $end = $_POST['expected_departure'] ?? '';

        if ($ar_id && $b_id && $start && $end) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO berth_allocations (arrival_request_id, berth_id, allocated_by, docking_start, expected_departure) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$ar_id, $b_id, $_SESSION['user_id'], $start, $end]);
                
                $pdo->prepare("UPDATE berths SET status = 'occupied' WHERE id = ?")->execute([$b_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'ALLOCATE_BERTH', 'vessel', $ar_id, "Allocated berth $b_id to arrival request $ar_id");
                $pdo->commit();
                $_SESSION['flash'] = "Berth allocated successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Failed to allocate berth: " . $e->getMessage();
            }
        }
        header("Location: berths.php");
        exit;
    }

    // Release Berth
    if ($_POST['action'] === 'release_berth') {
        $berth_id = $_POST['berth_id'] ?? 0;
        
        if ($berth_id) {
            $pdo->beginTransaction();
            try {
                // Find active allocation for this berth
                $stmt = $pdo->prepare("SELECT id FROM berth_allocations WHERE berth_id = ? AND actual_departure IS NULL LIMIT 1");
                $stmt->execute([$berth_id]);
                $alloc = $stmt->fetch();

                if ($alloc) {
                    $pdo->prepare("UPDATE berth_allocations SET actual_departure = NOW() WHERE id = ?")->execute([$alloc['id']]);
                }

                $pdo->prepare("UPDATE berths SET status = 'available' WHERE id = ?")->execute([$berth_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'RELEASE_BERTH', 'vessel', $berth_id, "Released berth ID $berth_id");
                
                $pdo->commit();
                $_SESSION['flash'] = "Berth released successfully.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['flash_error'] = "Failed to release berth: " . $e->getMessage();
            }
        }
        header("Location: berths.php");
        exit;
    }
}

// Fetch all active berths
$stmt = $pdo->query("
    SELECT
        b.id,
        b.berth_name,
        b.berth_type,
        b.max_tonnage,
        b.depth_metres,
        b.quay_length,
        b.status,
        b.is_active,
        b.notes,
        v.vessel_name                AS current_vessel,
        ar.eta                       AS vessel_eta,
        ba.expected_departure        AS vessel_departs,
        ba.docking_start
    FROM berths b
    LEFT JOIN berth_allocations ba
        ON  ba.berth_id        = b.id
        AND ba.actual_departure IS NULL
    LEFT JOIN arrival_requests ar ON ar.id  = ba.arrival_request_id
    LEFT JOIN vessels          v  ON v.id   = ar.vessel_id
    WHERE b.is_active = 1
    ORDER BY b.berth_type, b.berth_name
");
$berths = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Data for Allocation form
$unallocated_arrivals = $pdo->query("
    SELECT ar.id, v.vessel_name, ar.eta 
    FROM arrival_requests ar
    JOIN vessels v ON v.id = ar.vessel_id
    LEFT JOIN berth_allocations ba ON ba.arrival_request_id = ar.id
    WHERE ar.status = 'approved' AND ba.id IS NULL
    ORDER BY ar.eta ASC
")->fetchAll(PDO::FETCH_ASSOC);

$available_berths = $pdo->query("
    SELECT id, berth_name 
    FROM berths 
    WHERE status = 'available' AND is_active = 1
    ORDER BY berth_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Berths Management</title>
    <link rel="stylesheet" href="../../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .grid-forms { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px; }
        @media (max-width: 768px) { .grid-forms { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Berths Management</h1>
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
                <h2>Berth Status</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Berth Name</th>
                        <th>Type</th>
                        <th>Max Tonnage (t)</th>
                        <th>Depth (m)</th>
                        <th>Status</th>
                        <th>Current Vessel</th>
                        <th>Expected Departure</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($berths as $b): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($b['berth_name']) ?></strong>
                                <?php if ($b['notes']): ?>
                                    <br><small class="text-muted"><?= htmlspecialchars($b['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($b['berth_type']) ?></td>
                            <td><?= $b['max_tonnage'] ? number_format($b['max_tonnage']) : '-' ?></td>
                            <td><?= $b['depth_metres'] ?? '-' ?></td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td><?= $b['current_vessel'] ? htmlspecialchars($b['current_vessel']) : '—' ?></td>
                            <td><?= $b['vessel_departs'] ? format_dt($b['vessel_departs']) : '—' ?></td>
                            <td>
                                <?php if ($b['status'] === 'occupied'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="release_berth">
                                        <input type="hidden" name="berth_id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Release this berth?')">Release</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($berths)): ?>
                        <tr><td colspan="8" style="text-align: center;">No berths found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="grid-forms">
            <!-- ALLOCATE BERTH FORM -->
            <div class="form-card">
                <h2>Allocate Berth</h2>
                <?php if (empty($unallocated_arrivals)): ?>
                    <div class="alert alert-info" style="margin-top: 16px;">No pending approved arrival requests to allocate.</div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="action" value="allocate_berth">
                        
                        <div class="form-group">
                            <label class="form-label">Approved Arrival Request *</label>
                            <select name="arrival_request_id" class="form-control" required>
                                <option value="">-- Select Vessel --</option>
                                <?php foreach ($unallocated_arrivals as $ar): ?>
                                    <option value="<?= $ar['id'] ?>"><?= htmlspecialchars($ar['vessel_name']) ?> (ETA: <?= date('d M', strtotime($ar['eta'])) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Select Berth *</label>
                            <select name="berth_id" class="form-control" required>
                                <option value="">-- Select Available Berth --</option>
                                <?php foreach ($available_berths as $ab): ?>
                                    <option value="<?= $ab['id'] ?>"><?= htmlspecialchars($ab['berth_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display: flex; gap: 16px;">
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Docking Start *</label>
                                <input type="datetime-local" name="docking_start" class="form-control" required>
                            </div>
                            <div class="form-group" style="flex: 1;">
                                <label class="form-label">Expected Departure *</label>
                                <input type="datetime-local" name="expected_departure" class="form-control" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Allocate Berth</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ADD BERTH FORM -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="form-card">
                <h2>Add New Berth</h2>
                <form method="post">
                    <input type="hidden" name="action" value="add_berth">
                    
                    <div class="form-group">
                        <label class="form-label">Berth Name *</label>
                        <input type="text" name="berth_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Berth Type</label>
                        <select name="berth_type" class="form-control">
                            <option value="Container">Container</option>
                            <option value="Bulk">Bulk</option>
                            <option value="General">General</option>
                            <option value="RoRo">RoRo</option>
                            <option value="Liquid">Liquid</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 16px;">
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Max Tonnage (t)</label>
                            <input type="number" step="0.01" name="max_tonnage" class="form-control">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label class="form-label">Depth (m)</label>
                            <input type="number" step="0.01" name="depth_metres" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Quay Length (m)</label>
                        <input type="number" step="0.01" name="quay_length" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>

                    <button type="submit" class="btn btn-success" style="width: 100%; margin-top: 16px;">Add Berth</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
