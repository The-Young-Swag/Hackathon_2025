<?php
// Config/db_connection.php
// Secure database and email configuration for TAU Feedback System

// Database configuration
$host = 'localhost';
$dbname = 'hackathon';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database connection failed. Please try again later.']);
    exit;
}

// Load email configuration
$config_file = __DIR__ . '/config.ini';
if (!file_exists($config_file)) {
    error_log("Config file missing: $config_file");
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Configuration error: Missing config.ini']);
    exit;
}

$config = parse_ini_file($config_file, true);
if ($config === false || !isset($config['gmail'])) {
    error_log("Invalid or missing [gmail] section in config.ini");
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Configuration error: Invalid config.ini']);
    exit;
}

// Define email constants
define('GMAIL_USERNAME', $config['gmail']['username'] ?? '');
define('GMAIL_APP_PASSWORD', $config['gmail']['app_password'] ?? '');
define('GMAIL_FROM_NAME', $config['gmail']['from_name'] ?? 'TAU Feedback Team');
define('GMAIL_REPLY_TO_EMAIL', $config['gmail']['reply_to_email'] ?? GMAIL_USERNAME);
define('GMAIL_REPLY_TO_NAME', $config['gmail']['reply_to_name'] ?? GMAIL_FROM_NAME);

if (empty(GMAIL_USERNAME) || empty(GMAIL_APP_PASSWORD)) {
    error_log("Missing Gmail credentials in config.ini");
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Configuration error: Gmail credentials missing']);
    exit;
}
