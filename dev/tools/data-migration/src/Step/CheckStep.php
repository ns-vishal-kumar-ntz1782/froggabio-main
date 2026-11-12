<?php
/**
 * --check : read-only pre-flight report. Nothing is written to either database.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait CheckStep
{
    public function check(): void
    {
        $this->log->section('CONNECTIONS');
        $this->log->info("Source: {$this->src->schema} (" . $this->src->val('SELECT VERSION()') . ')' . ' - ' . $this->guessVersion());
        $this->log->info("Target: {$this->dst->schema} (" . $this->dst->val('SELECT VERSION()') . ')');
        foreach ([$this->src, $this->dst] as $db) {
            if ($db->hasColumn('catalog_product_entity', 'row_id')) {
                $this->log->warn("{$db->label} uses Adobe Commerce staging (row_id) - not supported.");
            }
        }

        $this->log->section('WEBSITES / STORE VIEWS');
        $dstStores = $this->dst->pairs("SELECT store_id, code FROM {$this->dst->q('store')}");
        $dstWebsites = $this->dst->pairs("SELECT website_id, code FROM {$this->dst->q('store_website')}");
        foreach ($this->src->all("SELECT website_id, code, name FROM {$this->src->q('store_website')}") as $w) {
            $m = $this->mapWebsite($w['website_id']);
            $line = sprintf('website %-3s %-20s -> %s', $w['website_id'], $w['code'], $m === null ? 'UNMAPPED' : "$m {$dstWebsites[$m]}");
            $m === null ? $this->log->warn($line) : $this->log->info($line);
        }
        foreach ($this->src->all("SELECT store_id, code, name FROM {$this->src->q('store')}") as $s) {
            $m = $this->mapStore($s['store_id']);
            $line = sprintf('store   %-3s %-20s -> %s', $s['store_id'], $s['code'],
                $m === null ? "UNMAPPED (store-level values skipped, sales use fallback store {$this->fallbackStoreId})" : "$m {$dstStores[$m]}");
            $m === null ? $this->log->warn($line) : $this->log->info($line);
        }

        $this->log->section('ROW COUNTS (source -> target now)');
        foreach ([
            'catalog_category_entity', 'catalog_product_entity', 'eav_attribute', 'eav_attribute_set',
            'customer_entity', 'customer_address_entity', 'newsletter_subscriber',
            'sales_order', 'sales_invoice', 'sales_shipment', 'sales_creditmemo',
            'cms_page', 'cms_block', 'salesrule', 'salesrule_coupon', 'url_rewrite',
        ] as $t) {
            $this->log->info(sprintf('%-32s %9d -> %9d', $t, $this->src->count($t), $this->dst->count($t)));
        }

        $this->log->section('ATTRIBUTES');
        foreach (self::EAV_TYPES as $code) {
            $srcType = $this->src->val("SELECT entity_type_id FROM {$this->src->q('eav_entity_type')} WHERE entity_type_code = ?", [$code]);
            $dstType = $this->dst->val("SELECT entity_type_id FROM {$this->dst->q('eav_entity_type')} WHERE entity_type_code = ?", [$code]);
            $dstCodes = array_flip($this->dst->col("SELECT attribute_code FROM {$this->dst->q('eav_attribute')} WHERE entity_type_id = ?", [$dstType]));
            $create = $static = $badModels = [];
            foreach ($this->src->all("SELECT * FROM {$this->src->q('eav_attribute')} WHERE entity_type_id = ?", [$srcType]) as $a) {
                if (isset($dstCodes[$a['attribute_code']])) {
                    continue;
                }
                if ($a['backend_type'] === 'static') {
                    $static[] = $a['attribute_code'];
                    continue;
                }
                $create[] = $a['attribute_code'];
                foreach (['backend_model', 'frontend_model', 'source_model'] as $c) {
                    if (!empty($a[$c]) && !$this->classExists($a[$c])) {
                        $badModels[] = "{$a['attribute_code']}.$c";
                    }
                }
            }
            $this->log->info(sprintf('%-18s %3d to create%s', $code, count($create), $create ? ': ' . implode(', ', $create) : ''));
            if ($static) {
                $this->log->warn("$code static attributes not migrated (module columns): " . implode(', ', $static));
            }
            if ($badModels) {
                $this->log->warn("$code models missing in new code (will be cleared): " . implode(', ', $badModels));
            }
        }

        $this->log->section('SCHEMA DIFFERENCES (source columns that will NOT be copied)');
        $tables = array_merge(
            ['catalog_category_entity', 'catalog_product_entity', 'customer_entity', 'customer_address_entity'],
            self::SALES_TABLES, self::CMS_TABLES, self::SALESRULE_TABLES, ['url_rewrite', 'cataloginventory_stock_item']
        );
        $any = false;
        foreach ($tables as $t) {
            if (!$this->src->hasTable($t)) {
                continue;
            }
            if (!$this->dst->hasTable($t)) {
                $this->log->warn("$t missing in target");
                continue;
            }
            $lost = array_diff($this->src->columnNames($t), array_keys($this->dst->columns($t)));
            if ($lost) {
                $any = true;
                $this->log->info(sprintf('%-32s %s', $t, implode(', ', $lost)));
            }
        }
        if (!$any) {
            $this->log->info('none');
        }

        $this->log->section('PAYMENT METHODS USED BY OLD ORDERS');
        $known = $this->knownPaymentMethods();
        $map = $this->cfg['payment_method_map'] ?? [];
        foreach ($this->src->pairs("SELECT method, COUNT(*) FROM {$this->src->q('sales_order_payment')} GROUP BY method ORDER BY 2 DESC") as $method => $n) {
            $target = $map[$method] ?? $method;
            $ok = $known === null || isset($known[$target]);
            $line = sprintf('%-32s %7d orders%s', $method, $n, $target !== $method ? " -> $target" : '');
            $ok ? $this->log->info($line) : $this->log->warn($line . '  (method not found in new code: admin order view may fail - add to payment_method_map)');
        }

        $this->log->section('CART PRICE RULES');
        $productAttrByCode = [];
        $srcProductType = $this->src->val("SELECT entity_type_id FROM {$this->src->q('eav_entity_type')} WHERE entity_type_code = 'catalog_product'");
        foreach ($this->src->pairs("SELECT attribute_code, attribute_id FROM {$this->src->q('eav_attribute')} WHERE entity_type_id = ?", [$srcProductType]) as $c => $id) {
            $productAttrByCode[$c] = (int)$id;
        }
        $problems = 0;
        foreach ($this->src->all("SELECT rule_id, name, is_active, simple_action, conditions_serialized, actions_serialized FROM {$this->src->q('salesrule')}") as $r) {
            $bad = [];
            foreach (['conditions_serialized', 'actions_serialized'] as $c) {
                if (!empty($r[$c])) {
                    $this->remapRuleConditions($r[$c], $productAttrByCode, $bad);
                }
            }
            if (!in_array($r['simple_action'], self::CORE_RULE_ACTIONS, true)) {
                $bad[] = 'action ' . $r['simple_action'];
            }
            if ($bad) {
                $problems++;
                $this->log->warn("rule #{$r['rule_id']} {$r['name']}" . ((int)$r['is_active'] ? ' (active)' : '') . ': ' . implode(', ', array_unique($bad)));
            }
        }
        $this->log->info($problems ? "$problems rule(s) will be imported inactive." : 'All rules use core features.');

        $this->log->section('RESULT');
        $this->log->info($this->log->warnings() . ' warning(s). Nothing was written.');
    }

    private function guessVersion(): string
    {
        // Rough schema fingerprint; all 2.4.x schemas are supported via column intersection.
        if ($this->src->hasTable('sales_order_grid') && $this->src->hasColumn('customer_entity', 'session_cutoff')) {
            return 'schema looks like 2.4.7 or newer';
        }
        return 'schema looks like 2.4.6 or older';
    }

    /** Payment method codes declared in config.xml of the new code base. @return array<string,bool>|null */
    private function knownPaymentMethods(): ?array
    {
        $files = array_merge(
            glob($this->magentoRoot . '/vendor/*/*/etc/config.xml') ?: [],
            glob($this->magentoRoot . '/app/code/*/*/etc/config.xml') ?: []
        );
        if (!$files) {
            return null;
        }
        $methods = [];
        foreach ($files as $f) {
            $xml = @simplexml_load_file($f);
            if ($xml && isset($xml->default->payment)) {
                foreach ($xml->default->payment->children() as $node) {
                    $methods[$node->getName()] = true;
                }
            }
        }
        return $methods;
    }
}
