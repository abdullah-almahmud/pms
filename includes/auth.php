<?php
// includes/auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Requires the user to be logged in.
 * If not logged in, redirects to login.php and exits.
 */
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "/login.php");
        exit;
    }
}

/**
 * Requires the logged-in user to have one of the allowed roles.
 * If not, redirects to 403.php and exits.
 * 
 * @param array $allowed Array of allowed role names (e.g., ['admin', 'harbor_master'])
 */
function require_role(array $allowed) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed)) {
        header("Location: " . BASE_URL . "/403.php");
        exit;
    }
}

/**
 * Returns the current CSRF token from the session.
 */
function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Renders a hidden HTML input field containing the CSRF token.
 */
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validates the CSRF token on POST requests.
 * Dies with 403 error on failure.
 */
function validate_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
            header("HTTP/1.1 403 Forbidden");
            die("Error: CSRF token verification failed.");
        }
    }
}
