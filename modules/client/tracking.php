<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['company']);

$user_id = $_SESSION['user_id'];

// Resolve company ID
$stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$company_id = $stmt->fetchColumn();

if (!$company_id) {
    die("Corporate link not found. Contact administrator.");
}

$search = trim($_GET['search'] ?? '');
$selected_container = null;

// Fetch details if a specific container is selected
if (isset($_GET['code'])) {
    $stmt = $pdo->prepare("
        SELECT c.*, v.vessel_name 
        FROM containers c
        JOIN vessels v ON c.vessel_id = v.id
        WHERE c.container_code = ? AND v.company_id = ?
    ");
    $stmt->execute([$_GET['code'], $company_id]);
    $selected_container = $stmt->fetch();
}

// Fetch all containers belonging to this company's vessels
$query = "
    SELECT c.*, v.vessel_name 
    FROM containers c
    JOIN vessels v ON c.vessel_id = v.id
    WHERE v.company_id = ?
";
$params = [$company_id];

if ($search) {
    $query .= " AND c.container_code LIKE ?";
    $params[] = "%$search%";
}

$query .= " ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$containers = $stmt->fetchAll();

// Map container status to timeline step numbers
$status_levels = [
    'arrived' => 2,
    'inspecting' => 3,
    'cleared' => 4,
    'held' => 4,
    'rejected' => 4,
    'stored' => 5,
    'in_transit' => 6,
    'delivered' => 7
];

$timeline_steps = [
    1 => ['label' => 'Manifested', 'icon' => 'fa-file-signature'],
    2 => ['label' => 'Arrived', 'icon' => 'fa-ship'],
    3 => ['label' => 'Inspecting', 'icon' => 'fa-magnifying-glass'],
    4 => ['label' => 'Clearance', 'icon' => 'fa-clipboard-check'],
    5 => ['label' => 'Stored', 'icon' => 'fa-warehouse'],
    6 => ['label' => 'In Transit', 'icon' => 'fa-truck-fast'],
    7 => ['label' => 'Delivered', 'icon' => 'fa-house-circle-check']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Supply Chain Tracking</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div>
                <a href="/dashboard.php" class="btn btn-secondary btn-sm" style="margin-bottom:12px;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                <h1 class="page-title">Supply Chain Track</h1>
            </div>
        </header>

        <!-- CONTAINER TIMELINE VIEWER -->
        <?php if ($selected_container): ?>
            <?php 
                $current_status = $selected_container['status'];
                $current_level = $status_levels[$current_status] ?? 1; // Default to manifested
                
                // Calculate tracking width percentage
                $percentage = (($current_level - 1) / 6) * 100;
            ?>
            <div class="glass-panel" style="margin-bottom: 28px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--panel-border); padding-bottom: 16px; margin-bottom: 24px;">
                    <div>
                        <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Container Code</span>
                        <h2 style="font-size: 1.5rem; margin: 4px 0 0 0;"><?= htmlspecialchars($selected_container['container_code']) ?></h2>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Current Status</span><br>
                        <?= status_badge($current_status) ?>
                    </div>
                </div>

                <!-- PROGRESS BAR -->
                <div class="timeline-tracker">
                    <div class="timeline-tracker-progress" style="width: <?= $percentage ?>%;"></div>
                    <?php foreach ($timeline_steps as $lvl => $step): ?>
                        <?php 
                            $class = '';
                            if ($lvl == $current_level) {
                                $class = 'active';
                            } elseif ($lvl < $current_level) {
                                $class = 'completed';
                            }
                            
                            // Visual branch mapping for HELD / REJECTED blocks
                            $icon = $step['icon'];
                            $label = $step['label'];
                            
                            if ($lvl == 4 && in_array($current_status, ['held', 'rejected'])) {
                                $icon = $current_status === 'held' ? 'fa-triangle-exclamation' : 'fa-circle-xmark';
                                $label = ucfirst($current_status);
                            }
                        ?>
                        <div class="timeline-step <?= $class ?>">
                            <div class="timeline-node">
                                <i class="fa-solid <?= $icon ?>"></i>
                            </div>
                            <div class="timeline-label"><?= htmlspecialchars($label) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 40px; background: rgba(0,0,0,0.15); padding: 20px; border-radius: var(--radius-md);">
                    <div>
                        <span style="font-size:0.75rem; color:var(--text-muted);">Vessel Name</span>
                        <div style="font-weight: 700; font-size:1rem; margin-top:4px;"><?= htmlspecialchars($selected_container['vessel_name']) ?></div>
                    </div>
                    <div>
                        <span style="font-size:0.75rem; color:var(--text-muted);">Container Type</span>
                        <div style="font-weight: 700; font-size:1rem; margin-top:4px;"><?= htmlspecialchars(ucfirst($selected_container['container_type'])) ?></div>
                    </div>
                    <div>
                        <span style="font-size:0.75rem; color:var(--text-muted);">TEU Size</span>
                        <div style="font-weight: 700; font-size:1rem; margin-top:4px;"><?= htmlspecialchars($selected_container['size_teu']) ?> TEU</div>
                    </div>
                    <div>
                        <span style="font-size:0.75rem; color:var(--text-muted);">Declared Weight</span>
                        <div style="font-weight: 700; font-size:1rem; margin-top:4px;"><?= number_format($selected_container['weight_kg'], 2) ?> kg</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- FILTER & SEARCH PANEL -->
        <div class="glass-panel" style="padding: 20px; margin-bottom: 28px;">
            <form method="get" action="<?= BASE_URL ?>/modules/client/tracking.php" style="display: flex; gap: 16px; align-items: center;">
                <div style="flex: 1; position: relative;">
                    <input type="text" name="search" class="form-control" placeholder="Search by container code (e.g. MSKU123456)..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter Cargo</button>
                <?php if ($search || $selected_container): ?>
                    <a href="<?= BASE_URL ?>/modules/client/tracking.php" class="btn btn-secondary">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- LIST OF CONTAINERS -->
        <div class="table-container">
            <div class="table-header">
                <h2>Tracked Containers In Terminal</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Container Code</th>
                        <th>Vessel Name</th>
                        <th>Type</th>
                        <th>Cargo Direction</th>
                        <th>Current Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($containers)): ?>
                        <tr><td colspan="7" style="text-align:center;">No containers found. Link container manifest lines to your company name to see them.</td></tr>
                    <?php else: ?>
                        <?php foreach ($containers as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['container_code']) ?></strong></td>
                                <td><?= htmlspecialchars($c['vessel_name']) ?></td>
                                <td><span class="badge badge-neutral"><?= htmlspecialchars(ucfirst($c['container_type'])) ?></span></td>
                                <td><?= htmlspecialchars(ucfirst($c['direction'])) ?></td>
                                <td><?= status_badge($c['status']) ?></td>
                                <td><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                                <td>
                                    <a href="<?= BASE_URL ?>/modules/client/tracking.php?code=<?= urlencode($c['container_code']) ?>&search=<?= urlencode($search) ?>" class="btn btn-sm btn-secondary">
                                        <i class="fa-solid fa-location-crosshairs"></i> Locate Timeline
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>

