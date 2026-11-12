# FroggaBio data migration (Magento 2.4.6 – 2.4.8 → 2.4.9)

A standalone PHP CLI tool that copies the store data from the old Magento database into this
2.4.9 installation. It needs no Magento bootstrap. It connects to both databases with PDO,
reads the old one, and writes to the new one.

| Step          | What is migrated |
|---------------|------------------|
| `attributes`  | Product, category, customer and address attributes. Also covers attribute options and swatches, store labels, attribute sets, groups and set assignments. Matching is by code, name or admin label, and anything missing is created. |
| `categories`  | Category tree and all category attribute values. |
| `products`    | Products of every type (simple, configurable, bundle, grouped, virtual, downloadable). Also covers EAV values, websites, category positions, tier prices, media gallery, custom options, related/up-sell/cross-sell links, stock (legacy + MSI source items). |
| `customers`   | Customers, addresses, their EAV values and newsletter subscribers. Customer groups and tax classes are mapped or created. |
| `orders`      | Orders, items, addresses, payments, status history, tax, invoices, shipments (with tracking), credit memos, transactions, admin grids and custom order statuses. |
| `cms`         | CMS pages and static blocks with their store view assignments. |
| `salesrules`  | Cart price rules and coupons, with websites, groups, labels, coupon usage and per-customer usage. |
| `urlrewrites` | Old product, category, CMS and custom URLs, plus 301 redirects. |
| `finalize`    | Moves the order, invoice, shipment and credit memo number sequences past the migrated numbers. Also prints the follow-up commands. |

## How it works

* The **source DB is read-only**. Nothing is written to the old shop.
* **Entity IDs are kept**: customers, products, categories, orders, CMS pages and blocks, rules and coupons. Order numbers (`increment_id`) are kept too, so links between records stay valid.
* **Configuration IDs are remapped** by code or name, because they differ between installs. This covers websites, store views, customer groups, tax classes, attributes, attribute options (including select and multiselect values and option IDs inside rule conditions), attribute sets and link types.
* **Only columns that exist in both schemas are copied**. That is why the same script works for a 2.4.6, 2.4.7 or 2.4.8 source. Columns that third-party extensions added to the old DB (for example Amasty fields on `sales_order`) are dropped, and `--check` lists them.
* **Re-runnable**: rows are upserted, and options or attributes are matched before anything is created. Running a step twice produces no duplicates.
* Without `--purge`, the tool refuses to merge into target data that clashes with the source. This covers the same SKU, e-mail, order number, coupon code or CMS identifier under a different ID.

## Usage

```bash
cd <magento root>
cp dev/tools/data-migration/config.php.dist dev/tools/data-migration/config.php
# edit config.php: source DB credentials + store_map / website_map

# 1. Read-only report: store mapping, attributes to create, lost columns, payment methods, rules
php dev/tools/data-migration/migrate.php --check

# 2. Back up the target DB, then do a full import that replaces the target catalog/customers/sales/cms/rules
php dev/tools/data-migration/migrate.php --purge

# Partial / repeated runs
php dev/tools/data-migration/migrate.php --steps=customers,orders
php dev/tools/data-migration/migrate.php --steps=products,urlrewrites --purge
php dev/tools/data-migration/migrate.php --skip=urlrewrites -v
```

`--purge` empties **only the target tables of the selected steps**. It never touches attributes,
configuration, admin users or store setup. `orders` always runs `finalize` automatically.
A log is written to `var/log/data-migration-<date>.log`.

### Delta migration before go-live

1. Do a full run with `--purge` on staging and test.
2. On go-live day, put the old shop into maintenance mode and take a fresh DB dump.
3. Run `--purge` again for everything, or use `--steps=customers,orders,salesrules` without purge to add only the new rows.

## Store mapping

The old shop's websites and store views are matched to the new ones **by code**. When the new
install has only one website (FroggaBio: 1 website / 3 store views), every old website is mapped
to it automatically. Use `store_map` to map store views whose codes differ, for example
`'us' => 'usastoreview'`. Run `--check` to see the resulting mapping.

* Store-level values (translated names, descriptions, labels) of **unmapped** store views are not migrated.
* Orders, customers and subscribers from unmapped store views are assigned to `fallback_store`.
* When several old websites become one, customers with the same e-mail are **merged** into the oldest account. Their addresses, orders and coupon usage are moved to that account.

## After the migration

```bash
bin/magento indexer:reindex
bin/magento cache:flush
```

* Copy the media files from the old server (`pub/media/catalog`, `pub/media/wysiwyg`, `pub/media/downloadable`, `pub/media/customer`). The database only stores the paths.
* Check **Stores › All Stores** to confirm that each store's root category points to the migrated category tree.
* Old orders paid with an extension's payment method that is no longer installed break the admin order view. Map those methods in `payment_method_map`; `--check` lists them.
* Cart rules that rely on third-party conditions or actions are imported **inactive** and listed in the log. Re-create them with core features or a Hyvä-compatible extension.
* Values of `custom_design` and `custom_layout_update*` are skipped, and the CMS page theme and layout XML are cleared. They point to the old Pearl/WeltPixel theme. The `skip_attribute_values` and `cms_strip_layout_updates` settings control this.
* Customer passwords keep working, because the hash formats are compatible.

## Not migrated

Quotes (active carts), wishlists, reviews, catalog price rules, admin users, system configuration,
and data that belongs to third-party extensions (their own tables) are not migrated. Static
attributes of removed extensions are also skipped. Add steps to `src/Step/` if you need any of these.

Adobe Commerce databases with content staging (`row_id`) are not supported.
