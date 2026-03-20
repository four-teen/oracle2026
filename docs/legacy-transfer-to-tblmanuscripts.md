## Legacy Transfer To `tblmanuscripts`

This project's current manuscript table is defined in `app/Database.php` and already matches the old `tblmanuscripts` dump very closely.

### What matches directly

Old `tblmanuscripts.sql` columns:

- `manuscriptid`
- `researchtypeid`
- `sdg_code`
- `manuscript_title`
- `other_details`
- `adviser_name`
- `panelists`
- `statisticians`
- `abstract_file_path`
- `abstract_original_name`
- `created_at`
- `updated_at`
- `adviser_accountid`
- `panelist_accountids`
- `statistician_accountid`
- `english_critic_accountid`
- `authors`

Current `tblmanuscripts` supports the same columns.

### What is different in the new development

The new development also maintains a normalized helper table:

- `tblmanuscript_panelists`

That table is populated from `tblmanuscripts.panelist_accountids` by `Database::ensureManuscriptPanelistTable()`.

### Legacy `tblresearches` mapping

The old `tblresearches` dump is a base record source, not a full manuscript-details source.

Legacy to current mapping:

- `titleid` -> `manuscriptid`
- `typeid` -> `researchtypeid`
- `sdgs` -> `sdg_code`
- `title` -> `manuscript_title`
- `authors` -> `authors`
- `submitted_at` -> `created_at`
- `updated_at` -> `updated_at`

Legacy fields without direct current columns:

- `campusid`
- `ayid`
- `status`
- `encoder`
- `programid`

Recommended handling:

- Store those legacy-only values inside `other_details` so they are not lost during the first migration.

### Findings from the provided dumps

From `tblresearches (2).sql`:

- Parsed rows: `203`
- Distinct `typeid`: `1`
- Distinct `campusid`: `2`
- Distinct `ayid`: `11`, `13`
- Distinct `status`: `On-going`
- Distinct `encoder`: `9`, `398`
- Distinct `programid`: `91`, `92`

From `tblmanuscripts.sql`:

- The schema is already aligned with the current development.
- The sample row includes account-linked detail columns, so account IDs must still exist in the current `tblaccount` table or be nulled during import.

### Important risk

`tblresearches.typeid` needs a confirmed mapping to the current `tblresearchtype.researchtypeid`.

This project seeds:

- `1 = Thesis`
- `2 = Capstone`
- `3 = Copyright`
- `4 = Other`

Your provided `tblresearches` dump only uses legacy `typeid = 1`, but the dump does not include the old type lookup table, so the meaning of `1` should be verified before final import.

### Recommended import approach

1. Load the old dumps into staging tables, not directly into the live tables.
2. Use the SQL template in `sql/legacy_transfer_to_tblmanuscripts.sql`.
3. Review the `legacy_researchtype_map` section before running the insert.
4. Open the manuscript management page after import so the app can backfill `tblmanuscript_panelists`.

### Suggested staging table names

- `legacy_tblresearches`
- `legacy_tblmanuscripts`

Do not import the old dumps directly over the live `tblmanuscripts` table in the current development database.
