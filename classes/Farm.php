<?php
declare(strict_types=1);

/**
 * classes/Farm.php
 * Model class for Farm operations.
 */

require_once __DIR__ . '/../includes/soft_delete_guard.php';
require_once __DIR__ . '/../includes/audit.php';

class Farm
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fetch active soil types lookup.
     */
    public function getSoilTypes(): array
    {
        return $this->pdo->query("
            SELECT soil_type_id, description
            FROM soil_types
            WHERE is_deleted = FALSE
            ORDER BY description ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch active irrigation types lookup.
     */
    public function getIrrigationTypes(): array
    {
        return $this->pdo->query("
            SELECT irrigation_type_id, description
            FROM irrigation_types
            WHERE is_deleted = FALSE
            ORDER BY description ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch active farms with owner for dropdown selection (e.g. in plantings).
     */
    public function getActiveFarmsForDropdown(): array
    {
        return $this->pdo->query("
            SELECT fm.farm_id, fm.farm_name, f.last_name, f.first_name
            FROM farms fm
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            WHERE fm.is_deleted = FALSE AND f.is_deleted = FALSE
            ORDER BY fm.farm_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get farms list with search, filter, and pagination.
     */
    public function getAll(string $search = '', int $soilId = 0, int $irrigId = 0, int $limit = 15, int $offset = 0): array
    {
        $whereClauses = ["fm.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(fm.farm_name ILIKE :s OR fm.location ILIKE :s OR f.first_name ILIKE :s OR f.last_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($soilId > 0) {
            $whereClauses[] = "fm.soil_type_id = :soil";
            $params[':soil'] = $soilId;
        }
        if ($irrigId > 0) {
            $whereClauses[] = "fm.irrigation_type_id = :irrig";
            $params[':irrig'] = $irrigId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $limitSql = $limit > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "";

        $query = "
            SELECT fm.farm_id, fm.farmer_id, fm.farm_name, fm.location, fm.area_hectares,
                   fm.soil_type_id, fm.irrigation_type_id, fm.created_at,
                   f.first_name, f.last_name,
                   st.description AS soil_name,
                   it.description AS irrigation_name,
                   COALESCE(pl.planting_count, 0) AS active_plantings
            FROM farms fm
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            LEFT JOIN soil_types st ON st.soil_type_id = fm.soil_type_id
            LEFT JOIN irrigation_types it ON it.irrigation_type_id = fm.irrigation_type_id
            LEFT JOIN (
                SELECT farm_id, COUNT(*) AS planting_count
                FROM plantings
                WHERE is_deleted = FALSE
                GROUP BY farm_id
            ) pl ON pl.farm_id = fm.farm_id
            {$whereSql}
            ORDER BY fm.farm_name ASC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count farms matching search and filters.
     */
    public function count(string $search = '', int $soilId = 0, int $irrigId = 0): int
    {
        $whereClauses = ["fm.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(fm.farm_name ILIKE :s OR fm.location ILIKE :s OR f.first_name ILIKE :s OR f.last_name ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($soilId > 0) {
            $whereClauses[] = "fm.soil_type_id = :soil";
            $params[':soil'] = $soilId;
        }
        if ($irrigId > 0) {
            $whereClauses[] = "fm.irrigation_type_id = :irrig";
            $params[':irrig'] = $irrigId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $countStmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM farms fm
            JOIN farmers f ON f.farmer_id = fm.farmer_id
            {$whereSql}
        ");
        $countStmt->execute($params);
        return (int)$countStmt->fetchColumn();
    }

    /**
     * Get single farm by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM farms WHERE farm_id = :id AND is_deleted = FALSE LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create farm record.
     */
    public function create(array $data): int
    {
        $farmerId     = (int)($data['farmer_id'] ?? 0);
        $farmName     = trim((string)($data['farm_name'] ?? ''));
        $location     = trim((string)($data['location'] ?? ''));
        $areaHectares = (float)($data['area_hectares'] ?? 0);
        $soilTypeId   = (int)($data['soil_type_id'] ?? 0) ?: null;
        $irrigTypeId  = (int)($data['irrigation_type_id'] ?? 0) ?: null;
        $userId       = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO farms
                    (farmer_id, farm_name, location, area_hectares, soil_type_id, irrigation_type_id, is_deleted, created_at, updated_at, created_by, updated_by)
                VALUES
                    (:farmer_id, :farm_name, :location, :area, :soil, :irrig, FALSE, NOW(), NOW(), :created_by, :updated_by)
                RETURNING farm_id
            ");
            $stmt->execute([
                ':farmer_id'  => $farmerId,
                ':farm_name'  => $farmName,
                ':location'   => $location ?: null,
                ':area'       => $areaHectares > 0 ? $areaHectares : null,
                ':soil'       => $soilTypeId,
                ':irrig'      => $irrigTypeId,
                ':created_by' => $userId,
                ':updated_by' => $userId,
            ]);
            $newFarmId = (int)$stmt->fetchColumn();

            $newData = [
                'farm_id'            => $newFarmId,
                'farmer_id'          => $farmerId,
                'farm_name'          => $farmName,
                'location'           => $location,
                'area_hectares'      => $areaHectares,
                'soil_type_id'       => $soilTypeId,
                'irrigation_type_id' => $irrigTypeId,
            ];

            logActivity($this->pdo, 'CREATE', 'farms', $newFarmId, "Created farm '{$farmName}'", null, $newData);
            $this->pdo->commit();

            return $newFarmId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update farm record.
     */
    public function update(int $id, array $data): bool
    {
        $oldRow = $this->getById($id);
        if (!$oldRow) {
            throw new RuntimeException('Farm not found or already deleted.');
        }

        $farmerId     = (int)($data['farmer_id'] ?? 0);
        $farmName     = trim((string)($data['farm_name'] ?? ''));
        $location     = trim((string)($data['location'] ?? ''));
        $areaHectares = (float)($data['area_hectares'] ?? 0);
        $soilTypeId   = (int)($data['soil_type_id'] ?? 0) ?: null;
        $irrigTypeId  = (int)($data['irrigation_type_id'] ?? 0) ?: null;
        $userId       = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE farms
                SET farmer_id = :farmer_id,
                    farm_name = :farm_name,
                    location = :location,
                    area_hectares = :area,
                    soil_type_id = :soil,
                    irrigation_type_id = :irrig,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE farm_id = :id
            ");
            $stmt->execute([
                ':farmer_id'  => $farmerId,
                ':farm_name'  => $farmName,
                ':location'   => $location ?: null,
                ':area'       => $areaHectares > 0 ? $areaHectares : null,
                ':soil'       => $soilTypeId,
                ':irrig'      => $irrigTypeId,
                ':updated_by' => $userId,
                ':id'         => $id,
            ]);

            $newData = [
                'farmer_id'          => $farmerId,
                'farm_name'          => $farmName,
                'location'           => $location,
                'area_hectares'      => $areaHectares,
                'soil_type_id'       => $soilTypeId,
                'irrigation_type_id' => $irrigTypeId,
            ];

            logActivity($this->pdo, 'UPDATE', 'farms', $id, "Updated farm '{$farmName}'", $oldRow, $newData);
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
     * Soft delete farm record.
     * Includes active child-record check before soft-deleting parent records.
     */
    public function softDelete(int $id, int $userId): bool
    {
        $guard = canSoftDelete($this->pdo, 'farms', $id);
        if (!$guard['allowed']) {
            throw new RuntimeException($guard['message']);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM farms WHERE farm_id = :id");
        $stmt->execute([':id' => $id]);
        $oldRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) {
            throw new RuntimeException('Farm not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE farms SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE farm_id = :id");
            $stmt->execute([
                ':updated_by' => $userId,
                ':id'         => $id,
            ]);

            logActivity($this->pdo, 'SOFT_DELETE', 'farms', $id, "Soft-deleted farm '{$oldRow['farm_name']}'", $oldRow);
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
     * Count total active farms (for dashboard).
     */
    public function countActive(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM farms WHERE is_deleted = FALSE");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Top farms by revenue (for dashboard chart).
     */
    public function getTopFarmsByRevenue(string $startDate, string $endDate, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT fm.farm_name, COALESCE(SUM(h.quantity_kg * COALESCE(h.selling_price_per_kg, 0)), 0) AS total_revenue
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            JOIN farms fm ON fm.farm_id = p.farm_id
            WHERE h.is_deleted = FALSE AND h.harvest_date BETWEEN :start AND :end
            GROUP BY fm.farm_id, fm.farm_name
            ORDER BY total_revenue DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
