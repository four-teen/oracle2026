-- Manual migration: legacy tblresearches -> current tblmanuscripts
-- Current DB confirmed:
--   campus 2 = ISULAN
--   colleges under campus 2 = 4 (Computer Studies), 9 (Engineering), 16 (Industrial Technology)
-- Legacy dump confirmed:
--   campusid = 2 for all rows
--   programid values = 91, 92
--   typeid = 1 for all rows
--
-- Important:
-- Do not guess college mapping unless you already know:
--   legacy programid 91 -> current collegeid ?
--   legacy programid 92 -> current collegeid ?
--
-- If you do not know the college mapping yet, keep collegeid NULL first.

START TRANSACTION;

-- 1. Backup current data first.
CREATE TABLE IF NOT EXISTS backup_tblmanuscripts_20260318 AS
SELECT * FROM tblmanuscripts WHERE 1 = 0;

INSERT INTO backup_tblmanuscripts_20260318
SELECT * FROM tblmanuscripts;

CREATE TABLE IF NOT EXISTS backup_tblmanuscript_panelists_20260318 AS
SELECT * FROM tblmanuscript_panelists WHERE 1 = 0;

INSERT INTO backup_tblmanuscript_panelists_20260318
SELECT * FROM tblmanuscript_panelists;

COMMIT;

-- 2. Add campus and college columns to tblmanuscripts.
-- Run only once.
ALTER TABLE tblmanuscripts
  ADD COLUMN campusid INT(11) NULL AFTER researchtypeid,
  ADD COLUMN collegeid INT(11) NULL AFTER campusid;

ALTER TABLE tblmanuscripts
  ADD KEY idx_tblmanuscripts_campusid (campusid),
  ADD KEY idx_tblmanuscripts_collegeid (collegeid);

ALTER TABLE tblmanuscripts
  ADD CONSTRAINT fk_tblmanuscripts_campus
    FOREIGN KEY (campusid) REFERENCES tblcampus (campusid)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  ADD CONSTRAINT fk_tblmanuscripts_college
    FOREIGN KEY (collegeid) REFERENCES tblcollege (collegeid)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

-- 3. Create staging table for the old tblresearches data.
DROP TABLE IF EXISTS legacy_tblresearches;

