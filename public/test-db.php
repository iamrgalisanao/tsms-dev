<?php
$host = '127.0.0.1';
$db   = 'tsms_dev';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=3306";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     echo "Connecting to $dsn...\n";
     $pdo = new PDO($dsn, $user, $pass, $options);
     echo "Connected successfully!\n";
     $stmt = $pdo->query('SELECT COUNT(*) as count FROM users');
     $row = $stmt->fetch();
     echo "User count: " . $row['count'] . "\n";
} catch (\PDOException $e) {
     echo "Connection failed: " . $e->getMessage() . "\n";
}
