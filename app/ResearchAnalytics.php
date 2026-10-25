<?php

declare(strict_types=1);

require_once __DIR__ . '/CoordinatorStudentResearch.php';

final class ResearchAnalytics
{
    public const SDG_COLORS = [
        1 => '#e5243b', 2 => '#dda63a', 3 => '#4c9f38', 4 => '#c5192d',
        5 => '#ff3a21', 6 => '#26bde2', 7 => '#fcc30b', 8 => '#a21942',
        9 => '#fd6925', 10 => '#dd1367', 11 => '#fd9d24', 12 => '#bf8b2e',
        13 => '#3f7e44', 14 => '#0a97d9', 15 => '#56c02b', 16 => '#00689d', 17 => '#19486a',
    ];

    public static function percentage(int $part, int $total): string
    {
        return $total > 0 ? number_format($part / $total * 100, 1) . '%' : '0%';
    }

    public static function campus(PDO $pdo, int $assignedCampusId, $requested): array
    {
        $options = $pdo->query('SELECT campusid, campusname FROM tblcampus ORDER BY campusname, campusid')->fetchAll();
        if ($options === []) {
            throw new RuntimeException('No campuses are available.');
        }
        $ids = array_map('intval', array_column($options, 'campusid'));
        $requestedId = coordinator_student_positive_int($requested);
        $id = in_array($requestedId, $ids, true) ? $requestedId
            : (in_array($assignedCampusId, $ids, true) ? $assignedCampusId : $ids[0]);
        $selected = $options[array_search($id, $ids, true)];
        return ['id' => $id, 'label' => trim((string) $selected['campusname']) ?: 'Campus #' . $id, 'options' => $options];
    }

    public static function summary(PDO $pdo, int $campusId): array
    {
        $statement = $pdo->prepare('SELECT COUNT(*) AS total, MAX(updated_at) AS latest_update FROM tblresearches WHERE campusid = :campus');
        $statement->execute(['campus' => $campusId]);
        $row = $statement->fetch();
        return ['total' => (int) $row['total'], 'latest_update' => (string) ($row['latest_update'] ?? '')];
    }

    public static function sdgs(PDO $pdo, int $campusId): array
    {
        $options = coordinator_student_sdg_options();
        $counts = array_fill_keys(array_keys($options), 0);
        $aligned = 0;
        $total = 0;
        $statement = $pdo->prepare('SELECT sdgs, COUNT(*) AS total FROM tblresearches WHERE campusid = :campus GROUP BY sdgs');
        $statement->execute(['campus' => $campusId]);
        foreach ($statement->fetchAll() as $row) {
            $goals = coordinator_student_integer_selection($row['sdgs'] ?? '', $options);
            $manuscripts = (int) $row['total'];
            $total += $manuscripts;
            if ($goals !== []) {
                $aligned += $manuscripts;
            }
            foreach ($goals as $goal) {
                $counts[$goal] += $manuscripts;
            }
        }
        $ranked = array_keys($options);
        usort($ranked, static function (int $left, int $right) use ($counts): int {
            return ($counts[$right] <=> $counts[$left]) ?: ($left <=> $right);
        });
        return ['options' => $options, 'counts' => $counts, 'total' => $total, 'aligned' => $aligned,
            'untagged' => $total - $aligned, 'represented' => count(array_filter($counts)), 'ranked' => $ranked];
    }

    public static function contributors(PDO $pdo, int $campusId): array
    {
        $roles = [
            'advisers' => ['label' => 'Advisers', 'title' => 'Top 20 advisers', 'icon' => 'bx-user-voice', 'people' => [], 'recorded_total' => 0],
            'panelists' => ['label' => 'Panelists', 'title' => 'Top 20 panelists', 'icon' => 'bx-group', 'people' => [], 'recorded_total' => 0],
            'statisticians' => ['label' => 'Statisticians', 'title' => 'Top 20 statisticians', 'icon' => 'bx-line-chart', 'people' => [], 'recorded_total' => 0],
            'english-critics' => ['label' => 'English critics', 'title' => 'Top 20 English critics', 'icon' => 'bx-edit-alt', 'people' => [], 'recorded_total' => 0],
        ];
        $statement = $pdo->prepare(
            "SELECT 'advisers' AS role_key, a.accountid, a.acc_name, COUNT(DISTINCT m.titleid) AS total
             FROM tblresearches m INNER JOIN tblaccount a ON a.accountid = m.adviser_accountid
             WHERE m.campusid = :adviser GROUP BY a.accountid, a.acc_name
             UNION ALL
             SELECT 'panelists', a.accountid, a.acc_name, COUNT(DISTINCT m.titleid)
             FROM tblresearches m
             INNER JOIN tblmanuscript_panelists mp ON mp.manuscriptid = m.titleid
             INNER JOIN tblaccount a ON a.accountid = mp.accountid
             WHERE m.campusid = :panelist GROUP BY a.accountid, a.acc_name
             UNION ALL
             SELECT 'statisticians', a.accountid, a.acc_name, COUNT(DISTINCT m.titleid)
             FROM tblresearches m INNER JOIN tblaccount a ON a.accountid = m.statistician_accountid
             WHERE m.campusid = :statistician GROUP BY a.accountid, a.acc_name
             UNION ALL
             SELECT 'english-critics', a.accountid, a.acc_name, COUNT(DISTINCT m.titleid)
             FROM tblresearches m INNER JOIN tblaccount a ON a.accountid = m.english_critic_accountid
             WHERE m.campusid = :critic GROUP BY a.accountid, a.acc_name
             ORDER BY role_key, total DESC, acc_name, accountid"
        );
        $statement->execute(['adviser' => $campusId, 'panelist' => $campusId, 'statistician' => $campusId, 'critic' => $campusId]);
        $people = [];
        $assignments = 0;
        foreach ($statement->fetchAll() as $row) {
            $role = (string) $row['role_key'];
            $id = (int) $row['accountid'];
            $name = trim((string) $row['acc_name']);
            $count = (int) $row['total'];
            $roles[$role]['people'][] = ['id' => $id, 'name' => $name !== ''
                ? (preg_replace('/\s+/u', ' ', $name) ?? $name) : 'Account #' . $id, 'total' => $count];
            $roles[$role]['recorded_total'] += $count;
            $people[$id] = true;
            $assignments += $count;
        }
        // Multiple panelists on one manuscript contribute one manuscript to coverage.
        $coverage = $pdo->prepare('SELECT COUNT(DISTINCT m.titleid) FROM tblresearches m
            INNER JOIN tblmanuscript_panelists mp ON mp.manuscriptid = m.titleid
            INNER JOIN tblaccount a ON a.accountid = mp.accountid WHERE m.campusid = :campus');
        $coverage->execute(['campus' => $campusId]);
        $roles['panelists']['recorded_total'] = (int) $coverage->fetchColumn();
        return ['roles' => $roles, 'people' => count($people), 'assignments' => $assignments];
    }
}