CREATE TABLE legacy_tblresearches (
  titleid INT(11) NOT NULL,
  title TEXT NOT NULL,
  typeid INT(11) NOT NULL,
  campusid INT(11) NOT NULL,
  ayid INT(11) NOT NULL,
  authors VARCHAR(500) NOT NULL,
  sdgs VARCHAR(50) NOT NULL,
  status VARCHAR(30) DEFAULT 'Pending',
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  encoder INT(11) NOT NULL,
  programid INT(11) NOT NULL,
  PRIMARY KEY (titleid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Import the contents of the old file into legacy_tblresearches.
-- In phpMyAdmin or your editor:
--   open tblresearches (2).sql
--   replace: CREATE TABLE `tblresearches`
--   with:    CREATE TABLE `legacy_tblresearches`
--   replace: INSERT INTO `tblresearches`
--   with:    INSERT INTO `legacy_tblresearches`
--   then run/import that edited SQL

-- 5. Check what you imported.
SELECT campusid, programid, COUNT(*) AS total_rows
FROM legacy_tblresearches
GROUP BY campusid, programid
ORDER BY campusid, programid;

-- 6A. Safe import first: campus only, college NULL.
-- Use this if you still do not know the college mapping for programid 91 and 92.
INSERT INTO tblmanuscripts (
  manuscriptid,
  researchtypeid,
  campusid,
  collegeid,
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
  1 AS researchtypeid,
  CASE
    WHEN campus.campusid IS NOT NULL THEN lr.campusid
    ELSE NULL
  END AS campusid,
  NULL AS collegeid,
  NULLIF(TRIM(lr.sdgs), '') AS sdg_code,
  LEFT(TRIM(REPLACE(REPLACE(lr.title, CHAR(13), ' '), CHAR(10), ' ')), 255) AS manuscript_title,
  NULLIF(
    CONCAT_WS(
      '\n',
      CONCAT('Legacy status: ', NULLIF(TRIM(lr.status), '')),
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
LEFT JOIN tblcampus campus
  ON campus.campusid = lr.campusid
LEFT JOIN tblmanuscripts existing
  ON existing.manuscriptid = lr.titleid
WHERE existing.manuscriptid IS NULL;

-- 6B. If you already know the college mapping, use this instead of 6A.
-- Example mapping only:
--   if both 91 and 92 belong to College of Computer Studies, use collegeid = 4.
--
-- DROP TEMPORARY TABLE IF EXISTS legacy_program_college_map;
-- CREATE TEMPORARY TABLE legacy_program_college_map (
--   legacy_programid INT NOT NULL PRIMARY KEY,
--   current_collegeid INT NOT NULL
-- );
--
-- INSERT INTO legacy_program_college_map (legacy_programid, current_collegeid) VALUES
--   (91, 4),
--   (92, 4);
--
-- INSERT INTO tblmanuscripts (
--   manuscriptid,
--   researchtypeid,
--   campusid,
--   collegeid,
--   sdg_code,
--   manuscript_title,
--   other_details,
--   adviser_name,
--   panelists,
--   statisticians,
--   abstract_file_path,
--   abstract_original_name,
--   created_at,
--   updated_at,
--   adviser_accountid,
--   panelist_accountids,
--   statistician_accountid,
--   english_critic_accountid,
--   authors
-- )
-- SELECT
--   lr.titleid AS manuscriptid,
--   1 AS researchtypeid,
--   CASE
--     WHEN campus.campusid IS NOT NULL THEN lr.campusid
--     ELSE NULL
--   END AS campusid,
--   CASE
--     WHEN college.collegeid IS NOT NULL
--       AND CAST(college.collegecampus AS UNSIGNED) = lr.campusid
--     THEN college.collegeid
--     ELSE NULL
--   END AS collegeid,
--   NULLIF(TRIM(lr.sdgs), '') AS sdg_code,
--   LEFT(TRIM(REPLACE(REPLACE(lr.title, CHAR(13), ' '), CHAR(10), ' ')), 255) AS manuscript_title,
--   NULLIF(
--     CONCAT_WS(
--       '\n',
--       CONCAT('Legacy status: ', NULLIF(TRIM(lr.status), '')),
--       CONCAT('Legacy ayid: ', lr.ayid),
--       CONCAT('Legacy encoder: ', lr.encoder),
--       CONCAT('Legacy programid: ', lr.programid)
--     ),
--     ''
--   ) AS other_details,
--   NULL AS adviser_name,
--   NULL AS panelists,
--   NULL AS statisticians,
--   NULL AS abstract_file_path,
--   NULL AS abstract_original_name,
--   COALESCE(lr.submitted_at, CURRENT_TIMESTAMP) AS created_at,
--   COALESCE(lr.updated_at, CURRENT_TIMESTAMP) AS updated_at,
--   NULL AS adviser_accountid,
--   NULL AS panelist_accountids,
--   NULL AS statistician_accountid,
--   NULL AS english_critic_accountid,
--   NULLIF(TRIM(lr.authors), '') AS authors
-- FROM legacy_tblresearches lr
-- LEFT JOIN tblcampus campus
--   ON campus.campusid = lr.campusid
-- LEFT JOIN legacy_program_college_map pcm
--   ON pcm.legacy_programid = lr.programid
-- LEFT JOIN tblcollege college
--   ON college.collegeid = pcm.current_collegeid
-- LEFT JOIN tblmanuscripts existing
--   ON existing.manuscriptid = lr.titleid
-- WHERE existing.manuscriptid IS NULL;

-- 7. Verify imported records.
SELECT manuscriptid, researchtypeid, campusid, collegeid, manuscript_title
FROM tblmanuscripts
ORDER BY manuscriptid DESC
LIMIT 20;

-- 8. If you imported using 6A, update college later after you confirm the mapping.
-- Example only:
-- UPDATE tblmanuscripts
-- SET collegeid = 4
-- WHERE campusid = 2
--   AND manuscriptid IN (
--     SELECT titleid
--     FROM legacy_tblresearches
--     WHERE programid IN (91, 92)
--   );

-- 9. If you ever need to roll back only the imported legacy records:
-- DELETE FROM tblmanuscripts
-- WHERE manuscriptid IN (SELECT titleid FROM legacy_tblresearches);
