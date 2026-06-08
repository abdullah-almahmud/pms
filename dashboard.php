<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// Retrieve company details for Shipping Company portal
$company_id = null;
if ($user_role === 'company') {
    $stmt = $pdo->prepare("SELECT company_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $company_id = $stmt->fetchColumn();
}

$metrics = [];

try {
    // ── Universal metrics ──
    $metrics['total_vessels'] = $pdo->query("SELECT COUNT(*) FROM vessels")->fetchColumn();
    $metrics['total_containers'] = $pdo->query("SELECT COUNT(*) FROM containers")->fetchColumn();

    // ── Admin metrics (Consolidated, highly key performance indicators only) ──
    if ($user_role === 'admin') {
        $metrics['active_users'] = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
        $metrics['pending_arrivals'] = $pdo->query("SELECT COUNT(*) FROM arrival_requests WHERE status = 'pending'")->fetchColumn();
        $metrics['containers_in_transit'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'in_transit'")->fetchColumn();
        $metrics['total_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments")->fetchColumn();
        
        $metrics['occupied_berths'] = $pdo->query("SELECT COUNT(*) FROM berths WHERE status = 'occupied'")->fetchColumn();
        $metrics['total_berths'] = $pdo->query("SELECT COUNT(*) FROM berths")->fetchColumn();
        $metrics['awaiting_inspection'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status IN ('arrived', 'inspecting') AND id NOT IN (SELECT container_id FROM customs_inspections)")->fetchColumn();
        $metrics['stored_containers'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'stored'")->fetchColumn();
        $metrics['delivered_containers'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'delivered'")->fetchColumn();
        
        $metrics['unpaid_invoices'] = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','overdue')")->fetchColumn();
        $metrics['overdue_invoices'] = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'overdue'")->fetchColumn();
        $metrics['active_workers'] = $pdo->query("SELECT COUNT(*) FROM workers WHERE status = 'active'")->fetchColumn();
        $metrics['shifts_today'] = $pdo->query("SELECT COUNT(*) FROM shifts WHERE shift_date = CURDATE()")->fetchColumn();
        $metrics['tasks_pending'] = $pdo->query("SELECT COUNT(*) FROM task_assignments WHERE status = 'assigned'")->fetchColumn();
    }

    // ── Shipping Company (B2B Client) metrics ──
    if ($user_role === 'company' && $company_id) {
        // My requested arrivals count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM arrival_requests ar JOIN vessels v ON ar.vessel_id = v.id WHERE v.company_id = ?");
        $stmt->execute([$company_id]);
        $metrics['my_arrivals'] = $stmt->fetchColumn();

        // My active tracking containers count (not delivered yet)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM containers c JOIN vessels v ON c.vessel_id = v.id WHERE v.company_id = ? AND c.status <> 'delivered'");
        $stmt->execute([$company_id]);
        $metrics['my_active_containers'] = $stmt->fetchColumn();

        // My unpaid invoices count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE company_id = ? AND status IN ('unpaid','overdue')");
        $stmt->execute([$company_id]);
        $metrics['my_unpaid_invoices'] = $stmt->fetchColumn();

        // My outstanding balance sum
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE company_id = ? AND status IN ('unpaid','overdue')");
        $stmt->execute([$company_id]);
        $metrics['my_outstanding'] = $stmt->fetchColumn();
    }

    // ── Harbor Master metrics ──
    if ($user_role === 'harbor_master') {
        $metrics['pending_arrivals'] = $pdo->query("SELECT COUNT(*) FROM arrival_requests WHERE status = 'pending'")->fetchColumn();
        $metrics['occupied_berths'] = $pdo->query("SELECT COUNT(*) FROM berths WHERE status = 'occupied'")->fetchColumn();
        $metrics['total_berths'] = $pdo->query("SELECT COUNT(*) FROM berths")->fetchColumn();
        $metrics['todays_dockings'] = $pdo->query("SELECT COUNT(*) FROM berth_allocations WHERE DATE(docking_start) = CURDATE()")->fetchColumn();
    }

    // ── Operations metrics ──
    if ($user_role === 'operations') {
        $metrics['awaiting_inspection'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status IN ('arrived', 'inspecting') AND id NOT IN (SELECT container_id FROM customs_inspections)")->fetchColumn();
        $metrics['awaiting_storage'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'cleared' AND id NOT IN (SELECT container_id FROM container_assignments WHERE vacated_at IS NULL)")->fetchColumn();
        $metrics['containers_in_transit'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'in_transit'")->fetchColumn();
        $metrics['stored_containers'] = $pdo->query("SELECT COUNT(*) FROM containers WHERE status = 'stored'")->fetchColumn();
    }

    // ── Finance metrics ──
    if ($user_role === 'finance') {
        $metrics['unpaid_invoices'] = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'unpaid'")->fetchColumn();
        $metrics['overdue_invoices'] = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'overdue'")->fetchColumn();
        $metrics['total_revenue'] = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments")->fetchColumn();
        $metrics['shifts_today'] = $pdo->query("SELECT COUNT(*) FROM shifts WHERE shift_date = CURDATE()")->fetchColumn();
        $metrics['tasks_pending'] = $pdo->query("SELECT COUNT(*) FROM task_assignments WHERE status = 'assigned'")->fetchColumn();
        $metrics['active_workers'] = $pdo->query("SELECT COUNT(*) FROM workers WHERE status = 'active'")->fetchColumn();
    }

    // ── Data lists ──
    $recent_logs = [];
    if ($user_role === 'admin') {
        $recent_logs = $pdo->query("
            SELECT a.*, u.name as user_name 
            FROM audit_log a 
            JOIN users u ON a.user_id = u.id 
            ORDER BY a.created_at DESC 
            LIMIT 5
        ")->fetchAll();
    }

    $recent_arrivals = [];
    if ($user_role === 'company' && $company_id) {
        $stmt = $pdo->prepare("
            SELECT ar.*, v.vessel_name 
            FROM arrival_requests ar 
            JOIN vessels v ON ar.vessel_id = v.id 
            WHERE v.company_id = ?
            ORDER BY ar.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$company_id]);
        $recent_arrivals = $stmt->fetchAll();
    } else {
        $recent_arrivals = $pdo->query("
            SELECT ar.*, v.vessel_name 
            FROM arrival_requests ar 
            JOIN vessels v ON ar.vessel_id = v.id 
            ORDER BY ar.created_at DESC 
            LIMIT 5
        ")->fetchAll();
    }

} catch (PDOException $e) {
    // Fail-safe handling for local installation checks
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PMS Terminal - Dashboard</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <?php include __DIR__ . '/includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <p style="color: var(--text-muted); margin-top: 4px;">Welcome back, <strong><?= htmlspecialchars($user_name) ?></strong>. Active terminal interface.</p>
            </div>
        </header>

        <!-- ─── ADMIN DASHBOARD ─── -->
        <?php if ($user_role === 'admin'): ?>
            <!-- Row 1: Key Financials & Activity -->
            <section class="dashboard-grid">
                <div class="stat-card stat-card--success">
                    <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
                    <div><div class="stat-title">Total Revenue</div><div class="stat-value">$<?= number_format($metrics['total_revenue'] ?? 0) ?></div></div>
                </div>
                <div class="stat-card stat-card--warning">
                    <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                    <div><div class="stat-title">Pending Arrivals</div><div class="stat-value"><?= $metrics['pending_arrivals'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div><div class="stat-title">Cargo Stored</div><div class="stat-value"><?= $metrics['stored_containers'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--accent">
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    <div><div class="stat-title">Active Workers</div><div class="stat-value"><?= $metrics['active_workers'] ?? 0 ?></div></div>
                </div>
            </section>

            <!-- Consolidated Layout: Operational Status Overview panel -->
            <div class="glass-panel" style="margin-bottom: 28px;">
                <h3 style="font-size: 1.15rem; margin-bottom: 20px; color: #fff; border-bottom: 1px solid var(--border-light); padding-bottom: 10px;">
                    <i class="fa-solid fa-circle-nodes" style="color: var(--accent); margin-right: 8px;"></i> Terminal Operational Status
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px;">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Berths Occupied</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #fff; margin-top: 4px;">
                            <?= $metrics['occupied_berths'] ?? 0 ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--text-muted);">/ <?= $metrics['total_berths'] ?? 0 ?></span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Awaiting Inspection</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #fff; margin-top: 4px;"><?= $metrics['awaiting_inspection'] ?? 0 ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Unpaid Invoices</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #fff; margin-top: 4px;">
                            <?= $metrics['unpaid_invoices'] ?? 0 ?> <span style="font-size:0.75rem; color:var(--danger); font-weight:600;">(<?= $metrics['overdue_invoices'] ?? 0 ?> overdue)</span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted);">Shifts Scheduled</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #fff; margin-top: 4px;"><?= $metrics['shifts_today'] ?? 0 ?></div>
                    </div>
                </div>
            </div>

            <!-- Two Column Info Tables -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 28px;">
                <!-- Recent Arrivals -->
                <div class="table-container">
                    <div class="table-header"><h2>Recent Terminal Arrivals</h2></div>
                    <table>
                        <thead><tr><th>Vessel</th><th>ETA</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if (empty($recent_arrivals)): ?>
                                <tr><td colspan="3" style="text-align:center;">No arrivals recorded</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_arrivals as $ar): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($ar['vessel_name']) ?></strong></td>
                                        <td><?= format_dt($ar['eta']) ?></td>
                                        <td><?= status_badge($ar['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Audit Log activity -->
                <div class="table-container">
                    <div class="table-header"><h2>Audit Trail Logs</h2></div>
                    <table>
                        <thead><tr><th>User</th><th>Action</th><th>Details</th></tr></thead>
                        <tbody>
                            <?php if (empty($recent_logs)): ?>
                                <tr><td colspan="3" style="text-align:center;">No activity logged</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($log['user_name']) ?></td>
                                        <td><span class="badge badge-neutral"><?= htmlspecialchars($log['action']) ?></span></td>
                                        <td style="max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($log['description'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ─── SHIPPING COMPANY DASHBOARD (B2B Client) ─── -->
        <?php if ($user_role === 'company'): ?>
            <!-- Row 1: Key Client Stats -->
            <section class="dashboard-grid">
                <div class="stat-card stat-card--accent">
                    <div class="stat-icon"><i class="fa-solid fa-ship"></i></div>
                    <div><div class="stat-title">My Vessel Arrivals</div><div class="stat-value"><?= $metrics['my_arrivals'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-packing"></i></div>
                    <div><div class="stat-title">Containers in Terminal</div><div class="stat-value"><?= $metrics['my_active_containers'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--danger">
                    <div class="stat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div><div class="stat-title">Unpaid Invoices</div><div class="stat-value"><?= $metrics['my_unpaid_invoices'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--warning">
                    <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
                    <div><div class="stat-title">Outstanding Balance</div><div class="stat-value">$<?= number_format($metrics['my_outstanding'] ?? 0) ?></div></div>
                </div>
            </section>

            <!-- Table of Client specific arrivals -->
            <div class="table-container">
                <div class="table-header"><h2>My Active Vessel Bookings</h2></div>
                <table>
                    <thead><tr><th>Vessel</th><th>ETA</th><th>Origin Port</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($recent_arrivals)): ?>
                            <tr><td colspan="4" style="text-align:center;">No arrival requests found. Link vessels to your company name to see them.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_arrivals as $ar): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ar['vessel_name']) ?></strong></td>
                                    <td><?= format_dt($ar['eta']) ?></td>
                                    <td><?= htmlspecialchars($ar['origin_port'] ?? '-') ?></td>
                                    <td><?= status_badge($ar['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ─── HARBOR MASTER DASHBOARD ─── -->
        <?php if ($user_role === 'harbor_master'): ?>
            <section class="dashboard-grid">
                <div class="stat-card stat-card--warning">
                    <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                    <div><div class="stat-title">Pending Arrivals</div><div class="stat-value"><?= $metrics['pending_arrivals'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-icon"><i class="fa-solid fa-dharmachakra"></i></div>
                    <div><div class="stat-title">Berths Occupied</div><div class="stat-value"><?= $metrics['occupied_berths'] ?? 0 ?> / <?= $metrics['total_berths'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--accent">
                    <div class="stat-icon"><i class="fa-solid fa-ship"></i></div>
                    <div><div class="stat-title">Today's Dockings</div><div class="stat-value"><?= $metrics['todays_dockings'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Total Vessels</div>
                    <div class="stat-value"><?= $metrics['total_vessels'] ?? 0 ?></div>
                </div>
            </section>

            <div class="table-container">
                <div class="table-header"><h2>Recent Terminal Arrivals</h2></div>
                <table>
                    <thead><tr><th>Vessel</th><th>ETA</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_arrivals as $ar): ?>
                            <tr><td><strong><?= htmlspecialchars($ar['vessel_name']) ?></strong></td><td><?= format_dt($ar['eta']) ?></td><td><?= status_badge($ar['status']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ─── OPERATIONS DASHBOARD ─── -->
        <?php if ($user_role === 'operations'): ?>
            <section class="dashboard-grid">
                <div class="stat-card stat-card--warning">
                    <div class="stat-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <div><div class="stat-title">Awaiting Inspection</div><div class="stat-value"><?= $metrics['awaiting_inspection'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-icon"><i class="fa-solid fa-warehouse"></i></div>
                    <div><div class="stat-title">Awaiting Storage</div><div class="stat-value"><?= $metrics['awaiting_storage'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--accent">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <div><div class="stat-title">In Storage</div><div class="stat-value"><?= $metrics['stored_containers'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--success">
                    <div class="stat-icon"><i class="fa-solid fa-truck-fast"></i></div>
                    <div><div class="stat-title">In Transit</div><div class="stat-value"><?= $metrics['containers_in_transit'] ?? 0 ?></div></div>
                </div>
            </section>

            <div class="table-container">
                <div class="table-header"><h2>Recent Terminal Arrivals</h2></div>
                <table>
                    <thead><tr><th>Vessel</th><th>ETA</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($recent_arrivals as $ar): ?>
                            <tr><td><strong><?= htmlspecialchars($ar['vessel_name']) ?></strong></td><td><?= format_dt($ar['eta']) ?></td><td><?= status_badge($ar['status']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ─── FINANCE DASHBOARD ─── -->
        <?php if ($user_role === 'finance'): ?>
            <section class="dashboard-grid">
                <div class="stat-card stat-card--success">
                    <div class="stat-icon"><i class="fa-solid fa-dollar-sign"></i></div>
                    <div><div class="stat-title">Total Revenue</div><div class="stat-value">$<?= number_format($metrics['total_revenue'] ?? 0) ?></div></div>
                </div>
                <div class="stat-card stat-card--warning">
                    <div class="stat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                    <div><div class="stat-title">Unpaid Invoices</div><div class="stat-value"><?= $metrics['unpaid_invoices'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--danger">
                    <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div><div class="stat-title">Overdue Invoices</div><div class="stat-value"><?= $metrics['overdue_invoices'] ?? 0 ?></div></div>
                </div>
                <div class="stat-card stat-card--info">
                    <div class="stat-icon"><i class="fa-solid fa-users-gear"></i></div>
                    <div><div class="stat-title">Active Workers</div><div class="stat-value"><?= $metrics['active_workers'] ?? 0 ?></div></div>
                </div>
            </section>
            <section class="dashboard-grid" style="margin-top: 0;">
                <div class="stat-card">
                    <div class="stat-title">Shifts Today</div>
                    <div class="stat-value"><?= $metrics['shifts_today'] ?? 0 ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Tasks Pending</div>
                    <div class="stat-value"><?= $metrics['tasks_pending'] ?? 0 ?></div>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
