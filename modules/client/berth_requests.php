<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['company']);

$user_id = $_SESSION['user_id'];

// Resolve client company ID
$stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$company_id = $stmt->fetchColumn();

if (!$company_id) {
    die("Corporate link not found. Contact administrator.");
}

$flash = $_SESSION['flash'] ?? '';
$flash_error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $vessel_name = trim($_POST['vessel_name'] ?? '');
    $vessel_type = trim($_POST['vessel_type'] ?? '');
    $tonnage = floatval($_POST['tonnage'] ?? 0);
    $eta = $_POST['eta'] ?? '';
    $cargo_type = trim($_POST['cargo_type'] ?? '');
    $origin_port = trim($_POST['origin_port'] ?? '');

    if ($vessel_name && $eta) {
        try {
            $pdo->beginTransaction();

            // 1. Resolve or Create Vessel mapped to this company
            $stmt = $pdo->prepare("SELECT id FROM vessels WHERE vessel_name = ? AND company_id = ?");
            $stmt->execute([$vessel_name, $company_id]);
            $vessel_id = $stmt->fetchColumn();

            if (!$vessel_id) {
                // Fetch company name
                $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
                $stmt->execute([$company_id]);
                $company_name = $stmt->fetchColumn();

                $stmt = $pdo->prepare("INSERT INTO vessels (vessel_name, vessel_type, tonnage, company_name, company_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$vessel_name, $vessel_type, $tonnage, $company_name, $company_id]);
                $vessel_id = $pdo->lastInsertId();
            }

            // 2. Submit new arrival request
            $stmt = $pdo->prepare("INSERT INTO arrival_requests (vessel_id, eta, cargo_type, origin_port, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$vessel_id, $eta, $cargo_type, $origin_port]);
            $request_id = $pdo->lastInsertId();

            // 3. Log to audit trail
            audit_log_entry($pdo, $user_id, 'CREATE', 'vessel', $request_id, "Requested berth allocation for vessel {$vessel_name}");

            $pdo->commit();
            $_SESSION['flash'] = "Berth request for {$vessel_name} successfully submitted.";
            header("Location: " . BASE_URL . "/modules/client/berth_requests.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_error'] = "Failed to submit request: " . $e->getMessage();
            header("Location: " . BASE_URL . "/modules/client/berth_requests.php");
            exit;
        }
    } else {
        $_SESSION['flash_error'] = "Vessel Name and ETA are required.";
        header("Location: " . BASE_URL . "/modules/client/berth_requests.php");
        exit;
    }
}

// Fetch B2B history
$stmt = $pdo->prepare("
    SELECT ar.*, v.vessel_name, v.vessel_type, v.tonnage 
    FROM arrival_requests ar
    JOIN vessels v ON ar.vessel_id = v.id
    WHERE v.company_id = ?
    ORDER BY ar.created_at DESC
");
$stmt->execute([$company_id]);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Berth Allocation Requests</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 200; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .modal.active { display: flex; }
        .modal-content { background: var(--panel-bg); border: 1px solid var(--panel-border); backdrop-filter: blur(20px); border-radius: var(--radius-lg); padding: 36px; width: 100%; max-width: 550px; box-shadow: var(--shadow-glass); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--panel-border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div>
                <a href="/dashboard.php" class="btn btn-secondary btn-sm" style="margin-bottom:12px;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                <h1 class="page-title">Berth Requests</h1>
            </div>
            <button class="btn btn-primary" onclick="document.getElementById('requestModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Request Arrival Slot
            </button>
        </header>

        <?php if ($flash): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Vessel Booking Slots</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Vessel Name</th>
                        <th>Type</th>
                        <th>Tonnage (MT)</th>
                        <th>ETA</th>
                        <th>Cargo Type</th>
                        <th>Origin Port</th>
                        <th>Status</th>
                        <th>Reviewer Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="8" style="text-align:center;">No requested arrivals yet. Click "Request Arrival Slot" to start.</td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['vessel_name']) ?></strong></td>
                                <td><?= htmlspecialchars($r['vessel_type'] ?? '-') ?></td>
                                <td><?= number_format($r['tonnage'], 2) ?></td>
                                <td><?= format_dt($r['eta']) ?></td>
                                <td><?= htmlspecialchars($r['cargo_type'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['origin_port'] ?? '-') ?></td>
                                <td><?= status_badge($r['status']) ?></td>
                                <td style="max-width:200px; color: var(--text-muted); font-size:0.8rem;"><?= htmlspecialchars($r['notes'] ?? 'Pending terminal validation') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- FORM MODAL -->
    <div id="requestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Request Berth Allocation</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <form method="post" action="<?= BASE_URL ?>/modules/client/berth_requests.php">
                <?php csrf_field(); ?>
                
                <div class="form-group">
                    <label class="form-label">Vessel Name *</label>
                    <input type="text" name="vessel_name" class="form-control" required placeholder="e.g. MV Red Sea">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Vessel Type</label>
                        <input type="text" name="vessel_type" class="form-control" placeholder="e.g. Container Ship">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tonnage (MT)</label>
                        <input type="number" step="0.01" name="tonnage" class="form-control" placeholder="45000.00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Estimated Time of Arrival (ETA) *</label>
                    <input type="datetime-local" name="eta" class="form-control" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Cargo Type</label>
                        <input type="text" name="cargo_type" class="form-control" placeholder="e.g. Standard TEU">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Origin Port</label>
                        <input type="text" name="origin_port" class="form-control" placeholder="e.g. Port of Singapore">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Submit Allocation Request</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>

