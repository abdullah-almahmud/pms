<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'harbor_master']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_arrival') {
        $vessel_id = $_POST['vessel_id'] ?? '';
        $vessel_name = trim($_POST['vessel_name'] ?? '');
        $vessel_type = $_POST['vessel_type'] ?? '';
        $tonnage = $_POST['tonnage'] ?? '';
        $company_name = $_POST['company_name'] ?? '';
        $eta = $_POST['eta'] ?? '';
        $cargo_type = $_POST['cargo_type'] ?? '';
        $origin_port = $_POST['origin_port'] ?? '';
        
        if (empty($vessel_id) && empty($vessel_name)) {
            $_SESSION['flash_error'] = "Vessel selection or name is required.";
            header("Location: " . BASE_URL . "/modules/vessel/arrivals.php");
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // If new vessel, insert it first
            if (empty($vessel_id) && !empty($vessel_name)) {
                // Try to resolve company_id from existing companies
                $comp_stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
                $comp_stmt->execute([$company_name]);
                $resolved_company_id = $comp_stmt->fetchColumn() ?: null;

                $stmt = $pdo->prepare("INSERT INTO vessels (vessel_name, vessel_type, tonnage, company_name, company_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$vessel_name, $vessel_type, $tonnage, $company_name, $resolved_company_id]);
                $vessel_id = $pdo->lastInsertId();
            }
            
            if ($vessel_id) {
                $stmt = $pdo->prepare("INSERT INTO arrival_requests (vessel_id, eta, cargo_type, origin_port) VALUES (?, ?, ?, ?)");
                $stmt->execute([$vessel_id, $eta, $cargo_type, $origin_port]);
                $arrival_id = $pdo->lastInsertId();
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'vessel', $arrival_id, "Created arrival request for vessel ID {$vessel_id}");
                $_SESSION['flash'] = "Arrival request created successfully.";
            } else {
                $_SESSION['flash_error'] = "Failed to create vessel.";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to create arrival request: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/vessel/arrivals.php");
        exit;
    }
    
    if ($action === 'approve' || $action === 'reject') {
        $request_id = $_POST['request_id'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        // Validate request is pending
        $stmt = $pdo->prepare("SELECT status FROM arrival_requests WHERE id = ?");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        
        if ($req && $req['status'] === 'pending') {
            $new_status = ($action === 'approve') ? 'approved' : 'rejected';
            
            if ($action === 'reject' && empty(trim($notes))) {
                $_SESSION['flash_error'] = "Rejection notes are required.";
            } else {
                $stmt = $pdo->prepare("UPDATE arrival_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), notes = ? WHERE id = ?");
                $stmt->execute([$new_status, $_SESSION['user_id'], $notes, $request_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], strtoupper($action), 'vessel', $request_id, ucfirst($action) . " arrival request");
                $_SESSION['flash'] = "Request {$new_status} successfully.";
            }
        }
        header("Location: " . BASE_URL . "/modules/vessel/arrivals.php");
        exit;
    }
}

// GET Data
$status_filter = $_GET['status'] ?? 'all';
$query = "SELECT ar.*, v.vessel_name, v.company_name, u.name as reviewer_name 
          FROM arrival_requests ar 
          JOIN vessels v ON ar.vessel_id = v.id 
          LEFT JOIN users u ON ar.reviewed_by = u.id";
$params = [];

if ($status_filter !== 'all') {
    $query .= " WHERE ar.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY ar.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$arrivals = $stmt->fetchAll();

// Get existing vessels for dropdown
$vessels = $pdo->query("SELECT * FROM vessels ORDER BY vessel_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Arrivals</title>
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
    <!-- Include Sidebar -->
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Arrival Requests</h1>
            <button class="btn btn-primary" onclick="document.getElementById('arrivalModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> New Arrival
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
            <form method="get" action="<?= BASE_URL ?>/modules/vessel/arrivals.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Filter by Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>All Requests</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Vessel</th>
                        <th>Company</th>
                        <th>ETA</th>
                        <th>Cargo</th>
                        <th>Origin</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($arrivals)): ?>
                        <tr><td colspan="7" style="text-align: center;">No records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($arrivals as $ar): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($ar['vessel_name']) ?></strong></td>
                                <td><?= htmlspecialchars($ar['company_name']) ?></td>
                                <td><?= format_dt($ar['eta']) ?></td>
                                <td><?= htmlspecialchars($ar['cargo_type']) ?></td>
                                <td><?= htmlspecialchars($ar['origin_port']) ?></td>
                                <td><?= status_badge($ar['status']) ?></td>
                                <td>
                                    <?php if ($ar['status'] === 'pending'): ?>
                                        <form method="post" style="display:inline;" data-confirm="Are you sure you want to approve this request?">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="request_id" value="<?= $ar['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="openRejectModal(<?= $ar['id'] ?>)">Reject</button>
                                    <?php else: ?>
                                        <span style="font-size: 0.8em; color: var(--text-muted);">
                                            Reviewed by <?= htmlspecialchars($ar['reviewer_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- NEW ARRIVAL MODAL -->
    <div id="arrivalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>New Arrival Request</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/vessel/arrivals.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_arrival">
                
                <div class="form-group">
                    <label class="form-label">Select Existing Vessel (or leave blank to create new)</label>
                    <select name="vessel_id" class="form-control" id="vesselSelect">
                        <option value="">-- Create New Vessel --</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vessel_name']) ?> (<?= htmlspecialchars($v['company_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="newVesselFields">
                    <div class="form-group">
                        <label class="form-label">Vessel Name</label>
                        <input type="text" name="vessel_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vessel Type</label>
                        <input type="text" name="vessel_type" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tonnage</label>
                        <input type="number" step="0.01" name="tonnage" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control">
                    </div>
                </div>

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border);">

                <div class="form-group">
                    <label class="form-label">ETA (Expected Time of Arrival) *</label>
                    <input type="datetime-local" name="eta" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Cargo Type *</label>
                    <input type="text" name="cargo_type" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Origin Port *</label>
                    <input type="text" name="origin_port" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Submit Request</button>
            </form>
        </div>
    </div>

    <!-- REJECT MODAL -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reject Arrival Request</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <form method="post" action="<?= BASE_URL ?>/modules/vessel/arrivals.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="rejectRequestId" value="">
                
                <div class="form-group">
                    <label class="form-label">Rejection Reason (Notes) *</label>
                    <textarea name="notes" class="form-control" rows="4" required></textarea>
                </div>

                <button type="submit" class="btn btn-danger" style="width: 100%;">Confirm Rejection</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        document.getElementById('vesselSelect').addEventListener('change', function() {
            document.getElementById('newVesselFields').style.display = this.value ? 'none' : 'block';
        });

        function openRejectModal(id) {
            document.getElementById('rejectRequestId').value = id;
            document.getElementById('rejectModal').classList.add('active');
        }
    </script>
</body>
</html>
