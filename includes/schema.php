<?php
/**
 * Small helper for the places where a page adds a column it needs when the database is older than the code
 * (ensure_user_schema(), password_reset_ensure()). A new install already has every column
 * (database/lgu_assistance_system.sql), so on an up-to-date database this only READS and changes nothing.
 *
 * It looks the columns up first instead of using "ADD COLUMN IF NOT EXISTS": that form is MariaDB-only and is a syntax
 * error on MySQL 8, which would have broken the sign-in and My Account pages there.
 * Never call it in the middle of a transaction: ALTER TABLE ends one in MySQL.
 */

/**
 * OPD's "reason for the check-up" used to be stored in applicant.diagnosis; the column is now applicant.checkup_reason
 * (database/migrations/migration_checkup_reason.sql does the same by hand). An older database is renamed here the first time
 * any page runs (config.php), keeping every stored answer, so the pages never meet a missing column. Safe to run again: it
 * only acts when `diagnosis` is there and `checkup_reason` is not. It never runs inside a transaction (ALTER ends one).
 * A failure is logged, never shown, and tried again on the next request.
 */
function schema_checkup_reason_ready(PDO $pdo): void
{
    if (!empty($_SESSION['checkup_reason_ready'])) return;
    try {
        $stmt = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME IN ('diagnosis', 'checkup_reason')");
        $have = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (in_array('diagnosis', $have, true) && !in_array('checkup_reason', $have, true)) {
            $pdo->exec("ALTER TABLE applicant CHANGE COLUMN diagnosis checkup_reason VARCHAR(255) DEFAULT NULL");
        }
        if ($have) $_SESSION['checkup_reason_ready'] = true;   // no applicant table yet (empty database): look again next time
    } catch (Throwable $e) {
        error_log('schema_checkup_reason_ready: ' . $e->getMessage());
    }
}

/**
 * Withdrawing and editing an application (applicant side; includes/application_changes.php).
 * An older database is brought up to date here the first time any page runs (config.php); a new install already has all of it
 * (database/lgu_assistance_system.sql) and database/migrations/migration_application_changes.sql does the same by hand. It adds:
 *   applicant.withdrawn_at, withdrawal_reason   when an applicant withdrew a Pending application, and why (the status is 'Withdrawn')
 *   applicant.edited_at, edit_count             when the applicant last edited it, and how many times (the office sees "edited")
 *   applicant.updated_at                        changes whenever the row changes: the edit page uses it to notice that the
 *                                               application changed (the office decided, or another tab saved) since it was opened
 * and lets the status be 'Withdrawn': chk_applicant_status allows it, and the "one Burial per person" key (person_rule_key)
 * no longer holds for a withdrawn Burial, so the person may file again. Those two are one ALTER TABLE statement, which is
 * all or nothing. Safe to run again: it only acts on what is missing. A failure is logged, never shown, and is tried again
 * after ten minutes. It never runs inside a transaction (ALTER ends one), and a lock keeps two requests from altering at once.
 *
 * @return bool true when everything is in place
 */
