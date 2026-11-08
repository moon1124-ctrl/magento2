# Odoo and barcode migration

Local Magento 2.4.9 code compared with `magento1-legacy` and the earlier `magento2-new` snapshot. Magento 1 was not modified. No production Odoo connection was opened, no live data was changed, and nothing was committed or deployed.

## Confirmed architecture

Odoo 10 assigns GS1 UPC and EAN values and prints Zebra labels. Magento 2 stores those values and imports product and stock data. Magento does not mint GS1 numbers and does not print labels. Payment is eWAY. Catchfeeder and SecurePay are not part of this Magento 2 tree.

Automatic product import stays off: `yd_odoo/connection/enabled` and `yd_odoo/sync/import_products` are `0`.

## What Magento 1 did

`Octocub_UpcCopy` in Magento 1.9 is an observer on `catalog_product_save_before`. When the SKU is 13 digits it writes that SKU into `upc`. It does not calculate a check digit, does not keep a sequence, and does not print. The comment in that observer mentions an empty UPC, but the code overwrites `upc` whenever the SKU is 13 digits.

Magento 1 has no `Yd_Odoo` module. The earlier Magento 2 snapshot still pushed products, stock, and orders to Odoo through `ProductSync`, `OrderSync`, and catalog/order observers, and it copied a 13-digit SKU into empty UPC and EAN fields.

## What Magento 2 does now

`Yd_Odoo` reads Odoo and writes Magento.

- XML-RPC 2: `https://host/xmlrpc/2/common` `authenticate`, then `https://host/xmlrpc/2/object` `execute_kw`. Details are in [ODOO_XMLRPC_VALIDATION.md](ODOO_XMLRPC_VALIDATION.md).
- Only `product.product` `search` and `read` are sent. `create`, `write`, and `unlink` are refused before a request is built, so Magento cannot push a barcode or an order back to Odoo.
- A valid UPC-A is stored as that 12-digit value and as the EAN-13 formed with a leading `0`. `036000291452` is stored as UPC `036000291452` and EAN `0036000291452`. Any other Odoo barcode, including EAN-13 `4006381333931` and a non-GS1 code, is stored on EAN. An existing UPC is left in place. An empty Odoo barcode does not clear either field.
- Stock is the Odoo on-hand quantity written to the default MSI source only. A negative quantity is stored as `0`. Other sources are not changed. Magento updates the legacy stock item from that default-source save.
- If a save fails, the import stops and the cursor stays on the last successful earlier row. The failed product is retried on the next run.
- `bin/magento yd:odoo:import --dry-run` and `--sku` remain available. `--dry-run --sku` does not save Magento data and does not send an Odoo write. The cron job still returns immediately while the two flags are No.

`Octocub_UpcCopy` keeps the `upc` and `ean` attributes and the attribute data patch. On product save it puts a stored barcode back when a later save would blank it. It does not copy the SKU into a barcode and it does not allocate a new GS1 number. The admin Zebra button, print route, and generation settings are removed. The `octocub_upc_sequence` table is unchanged so an existing counter is not dropped; product save does not read it.

## Tests

PHPUnit 12.5.43 on PHP 8.5.12, with mocked HTTP and no database:

- `Octocub_UpcCopy`: 39 tests, 408 assertions, OK
- `Yd_Odoo`: 48 tests, 353 assertions, OK

- `app/code/Yd/Odoo/Test/Unit`
- `app/code/Octocub/UpcCopy/Test/Unit`

Run from the Magento root:

```text
php vendor/bin/phpunit -c app/code/Yd/Odoo/Test/Unit/phpunit.xml.dist --do-not-cache-result
php vendor/bin/phpunit -c app/code/Octocub/UpcCopy/Test/Unit/phpunit.xml.dist --do-not-cache-result
```

Use the project's PHP 8.5 binary. This workspace has no `app/etc/env.php`, so `bin/magento` was not run.

## Risks

- A Magento 1 product whose UPC was only a copy of a 13-digit SKU still has that stored value. Magento 2 will not invent a replacement and will not clear it.
- Odoo remains the writer for barcodes. A dry run shows the mapping before any scheduled import is enabled.
- The old sequence table can still exist after `setup:upgrade`. Nothing in the product save path uses it.
- eWAY is present. SecurePay and Catchfeeder are not in this tree. The reference snapshot under `magento2-new` still contains them and was not edited.

## Deployment

On the staging Magento 2 host, after the code is copied and the module list includes `Eway_Rapid`, `Octocub_UpcCopy`, and `Yd_Odoo`:

1. Leave **Stores > Configuration > Odoo > Enabled** and **Import products** set to No.
2. Run `bin/magento setup:upgrade` once so the UPC and EAN attributes are created if they are missing. The data patch does not rewrite existing product values.
3. Run `bin/magento setup:di:compile` and `bin/magento cache:flush` on production mode.
4. Confirm the product edit screen has no Zebra label button.
5. Against a non-production Odoo only, run `bin/magento yd:odoo:import --dry-run --sku=ONE-SKU` and check the printed UPC and EAN.
6. Enable the connection and product import only after that dry run matches Odoo.
