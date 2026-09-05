<?php
if ($argc < 4) { fwrite(STDERR, "Usage: php scripts/create_owner.php email name password\n"); exit(1); }
$config = require __DIR__ . '/../config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role,is_active) VALUES (?,?,?,?,1)');
$stmt->execute([$argv[2], strtolower(trim($argv[1])), password_hash($argv[3], PASSWORD_DEFAULT), 'OWNER']);
echo "Owner created.\n";