function schema_application_changes_ready(PDO $pdo): bool
{
    static $result = null;
    if ($result !== null) return $result;
    if (!empty($_SESSION['application_changes_ready'])) return $result = true;
    if (isset($_SESSION['application_changes_failed_at']) && time() - (int)$_SESSION['application_changes_failed_at'] < 600) return $result = false;

    $locked = false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) return $result = false;   // no applicant table yet (empty database): look again next time

        $locked = (int)$pdo->query("SELECT GET_LOCK('lgu_schema_application_changes', 10)")->fetchColumn() === 1;
        if (!$locked) return $result = false;

        schema_add_columns($pdo, 'applicant', [
            'withdrawn_at'      => "DATETIME NULL DEFAULT NULL COMMENT 'When the applicant withdrew this Pending application (status Withdrawn)'",
            'withdrawal_reason' => "VARCHAR(500) NULL DEFAULT NULL COMMENT 'Optional reason the applicant gave for withdrawing'",
            'edited_at'         => "DATETIME NULL DEFAULT NULL COMMENT 'When the applicant last edited this application online; NULL = never'",
            'edit_count'        => "INT NOT NULL DEFAULT 0 COMMENT 'How many times the applicant saved an edit of the details or the documents'",
            'updated_at'        => "DATETIME(6) NOT NULL DEFAULT current_timestamp(6) ON UPDATE current_timestamp(6) COMMENT 'Changes whenever the row changes: the edit page uses it to notice a change made since it was opened'",
        ]);

        $parts = [];
        $one = function (string $sql, array $params = []) use ($pdo) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        };

        // "One Burial per person" ignores Declined and Withdrawn Burials (a generated column's formula can't be edited in place,
        // so the key is rebuilt, in the same statement as the check below)
        $expression = $one("SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND COLUMN_NAME = 'person_rule_key'");
        if ($expression !== false && stripos((string)$expression, 'Withdrawn') === false) {
            if ($one("SELECT COUNT(*) FROM information_schema.STATISTICS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant' AND INDEX_NAME = 'uq_person_rule'") > 0) {
                $parts[] = 'DROP INDEX `uq_person_rule`';
            }
            $parts[] = 'DROP COLUMN `person_rule_key`';
            $parts[] = "ADD COLUMN `person_rule_key` varchar(20) GENERATED ALWAYS AS (CASE
                WHEN `beneficiary_id` IS NULL THEN NULL
                WHEN `assistance_type_id` = 2 AND `status` NOT IN ('Disapproved', 'Withdrawn') THEN CONCAT('B-', `beneficiary_id`)
                WHEN `assistance_type_id` = 1 AND `status` = 'Pending' THEN CONCAT('M-', `beneficiary_id`)
                END) STORED COMMENT 'Unique: one Burial per person (a Declined or Withdrawn one does not count), one pending Medical per person'";
            $parts[] = 'ADD UNIQUE KEY `uq_person_rule` (`person_rule_key`)';
        }

        $clause = $one("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                        WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_applicant_status'");
        if ($clause !== false && stripos((string)$clause, 'Withdrawn') === false) {
            $parts[] = 'DROP CONSTRAINT `chk_applicant_status`';
            $parts[] = "ADD CONSTRAINT `chk_applicant_status` CHECK (`status` IN ('Pending', 'Approved', 'Disapproved', 'Withdrawn'))";
        }

        if ($parts) {
            $pdo->exec('ALTER TABLE `applicant` ' . implode(', ', $parts));
        }
        $_SESSION['application_changes_ready'] = true;
        unset($_SESSION['application_changes_failed_at']);
        return $result = true;
    } catch (Throwable $e) {
        error_log('schema_application_changes_ready: ' . $e->getMessage());
        $_SESSION['application_changes_failed_at'] = time();
        return $result = false;
    } finally {
        if ($locked) {
            try { $pdo->query("SELECT RELEASE_LOCK('lgu_schema_application_changes')"); } catch (Throwable $e) { /* ends with the connection */ }
        }
    }
}

/**
 * Medical & Burial staff (role `aid_staff`; database/migrations/migration_aid_staff.sql does the same by hand).
 * An older database is brought up to date here the first time any page runs (config.php); a new install already
 * has it (database/lgu_assistance_system.sql). Adds:
 *   users.role           gains 'aid_staff' alongside 'admin' and 'staff'
 *   applicant.encoded_by who keyed in a walk-in (NULL for an online application)
 * Safe to run again: it only acts on what is missing. A failure is logged, never shown, and tried again
 * after ten minutes. It never runs inside a transaction (ALTER ends one).
 */
