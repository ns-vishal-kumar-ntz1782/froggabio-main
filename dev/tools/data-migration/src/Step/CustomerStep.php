<?php
/**
 * Customers, customer addresses (with EAV values) and newsletter subscribers.
 * Customer groups and tax classes are mapped by code/name (missing ones are created).
 * When several old websites collapse into one new website, customers with the same e-mail are
 * merged into the oldest account; their addresses and orders are re-assigned to it.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait CustomerStep
{
    private const CUSTOMER_TABLES = [
        'customer_entity', 'customer_entity_datetime', 'customer_entity_decimal', 'customer_entity_int',
        'customer_entity_text', 'customer_entity_varchar',
        'customer_address_entity', 'customer_address_entity_datetime', 'customer_address_entity_decimal',
        'customer_address_entity_int', 'customer_address_entity_text', 'customer_address_entity_varchar',
        'customer_grid_flat', 'newsletter_subscriber',
    ];

    /** Build the duplicate map caused by website consolidation (needed by orders/rules too). */
    private function buildCustomerDedupeMap(): void
    {
        $this->customerIdMap = [];
        $seen = [];
        foreach ($this->src->iterate('customer_entity', ['entity_id', 'email', 'website_id'], null, [], 5000) as $r) {
            $website = $this->mapWebsite($r['website_id']) ?? $this->fallbackWebsiteId;
            $key = mb_strtolower(trim((string)$r['email'])) . '|' . $website;
            if (isset($seen[$key])) {
                $this->customerIdMap[(int)$r['entity_id']] = $seen[$key];
            } else {
                $seen[$key] = (int)$r['entity_id'];
            }
        }
        if ($this->customerIdMap) {
            $this->log->warn(count($this->customerIdMap) . ' customer account(s) share an e-mail after website mapping and are merged into the oldest account.');
        }
    }

    private function stepCustomers(): void
    {
        $this->syncEntityType('customer');
        $this->syncEntityType('customer_address');
        $this->groupMap();
        $this->buildCustomerDedupeMap();

        if ($this->purge) {
            $this->purgeTables(self::CUSTOMER_TABLES);
        } else {
            $dst = [];
            foreach ($this->dst->iterate('customer_entity', ['entity_id', 'email', 'website_id'], null, [], 5000) as $r) {
                $dst[mb_strtolower(trim((string)$r['email'])) . '|' . $r['website_id']] = (int)$r['entity_id'];
            }
            $conflicts = [];
            if ($dst) {
                foreach ($this->src->iterate('customer_entity', ['entity_id', 'email', 'website_id'], null, [], 5000) as $r) {
                    if (isset($this->customerIdMap[(int)$r['entity_id']])) {
                        continue;
                    }
                    $website = $this->mapWebsite($r['website_id']) ?? $this->fallbackWebsiteId;
                    $key = mb_strtolower(trim((string)$r['email'])) . '|' . $website;
                    if (isset($dst[$key]) && $dst[$key] !== (int)$r['entity_id']) {
                        $conflicts[] = $r['email'];
                    }
                }
            }
            $this->assertNoConflicts('customer e-mails', $conflicts);
        }

        $dupes = $this->customerIdMap;
        $this->copyTable('customer_entity', [
            'map' => ['website_id' => 'website_fallback', 'store_id' => 'store_fallback', 'group_id' => 'group_fallback'],
            'transform' => static fn (array $r) => isset($dupes[(int)$r['entity_id']]) ? null : $r,
        ]);
        $this->copyEavValues('customer_entity', static fn (int $id) => isset($dupes[$id]) ? null : $id);

        $this->copyTable('customer_address_entity', [
            'transform' => static function (array $r) use ($dupes): array {
                $r['parent_id'] = $dupes[(int)$r['parent_id']] ?? $r['parent_id'];
                return $r;
            },
        ]);
        $this->copyEavValues('customer_address_entity');

        $this->loadDstCustomerIds();
        $this->copyTable('newsletter_subscriber', [
            'map' => ['store_id' => 'store_fallback', 'customer_id' => 'customer'],
            'transform' => static function (array $r): array {
                $r['customer_id'] ??= 0;
                return $r;
            },
        ]);
        $this->log->info('Run "bin/magento indexer:reindex customer_grid" to rebuild the admin customer grid.');
    }
}
