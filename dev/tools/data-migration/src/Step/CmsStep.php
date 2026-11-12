<?php
/**
 * CMS pages and static blocks with their store assignments. Ids are preserved so
 * {{widget type="Magento\Cms\Block\Widget\Block" block_id="12"}} references keep working.
 */
declare(strict_types=1);

namespace FroggaBio\DataMigration\Step;

trait CmsStep
{
    private const CMS_TABLES = ['cms_page', 'cms_page_store', 'cms_block', 'cms_block_store'];
    private const PAGE_LAYOUTS = [
        'empty', '1column', '2columns-left', '2columns-right', '3columns',
        'cms-full-width', 'category-full-width', 'product-full-width',
    ];

    private function stepCms(): void
    {
        if ($this->purge) {
            $this->purgeTables(self::CMS_TABLES);
        } else {
            $conflicts = [];
            foreach (['cms_page' => 'page_id', 'cms_block' => 'block_id'] as $table => $pk) {
                $src = [];
                foreach ($this->src->all("SELECT $pk, identifier FROM {$this->src->q($table)}") as $r) {
                    $src[(int)$r[$pk]] = mb_strtolower($r['identifier']);
                }
                $srcIdentifiers = array_flip($src);
                foreach ($this->dst->all("SELECT $pk, identifier FROM {$this->dst->q($table)}") as $r) {
                    $id = (int)$r[$pk];
                    $ident = mb_strtolower($r['identifier']);
                    if ((isset($src[$id]) && $src[$id] !== $ident) || (!isset($src[$id]) && isset($srcIdentifiers[$ident]))) {
                        $conflicts[] = "$table:{$r['identifier']}";
                    }
                }
            }
            $this->assertNoConflicts('CMS pages/blocks', $conflicts);
        }

        $strip = (bool)($this->cfg['cms_strip_layout_updates'] ?? true);
        $this->copyTable('cms_page', [
            'transform' => static function (array $r) use ($strip): array {
                if ($strip) {
                    // Old theme ids / layout XML do not exist in the new (Hyva) theme.
                    foreach (['custom_theme', 'custom_theme_from', 'custom_theme_to', 'custom_layout_update_xml',
                                 'layout_update_xml', 'layout_update_selected', 'custom_root_template'] as $c) {
                        if (array_key_exists($c, $r)) {
                            $r[$c] = null;
                        }
                    }
                }
                if (!empty($r['page_layout']) && !in_array($r['page_layout'], self::PAGE_LAYOUTS, true)) {
                    $r['page_layout'] = '1column';
                }
                return $r;
            },
        ]);
        $this->copyTable('cms_page_store', ['map' => ['store_id' => 'store'], 'mode' => 'ignore']);
        $this->copyTable('cms_block');
        $this->copyTable('cms_block_store', ['map' => ['store_id' => 'store'], 'mode' => 'ignore']);

        // Two old store views mapped onto one new store view can leave duplicate identifiers.
        foreach (['cms_page' => 'page_id', 'cms_block' => 'block_id'] as $table => $pk) {
            $dupes = $this->dst->col(
                "SELECT CONCAT(e.identifier, ' (store ', s.store_id, ')') FROM {$this->dst->q($table)} e"
                . " JOIN {$this->dst->q($table . '_store')} s ON s.$pk = e.$pk"
                . ' GROUP BY e.identifier, s.store_id HAVING COUNT(*) > 1'
            );
            if ($dupes) {
                $this->log->warn("$table: duplicate identifiers per store view, fix in admin: " . implode(', ', array_slice($dupes, 0, 20)));
            }
        }
        $this->log->info('Remember to copy pub/media/wysiwyg (images used in CMS content) from the old server.');
    }
}
