<?php
declare(strict_types=1);

/**
 * classes/Farmer.php
 * Model class for Farmer operations.
 */

require_once __DIR__ . '/../includes/soft_delete_guard.php';
require_once __DIR__ . '/../includes/audit.php';

class Farmer
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check if barangay_id column exists on farmers table.
     */
    public function hasBarangayColumn(): bool
    {
        try {
            $colChk = $this->pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'farmers' AND column_name = 'barangay_id'")->fetchColumn();
            return (bool)$colChk;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Fetch active barangays lookup if table exists, otherwise fallback to defaults.
     */
    public function getActiveBarangays(): array
    {
        $activeBarangays = [];
        try {
            $bStmt = $this->pdo->query("SELECT to_regclass('public.barangays')");
            if ($bStmt && $bStmt->fetchColumn()) {
                $activeBarangays = $this->pdo->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {}

        if (empty($activeBarangays)) {
            $activeBarangays = [
                ['barangay_id' => 1, 'barangay_name' => 'Barangay 1 (Poblacion)'],
                ['barangay_id' => 2, 'barangay_name' => 'Barangay 2 (San Isidro)'],
                ['barangay_id' => 3, 'barangay_name' => 'Barangay 3 (San Roque)'],
                ['barangay_id' => 4, 'barangay_name' => 'Barangay 4 (Santa Maria)'],
                ['barangay_id' => 5, 'barangay_name' => 'Barangay 5 (Santo Niño)']
            ];
        }
        return $activeBarangays;
    }

    /**
     * Fetch all active farmers with farm counts and total hectares.
     */
    public function getAll(string $search = '', string $sort = 'name', string $order = 'ASC', int $limit = 15, int $offset = 0): array
    {
        $sortColumns = [
            'id'   => 'f.farmer_id',
            'name' => 'f.last_name',
            'date' => 'f.registration_date',
        ];
        $sortSql = $sortColumns[$sort] ?? 'f.last_name';
        $orderSql = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $whereClauses = ["f.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(f.first_name ILIKE :s OR f.last_name ILIKE :s OR f.address ILIKE :s OR f.contact_no ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $hasBarangay = $this->hasBarangayColumn();
        $barangayField = $hasBarangay ? 'f.barangay_id,' : '0 AS barangay_id,';

        $limitSql = $limit > 0 ? "LIMIT {$limit} OFFSET {$offset}" : "";

        $query = "
            SELECT f.farmer_id, f.first_name, f.last_name, f.contact_no, f.address,
                   {$barangayField}
                   f.registration_date, f.created_at,
                   COALESCE(fm.farm_count, 0) AS active_farms,
                   COALESCE(fm.total_hectares, 0) AS total_hectares
            FROM farmers f
            LEFT JOIN (
                SELECT farmer_id, COUNT(*) AS farm_count, SUM(area_hectares) AS total_hectares
                FROM farms
                WHERE is_deleted = FALSE
                GROUP BY farmer_id
            ) fm ON fm.farmer_id = f.farmer_id
            {$whereSql}
            ORDER BY {$sortSql} {$orderSql}, f.farmer_id DESC
            {$limitSql}
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total active farmers matching search filters.
     */
    public function count(string $search = ''): int
    {
        $whereClauses = ["f.is_deleted = FALSE"];
        $params = [];

        if (trim($search) !== '') {
            $whereClauses[] = "(f.first_name ILIKE :s OR f.last_name ILIKE :s OR f.address ILIKE :s OR f.contact_no ILIKE :s)";
            $params[':s'] = "%" . trim($search) . "%";
        }
        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM farmers f {$whereSql}");
        $countStmt->execute($params);
        return (int)$countStmt->fetchColumn();
    }

    /**
     * Fetch active farmers for dropdown selection.
     */
    public function getActiveFarmers(): array
    {
        $stmt = $this->pdo->query("
            SELECT farmer_id, first_name, last_name
            FROM farmers
            WHERE is_deleted = FALSE
            ORDER BY last_name ASC, first_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single active farmer by ID.
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM farmers WHERE farmer_id = :id AND is_deleted = FALSE LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new farmer record.
     */
    public function create(array $data): int
    {
        $firstName  = trim((string)($data['first_name'] ?? ''));
        $lastName   = trim((string)($data['last_name'] ?? ''));
        $contactNo  = trim((string)($data['contact_no'] ?? ''));
        $barangayId = (int)($data['barangay_id'] ?? 0);
        $address    = trim((string)($data['address'] ?? ''));
        $regDate    = trim((string)($data['registration_date'] ?? date('Y-m-d')));
        $userId     = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $hasBarangay = $this->hasBarangayColumn();

        $this->pdo->beginTransaction();
        try {
            if ($hasBarangay) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO farmers
                        (first_name, last_name, contact_no, address, barangay_id, registration_date, is_deleted, created_at, updated_at, created_by, updated_by)
                    VALUES
                        (:first_name, :last_name, :contact_no, :address, :barangay_id, :reg_date, FALSE, NOW(), NOW(), :created_by, :updated_by)
                    RETURNING farmer_id
                ");
                $stmt->execute([
                    ':first_name'  => $firstName,
                    ':last_name'   => $lastName,
                    ':contact_no'  => $contactNo ?: null,
                    ':address'     => $address ?: null,
                    ':barangay_id' => $barangayId > 0 ? $barangayId : null,
                    ':reg_date'    => $regDate ?: date('Y-m-d'),
                    ':created_by'  => $userId,
                    ':updated_by'  => $userId,
                ]);
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO farmers
                        (first_name, last_name, contact_no, address, registration_date, is_deleted, created_at, updated_at, created_by, updated_by)
                    VALUES
                        (:first_name, :last_name, :contact_no, :address, :reg_date, FALSE, NOW(), NOW(), :created_by, :updated_by)
                    RETURNING farmer_id
                ");
                $stmt->execute([
                    ':first_name' => $firstName,
                    ':last_name'  => $lastName,
                    ':contact_no' => $contactNo ?: null,
                    ':address'    => $address ?: null,
                    ':reg_date'   => $regDate ?: date('Y-m-d'),
                    ':created_by' => $userId,
                    ':updated_by' => $userId,
                ]);
            }

            $newFarmerId = (int)$stmt->fetchColumn();

            $newData = [
                'farmer_id'         => $newFarmerId,
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'contact_no'        => $contactNo,
                'barangay_id'       => $barangayId,
                'address'           => $address,
                'registration_date' => $regDate,
            ];

            logActivity($this->pdo, 'CREATE', 'farmers', $newFarmerId, "Registered farmer '{$firstName} {$lastName}'", null, $newData);
            $this->pdo->commit();

            return $newFarmerId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing farmer record.
     */
    public function update(int $id, array $data): bool
    {
        $oldRow = $this->getById($id);
        if (!$oldRow) {
            throw new RuntimeException('Farmer record not found or already deleted.');
        }

        $firstName  = trim((string)($data['first_name'] ?? ''));
        $lastName   = trim((string)($data['last_name'] ?? ''));
        $contactNo  = trim((string)($data['contact_no'] ?? ''));
        $barangayId = (int)($data['barangay_id'] ?? 0);
        $address    = trim((string)($data['address'] ?? ''));
        $regDate    = trim((string)($data['registration_date'] ?? date('Y-m-d')));
        $userId     = isset($data['user_id']) ? (int)$data['user_id'] : null;

        $hasBarangay = $this->hasBarangayColumn();

        $this->pdo->beginTransaction();
        try {
            if ($hasBarangay) {
                $stmt = $this->pdo->prepare("
                    UPDATE farmers
                    SET first_name = :first_name,
                        last_name = :last_name,
                        contact_no = :contact_no,
                        address = :address,
                        barangay_id = :barangay_id,
                        registration_date = :reg_date,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE farmer_id = :id
                ");
                $stmt->execute([
                    ':first_name'  => $firstName,
                    ':last_name'   => $lastName,
                    ':contact_no'  => $contactNo ?: null,
                    ':address'     => $address ?: null,
                    ':barangay_id' => $barangayId > 0 ? $barangayId : null,
                    ':reg_date'    => $regDate ?: date('Y-m-d'),
                    ':updated_by'  => $userId,
                    ':id'          => $id,
                ]);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE farmers
                    SET first_name = :first_name,
                        last_name = :last_name,
                        contact_no = :contact_no,
                        address = :address,
                        registration_date = :reg_date,
                        updated_at = NOW(),
                        updated_by = :updated_by
                    WHERE farmer_id = :id
                ");
                $stmt->execute([
                    ':first_name' => $firstName,
                    ':last_name'  => $lastName,
                    ':contact_no' => $contactNo ?: null,
                    ':address'    => $address ?: null,
                    ':reg_date'   => $regDate ?: date('Y-m-d'),
                    ':updated_by' => $userId,
                    ':id'         => $id,
                ]);
            }

            $newData = [
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'contact_no'        => $contactNo,
                'barangay_id'       => $barangayId,
                'address'           => $address,
                'registration_date' => $regDate,
            ];

            logActivity($this->pdo, 'UPDATE', 'farmers', $id, "Updated farmer record '{$firstName} {$lastName}'", $oldRow, $newData);
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
     * Soft delete farmer record.
     * Includes active child-record check before soft-deleting parent records.
     */
    public function softDelete(int $id, int $userId): bool
    {
        $guard = canSoftDelete($this->pdo, 'farmers', $id);
        if (!$guard['allowed']) {
            throw new RuntimeException($guard['message']);
        }

        $stmt = $this->pdo->prepare("SELECT * FROM farmers WHERE farmer_id = :id");
        $stmt->execute([':id' => $id]);
        $oldRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldRow) {
            throw new RuntimeException('Farmer record not found.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE farmers
                SET is_deleted = TRUE, updated_at = NOW(), updated_by = :updated_by
                WHERE farmer_id = :id
            ");
            $stmt->execute([
                ':updated_by' => $userId,
                ':id'         => $id,
            ]);

            logActivity($this->pdo, 'SOFT_DELETE', 'farmers', $id, "Soft-deleted farmer '{$oldRow['first_name']} {$oldRow['last_name']}'", $oldRow);
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
     * Count total active farmers (e.g. for dashboard).
     */
    public function countActive(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM farmers WHERE is_deleted = FALSE");
        return (int)$stmt->fetchColumn();
    }
}
