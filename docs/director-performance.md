# Director loading performance

Measured locally on 2026-10-25 with the site's PHP 7.1.26 runtime and the ISULAN campus (905 manuscripts). Browser measurements used the same authenticated Edge session with warm asset caches. These are individual local measurements, rather than production latency guarantees.

## Causes

Director authorization and rendering invoked account schema maintenance repeatedly. Each page also invoked `Database::ensureManuscriptTable()`, which performs schema inspection, cleanup, seed inserts, foreign-key inspection, panelist-link backfilling, and similarity-table maintenance.

The manuscript routine executed 1,878 SQL statements in 2,187 ms. That included 1,743 `INSERT IGNORE` attempts for panelist relationships already represented in the catalog. Foreign-key owner lookups accounted for another 684 ms. The actual SDG and contributor reads took about 41 ms combined.

The shared layout loaded jQuery, Select2, Bootstrap JavaScript, template helpers/configuration, and SweetAlert on director pages, although these pages use native forms and their own JavaScript. Those scripts total approximately 1.93 MB before compression, plus about 45 KB of unnecessary stylesheets.

## Changes

- Director authorization uses a zero-row account schema probe and still refreshes the account and checks its enabled status, selected role, and campus.
- Analytics uses six zero-row schema probes, reused within the request. Existing schemas perform no maintenance writes. Missing-table or missing-column errors invoke the existing initializer; other database errors propagate.
- Account/manuscript management and other workspaces retain their existing setup behavior. Legacy panelist imports should run the existing manuscript initializer during import, rather than relying on an analytics view to rebuild their relationships.
- Director layouts load the scripts they use. The dashboard chart library and page scripts use `defer`, preserving execution order.
- Statistics are queried live on each request.

## Measurements

The combined account/schema + SDG/contributor/summary benchmark dropped from 1,953 statements to 11, with zero writes on the optimized path. Schema work dropped from about 2,222 ms to 12 ms.

| SDG page, browser navigation timing | Before | After |
| --- | ---: | ---: |
| First response byte | 2,175 ms | 56 ms |
| DOM interactive | 2,396 ms | 196 ms |
| Load event complete | 4,778 ms | 552 ms |

After optimization, the Contributors page returned its first byte in 102 ms and completed loading in 709 ms. The dashboard returned its first byte in 156 ms and completed loading in 654 ms, including the chart library.

Temporary browser instrumentation was removed after measurements.

## Validation

```text
php tests/director-auth.php
php tests/director-read-schema.php
php tests/research-analytics.php
```

These cover fresh authorization, rejected disabled/removed/wrong-role accounts, campus assignment, read probes, per-request reuse, missing-schema initialization, database-error propagation, and statistics correctness. Browser checks cover all three director pages, the chart, SDG filtering, contributor role switching and search, and mobile navigation.
