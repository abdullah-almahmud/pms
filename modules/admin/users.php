<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_user') {
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $role_id = $_POST['role_id'] ?? '';
        
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $hash, $role_id]);
            $new_user_id = $pdo->lastInsertId();
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'admin', $new_user_id, "Created new user {$email}");
            $_SESSION['flash'] = "User {$email} created successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['flash_error'] = "Email address already exists.";
            } else {
                $_SESSION['flash_error'] = "Failed to create user: " . $e->getMessage();
            }
        }
        header("Location: " . BASE_URL . "/modules/admin/users.php");
        exit;
    }
    
    if ($action === 'toggle_status') {
        $user_id = $_POST['user_id'] ?? '';
        $new_status = $_POST['status'] ?? ''; // active or inactive
        
        // Prevent disabling oneself
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['flash_error'] = "You cannot disable your own account.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $user_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'UPDATE', 'admin', $user_id, "Changed user {$user_id} status to {$new_status}");
                $_SESSION['flash'] = "User status updated.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Failed to update user status.";
            }
        }
        header("Location: " . BASE_URL . "/modules/admin/users.php");
        exit;
    }
}

// GET Data
$users = $pdo->query("
    SELECT u.*, r.name as role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY u.created_at DESC
")->fetchAll();

$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - User Management</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">User Management</h1>
            <button class="btn btn-primary" onclick="document.getElementById('userModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Create User
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
                <h2>System Users</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge badge-neutral"><?= htmlspecialchars(ucfirst($u['role_name'])) ?></span></td>
                            <td><?= status_badge($u['status']) ?></td>
                            <td><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="post" style="display:inline;" data-confirm="Are you sure you want to change this user's status?">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <?php if ($u['status'] === 'active'): ?>
                                            <input type="hidden" name="status" value="inactive">
                                            <button type="submit" class="btn btn-sm btn-danger">Disable</button>
                                        <?php else: ?>
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="btn btn-sm btn-success">Enable</button>
                                        <?php endif; ?>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-neutral">You</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- CREATE USER MODAL -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create System User</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/admin/users.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>

                <div class="form-group">
                    <label class="form-label">System Role *</label>
                    <select name="role_id" class="form-control" required>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars(ucfirst($r['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Create User</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>