function schema_aid_staff_ready(PDO $pdo): void
{
    if (!empty($_SESSION['aid_staff_schema_ready'])) return;
    if (isset($_SESSION['aid_staff_schema_failed_at']) && time() - (int)$_SESSION['aid_staff_schema_failed_at'] < 600) return;
    try {
        $roleType = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
        if ($roleType === false) return;   // no users table yet (empty database): look again next time
        if (stripos((string)$roleType, 'aid_staff') === false) {
            $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin','staff','aid_staff') NOT NULL");
        }
        $hadEncodedBy = in_array('encoded_by', array_map('strtolower', $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applicant'")->fetchAll(PDO::FETCH_COLUMN)), true);
        schema_add_columns($pdo, 'applicant', [
            'encoded_by' => "INT(11) DEFAULT NULL COMMENT 'User ID of the admin/staff who keyed in a walk-in; NULL for an online application'",
        ]);
        if (!$hadEncodedBy) {
            $pdo->exec("ALTER TABLE `applicant` ADD KEY `fk_applicant_encoded_by` (`encoded_by`),
                        ADD CONSTRAINT `fk_applicant_encoded_by` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        }
        $_SESSION['aid_staff_schema_ready'] = true;
        unset($_SESSION['aid_staff_schema_failed_at']);
    } catch (Throwable $e) {
        error_log('schema_aid_staff_ready: ' . $e->getMessage());
        $_SESSION['aid_staff_schema_failed_at'] = time();
    }
}

/** Columns needed to validate adult claimants and dated Medical exceptions. */
function schema_claimant_reapply_columns(): array
{
    return [
        'applicant' => ['claimant_birthdate' => 'DATE NULL'],
        'medical_reapply_decisions' => [
            'eligible_from' => 'DATE NULL',
            'default_eligible_from' => 'DATE NULL',
            'previous_eligible_from' => 'DATE NULL',
        ],
    ];
}

/** Read only: safe to use inside a save transaction, even if the startup update failed. */
function schema_claimant_reapply_installed(PDO $pdo): bool
{
    try {
        $rows = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND (
                (TABLE_NAME = 'applicant' AND COLUMN_NAME = 'claimant_birthdate') OR
                (TABLE_NAME = 'medical_reapply_decisions' AND COLUMN_NAME IN ('eligible_from', 'default_eligible_from', 'previous_eligible_from'))
            )")->fetchAll(PDO::FETCH_ASSOC);
        $have = [];
        foreach ($rows as $row) $have[strtolower($row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'])] = true;
        foreach (schema_claimant_reapply_columns() as $table => $columns) {
            foreach ($columns as $column => $_) if (!isset($have[$table . '.' . $column])) return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('schema_claimant_reapply_installed: ' . $e->getMessage());
        return false;
    }
}

/** A form can report this without exposing SQL or allowing an unchecked application to be saved. */
function schema_claimant_reapply_problem(PDO $pdo): ?string
{
    return schema_claimant_reapply_installed($pdo) ? null
        : 'Applications are temporarily unavailable while the office updates the system. Nothing was saved. Please contact the office or try again later.';
}

/**
 * Attempt the additive update once the older person-registry tables exist. Keep failures
 * local to the affected features, retry later, and serialize concurrent first requests.
 */
function schema_claimant_reapply_ready(PDO $pdo): bool
{
    $locked = false;
    try {
        // Check before the retry delay: a manual repair must take effect immediately.
        if (schema_claimant_reapply_installed($pdo)) {
            $_SESSION['claimant_reapply_schema_v1'] = true;
            unset($_SESSION['claimant_reapply_schema_failed_at']);
            return true;
        }
        unset($_SESSION['claimant_reapply_schema_v1']);
        if ($pdo->inTransaction() || (isset($_SESSION['claimant_reapply_schema_failed_at'])
            && time() - (int)$_SESSION['claimant_reapply_schema_failed_at'] < 600)) return false;

        $locked = (int)$pdo->query("SELECT GET_LOCK('lgu_schema_claimant_reapply', 10)")->fetchColumn() === 1;
        if (!$locked) return false;
        foreach (schema_claimant_reapply_columns() as $table => $columns) {
            schema_add_columns($pdo, $table, $columns);
        }
        $_SESSION['claimant_reapply_schema_v1'] = true;
        unset($_SESSION['claimant_reapply_schema_failed_at']);
        return true;
    } catch (Throwable $e) {
        error_log('schema_claimant_reapply_ready: ' . $e->getMessage());
        unset($_SESSION['claimant_reapply_schema_v1']);
        $_SESSION['claimant_reapply_schema_failed_at'] = time();
        return false;
    } finally {
        if ($locked) {
            try { $pdo->query("SELECT RELEASE_LOCK('lgu_schema_claimant_reapply')"); }
            catch (Throwable $e) { error_log('schema_claimant_reapply unlock: ' . $e->getMessage()); }
        }
    }
}

/**
 * Make sure these columns exist in a table, adding the missing ones.
 * Never use inside a transaction: ALTER TABLE commits it in MySQL.
 * @throws PDOException when a column cannot be added
 */
function schema_add_columns(PDO $pdo, string $table, array $columns): void
{
    if (!preg_match('/^\w+$/', $table)) {
        throw new InvalidArgumentException('schema_add_columns: bad table name');
    }
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    $have = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($columns as $name => $definition) {
        if (!preg_match('/^\w+$/', (string)$name)) {
            throw new InvalidArgumentException('schema_add_columns: bad column name');
        }
        if (!in_array(strtolower((string)$name), $have, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
        }
    }
}
