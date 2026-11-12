<?php
/**
 * Orders with invoices, shipments, credit memos, payment transactions, tax, comments, grids
 * and custom order statuses. Entity ids and increment ids are preserved.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait SalesStep
{
    private const SALES_TABLES = [
        'sales_order', 'sales_order_grid', 'sales_order_address', 'sales_order_item', 'sales_order_payment',
        'sales_order_status_history', 'sales_order_tax', 'sales_order_tax_item', 'sales_payment_transaction',
        'sales_invoice', 'sales_invoice_grid', 'sales_invoice_item', 'sales_invoice_comment',
        'sales_shipment', 'sales_shipment_grid', 'sales_shipment_item', 'sales_shipment_comment', 'sales_shipment_track',
        'sales_creditmemo', 'sales_creditmemo_grid', 'sales_creditmemo_item', 'sales_creditmemo_comment',
        'downloadable_link_purchased', 'downloadable_link_purchased_item',
    ];

    private function stepOrders(): void
    {
        $this->groupMap();
        if (!$this->customerIdMap) {
            $this->buildCustomerDedupeMap();
        }
        $this->loadDstCustomerIds();

        if ($this->purge) {
            $this->purgeTables(self::SALES_TABLES);
        } else {
            $dst = $this->dst->pairs("SELECT entity_id, increment_id FROM {$this->dst->q('sales_order')}");
            $conflicts = [];
            if ($dst) {
                $src = $this->src->pairs("SELECT entity_id, increment_id FROM {$this->src->q('sales_order')}");
                foreach ($dst as $id => $inc) {
                    if (!isset($src[$id]) || $src[$id] !== $inc) {
                        $conflicts[] = "#$inc";
                    }
                }
            }
            $this->assertNoConflicts('orders', $conflicts);
        }

        // Custom statuses (core ones already exist in the target)
        $this->copyTable('sales_order_status', ['mode' => 'ignore']);
        $this->copyTable('sales_order_status_state', ['mode' => 'ignore']);
        $this->copyTable('sales_order_status_label', ['map' => ['store_id' => 'store']]);

        $maps = [
            'store_id' => 'store_fallback',
            'customer_id' => 'customer',
            'customer_group_id' => 'group_fallback',
        ];
        $paymentMap = $this->cfg['payment_method_map'] ?? [];
        foreach (self::SALES_TABLES as $table) {
            $opt = ['map' => $maps];
            if ($table === 'sales_order_payment' && $paymentMap) {
                $opt['transform'] = static function (array $r) use ($paymentMap): array {
                    if (isset($paymentMap[$r['method']])) {
                        $r['method'] = $paymentMap[$r['method']];
                    }
                    return $r;
                };
            }
            if ($table === 'sales_order_grid' && $paymentMap) {
                $opt['transform'] = static function (array $r) use ($paymentMap): array {
                    if (isset($r['payment_method'], $paymentMap[$r['payment_method']])) {
                        $r['payment_method'] = $paymentMap[$r['payment_method']];
                    }
                    return $r;
                };
            }
            $this->copyTable($table, $opt);
        }
        $this->log->info('Order increment sequences are advanced in the "finalize" step.');
    }
}
