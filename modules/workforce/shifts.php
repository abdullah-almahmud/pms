<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'finance']);

// Process POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_shift') {
        $shift_date = $_POST['shift_date'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $zone = $_POST['zone'] ?? '';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO shifts (shift_date, start_time, end_time, zone, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$shift_date, $start_time, $end_time, $zone, $_SESSION['user_id']]);
            $shift_id = $pdo->lastInsertId();
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'workforce', $shift_id, "Created shift for {$shift_date} in {$zone}");
            $_SESSION['flash'] = "Shift created successfully.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Failed to create shift: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/workforce/shifts.php");
        exit;
    }
    
    if ($action === 'assign_worker') {
        $shift_id = $_POST['shift_id'] ?? '';
        $worker_ids = $_POST['worker_ids'] ?? [];
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT IGNORE INTO shift_assignments (shift_id, worker_id, assigned_by) VALUES (?, ?, ?)");
            $count = 0;
            foreach ($worker_ids as $worker_id) {
                $stmt->execute([$shift_id, $worker_id, $_SESSION['user_id']]);
                if ($stmt->rowCount() > 0) $count++;
            }
            $pdo->commit();
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'CREATE', 'workforce', $shift_id, "Assigned {$count} workers to shift {$shift_id}");
            $_SESSION['flash'] = "Assigned {$count} workers successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to assign workers: " . $e->getMessage();
        }
        header("Location: " . BASE_URL . "/modules/workforce/shifts.php");
        exit;
    }
    
    if ($action === 'remove_worker') {
        $assignment_id = $_POST['assignment_id'] ?? '';
        
        try {
            // Get details for audit log before deleting
            $stmt = $pdo->prepare("SELECT shift_id, worker_id FROM shift_assignments WHERE id = ?");
            $stmt->execute([$assignment_id]);
            $assignment = $stmt->fetch();
            
            if ($assignment) {
                $del = $pdo->prepare("DELETE FROM shift_assignments WHERE id = ?");
                $del->execute([$assignment_id]);
                
                audit_log_entry($pdo, $_SESSION['user_id'], 'DELETE', 'workforce', $assignment['shift_id'], "Removed worker {$assignment['worker_id']} from shift {$assignment['shift_id']}");
                $_SESSION['flash'] = "Worker removed from shift.";
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Failed to remove worker.";
        }
        header("Location: " . BASE_URL . "/modules/workforce/shifts.php");
        exit;
    }
    
    if ($action === 'update_shift_status') {
        $shift_id = $_POST['shift_id'] ?? '';
        $new_status = $_POST['status'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE shifts SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $shift_id]);
            
            audit_log_entry($pdo, $_SESSION['user_id'], 'UPDATE', 'workforce', $shift_id, "Updated shift {$shift_id} status to {$new_status}");
            $_SESSION['flash'] = "Shift status updated to " . htmlspecialchars($new_status) . ".";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = "Failed to update shift status.";
        }
        header("Location: " . BASE_URL . "/modules/workforce/shifts.php");
        exit;
    }
}

// GET Data
$date_filter = $_GET['date'] ?? '';
$zone_filter = $_GET['zone'] ?? 'all';

$query = "
    SELECT s.*, u.name as creator_name,
           (SELECT COUNT(*) FROM shift_assignments sa WHERE sa.shift_id = s.id) as worker_count
    FROM shifts s
    JOIN users u ON s.created_by = u.id
";
$where = [];
$params = [];

if (!empty($date_filter)) {
    $where[] = "s.shift_date = ?";
    $params[] = $date_filter;
}
if ($zone_filter !== 'all') {
    $where[] = "s.zone = ?";
    $params[] = $zone_filter;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}
$query .= " ORDER BY s.shift_date DESC, s.start_time ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$shifts = $stmt->fetchAll();

