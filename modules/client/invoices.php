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

// Gather billing totals
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE company_id = ? AND status IN ('unpaid','overdue')");
$stmt->execute([$company_id]);
$unpaid_sum = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount_paid), 0) 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    WHERE i.company_id = ?
");
$stmt->execute([$company_id]);
$paid_sum = $stmt->fetchColumn();

// Fetch invoices
$stmt = $pdo->prepare("
    SELECT * 
    FROM invoices 
    WHERE company_id = ? 
    ORDER BY generated_at DESC
");
$stmt->execute([$company_id]);
$invoices = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - My Invoices & Billing</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div>
                <a href="/dashboard.php" class="btn btn-secondary btn-sm" style="margin-bottom:12px;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
                <h1 class="page-title">My Invoices</h1>
            </div>
        </header>

        <!-- Billing Summary Cards -->
        <section class="dashboard-grid">
            <div class="stat-card stat-card--warning">
                <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
                <div>
                    <div class="stat-title">Outstanding Balance</div>
                    <div class="stat-value">$<?= number_format($unpaid_sum, 2) ?></div>
                </div>
            </div>
            <div class="stat-card stat-card--success">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="stat-title">Total Payments Settled</div>
                    <div class="stat-value">$<?= number_format($paid_sum, 2) ?></div>
                </div>
            </div>
        </section>

        <!-- TABLE OF INVOICES -->
        <div class="table-container">
            <div class="table-header">
                <h2>Invoice Ledger</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Invoice Reference</th>
                        <th>Type</th>
                        <th>Billed Amount</th>
                        <th>Due Date</th>
                        <th>Generated On</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="6" style="text-align:center;">No billing statements generated. Link invoices to your company profile to see records.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $i): ?>
                            <tr>
                                <td><strong>#INV-<?= str_pad($i['id'], 5, '0', STR_PAD_LEFT) ?></strong></td>
                                <td><span class="badge badge-neutral"><?= htmlspecialchars(ucfirst($i['invoice_type'])) ?> allocation</span></td>
                                <td><strong>$<?= number_format($i['amount'], 2) ?></strong></td>
                                <td><?= date('d M Y', strtotime($i['due_date'])) ?></td>
                                <td><?= date('d M Y, H:i', strtotime($i['generated_at'])) ?></td>
                                <td><?= status_badge($i['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>

