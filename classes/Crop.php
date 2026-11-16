<?php
declare(strict_types=1);

/**
 * classes/Crop.php
 * Model class for Crop operations.
 */

require_once __DIR__ . '/../includes/soft_delete_guard.php';
require_once __DIR__ . '/../includes/audit.php';

class Crop
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fetch active crop categories lookup.
     */
    public function getCategories(): array
    {
        return $this->pdo->query("
            SELECT category_id, description
            FROM crop_categories
            WHERE is_deleted = FALSE
            ORDER BY description ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch active seasons lookup.
     */
    public function getSeasons(): array
    {
        return $this->pdo->query("
            SELECT season_id, description
            FROM seasons
            WHERE is_deleted = FALSE
            ORDER BY description ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch active crops for dropdowns (e.g. plantings module).
     */
    public function getActiveCropsForDropdown(): array
    {
        return $this->pdo->query("
            SELECT crop_id, crop_name, variety, growing_days
            FROM crops
            WHERE is_deleted = FALSE
            ORDER BY crop_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a crop name already exists (excluding a specific crop_id if updating).
     */
    public function nameExists(string $cropName, int $excludeId = 0): bool
    {
        if ($excludeId > 0) {
            $stmt = $this->pdo->prepare("
                SELECT crop_id FROM crops
                WHERE LOWER(crop_name) = LOWER(:name) AND crop_id <> :id AND is_deleted = FALSE
                LIMIT 1
            ");
            $stmt->execute([':name' => $cropName, ':id' => $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT crop_id FROM crops
                WHERE LOWER(crop_name) = LOWER(:name) AND is_deleted = FALSE
                LIMIT 1
            ");
            $stmt->execute([':name' => $cropName]);
        }
        return (bool)$stmt->fetch();
    }

    /**
     * Get crops list with search, filter, and pagination.
     */
    public function getAll(string $search = '', int $catId = 0, int $seasonId = 0, int $limit = 15, int $offset = 0): array
    {
        $whereClauses = ["c.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(c.crop_name ILIKE :s OR c.variety ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($catId > 0) {
            $whereClauses[] = "c.category_id = :cat";
            $params[':cat'] = $catId;
        }
        if ($seasonId > 0) {
            $whereClauses[] = "c.season_id = :season";
            $params[':season'] = $seasonId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $limitSql = $limit > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "";

        $query = "
            SELECT c.crop_id, c.crop_name, c.variety, c.category_id, c.growing_days,
                   c.season_id, c.created_at,
                   cc.description AS category_name,
                   s.description  AS season_name,
                   COALESCE(pl.planting_count, 0) AS active_plantings
            FROM crops c
            LEFT JOIN crop_categories cc ON cc.category_id = c.category_id
            LEFT JOIN seasons s ON s.season_id = c.season_id
            LEFT JOIN (
                SELECT crop_id, COUNT(*) AS planting_count
                FROM plantings
                WHERE is_deleted = FALSE
                GROUP BY crop_id
            ) pl ON pl.crop_id = c.crop_id
            {$whereSql}
            ORDER BY c.crop_name ASC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count crops matching search and filters.
     */
    public function count(string $search = '', int $catId = 0, int $seasonId = 0): int
    {
        $whereClauses = ["c.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(c.crop_name ILIKE :s OR c.variety ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        if ($catId > 0) {
            $whereClauses[] = "c.category_id = :cat";
            $params[':cat'] = $catId;
        }
        if ($seasonId > 0) {
            $whereClauses[] = "c.season_id = :season";
            $params[':season'] = $seasonId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM crops c {$whereSql}");
        $countStmt->execute($params);
        return (int)$countStmt->fetchColumn();
    }

    /**
     * Get single crop by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM crops WHERE crop_id = :id AND is_deleted = FALSE LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create crop record.
     */
    public function create(array $data): int
    {
        $cropName    = trim((string)($data['crop_name'] ?? ''));
        $variety     = trim((string)($data['variety'] ?? ''));
        $categoryId  = (int)($data['category_id'] ?? 0) ?: null;
        $growingDays = (int)($data['growing_days'] ?? 0) ?: null;
        $seasonId    = (int)($data['season_id'] ?? 0) ?: null;
        $userId      = isset($data['user_id']) ? (int)$data['user_id'] : null;

        if ($this->nameExists($cropName)) {
            throw new RuntimeException("A crop named '{$cropName}' already exists. Please choose a different name.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO crops
                    (crop_name, variety, category_id, growing_days, season_id, is_deleted, created_at, updated_at, created_by, updated_by)
                VALUES
                    (:crop_name, :variety, :cat, :days, :season, FALSE, NOW(), NOW(), :created_by, :updated_by)
                RETURNING crop_id
            ");
            $stmt->execute([
                ':crop_name'   => $cropName,
                ':variety'     => $variety ?: null,
                ':cat'         => $categoryId,
                ':days'        => $growingDays,
                ':season'      => $seasonId,
                ':created_by'  => $userId,
                ':updated_by'  => $userId,
            ]);
            $newCropId = (int)$stmt->fetchColumn();

            $newData = [
                'crop_id'      => $newCropId,
                'crop_name'    => $cropName,
                'variety'      => $variety,
                'category_id'  => $categoryId,
                'growing_days' => $growingDays,
                'season_id'    => $seasonId,
            ];

            logActivity($this->pdo, 'CREATE', 'crops', $newCropId, "Added crop '{$cropName}'", null, $newData);
            $this->pdo->commit();

            return $newCropId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update crop record.
     */
    public function update(int $id, array $data): bool
    {
        $oldRow = $this->getById($id);
        if (!$oldRow) {
            throw new RuntimeException('Crop not found or already deleted.');
        }

        $cropName    = trim((string)($data['crop_name'] ?? ''));
        $variety     = trim((string)($data['variety'] ?? ''));
        $categoryId  = (int)($data['category_id'] ?? 0) ?: null;
        $growingDays = (int)($data['growing_days'] ?? 0) ?: null;
        $seasonId    = (int)($data['season_id'] ?? 0) ?: null;
        $userId      = isset($data['user_id']) ? (int)$data['user_id'] : null;

        if ($this->nameExists($cropName, $id)) {
            throw new RuntimeException("A crop named '{$cropName}' already exists. Please choose a different name.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE crops
                SET crop_name = :crop_name,
                    variety = :variety,
                    category_id = :cat,
                    growing_days = :days,
                    season_id = :season,
                    updated_at = NOW(),
                    updated_by = :updated_by
                WHERE crop_id = :id
            ");
            $stmt->execute([
                ':crop_name'   => $cropName,
                ':variety'     => $variety ?: null,
                ':cat'         => $categoryId,
                ':days'        => $growingDays,
                ':season'      => $seasonId,
                ':updated_by'  => $userId,
                ':id'          => $id,
            ]);

            $newData = [
                'crop_name'    => $cropName,
                'variety'      => $variety,
                'category_id'  => $categoryId,
                'growing_days' => $growingDays,
                'season_id'    => $seasonId,
            ];

            logActivity($this->pdo, 'UPDATE', 'crops', $id, "Updated crop '{$cropName}'", $oldRow, $newData);
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
     * Soft delete crop record.
     * Includes active child-record check before soft-deleting parent records.
     */
    public function softDelete(int $id, int $userId): bool
    {
        $guard = canSoftDelete($this->pdo, 'crops', $id);
        if (!$guard['allowed']) {
            throw new RuntimeException($guard['message']);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM crops WHERE crop_id = :id");
        $stmt->execute([':id' => $id]);
        $oldRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) {
            throw new RuntimeException('Crop not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("UPDATE crops SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by WHERE crop_id = :id");
            $stmt->execute([
                ':updated_by' => $userId,
                ':id'         => $id,
            ]);

            logActivity($this->pdo, 'SOFT_DELETE', 'crops', $id, "Soft-deleted crop '{$oldRow['crop_name']}'", $oldRow);
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
     * Top crops by yield (for dashboard chart).
     */
    public function getTopCropsByYield(string $startDate, string $endDate, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.crop_name, COALESCE(SUM(h.quantity_kg), 0) AS total_kg
            FROM harvests h
            JOIN plantings p ON p.planting_id = h.planting_id
            JOIN crops c ON c.crop_id = p.crop_id
            WHERE COALESCE(h.is_deleted, FALSE) = FALSE AND h.harvest_date BETWEEN :start AND :end
            GROUP BY c.crop_id, c.crop_name
            ORDER BY total_kg DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
