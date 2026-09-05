<?php
$configFile = __DIR__ . '/../config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/../config.example.php';
require __DIR__ . '/../api/lib.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = db();
$rows = $pdo->query('SELECT id, title, body FROM `BlogPost`')->fetchAll();
$changed = 0;

if (!$dryRun) $pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE `BlogPost` SET body = ?, updatedAt = UTC_TIMESTAMP(3) WHERE id = ?');
    foreach ($rows as $row) {
        $cleanBody = sanitize_html((string) $row['body']);
        if ($cleanBody === (string) $row['body']) continue;
        $changed++;
        printf("%s %s\n", $dryRun ? 'Would clean' : 'Cleaning', $row['title']);
        if (!$dryRun) $update->execute([$cleanBody, $row['id']]);
    }
    if (!$dryRun) $pdo->commit();
} catch (Throwable $error) {
    if (!$dryRun && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

printf("%d post(s) %s.\n", $changed, $dryRun ? 'would be cleaned' : 'cleaned');
