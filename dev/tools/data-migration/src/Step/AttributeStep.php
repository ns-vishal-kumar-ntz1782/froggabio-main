<?php
/**
 * EAV structure: attributes (+ catalog/customer additional tables), labels, options, swatches,
 * attribute sets, groups and set assignments. Matching is by attribute_code / set name / option
 * admin label, missing items are created in the target. Idempotent.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait AttributeStep
{
    private const EAV_TYPES = ['catalog_category', 'catalog_product', 'customer', 'customer_address'];
    private const TABLE_SOURCE = 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Table';
    private const ARRAY_BACKEND = 'Magento\\Eav\\Model\\Entity\\Attribute\\Backend\\ArrayBackend';

    private function stepAttributes(): void
    {
        foreach (self::EAV_TYPES as $code) {
            $this->syncEntityType($code);
        }
    }

    private function syncEntityType(string $code): void
    {
        if (isset($this->syncedEntityTypes[$code])) {
            return;
        }
        $this->syncedEntityTypes[$code] = true;

        $srcType = $this->src->one("SELECT * FROM {$this->src->q('eav_entity_type')} WHERE entity_type_code = ?", [$code]);
        $dstType = $this->dst->one("SELECT * FROM {$this->dst->q('eav_entity_type')} WHERE entity_type_code = ?", [$code]);
        if (!$srcType || !$dstType) {
            $this->log->warn("EAV entity type $code missing in " . ($srcType ? 'target' : 'source'));
            return;
        }
        $this->entityTypes[$code] = [
            'src' => (int)$srcType['entity_type_id'],
            'dst' => (int)$dstType['entity_type_id'],
            'dst_default_set' => (int)$dstType['default_attribute_set_id'],
        ];
        $this->log->info("EAV structure: $code");
        $this->syncAttributes($code, $srcType, $dstType);
        $this->syncAttributeSets($code);
    }

    private function syncAttributes(string $code, array $srcType, array $dstType): void
    {
        $srcTypeId = (int)$srcType['entity_type_id'];
        $dstTypeId = (int)$dstType['entity_type_id'];
        $create = (bool)($this->cfg['create_missing_attributes'] ?? true);
        $skipAttrs = array_flip($this->cfg['skip_attributes'] ?? []);
        $srcAddTable = $srcType['additional_attribute_table'] ?: null;
        $dstAddTable = $dstType['additional_attribute_table'] ?: null;
        if ($srcAddTable && !$this->src->hasTable($srcAddTable)) {
            $srcAddTable = null;
        }

        $dstByCode = [];
        foreach ($this->dst->all("SELECT * FROM {$this->dst->q('eav_attribute')} WHERE entity_type_id = ?", [$dstTypeId]) as $a) {
            $dstByCode[$a['attribute_code']] = $a;
            $this->dstAttr[(int)$a['attribute_id']] = $a;
        }

        $created = [];
        $skippedStatic = [];
        $skipped = [];
        $srcAttrs = $this->src->all("SELECT * FROM {$this->src->q('eav_attribute')} WHERE entity_type_id = ?", [$srcTypeId]);
        foreach ($srcAttrs as $a) {
            $srcId = (int)$a['attribute_id'];
            $this->srcAttr[$srcId] = $a + ['entity_type_code' => $code];
            $attrCode = $a['attribute_code'];

            if (isset($dstByCode[$attrCode])) {
                $this->attrMap[$srcId] = (int)$dstByCode[$attrCode]['attribute_id'];
                continue;
            }
            if (isset($skipAttrs[$attrCode]) || !$create) {
                $skipped[] = $attrCode;
                continue;
            }
            if ($a['backend_type'] === 'static') {
                // Static attributes are columns of the entity table, provided by a module.
                $skippedStatic[] = $attrCode;
                continue;
            }

            $row = $a;
            unset($row['attribute_id']);
            $row['entity_type_id'] = $dstTypeId;
            $row = $this->sanitizeModels($row, ['attribute_model', 'backend_model', 'frontend_model', 'source_model'], $attrCode);
            if (in_array($row['frontend_input'], ['select', 'multiselect'], true) && empty($row['source_model'])
                && !empty($a['source_model'])) {
                $row['source_model'] = self::TABLE_SOURCE;
            }
            if ($row['frontend_input'] === 'multiselect' && empty($row['backend_model'])) {
                $row['backend_model'] = self::ARRAY_BACKEND;
            }
            $newId = $this->dst->insert('eav_attribute', $row);
            $row['attribute_id'] = $newId;
            $this->dstAttr[$newId] = $row;
            $this->attrMap[$srcId] = $newId;
            $dstByCode[$attrCode] = $row;
            $created[$srcId] = $attrCode;

            if ($srcAddTable && $dstAddTable && $this->dst->hasTable($dstAddTable)) {
                $add = $this->src->one("SELECT * FROM {$this->src->q($srcAddTable)} WHERE attribute_id = ?", [$srcId]);
                if ($add) {
                    $add['attribute_id'] = $newId;
                    $add = $this->sanitizeModels($add, ['frontend_input_renderer', 'data_model'], $attrCode);
                    $this->dst->insert($dstAddTable, $add, true);
                }
            }
            if ($code === 'customer' || $code === 'customer_address') {
                $this->copyCustomerAttributeMeta($srcId, $newId);
            }
        }

        // Options for every mapped attribute that has options in the source.
        $withOptions = array_map('intval', $this->src->col(
            "SELECT DISTINCT o.attribute_id FROM {$this->src->q('eav_attribute_option')} o"
            . " JOIN {$this->src->q('eav_attribute')} a ON a.attribute_id = o.attribute_id WHERE a.entity_type_id = ?",
            [$srcTypeId]
        ));
        $newOptions = 0;
        foreach ($withOptions as $srcId) {
            if (isset($this->attrMap[$srcId])) {
                $newOptions += $this->syncOptions($srcId, $this->attrMap[$srcId]);
            }
        }

        // Store labels + default values for created attributes.
        foreach ($created as $srcId => $attrCode) {
            $dstId = $this->attrMap[$srcId];
            foreach ($this->src->all("SELECT store_id, value FROM {$this->src->q('eav_attribute_label')} WHERE attribute_id = ?", [$srcId]) as $l) {
                $store = $this->mapStore($l['store_id']);
                if ($store !== null && $store !== 0) {
                    $this->dst->exec("DELETE FROM {$this->dst->q('eav_attribute_label')} WHERE attribute_id = ? AND store_id = ?", [$dstId, $store]);
                    $this->dst->insert('eav_attribute_label', ['attribute_id' => $dstId, 'store_id' => $store, 'value' => $l['value']]);
                }
            }
            $default = $this->srcAttr[$srcId]['default_value'];
            if ($default !== null && $default !== '' && !empty($this->optionMap[$srcId])) {
                $this->dst->exec(
                    "UPDATE {$this->dst->q('eav_attribute')} SET default_value = ? WHERE attribute_id = ?",
                    [$this->mapOptionValue($srcId, $default), $dstId]
                );
            }
        }

        $this->log->info(sprintf(
            '  %d attributes mapped, %d created%s, %d new options',
            count(array_filter($srcAttrs, fn ($a) => isset($this->attrMap[(int)$a['attribute_id']]))),
            count($created),
            $created ? ' (' . implode(', ', array_slice($created, 0, 30)) . (count($created) > 30 ? ', ...' : '') . ')' : '',
            $newOptions
        ));
        if ($skippedStatic) {
            $this->log->warn("  $code static attributes from source modules not migrated: " . implode(', ', $skippedStatic));
        }
        if ($skipped) {
            $this->log->info("  $code attributes skipped by config: " . implode(', ', $skipped));
        }
    }

    private function sanitizeModels(array $row, array $cols, string $attrCode): array
    {
        foreach ($cols as $col) {
            if (!empty($row[$col]) && !$this->classExists($row[$col])) {
                $this->log->warn("  attribute $attrCode: $col {$row[$col]} does not exist in target code - cleared");
                $row[$col] = null;
            }
        }
        return $row;
    }

    private function copyCustomerAttributeMeta(int $srcId, int $dstId): void
    {
        if ($this->src->hasTable('customer_form_attribute') && $this->dst->hasTable('customer_form_attribute')) {
            foreach ($this->src->col("SELECT form_code FROM {$this->src->q('customer_form_attribute')} WHERE attribute_id = ?", [$srcId]) as $form) {
                $this->dst->insert('customer_form_attribute', ['form_code' => $form, 'attribute_id' => $dstId], true);
            }
        }
        if ($this->src->hasTable('customer_eav_attribute_website') && $this->dst->hasTable('customer_eav_attribute_website')) {
            foreach ($this->src->all("SELECT * FROM {$this->src->q('customer_eav_attribute_website')} WHERE attribute_id = ?", [$srcId]) as $w) {
                $website = $this->mapWebsite($w['website_id']);
                if ($website !== null) {
                    $w['attribute_id'] = $dstId;
                    $w['website_id'] = $website;
                    $this->dst->insert('customer_eav_attribute_website', $w, true);
                }
            }
        }
    }

    /** @return array<int, array{sort:int, values:array<int,string>}> */
    private function loadOptions(\FroggaBio\DataMigration\Db $db, int $attrId): array
    {
        $rows = $db->all(
            "SELECT o.option_id, o.sort_order, v.store_id, v.value FROM {$db->q('eav_attribute_option')} o"
            . " LEFT JOIN {$db->q('eav_attribute_option_value')} v ON v.option_id = o.option_id"
            . ' WHERE o.attribute_id = ? ORDER BY o.option_id, v.store_id',
            [$attrId]
        );
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['option_id'];
            $out[$id] ??= ['sort' => (int)$r['sort_order'], 'values' => []];
            if ($r['store_id'] !== null) {
                $out[$id]['values'][(int)$r['store_id']] = (string)$r['value'];
            }
        }
        return $out;
    }

    private static function optionKey(array $o): string
    {
        $v = $o['values'][0] ?? (reset($o['values']) ?: '');
        return mb_strtolower(trim((string)$v));
    }

    /** Map source options onto target options by admin label, creating missing ones. Returns created count. */
    private function syncOptions(int $srcAid, int $dstAid): int
    {
        $srcOpts = $this->loadOptions($this->src, $srcAid);
        $dstOpts = $this->loadOptions($this->dst, $dstAid);
        $index = [];
        foreach ($dstOpts as $id => $o) {
            $k = self::optionKey($o);
            if ($k !== '') {
                $index[$k] ??= $id;
            }
        }

        $swatches = [];
        if ($this->src->hasTable('eav_attribute_option_swatch') && $this->dst->hasTable('eav_attribute_option_swatch')) {
            foreach ($this->src->all(
                "SELECT s.* FROM {$this->src->q('eav_attribute_option_swatch')} s JOIN {$this->src->q('eav_attribute_option')} o"
                . ' ON o.option_id = s.option_id WHERE o.attribute_id = ?',
                [$srcAid]
            ) as $s) {
                $swatches[(int)$s['option_id']][] = $s;
            }
        }

        $map = [];
        $created = 0;
        foreach ($srcOpts as $sid => $o) {
            $k = self::optionKey($o);
            if ($k !== '' && isset($index[$k])) {
                $did = $index[$k];
                $map[$sid] = $did;
                // add store labels the target option does not have yet
                $have = $dstOpts[$did]['values'] ?? [];
                foreach ($o['values'] as $store => $value) {
                    $ds = $this->mapStore($store);
                    if ($ds !== null && !isset($have[$ds])) {
                        $this->dst->insert('eav_attribute_option_value', ['option_id' => $did, 'store_id' => $ds, 'value' => $value]);
                        $have[$ds] = $value;
                    }
                }
                $dstOpts[$did]['values'] = $have;
                continue;
            }

            $did = $this->dst->insert('eav_attribute_option', ['attribute_id' => $dstAid, 'sort_order' => $o['sort']]);
            $done = [];
            foreach ($o['values'] as $store => $value) {
                $ds = $this->mapStore($store);
                if ($ds !== null && !isset($done[$ds])) {
                    $this->dst->insert('eav_attribute_option_value', ['option_id' => $did, 'store_id' => $ds, 'value' => $value]);
                    $done[$ds] = true;
                }
            }
            foreach ($swatches[$sid] ?? [] as $s) {
                $ds = $this->mapStore($s['store_id']);
                if ($ds !== null) {
                    unset($s['swatch_id']);
                    $s['option_id'] = $did;
                    $s['store_id'] = $ds;
                    $this->dst->insert('eav_attribute_option_swatch', $s, true);
                }
            }
            $map[$sid] = $did;
            if ($k !== '') {
                $index[$k] = $did;
            }
            $created++;
        }
        $this->optionMap[$srcAid] = $map;
        return $created;
    }

    private function syncAttributeSets(string $code): void
    {
        $et = $this->entityTypes[$code];
        $dstSets = [];
        foreach ($this->dst->all("SELECT attribute_set_id, attribute_set_name FROM {$this->dst->q('eav_attribute_set')} WHERE entity_type_id = ?", [$et['dst']]) as $s) {
            $dstSets[mb_strtolower($s['attribute_set_name'])] = (int)$s['attribute_set_id'];
        }
        $createdSets = [];
        $mappedSets = 0;
        foreach ($this->src->all("SELECT * FROM {$this->src->q('eav_attribute_set')} WHERE entity_type_id = ?", [$et['src']]) as $s) {
            $mappedSets++;
            $key = mb_strtolower($s['attribute_set_name']);
            if (!isset($dstSets[$key])) {
                $dstSets[$key] = $this->dst->insert('eav_attribute_set', [
                    'entity_type_id' => $et['dst'],
                    'attribute_set_name' => $s['attribute_set_name'],
                    'sort_order' => $s['sort_order'],
                ]);
                $createdSets[] = $s['attribute_set_name'];
            }
            $this->attrSetMap[(int)$s['attribute_set_id']] = $dstSets[$key];
        }

        // Groups
        $dstGroups = [];
        foreach ($this->dst->all(
            "SELECT g.* FROM {$this->dst->q('eav_attribute_group')} g JOIN {$this->dst->q('eav_attribute_set')} s"
            . ' ON s.attribute_set_id = g.attribute_set_id WHERE s.entity_type_id = ?',
            [$et['dst']]
        ) as $g) {
            $set = (int)$g['attribute_set_id'];
            $dstGroups[$set]['name:' . mb_strtolower($g['attribute_group_name'])] = (int)$g['attribute_group_id'];
            if (isset($g['attribute_group_code'])) {
                $dstGroups[$set]['code:' . $g['attribute_group_code']] = (int)$g['attribute_group_id'];
            }
        }
        $groupMap = [];
        foreach ($this->src->all(
            "SELECT g.* FROM {$this->src->q('eav_attribute_group')} g JOIN {$this->src->q('eav_attribute_set')} s"
            . ' ON s.attribute_set_id = g.attribute_set_id WHERE s.entity_type_id = ?',
            [$et['src']]
        ) as $g) {
            $set = $this->attrSetMap[(int)$g['attribute_set_id']] ?? null;
            if ($set === null) {
                continue;
            }
            $code = $g['attribute_group_code'] ?? null;
            $id = ($code !== null ? ($dstGroups[$set]['code:' . $code] ?? null) : null)
                ?? $dstGroups[$set]['name:' . mb_strtolower($g['attribute_group_name'])] ?? null;
            if ($id === null) {
                $row = $g;
                unset($row['attribute_group_id']);
                $row['attribute_set_id'] = $set;
                if ($code !== null) {
                    $base = $code;
                    for ($i = 1; isset($dstGroups[$set]['code:' . $code]); $i++) {
                        $code = $base . '-' . $i;
                    }
                    $row['attribute_group_code'] = $code;
                    $dstGroups[$set]['code:' . $code] = 0;
                }
                $id = $this->dst->insert('eav_attribute_group', $row);
                $dstGroups[$set]['name:' . mb_strtolower($g['attribute_group_name'])] = $id;
                if ($code !== null) {
                    $dstGroups[$set]['code:' . $code] = $id;
                }
            }
            $groupMap[(int)$g['attribute_group_id']] = $id;
        }

        // Attribute assignments (existing assignments in the target are kept)
        $rows = [];
        foreach ($this->src->all("SELECT * FROM {$this->src->q('eav_entity_attribute')} WHERE entity_type_id = ?", [$et['src']]) as $r) {
            $set = $this->attrSetMap[(int)$r['attribute_set_id']] ?? null;
            $group = $groupMap[(int)$r['attribute_group_id']] ?? null;
            $attr = $this->attrMap[(int)$r['attribute_id']] ?? null;
            if ($set && $group && $attr) {
                $rows[] = ['entity_type_id' => $et['dst'], 'attribute_set_id' => $set, 'attribute_group_id' => $group,
                    'attribute_id' => $attr, 'sort_order' => $r['sort_order']];
            }
        }
        $added = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $added += $this->dst->insertRows('eav_entity_attribute',
                ['entity_type_id', 'attribute_set_id', 'attribute_group_id', 'attribute_id', 'sort_order'], $chunk, 'ignore');
        }
        $this->log->info(sprintf('  attribute sets: %d mapped, %d created%s; %d new set assignments',
            $mappedSets, count($createdSets),
            $createdSets ? ' (' . implode(', ', $createdSets) . ')' : '', $added));
    }

    private function mapAttributeSet(mixed $srcSetId, string $entityTypeCode): int
    {
        if ((int)$srcSetId === 0) {
            return 0;
        }
        return $this->attrSetMap[(int)$srcSetId] ?? $this->entityTypes[$entityTypeCode]['dst_default_set'];
    }
}
