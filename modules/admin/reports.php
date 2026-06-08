<?php
// /modules/admin/reports.php
require_once '../../config/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';

require_login();
require_role(['admin']);

// Report 1 — Company Throughput Ranking
$stmt1 = $pdo->query("
    SELECT
        v.company_name,
        COUNT(c.id)                                          AS total_cleared,
        RANK() OVER (ORDER BY COUNT(c.id) DESC)              AS company_rank,
        ROUND(
            COUNT(c.id) * 100.0 /
            SUM(COUNT(c.id)) OVER ()
        , 2)                                                 AS pct_of_total
    FROM vessels v
    JOIN containers c ON c.vessel_id = v.id
    WHERE c.status = 'cleared'
    GROUP BY v.company_name
    ORDER BY company_rank;
");
$report1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

// Report 2 — Zone Capacity Utilisation
$stmt2 = $pdo->query("
    SELECT
        zone,
        slot_type,
        COUNT(*)                                              AS total_slots,
        SUM(status = 'occupied')                              AS occupied,
        SUM(status = 'available')                             AS available,
        ROUND(SUM(status = 'occupied') * 100.0 / COUNT(*), 1) AS pct_used,
        CASE
            WHEN SUM(status = 'occupied') * 100.0 / COUNT(*) >= 90 THEN 'critical'
            WHEN SUM(status = 'occupied') * 100.0 / COUNT(*) >= 70 THEN 'high'
            ELSE 'normal'
        END                                                   AS capacity_status
    FROM storage_locations
    GROUP BY zone, slot_type
    ORDER BY pct_used DESC;
");
$report2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Report 3 — Containers Above Officer's Own Average Inspection Time
$stmt3 = $pdo->query("
    SELECT
        u.name                                                      AS officer,
        c.container_code,
        TIMESTAMPDIFF(MINUTE, ta.started_at, ta.completed_at)       AS minutes_taken
    FROM customs_inspections ci
    JOIN users            u  ON u.id  = ci.inspected_by
    JOIN containers       c  ON c.id  = ci.container_id
    JOIN task_assignments ta ON ta.assigned_to = ci.inspected_by
                            AND ta.ref_id       = ci.container_id
                            AND ta.ref_type     = 'container'
    WHERE TIMESTAMPDIFF(MINUTE, ta.started_at, ta.completed_at) > (
        SELECT AVG(TIMESTAMPDIFF(MINUTE, ta2.started_at, ta2.completed_at))
        FROM   customs_inspections ci2
        JOIN   task_assignments    ta2
               ON  ta2.assigned_to = ci2.inspected_by
               AND ta2.ref_id      = ci2.container_id
               AND ta2.ref_type    = 'container'
        WHERE  ci2.inspected_by = ci.inspected_by
    )
    ORDER BY u.name, minutes_taken DESC;
");
$report3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);

// Report 4 — Rolling 7-Day Container Arrival Volume
$stmt4 = $pdo->query("
    SELECT
        arrival_day,
        daily_count,
        SUM(daily_count) OVER (
            ORDER BY arrival_day
            ROWS BETWEEN 6 PRECEDING AND CURRENT ROW
        ) AS rolling_7d
    FROM (
        SELECT
            DATE(ar.eta)  AS arrival_day,
            COUNT(c.id)   AS daily_count
        FROM arrival_requests ar
        JOIN containers c ON c.vessel_id = ar.vessel_id
        GROUP BY DATE(ar.eta)
    ) sub
    ORDER BY arrival_day DESC
    LIMIT 30;
");
$report4 = $stmt4->fetchAll(PDO::FETCH_ASSOC);

// Report 5 — Full Invoice Status Summary
$stmt5 = $pdo->query("
    SELECT
        invoice_type,
        COUNT(*)                                                  AS total_invoices,
        SUM(status = 'unpaid')                                    AS unpaid,
        SUM(status = 'paid')                                      AS paid,
        SUM(status = 'overdue')                                   AS overdue,
        ROUND(SUM(amount), 2)                                     AS total_value,
        ROUND(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 2) AS collected,
        ROUND(
            SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END)
            * 100.0 / NULLIF(SUM(amount), 0)
        , 1)                                                      AS collection_rate_pct
    FROM invoices
    GROUP BY invoice_type;
");
$report5 = $stmt5->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Reports</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        section { margin-bottom: 40px; }
        h2 { border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 15px; font-size: 1.5rem; color: var(--text-primary); }
        .bg-critical { background-color: rgba(220, 53, 69, 0.2) !important; color: var(--danger) !important; font-weight: bold; }
        .bg-high { background-color: rgba(255, 193, 7, 0.2) !important; color: #ffc107 !important; font-weight: bold; }
        .bg-normal { background-color: rgba(40, 167, 69, 0.2) !important; color: var(--success) !important; font-weight: bold; }
    </style>
</head>
<body>

    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">System Reports</h1>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <section>
            <h2>Company Throughput Ranking</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Company Name</th>
                            <th>Total Cleared Containers</th>
                            <th>Rank</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report1 as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['company_name']) ?></td>
                                <td><?= htmlspecialchars($r['total_cleared']) ?></td>
                                <td><?= htmlspecialchars($r['company_rank']) ?></td>
                                <td><?= htmlspecialchars($r['pct_of_total']) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report1)): ?>
                            <tr><td colspan="4" style="text-align: center;">No data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2>Zone Capacity Utilisation</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Zone</th>
                            <th>Slot Type</th>
                            <th>Total Slots</th>
                            <th>Occupied</th>
                            <th>Available</th>
                            <th>% Used</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report2 as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['zone']) ?></td>
                                <td><?= htmlspecialchars($r['slot_type']) ?></td>
                                <td><?= htmlspecialchars($r['total_slots']) ?></td>
                                <td><?= htmlspecialchars($r['occupied']) ?></td>
                                <td><?= htmlspecialchars($r['available']) ?></td>
                                <td class="bg-<?= htmlspecialchars($r['capacity_status']) ?>">
                                    <?= htmlspecialchars($r['pct_used']) ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report2)): ?>
                            <tr><td colspan="6" style="text-align: center;">No data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2>Containers Above Officer's Own Average Inspection Time</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Officer Name</th>
                            <th>Container Code</th>
                            <th>Minutes Taken</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report3 as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['officer']) ?></td>
                                <td><?= htmlspecialchars($r['container_code']) ?></td>
                                <td><?= htmlspecialchars($r['minutes_taken']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report3)): ?>
                            <tr><td colspan="3" style="text-align: center;">No inspection data yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2>Rolling 7-Day Container Arrival Volume</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Arrival Day</th>
                            <th>Daily Count</th>
                            <th>Rolling 7-Day Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report4 as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['arrival_day']) ?></td>
                                <td><?= htmlspecialchars($r['daily_count']) ?></td>
                                <td><?= htmlspecialchars($r['rolling_7d']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report4)): ?>
                            <tr><td colspan="3" style="text-align: center;">No data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2>Full Invoice Status Summary</h2>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice Type</th>
                            <th>Total Invoices</th>
                            <th>Unpaid</th>
                            <th>Paid</th>
                            <th>Overdue</th>
                            <th>Total Value</th>
                            <th>Collected Value</th>
                            <th>Collection Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report5 as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['invoice_type']) ?></td>
                                <td><?= htmlspecialchars($r['total_invoices']) ?></td>
                                <td><?= htmlspecialchars($r['unpaid']) ?></td>
                                <td><?= htmlspecialchars($r['paid']) ?></td>
                                <td><?= htmlspecialchars($r['overdue']) ?></td>
                                <td>$<?= htmlspecialchars($r['total_value']) ?></td>
                                <td>$<?= htmlspecialchars($r['collected']) ?></td>
                                <td><?= htmlspecialchars($r['collection_rate_pct']) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($report5)): ?>
                            <tr><td colspan="8" style="text-align: center;">No data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
