<?php
/**
 * FroggaBio data migration - core: id mappings (website/store/group/tax/attribute/option/set),
 * generic table copy and EAV value copy. The individual steps live in src/Step/*.php.
 *
 * Design rules:
 *  - The source DB is only ever read.
 *  - Entity ids (customers, products, categories, orders, CMS, rules) are preserved, so
 *    references between migrated data stay valid and re-runs are idempotent (upsert).
 *  - Configuration ids (website/store/customer group/tax class/attribute/option/attribute set)
 *    are re-mapped by code/name, because they differ between installations.
 *  - Only columns present in both schemas are copied, so 2.4.6 / 2.4.7 / 2.4.8 sources work
 *    against a 2.4.9 target; extra third-party columns are dropped (reported in --check).
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration;

final class Migrator
{
    use Step\CheckStep;
    use Step\AttributeStep;
    use Step\CatalogStep;
    use Step\CustomerStep;
    use Step\SalesStep;
    use Step\CmsStep;
    use Step\SalesRuleStep;
    use Step\UrlRewriteStep;
    use Step\FinalizeStep;

    public const STEPS = [
        'attributes', 'categories', 'products', 'customers', 'orders', 'cms', 'salesrules', 'urlrewrites', 'finalize',
    ];

    private Db $src;
    private Db $dst;
    private ?object $classLoader = null;

    /** @var array<int,int> source website_id => target website_id */
    private array $websiteMap = [];
    /** @var array<int,int> source store_id => target store_id */
    private array $storeMap = [];
    private int $fallbackStoreId = 1;
    private int $fallbackWebsiteId = 1;

    /** @var array<int,int> */
    private ?array $taxClassMap = null;
    /** @var array<int,int> */
    private ?array $groupMap = null;
    private int $defaultGroupId = 1;

    /** @var array<int,int> source attribute_id => target attribute_id */
    private array $attrMap = [];
    /** @var array<int,array> source attribute rows (+ entity_type_code) */
    private array $srcAttr = [];
    /** @var array<int,array> target attribute rows */
    private array $dstAttr = [];
    /** @var array<int,array<int,int>> source attribute_id => [source option_id => target option_id] */
    private array $optionMap = [];
    /** @var array<int,int> */
    private array $attrSetMap = [];
    /** @var array<string,array{src:int,dst:int,dst_default_set:int}> */
    private array $entityTypes = [];
    /** @var array<string,bool> */
    private array $syncedEntityTypes = [];

    /** @var array<int,int> duplicate source customer id => kept customer id (website merge) */
    private array $customerIdMap = [];
    /** @var array<int,bool>|null target customer ids */
    private ?array $dstCustomerIds = null;

    /** @var array<string,array{read:int,written:int,skipped:int}> */
    private array $stats = [];

    public function __construct(
        private readonly array $cfg,
        private readonly Logger $log,
        private readonly string $magentoRoot,
        private readonly bool $purge = false,
        private readonly int $batchSize = 1000,
    ) {
        $this->src = new Db($cfg['source'], 'source');
        $this->dst = new Db($cfg['target'], 'target');
        if ($this->src->schema === $this->dst->schema
            && ($cfg['source']['host'] ?? '') === ($cfg['target']['host'] ?? '')
            && (string)($cfg['source']['port'] ?? '') === (string)($cfg['target']['port'] ?? '')) {
            throw new \RuntimeException('Source and target point to the same database.');
        }
        $this->dst->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $autoload = $magentoRoot . '/vendor/autoload.php';
        if (is_file($autoload)) {
            try {
                $loader = require $autoload;
                $this->classLoader = is_object($loader) ? $loader : null;
            } catch (\Throwable $e) {
                $this->log->warn('Could not load vendor/autoload.php, class checks disabled: ' . $e->getMessage());
            }
        }
        $this->initStoreMaps();
    }

    /** @param string[] $steps */
    public function run(array $steps): void
    {
        $started = microtime(true);
        foreach ($steps as $step) {
            $t = microtime(true);
            $this->log->section('STEP ' . $step . ($this->purge && $step !== 'attributes' && $step !== 'finalize' ? ' (purge)' : ''));
            $this->{'step' . ucfirst($step)}();
            $this->log->info(sprintf('Step %s finished in %.1fs', $step, microtime(true) - $t));
        }
        $this->log->section('SUMMARY');
        foreach ($this->stats as $table => $s) {
            if ($s['read'] === 0) {
                continue;
            }
            $this->log->info(sprintf('%-48s read %8d  written %8d  skipped %7d', $table, $s['read'], $s['written'], $s['skipped']));
        }
        $this->log->info(sprintf('Done in %.1fs with %d warning(s).', microtime(true) - $started, $this->log->warnings()));
    }

    // ------------------------------------------------------------------ store / website mapping

    private function initStoreMaps(): void
    {
        $srcWebsites = $this->src->pairs("SELECT website_id, code FROM {$this->src->q('store_website')}");
        $dstWebsites = $this->dst->pairs("SELECT code, website_id FROM {$this->dst->q('store_website')}");
        $dstRealWebsites = array_filter($dstWebsites, static fn ($id) => (int)$id !== 0);
        $cfgWebsites = $this->cfg['website_map'] ?? [];

        foreach ($srcWebsites as $id => $code) {
            $target = $cfgWebsites[$code] ?? $cfgWebsites[$id] ?? $code;
            if (isset($dstWebsites[$target])) {
                $this->websiteMap[(int)$id] = (int)$dstWebsites[$target];
            } elseif ((int)$id !== 0 && count($dstRealWebsites) === 1) {
                // Target has a single website: everything collapses into it.
                $this->websiteMap[(int)$id] = (int)reset($dstRealWebsites);
            }
        }

        $srcStores = $this->src->all("SELECT store_id, code, website_id FROM {$this->src->q('store')}");
        $dstStores = $this->dst->pairs("SELECT code, store_id FROM {$this->dst->q('store')}");
        $cfgStores = $this->cfg['store_map'] ?? [];
        foreach ($srcStores as $s) {
            $target = $cfgStores[$s['code']] ?? $cfgStores[$s['store_id']] ?? $s['code'];
            if (isset($dstStores[$target])) {
                $this->storeMap[(int)$s['store_id']] = (int)$dstStores[$target];
            } elseif (is_int($target) && in_array((string)$target, array_map('strval', $dstStores), true)) {
                $this->storeMap[(int)$s['store_id']] = $target;
            }
        }

        $fallback = $this->cfg['fallback_store'] ?? null;
        if ($fallback !== null && isset($dstStores[$fallback])) {
            $this->fallbackStoreId = (int)$dstStores[$fallback];
        } else {
            $this->fallbackStoreId = (int)$this->dst->val(
                "SELECT g.default_store_id FROM {$this->dst->q('store_website')} w"
                . " JOIN {$this->dst->q('store_group')} g ON g.group_id = w.default_group_id WHERE w.is_default = 1"
            ) ?: 1;
        }
        $this->fallbackWebsiteId = (int)$this->dst->val(
            "SELECT website_id FROM {$this->dst->q('store')} WHERE store_id = ?",
            [$this->fallbackStoreId]
        ) ?: 1;
    }

    private function mapStore(mixed $id): ?int
    {
        return $id === null ? null : ($this->storeMap[(int)$id] ?? null);
    }

    private function mapWebsite(mixed $id): ?int
    {
        return $id === null ? null : ($this->websiteMap[(int)$id] ?? null);
    }

    // ------------------------------------------------------------------ tax class / customer group mapping

    /** @return array<int,int> */
    private function taxClassMap(): array
    {
        if ($this->taxClassMap !== null) {
            return $this->taxClassMap;
        }
        $this->taxClassMap = [0 => 0];
        $dst = [];
        foreach ($this->dst->all("SELECT * FROM {$this->dst->q('tax_class')}") as $r) {
            $dst[$r['class_type'] . '|' . mb_strtolower(trim($r['class_name']))] = (int)$r['class_id'];
        }
        foreach ($this->src->all("SELECT * FROM {$this->src->q('tax_class')}") as $r) {
            $key = $r['class_type'] . '|' . mb_strtolower(trim($r['class_name']));
            if (!isset($dst[$key])) {
                $dst[$key] = $this->dst->insert('tax_class', ['class_name' => $r['class_name'], 'class_type' => $r['class_type']]);
                $this->log->info("Created tax class '{$r['class_name']}' ({$r['class_type']})");
            }
            $this->taxClassMap[(int)$r['class_id']] = $dst[$key];
        }
        return $this->taxClassMap;
    }

    /** @return array<int,int> */
    private function groupMap(): array
    {
        if ($this->groupMap !== null) {
            return $this->groupMap;
        }
        $tax = $this->taxClassMap();
        $cfgMap = $this->cfg['customer_group_map'] ?? [];
        $dst = [];
        foreach ($this->dst->all("SELECT * FROM {$this->dst->q('customer_group')}") as $r) {
            $dst[mb_strtolower($r['customer_group_code'])] = (int)$r['customer_group_id'];
        }
        $this->groupMap = [];
        foreach ($this->src->all("SELECT * FROM {$this->src->q('customer_group')}") as $r) {
            $code = mb_strtolower($cfgMap[$r['customer_group_code']] ?? $r['customer_group_code']);
            if (!isset($dst[$code])) {
                $dst[$code] = $this->dst->insert('customer_group', [
                    'customer_group_code' => $r['customer_group_code'],
                    'tax_class_id' => $tax[(int)$r['tax_class_id']] ?? 3,
                ]);
                $this->log->info("Created customer group '{$r['customer_group_code']}'");
            }
            $this->groupMap[(int)$r['customer_group_id']] = $dst[$code];
        }
        $this->defaultGroupId = (int)($this->dst->val(
            "SELECT value FROM {$this->dst->q('core_config_data')} WHERE path = 'customer/create_account/default_group' AND scope = 'default'"
        ) ?? 1) ?: 1;
        return $this->groupMap;
    }

    // ------------------------------------------------------------------ customer id helpers

    private function resolveCustomerId(mixed $id): ?int
    {
        if ($id === null || (int)$id === 0) {
            return $id === null ? null : 0;
        }
        $id = $this->customerIdMap[(int)$id] ?? (int)$id;
        if ($this->dstCustomerIds === null) {
            $this->loadDstCustomerIds();
        }
        return isset($this->dstCustomerIds[$id]) ? $id : null;
    }

    private function loadDstCustomerIds(): void
    {
        $this->dstCustomerIds = array_fill_keys(
            array_map('intval', $this->dst->col("SELECT entity_id FROM {$this->dst->q('customer_entity')}")),
            true
        );
    }

    // ------------------------------------------------------------------ class existence (for model columns)

    private function classExists(?string $class): bool
    {
        if ($class === null || $class === '') {
            return true;
        }
        if ($this->classLoader === null || !method_exists($this->classLoader, 'findFile')) {
            return true; // cannot verify - assume fine
        }
        $class = ltrim($class, '\\');
        if ($this->classLoader->findFile($class) !== false) {
            return true;
        }
        // Generated code (factories, proxies) and app/code psr-0
        $rel = str_replace('\\', '/', $class) . '.php';
        foreach (['generated/code/', 'app/code/'] as $dir) {
            if (is_file($this->magentoRoot . '/' . $dir . $rel)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ generic copy

    /**
     * Copy one table from source to target.
     *
     * Options:
     *  - target:      target table name (default: same)
     *  - where/params: SQL filter on the source table
     *  - map:         [column => kind] where kind is one of
     *                 store | store_fallback | store_null | website | website_fallback | attribute |
     *                 group | group_fallback | customer | customer_required | tax_class
     *  - transform:   callable(array $row): ?array  (return null to skip)
     *  - mode:        upsert | ignore | insert
     *  - drop:        columns not to write (e.g. surrogate keys)
     */
    private function copyTable(string $table, array $opt = []): void
    {
        $dstTable = $opt['target'] ?? $table;
        if (!$this->src->hasTable($table)) {
            $this->log->debug("Skip $table: not present in source");
            return;
        }
        if (!$this->dst->hasTable($dstTable)) {
            $this->log->warn("Skip $table: not present in target (module not installed?)");
            return;
        }

        $srcCols = $this->src->columnNames($table);
        $dstMeta = $this->dst->columns($dstTable);
        $drop = $opt['drop'] ?? [];
        $cols = [];
        $fillers = [];
        foreach ($dstMeta as $name => $m) {
            if ($m['generated'] || in_array($name, $drop, true)) {
                continue;
            }
            if (in_array($name, $srcCols, true)) {
                $cols[] = $name;
            } elseif (!$m['nullable'] && $m['default'] === null && !$m['auto']) {
                $cols[] = $name;
                $fillers[$name] = Db::filler($m['type']);
            }
        }
        $lost = array_diff($srcCols, array_keys($dstMeta));
        if ($lost) {
            $this->log->debug("$table: source-only columns not copied: " . implode(', ', $lost));
        }

        $maps = $opt['map'] ?? [];
        $transform = $opt['transform'] ?? null;
        $mode = $opt['mode'] ?? 'upsert';
        $read = $written = $skipped = 0;
        $buffer = [];

        foreach ($this->src->iterate($table, $srcCols, $opt['where'] ?? null, $opt['params'] ?? [], $this->batchSize) as $row) {
            $read++;
            $row = $this->applyMaps($row, $maps);
            if ($row !== null && $transform) {
                $row = $transform($row);
            }
            if ($row === null) {
                $skipped++;
                continue;
            }
            $out = [];
            foreach ($cols as $c) {
                $v = array_key_exists($c, $row) ? $row[$c] : null;
                $out[$c] = $v === null && isset($fillers[$c]) ? $fillers[$c] : $v;
            }
            $buffer[] = $out;
            if (count($buffer) >= $this->batchSize) {
                $this->dst->insertRows($dstTable, $cols, $buffer, $mode);
                $written += count($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            $this->dst->insertRows($dstTable, $cols, $buffer, $mode);
            $written += count($buffer);
        }
        $this->stat($table, $read, $written, $skipped);
    }

    private function applyMaps(array $row, array $maps): ?array
    {
        foreach ($maps as $col => $kind) {
            if (!array_key_exists($col, $row) || $row[$col] === null) {
                continue;
            }
            $v = $row[$col];
            switch ($kind) {
                case 'store':
                    $m = $this->mapStore($v);
                    if ($m === null) {
                        return null;
                    }
                    break;
                case 'store_fallback':
                    $m = $this->mapStore($v) ?? $this->fallbackStoreId;
                    break;
                case 'store_null':
                    $m = $this->mapStore($v);
                    break;
                case 'website':
                    $m = $this->mapWebsite($v);
                    if ($m === null) {
                        return null;
                    }
                    break;
                case 'website_fallback':
                    $m = $this->mapWebsite($v) ?? $this->fallbackWebsiteId;
                    break;
                case 'attribute':
                    $m = $this->attrMap[(int)$v] ?? null;
                    if ($m === null) {
                        return null;
                    }
                    break;
                case 'group':
                    $m = $this->groupMap()[(int)$v] ?? null;
                    if ($m === null) {
                        return null;
                    }
                    break;
                case 'group_fallback':
                    $m = $this->groupMap()[(int)$v] ?? $this->defaultGroupId;
                    break;
                case 'customer':
                    $m = $this->resolveCustomerId($v);
                    break;
                case 'customer_required':
                    $m = $this->resolveCustomerId($v);
                    if ($m === null) {
                        return null;
                    }
                    break;
                case 'tax_class':
                    $m = $this->taxClassMap()[(int)$v] ?? $v;
                    break;
                default:
                    throw new \LogicException("Unknown map kind $kind");
            }
            $row[$col] = $m;
        }
        return $row;
    }

    /**
     * Copy EAV value tables (<base>_datetime|decimal|int|text|varchar) re-mapping attribute, store and
     * option ids. Values are routed to the table matching the TARGET attribute backend type.
     *
     * @param callable|null $entityFilter fn(int $entityId): ?int  - map / reject entity ids
     */
    private function copyEavValues(string $baseTable, ?callable $entityFilter = null): void
    {
        $skipCodes = array_flip($this->cfg['skip_attribute_values'] ?? []);
        foreach (['datetime', 'decimal', 'int', 'text', 'varchar'] as $type) {
            $srcTable = "{$baseTable}_{$type}";
            if (!$this->src->hasTable($srcTable)) {
                continue;
            }
            $srcCols = $this->src->columnNames($srcTable);
            $hasStore = in_array('store_id', $srcCols, true);
            $outCols = $hasStore ? ['attribute_id', 'store_id', 'entity_id', 'value'] : ['attribute_id', 'entity_id', 'value'];
            $buffers = [];
            $read = $written = $skipped = 0;
            $unmapped = [];

            $flush = function (string $t) use (&$buffers, &$written, $baseTable, $outCols): void {
                if (!empty($buffers[$t])) {
                    $this->dst->insertRows("{$baseTable}_{$t}", $outCols, $buffers[$t], 'upsert');
                    $written += count($buffers[$t]);
                    $buffers[$t] = [];
                }
            };

            foreach ($this->src->iterate($srcTable, $srcCols, null, [], $this->batchSize) as $row) {
                $read++;
                $srcAid = (int)$row['attribute_id'];
                $dstAid = $this->attrMap[$srcAid] ?? null;
                if ($dstAid === null) {
                    $unmapped[$srcAid] = true;
                    $skipped++;
                    continue;
                }
                $dstA = $this->dstAttr[$dstAid];
                $dstType = $dstA['backend_type'];
                if ($dstType === 'static' || isset($skipCodes[$dstA['attribute_code']])
                    || !$this->dst->hasTable("{$baseTable}_{$dstType}")) {
                    $skipped++;
                    continue;
                }
                $entityId = (int)$row['entity_id'];
                if ($entityFilter) {
                    $entityId = $entityFilter($entityId);
                    if ($entityId === null) {
                        $skipped++;
                        continue;
                    }
                }
                $value = $this->mapEavValue($srcAid, $dstA, $row['value']);
                if ($value === false) {
                    $skipped++;
                    continue;
                }
                $out = ['attribute_id' => $dstAid];
                if ($hasStore) {
                    $s = $this->mapStore($row['store_id']);
                    if ($s === null) {
                        $skipped++;
                        continue;
                    }
                    $out['store_id'] = $s;
                }
                $out['entity_id'] = $entityId;
                $out['value'] = $value;
                $buffers[$dstType][] = $out;
                if (count($buffers[$dstType]) >= $this->batchSize) {
                    $flush($dstType);
                }
            }
            foreach (array_keys($buffers) as $t) {
                $flush($t);
            }
            if ($unmapped) {
                $codes = array_map(fn ($id) => $this->srcAttr[$id]['attribute_code'] ?? "#$id", array_keys($unmapped));
                $this->log->debug("$srcTable: values of unmapped attributes skipped: " . implode(', ', $codes));
            }
            $this->stat($srcTable, $read, $written, $skipped);
        }
    }

    /** @return mixed mapped value, or false when the value cannot be stored in the target type */
    private function mapEavValue(int $srcAid, array $dstA, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($dstA['attribute_code'] === 'tax_class_id') {
            return $this->taxClassMap()[(int)$value] ?? $value;
        }
        if (!empty($this->optionMap[$srcAid])) {
            $value = $this->mapOptionValue($srcAid, $value);
        }
        $type = $dstA['backend_type'];
        if (($type === 'int' || $type === 'decimal') && !is_numeric($value)) {
            return $value === '' ? null : false;
        }
        return $value;
    }

    /** Map an option id or a comma separated list of option ids (multiselect). */
    private function mapOptionValue(int $srcAid, mixed $value): mixed
    {
        $map = $this->optionMap[$srcAid] ?? [];
        if (!$map || $value === null || $value === '') {
            return $value;
        }
        if (is_array($value)) {
            return array_map(fn ($v) => $this->mapOptionValue($srcAid, $v), $value);
        }
        $parts = explode(',', (string)$value);
        foreach ($parts as &$p) {
            $t = trim($p);
            if (ctype_digit($t) && isset($map[(int)$t])) {
                $p = (string)$map[(int)$t];
            }
        }
        return implode(',', $parts);
    }

    // ------------------------------------------------------------------ misc helpers

    private function purgeTables(array $tables): void
    {
        foreach ($tables as $t) {
            if ($this->dst->hasTable($t)) {
                $this->dst->truncate($t);
                $this->log->debug("Truncated target table $t");
            }
        }
        $this->log->info('Purged ' . count($tables) . ' target table(s).');
    }

    /** Abort when target rows would collide with migrated rows on a natural unique key. */
    private function assertNoConflicts(string $what, array $conflicts): void
    {
        if (!$conflicts) {
            return;
        }
        $sample = implode(', ', array_slice($conflicts, 0, 10));
        throw new \RuntimeException(sprintf(
            "%d %s already exist in the target under a different id (e.g. %s). Re-run with --purge to replace the target data.",
            count($conflicts),
            $what,
            $sample
        ));
    }

    private function stat(string $table, int $read, int $written, int $skipped): void
    {
        $s = $this->stats[$table] ?? ['read' => 0, 'written' => 0, 'skipped' => 0];
        $this->stats[$table] = ['read' => $s['read'] + $read, 'written' => $s['written'] + $written, 'skipped' => $s['skipped'] + $skipped];
        $log = $read === 0 ? 'debug' : 'info';
        $this->log->$log(sprintf('  %-46s %7d read, %7d written, %6d skipped', $table, $read, $written, $skipped));
    }

    /** @return array<int,bool> */
    private function dstIdSet(string $table, string $col = 'entity_id'): array
    {
        if (!$this->dst->hasTable($table)) {
            return [];
        }
        return array_fill_keys(array_map('intval', $this->dst->col("SELECT `$col` FROM {$this->dst->q($table)}")), true);
    }
}