// Get unique zones for filter dropdown
$zones = $pdo->query("SELECT DISTINCT zone FROM shifts WHERE zone IS NOT NULL AND zone != '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);

// Fetch all shift assignments grouped by shift
$assignments_by_shift = [];
$asm_stmt = $pdo->query("
    SELECT sa.id, sa.shift_id, w.name, w.job_role, w.id as worker_id
    FROM shift_assignments sa
    JOIN workers w ON sa.worker_id = w.id
    ORDER BY w.name ASC
");
foreach ($asm_stmt->fetchAll() as $row) {
    $assignments_by_shift[$row['shift_id']][] = $row;
}

// Fetch active workers for assignment dropdown, grouped by role
$workers = $pdo->query("SELECT id, name, job_role FROM workers WHERE status = 'active' ORDER BY job_role ASC, name ASC")->fetchAll();
$workers_by_role = [];
foreach ($workers as $w) {
    $workers_by_role[$w['job_role']][] = $w;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PMS - Workforce Shifts</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); padding: 32px; border-radius: var(--radius-lg); width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; border-bottom: 1px solid var(--border); padding-bottom: 16px; }
        .close-btn { cursor: pointer; font-size: 1.5rem; color: var(--text-muted); }
        .worker-tag { display: inline-flex; align-items: center; background: rgba(255,255,255,0.08); color: var(--text-secondary); padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; margin: 2px; }
        .worker-tag .remove { margin-left: 6px; color: var(--danger-text); cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../../includes/nav.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h1 class="page-title">Shift Management</h1>
            <button class="btn btn-primary" onclick="document.getElementById('shiftModal').classList.add('active')">
                <i class="fa-solid fa-plus"></i> Create Shift
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
            <form method="get" action="<?= BASE_URL ?>/modules/workforce/shifts.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date_filter) ?>" onchange="this.form.submit()">
                </div>
                <div style="flex: 1;">
                    <label class="form-label">Zone</label>
                    <select name="zone" class="form-control" onchange="this.form.submit()">
                        <option value="all" <?= $zone_filter === 'all' ? 'selected' : '' ?>>All Zones</option>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?= htmlspecialchars($z) ?>" <?= $zone_filter === $z ? 'selected' : '' ?>><?= htmlspecialchars($z) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <a href="<?= BASE_URL ?>/modules/workforce/shifts.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>

        <!-- TABLE -->
        <div class="table-container">
            <div class="table-header">
                <h2>Roster</h2>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Zone</th>
                        <th>Status</th>
                        <th>Workers Assigned</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($shifts)): ?>
                        <tr><td colspan="4" style="text-align: center;">No shifts found</td></tr>
                    <?php else: ?>
                        <?php foreach ($shifts as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= date('D, d M Y', strtotime($s['shift_date'])) ?></strong><br>
                                    <small><?= date('H:i', strtotime($s['start_time'])) ?> - <?= date('H:i', strtotime($s['end_time'])) ?></small>
                                </td>
                                <td><?= htmlspecialchars($s['zone']) ?></td>
                                <td><?= status_badge($s['status']) ?></td>
                                <td>
                                    <?php if (isset($assignments_by_shift[$s['id']])): ?>
                                        <div style="margin-bottom: 8px;">
                                        <?php foreach ($assignments_by_shift[$s['id']] as $w): ?>
                                            <div class="worker-tag">
                                                <?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars(ucwords(str_replace('_', ' ', $w['job_role']))) ?>)
                                                <form method="post" style="display:inline;" data-confirm="Remove worker from shift?">
                                                    <input type="hidden" name="action" value="remove_worker">
                                                    <input type="hidden" name="assignment_id" value="<?= $w['id'] ?>">
                                                    <button type="submit" style="background:none; border:none; padding:0; font:inherit;" class="remove">&times;</button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">0 workers</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-secondary" onclick="openAssignModal(<?= $s['id'] ?>, '<?= date('d M Y', strtotime($s['shift_date'])) ?> - <?= htmlspecialchars($s['zone'], ENT_QUOTES) ?>')">
                                        Assign
                                    </button>
                                    <?php if ($s['status'] === 'scheduled'): ?>
                                        <form method="post" style="display:inline;" data-confirm="Start this shift?">
                                            <input type="hidden" name="action" value="update_shift_status">
                                            <input type="hidden" name="shift_id" value="<?= $s['id'] ?>">
                                            <input type="hidden" name="status" value="in_progress">
                                            <button type="submit" class="btn btn-sm btn-primary">Start</button>
                                        </form>
                                    <?php elseif ($s['status'] === 'in_progress'): ?>
                                        <form method="post" style="display:inline;" data-confirm="Complete this shift?">
                                            <input type="hidden" name="action" value="update_shift_status">
                                            <input type="hidden" name="shift_id" value="<?= $s['id'] ?>">
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="btn btn-sm btn-success">Complete</button>
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

    <!-- CREATE SHIFT MODAL -->
    <div id="shiftModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create Shift</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/workforce/shifts.php">
                <input type="hidden" name="action" value="create_shift">
                
                <div class="form-group">
                    <label class="form-label">Date *</label>
                    <input type="date" name="shift_date" class="form-control" required>
                </div>

                <div style="display: flex; gap: 16px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Start Time *</label>
                        <input type="time" name="start_time" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">End Time *</label>
                        <input type="time" name="end_time" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Zone/Area *</label>
                    <input type="text" name="zone" class="form-control" placeholder="e.g. Warehouse A, Dock 1" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">Create Shift</button>
            </form>
        </div>
    </div>

    <!-- ASSIGN WORKER MODAL -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Assign Worker</h2>
                <span class="close-btn" onclick="this.closest('.modal').classList.remove('active')">&times;</span>
            </div>
            <p style="margin-bottom: 16px; font-weight: bold; color: var(--primary);" id="assignShiftDisplay"></p>
            <!-- FORM -->
            <form method="post" action="<?= BASE_URL ?>/modules/workforce/shifts.php" onsubmit="return confirmShiftAssignment(this)">
                <input type="hidden" name="action" value="assign_worker">
                <input type="hidden" name="shift_id" id="assignShiftId">
                <input type="hidden" id="hiddenShiftZone" value="">
                
                <div class="form-group">
                    <label class="form-label">Select Workers (Hold Ctrl/Cmd to multi-select) *</label>
                    <select name="worker_ids[]" id="workerSelect" class="form-control" multiple required style="height: 200px;">
                        <?php foreach ($workers_by_role as $role => $role_workers): ?>
                            <optgroup label="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $role))) ?>">
                                <?php foreach ($role_workers as $w): ?>
                                    <option value="<?= $w['id'] ?>" data-role="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $w['job_role']))) ?>"><?= htmlspecialchars($w['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">Assign to Shift</button>
            </form>
        </div>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
    <script>
        function openAssignModal(shiftId, displayStr, zone) {
            document.getElementById('assignShiftId').value = shiftId;
            document.getElementById('assignShiftDisplay').innerText = displayStr;
            document.getElementById('hiddenShiftZone').value = zone;
            document.getElementById('assignModal').classList.add('active');
        }

        function confirmShiftAssignment(form) {
            const select = document.getElementById('workerSelect');
            const selectedOptions = Array.from(select.selectedOptions);
            const count = selectedOptions.length;
            
            if (count === 0) return false;
            
            // Get unique roles
            const roles = new Set(selectedOptions.map(opt => opt.getAttribute('data-role')));
            const rolesArray = Array.from(roles);
            let roleStr = rolesArray.join('s and ') + 's'; // simple pluralization
            if (rolesArray.length === 1) {
                roleStr = rolesArray[0] + 's';
            } else if (rolesArray.length > 1) {
                roleStr = rolesArray.length + ' different roles';
            }
            
            const zone = document.getElementById('hiddenShiftZone').value;
            const confirmMsg = `Assign ${count} workers (${roleStr}) to ${zone}?`;
            return confirm(confirmMsg);
        }
    </script>
</body>
</html>

