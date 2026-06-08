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
    
    if ($action === 'register_container') {
        $container_code = $_POST['container_code'] ?? '';
        $container_type = $_POST['container_type'] ?? '';
        $size_teu = $_POST['size_teu'] ?? '';
        $weight_kg = $_POST['weight_kg'] ?? '';
        $direction = $_POST['direction'] ?? '';
        $vessel_id = $_POST['vessel_id'] ?? '';
        $manifest_item_id = !empty($_POST['manifest_item_id']) ? $_POST['manifest_item_id'] : null;
        
        try {
            $stmt = $pdo->prepare("INSERT INTO containers (container_code, container_type, size_teu, weight_kg, direction, vessel_id, manifest_item_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'arrived')");
            $stmt->execute([$container_code, $container_type, $size_teu, $weight_kg, $direction, $vessel_id, $manifest_item_id]);
            $container_id = $pdo->lastInsertId();
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'cargo', $container_id, "Registered new container {$container_code}");
            $_SESSION['flash'] = "Container {$container_code} registered successfully.";
        } catch (PDOException $e) {
            // Check for duplicate container code
            if ($e->getCode() == 23000) {
                $_SESSION['flash_error'] = "Container code {$container_code} already exists.";
            } else {
                $_SESSION['flash_error'] = "Database error: " . $e->getMessage();
            }
        }
        
        header("Location: " . BASE_URL . "/modules/cargo/containers.php");
        exit;
    }
}

// GET Data
$status_filter = $_GET['status'] ?? 'all';
$direction_filter = $_GET['direction'] ?? 'all';
$type_filter = $_GET['container_type'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$count_query = "SELECT COUNT(*) FROM containers c JOIN vessels v ON c.vessel_id = v.id";
$query = "SELECT c.*, v.vessel_name FROM containers c JOIN vessels v ON c.vessel_id = v.id";
$where = [];
$params = [];

if ($status_filter !== 'all') {
    $where[] = "c.status = ?";
    $params[] = $status_filter;
}
if ($direction_filter !== 'all') {
    $where[] = "c.direction = ?";
    $params[] = $direction_filter;
}
if ($type_filter !== 'all') {
    $where[] = "c.container_type = ?";
    $params[] = $type_filter;
}

if (!empty($where)) {
    $count_query .= " WHERE " . implode(" AND ", $where);
    $query .= " WHERE " . implode(" AND ", $where);
}
$query .= " ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";

// Count total matching items
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$stmt = $pdo->prepare($query);
// Bind query params
$param_index = 1;
foreach ($params as $param) {
    $stmt->bindValue($param_index++, $param);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$containers = $stmt->fetchAll();

// For the registration form
$vessels = $pdo->query("SELECT id, vessel_name FROM vessels ORDER BY vessel_name ASC")->fetchAll();
$manifest_items = $pdo->query("
    SELECT mi.id, mi.container_code, v.vessel_name 
    FROM manifest_items mi
    JOIN cargo_manifests cm ON mi.manifest_id = cm.id
    JOIN arrival_requests ar ON cm.arrival_request_id = ar.id
    JOIN vessels v ON ar.vessel_id = v.id
    WHERE mi.id NOT IN (SELECT manifest_item_id FROM containers WHERE manifest_item_id IS NOT NULL)
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Containers</title>
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
            <h1 class="page-title">Container Registry</h1>
            <button class="btn btn-primary" onclick="document.getElementById('registerModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Register Container
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
            <form method="get" action="<?= BASE_URL ?>/modules/cargo/containers.php" style="display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 150px;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="arrived" <?= $status_filter === 'arrived' ? 'selected' : '' ?>>Arrived</option>
                        <option value="cleared" <?= $status_filter === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                        <option value="held" <?= $status_filter === 'held' ? 'selected' : '' ?>>Held</option>
                        <option value="stored" <?= $status_filter === 'stored' ? 'selected' : '' ?>>Stored</option>
                        <option value="in_transit" <?= $status_filter === 'in_transit' ? 'selected' : '' ?>>In Transit</option>
                        <option value="delivered" <?= $status_filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label class="form-label">Direction</label>
                    <select name="direction" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $direction_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="inbound" <?= $direction_filter === 'inbound' ? 'selected' : '' ?>>Inbound</option>
                        <option value="outbound" <?= $direction_filter === 'outbound' ? 'selected' : '' ?>>Outbound</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 150px;">
                    <label class="form-label">Type</label>
                    <select name="container_type" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="standard" <?= $type_filter === 'standard' ? 'selected' : '' ?>>Standard</option>
                        <option value="refrigerated" <?= $type_filter === 'refrigerated' ? 'selected' : '' ?>>Refrigerated</option>
                        <option value="hazmat" <?= $type_filter === 'hazmat' ? 'selected' : '' ?>>Hazmat</option>
                        <option value="bulk" <?= $type_filter === 'bulk' ? 'selected' : '' ?>>Bulk</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>All Containers</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Vessel</th>
                        <th>Direction</th>
                        <th>Type</th>
                        <th>Size (TEU)</th>
                        <th>Weight (kg)</th>
                        <th>Status</th>
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
                                <td><?= htmlspecialchars(ucfirst($c['direction'])) ?></td>
                                <td><?= htmlspecialchars(ucfirst($c['container_type'])) ?></td>
                                <td><?= number_format($c['size_teu'], 1) ?></td>
                                <td><?= number_format($c['weight_kg']) ?></td>
                                <td><?= status_badge($c['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION CONTROLS -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php 
                $query_params = $_GET;
                ?>
                
                <?php if ($page > 1): ?>
                    <?php $query_params['page'] = $page - 1; ?>
                    <a href="?<?= http_build_query($query_params) ?>" class="pagination-item"><i class="fa-solid fa-angle-left"></i></a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php $query_params['page'] = $i; ?>
                    <a href="?<?= http_build_query($query_params) ?>" class="pagination-item <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <?php $query_params['page'] = $page + 1; ?>
                    <a href="?<?= http_build_query($query_params) ?>" class="pagination-item"><i class="fa-solid fa-angle-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- REGISTER MODAL -->
    <div id="registerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Register New Container</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/cargo/containers.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="register_container">
                
                <div class="form-group">
                    <label class="form-label">Container Code *</label>
                    <input type="text" name="container_code" class="form-control" required placeholder="e.g. MSCU1234567">
                </div>
                
                <div style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Type *</label>
                        <select name="container_type" class="form-control" required>
                            <option value="standard">Standard</option>
                            <option value="refrigerated">Refrigerated</option>
                            <option value="hazmat">Hazmat</option>
                            <option value="bulk">Bulk</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Direction *</label>
                        <select name="direction" class="form-control" required>
                            <option value="inbound">Inbound</option>
                            <option value="outbound">Outbound</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Size (TEU) *</label>
                        <input type="number" step="0.1" name="size_teu" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Weight (kg) *</label>
                        <input type="number" step="0.01" name="weight_kg" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Vessel *</label>
                    <select name="vessel_id" class="form-control" required>
                        <option value="">-- Select Vessel --</option>
                        <?php foreach ($vessels as $v): ?>
                            <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vessel_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Link to Manifest Item (Optional)</label>
                    <select name="manifest_item_id" class="form-control">
                        <option value="">-- No Manifest Link --</option>
                        <?php foreach ($manifest_items as $mi): ?>
                            <option value="<?= $mi['id'] ?>"><?= htmlspecialchars($mi['container_code']) ?> (<?= htmlspecialchars($mi['vessel_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">Only shows manifest items not yet linked to a container.</small>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Register Container</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>

