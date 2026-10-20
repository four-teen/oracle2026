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
