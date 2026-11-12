<?php
/**
 * URL rewrites for products, categories, CMS pages and custom redirects. Copying (instead of
 * regenerating) keeps the old URLs and the 301 redirects that were created over the years, so
 * SEO and external links keep working. Run it after categories/products/cms.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait UrlRewriteStep
{
    private function stepUrlrewrites(): void
    {
        if (!$this->src->hasTable('url_rewrite')) {
            return;
        }
        $types = ['product', 'category', 'cms-page', 'custom'];
        $hasPc = $this->src->hasTable('catalog_url_rewrite_product_category')
            && $this->dst->hasTable('catalog_url_rewrite_product_category');

        if ($this->purge) {
            $this->dst->exec(
                "DELETE FROM {$this->dst->q('url_rewrite')} WHERE entity_type IN (" . implode(',', array_fill(0, count($types), '?')) . ')',
                $types
            );
            if ($hasPc) {
                $this->dst->truncate('catalog_url_rewrite_product_category');
            }
            $this->log->info('Purged product/category/cms-page/custom URL rewrites in target.');
        }

        $exists = [
            'product' => $this->dstIdSet('catalog_product_entity'),
            'category' => $this->dstIdSet('catalog_category_entity'),
            'cms-page' => $this->dstIdSet('cms_page', 'page_id'),
        ];
        $pc = [];
        if ($hasPc) {
            foreach ($this->src->all("SELECT * FROM {$this->src->q('catalog_url_rewrite_product_category')}") as $r) {
                $pc[(int)$r['url_rewrite_id']] = $r;
            }
        }

        $cols = array_values(array_intersect(
            $this->dst->columnNames('url_rewrite'),
            $this->src->columnNames('url_rewrite')
        ));
        $cols = array_values(array_diff($cols, ['url_rewrite_id']));
        $read = $written = $skipped = 0;
        $buffer = [];
        $where = 'entity_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';

        foreach ($this->src->iterate('url_rewrite', $this->src->columnNames('url_rewrite'), $where, $types, $this->batchSize) as $r) {
            $read++;
            $store = $this->mapStore($r['store_id']);
            $type = $r['entity_type'];
            if ($store === null || $store === 0 || ($type !== 'custom' && !isset($exists[$type][(int)$r['entity_id']]))) {
                $skipped++;
                continue;
            }
            $r['store_id'] = $store;
            $row = array_intersect_key($r, array_flip($cols));

            if (isset($pc[(int)$r['url_rewrite_id']])) {
                // need the new id for the product-category relation
                $newId = $this->dst->insert('url_rewrite', $row, true);
                if ($newId) {
                    $link = $pc[(int)$r['url_rewrite_id']];
                    if (isset($exists['category'][(int)$link['category_id']])) {
                        $this->dst->insert('catalog_url_rewrite_product_category', [
                            'url_rewrite_id' => $newId,
                            'category_id' => $link['category_id'],
                            'product_id' => $link['product_id'],
                        ], true);
                    }
                    $written++;
                } else {
                    $skipped++;
                }
                continue;
            }
            $buffer[] = $row;
            if (count($buffer) >= $this->batchSize) {
                $written += $this->dst->insertRows('url_rewrite', $cols, $buffer, 'ignore');
                $buffer = [];
            }
        }
        if ($buffer) {
            $written += $this->dst->insertRows('url_rewrite', $cols, $buffer, 'ignore');
        }
        // "skipped" includes rows already present (same request path + store) from a previous run.
        $this->stat('url_rewrite', $read, $written, $read - $written);
    }
}
