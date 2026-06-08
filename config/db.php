<?php
// config/db.php

if (!defined('BASE_URL')) {
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
    $dir = str_replace('\\', '/', dirname(__DIR__));
    $basePath = str_replace($docRoot, '', $dir);
    $basePath = '/' . trim($basePath, '/');
    if ($basePath === '/') {
        $basePath = '';
    }
    define('BASE_URL', $basePath);
}

$host = 'localhost';
$port = 3307;
$dbname = 'pms';
$username = 'root';
$password = ''; // Set your database password here if necessary

try {
    $pdo = new PDO(
    "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
    $username,
    $password
);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Optional: Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

