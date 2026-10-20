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
 * Make sure these columns exist in a table, adding the missing ones.
 *
 * @param string   $table   a table of this system (letters, digits and underscores only: it is written into the statement)
 * @param string[] $columns column name => definition, e.g. 'phone' => 'VARCHAR(20) NULL'
 * @throws PDOException     when a column has to be added and the database refuses
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
