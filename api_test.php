<?php
header('Content-Type: application/json; charset=UTF-8');
$out = [];

try {
    $out['step'] = 'before_config';
    require_once __DIR__ . '/config.php';
    $out['config_loaded'] = true;
    $out['DB_PATH'] = defined('DB_PATH') ? DB_PATH : 'NOT_DEFINED';
    $out['db_file_exists'] = is_file($out['DB_PATH']);
    $out['db_file_readable'] = is_readable($out['DB_PATH']);
    $out['dir_writable'] = is_writable(__DIR__);

    $out['step'] = 'pdo_connect';
    $db = new PDO('sqlite:' . $out['DB_PATH']);
    $out['pdo_ok'] = true;

    $out['step'] = 'schema_check';
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $out['tables'] = $tables;

} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
