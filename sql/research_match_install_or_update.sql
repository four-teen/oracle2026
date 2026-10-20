-- Research Match: install missing tables, add the two later columns, then verify.
-- Select the intended database in phpMyAdmin before importing this entire file.
-- Existing tables/data are retained. Re-running skips tables/columns already present.
-- A verification CHECK result identifies an older or incompatible definition requiring attention.

-- Shared Research Match collection. Snapshots deliberately do not reference live manuscripts.
CREATE TABLE IF NOT EXISTS tblresearchmatch_document (
    document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    content_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    titleid BIGINT UNSIGNED NOT NULL,
    title MEDIUMTEXT NOT NULL,
    abstract_text MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rm_document_hash (content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblresearchmatch_batch (
    batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_key CHAR(64) CHARACTER SET ascii NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    algorithm_version VARCHAR(64) NOT NULL,
    catalog_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    catalog_count INT UNSIGNED NOT NULL,
    expected_comparisons BIGINT UNSIGNED NOT NULL,
    processed_comparisons BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_rm_batch_request (request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblresearchmatch_batch_document (
    batch_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (batch_id, position),
    CONSTRAINT fk_rm_batch_document_batch FOREIGN KEY (batch_id) REFERENCES tblresearchmatch_batch (batch_id),
    CONSTRAINT fk_rm_batch_document_snapshot FOREIGN KEY (document_id) REFERENCES tblresearchmatch_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblresearchmatch_run (
    run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_key CHAR(64) CHARACTER SET ascii NULL,
    source_kind VARCHAR(32) NOT NULL,
    comparison_mode VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL,
    query_text TEXT NULL,
    normalized_query TEXT NULL,
    query_hash CHAR(64) CHARACTER SET ascii NULL,
    source_document_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    source_position INT UNSIGNED NULL,
    next_target_position INT UNSIGNED NOT NULL DEFAULT 0,
    algorithm_version VARCHAR(64) NOT NULL,
    catalog_hash CHAR(64) CHARACTER SET ascii NOT NULL,
    catalog_count INT UNSIGNED NOT NULL,
    result_count INT UNSIGNED NOT NULL DEFAULT 0,
    result_limit SMALLINT UNSIGNED NULL,
    summary_json LONGTEXT NULL,
    response_json LONGTEXT NULL,
    cached_from_run_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_rm_run_request (request_key),
    UNIQUE KEY uq_rm_run_batch_source (batch_id, source_position),
    KEY idx_rm_run_batch_status (batch_id, status, source_position),
    KEY idx_rm_run_source (source_kind, run_id),
    KEY idx_rm_run_mode (comparison_mode, run_id),
    KEY idx_rm_run_cache (catalog_hash, algorithm_version, query_hash, result_limit, completed_at),
    CONSTRAINT fk_rm_run_source FOREIGN KEY (source_document_id) REFERENCES tblresearchmatch_document (document_id),
    CONSTRAINT fk_rm_run_batch FOREIGN KEY (batch_id) REFERENCES tblresearchmatch_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblresearchmatch_result (
    result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    target_document_id BIGINT UNSIGNED NOT NULL,
    rank_order INT UNSIGNED NULL,
    basis VARCHAR(24) NOT NULL,
    title_score DOUBLE NOT NULL,
    abstract_score DOUBLE NULL,
    overall_score DOUBLE NOT NULL,
    calculation_json LONGTEXT NOT NULL,
    reused_from_result_id BIGINT UNSIGNED NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rm_result_target (run_id, target_document_id),
    KEY idx_rm_result_rank (run_id, rank_order),
    KEY idx_rm_result_score (run_id, overall_score),
    CONSTRAINT fk_rm_result_run FOREIGN KEY (run_id) REFERENCES tblresearchmatch_run (run_id),
    CONSTRAINT fk_rm_result_target FOREIGN KEY (target_document_id) REFERENCES tblresearchmatch_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tblresearchmatch_review (
    review_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    result_id BIGINT UNSIGNED NOT NULL,
    reviewer_accountid INT NOT NULL,
    reviewer_name VARCHAR(255) NOT NULL,
    expert_label VARCHAR(30) NOT NULL,
    review_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rm_review (result_id, reviewer_accountid),
    CONSTRAINT fk_rm_review_result FOREIGN KEY (result_id) REFERENCES tblresearchmatch_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add the two later columns only when missing. Existing columns are skipped.
-- PREPARE works with MySQL and MariaDB without stored-procedure privileges.
SET @rm_upgrade_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tblresearchmatch_run' AND COLUMN_NAME = 'summary_json'),
    'SELECT ''summary_json already exists - skipped'' AS migration_status',
    'ALTER TABLE `tblresearchmatch_run` ADD COLUMN `summary_json` LONGTEXT NULL AFTER `result_limit`'
);
PREPARE rm_upgrade_statement FROM @rm_upgrade_sql;
EXECUTE rm_upgrade_statement;
DEALLOCATE PREPARE rm_upgrade_statement;

SET @rm_upgrade_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'tblresearchmatch_result' AND COLUMN_NAME = 'reused_from_result_id'),
    'SELECT ''reused_from_result_id already exists - skipped'' AS migration_status',
    'ALTER TABLE `tblresearchmatch_result` ADD COLUMN `reused_from_result_id` BIGINT UNSIGNED NULL AFTER `calculation_json`'
);
PREPARE rm_upgrade_statement FROM @rm_upgrade_sql;
EXECUTE rm_upgrade_statement;
DEALLOCATE PREPARE rm_upgrade_statement;

-- Verification is read-only. Each of the six rows must show OK in every status column.
-- Extra unrelated columns/indexes are permitted; required schema definitions are checked.
SELECT DATABASE() AS selected_database;

SELECT e.table_name,
    CASE WHEN t.TABLE_NAME IS NOT NULL AND LOWER(t.ENGINE) = 'innodb'
         AND t.TABLE_COLLATION = 'utf8mb4_unicode_ci' THEN 'OK' ELSE 'CHECK TABLE' END AS table_status,
    CASE WHEN cols.valid_count = cols.required_count THEN 'OK' ELSE 'CHECK COLUMNS' END AS column_status,
    CONCAT(cols.valid_count, '/', cols.required_count) AS required_columns,
    COALESCE(cols.column_issues, '') AS column_issues,
    CASE WHEN indexes.valid_count = indexes.required_count THEN 'OK' ELSE 'CHECK INDEXES' END AS index_status,
    COALESCE(indexes.index_issues, '') AS index_issues,
    CASE WHEN COALESCE(fks.valid_count, 0) = COALESCE(fks.required_count, 0) THEN 'OK' ELSE 'CHECK FOREIGN KEYS' END AS foreign_key_status,
    COALESCE(fks.foreign_key_issues, '') AS foreign_key_issues
FROM (
    SELECT 'tblresearchmatch_document' AS table_name
    UNION ALL SELECT 'tblresearchmatch_batch' AS table_name
    UNION ALL SELECT 'tblresearchmatch_batch_document' AS table_name
    UNION ALL SELECT 'tblresearchmatch_run' AS table_name
    UNION ALL SELECT 'tblresearchmatch_result' AS table_name
    UNION ALL SELECT 'tblresearchmatch_review' AS table_name
) e
LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = e.table_name
LEFT JOIN (
    SELECT e.table_name, COUNT(*) AS required_count,
        SUM(CASE WHEN c.COLUMN_NAME IS NOT NULL AND c.DATA_TYPE = e.data_type
            AND c.IS_NULLABLE = e.nullable
            AND (e.max_length IS NULL OR c.CHARACTER_MAXIMUM_LENGTH = e.max_length)
            AND (LOCATE('unsigned', LOWER(c.COLUMN_TYPE)) > 0) = e.is_unsigned
            AND COALESCE(c.CHARACTER_SET_NAME, '') = e.character_set
            AND (LOCATE('auto_increment', LOWER(c.EXTRA)) > 0) = e.auto_increment_required THEN 1 ELSE 0 END) AS valid_count,
        GROUP_CONCAT(CASE WHEN c.COLUMN_NAME IS NOT NULL AND c.DATA_TYPE = e.data_type
            AND c.IS_NULLABLE = e.nullable
            AND (e.max_length IS NULL OR c.CHARACTER_MAXIMUM_LENGTH = e.max_length)
            AND (LOCATE('unsigned', LOWER(c.COLUMN_TYPE)) > 0) = e.is_unsigned
            AND COALESCE(c.CHARACTER_SET_NAME, '') = e.character_set
            AND (LOCATE('auto_increment', LOWER(c.EXTRA)) > 0) = e.auto_increment_required THEN NULL ELSE e.column_name END ORDER BY e.column_name SEPARATOR ', ') AS column_issues
    FROM (
        SELECT 'tblresearchmatch_document' AS table_name, 'document_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 1 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_document' AS table_name, 'content_hash' AS column_name, 'char' AS data_type, 64 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'ascii' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_document' AS table_name, 'titleid' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_document' AS table_name, 'title' AS column_name, 'mediumtext' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_document' AS table_name, 'abstract_text' AS column_name, 'mediumtext' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_document' AS table_name, 'created_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'batch_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 1 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'request_key' AS column_name, 'char' AS data_type, 64 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'ascii' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'status' AS column_name, 'varchar' AS data_type, 20 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'algorithm_version' AS column_name, 'varchar' AS data_type, 64 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'catalog_hash' AS column_name, 'char' AS data_type, 64 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'ascii' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'catalog_count' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'expected_comparisons' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'processed_comparisons' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'created_by' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'created_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'completed_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch_document' AS table_name, 'batch_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch_document' AS table_name, 'position' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_batch_document' AS table_name, 'document_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'run_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 1 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'request_key' AS column_name, 'char' AS data_type, 64 AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'ascii' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'source_kind' AS column_name, 'varchar' AS data_type, 32 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'comparison_mode' AS column_name, 'varchar' AS data_type, 32 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'status' AS column_name, 'varchar' AS data_type, 20 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'query_text' AS column_name, 'text' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'normalized_query' AS column_name, 'text' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'query_hash' AS column_name, 'char' AS data_type, 64 AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'ascii' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'source_document_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'batch_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'source_position' AS column_name, 'int' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'next_target_position' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'algorithm_version' AS column_name, 'varchar' AS data_type, 64 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'catalog_hash' AS column_name, 'char' AS data_type, 64 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'ascii' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'catalog_count' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'result_count' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'result_limit' AS column_name, 'smallint' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'summary_json' AS column_name, 'longtext' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'response_json' AS column_name, 'longtext' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'cached_from_run_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'created_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'completed_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'result_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 1 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'run_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'target_document_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'rank_order' AS column_name, 'int' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'basis' AS column_name, 'varchar' AS data_type, 24 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'title_score' AS column_name, 'double' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'abstract_score' AS column_name, 'double' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'overall_score' AS column_name, 'double' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'calculation_json' AS column_name, 'longtext' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'reused_from_result_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'YES' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'computed_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'review_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 1 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'result_id' AS column_name, 'bigint' AS data_type, NULL AS max_length, 'NO' AS nullable, 1 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'reviewer_accountid' AS column_name, 'int' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'reviewer_name' AS column_name, 'varchar' AS data_type, 255 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'expert_label' AS column_name, 'varchar' AS data_type, 30 AS max_length, 'NO' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'review_note' AS column_name, 'text' AS data_type, NULL AS max_length, 'YES' AS nullable, 0 AS is_unsigned, 'utf8mb4' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'created_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'updated_at' AS column_name, 'datetime' AS data_type, NULL AS max_length, 'NO' AS nullable, 0 AS is_unsigned, '' AS character_set, 0 AS auto_increment_required
    ) e
    LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE()
        AND c.TABLE_NAME = e.table_name AND c.COLUMN_NAME = e.column_name
    GROUP BY e.table_name
) cols ON cols.table_name = e.table_name
LEFT JOIN (
    SELECT e.table_name, COUNT(*) AS required_count,
        SUM(CASE WHEN a.column_list = e.column_list AND a.non_unique = e.non_unique AND a.has_prefix = 0 THEN 1 ELSE 0 END) AS valid_count,
        GROUP_CONCAT(CASE WHEN a.column_list = e.column_list AND a.non_unique = e.non_unique AND a.has_prefix = 0 THEN NULL ELSE e.index_name END ORDER BY e.index_name SEPARATOR ', ') AS index_issues
    FROM (
        SELECT 'tblresearchmatch_document' AS table_name, 'PRIMARY' AS index_name, 'document_id' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_document' AS table_name, 'uq_rm_document_hash' AS index_name, 'content_hash' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'PRIMARY' AS index_name, 'batch_id' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_batch' AS table_name, 'uq_rm_batch_request' AS index_name, 'request_key' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_batch_document' AS table_name, 'PRIMARY' AS index_name, 'batch_id,position' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'PRIMARY' AS index_name, 'run_id' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'uq_rm_run_request' AS index_name, 'request_key' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'uq_rm_run_batch_source' AS index_name, 'batch_id,source_position' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'idx_rm_run_batch_status' AS index_name, 'batch_id,status,source_position' AS column_list, 1 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'idx_rm_run_source' AS index_name, 'source_kind,run_id' AS column_list, 1 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'idx_rm_run_mode' AS index_name, 'comparison_mode,run_id' AS column_list, 1 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'idx_rm_run_cache' AS index_name, 'catalog_hash,algorithm_version,query_hash,result_limit,completed_at' AS column_list, 1 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'PRIMARY' AS index_name, 'result_id' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'uq_rm_result_target' AS index_name, 'run_id,target_document_id' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'idx_rm_result_rank' AS index_name, 'run_id,rank_order' AS column_list, 1 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'idx_rm_result_score' AS index_name, 'run_id,overall_score' AS column_list, 1 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'PRIMARY' AS index_name, 'review_id' AS column_list, 0 AS non_unique
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'uq_rm_review' AS index_name, 'result_id,reviewer_accountid' AS column_list, 0 AS non_unique
    ) e
    LEFT JOIN (
        SELECT TABLE_NAME, INDEX_NAME, MIN(NON_UNIQUE) AS non_unique,
            MAX(CASE WHEN SUB_PART IS NOT NULL THEN 1 ELSE 0 END) AS has_prefix,
            GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS column_list
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        GROUP BY TABLE_NAME, INDEX_NAME
    ) a ON a.TABLE_NAME = e.table_name AND a.INDEX_NAME = e.index_name
    GROUP BY e.table_name
) indexes ON indexes.table_name = e.table_name
LEFT JOIN (
    SELECT e.table_name, COUNT(*) AS required_count,
        SUM(CASE WHEN k.COLUMN_NAME = e.column_name AND k.REFERENCED_TABLE_NAME = e.referenced_table
            AND k.REFERENCED_COLUMN_NAME = e.referenced_column AND k.REFERENCED_TABLE_SCHEMA = DATABASE() THEN 1 ELSE 0 END) AS valid_count,
        GROUP_CONCAT(CASE WHEN k.COLUMN_NAME = e.column_name AND k.REFERENCED_TABLE_NAME = e.referenced_table
            AND k.REFERENCED_COLUMN_NAME = e.referenced_column AND k.REFERENCED_TABLE_SCHEMA = DATABASE()
            THEN NULL ELSE e.constraint_name END ORDER BY e.constraint_name SEPARATOR ', ') AS foreign_key_issues
    FROM (
        SELECT 'tblresearchmatch_batch_document' AS table_name, 'fk_rm_batch_document_batch' AS constraint_name, 'batch_id' AS column_name, 'tblresearchmatch_batch' AS referenced_table, 'batch_id' AS referenced_column
        UNION ALL SELECT 'tblresearchmatch_batch_document' AS table_name, 'fk_rm_batch_document_snapshot' AS constraint_name, 'document_id' AS column_name, 'tblresearchmatch_document' AS referenced_table, 'document_id' AS referenced_column
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'fk_rm_run_source' AS constraint_name, 'source_document_id' AS column_name, 'tblresearchmatch_document' AS referenced_table, 'document_id' AS referenced_column
        UNION ALL SELECT 'tblresearchmatch_run' AS table_name, 'fk_rm_run_batch' AS constraint_name, 'batch_id' AS column_name, 'tblresearchmatch_batch' AS referenced_table, 'batch_id' AS referenced_column
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'fk_rm_result_run' AS constraint_name, 'run_id' AS column_name, 'tblresearchmatch_run' AS referenced_table, 'run_id' AS referenced_column
        UNION ALL SELECT 'tblresearchmatch_result' AS table_name, 'fk_rm_result_target' AS constraint_name, 'target_document_id' AS column_name, 'tblresearchmatch_document' AS referenced_table, 'document_id' AS referenced_column
        UNION ALL SELECT 'tblresearchmatch_review' AS table_name, 'fk_rm_review_result' AS constraint_name, 'result_id' AS column_name, 'tblresearchmatch_result' AS referenced_table, 'result_id' AS referenced_column
    ) e
    LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = DATABASE()
        AND k.TABLE_NAME = e.table_name AND k.CONSTRAINT_NAME = e.constraint_name
    GROUP BY e.table_name
) fks ON fks.table_name = e.table_name
ORDER BY e.table_name;
