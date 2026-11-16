<?php
declare(strict_types=1);

/**
 * classes/Harvest.php
 * Model class for Harvest operations.
 */

require_once __DIR__ . '/../includes/soft_delete_guard.php';
require_once __DIR__ . '/../includes/audit.php';

class Harvest
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fetch active quality grades lookup.
     */
    public function getQualityGrades(): array
    {
        return $this->pdo->query("
            SELECT grade_id, description
            FROM quality_grades
            WHERE is_deleted = FALSE
            ORDER BY grade_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch active harvest remarks lookup.
     */
    public function getHarvestRemarks(): array
    {
        return $this->pdo->query("
            SELECT remark_id, description
            FROM harvest_remarks
            WHERE is_deleted = FALSE
            ORDER BY remark_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get harvests list with multi-table JOINs, dynamic total value, search, filter, and pagination.
     */
    public function getAll(string $search = '', int $gradeId = 0, int $remarkId = 0, int $limit = 15, int $offset = 0): array
    {
        $whereClauses = ["h.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(c.crop_name ILIKE :s OR fm.farm_name ILIKE :s OR f.last_name ILIKE :s OR f.first_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($gradeId > 0) {
            $whereClauses[] = "h.grade_id = :grade";
            $params[':grade'] = $gradeId;
        }
        if ($remarkId > 0) {
            $whereClauses[] = "h.remark_id = :remark";
            $params[':remark'] = $remarkId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $limitSql = $limit > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "";

        $query = "
            SELECT h.harvest_id, h.planting_id, h.harvest_date, h.quantity_kg,
                   h.selling_price_per_kg, h.grade_id, h.remark_id, h.created_at,
                   (h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)) AS total_value,
                   qg.description AS grade_name,
                   hr.description AS remark_name,
                   c.crop_name, c.variety,
                   fm.farm_name,
                   f.first_name AS farmer_first_name, f.last_name AS farmer_last_name,
                   p.planting_date
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            LEFT JOIN quality_grades qg ON qg.grade_id = h.grade_id
            LEFT JOIN harvest_remarks hr ON hr.remark_id = h.remark_id
            {$whereSql}
            ORDER BY h.harvest_date DESC, h.harvest_id DESC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count harvests matching search and filters.
     */
    public function count(string $search = '', int $gradeId = 0, int $remarkId = 0): int
    {
        $whereClauses = ["h.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(c.crop_name ILIKE :s OR fm.farm_name ILIKE :s OR f.last_name ILIKE :s OR f.first_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($gradeId > 0) {
            $whereClauses[] = "h.grade_id = :grade";
            $params[':grade'] = $gradeId;
        }
        if ($remarkId > 0) {
            $whereClauses[] = "h.remark_id = :remark";
            $params[':remark'] = $remarkId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            {$whereSql}
        ");
        $countStmt->execute($params);
        return (int)$countStmt->fetchColumn();
    }

    /**
     * Get aggregate summary of harvests matching search & filters (count, total kg, total revenue).
     */
    public function getSummary(string $search = '', int $gradeId = 0, int $remarkId = 0): array
    {
        $whereClauses = ["h.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(c.crop_name ILIKE :s OR fm.farm_name ILIKE :s OR f.last_name ILIKE :s OR f.first_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($gradeId > 0) {
            $whereClauses[] = "h.grade_id = :grade";
            $params[':grade'] = $gradeId;
        }
        if ($remarkId > 0) {
            $whereClauses[] = "h.remark_id = :remark";
            $params[':remark'] = $remarkId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $aggStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total_count,
                   COALESCE(SUM(h.quantity_kg), 0) AS total_kg,
                   COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            JOIN crops c ON c.crop_id = p.crop_id
            {$whereSql}
        ");
        $aggStmt->execute($params);
        return $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_count' => 0, 'total_kg' => 0, 'total_revenue' => 0
        ];
    }

    /**
     * Get single harvest by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM harvests WHERE harvest_id = :id AND is_deleted = FALSE LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create harvest record and sync linked planting status to Harvested (3).
     */
    public function create(array $data): int
    {
        $plantingId   = (int)($data['planting_id'] ?? 0);
        $harvestDate  = trim((string)($data['harvest_date'] ?? date('Y-m-d')));
        $quantityKg   = (float)($data['quantity_kg'] ?? 0);
        $sellingPrice = (float)($data['selling_price_per_kg'] ?? 0);
        $gradeId      = (int)($data['grade_id'] ?? 0) ?: null;
        $remarkId     = (int)($data['remark_id'] ?? 0) ?: null;
        $userId       = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO harvests
                    (planting_id, harvest_date, quantity_kg, selling_price_per_kg, grade_id, remark_id,
                     is_deleted, created_at, updated_at, created_by, updated_by)
                VALUES
                    (:planting_id, :h_date, :qty, :price, :grade, :remark,
                     FALSE, NOW(), NOW(), :created_by, :updated_by)
                RETURNING harvest_id
            ");
            $stmt->execute([
                ':planting_id' => $plantingId,
                ':h_date'      => $harvestDate,
                ':qty'         => $quantityKg,
                ':price'       => $sellingPrice > 0 ? $sellingPrice : null,
                ':grade'       => $gradeId,
                ':remark'      => $remarkId,
                ':created_by'  => $userId,
                ':updated_by'  => $userId,
            ]);
            $newHarvestId = (int)$stmt->fetchColumn();

            // Automatically update related planting's status to Harvested (3)
            $updPlanting = $this->pdo->prepare("
                UPDATE plantings
                SET status_id = 3, updated_at = NOW(), updated_by = :user_id
                WHERE planting_id = :planting_id AND status_id <> 3
            ");
            $updPlanting->execute([
                ':user_id'     => $userId,
                ':planting_id' => $plantingId
            ]);

            $totalValue = $quantityKg * $sellingPrice;
            $newData = [
                'harvest_id'           => $newHarvestId,
                'planting_id'          => $plantingId,
                'harvest_date'         => $harvestDate,
                'quantity_kg'          => $quantityKg,
                'selling_price_per_kg' => $sellingPrice,
                'total_value'          => $totalValue,
                'grade_id'             => $gradeId,
                'remark_id'            => $remarkId,
                'planting_auto_status' => 'Harvested (3)',
            ];

            logActivity($this->pdo, 'CREATE', 'harvests', $newHarvestId, "Recorded harvest #{$newHarvestId} ({$quantityKg} kg)", null, $newData);
            $this->pdo->commit();

            return $newHarvestId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update harvest record and sync linked planting status to Harvested (3).
     */
    public function update(int $id, array $data): bool
    {
        $oldRow = $this->getById($id);
        if (!$oldRow) {
            throw new RuntimeException('Harvest not found or already deleted.');
        }

        $plantingId   = (int)($data['planting_id'] ?? 0);
        $harvestDate  = trim((string)($data['harvest_date'] ?? date('Y-m-d')));
        $quantityKg   = (float)($data['quantity_kg'] ?? 0);
        $sellingPrice = (float)($data['selling_price_per_kg'] ?? 0);
        $gradeId      = (int)($data['grade_id'] ?? 0) ?: null;
        $remarkId     = (int)($data['remark_id'] ?? 0) ?: null;
        $userId       = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE harvests
                SET planting_id = :planting_id,
                    harvest_date = :h_date,
                    quantity_kg = :qty,
                    selling_price_per_kg = :price,
                    grade_id = :grade,
                    remark_id = :remark,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE harvest_id = :id
            ");
            $stmt->execute([
                ':planting_id' => $plantingId,
                ':h_date'      => $harvestDate,
                ':qty'         => $quantityKg,
                ':price'       => $sellingPrice > 0 ? $sellingPrice : null,
                ':grade'       => $gradeId,
                ':remark'      => $remarkId,
                ':updated_by'  => $userId,
                ':id'          => $id,
            ]);

            // Ensure the linked planting status is synced to Harvested (3)
            $updPlanting = $this->pdo->prepare("
                UPDATE plantings
                SET status_id = 3, updated_at = NOW(), updated_by = :user_id
                WHERE planting_id = :planting_id AND status_id <> 3
            ");
            $updPlanting->execute([
                ':user_id'     => $userId,
                ':planting_id' => $plantingId,
            ]);

            $newData = [
                'planting_id'          => $plantingId,
                'harvest_date'         => $harvestDate,
                'quantity_kg'          => $quantityKg,
                'selling_price_per_kg' => $sellingPrice,
                'grade_id'             => $gradeId,
                'remark_id'            => $remarkId,
                'planting_auto_status' => 'Harvested (3)',
            ];

            logActivity($this->pdo, 'UPDATE', 'harvests', $id, "Updated harvest #{$id}", $oldRow, $newData);
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
     * Soft delete harvest record.
     * Includes active child-record check before soft-deleting.
     */
    public function softDelete(int $id, int $userId): bool
    {
        $guard = canSoftDelete($this->pdo, 'harvests', $id);
        if (!$guard['allowed']) {
            throw new RuntimeException($guard['message']);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM harvests WHERE harvest_id = :id");
        $stmt->execute([':id' => $id]);
        $oldRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) {
            throw new RuntimeException('Harvest record not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE harvests SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE harvest_id = :id");
            $stmt->execute([
                ':updated_by' => $userId,
                ':id'         => $id,
            ]);

            logActivity($this->pdo, 'SOFT_DELETE', 'harvests', $id, "Soft-deleted harvest #{$id}", $oldRow);
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
     * Get Monthly KPI metrics (Current month vs Previous month).
     */
    public function getMonthKpi(string $tmStart, string $tmEnd, string $lmStart, string $lmEnd): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN harvest_date BETWEEN :tm_start AND :tm_end THEN quantity_kg ELSE 0 END), 0) AS tm_kg,
                COALESCE(SUM(CASE WHEN harvest_date BETWEEN :tm_start AND :tm_end THEN quantity_kg * COALESCE(selling_price_per_kg, 0) ELSE 0 END), 0) AS tm_rev,
                COALESCE(SUM(CASE WHEN harvest_date BETWEEN :lm_start AND :lm_end THEN quantity_kg ELSE 0 END), 0) AS lm_kg,
                COALESCE(SUM(CASE WHEN harvest_date BETWEEN :lm_start AND :lm_end THEN quantity_kg * COALESCE(selling_price_per_kg, 0) ELSE 0 END), 0) AS lm_rev
            FROM harvests
            WHERE is_deleted = FALSE
        ");
        $stmt->execute([
            ':tm_start' => $tmStart,
            ':tm_end'   => $tmEnd,
            ':lm_start' => $lmStart,
            ':lm_end'   => $lmEnd,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'tm_kg' => 0, 'tm_rev' => 0, 'lm_kg' => 0, 'lm_rev' => 0
        ];
    }

    /**
     * Get Average yield / hectare across all active harvested cycles.
     */
    public function getAvgYieldPerHectare(): float
    {
        $stmt = $this->pdo->query("
            SELECT COALESCE(SUM(h.quantity_kg) / NULLIF(SUM(p.area_planted_hectares), 0), 0) AS avg_yield
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            WHERE h.is_deleted = FALSE AND p.is_deleted = FALSE
        ");
        return (float)$stmt->fetchColumn();
    }

    /**
     * Get Monthly trends for volume and revenue within date range.
     */
    public function getMonthlyTrends(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare("
            SELECT TO_CHAR(harvest_date, 'YYYY-MM') AS month_key,
                   COALESCE(SUM(quantity_kg), 0) AS total_kg,
                   COALESCE(SUM(quantity_kg * COALESCE(selling_price_per_kg, 0)), 0) AS total_revenue
            FROM harvests
            WHERE is_deleted = FALSE AND harvest_date BETWEEN :start AND :end
            GROUP BY TO_CHAR(harvest_date, 'YYYY-MM')
            ORDER BY month_key ASC
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get recent harvests for dashboard.
     */
    public function getRecentHarvests(int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT h.harvest_id, h.harvest_date, h.quantity_kg, c.crop_name, fm.farm_name,
                   (h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)) AS total_value
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            JOIN farms fm ON fm.farm_id = p.farm_id
            JOIN crops c ON c.crop_id = p.crop_id
            WHERE h.is_deleted = FALSE
            ORDER BY h.harvest_date DESC, h.harvest_id DESC
            LIMIT {$limit}
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
