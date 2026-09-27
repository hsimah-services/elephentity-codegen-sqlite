<?php

declare(strict_types=1);

// Optional workspace acceptance test: compiler -> orchestrator -> all three builders.
$workspace = $argv[1] ?? dirname(__DIR__, 2);
$project = sys_get_temp_dir() . '/eleph-pipeline-' . bin2hex(random_bytes(8));
mkdir($project . '/spec/entities', 0700, true);
$example = dirname(__DIR__) . '/examples/standalone';
copy($example . '/spec/project.yml', $project . '/spec/project.yml');
foreach (glob($example . '/spec/entities/*.yml') as $file) copy($file, $project . '/spec/entities/' . basename($file));
$config = json_decode(file_get_contents($example . '/eleph.json'), true, flags: JSON_THROW_ON_ERROR);
$config['codegen'] = $workspace . '/elephentity-codegen/bin/eleph-codegen';
foreach (['php', 'sqlite', 'graphql-php'] as $target) $config['targets'][$target]['builder'] = $workspace . '/elephentity-codegen-' . $target . '/bin/eleph-gen-' . $target;
file_put_contents($project . '/eleph.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$base = [PHP_BINARY, '-d', 'auto_prepend_file=' . $workspace . '/elephentity-codegen-graphql-php/tests/vendor/autoload.php', $workspace . '/elephentity/packages/cli/bin/eleph'];
try {
    foreach ([['generate'], ['generate', '--check'], ['check']] as $arguments) {
        $command = [...$base, ...$arguments, '--project', $project];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        fclose($pipes[0]);
        if (proc_close($process) !== 0) throw new RuntimeException('Pipeline command failed: ' . implode(' ', $arguments));
    }
    require $workspace . '/elephentity-codegen-sqlite/tests/vendor/autoload.php';
    $install = require $project . '/generated/sqlite/install.php';
    $database = new PDO('sqlite::memory:'); $install($database, 'app_');
    $manifest = (require $project . '/generated/sqlite/storage-manifest.php')->withPrefix('app_');
    if (!isset($manifest->tables['Book']) || $manifest->tables['Book']->name !== 'app_demo_book') throw new RuntimeException('Compiler prefix or manifest mismatch');
    $database->exec("INSERT INTO app_demo_author (name) VALUES ('Ada')");
    $database->exec("INSERT INTO app_demo_book (title, author_id) VALUES ('Generated', 1)");
    if ($database->query('PRAGMA foreign_key_check')->fetchAll() !== []) throw new RuntimeException('Broken pipeline foreign key');
    echo "PASS: compiler discovery, generation, signed drift check, entity conformance and SQLite installation\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($project);
}
