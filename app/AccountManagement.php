<?php

declare(strict_types=1);

final class AccountManagement
{
    public static function roleIds($value, ?int $primaryRole = null): array
    {
        $roleMap = Auth::roleLabels();
        $rawRoles = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
        $rawRoles = is_array($rawRoles) ? $rawRoles : [];

        if ($primaryRole !== null) {
            $rawRoles[] = $primaryRole;
        }

        $roles = [];

        foreach ($rawRoles as $rawRole) {
            if (!is_scalar($rawRole)) {
                continue;
            }

            $normalized = trim((string) $rawRole);

            if ($normalized === '' || !preg_match('/^\d+$/', $normalized)) {
                continue;
            }

            $role = (int) $normalized;

            if (array_key_exists($role, $roleMap)) {
                $roles[$role] = $role;
            }
        }

        return array_values($roles);
    }

    public static function save(
        PDO $pdo,
        array $input,
        bool $isNew,
        int $currentAccountId,
        int $currentSessionRole,
        string $currentLoginEmail
    ): int {
        $accountId = $isNew ? 0 : self::positiveInt($input['accountid'] ?? 0);

        if (!$isNew) {
            $existing = $pdo->prepare('SELECT accountid FROM tblaccount WHERE accountid = :accountid LIMIT 1');
            $existing->execute(['accountid' => $accountId]);

            if (!$existing->fetchColumn()) {
                throw new RuntimeException('The selected account was not found.');
            }
        }

        $name = self::text($input['acc_name'] ?? '');
        $email = self::text($input['email'] ?? '');
        $roleInput = self::text($input['acc_type'] ?? '');
        $roleId = (int) $roleInput;
        $campusId = self::positiveInt($input['campus'] ?? 0);
        $programId = self::positiveInt($input['programid'] ?? 0);

        if ($name === '') {
            throw new RuntimeException('Account name is required.');
        }

        if (self::length($name) > 50) {
            throw new RuntimeException('Account name must be 50 characters or fewer.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }

        if (self::length($email) > 50) {
            throw new RuntimeException('Email must be 50 characters or fewer.');
        }

        if ($roleInput === '' || !ctype_digit($roleInput) || !array_key_exists($roleId, Auth::roleLabels())) {
            throw new RuntimeException('Select a valid role.');
        }

        $roleIds = self::roleIds($input['acc_roles'] ?? [], $roleId);

        if (
            (in_array(Auth::ROLE_RESEARCH_COORDINATOR, $roleIds, true)
                || in_array(Auth::ROLE_DIRECTOR, $roleIds, true))
            && $campusId < 1
        ) {
            throw new RuntimeException('Assign a campus to every Research Coordinator or Director account.');
        }

        if (!$isNew && $accountId === $currentAccountId && !in_array($currentSessionRole, $roleIds, true)) {
            throw new RuntimeException('Keep your current session role assigned to this account before saving.');
        }

        if ($campusId > 0) {
            $campus = $pdo->prepare('SELECT campusid FROM tblcampus WHERE campusid = :campusid LIMIT 1');
            $campus->execute(['campusid' => $campusId]);

            if (!$campus->fetchColumn()) {
                throw new RuntimeException('The selected campus does not exist.');
            }
        }

        if ($programId > 0) {
            $program = $pdo->prepare(
                "SELECT course.courseid,
                        CAST(NULLIF(TRIM(COALESCE(college.collegecampus, '')), '') AS UNSIGNED) AS campusid
                 FROM tblcourse course
                 LEFT JOIN tblcollege college
                   ON college.collegeid = CAST(NULLIF(TRIM(COALESCE(course.coursecollege, '')), '') AS UNSIGNED)
                 WHERE course.courseid = :programid
                 LIMIT 1"
            );
            $program->execute(['programid' => $programId]);
            $programRow = $program->fetch(PDO::FETCH_ASSOC);

            if (!is_array($programRow)) {
                throw new RuntimeException('The selected program does not exist.');
            }

            $programCampusId = self::positiveInt($programRow['campusid'] ?? 0);

            if ($campusId > 0 && $programCampusId > 0 && $programCampusId !== $campusId) {
                throw new RuntimeException('Select a program that belongs to the assigned campus.');
            }
        }

        $normalizedEmail = normalize_email($email);

        if (
            !$isNew && $accountId === $currentAccountId && $currentLoginEmail !== ''
            && $normalizedEmail !== normalize_email($currentLoginEmail)
        ) {
            throw new RuntimeException('You cannot change the login email for the account you are currently signed in with.');
        }

        $duplicate = $pdo->prepare(
            'SELECT COUNT(*) FROM tblaccount
             WHERE REPLACE(LOWER(email), " ", "") = :email AND accountid <> :accountid'
        );
        $duplicate->execute(['email' => $normalizedEmail, 'accountid' => $accountId]);

        if ((int) $duplicate->fetchColumn() > 0) {
            throw new RuntimeException('That email address is already assigned to another account.');
        }

        $sql = $isNew
            ? 'INSERT INTO tblaccount (acc_name, email, acc_type, acc_roles, is_enabled, campus, programid)
               VALUES (:acc_name, :email, :acc_type, :acc_roles, 1, :campus, :programid)'
            : 'UPDATE tblaccount SET acc_name = :acc_name, email = :email, acc_type = :acc_type,
                   acc_roles = :acc_roles, campus = :campus, programid = :programid
               WHERE accountid = :accountid';
        $save = $pdo->prepare($sql);
        $save->bindValue(':acc_name', $name);
        $save->bindValue(':email', $email);
        $save->bindValue(':acc_type', $roleId, PDO::PARAM_INT);
        $save->bindValue(':acc_roles', implode(',', array_map('strval', $roleIds)));
        $save->bindValue(':campus', $campusId > 0 ? $campusId : null, $campusId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $save->bindValue(':programid', $programId > 0 ? $programId : null, $programId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);

        if (!$isNew) {
            $save->bindValue(':accountid', $accountId, PDO::PARAM_INT);
        }

        $save->execute();

        return $isNew ? (int) $pdo->lastInsertId() : $accountId;
    }

    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function positiveInt($value): int
    {
        $normalized = self::text($value);

        return $normalized !== '' && ctype_digit($normalized) ? (int) $normalized : 0;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
