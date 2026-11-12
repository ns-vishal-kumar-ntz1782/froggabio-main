#!/usr/bin/env php
<?php
/**
 * FroggaBio: migrate data from an old Magento 2.4.6 - 2.4.8 database into this Magento 2.4.9 install.
 *
 *   php dev/tools/data-migration/migrate.php --check
 *   php dev/tools/data-migration/migrate.php --purge
 *   php dev/tools/data-migration/migrate.php --steps=products,urlrewrites
 *
 * See README.md in this directory.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/Logger.php';
foreach (glob(__DIR__ . '/src/Step/*.php') as $file) {
    require $file;
}
require __DIR__ . '/src/Migrator.php';

$help = <<<TXT
Usage: php dev/tools/data-migration/migrate.php [options]

  --check              Read-only pre-flight report (store mapping, attributes, schema diffs, payment methods, rules)
  --steps=a,b          Steps to run (default: all). Available:
                       attributes, categories, products, customers, orders, cms, salesrules, urlrewrites, finalize
  --skip=a,b           Steps to leave out
  --purge              Empty the target tables of the selected steps before importing (recommended for the first run)
  --yes                Do not ask for confirmation
  --config=FILE        Config file (default: config.php next to this script)
  --batch-size=N       Rows per batch (default 1000)
  -v                   Verbose (debug) output

TXT;

$opts = getopt('vh', ['check', 'steps:', 'skip:', 'purge', 'yes', 'config:', 'batch-size:', 'help']);
if (isset($opts['h']) || isset($opts['help'])) {
    echo $help;
    exit(0);
}

$magentoRoot = dirname(__DIR__, 3);
$configFile = $opts['config'] ?? __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Config file $configFile not found. Copy config.php.dist to config.php first.\n");
    exit(1);
}
$cfg = require $configFile;

if (empty($cfg['target'])) {
    $env = @include $magentoRoot . '/app/etc/env.php';
    $c = $env['db']['connection']['default'] ?? null;
    if (!$c) {
        fwrite(STDERR, "No 'target' in config and app/etc/env.php has no DB connection.\n");
        exit(1);
    }
    $cfg['target'] = [
        'host' => $c['host'],
        'dbname' => $c['dbname'],
        'username' => $c['username'],
        'password' => $c['password'] ?? '',
        'table_prefix' => $env['db']['table_prefix'] ?? '',
    ];
}

$steps = Migrator::STEPS;
if (!empty($opts['steps'])) {
    $steps = array_map('trim', explode(',', $opts['steps']));
    $unknown = array_diff($steps, Migrator::STEPS);
    if ($unknown) {
        fwrite(STDERR, 'Unknown step(s): ' . implode(', ', $unknown) . "\n");
        exit(1);
    }
    // keep canonical order
    $steps = array_values(array_intersect(Migrator::STEPS, $steps));
}
if (!empty($opts['skip'])) {
    $steps = array_values(array_diff($steps, array_map('trim', explode(',', $opts['skip']))));
}
if (in_array('orders', $steps, true) && !in_array('finalize', $steps, true)) {
    $steps[] = 'finalize'; // sequences must always be advanced after importing orders
}

$purge = isset($opts['purge']);
$check = isset($opts['check']);
$logger = new Logger($magentoRoot . '/var/log/data-migration-' . date('Ymd-His') . '.log', isset($opts['v']));

try {
    @ini_set('memory_limit', '4G');
    $migrator = new Migrator($cfg, $logger, $magentoRoot, $purge, max(100, (int)($opts['batch-size'] ?? 1000)));

    if ($check) {
        $migrator->check();
        exit(0);
    }

    $t = $cfg['target'];
    echo "\nTarget database : {$t['dbname']} @ " . ($t['host'] ?? 'localhost') . "\n";
    echo 'Steps           : ' . implode(', ', $steps) . "\n";
    echo 'Mode            : ' . ($purge ? 'PURGE target tables of these steps, then import' : 'upsert (keep existing target rows)') . "\n";
    echo "Take a backup of the target database first.\n";
    if (!isset($opts['yes'])) {
        echo 'Type "yes" to continue: ';
        if (trim((string)fgets(STDIN)) !== 'yes') {
            echo "Aborted.\n";
            exit(1);
        }
    }
    $migrator->run($steps);
} catch (\Throwable $e) {
    $logger->warn('FAILED: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    if (isset($opts['v'])) {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
