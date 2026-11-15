<?php
declare(strict_types=1);

/**
 * includes/audit.php
 * Production-grade Audit Trail logger for Leafty.
 *
 * Records CREATE, UPDATE, SOFT_DELETE, RESTORE, and LOGIN actions
 * into the `activity_log` table with before-and-after snapshots (JSONB).
 */

const ACTIVITY_ACTION_TYPES = ['CREATE', 'UPDATE', 'SOFT_DELETE', 'RESTORE', 'LOGIN'];

/**
 * Log a user action to the activity_log table.
 *
 * @param PDO        $pdo         PDO connection instance.
 * @param string     $actionType  One of: CREATE, UPDATE, SOFT_DELETE, RESTORE, LOGIN.
 * @param string     $targetTable Target database table (e.g., 'farmers', 'plantings').
 * @param int|null   $recordId    Primary key of the affected record, or null if not applicable.
 * @param string     $description Human-readable description of the operation.
 * @param array|null $oldData     Pre-mutation record snapshot, or null.
 * @param array|null $newData     Post-mutation record snapshot, or null.
 *
 * @throws InvalidArgumentException When actionType or targetTable is invalid.
 * @throws PDOException             When the insert query fails.
 */
function logActivity(
    PDO $pdo,
    string $actionType,
    string $targetTable,
    ?int $recordId,
    string $description,
    ?array $oldData = null,
    ?array $newData = null
): void {
    $actionType = strtoupper(trim($actionType));
    if (!in_array($actionType, ACTIVITY_ACTION_TYPES, true)) {
        throw new InvalidArgumentException("Invalid activity action type: '{$actionType}'");
    }

    $targetTable = trim($targetTable);
    if ($targetTable === '' || strlen($targetTable) > 64) {
        throw new InvalidArgumentException("Invalid target table: '{$targetTable}' (must be 1-64 characters).");
    }

    // Get current logged-in user ID (null for system or anonymous operations)
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
               | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    // Redact password or credential fields from JSON snapshots
    $sanitize = static function (?array $data): ?array {
        if ($data === null) {
            return null;
        }
        unset($data['password_hash'], $data['password'], $data['token']);
        return $data;
    };

    $oldJson = $oldData !== null ? json_encode($sanitize($oldData), $jsonFlags) : null;
    $newJson = $newData !== null ? json_encode($sanitize($newData), $jsonFlags) : null;

    $stmt = $pdo->prepare("
        INSERT INTO activity_log (
            user_id,
            action_type,
            target_table,
            record_id,
            description,
            old_data,
            new_data,
            created_at
        ) VALUES (
            :user_id,
            :action_type,
            :target_table,
            :record_id,
            :description,
            CAST(:old_data AS JSONB),
            CAST(:new_data AS JSONB),
            NOW()
        )
    ");

    $stmt->bindValue(':user_id',      $userId,      $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':action_type',  $actionType,  PDO::PARAM_STR);
    $stmt->bindValue(':target_table', $targetTable, PDO::PARAM_STR);
    $stmt->bindValue(':record_id',    $recordId,    $recordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':description',  $description, PDO::PARAM_STR);
    $stmt->bindValue(':old_data',     $oldJson,     $oldJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':new_data',     $newJson,     $newJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

    $stmt->execute();
}
