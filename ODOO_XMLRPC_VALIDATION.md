# Odoo 10 XML-RPC validation

The product and barcode workflow is described in [MIGRATION_ODOO.md](MIGRATION_ODOO.md). This note covers the XML-RPC client only.

Validated `app/code/Yd/Odoo` for the Odoo 10 external API. No connection was opened to a live Odoo server, no live credentials were used, and `yd_odoo/connection/enabled` and `yd_odoo/sync/import_products` remain `0`.

PHPUnit 12.5.43 on PHP 8.5.12: **48 tests, 353 assertions, OK.** Curl is mocked. The fixture password is `fixture-password`.

## Endpoints

Odoo 10 documents the XML-RPC 2 services:

- `POST https://<host>/xmlrpc/2/common`
- `POST https://<host>/xmlrpc/2/object`

The client was calling `/xmlrpc/common` and `/xmlrpc/object`. It now builds `/xmlrpc/2/common` and `/xmlrpc/2/object`. A subpath on the configured URL is kept (`https://odoo.example.com/odoo` becomes `https://odoo.example.com/odoo/xmlrpc/2/object`). A pasted `/xmlrpc/2/...` suffix is not doubled.

The configured port `8069` is Odoo's cleartext port. It is no longer appended to an `https://` URL that has no port, so `https://odoo.example.com` stays on 443. An explicit port in the URL is kept (`https://odoo.example.com:8443`). Any other non-443 port setting is added only when the URL itself has no port. TLS on 8069 has to be written in the URL: `https://odoo.example.com:8069`.

`http://`, a missing scheme, and `user:password@host` are refused before a request is built. The password never goes in the URL.

## Authentication

`/xmlrpc/2/common` is called as `authenticate(db, login, password, {})`. The fourth argument is an empty XML-RPC struct, which is what Odoo 10 expects for `user_agent_env`. A boolean `false` or any non-numeric result is rejected and is not cached. A successful user id is reused for later `execute_kw` calls in the same process.

## execute_kw

`/xmlrpc/2/object` is called as:

```text
execute_kw(db, uid, password, model, method, args, kwargs)
```

`args` is an array. `kwargs` is a struct. Product calls send `context.lang = en_US`. The importer uses only:

- `product.product` `search(domain, offset, limit, order)` with `write_date asc, id asc`
- `product.product` `read(ids, fields)`

`Gateway` refuses every other model and every other method, including `create`, `write`, and `unlink`, before `login` and before the HTTP body is built.

## XML encoding and decoding

Requests are UTF-8 `methodCall` documents. Strings are escaped with `ENT_XML1`, so a password containing `&` or `<` cannot break the document. Booleans are `0` and `1`. Lists stay arrays. Associative arrays and an empty `stdClass` stay structs. `nil` is encoded for XML-RPC 2.

Responses accept `int`, `i4`, `i8`, `double`, `boolean`, `string`, `dateTime.iso8601`, `base64`, `nil`, `array`, and `struct`. A string barcode stays a string, so `036000291452` keeps its leading zero. An empty Odoo barcode, which XML-RPC returns as boolean `0`, stays false and does not clear Magento UPC or EAN.

## Error handling

- A curl failure becomes `Odoo could not be reached`, including the transport text (for example a certificate error). The request is not retried.
- HTTP status outside 200–299 reports the status only. The response body is not copied into the exception.
- An XML-RPC fault reports `faultString` only.
- If that text contains the configured username or password, both are replaced with `***` before the exception leaves `Gateway`.
- A response that is empty, larger than 2 MiB, contains a doctype or entity declaration, or is not a `methodResponse` is rejected.
- `simplexml_load_string` is called with `LIBXML_NONET`. External entities are not loaded.

## Connection security

Every call sets, then clears, these curl options so a shared Magento curl client cannot leave them behind:

- `CURLOPT_SSL_VERIFYPEER = true`
- `CURLOPT_SSL_VERIFYHOST = 2`
- `CURLOPT_FOLLOWLOCATION = false`
- `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` limited to HTTPS

The timeout is 30 seconds. The `Content-Type` is `text/xml; charset=utf-8`. The database password is sent only inside the TLS body, which is what Odoo 10 `authenticate` and `execute_kw` require. Scheduled sync still does not run while both admin flags are No.

## `--dry-run --sku`

`bin/magento yd:odoo:import --dry-run --sku=ABC` calls `ProductImporter::preview()` and does not call `import()` or `importOne()`.

The mocked transport test posts exactly three bodies:

1. `authenticate` on `/xmlrpc/2/common`
2. `execute_kw` `product.product` `search` for that SKU on `/xmlrpc/2/object`
3. `execute_kw` `product.product` `read` on `/xmlrpc/2/object`

None of those bodies contain `write`, `create`, or `unlink`. The cursor is not read or written. Product save, stock save, and the product map are not called. The mapped UPC and EAN are returned only in memory.

`--sku` without `--dry-run` is a real one-product import. It still cannot call an Odoo write method, and it does not move the cursor, but it does save that Magento product. The 15-minute cron remains behind the two disabled flags and does not call Odoo.

## Issues fixed

| Issue | Result |
| --- | --- |
| Legacy `/xmlrpc/common` and `/xmlrpc/object` | Calls use `/xmlrpc/2/common` and `/xmlrpc/2/object` |
| `login` with three arguments | `authenticate` with an empty struct |
| Default port 8069 appended to HTTPS hosts | `8069` and `443` are not added to an `https://` URL |
| Missing scheme fell through to HTTP | Absolute `https://` is required |
| Credentials could be placed in the URL | `user:password@host` is refused |
| Magento curl allows FTP and can follow redirects | Each call forces HTTPS, peer verification, and no redirects |
| `execute_kw` would send `write` if asked | `product.product` `search` and `read` only, refused before HTTP |
| Fault or HTTP body could echo the password | Status and `faultString` only, then username and password redacted |
| Doctype / external entity in a response | Doctype and entity declarations rejected; `LIBXML_NONET` |
| Boolean `true` from `authenticate` became user id 1 | Only an integer, or a digit string, is accepted |
