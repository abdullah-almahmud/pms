<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'finance']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record_payment') {
        $invoice_id = $_POST['invoice_id'] ?? '';
        $amount_paid = $_POST['amount_paid'] ?? '';
        $payment_date = $_POST['payment_date'] ?? '';
        $notes = $_POST['notes'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Validate invoice exists and is unpaid/overdue
            $stmt = $pdo->prepare("SELECT status FROM invoices WHERE id = ? FOR UPDATE");
            $stmt->execute([$invoice_id]);
            $inv = $stmt->fetch();
            
            if ($inv && in_array($inv['status'], ['unpaid', 'overdue'])) {
                // Insert payment
                $p_stmt = $pdo->prepare("INSERT INTO payments (invoice_id, amount_paid, payment_date, recorded_by, notes) VALUES (?, ?, ?, ?, ?)");
                $p_stmt->execute([$invoice_id, $amount_paid, $payment_date, $_SESSION['user_id'], $notes]);
                $payment_id = $pdo->lastInsertId();
                
                // Mark invoice as paid (assuming full payment in this scope)
                $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = ?")->execute([$invoice_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'finance', $payment_id, "Recorded payment for invoice {$invoice_id}");
                $_SESSION['flash'] = "Payment recorded successfully. Invoice marked as paid.";
            } else {
                $_SESSION['flash_error'] = "Invoice is either already paid or doesn't exist.";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to record payment: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/finance/payments.php");
        exit;
    }
}

// GET Data
$payments = $pdo->query("
    SELECT p.*, i.client_name, i.invoice_type, u.name as recorder
    FROM payments p
    JOIN invoices i ON p.invoice_id = i.id
    JOIN users u ON p.recorded_by = u.id
    ORDER BY p.payment_date DESC, p.created_at DESC
")->fetchAll();

// Eligible invoices for dropdown
$unpaid_invoices = $pdo->query("
    SELECT id, client_name, amount, invoice_type, status 
    FROM invoices 
    WHERE status IN ('unpaid', 'overdue')
    ORDER BY id ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Payments</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Payments Received</h1>
            <button class="btn btn-primary" onclick="document.getElementById('paymentModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Record Payment
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

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Payment History</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice / Client</th>
                        <th>Amount Paid</th>
                        <th>Notes</th>
                        <th>Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="5" style="text-align: center;">No payments recorded</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><strong><?= date('d M Y', strtotime($p['payment_date'])) ?></strong></td>
                                <td>
                                    #INV-<?= str_pad($p['invoice_id'], 5, '0', STR_PAD_LEFT) ?><br>
                                    <small><?= htmlspecialchars($p['client_name']) ?> (<?= ucfirst($p['invoice_type']) ?>)</small>
                                </td>
                                <td><span style="color: var(--success-text); font-weight: bold;">$<?= number_format($p['amount_paid'], 2) ?></span></td>
                                <td><?= htmlspecialchars($p['notes']) ?></td>
                                <td><?= htmlspecialchars($p['recorder']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- RECORD PAYMENT MODAL -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Record Payment</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/finance/payments.php">
                <input type="hidden" name="action" value="record_payment">
                
                <div class="form-group">
                    <label class="form-label">Select Unpaid Invoice *</label>
                    <select name="invoice_id" id="invoiceSelect" class="form-control" required>
                        <option value="">-- Choose Invoice --</option>
                        <?php foreach ($unpaid_invoices as $inv): ?>
                            <option value="<?= $inv['id'] ?>" data-amount="<?= $inv['amount'] ?>">
                                #INV-<?= str_pad($inv['id'], 5, '0', STR_PAD_LEFT) ?> - <?= htmlspecialchars($inv['client_name']) ?> ($<?= number_format($inv['amount'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount Paid ($) *</label>
                    <input type="number" step="0.01" name="amount_paid" id="amountInput" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Payment Date *</label>
                    <input type="date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Bank Transfer Ref: TR293001"></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Confirm Payment</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        document.getElementById('invoiceSelect').addEventListener('change', function() {
            // Auto-fill amount based on selected invoice
            const option = this.options[this.selectedIndex];
            if (option.value) {
                document.getElementById('amountInput').value = option.getAttribute('data-amount');
            } else {
                document.getElementById('amountInput').value = '';
            }
        });
    </script>
</body>
</html>

