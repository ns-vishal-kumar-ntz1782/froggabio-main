<?php
/**
 * Post-migration fixes: advance sales sequences beyond migrated increment ids and print the
 * remaining manual steps.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait FinalizeStep
{
    private function stepFinalize(): void
    {
        $this->advanceSalesSequences();

        $this->log->info('Next steps (run from the Magento root):');
        foreach ([
            'bin/magento indexer:reindex',
            'bin/magento cache:flush',
            'rsync old pub/media/catalog/, pub/media/wysiwyg/, pub/media/downloadable/ and pub/media/customer/ into the new pub/media/',
            'bin/magento catalog:images:resize   (optional - pre-generate image cache)',
            'Check Stores > All Stores root categories, payment methods of old orders, and inactive cart rules reported above.',
        ] as $i => $line) {
            $this->log->info(sprintf('  %d. %s', $i + 1, $line));
        }
    }

    private function advanceSalesSequences(): void
    {
        if (!$this->dst->hasTable('sales_sequence_meta')) {
            return;
        }
        $entities = ['order' => 'sales_order', 'invoice' => 'sales_invoice', 'shipment' => 'sales_shipment', 'creditmemo' => 'sales_creditmemo'];
        foreach ($entities as $type => $table) {
            if (!$this->dst->hasTable($table)) {
                continue;
            }
            // Numeric tail (pad length 9) of every existing increment id; new numbers must be above it.
            $max = (int)$this->dst->val(
                "SELECT MAX(CAST(RIGHT(REGEXP_SUBSTR(increment_id, '[0-9]+$'), 9) AS UNSIGNED)) FROM {$this->dst->q($table)}"
            );
            if ($max <= 0) {
                continue;
            }
            foreach ($this->dst->col("SELECT sequence_table FROM {$this->dst->q('sales_sequence_meta')} WHERE entity_type = ?", [$type]) as $seq) {
                $name = $this->dst->hasRawTable($seq) ? $seq : $this->dst->t($seq);
                if (!$this->dst->hasRawTable($name)) {
                    continue;
                }
                $current = (int)$this->dst->val("SELECT MAX(sequence_value) FROM `$name`");
                if ($current < $max) {
                    $this->dst->exec("INSERT INTO `$name` (sequence_value) VALUES (?)", [$max]);
                    $this->log->info("  $name advanced to $max");
                }
            }
        }
    }
}
