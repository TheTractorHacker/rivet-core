<?php

declare(strict_types=1);

namespace RivetCore\Mcp\Migration;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Migration\MigrationInterface;

/**
 * OIDC `iss` and `sub` are case-sensitive, and trailing spaces count. mcp_unlinked_identities (0003) was created with
 * utf8mb4_general_ci, so UNIQUE (issuer, subject) treated "ABC" and "abc" (and "abc" and "abc ") as the same identity. This moves both
 * columns to utf8mb4_bin. Moving from a case-insensitive to a binary collation can only make keys more distinct, so the unique key
 * cannot fail on existing rows. Idempotent: a column that is already utf8mb4_bin is left alone, and a missing table is a no-op.
 *
 * @internal
 */
final class Migration0017McpIdentityBinaryCollation implements MigrationInterface
{
    private const TABLE = 'mcp_unlinked_identities';
    private const COLLATION = 'utf8mb4_bin';

    public function id(): string
    {
        return '0017_mcp_identity_binary_collation';
    }

    public function up(DatabaseInterface $database): void
    {
        $rows = $database->fetchAll(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (\'issuer\', \'subject\')',
            [self::TABLE]
        );
        $todo = [];
        foreach ($rows as $row) {
            $name = (string) ($row['COLUMN_NAME'] ?? '');
            if (($name === 'issuer' || $name === 'subject') && (string) ($row['COLLATION_NAME'] ?? '') !== self::COLLATION) {
                $todo[] = "MODIFY COLUMN `$name` varchar(255) CHARACTER SET utf8mb4 COLLATE " . self::COLLATION . ' NOT NULL';
            }
        }
        if ($todo !== []) {
            $database->execute('ALTER TABLE `' . self::TABLE . '` ' . implode(', ', $todo));
        }
    }
}
