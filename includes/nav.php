<?php
// includes/nav.php
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['name'] ?? '';
$current_page = $_SERVER['PHP_SELF'];
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <i class="fa-solid fa-anchor"></i>
        <span>PMS Terminal</span>
    </div>
    <nav class="sidebar-nav">
        <a href="<?= BASE_URL ?>/dashboard.php" class="<?= $current_page === '/dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        
        <?php if ($user_role === 'company'): ?>
            <div class="nav-section-label">Commercial Portal</div>
            <a href="<?= BASE_URL ?>/modules/client/berth_requests.php" class="<?= strpos($current_page, '/client/berth_requests.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-ship"></i> Berth Requests</a>
            <a href="<?= BASE_URL ?>/modules/client/tracking.php" class="<?= strpos($current_page, '/client/tracking.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-route"></i> Supply Chain Track</a>
            <a href="<?= BASE_URL ?>/modules/client/invoices.php" class="<?= strpos($current_page, '/client/invoices.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> My Invoices</a>
        <?php endif; ?>

        <?php if (in_array($user_role, ['admin', 'harbor_master'])): ?>
            <div class="nav-section-label">Vessel & Berth</div>
            <a href="<?= BASE_URL ?>/modules/vessel/arrivals.php" class="<?= strpos($current_page, '/vessel/arrivals.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-ship"></i> Arrivals</a>
            <a href="<?= BASE_URL ?>/modules/vessel/berths.php" class="<?= strpos($current_page, '/vessel/berths.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-dharmachakra"></i> Berths</a>
        <?php endif; ?>
        
        <?php if (in_array($user_role, ['admin', 'harbor_master', 'operations', 'finance'])): ?>
            <a href="<?= BASE_URL ?>/modules/vessel/schedule.php" class="<?= strpos($current_page, '/vessel/schedule.php') !== false ? 'active' : '' ?>"><i class="fa-regular fa-calendar-days"></i> Schedule</a>
        <?php endif; ?>
        
        <?php if (in_array($user_role, ['admin', 'operations'])): ?>
            <div class="nav-section-label">Customs & Cargo</div>
            <a href="<?= BASE_URL ?>/modules/customs/manifests.php" class="<?= strpos($current_page, '/customs/manifests.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice"></i> Manifests</a>
            <a href="<?= BASE_URL ?>/modules/customs/inspections.php" class="<?= strpos($current_page, '/customs/inspections.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-magnifying-glass"></i> Inspections</a>
            <a href="<?= BASE_URL ?>/modules/cargo/containers.php" class="<?= strpos($current_page, '/cargo/containers.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-box"></i> Containers</a>
            <a href="<?= BASE_URL ?>/modules/cargo/storage.php" class="<?= strpos($current_page, '/cargo/storage.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-warehouse"></i> Storage</a>
            <div class="nav-section-label">Logistics</div>
            <a href="<?= BASE_URL ?>/modules/logistics/transport.php" class="<?= strpos($current_page, '/logistics/transport.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-truck"></i> Transport</a>
            <a href="<?= BASE_URL ?>/modules/logistics/delivery.php" class="<?= strpos($current_page, '/logistics/delivery.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-route"></i> Delivery</a>
        <?php endif; ?>
        
        <?php if (in_array($user_role, ['admin', 'finance'])): ?>
            <div class="nav-section-label">Workforce</div>
            <a href="<?= BASE_URL ?>/modules/workforce/shifts.php" class="<?= strpos($current_page, '/workforce/shifts.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-users-gear"></i> Shifts</a>
            <a href="<?= BASE_URL ?>/modules/workforce/tasks.php" class="<?= strpos($current_page, '/workforce/tasks.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> Tasks</a>
            <div class="nav-section-label">Finance</div>
            <a href="<?= BASE_URL ?>/modules/finance/invoices.php" class="<?= strpos($current_page, '/finance/invoices.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices</a>
            <a href="<?= BASE_URL ?>/modules/finance/payments.php" class="<?= strpos($current_page, '/finance/payments.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-money-bill-wave"></i> Payments</a>
        <?php endif; ?>
        
        <?php if ($user_role === 'admin'): ?>
            <div class="nav-section-label">Administration</div>
            <a href="<?= BASE_URL ?>/modules/admin/users.php" class="<?= strpos($current_page, '/admin/users.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Users</a>
            <a href="<?= BASE_URL ?>/modules/admin/audit_logs.php" class="<?= strpos($current_page, '/admin/audit_logs.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-shield-halved"></i> Audit Log</a>
            <a href="<?= BASE_URL ?>/modules/admin/employee_monitor.php" class="<?= strpos($current_page, '/admin/employee_monitor.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-desktop"></i> Employee Monitor</a>
            <a href="<?= BASE_URL ?>/modules/admin/flags.php" class="<?= strpos($current_page, '/admin/flags.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-flag"></i> Flag History</a>
            <a href="<?= BASE_URL ?>/modules/admin/reports.php" class="<?= strpos($current_page, '/admin/reports.php') !== false ? 'active' : '' ?>"><i class="fa-solid fa-chart-bar"></i> Reports</a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="user-info">
            <strong><?= htmlspecialchars($user_name) ?></strong><br>
            <span style="font-size:0.75rem;color:var(--accent);font-weight:600;">
                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $user_role))) ?>
            </span>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="logout-btn" data-confirm="Are you sure you want to log out?"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>
</aside>
