<?php

declare(strict_types=1);

final class Database
{
    private static $connection = null;
    private static $accountColumns = null;
    private static $tableColumns = [];

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = env('DB_HOST');
        $port = env('DB_PORT', '3306');
        $database = env('DB_DATABASE');
        $username = env('DB_USERNAME');
        $password = env('DB_PASSWORD', '') ?? '';

        $missing = [];

        foreach ([
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
        ] as $key => $value) {
            if ($value === null || trim($value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing database configuration: ' . implode(', ', $missing) . '.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }

    public static function findAccountByEmail(string $email): ?array
    {
        $normalizedEmail = normalize_email($email);

        if ($normalizedEmail === '') {
            return null;
        }

        $columns = self::accountColumns();

        $statement = self::connection()->prepare(
            'SELECT ' . implode(', ', $columns) . '
             FROM tblaccount
             WHERE REPLACE(LOWER(email), " ", "") = :email
             ORDER BY accountid ASC
             LIMIT 1'
        );

        $statement->execute([
            'email' => $normalizedEmail,
        ]);

        $account = $statement->fetch();

        return is_array($account) ? $account : null;
    }

    public static function findAccountById(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }

        $columns = self::accountColumns();

        $statement = self::connection()->prepare(
            'SELECT ' . implode(', ', $columns) . '
             FROM tblaccount
             WHERE accountid = :accountid
             LIMIT 1'
        );

        $statement->bindValue(':accountid', $accountId, PDO::PARAM_INT);
        $statement->execute();

        $account = $statement->fetch();

        return is_array($account) ? $account : null;
    }

    public static function ensureAccountStatusColumn(): void
    {
        if (self::hasAccountColumn('is_enabled')) {
            return;
        }

        self::connection()->exec(
            'ALTER TABLE tblaccount
             ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER acc_type'
        );

        self::$accountColumns = null;
        unset(self::$tableColumns['tblaccount']);
    }

    public static function ensureResearchTypeTable(): void
    {
        $tableAlreadyExists = self::tableExists('tblresearchtype');

        self::connection()->exec(
            'CREATE TABLE IF NOT EXISTS tblresearchtype (
                researchtypeid INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                research_type VARCHAR(100) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_research_type (research_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        unset(self::$tableColumns['tblresearchtype']);

        if ($tableAlreadyExists) {
            return;
        }

        $seedStatement = self::connection()->prepare(
            'INSERT INTO tblresearchtype (research_type, is_active)
             VALUES (:research_type, 1)'
        );

        foreach (['Thesis', 'Capstone', 'Copyright', 'Other'] as $researchType) {
            $seedStatement->execute([
                'research_type' => $researchType,
            ]);
        }
    }

    public static function ensureAcademicYearTable(): void
    {
        self::connection()->exec(
            'CREATE TABLE IF NOT EXISTS tblacademic_year (
                ayid INT(11) NOT NULL PRIMARY KEY,
                ay_from VARCHAR(19) NOT NULL,
                ay_to VARCHAR(19) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        unset(self::$tableColumns['tblacademic_year']);

        $seedStatement = self::connection()->prepare(
            'INSERT IGNORE INTO tblacademic_year (ayid, ay_from, ay_to)
             VALUES (:ayid, :ay_from, :ay_to)'
        );

        foreach ([
            [11, '2022', '2023'],
            [12, '2023', '2024'],
            [13, '2024', '2025'],
            [14, '2025', '2026'],
        ] as [$academicYearId, $academicYearFrom, $academicYearTo]) {
            $seedStatement->execute([
                'ayid' => $academicYearId,
                'ay_from' => $academicYearFrom,
                'ay_to' => $academicYearTo,
            ]);
        }
    }

    public static function ensureCourseTable(): void
    {
        self::connection()->exec(
            'CREATE TABLE IF NOT EXISTS tblcourse (
                courseid INT(11) NOT NULL PRIMARY KEY,
                coursecode VARCHAR(30) NOT NULL,
                coursedescription VARCHAR(100) NOT NULL,
                coursemajor VARCHAR(50) NOT NULL DEFAULT \'\',
                coursecollege VARCHAR(50) NOT NULL DEFAULT \'\',
                specification VARCHAR(15) NOT NULL DEFAULT \'0\'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        unset(self::$tableColumns['tblcourse']);

        $seedStatement = self::connection()->prepare(
            'INSERT IGNORE INTO tblcourse (
                courseid,
                coursecode,
                coursedescription,
                coursemajor,
                coursecollege,
                specification
             ) VALUES (
                :courseid,
                :coursecode,
                :coursedescription,
                :coursemajor,
                :coursecollege,
                :specification
             )'
        );

        foreach ([
            [87, 'BSCE', 'BACHELOR OF SCIENCE IN CIVIL ENGINEERING', 'STRUCTURAL ENGINEERING', '9', '1'],
            [88, 'BSECE', 'BACHELOR OF SCIENCE IN ELECTRONICS ENGINEERING', '', '9', '1'],
            [89, 'BSCpE', 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING', '', '9', '0'],
            [90, 'BSCS', 'BACHELOR OF SCIENCE IN COMPUTER SCIENCE', '', '4', '0'],
            [91, 'BSIT', 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY', '', '4', '0'],
            [92, 'BSIS', 'BACHELOR OF SCIENCE IN INFORMATION SYSTEMS', '', '4', '0'],
            [93, 'BSIT-AT', 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY', 'AUTOMOTIVE TECHNOLOGY', '16', '0'],
            [94, 'BSIT-CT', 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY', 'CIVIL TECHNOLOGY', '16', '0'],
            [95, 'BSIT-DT', 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY', 'DRAFTING TECHNOLOGY', '16', '0'],
            [96, 'BSIT-ELECTRONICS', 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY', 'ELECTRONICS TECHNOLOGY', '16', '0'],
            [97, 'BSIT-ELECTRICAL', 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY', 'ELECTRICAL TECHNOLOGY', '16', '0'],
            [98, 'BSIT-CULINARY', 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY', 'CULINARY TECHNOLOGY', '16', '0'],
            [99, 'BTVTE-AUTOMOTIVE', 'BACHELOR OF TECHNICAL-VOCATIONAL TEACHER EDUCATION', 'AUTOMOTIVE TECHNOLOGY', '16', '1'],
            [100, 'BTVTE-CIVIL', 'BACHELOR OF TECHNICAL-VOCATIONAL TEACHER EDUCATION', 'CIVIL TECHNOLOGY', '16', '1'],
            [101, 'BTVTE-DRAFTING', 'BACHELOR OF TECHNICAL-VOCATIONAL TEACHER EDUCATION', 'DRAFTING TECHNOLOGY', '16', '1'],
            [102, 'BTVTE-ELECTRONICS', 'BACHELOR OF TECHNICAL-VOCATIONAL TEACHER EDUCATION', 'ELECTRONICS TECHNOLOGY', '16', '1'],
            [103, 'BTVTE-ELECTRICAL', 'BACHELOR OF TECHNICAL-VOCATIONAL TEACHER EDUCATION', 'ELECTRICAL TECHNOLOGY', '16', '1'],
            [104, 'BTVTE-FSM', 'BACHELOR OF TECHNICAL-VOCATIONAL TEACHER EDUCATION', 'FOOD AND SERVICE MANAGEMENT', '16', '1'],
        ] as [$courseId, $courseCode, $courseDescription, $courseMajor, $courseCollege, $specification]) {
            $seedStatement->execute([
                'courseid' => $courseId,
                'coursecode' => $courseCode,
                'coursedescription' => $courseDescription,
                'coursemajor' => $courseMajor,
                'coursecollege' => $courseCollege,
                'specification' => $specification,
            ]);
        }
    }

    public static function ensureManuscriptTable(): void
    {
        self::ensureResearchTypeTable();
        self::ensureAccountStatusColumn();
        self::ensureAcademicYearTable();
        self::ensureCourseTable();

        $accountIdType = self::columnType('tblaccount', 'accountid') ?? 'INT';

        self::connection()->exec(
            'CREATE TABLE IF NOT EXISTS tblresearches (
                titleid INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title TEXT NOT NULL,
                typeid INT NOT NULL,
                campusid INT NOT NULL,
                ayid INT NULL,
                authors TEXT NULL,
                sdgs VARCHAR(100) NOT NULL,
                status VARCHAR(30) NULL DEFAULT \'Pending\',
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                encoder ' . $accountIdType . ' NULL,
                programid INT NULL,
                other_details TEXT NULL,
                adviser_name VARCHAR(150) NULL,
                panelists TEXT NULL,
                statisticians TEXT NULL,
                english_critic_name VARCHAR(150) NULL,
                abstract_file_path VARCHAR(255) NULL,
                abstract_original_name VARCHAR(255) NULL,
                adviser_accountid ' . $accountIdType . ' NULL,
                panelist_accountids VARCHAR(255) NULL,
                statistician_accountid ' . $accountIdType . ' NULL,
                english_critic_accountid ' . $accountIdType . ' NULL,
                KEY idx_tblresearches_typeid (typeid),
                KEY idx_tblresearches_sdgs (sdgs),
                KEY idx_tblresearches_submitted_at (submitted_at),
                KEY idx_tblresearches_campusid (campusid),
                KEY idx_tblresearches_status (status),
                KEY idx_tblresearches_encoder (encoder),
                KEY idx_tblresearches_adviser_accountid (adviser_accountid),
                KEY idx_tblresearches_statistician_accountid (statistician_accountid),
                KEY idx_tblresearches_english_critic_accountid (english_critic_accountid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        unset(self::$tableColumns['tblresearches']);

        $titleIdColumn = self::columnMeta('tblresearches', 'titleid');
        $titleIdType = strtolower(trim((string) ($titleIdColumn['Type'] ?? '')));
        $titleIdAllowsNull = strtoupper((string) ($titleIdColumn['Null'] ?? '')) === 'YES';
        $titleIdExtra = strtolower(trim((string) ($titleIdColumn['Extra'] ?? '')));

        if ($titleIdType !== 'int(10) unsigned' || $titleIdAllowsNull || $titleIdExtra !== 'auto_increment') {
            self::connection()->exec(
                'ALTER TABLE tblresearches
                 MODIFY COLUMN titleid INT(10) UNSIGNED NOT NULL AUTO_INCREMENT'
            );
            unset(self::$tableColumns['tblresearches']);
        }

        self::ensureColumnType('tblresearches', 'authors', 'TEXT', true);
        self::ensureColumnType('tblresearches', 'sdgs', 'VARCHAR(100)', false);
        self::ensureColumnType('tblresearches', 'encoder', $accountIdType, true);

        $ayIdType = self::columnType('tblresearches', 'ayid') ?? 'INT';
        $programIdType = self::columnType('tblresearches', 'programid') ?? 'INT';
        self::ensureColumnType('tblresearches', 'ayid', $ayIdType, true);
        self::ensureColumnType('tblresearches', 'programid', $programIdType, true);

        $researchColumns = self::tableColumns('tblresearches');

        $additionalColumns = [
            'other_details' => 'ALTER TABLE tblresearches ADD COLUMN other_details TEXT NULL',
            'adviser_name' => 'ALTER TABLE tblresearches ADD COLUMN adviser_name VARCHAR(150) NULL',
            'panelists' => 'ALTER TABLE tblresearches ADD COLUMN panelists TEXT NULL',
            'statisticians' => 'ALTER TABLE tblresearches ADD COLUMN statisticians TEXT NULL',
            'english_critic_name' => 'ALTER TABLE tblresearches ADD COLUMN english_critic_name VARCHAR(150) NULL',
            'abstract_file_path' => 'ALTER TABLE tblresearches ADD COLUMN abstract_file_path VARCHAR(255) NULL',
            'abstract_original_name' => 'ALTER TABLE tblresearches ADD COLUMN abstract_original_name VARCHAR(255) NULL',
            'adviser_accountid' => 'ALTER TABLE tblresearches ADD COLUMN adviser_accountid ' . $accountIdType . ' NULL',
            'panelist_accountids' => 'ALTER TABLE tblresearches ADD COLUMN panelist_accountids VARCHAR(255) NULL',
            'statistician_accountid' => 'ALTER TABLE tblresearches ADD COLUMN statistician_accountid ' . $accountIdType . ' NULL',
            'english_critic_accountid' => 'ALTER TABLE tblresearches ADD COLUMN english_critic_accountid ' . $accountIdType . ' NULL',
        ];

        foreach ($additionalColumns as $column => $statement) {
            if (in_array($column, $researchColumns, true)) {
                continue;
            }

            self::connection()->exec($statement);
            unset(self::$tableColumns['tblresearches']);
            $researchColumns = self::tableColumns('tblresearches');
        }

        foreach (['adviser_accountid', 'statistician_accountid', 'english_critic_accountid'] as $accountColumn) {
            self::ensureColumnType('tblresearches', $accountColumn, $accountIdType, true);
        }

        self::connection()->exec(
            'UPDATE tblresearches m
             LEFT JOIN tblaccount a ON a.accountid = m.encoder
             SET m.encoder = NULL
             WHERE m.encoder IS NOT NULL
               AND a.accountid IS NULL'
        );
        self::connection()->exec(
            'UPDATE tblresearches m
             LEFT JOIN tblaccount a ON a.accountid = m.adviser_accountid
             SET m.adviser_accountid = NULL
             WHERE m.adviser_accountid IS NOT NULL
               AND a.accountid IS NULL'
        );
        self::connection()->exec(
            'UPDATE tblresearches m
             LEFT JOIN tblaccount a ON a.accountid = m.statistician_accountid
             SET m.statistician_accountid = NULL
             WHERE m.statistician_accountid IS NOT NULL
               AND a.accountid IS NULL'
        );
        self::connection()->exec(
            'UPDATE tblresearches m
             LEFT JOIN tblaccount a ON a.accountid = m.english_critic_accountid
             SET m.english_critic_accountid = NULL
             WHERE m.english_critic_accountid IS NOT NULL
               AND a.accountid IS NULL'
        );

        self::ensureIndex('tblresearches', 'idx_tblresearches_typeid', 'typeid');
        self::ensureIndex('tblresearches', 'idx_tblresearches_sdgs', 'sdgs');
        self::ensureIndex('tblresearches', 'idx_tblresearches_submitted_at', 'submitted_at');
        self::ensureIndex('tblresearches', 'idx_tblresearches_campusid', 'campusid');
        self::ensureIndex('tblresearches', 'idx_tblresearches_status', 'status');
        self::ensureIndex('tblresearches', 'idx_tblresearches_encoder', 'encoder');
        self::ensureIndex('tblresearches', 'idx_tblresearches_adviser_accountid', 'adviser_accountid');
        self::ensureIndex('tblresearches', 'idx_tblresearches_statistician_accountid', 'statistician_accountid');
        self::ensureIndex('tblresearches', 'idx_tblresearches_english_critic_accountid', 'english_critic_accountid');

        self::ensureForeignKey(
            'tblresearches',
            'fk_tblresearches_encoder_account',
            'encoder',
            'tblaccount',
            'accountid',
            'SET NULL'
        );
        self::ensureForeignKey(
            'tblresearches',
            'fk_tblresearches_adviser_account',
            'adviser_accountid',
            'tblaccount',
            'accountid',
            'SET NULL'
        );
        self::ensureForeignKey(
            'tblresearches',
            'fk_tblresearches_statistician_account',
            'statistician_accountid',
            'tblaccount',
            'accountid',
            'SET NULL'
        );
        self::ensureForeignKey(
            'tblresearches',
            'fk_tblresearches_english_critic_account',
            'english_critic_accountid',
            'tblaccount',
            'accountid',
            'SET NULL'
        );

        self::ensureManuscriptPanelistTable();

        unset(self::$tableColumns['tblresearches']);
    }

    public static function hasAccountColumn(string $column): bool
    {
        return in_array($column, self::tableColumns('tblaccount'), true);
    }

    private static function tableExists(string $table): bool
    {
        $statement = self::connection()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table'
        );
        $statement->execute([
            'table' => $table,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function accountColumns(): array
    {
        if (is_array(self::$accountColumns)) {
            return self::$accountColumns;
        }

        $availableColumns = self::tableColumns('tblaccount');

        $preferredColumns = [
            'accountid',
            'acc_name',
            'email',
            'acc_type',
            'is_enabled',
            'campus',
            'programid',
        ];

        $selectedColumns = [];

        foreach ($preferredColumns as $column) {
            if (in_array($column, $availableColumns, true)) {
                $selectedColumns[] = $column;
            }
        }

        if ($selectedColumns === []) {
            throw new RuntimeException('tblaccount does not contain the expected account columns.');
        }

        self::$accountColumns = $selectedColumns;

        return self::$accountColumns;
    }

    private static function ensureManuscriptPanelistTable(): void
    {
        $manuscriptIdType = self::columnType('tblresearches', 'titleid') ?? 'INT(10) UNSIGNED';
        $accountIdType = self::columnType('tblaccount', 'accountid') ?? 'INT';

        self::connection()->exec(
            'CREATE TABLE IF NOT EXISTS tblmanuscript_panelists (
                manuscript_panelistid INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                manuscriptid ' . $manuscriptIdType . ' NOT NULL,
                accountid ' . $accountIdType . ' NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::ensureColumnType('tblmanuscript_panelists', 'manuscriptid', $manuscriptIdType, false);
        self::ensureColumnType('tblmanuscript_panelists', 'accountid', $accountIdType, false);

        self::connection()->exec(
            'DELETE mp
             FROM tblmanuscript_panelists mp
             LEFT JOIN tblresearches r ON r.titleid = mp.manuscriptid
             WHERE r.titleid IS NULL'
        );
        self::connection()->exec(
            'DELETE mp
             FROM tblmanuscript_panelists mp
             LEFT JOIN tblaccount a ON a.accountid = mp.accountid
             WHERE a.accountid IS NULL'
        );

        self::ensureIndex(
            'tblmanuscript_panelists',
            'uniq_tblmanuscript_panelists_pair',
            'manuscriptid, accountid',
            true
        );
        self::ensureIndex(
            'tblmanuscript_panelists',
            'idx_tblmanuscript_panelists_accountid',
            'accountid'
        );

        self::ensureForeignKey(
            'tblmanuscript_panelists',
            'fk_tblmanuscript_panelists_manuscript',
            'manuscriptid',
            'tblresearches',
            'titleid',
            'CASCADE'
        );
        self::ensureForeignKey(
            'tblmanuscript_panelists',
            'fk_tblmanuscript_panelists_account',
            'accountid',
            'tblaccount',
            'accountid',
            'CASCADE'
        );

        $rows = self::connection()->query(
            "SELECT titleid AS manuscriptid, panelist_accountids
             FROM tblresearches
             WHERE TRIM(COALESCE(panelist_accountids, '')) <> ''"
        )->fetchAll();

        if ($rows === []) {
            return;
        }

        $validAccountIds = array_map(
            'intval',
            self::connection()->query('SELECT accountid FROM tblaccount')->fetchAll(PDO::FETCH_COLUMN)
        );
        $validAccountMap = array_fill_keys($validAccountIds, true);
        $insertStatement = self::connection()->prepare(
            'INSERT IGNORE INTO tblmanuscript_panelists (manuscriptid, accountid)
             VALUES (:manuscriptid, :accountid)'
        );

        foreach ($rows as $row) {
            $manuscriptId = isset($row['manuscriptid']) ? (int) $row['manuscriptid'] : 0;
            $rawPanelistIds = preg_split('/\s*,\s*/', trim((string) ($row['panelist_accountids'] ?? '')));
            $rawPanelistIds = is_array($rawPanelistIds) ? $rawPanelistIds : [];

            if ($manuscriptId < 1) {
                continue;
            }

            foreach ($rawPanelistIds as $rawPanelistId) {
                $normalizedPanelistId = trim((string) $rawPanelistId);

                if ($normalizedPanelistId === '' || !ctype_digit($normalizedPanelistId)) {
                    continue;
                }

                $panelistId = (int) $normalizedPanelistId;

                if ($panelistId < 1 || !isset($validAccountMap[$panelistId])) {
                    continue;
                }

                $insertStatement->bindValue(':manuscriptid', $manuscriptId, PDO::PARAM_INT);
                $insertStatement->bindValue(':accountid', $panelistId, PDO::PARAM_INT);
                $insertStatement->execute();
            }
        }
    }

    private static function ensureColumnType(string $table, string $column, string $type, bool $nullable): void
    {
        $columnMeta = self::columnMeta($table, $column);

        if (!is_array($columnMeta)) {
            return;
        }

        $currentType = strtolower(trim((string) ($columnMeta['Type'] ?? '')));
        $targetType = strtolower(trim($type));
        $isNullable = strtoupper((string) ($columnMeta['Null'] ?? '')) === 'YES';

        if ($currentType === $targetType && $isNullable === $nullable) {
            return;
        }

        self::connection()->exec(
            'ALTER TABLE ' . $table . '
             MODIFY COLUMN ' . $column . ' ' . $type . ' ' . ($nullable ? 'NULL' : 'NOT NULL')
        );

        unset(self::$tableColumns[$table]);
    }

    private static function ensureIndex(string $table, string $indexName, string $columns, bool $unique = false): void
    {
        if (self::indexExists($table, $indexName)) {
            return;
        }

        self::connection()->exec(
            'ALTER TABLE ' . $table . '
             ADD ' . ($unique ? 'UNIQUE KEY ' : 'KEY ') . $indexName . ' (' . $columns . ')'
        );
    }

    private static function ensureForeignKey(
        string $table,
        string $constraintName,
        string $column,
        string $referenceTable,
        string $referenceColumn,
        string $onDelete
    ): void {
        $existingReferenceTable = self::foreignKeyReferenceTable($table, $constraintName);

        if ($existingReferenceTable !== null) {
            if (strcasecmp($existingReferenceTable, $referenceTable) === 0) {
                return;
            }

            self::connection()->exec(
                'ALTER TABLE ' . $table . '
                 DROP FOREIGN KEY ' . $constraintName
            );
        } elseif (self::constraintExists($table, $constraintName)) {
            return;
        }

        self::connection()->exec(
            'ALTER TABLE ' . $table . '
             ADD CONSTRAINT ' . $constraintName . '
             FOREIGN KEY (' . $column . ') REFERENCES ' . $referenceTable . ' (' . $referenceColumn . ')
             ON DELETE ' . $onDelete . '
             ON UPDATE CASCADE'
        );
    }

    private static function columnMeta(string $table, string $column): ?array
    {
        $columnMeta = self::connection()
            ->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . self::connection()->quote($column))
            ->fetch();

        return is_array($columnMeta) ? $columnMeta : null;
    }

    private static function columnType(string $table, string $column): ?string
    {
        $columnMeta = self::columnMeta($table, $column);

        if (!is_array($columnMeta)) {
            return null;
        }

        $type = trim((string) ($columnMeta['Type'] ?? ''));

        return $type !== '' ? $type : null;
    }

    private static function indexExists(string $table, string $indexName): bool
    {
        $statement = self::connection()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name'
        );
        $statement->execute([
            'table' => $table,
            'index_name' => $indexName,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function foreignKeyReferenceTable(string $table, string $constraintName): ?string
    {
        $statement = self::connection()->prepare(
            'SELECT REFERENCED_TABLE_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = :constraint_name
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1'
        );
        $statement->execute([
            'table' => $table,
            'constraint_name' => $constraintName,
        ]);

        $referenceTable = $statement->fetchColumn();

        return is_string($referenceTable) && $referenceTable !== '' ? $referenceTable : null;
    }

    private static function constraintExists(string $table, string $constraintName): bool
    {
        $statement = self::connection()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = :constraint_name'
        );
        $statement->execute([
            'table' => $table,
            'constraint_name' => $constraintName,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function tableColumns(string $table): array
    {
        if (isset(self::$tableColumns[$table]) && is_array(self::$tableColumns[$table])) {
            return self::$tableColumns[$table];
        }

        $availableColumns = [];

        foreach (self::connection()->query('DESCRIBE ' . $table) as $column) {
            if (isset($column['Field'])) {
                $availableColumns[] = $column['Field'];
            }
        }

        self::$tableColumns[$table] = $availableColumns;

        return self::$tableColumns[$table];
    }
}
