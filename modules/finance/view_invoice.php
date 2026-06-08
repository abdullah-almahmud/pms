<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'finance', 'company']);

$invoice_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die("Invoice not found.");
}

// Access check: companies can only see their own invoices
if ($_SESSION['role'] === 'company' && $_SESSION['company_id'] != $invoice['company_id']) {
    die("Access denied.");
}

// Process Payment Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    validate_csrf();
    require_role(['admin', 'finance']); // only finance/admin can record payment, not company
    
    $amount_paid = $_POST['amount_paid'] ?? 0;
    $notes = $_POST['notes'] ?? '';
    
    if ($amount_paid > 0 && in_array($invoice['status'], ['unpaid', 'overdue', 'partially_paid'])) {
        $pdo->beginTransaction();
        
        $pdo->prepare("INSERT INTO payments (invoice_id, amount_paid, payment_date, recorded_by, notes) VALUES (?, ?, CURDATE(), ?, ?)")
            ->execute([$invoice_id, $amount_paid, $_SESSION['user_id'], $notes]);
            
        // Calculate new status
        $p_stmt = $pdo->prepare("SELECT SUM(amount_paid) as total_paid FROM payments WHERE invoice_id = ?");
        $p_stmt->execute([$invoice_id]);
        $total_paid = $p_stmt->fetch()['total_paid'] ?? 0;
        
        $new_status = 'partially_paid';
        if ($total_paid >= $invoice['amount']) {
            $new_status = 'paid';
        }
        
        $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$new_status, $invoice_id]);
        
        audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'finance', $invoice_id, "Recorded payment of \${$amount_paid} for invoice {$invoice_id}");
        $pdo->commit();
        
        $_SESSION['flash'] = "Payment recorded successfully.";
        header("Location: " . BASE_URL . "/modules/finance/view_invoice.php?id=" . $invoice_id);
        exit;
    }
}

// Get cost records
$items = $pdo->prepare("SELECT * FROM cost_records WHERE invoice_id = ?");
$items->execute([$invoice_id]);
$cost_records = $items->fetchAll();

// Get payments
$payments = $pdo->prepare("SELECT p.*, u.name as recorder FROM payments p JOIN users u ON p.recorded_by = u.id WHERE p.invoice_id = ? ORDER BY p.payment_date DESC");
$payments->execute([$invoice_id]);
$payment_records = $payments->fetchAll();

$total_paid = array_sum(array_column($payment_records, 'amount_paid'));
$balance_due = max(0, $invoice['amount'] - $total_paid);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - View Invoice #INV-<?= str_pad($invoice['id'], 5, '0', STR_PAD_LEFT) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Invoice #INV-<?= str_pad($invoice['id'], 5, '0', STR_PAD_LEFT) ?></h1>
            <a href="<?= BASE_URL ?>/modules/finance/invoices.php" class="btn btn-secondary">Back to Invoices</a>
        </header>

        <?php if (isset($_SESSION['flash'])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
            <?php unset($_SESSION['flash']); ?>
        <?php endif; ?>

        <div style="display: flex; gap: 24px; flex-wrap: wrap;">
            
            <div style="flex: 2; min-width: 400px;">
                <div class="stat-card" style="padding: 32px; background: white;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 2px solid var(--border); padding-bottom: 16px; margin-bottom: 24px;">
                        <div>
                            <h2>Billed To:</h2>
                            <p><strong><?= htmlspecialchars($invoice['client_name']) ?></strong></p>
                            <p style="color: var(--text-muted);">Due Date: <?= date('d M Y', strtotime($invoice['due_date'])) ?></p>
                        </div>
                        <div style="text-align: right;">
                            <h2><?= status_badge($invoice['status']) ?></h2>
                            <h1 style="color: var(--primary-color); margin: 8px 0 0 0;">$<?= number_format($invoice['amount'], 2) ?></h1>
                        </div>
                    </div>

                    <h3>Itemized Costs</h3>
                    <table style="margin-bottom: 32px;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th style="text-align: right;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cost_records)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No itemized breakdown available (Legacy Invoice)</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($cost_records as $item): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($item['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($item['description']) ?></td>
                                        <td><?= ucfirst($item['invoice_type']) ?></td>
                                        <td style="text-align: right;">$<?= number_format($item['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr style="background: var(--surface);">
                                <td colspan="3" style="text-align: right;"><strong>Total Due:</strong></td>
                                <td style="text-align: right;"><strong>$<?= number_format($invoice['amount'], 2) ?></strong></td>
                            </tr>
                            <tr style="background: var(--surface);">
                                <td colspan="3" style="text-align: right;"><strong>Total Paid:</strong></td>
                                <td style="text-align: right; color: var(--success-text);"><strong>$<?= number_format($total_paid, 2) ?></strong></td>
                            </tr>
                            <tr style="background: var(--surface);">
                                <td colspan="3" style="text-align: right;"><strong>Balance Due:</strong></td>
                                <td style="text-align: right; color: var(--danger-text);"><strong>$<?= number_format($balance_due, 2) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if (!empty($payment_records)): ?>
                        <h3>Payment History</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Notes</th>
                                    <th>Recorded By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_records as $p): ?>
                                    <tr>
                                        <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                                        <td style="color: var(--success-text);"><strong>$<?= number_format($p['amount_paid'], 2) ?></strong></td>
                                        <td><?= htmlspecialchars($p['notes']) ?></td>
                                        <td><?= htmlspecialchars($p['recorder']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (in_array($_SESSION['role'], ['admin', 'finance']) && $balance_due > 0): ?>
                <div class="form-card" style="flex: 1; min-width: 300px; margin: 0; position: sticky; top: 40px; height: fit-content;">
                    <h2 style="margin-top: 0;">Record Payment</h2>
                    <form method="post" action="">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="record_payment">
                        
                        <div class="form-group">
                            <label class="form-label">Payment Amount ($)</label>
                            <input type="number" step="0.01" max="<?= $balance_due ?>" name="amount_paid" class="form-control" required value="<?= $balance_due ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Notes / Reference</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="e.g. Bank Transfer ID"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">Submit Payment</button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
