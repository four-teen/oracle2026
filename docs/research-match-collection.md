# Research Match collection

## Administrator workflow

Open **Research Workspace → Research Similarity** (the existing `administrator/title_similarity.php` URL).

1. **Start new evaluation** freezes the current catalog and creates a system-generated run for every titled manuscript, including administrator-only unpublished manuscripts.
2. The page processes short requests automatically. Each source is compared with every other frozen manuscript. Self-comparisons are excluded; low and zero scores are retained.
3. **Pause** finishes the current request before stopping. Closing or refreshing the page stops further requests. **Resume** continues from the last committed cursor. Previous evaluations remain in history when a new one starts.
4. **Automated evaluation** reports each completed source run's score bands, average algorithm scores, and reference-threshold counts without requiring reviews. **View saved runs → Open run → Review / calculation** shows the original input, system assessment, and full calculation. Add an optional relevance review afterward; it does not replace the automated assessment.
5. Export a run or the filtered collection to CSV. Exports include every saved comparison in the selection, the original text snapshots, the algorithm breakdown, and independent reviewer labels.

**View saved runs** opens a separate tab so that inspecting a completed run or adding a later review does not stop the active evaluation. The processing page announces newly completed automatic reports using the existing progress responses, without an extra polling request.

For N manuscripts, an evaluation stores N × (N−1) directed comparisons. Both A→B and B→A are retained under their respective source runs. There is no automatic full-catalog execution during deployment or page load. Large evaluations require the page to remain open; interrupted work is resumable. Each individual full-text comparison can take longer for unusually long abstracts.

The four catalog comparison formulas are symmetric for the same frozen inputs. When B→A has already been calculated, A→B reuses that complete calculation instead of repeating it. Both result rows are retained, and `reused_from_result_id` records the provenance. Public cache copies also retain the originating result ID.

## Sources and comparison modes

| Source | Mode | Saved results |
| --- | --- | --- |
| `user_submitted` | `query_to_record` | The matches returned by a successful Research Match modal submission (normally five), with their complete calculation breakdowns. Successful zero-match searches also create a run. |
| `system_generated` | `record_to_record` | Every other manuscript in the frozen administrator catalog, including low/zero scores. |

“User-submitted” describes the public submission path, not verified human identity. The server assigns the source; the browser cannot choose it. Public searches continue to exclude unpublished faculty-owned records. Only administrators can read the collection, snapshots, reviews, and exports.

Public searches compare the entered text independently against a target title and target abstract. Catalog evaluations compare a source title against a target title and a source abstract against a target abstract. These modes must be distinguished in research reports.

For each algorithm, `combined = 0.70 × title + 0.30 × abstract`. Public mode uses 100% title when the target abstract is absent. Catalog mode uses 100% title when either abstract is absent. Unavailable abstract scores are null, not zero. The final score is `0.20 × Jaccard + 0.30 × Cosine + 0.20 × Levenshtein + 0.30 × Dice` using the combined scores. The shared scorer processes complete abstracts; it does not use display excerpts.

## Storage and reproducibility

The two primary shared tables are `tblresearchmatch_run` and `tblresearchmatch_result`.

- `tblresearchmatch_document`: immutable, content-addressed manuscript title/abstract snapshots, reused across runs to avoid repeating long abstracts in every comparison.
- `tblresearchmatch_batch`: catalog fingerprint, algorithm version, originator, expected/committed comparison counts, and evaluation status.
- `tblresearchmatch_batch_document`: fixed catalog membership and processing order.
- `tblresearchmatch_run`: source, mode, query or source snapshot, catalog fingerprint, algorithm version, status, result count, request key, and processing cursor.
- Each completed run stores its automatic aggregate report in `summary_json`; the administrator can combine these small summaries without repeatedly reading every detailed calculation.
- `tblresearchmatch_result`: target snapshot, stored rank, field/overall scores, and full calculation JSON containing each algorithm's weights, scores, and contributions.
- `tblresearchmatch_review`: one updatable relevance judgment per reviewer per result. Other reviewers' judgments remain independent.

Snapshot IDs deliberately do not have foreign keys to the live manuscript catalog. Editing or deleting a live manuscript does not rewrite the recorded comparison inputs. The previous title-only scores/reviews remain in their original tables and can be accessed through **Previous title-only evaluation**; they are not silently converted to the new method.

The current scorer version is `ResearchAiSearch::ALGORITHM_VERSION`. Bump it whenever normalization, formulas, field weights, or algorithm weights change. An older batch cannot resume with a different version. Its saved results remain reviewable/exportable.

