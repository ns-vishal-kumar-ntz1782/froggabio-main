<?php
/**
 * Cart price rules (promotions) and coupons, incl. websites, customer groups, labels,
 * coupon usage and per-customer usage. Product attribute option ids inside rule conditions
 * are re-mapped. Rules relying on condition classes or actions that do not exist in the new
 * code base (e.g. third-party promotion extensions) are imported as inactive.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait SalesRuleStep
{
    private const SALESRULE_TABLES = [
        'salesrule', 'salesrule_website', 'salesrule_customer_group', 'salesrule_label', 'salesrule_coupon',
        'salesrule_coupon_usage', 'salesrule_customer', 'salesrule_product_attribute',
    ];
    private const CORE_RULE_ACTIONS = ['by_percent', 'by_fixed', 'cart_fixed', 'buy_x_get_y'];

    private function stepSalesrules(): void
    {
        $this->syncEntityType('catalog_product');
        $this->groupMap();
        if (!$this->customerIdMap) {
            $this->buildCustomerDedupeMap();
        }
        $this->loadDstCustomerIds();

        if ($this->purge) {
            $this->purgeTables(self::SALESRULE_TABLES);
        } else {
            $src = $this->src->pairs("SELECT coupon_id, code FROM {$this->src->q('salesrule_coupon')}");
            $srcCodes = array_flip(array_map('mb_strtolower', $src));
            $conflicts = [];
            foreach ($this->dst->pairs("SELECT coupon_id, code FROM {$this->dst->q('salesrule_coupon')}") as $id => $code) {
                $lc = mb_strtolower((string)$code);
                if ((isset($src[$id]) && mb_strtolower((string)$src[$id]) !== $lc) || (!isset($src[$id]) && isset($srcCodes[$lc]))) {
                    $conflicts[] = $code;
                }
            }
            $this->assertNoConflicts('coupon codes', $conflicts);
        }

        $productAttrByCode = [];
        foreach ($this->srcAttr as $id => $a) {
            if ($a['entity_type_code'] === 'catalog_product') {
                $productAttrByCode[$a['attribute_code']] = $id;
            }
        }

        $deactivated = [];
        $this->copyTable('salesrule', [
            'transform' => function (array $r) use ($productAttrByCode, &$deactivated): array {
                $bad = [];
                foreach (['conditions_serialized', 'actions_serialized'] as $c) {
                    if (!empty($r[$c])) {
                        $r[$c] = $this->remapRuleConditions($r[$c], $productAttrByCode, $bad);
                    }
                }
                if (!in_array($r['simple_action'], self::CORE_RULE_ACTIONS, true)) {
                    $bad[] = 'action ' . $r['simple_action'];
                }
                if ($bad && (int)$r['is_active'] === 1) {
                    $r['is_active'] = 0;
                    $deactivated[] = "#{$r['rule_id']} {$r['name']} (" . implode(', ', array_unique($bad)) . ')';
                }
                return $r;
            },
        ]);
        foreach ($deactivated as $d) {
            $this->log->warn("Cart rule imported as INACTIVE, uses features not available in the new shop: $d");
        }

        $this->copyTable('salesrule_website', ['map' => ['website_id' => 'website'], 'mode' => 'ignore']);
        $this->copyTable('salesrule_customer_group', ['map' => ['customer_group_id' => 'group'], 'mode' => 'ignore']);
        $this->copyTable('salesrule_label', ['map' => ['store_id' => 'store'], 'drop' => ['label_id']]);
        $this->copyTable('salesrule_coupon');
        $this->copyTable('salesrule_coupon_usage', ['map' => ['customer_id' => 'customer_required']]);
        $this->copyTable('salesrule_customer', ['map' => ['customer_id' => 'customer_required']]);
        $this->copyTable('salesrule_product_attribute', [
            'map' => ['website_id' => 'website', 'customer_group_id' => 'group', 'attribute_id' => 'attribute'],
            'mode' => 'ignore',
        ]);
    }

    /** @param string[] $bad collects unknown condition classes */
    private function remapRuleConditions(string $serialized, array $productAttrByCode, array &$bad): string
    {
        $data = json_decode($serialized, true);
        if (!is_array($data)) {
            $bad[] = 'non-JSON conditions';
            return $serialized;
        }
        $walk = function (array $node) use (&$walk, $productAttrByCode, &$bad): array {
            if (!empty($node['type']) && !$this->classExists($node['type'])) {
                $bad[] = $node['type'];
            }
            if (!empty($node['attribute']) && isset($productAttrByCode[$node['attribute']])
                && str_contains((string)($node['type'] ?? ''), 'Condition\\Product')
                && isset($node['value']) && $node['value'] !== '') {
                $node['value'] = $this->mapOptionValue($productAttrByCode[$node['attribute']], $node['value']);
            }
            foreach (['conditions', 'actions'] as $k) {
                if (!empty($node[$k]) && is_array($node[$k])) {
                    $node[$k] = array_map($walk, $node[$k]);
                }
            }
            return $node;
        };
        return json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
