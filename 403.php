<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PMS - Access Denied</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="error-body">
    <div class="error-card">
        <h1>403</h1>
        <h2 style="color: var(--text-main); font-size: 1.5rem;">Access Denied</h2>
        <p style="color: var(--text-muted); margin-bottom: 24px;">You do not have permission to view this page.</p>
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary"><i class="fa-solid fa-arrow-left"></i> Return to Dashboard</a>
    </div>
</body>
</html>
