<?php
declare(strict_types=1);

/**
 * includes/soft_delete_guard.php
 * Referential integrity guard for Soft Deletion (is_deleted = TRUE).
 *
 * Verifies that no active (is_deleted = FALSE) child dependencies exist
 * before allowing a record to be soft-deleted. Also prevents self-deactivation
 * and deactivation of the last remaining Administrator.
 */

/**
 * Metadata configuration for soft-deletable tables.
 * Each blocker: [child table, fk column name, singular label, plural label]
 *
 * @return array<string, array{pk: string, label: string, blockers: array<int, array{0: string, 1: string, 2: string, 3: string}>}>
 */
function softDeleteRules(): array
{
    return [
        // Core operational tables
        'farmers' => [
            'pk'       => 'farmer_id',
            'label'    => 'farmer',
            'blockers' => [
                ['farms', 'farmer_id', 'farm', 'farms']
            ]
        ],
        'farms' => [
            'pk'       => 'farm_id',
            'label'    => 'farm',
            'blockers' => [
                ['plantings', 'farm_id', 'planting', 'plantings']
            ]
        ],
        'crops' => [
            'pk'       => 'crop_id',
            'label'    => 'crop',
            'blockers' => [
                ['plantings', 'crop_id', 'planting', 'plantings']
            ]
        ],
        'plantings' => [
            'pk'       => 'planting_id',
            'label'    => 'planting',
            'blockers' => [
                ['harvests', 'planting_id', 'harvest', 'harvests']
            ]
        ],
        'harvests' => [
            'pk'       => 'harvest_id',
            'label'    => 'harvest',
            'blockers' => []
        ],

        // Seven (7) Lookup tables
        'soil_types' => [
            'pk'       => 'soil_type_id',
            'label'    => 'soil type',
            'blockers' => [
                ['farms', 'soil_type_id', 'farm', 'farms']
            ]
        ],
        'irrigation_types' => [
            'pk'       => 'irrigation_type_id',
            'label'    => 'irrigation type',
            'blockers' => [
                ['farms', 'irrigation_type_id', 'farm', 'farms']
            ]
        ],
        'crop_categories' => [
            'pk'       => 'category_id',
            'label'    => 'crop category',
            'blockers' => [
                ['crops', 'category_id', 'crop', 'crops']
            ]
        ],
        'seasons' => [
            'pk'       => 'season_id',
            'label'    => 'season',
            'blockers' => [
                ['crops', 'season_id', 'crop', 'crops']
            ]
        ],
        'planting_status' => [
            'pk'       => 'status_id',
            'label'    => 'planting status',
            'blockers' => [
                ['plantings', 'status_id', 'planting', 'plantings']
            ]
        ],
        'quality_grades' => [
            'pk'       => 'grade_id',
            'label'    => 'quality grade',
            'blockers' => [
                ['harvests', 'grade_id', 'harvest', 'harvests']
            ]
        ],
        'harvest_remarks' => [
            'pk'       => 'remark_id',
            'label'    => 'harvest remark',
            'blockers' => [
                ['harvests', 'remark_id', 'harvest', 'harvests']
            ]
        ],

        // System users
        'system_user' => [
            'pk'       => 'user_id',
            'label'    => 'user',
            'blockers' => []
        ],
    ];
}

/**
 * Validates whether a record can be safely soft-deleted.
 *
 * @param PDO    $pdo   Active PDO connection.
 * @param string $table Target table name.
 * @param int    $id    Primary key ID of the target record.
 *
 * @return array{allowed: bool, message: string}
 */
function canSoftDelete(PDO $pdo, string $table, int $id): array
{
    $rules = softDeleteRules();

    // Map legacy alias to standard table name
    if ($table === 'app_users') {
        $table = 'system_user';
    }

    if (!isset($rules[$table])) {
        return ['allowed' => false, 'message' => "Soft-deletion is not supported for '{$table}'."];
    }

    $rule  = $rules[$table];
    $pk    = $rule['pk'];
    $label = $rule['label'];

    // 1. Verify that the record exists and is currently active
    $stmt = $pdo->prepare("SELECT is_deleted FROM \"{$table}\" WHERE \"{$pk}\" = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['allowed' => false, 'message' => ucfirst($label) . ' not found.'];
    }

    if (filter_var($row['is_deleted'], FILTER_VALIDATE_BOOLEAN)) {
        return ['allowed' => false, 'message' => 'This ' . $label . ' is already deleted.'];
    }

    // 2. Special rules for system_user: prevent self-deactivation & last Admin deactivation
    if ($table === 'system_user') {
        $sessionUser = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        if ($sessionUser !== null && $sessionUser === $id) {
            return ['allowed' => false, 'message' => 'You cannot deactivate your own account.'];
        }

        $adminRole = defined('ROLE_ADMIN') ? (int)ROLE_ADMIN : 1;

        $stmt = $pdo->prepare("SELECT role_id FROM \"system_user\" WHERE user_id = :id");
        $stmt->execute([':id' => $id]);
        $targetRoleId = (int)$stmt->fetchColumn();

        if ($targetRoleId === $adminRole) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM \"system_user\"
                WHERE role_id = :role AND is_deleted = FALSE AND user_id <> :id
            ");
            $stmt->execute([':role' => $adminRole, ':id' => $id]);
            $remainingAdmins = (int)$stmt->fetchColumn();

            if ($remainingAdmins === 0) {
                return [
                    'allowed' => false,
                    'message' => 'Cannot deactivate the last active Administrator. Promote another user to Administrator first.'
                ];
            }
        }

        return ['allowed' => true, 'message' => 'OK'];
    }

    // 3. Operational & Lookup tables: block if active child records exist
    foreach ($rule['blockers'] as [$childTable, $fkColumn, $singular, $plural]) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM \"{$childTable}\"
            WHERE \"{$fkColumn}\" = :id AND is_deleted = FALSE
        ");
        $stmt->execute([':id' => $id]);
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            $noun = $count === 1 ? $singular : $plural;
            $verb = $count === 1 ? 'is' : 'are';
            return [
                'allowed' => false,
                'message' => "Cannot delete this {$label}: {$count} active {$noun} {$verb} still linked to it. Delete or reassign them first.",
            ];
        }
    }

    return ['allowed' => true, 'message' => 'OK'];
}
