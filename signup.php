<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $company_name = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($company_name && $email && $password) {
        try {
            $pdo->beginTransaction();

            // 1. Check if email exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception("Email address is already registered.");
            }

            // 2. Check if company exists
            $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
            $stmt->execute([$company_name]);
            if ($stmt->fetch()) {
                throw new Exception("Company name is already registered.");
            }

            // 3. Create company
            $stmt = $pdo->prepare("INSERT INTO companies (name, email, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$company_name, $email, $phone, $address]);
            $company_id = $pdo->lastInsertId();

            // 4. Resolve company role ID
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'company'");
            $stmt->execute();
            $role_id = $stmt->fetchColumn();
            if (!$role_id) {
                throw new Exception("B2B Company role is not configured. Please contact administrator.");
            }

            // 5. Create user linked to company
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role_id, company_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$company_name, $email, $hash, $role_id, $company_id]);
            $user_id = $pdo->lastInsertId();

            // Log audit trail
            $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, description) VALUES (?, 'CREATE', 'admin', ?, ?)");
            $stmt->execute([$user_id, $user_id, "Shipping company {$company_name} registered."]);

            $pdo->commit();

            // Log user in automatically
            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = 'company';
            $_SESSION['name'] = $company_name;

            header("Location: " . BASE_URL . "/dashboard.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    } else {
        $error = 'Please fill in all required fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PMS - Company Registration</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-body">
    <div class="login-card" style="max-width: 500px;">
        <div style="text-align: center; margin-bottom: 8px;">
            <i class="fa-solid fa-anchor" style="font-size: 2.5rem; color: var(--accent); filter: drop-shadow(0 0 8px var(--accent-glow));"></i>
        </div>
        <h1>Shipping Company Portal</h1>
        <p>Register your commercial shipping account</p>
 
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
 
        <form action="<?= BASE_URL ?>/signup.php" method="post">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label class="form-label" for="company_name">Company Name *</label>
                <input type="text" name="company_name" id="company_name" class="form-control" required placeholder="e.g. Ocean Blue Lines" value="<?= htmlspecialchars($_POST['company_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="email">Commercial Email *</label>
                <input type="email" name="email" id="email" class="form-control" required placeholder="e.g. logistics@oceanblue.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Account Password *</label>
                <input type="password" name="password" id="password" class="form-control" required placeholder="••••••••" minlength="6">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="phone">Phone / Contact</label>
                    <input type="text" name="phone" id="phone" class="form-control" placeholder="+12345678" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="address">Headquarters Address</label>
                    <input type="text" name="address" id="address" class="form-control" placeholder="London Port Terminal" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem; margin-top: 10px;">
                <i class="fa-solid fa-user-plus"></i> Register Corporate Account
            </button>
            <p style="text-align: center; margin-top: 20px; font-size: 0.85rem; color: var(--text-muted);">
                Already have an account? <a href="<?= BASE_URL ?>/login.php">Log In here</a>
            </p>
        </form>
    </div>
</body>
</html>
