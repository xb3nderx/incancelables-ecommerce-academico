<?php

$host = '127.0.0.1';
$port = '3306';
$dbname = 'incancelables_ecommerce';
$username = 'root';
$password = '';

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

$pdo = new PDO($dsn, $username, $password);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);