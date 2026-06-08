<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Validate CSRF token on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/dashboard.php");
    exit;
}

$error = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $stmt = $pdo->prepare("
            SELECT u.*, r.name as role_slug 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ? AND u.status = 'active'
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role_slug'];
            $_SESSION['name'] = $user['name'];
            header("Location: " . BASE_URL . "/dashboard.php");
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please enter both email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PMS - Login</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-body">
    <div class="login-card">
        <div style="text-align: center; margin-bottom: 8px;">
            <i class="fa-solid fa-anchor" style="font-size: 2.5rem; color: var(--accent);"></i>
        </div>
        <h1>Port Management System</h1>
        <p>Sign in to your account</p>

        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="<?= BASE_URL ?>/login.php" method="post">
            <?php csrf_field(); ?>
            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input type="email" name="email" id="email" class="form-control" required placeholder="admin@pms.local" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem;">
                <i class="fa-solid fa-arrow-right-to-bracket"></i> Log In
            </button>
        </form>
        <p style="text-align: center; margin-top: 20px; font-size: 0.85rem; color: var(--text-muted);">
            Are you a shipping line? <a href="<?= BASE_URL ?>/signup.php">Register Corporate Account here</a>
        </p>
    </div>
</body>
</html>
