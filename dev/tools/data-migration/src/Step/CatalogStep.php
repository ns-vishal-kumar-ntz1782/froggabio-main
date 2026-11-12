<?php
/**
 * Categories and products (simple, configurable, bundle, grouped, virtual, downloadable),
 * including EAV values, media gallery, tier prices, custom options, links, websites,
 * category assignments and stock (legacy CatalogInventory + MSI source items).
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait CatalogStep
{
    private const CATEGORY_TABLES = [
        'catalog_category_entity', 'catalog_category_entity_datetime', 'catalog_category_entity_decimal',
        'catalog_category_entity_int', 'catalog_category_entity_text', 'catalog_category_entity_varchar',
    ];

    private const PRODUCT_TABLES = [
        'catalog_product_entity', 'catalog_product_entity_datetime', 'catalog_product_entity_decimal',
        'catalog_product_entity_int', 'catalog_product_entity_text', 'catalog_product_entity_varchar',
        'catalog_product_entity_media_gallery', 'catalog_product_entity_media_gallery_value',
        'catalog_product_entity_media_gallery_value_to_entity', 'catalog_product_entity_media_gallery_value_video',
        'catalog_product_entity_tier_price', 'catalog_product_website', 'catalog_category_product',
        'catalog_product_option', 'catalog_product_option_title', 'catalog_product_option_price',
        'catalog_product_option_type_value', 'catalog_product_option_type_title', 'catalog_product_option_type_price',
        'catalog_product_link', 'catalog_product_link_attribute_decimal', 'catalog_product_link_attribute_int',
        'catalog_product_link_attribute_varchar', 'catalog_product_super_attribute', 'catalog_product_super_attribute_label',
        'catalog_product_super_link', 'catalog_product_relation',
        'catalog_product_bundle_option', 'catalog_product_bundle_option_value', 'catalog_product_bundle_selection',
        'catalog_product_bundle_selection_price',
        'downloadable_link', 'downloadable_link_price', 'downloadable_link_title', 'downloadable_sample',
        'downloadable_sample_title',
        'cataloginventory_stock_item', 'inventory_source_item',
    ];

    private function assertNoStaging(): void
    {
        foreach ([$this->src, $this->dst] as $db) {
            if ($db->hasColumn('catalog_product_entity', 'row_id')) {
                throw new \RuntimeException("{$db->label} database uses Adobe Commerce content staging (row_id); this tool supports Magento Open Source only.");
            }
        }
    }

    private function stepCategories(): void
    {
        $this->assertNoStaging();
        $this->syncEntityType('catalog_category');
        if ($this->purge) {
            $this->purgeTables(self::CATEGORY_TABLES);
        }
        $this->copyTable('catalog_category_entity', [
            'transform' => function (array $r): array {
                $r['attribute_set_id'] = $this->mapAttributeSet($r['attribute_set_id'], 'catalog_category');
                return $r;
            },
        ]);
        $this->copyEavValues('catalog_category_entity');

        // Every store group must point at an existing root category.
        $cats = $this->dstIdSet('catalog_category_entity');
        foreach ($this->dst->all("SELECT group_id, name, root_category_id FROM {$this->dst->q('store_group')} WHERE group_id > 0") as $g) {
            if (!isset($cats[(int)$g['root_category_id']])) {
                $this->log->warn("Store group '{$g['name']}' uses root category {$g['root_category_id']} which does not exist - set it in Stores > All Stores.");
            }
        }
        $roots = $this->dst->all(
            "SELECT e.entity_id, v.value AS name FROM {$this->dst->q('catalog_category_entity')} e"
            . " LEFT JOIN {$this->dst->q('catalog_category_entity_varchar')} v ON v.entity_id = e.entity_id AND v.store_id = 0"
            . " AND v.attribute_id = (SELECT attribute_id FROM {$this->dst->q('eav_attribute')} WHERE attribute_code = 'name' AND entity_type_id = ?)"
            . ' WHERE e.level = 1',
            [$this->entityTypes['catalog_category']['dst'] ?? 3]
        );
        $this->log->info('Root categories in target: ' . implode(', ', array_map(static fn ($r) => "#{$r['entity_id']} {$r['name']}", $roots)));
    }

    private function stepProducts(): void
    {
        $this->assertNoStaging();
        $this->syncEntityType('catalog_product');
        $this->syncEntityType('catalog_category');
        $this->groupMap();

        if ($this->purge) {
            $this->purgeTables(self::PRODUCT_TABLES);
        } else {
            $dstSkus = $this->dst->pairs("SELECT sku, entity_id FROM {$this->dst->q('catalog_product_entity')}");
            $conflicts = [];
            if ($dstSkus) {
                $dstSkus = array_change_key_case($dstSkus, CASE_LOWER);
                foreach ($this->src->iterate('catalog_product_entity', ['entity_id', 'sku'], null, [], 5000) as $r) {
                    $k = mb_strtolower((string)$r['sku']);
                    if (isset($dstSkus[$k]) && (int)$dstSkus[$k] !== (int)$r['entity_id']) {
                        $conflicts[] = $r['sku'];
                    }
                }
            }
            $this->assertNoConflicts('product SKUs', $conflicts);
        }

        $this->copyTable('catalog_product_entity', [
            'transform' => function (array $r): array {
                $r['attribute_set_id'] = $this->mapAttributeSet($r['attribute_set_id'], 'catalog_product');
                return $r;
            },
        ]);
        $this->copyEavValues('catalog_product_entity');

        $this->copyTable('catalog_product_website', ['map' => ['website_id' => 'website'], 'mode' => 'ignore']);

        $categories = $this->dstIdSet('catalog_category_entity');
        $this->copyTable('catalog_category_product', [
            'mode' => 'ignore',
            'transform' => static fn (array $r) => isset($categories[(int)$r['category_id']]) ? $r : null,
        ]);

        $this->copyTable('catalog_product_entity_tier_price', [
            'map' => ['website_id' => 'website', 'customer_group_id' => 'group'],
            'drop' => ['value_id'],
        ]);

        // Media gallery
        $this->copyTable('catalog_product_entity_media_gallery', ['map' => ['attribute_id' => 'attribute']]);
        $this->copyTable('catalog_product_entity_media_gallery_value', ['map' => ['store_id' => 'store']]);
        $this->copyTable('catalog_product_entity_media_gallery_value_to_entity', ['mode' => 'ignore']);
        $this->copyTable('catalog_product_entity_media_gallery_value_video', ['map' => ['store_id' => 'store']]);

        // Custom options
        $this->copyTable('catalog_product_option');
        $this->copyTable('catalog_product_option_title', ['map' => ['store_id' => 'store']]);
        $this->copyTable('catalog_product_option_price', ['map' => ['store_id' => 'store']]);
        $this->copyTable('catalog_product_option_type_value');
        $this->copyTable('catalog_product_option_type_title', ['map' => ['store_id' => 'store']]);
        $this->copyTable('catalog_product_option_type_price', ['map' => ['store_id' => 'store']]);

        // Related / up-sell / cross-sell / grouped links
        [$linkTypeMap, $linkAttrMap] = $this->linkMaps();
        $this->copyTable('catalog_product_link', [
            'transform' => static function (array $r) use ($linkTypeMap): ?array {
                $r['link_type_id'] = $linkTypeMap[(int)$r['link_type_id']] ?? null;
                return $r['link_type_id'] === null ? null : $r;
            },
        ]);
        foreach (['decimal', 'int', 'varchar'] as $t) {
            $this->copyTable("catalog_product_link_attribute_$t", [
                'transform' => static function (array $r) use ($linkAttrMap): ?array {
                    $r['product_link_attribute_id'] = $linkAttrMap[(int)$r['product_link_attribute_id']] ?? null;
                    return $r['product_link_attribute_id'] === null ? null : $r;
                },
            ]);
        }

        // Configurable
        $this->copyTable('catalog_product_super_attribute', ['map' => ['attribute_id' => 'attribute']]);
        $this->copyTable('catalog_product_super_attribute_label', ['map' => ['store_id' => 'store']]);
        $this->copyTable('catalog_product_super_link');
        $this->copyTable('catalog_product_relation', ['mode' => 'ignore']);

        // Bundle
        $this->copyTable('catalog_product_bundle_option');
        $this->copyTable('catalog_product_bundle_option_value', ['map' => ['store_id' => 'store']]);
        $this->copyTable('catalog_product_bundle_selection');
        $this->copyTable('catalog_product_bundle_selection_price', ['map' => ['website_id' => 'website'], 'mode' => 'ignore']);

        // Downloadable
        $this->copyTable('downloadable_link');
        $this->copyTable('downloadable_link_price', ['map' => ['website_id' => 'website']]);
        $this->copyTable('downloadable_link_title', ['map' => ['store_id' => 'store']]);
        $this->copyTable('downloadable_sample');
        $this->copyTable('downloadable_sample_title', ['map' => ['store_id' => 'store']]);

        // Stock
        $this->copyTable('cataloginventory_stock_item', [
            'transform' => function (array $r): array {
                if (isset($r['website_id']) && (int)$r['website_id'] !== 0) {
                    $r['website_id'] = $this->mapWebsite($r['website_id']) ?? 0;
                }
                return $r;
            },
        ]);
        $this->copyInventorySources();
    }

    /** @return array{0: array<int,int>, 1: array<int,int>} */
    private function linkMaps(): array
    {
        $dstTypes = $this->dst->pairs("SELECT code, link_type_id FROM {$this->dst->q('catalog_product_link_type')}");
        $typeMap = [];
        $srcTypeCodes = [];
        foreach ($this->src->pairs("SELECT link_type_id, code FROM {$this->src->q('catalog_product_link_type')}") as $id => $code) {
            $srcTypeCodes[(int)$id] = $code;
            if (isset($dstTypes[$code])) {
                $typeMap[(int)$id] = (int)$dstTypes[$code];
            } else {
                $this->log->warn("Product link type '$code' does not exist in target - those links are skipped.");
            }
        }
        $dstAttrs = [];
        foreach ($this->dst->all("SELECT * FROM {$this->dst->q('catalog_product_link_attribute')}") as $a) {
            $dstAttrs[$a['link_type_id'] . '|' . $a['product_link_attribute_code']] = (int)$a['product_link_attribute_id'];
        }
        $attrMap = [];
        foreach ($this->src->all("SELECT * FROM {$this->src->q('catalog_product_link_attribute')}") as $a) {
            $dstType = $typeMap[(int)$a['link_type_id']] ?? null;
            if ($dstType !== null && isset($dstAttrs[$dstType . '|' . $a['product_link_attribute_code']])) {
                $attrMap[(int)$a['product_link_attribute_id']] = $dstAttrs[$dstType . '|' . $a['product_link_attribute_code']];
            }
        }
        return [$typeMap, $attrMap];
    }

    private function copyInventorySources(): void
    {
        if (!$this->src->hasTable('inventory_source_item') || !$this->dst->hasTable('inventory_source_item')) {
            return;
        }
        $sourceMap = $this->cfg['inventory_source_map'] ?? [];
        // Create sources that exist only in the old shop (unless mapped to another source).
        if ($this->src->hasTable('inventory_source')) {
            $this->copyTable('inventory_source', [
                'mode' => 'ignore',
                'transform' => static fn (array $r) => isset($sourceMap[$r['source_code']]) ? null : $r,
            ]);
            $stocks = $this->dstIdSet('inventory_stock', 'stock_id');
            $this->copyTable('inventory_source_stock_link', [
                'mode' => 'ignore',
                'drop' => ['link_id'],
                'transform' => static fn (array $r) => isset($stocks[(int)$r['stock_id']]) && !isset($sourceMap[$r['source_code']]) ? $r : null,
            ]);
        }
        $dstSources = array_flip($this->dst->col("SELECT source_code FROM {$this->dst->q('inventory_source')}"));
        $this->copyTable('inventory_source_item', [
            'drop' => ['source_item_id'],
            'transform' => static function (array $r) use ($sourceMap, $dstSources): ?array {
                $r['source_code'] = $sourceMap[$r['source_code']] ?? $r['source_code'];
                return isset($dstSources[$r['source_code']]) ? $r : null;
            },
        ]);
    }
}
