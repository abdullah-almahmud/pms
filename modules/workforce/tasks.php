<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'finance']); // finance encompasses workforce in this project

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_task') {
        $worker_id = $_POST['worker_id'] ?? '';
        $shift_id = !empty($_POST['shift_id']) ? $_POST['shift_id'] : null;
        $task_type = $_POST['task_type'] ?? '';
        $ref_type = $_POST['ref_type'] ?? '';
        $ref_id = $_POST['ref_id'] ?? '';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO task_assignments (worker_id, shift_id, task_type, ref_type, ref_id, assigned_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$worker_id, $shift_id, $task_type, $ref_type, $ref_id, $_SESSION['user_id']]);
            $task_id = $pdo->lastInsertId();
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'workforce', $task_id, "Assigned {$task_type} task to worker {$worker_id}");
            $_SESSION['flash'] = "Task assigned successfully.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Failed to assign task: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/workforce/tasks.php");
        exit;
    }
    
    if ($action === 'update_status') {
        $task_id = $_POST['task_id'] ?? '';
        $new_status = $_POST['status'] ?? '';
        
        try {
            $pdo->beginTransaction();
            if ($new_status === 'in_progress') {
                task_start($pdo, $task_id);
            } elseif ($new_status === 'done') {
                task_complete($pdo, $task_id);
                
                // Find company based on ref_type and ref_id
                $t_stmt = $pdo->prepare("SELECT ref_type, ref_id FROM task_assignments WHERE id = ?");
                $t_stmt->execute([$task_id]);
                $t = $t_stmt->fetch();
                
                $company_id = null;
                $client_name = null;
                
                if ($t && $t['ref_type'] === 'vessel') {
                    $v = $pdo->prepare("SELECT company_id, company_name FROM vessels JOIN arrival_requests ar ON ar.vessel_id = vessels.id WHERE ar.id = ?");
                    $v->execute([$t['ref_id']]);
                    if ($v_data = $v->fetch()) {
                        $company_id = $v_data['company_id'];
                        $client_name = $v_data['company_name'];
                    }
                } elseif ($t && $t['ref_type'] === 'container') {
                    $v = $pdo->prepare("SELECT company_id, company_name FROM vessels JOIN containers c ON c.vessel_id = vessels.id WHERE c.id = ?");
                    $v->execute([$t['ref_id']]);
                    if ($v_data = $v->fetch()) {
                        $company_id = $v_data['company_id'];
                        $client_name = $v_data['company_name'];
                    }
                }
                
                $cost = 50.00; // Flat labor fee
                record_cost($pdo, $company_id, $client_name, 'other', $task_id, "Labor service charge for task {$task_id}", $cost);
            }
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'UPDATE', 'workforce', $task_id, "Updated task {$task_id} status to {$new_status}");
            $_SESSION['flash'] = "Task status updated.";
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to update task.";
        }
        header("Location: " . BASE_URL . "/modules/workforce/tasks.php");
        exit;
    }
}

// GET Data
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['task_type'] ?? 'all';

$query = "
    SELECT ta.*, w.name as worker_name, w.job_role, s.shift_date, s.zone
    FROM task_assignments ta
    JOIN workers w ON ta.worker_id = w.id
    LEFT JOIN shifts s ON ta.shift_id = s.id
";
$where = [];
$params = [];

if ($status_filter !== 'all') {
    $where[] = "ta.status = ?";
    $params[] = $status_filter;
}
if ($type_filter !== 'all') {
    $where[] = "ta.task_type = ?";
    $params[] = $type_filter;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}
