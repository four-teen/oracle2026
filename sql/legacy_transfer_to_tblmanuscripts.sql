-- Legacy transfer helper for the current Oracle development database.
-- Preconditions:
-- 1. Current app tables already exist (`tblresearchtype`, `tblmanuscripts`, `tblaccount`).
-- 2. Old dumps were imported into staging tables:
--      - legacy_tblresearches
--      - legacy_tblmanuscripts
-- 3. Review legacy_researchtype_map before running inserts.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS legacy_researchtype_map;

CREATE TEMPORARY TABLE legacy_researchtype_map (
  legacy_typeid INT NOT NULL PRIMARY KEY,
  new_researchtypeid INT NOT NULL
);

-- Adjust this mapping after verifying what the old type IDs mean.
-- Current development seeds:
--   1 = Thesis
--   2 = Capstone
--   3 = Copyright
--   4 = Other
INSERT INTO legacy_researchtype_map (legacy_typeid, new_researchtypeid) VALUES
  (1, 1),
  (2, 2),
  (3, 3),
  (4, 4);

-- Import legacy manuscript rows when they already exist in old tblmanuscripts.
-- Account-linked fields are nulled if the referenced current account does not exist.
INSERT INTO tblmanuscripts (
  manuscriptid,
  researchtypeid,
  sdg_code,
  manuscript_title,
  other_details,
  adviser_name,
  panelists,
  statisticians,
  abstract_file_path,
  abstract_original_name,
  created_at,
  updated_at,
  adviser_accountid,
  panelist_accountids,
  statistician_accountid,
  english_critic_accountid,
  authors
)
SELECT
  lm.manuscriptid,
  COALESCE(rtm.new_researchtypeid, 4) AS researchtypeid,
  NULLIF(TRIM(lm.sdg_code), '') AS sdg_code,
  LEFT(TRIM(REPLACE(REPLACE(lm.manuscript_title, CHAR(13), ' '), CHAR(10), ' ')), 255) AS manuscript_title,
  NULLIF(TRIM(lm.other_details), '') AS other_details,
  NULLIF(TRIM(lm.adviser_name), '') AS adviser_name,
  NULLIF(TRIM(lm.panelists), '') AS panelists,
  NULLIF(TRIM(lm.statisticians), '') AS statisticians,
  NULLIF(TRIM(lm.abstract_file_path), '') AS abstract_file_path,
  NULLIF(TRIM(lm.abstract_original_name), '') AS abstract_original_name,
  COALESCE(lm.created_at, CURRENT_TIMESTAMP) AS created_at,
  COALESCE(lm.updated_at, CURRENT_TIMESTAMP) AS updated_at,
  CASE
    WHEN adviser.accountid IS NOT NULL THEN lm.adviser_accountid
    ELSE NULL
  END AS adviser_accountid,
  NULLIF(TRIM(lm.panelist_accountids), '') AS panelist_accountids,
  CASE
    WHEN statistician.accountid IS NOT NULL THEN lm.statistician_accountid
    ELSE NULL
  END AS statistician_accountid,
  CASE
    WHEN critic.accountid IS NOT NULL THEN lm.english_critic_accountid
    ELSE NULL
  END AS english_critic_accountid,
  NULLIF(TRIM(lm.authors), '') AS authors
FROM legacy_tblmanuscripts lm
LEFT JOIN legacy_researchtype_map rtm
  ON rtm.legacy_typeid = lm.researchtypeid
LEFT JOIN tblaccount adviser
  ON adviser.accountid = lm.adviser_accountid
LEFT JOIN tblaccount statistician
  ON statistician.accountid = lm.statistician_accountid
LEFT JOIN tblaccount critic
  ON critic.accountid = lm.english_critic_accountid
LEFT JOIN tblmanuscripts existing
  ON existing.manuscriptid = lm.manuscriptid
WHERE existing.manuscriptid IS NULL;

-- Import legacy research rows as base manuscript records.
-- These rows do not have adviser/panelist/statistician/critic/abstract data in the old table.
-- Legacy-only fields are preserved inside other_details.
INSERT INTO tblmanuscripts (
  manuscriptid,
  researchtypeid,
  sdg_code,
  manuscript_title,
  other_details,
  adviser_name,
  panelists,
  statisticians,
  abstract_file_path,
  abstract_original_name,
  created_at,
  updated_at,
  adviser_accountid,
  panelist_accountids,
  statistician_accountid,
  english_critic_accountid,
  authors
)
SELECT
  lr.titleid AS manuscriptid,
  COALESCE(rtm.new_researchtypeid, 4) AS researchtypeid,
  NULLIF(TRIM(lr.sdgs), '') AS sdg_code,
  LEFT(TRIM(REPLACE(REPLACE(lr.title, CHAR(13), ' '), CHAR(10), ' ')), 255) AS manuscript_title,
  NULLIF(
    CONCAT_WS(
      '\n',
      CONCAT('Legacy status: ', NULLIF(TRIM(lr.status), '')),
      CONCAT('Legacy campusid: ', lr.campusid),
      CONCAT('Legacy ayid: ', lr.ayid),
      CONCAT('Legacy encoder: ', lr.encoder),
      CONCAT('Legacy programid: ', lr.programid)
    ),
    ''
  ) AS other_details,
  NULL AS adviser_name,
  NULL AS panelists,
  NULL AS statisticians,
  NULL AS abstract_file_path,
  NULL AS abstract_original_name,
  COALESCE(lr.submitted_at, CURRENT_TIMESTAMP) AS created_at,
  COALESCE(lr.updated_at, CURRENT_TIMESTAMP) AS updated_at,
  NULL AS adviser_accountid,
  NULL AS panelist_accountids,
  NULL AS statistician_accountid,
  NULL AS english_critic_accountid,
  NULLIF(TRIM(lr.authors), '') AS authors
FROM legacy_tblresearches lr
LEFT JOIN legacy_researchtype_map rtm
  ON rtm.legacy_typeid = lr.typeid
LEFT JOIN tblmanuscripts existing
  ON existing.manuscriptid = lr.titleid
WHERE existing.manuscriptid IS NULL;

COMMIT;

-- After running this script:
-- 1. Open the administrator manuscript page once.
-- 2. The app will create/backfill tblmanuscript_panelists from panelist_accountids.
-- 3. Review imported other_details values and decide whether campus/program/AY should
--    remain archived there or move into new dedicated tables later.
