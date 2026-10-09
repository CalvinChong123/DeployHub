<?php

// Optional Laragon bootstrap. Creates ONLY this application's database.
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '');
$pdo->exec('CREATE DATABASE IF NOT EXISTS deployhub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "Local deployhub database is available.\n";