$query .= " ORDER BY ta.assigned_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Workers & Shifts for form
$workers = $pdo->query("
    SELECT w.id, w.name, w.job_role, 
           (SELECT COUNT(*) FROM task_assignments ta WHERE ta.worker_id = w.id AND ta.status IN ('assigned', 'in_progress')) as active_tasks
    FROM workers w 
    WHERE w.status = 'active' 
    ORDER BY w.name ASC
")->fetchAll();
// Only shifts from today onwards
$shifts = $pdo->query("SELECT id, shift_date, zone, start_time, end_time FROM shifts WHERE shift_date >= CURDATE() ORDER BY shift_date ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Task Assignments</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Task Assignments</h1>
            <button class="btn btn-primary" onclick="document.getElementById('taskModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Assign Task
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

        <!-- FILTERS -->
        <div class="form-card" style="padding: 16px; margin-bottom: 24px;">
            <form method="get" action="<?= BASE_URL ?>/modules/workforce/tasks.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="assigned" <?= $status_filter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                        <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="done" <?= $status_filter === 'done' ? 'selected' : '' ?>>Done</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Task Type</label>
                    <select name="task_type" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                        <option value="loading" <?= $type_filter === 'loading' ? 'selected' : '' ?>>Loading</option>
                        <option value="unloading" <?= $type_filter === 'unloading' ? 'selected' : '' ?>>Unloading</option>
                        <option value="crane_operation" <?= $type_filter === 'crane_operation' ? 'selected' : '' ?>>Crane Operation</option>
                        <option value="inspection_support" <?= $type_filter === 'inspection_support' ? 'selected' : '' ?>>Inspection Support</option>
                        <option value="driving" <?= $type_filter === 'driving' ? 'selected' : '' ?>>Driving</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Task List</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Worker</th>
                        <th>Task Type</th>
                        <th>Linked To</th>
                        <th>Shift</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr><td colspan="6" style="text-align: center;">No tasks found</td></tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($t['worker_name']) ?></strong><br><small><?= htmlspecialchars(ucwords(str_replace('_', ' ', $t['job_role']))) ?></small></td>
                                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $t['task_type']))) ?></td>
                                <td><small class="badge badge-neutral"><?= htmlspecialchars(strtoupper($t['ref_type'])) ?> #<?= $t['ref_id'] ?></small></td>
                                <td>
                                    <?php if ($t['shift_date']): ?>
                                        <?= date('d M', strtotime($t['shift_date'])) ?><br><small><?= htmlspecialchars($t['zone']) ?></small>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= status_badge($t['status']) ?></td>
                                <td>
                                    <?php if ($t['status'] === 'assigned'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="status" value="in_progress">
                                            <button type="submit" class="btn btn-sm btn-secondary">Start</button>
                                        </form>
                                    <?php elseif ($t['status'] === 'in_progress'): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                                            <input type="hidden" name="status" value="done">
                                            <button type="submit" class="btn btn-sm btn-primary">Complete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- ASSIGN TASK MODAL -->
    <div id="taskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Assign Task</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/workforce/tasks.php">
                <input type="hidden" name="action" value="create_task">
                
                <div class="form-group">
                    <label class="form-label">Worker *</label>
                    <select name="worker_id" id="workerSelect" class="form-control" required>
                        <option value="">-- Select Worker --</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?= $w['id'] ?>" data-role="<?= htmlspecialchars($w['job_role']) ?>" data-active-tasks="<?= $w['active_tasks'] ?>"><?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars(ucwords(str_replace('_', ' ', $w['job_role']))) ?>) - <?= $w['active_tasks'] ?> active tasks</option>
                        <?php endforeach; ?>
                    </select>
                    <small id="workerWarning" style="color: var(--danger-text); display: none; font-weight: bold; margin-top: 4px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Warning: This worker already has 3 or more active tasks!
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Shift (Optional)</label>
                    <select name="shift_id" class="form-control">
                        <option value="">-- No Shift Link --</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= date('d M', strtotime($s['shift_date'])) ?>: <?= date('H:i', strtotime($s['start_time'])) ?>-<?= date('H:i', strtotime($s['end_time'])) ?> (<?= htmlspecialchars($s['zone']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Task Type *</label>
                    <select name="task_type" class="form-control" required>
                        <option value="loading">Loading</option>
                        <option value="unloading">Unloading</option>
                        <option value="crane_operation">Crane Operation</option>
                        <option value="inspection_support">Inspection Support</option>
                        <option value="driving">Driving</option>
                    </select>
                </div>

                <div style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Reference Type *</label>
                        <select name="ref_type" class="form-control" required>
                            <option value="vessel">Vessel (Arrival)</option>
                            <option value="container">Container</option>
                            <option value="storage_location">Storage Slot</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Reference ID *</label>
                        <input type="number" name="ref_id" class="form-control" required placeholder="ID #">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Assign Task</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        document.getElementById('workerSelect').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value) {
                const activeTasks = parseInt(option.getAttribute('data-active-tasks'));
                const warningMsg = document.getElementById('workerWarning');
                if (activeTasks >= 3) {
                    warningMsg.style.display = 'block';
                } else {
                    warningMsg.style.display = 'none';
                }
                
                // Auto-infer task type from job_role
                const role = option.getAttribute('data-role');
                const taskTypeSelect = document.querySelector('select[name="task_type"]');
                let inferredType = '';
                if (role === 'crane_operator') inferredType = 'crane_operation';
                else if (role === 'truck_driver') inferredType = 'driving';
                else if (role === 'customs_inspector') inferredType = 'inspection_support';
                else if (role === 'dock_worker') inferredType = 'loading'; // or unloading
                
                if (inferredType) {
                    taskTypeSelect.value = inferredType;
                }
            } else {
                document.getElementById('workerWarning').style.display = 'none';
            }
        });
    </script>
</body>
</html>

