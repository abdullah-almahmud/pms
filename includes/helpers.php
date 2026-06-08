<?php
// includes/helpers.php

/**
 * Logs an action to the audit_log table.
 */
function audit_log_entry($pdo, $user_id, $action, $module, $record_id, $description) {
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, description) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $module, $record_id, $description]);
}

/**
 * Returns an HTML <span> with a CSS class based on status string.
 */
function status_badge($status) {
    $safeStatus = htmlspecialchars($status ?? '');
    return "<span class=\"badge badge-{$safeStatus}\">{$safeStatus}</span>";
}

/**
 * Returns a formatted datetime string: "12 Jun 2025, 14:30"
 */
function format_dt($datetime_string) {
    if (!$datetime_string) return '-';
    return date('d M Y, H:i', strtotime($datetime_string));
}

/**
 * Records a cost automatically into cost_records
 */
function record_cost($pdo, $company_id, $client_name, $invoice_type, $ref_id, $description, $amount) {
    // If client_name is empty but company_id is provided, try to fetch it
    if (empty($client_name) && $company_id) {
        $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $stmt->execute([$company_id]);
        $c = $stmt->fetch();
        if ($c) $client_name = $c['name'];
    }
    
    if (empty($client_name)) $client_name = 'Unknown Client';
    
    $stmt = $pdo->prepare("INSERT INTO cost_records (company_id, client_name, invoice_type, ref_id, description, amount) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$company_id, $client_name, $invoice_type, $ref_id, $description, $amount]);
}

/**
 * Propagates berth time changes to downstream records
 * Throws Exception on conflicts
 */
function propagate_berth_time($pdo, $allocation_id, $berth_id, $new_start, $new_end, $arrival_request_id = null) {
    // 1. Overlap Check
    // Exclude the current allocation if updating ($allocation_id != null)
    $q = "SELECT id FROM berth_allocations WHERE berth_id = ? AND docking_start < ? AND expected_departure > ?";
    $params = [$berth_id, $new_end, $new_start];
    if ($allocation_id) {
        $q .= " AND id != ?";
        $params[] = $allocation_id;
    }
    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        throw new Exception("Time conflicts with an existing allocation on this berth.");
    }

    $req_id = $arrival_request_id;
    $diff_seconds = 0;

    if ($allocation_id) {
        // Get existing to calculate delta
        $stmt = $pdo->prepare("SELECT arrival_request_id, docking_start, expected_departure FROM berth_allocations WHERE id = ?");
        $stmt->execute([$allocation_id]);
        $alloc = $stmt->fetch();
        if ($alloc) {
            $req_id = $alloc['arrival_request_id'];
            $old_end_time = strtotime($alloc['expected_departure']);
            $new_end_time = strtotime($new_end);
            $diff_seconds = $new_end_time - $old_end_time;
        }
    }

    if (!$req_id) return;

    // 2. Update ETA on arrival request
    $pdo->prepare("UPDATE arrival_requests SET eta = ? WHERE id = ?")->execute([$new_start, $req_id]);

    // 3. Shift downstream transport assignments
    if ($diff_seconds != 0) {
        $pdo->prepare("
            UPDATE transport_assignments ta
            JOIN containers c ON ta.container_id = c.id
            JOIN vessels v ON c.vessel_id = v.id
            JOIN arrival_requests ar ON ar.vessel_id = v.id
            SET ta.scheduled_pickup = DATE_ADD(ta.scheduled_pickup, INTERVAL ? SECOND)
            WHERE ar.id = ? AND ta.status = 'scheduled'
        ")->execute([$diff_seconds, $req_id]);
    }

    // 4. Validate downstream task assignments
    $t_stmt = $pdo->prepare("
        SELECT ta.id, s.shift_date 
        FROM task_assignments ta
        JOIN shifts s ON ta.shift_id = s.id
        WHERE ta.ref_type = 'vessel' AND ta.ref_id = ?
    ");
    $t_stmt->execute([$req_id]);
    $tasks = $t_stmt->fetchAll();
    
    $start_date = date('Y-m-d', strtotime($new_start));
    $end_date = date('Y-m-d', strtotime($new_end));
    
    foreach ($tasks as $t) {
        if ($t['shift_date'] < $start_date || $t['shift_date'] > $end_date) {
            throw new Exception("Task conflict: Existing worker tasks assigned to shift on {$t['shift_date']} which is now outside the new docking schedule ({$start_date} to {$end_date}). Please clear or reassign those tasks first.");
        }
    }
}

/**
 * Inserts an employee flag row. Call after any action that could
 * indicate poor performance (e.g. after saving an inspection result).
 * Uses INSERT IGNORE so duplicate active flags are silently skipped.
 */
function flag_employee(PDO $pdo, int $user_id, string $flag_type,
                       float $threshold, float $actual): void {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO employee_flags
            (user_id, flag_type, threshold_value, actual_value)
        VALUES (:uid, :type, :threshold, :actual)
    ");
    $stmt->execute([
        ':uid'       => $user_id,
        ':type'      => $flag_type,
        ':threshold' => $threshold,
        ':actual'    => $actual,
    ]);
}

/**
 * Marks a task as started. Sets started_at = NOW(), status = in_progress.
 * Call when a user clicks \"Start Task\" on their task list.
 */
function task_start(PDO $pdo, int $task_id): void {
    $stmt = $pdo->prepare("
        UPDATE task_assignments
        SET    status     = 'in_progress',
               started_at = NOW()
        WHERE  id         = :id
          AND  status     = 'assigned'
    ");
    $stmt->execute([':id' => $task_id]);
}

/**
 * Marks a task as done. Sets completed_at = NOW(), status = done.
 * Call when a user clicks \"Complete Task\".
 */
function task_complete(PDO $pdo, int $task_id): void {
    $stmt = $pdo->prepare("
        UPDATE task_assignments
        SET    status       = 'done',
               completed_at = NOW()
        WHERE  id           = :id
          AND  status       = 'in_progress'
    ");
    $stmt->execute([':id' => $task_id]);
}
