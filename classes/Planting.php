<?php
declare(strict_types=1);

/**
 * classes/Planting.php
 * Model class for Planting operations.
 */

require_once __DIR__ . '/../includes/soft_delete_guard.php';
require_once __DIR__ . '/../includes/audit.php';

class Planting
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fetch active planting status lookup.
     */
    public function getStatuses(): array
    {
        return $this->pdo->query("
            SELECT status_id, description
            FROM planting_status
            WHERE is_deleted = FALSE
            ORDER BY status_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch active plantings available for harvest selection.
     */
    public function getActivePlantingsForHarvest(): array
    {
        return $this->pdo->query("
            SELECT p.planting_id, p.planting_date, c.crop_name, c.variety, fm.farm_name, f.last_name
            FROM plantings p
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            WHERE p.is_deleted = FALSE
            ORDER BY p.planting_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get plantings list with multi-table JOINs, search, filter, and pagination.
     */
    public function getAll(string $search = '', int $statusId = 0, int $cropId = 0, int $limit = 15, int $offset = 0): array
    {
        $whereClauses = ["p.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(fm.farm_name ILIKE :s OR c.crop_name ILIKE :s OR f.first_name ILIKE :s OR f.last_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($statusId > 0) {
            $whereClauses[] = "p.status_id = :status";
            $params[':status'] = $statusId;
        }
        if ($cropId > 0) {
            $whereClauses[] = "p.crop_id = :crop";
            $params[':crop'] = $cropId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $limitSql = $limit > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "";

        $query = "
            SELECT p.planting_id, p.farm_id, p.crop_id, p.planting_date,
                   p.area_planted_hectares, p.seed_quantity_kg, p.expected_harvest_date,
                   p.status_id, p.created_at,
                   fm.farm_name,
                   f.first_name AS farmer_first_name, f.last_name AS farmer_last_name,
                   c.crop_name, c.variety, c.growing_days,
                   ps.description AS status_name,
                   COALESCE(hv.harvest_count, 0) AS harvest_count,
                   COALESCE(hv.total_harvested_kg, 0) AS total_harvested_kg
            FROM plantings p
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            LEFT JOIN planting_status ps ON ps.status_id = p.status_id
            LEFT JOIN (
                SELECT planting_id, COUNT(*) AS harvest_count, SUM(quantity_kg) AS total_harvested_kg
                FROM harvests
                WHERE is_deleted = FALSE
                GROUP BY planting_id
            ) hv ON hv.planting_id = p.planting_id
            {$whereSql}
            ORDER BY p.planting_date DESC, p.planting_id DESC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count plantings matching search and filters.
     */
    public function count(string $search = '', int $statusId = 0, int $cropId = 0): int
    {
        $whereClauses = ["p.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(fm.farm_name ILIKE :s OR c.crop_name ILIKE :s OR f.first_name ILIKE :s OR f.last_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($statusId > 0) {
            $whereClauses[] = "p.status_id = :status";
            $params[':status'] = $statusId;
        }
        if ($cropId > 0) {
            $whereClauses[] = "p.crop_id = :crop";
            $params[':crop'] = $cropId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM plantings p
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            {$whereSql}
        ");
        $countStmt->execute($params);
        return (int)$countStmt->fetchColumn();
    }

    /**
     * Get single planting by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM plantings WHERE planting_id = :id AND is_deleted = FALSE LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create planting cycle record.
     */
    public function create(array $data): int
    {
        $farmId       = (int)($data['farm_id'] ?? 0);
        $cropId       = (int)($data['crop_id'] ?? 0);
        $plantingDate = trim((string)($data['planting_date'] ?? date('Y-m-d')));
        $areaPlanted  = (float)($data['area_planted_hectares'] ?? 0);
        $seedQty      = (float)($data['seed_quantity_kg'] ?? 0);
        $expectedDate = trim((string)($data['expected_harvest_date'] ?? ''));
        $statusId     = (int)($data['status_id'] ?? 1);
        $userId       = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO plantings
                    (farm_id, crop_id, planting_date, area_planted_hectares, seed_quantity_kg,
                     expected_harvest_date, status_id, is_deleted, created_at, updated_at, created_by, updated_by)
                VALUES
                    (:farm_id, :crop_id, :p_date, :area, :seeds, :exp_date, :status_id,
                     FALSE, NOW(), NOW(), :created_by, :updated_by)
                RETURNING planting_id
            ");
            $stmt->execute([
                ':farm_id'     => $farmId,
                ':crop_id'     => $cropId,
                ':p_date'      => $plantingDate,
                ':area'        => $areaPlanted > 0 ? $areaPlanted : null,
                ':seeds'       => $seedQty > 0 ? $seedQty : null,
                ':exp_date'    => $expectedDate ?: null,
                ':status_id'   => $statusId,
                ':created_by'  => $userId,
                ':updated_by'  => $userId,
            ]);
            $newPlantingId = (int)$stmt->fetchColumn();

            $newData = [
                'planting_id'           => $newPlantingId,
                'farm_id'               => $farmId,
                'crop_id'               => $cropId,
                'planting_date'         => $plantingDate,
                'area_planted_hectares' => $areaPlanted,
                'seed_quantity_kg'      => $seedQty,
                'expected_harvest_date' => $expectedDate,
                'status_id'             => $statusId,
            ];

            logActivity($this->pdo, 'CREATE', 'plantings', $newPlantingId, "Recorded new planting cycle #{$newPlantingId}", null, $newData);
            $this->pdo->commit();

            return $newPlantingId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update planting cycle record.
     * When status is set to Harvested (3), auto-inserts a default harvest record if none already exists.
     */
    public function update(int $id, array $data): bool
    {
        $oldRow = $this->getById($id);
        if (!$oldRow) {
            throw new RuntimeException('Planting record not found or already deleted.');
        }

        $farmId       = (int)($data['farm_id'] ?? 0);
        $cropId       = (int)($data['crop_id'] ?? 0);
        $plantingDate = trim((string)($data['planting_date'] ?? date('Y-m-d')));
        $areaPlanted  = (float)($data['area_planted_hectares'] ?? 0);
        $seedQty      = (float)($data['seed_quantity_kg'] ?? 0);
        $expectedDate = trim((string)($data['expected_harvest_date'] ?? ''));
        $statusId     = (int)($data['status_id'] ?? 1);
        $userId       = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE plantings
                SET farm_id = :farm_id,
                    crop_id = :crop_id,
                    planting_date = :p_date,
                    area_planted_hectares = :area,
                    seed_quantity_kg = :seeds,
                    expected_harvest_date = :exp_date,
                    status_id = :status_id,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE planting_id = :id
            ");
            $stmt->execute([
                ':farm_id'     => $farmId,
                ':crop_id'     => $cropId,
                ':p_date'      => $plantingDate,
                ':area'        => $areaPlanted > 0 ? $areaPlanted : null,
                ':seeds'       => $seedQty > 0 ? $seedQty : null,
                ':exp_date'    => $expectedDate ?: null,
                ':status_id'   => $statusId,
                ':updated_by'  => $userId,
                ':id'          => $id,
            ]);

            // When status is set to Harvested (3), auto-insert default harvest record if none already exists
            if ($statusId === 3) {
                $existsStmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM harvests
                    WHERE planting_id = :planting_id AND COALESCE(is_deleted, FALSE) = FALSE
                ");
                $existsStmt->execute([':planting_id' => $id]);
                $harvestExists = (int)$existsStmt->fetchColumn();

                if ($harvestExists === 0) {
                    $gradeStmt = $this->pdo->query("
                        SELECT grade_id FROM quality_grades
                        WHERE COALESCE(is_deleted, FALSE) = FALSE
                        ORDER BY grade_id ASC LIMIT 1
                    ");
                    $defaultGradeId = $gradeStmt ? $gradeStmt->fetchColumn() : null;

                    $remarkStmt = $this->pdo->query("
                        SELECT remark_id FROM harvest_remarks
                        WHERE COALESCE(is_deleted, FALSE) = FALSE
                          AND LOWER(description) LIKE '%auto%'
                        ORDER BY remark_id ASC LIMIT 1
                    ");
                    $defaultRemarkId = $remarkStmt ? $remarkStmt->fetchColumn() : null;

                    $insHarvest = $this->pdo->prepare("
                        INSERT INTO harvests
                            (planting_id, harvest_date, quantity_kg, selling_price_per_kg,
                             grade_id, remark_id, is_deleted, created_at, updated_at,
                             created_by, updated_by)
                        VALUES
                            (:planting_id, CURRENT_DATE, 0, 0,
                             :grade_id, :remark_id, FALSE, NOW(), NOW(),
                             :created_by, :updated_by)
                    ");
                    $insHarvest->execute([
                        ':planting_id' => $id,
                        ':grade_id'    => $defaultGradeId ?: null,
                        ':remark_id'   => $defaultRemarkId ?: null,
                        ':created_by'  => $userId,
                        ':updated_by'  => $userId,
                    ]);
                }
            }

            $newData = [
                'farm_id'               => $farmId,
                'crop_id'               => $cropId,
                'planting_date'         => $plantingDate,
                'area_planted_hectares' => $areaPlanted,
                'seed_quantity_kg'      => $seedQty,
                'expected_harvest_date' => $expectedDate,
                'status_id'             => $statusId,
            ];

            logActivity($this->pdo, 'UPDATE', 'plantings', $id, "Updated planting cycle #{$id}", $oldRow, $newData);
            $this->pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Soft delete planting cycle record.
     * Includes active child-record check before soft-deleting parent records.
     */
    public function softDelete(int $id, int $userId): bool
    {
        $guard = canSoftDelete($this->pdo, 'plantings', $id);
        if (!$guard['allowed']) {
            throw new RuntimeException($guard['message']);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM plantings WHERE planting_id = :id");
        $stmt->execute([':id' => $id]);
        $oldRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) {
            throw new RuntimeException('Planting record not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE plantings SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE planting_id = :id");
            $stmt->execute([
                ':updated_by' => $userId,
                ':id'         => $id,
            ]);

            logActivity($this->pdo, 'SOFT_DELETE', 'plantings', $id, "Soft-deleted planting #{$id}", $oldRow);
            $this->pdo->commit();

            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Count active growing plantings (status 1 Planted, 2 Growing).
     */
    public function countActiveGrowing(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM plantings WHERE is_deleted = FALSE AND status_id IN (1, 2)");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count plantings with harvests due within specified days.
     */
    public function countHarvestsDueSoon(int $days = 14): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM plantings
            WHERE is_deleted = FALSE AND status_id <> 3
              AND expected_harvest_date BETWEEN CURRENT_DATE AND CURRENT_DATE + (:days || ' days')::INTERVAL
        ");
        $stmt->execute([':days' => $days]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get upcoming harvests for dashboard.
     */
    public function getUpcomingHarvests(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.planting_id, p.expected_harvest_date, c.crop_name, c.variety, fm.farm_name, f.last_name,
                   (p.expected_harvest_date - CURRENT_DATE) AS days_left
            FROM plantings p
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            WHERE p.is_deleted = FALSE AND p.status_id <> 3
              AND p.expected_harvest_date IS NOT NULL
              AND p.expected_harvest_date >= CURRENT_DATE
            ORDER BY p.expected_harvest_date ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
