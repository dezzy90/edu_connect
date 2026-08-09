<?php
try {
    $host = '127.0.0.1';
    $port = '3306';
    $username = 'root';
    $password = ''; // Update this if you have a password

    // Connect to MySQL server (without specifying database)
    $pdo = new PDO("mysql:host=$host;port=$port", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS rod_connect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    echo "Database 'rod_connect' created successfully!\n";
    
} catch (PDOException $e) {
    echo "Error creating database: " . $e->getMessage() . "\n";
    echo "Please make sure MySQL is running and the credentials are correct.\n";
}