Version `research-match-1.1` is compatible with Apache PHP 7.1.26. The JSON helper checks encode/decode errors without `JSON_THROW_ON_ERROR`; JSON endpoints suppress displayed PHP notices, log server failures, and return structured errors. Exact full-length Levenshtein distance uses a portable bit-vector implementation when PHP 7's 255-byte native limit would apply. Its block recurrence was checked against [Edlib's primary implementation](https://github.com/Martinsos/edlib/blob/master/edlib/src/edlib.cpp), and regression tests compare its distances with an independent dynamic-programming implementation and PHP 8's native function. No titles or abstracts are truncated.

The one supported recovery exception is an entirely unstarted `research-match-1.0` batch: Resume may promote it to 1.1 only while locked, with zero saved comparisons and all source runs still queued. This preserves the failed PHP 7.1 batch's frozen catalog. Any batch with prior calculations retains its original version and requires a new evaluation.

## Save timing, cache, and retry behavior

The public endpoint calculates and saves in the same POST. It returns success only after the run and results commit. Modal close does not trigger an additional save request, and reopening the modal retains its displayed results.

The browser sends a random request ID and CSRF token. Retrying an unsuccessful request uses the same ID; after a successful submission the next submission gets a new ID. Request IDs are scoped to the browser session. Public source labels and catalog visibility are assigned by the server.

A ten-minute calculation cache is keyed by normalized query, public catalog fingerprint, scorer version, and result limit. Reusing a computation still records a separate submission run. Catalog or metadata edits invalidate the cache. No IP address or raw session identifier is stored in the collection.

Each catalog processing request locks its batch and commits new results, run cursor, ranks, and batch counters together. A failure rolls the transaction back. Concurrent workers serialize on that batch lock, preventing skipped or duplicate comparisons. There is no background worker after the administrator closes the page.

## Review metrics and exports

Every new calculation stores an explicit system assessment: high similarity at an overall score of at least 0.72, moderate at 0.48–below 0.72, and low below 0.48. Automatic aggregate reports preserve each algorithm's threshold, score sum, and count at/above threshold. These reports are available before human review. The reference thresholds are configurable research assumptions, not verified human labels.

The evaluation view groups metrics by comparison mode and scorer version, applying the selected run/source/catalog filters. Highly Related and Related are positive; Not Related is negative. A tie between positive and negative reviewer votes is excluded.

The initial decision thresholds are reference values inherited from the older workspace, not newly validated thresholds: Hybrid 0.56, Jaccard 0.34, Cosine 0.50, Levenshtein 0.68, Dice 0.44. The page reports accuracy, precision, recall, F1, and TP/FP/TN/FN rather than automatically declaring an algorithm validated. Include lower-scoring candidates and a defined human review sample when evaluating research quality.

CSV scores and weights use the 0–1 scale. Contribution × 100 is the number of percentage points shown in the interface. CSV includes unrounded numeric values, the system assessment and its thresholds, calculation reuse references, reviewer records as JSON, run status, and calculation timestamps. Formula-like submitted text is escaped for spreadsheet safety. Timestamps follow the database server's time configuration.

## Installation and checks

For a manual phpMyAdmin deployment, select the intended database and import **`sql/research_match_install_or_update.sql`** in one operation. It creates missing collection tables, checks whether the two later columns exist before adding them, and finishes with a six-row schema report. Existing tables, columns, and saved records are retained. Every `table_status`, `column_status`, `index_status`, and `foreign_key_status` must read `OK`. A column-exists/skipped message is normal. Required column counts are 6/6 (document), 11/11 (batch), 3/3 (batch membership), 22/22 (run), 11/11 (result), and 8/8 (review).

To inspect an existing deployment without making any changes, run **`sql/research_match_verify.sql`**. The report verifies all 61 required columns (types, lengths, nullability, character sets, unsigned IDs, and auto-increment), 18 primary/unique/other indexes, seven foreign-key mappings, and the table engines/collations. The installer repairs missing tables and the two later columns; it reports other incompatible definitions instead of silently changing them. Share any `CHECK ...` rows before applying further schema changes.

`Database::ensureResearchMatchTables()` applies `sql/research_match_collection.sql` idempotently when the public save endpoint or administrator workspace first uses the collection. The migration creates new tables and does not replace legacy scores/reviews. For deployment accounts without CREATE TABLE permission, apply that SQL file through the normal database migration process first.

To prepare the tables explicitly without starting an evaluation, run `php tasks/setup-research-match.php`.

Run:

```text
php tests/research-similarity.php --integration
php tests/research-match-collection.php
php tests/research-edit-distance.php
php tests/research-match-migration.php
```

The first test uses connection-local temporary catalog tables. The collection test creates a uniquely named disposable `oracle_rm_test_*` database, exercises the real schema/foreign keys and processing/export paths, then removes that test database in a finally block. It requires CREATE/DROP DATABASE permission on the test database server; it does not change application records.

The migration test also uses a disposable database. It checks fresh installation, repeat imports, upgrades from the missing-column schema, preservation of existing searches, and reporting of incompatible column/index/foreign-key definitions.

Run the same checks with the actual Apache PHP binary, not only the default command-line PHP. On this machine an isolated PHP 7.1 invocation is:

```powershell
& 'C:\VertrigoServ\Php\php.exe' -n -d extension_dir=C:\VertrigoServ\Php\ext -d extension=php_mbstring.dll -d extension=php_pdo_mysql.dll tests\research-match-collection.php
```

This loads the required extensions for that process without changing the server's PHP configuration.
