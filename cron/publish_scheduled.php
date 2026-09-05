<?php
$config = require __DIR__ . '/../config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE blog_posts SET status='PUBLISHED', published_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE status='SCHEDULED' AND scheduled_at IS NOT NULL AND scheduled_at <= UTC_TIMESTAMP()");
