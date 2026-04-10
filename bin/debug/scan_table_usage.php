<?php
$tables = json_decode(file_get_contents('db_tables.json'), true);
$tableNames = array_keys($tables);

$usage = [];
foreach ($tableNames as $table) {
    $usage[$table] = [];
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src/'));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        foreach ($tableNames as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/', $content)) {
                $usage[$table][] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }
}

file_put_contents('db_usage.json', json_encode($usage, JSON_PRETTY_PRINT));
echo "Scan complete.\n";
