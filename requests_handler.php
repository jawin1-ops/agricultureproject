<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// --- Auth guards ---
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden: Admins only']);
    exit;
}

// --- Method guard ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// --- Input validation ---
$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$decision  = strtoupper(trim($_POST['decision'] ?? ''));

if (!$requestId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing request_id']);
    exit;
}
if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid decision; must be APPROVE or REJECT']);
    exit;
}

// --- Table whitelist & PK map (PLURAL names) ---
$allowedTables = ['farmers', 'farms', 'crops', 'plantings', 'harvests'];

$pkMap = [
    'farmers'   => 'farmer_id',
    'farms'     => 'farm_id',
    'crops'     => 'crop_id',
    'plantings' => 'planting_id',
    'harvests'  => 'harvest_id',
];

/**
 * Helper function to resolve or insert a barangay by name.
 */
function resolveOrInsertBarangay(PDO $pdo, string $name): ?int {
    $cleanName = trim($name);
    if ($cleanName === '') {
        return null;
    }

    // Check if barangay already exists (case-insensitive)
    $chk = $pdo->prepare("
        SELECT barangay_id
        FROM barangays
        WHERE LOWER(barangay_name) = LOWER(:name)
        LIMIT 1
    ");
    $chk->execute([':name' => $cleanName]);
    $existing = $chk->fetchColumn();

    if ($existing) {
        return (int)$existing;
    }

    // Insert new barangay if not found
    $ins = $pdo->prepare("
        INSERT INTO barangays (barangay_name)
        VALUES (:name)
        RETURNING barangay_id
    ");
    $ins->execute([':name' => $cleanName]);
    $newId = (int)$ins->fetchColumn();
    if (!$newId) {
        $newId = (int)$pdo->lastInsertId();
    }
    return $newId ?: null;
}

try {
    $pdo->beginTransaction();

    // --- Fetch the PENDING change_request row ---
    $stmt = $pdo->prepare(
        "SELECT * FROM change_requests WHERE request_id = :id AND status = 'PENDING'"
    );
    $stmt->execute([':id' => $requestId]);
    $changeReq = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$changeReq) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Change request not found or not PENDING']);
        exit;
    }

    $table       = $changeReq['target_table'];
    $action      = strtoupper($changeReq['action_type'] ?? '');
    $recordId    = $changeReq['record_id'];
    $requestedBy = $changeReq['requested_by'];

    // --- Validate table ---
    if (!in_array($table, $allowedTables, true)) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Target table not allowed: ' . $table]);
        exit;
    }

    $pkCol   = $pkMap[$table];
    $adminId = $_SESSION['user_id'] ?? null;

    if ($decision === 'APPROVE') {
        if ($action === 'INSERT') {
            // --- Decode proposed data ---
            $proposedData = json_decode($changeReq['proposed_data'], true);
            if (!is_array($proposedData)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Invalid proposed_data JSON for INSERT']);
                exit;
            }

            // --- Special handling: farmers and farms with barangay resolution/insertion ---
            if (in_array($table, ['farmers', 'farms'], true)) {
                $bName = null;
                if (isset($proposedData['barangay_name'])) {
                    $bName = trim((string)$proposedData['barangay_name']);
                    unset($proposedData['barangay_name']);
                } elseif (isset($proposedData['new_barangay_name'])) {
                    $bName = trim((string)$proposedData['new_barangay_name']);
                    unset($proposedData['new_barangay_name'], $proposedData['new_city_municipality'], $proposedData['new_province']);
                }

                if ($bName !== null) {
                    if ($bName !== '') {
                        $proposedData['barangay_id'] = resolveOrInsertBarangay($pdo, $bName);
                    } else {
                        $proposedData['barangay_id'] = null;
                    }
                }
            }

            if ($table === 'farms' && empty($proposedData['farmer_id']) && !empty($proposedData['farmer_search'])) {
                if (preg_match('/\(ID:\s*(\d+)\)/i', (string)$proposedData['farmer_search'], $m)) {
                    $proposedData['farmer_id'] = (int)$m[1];
                }
                unset($proposedData['farmer_search']);
            }

            $colNames     = [];
            $placeholders = [];
            $params       = [];

            foreach ($proposedData as $col => $val) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                    continue; // skip unsafe column names
                }
                if (in_array(strtolower($col), ['created_by', 'updated_by', 'created_at', 'updated_at', $pkCol], true)) {
                    continue;
                }
                $placeholder          = ':col_' . $col;
                $colNames[]           = '"' . $col . '"';
                $placeholders[]       = $placeholder;
                $params[$placeholder] = $val;
            }

            // Set created_by and updated_by to requested_by
            $colNames[]             = '"created_by"';
            $placeholders[]         = ':_created_by';
            $params[':_created_by'] = $requestedBy;

            $colNames[]             = '"updated_by"';
            $placeholders[]         = ':_updated_by';
            $params[':_updated_by'] = $requestedBy;

            // Set timestamps
            $colNames[]     = '"created_at"';
            $placeholders[] = 'NOW()';

            $colNames[]     = '"updated_at"';
            $placeholders[] = 'NOW()';

            $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $colNames) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $ins = $pdo->prepare($sql);
            $ins->execute($params);

        } elseif ($action === 'UPDATE') {
            // --- Decode proposed data ---
            $proposedData = json_decode($changeReq['proposed_data'], true);
            if (!is_array($proposedData)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Invalid proposed_data JSON for UPDATE']);
                exit;
            }

            if (empty($recordId)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Missing record_id for UPDATE']);
                exit;
            }

            // --- Special handling: farmers and farms with barangay resolution/insertion ---
            if (in_array($table, ['farmers', 'farms'], true)) {
                $bName = null;
                if (isset($proposedData['barangay_name'])) {
                    $bName = trim((string)$proposedData['barangay_name']);
                    unset($proposedData['barangay_name']);
                } elseif (isset($proposedData['new_barangay_name'])) {
                    $bName = trim((string)$proposedData['new_barangay_name']);
                    unset($proposedData['new_barangay_name'], $proposedData['new_city_municipality'], $proposedData['new_province']);
                }

                if ($bName !== null) {
                    if ($bName !== '') {
                        $proposedData['barangay_id'] = resolveOrInsertBarangay($pdo, $bName);
                    } else {
                        $proposedData['barangay_id'] = null;
                    }
                }
            }

            if ($table === 'farms' && empty($proposedData['farmer_id']) && !empty($proposedData['farmer_search'])) {
                if (preg_match('/\(ID:\s*(\d+)\)/i', (string)$proposedData['farmer_search'], $m)) {
                    $proposedData['farmer_id'] = (int)$m[1];
                }
                unset($proposedData['farmer_search']);
            }

            // --- Build SET clause with sanitised column names ---
            $setClauses = [];
            $params     = [':record_id' => $recordId];

            foreach ($proposedData as $col => $val) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                    continue; // skip unsafe column names
                }
                if (in_array(strtolower($col), ['created_by', 'updated_by', 'created_at', 'updated_at', $pkCol], true)) {
                    continue;
                }
                $placeholder          = ':col_' . $col;
                $setClauses[]         = '"' . $col . '" = ' . $placeholder;
                $params[$placeholder] = $val;
            }

            // Always set updated_by = requested_by and updated_at = NOW()
            $setClauses[]           = '"updated_by" = :_updated_by';
            $setClauses[]           = '"updated_at" = NOW()';
            $params[':_updated_by'] = $requestedBy;

            if (empty($setClauses)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'No valid columns to update']);
                exit;
            }

            $sql = 'UPDATE "' . $table . '" SET ' . implode(', ', $setClauses)
                 . ' WHERE "' . $pkCol . '" = :record_id';

            $upd = $pdo->prepare($sql);
            $upd->execute($params);

        } elseif ($action === 'DELETE') {
            if (empty($recordId)) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Missing record_id for DELETE']);
                exit;
            }

            $del = $pdo->prepare(
                'DELETE FROM "' . $table . '" WHERE "' . $pkCol . '" = :record_id'
            );
            $del->execute([':record_id' => $recordId]);

        } else {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'Unsupported action_type: ' . $action]);
            exit;
        }

        // --- Mark change_request as APPROVED ---
        $approve = $pdo->prepare(
            "UPDATE change_requests
             SET status = 'APPROVED', reviewed_at = NOW(), reviewed_by = :admin_id
             WHERE request_id = :id"
        );
        $approve->execute([':admin_id' => $adminId, ':id' => $requestId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Change request approved successfully']);

    } elseif ($decision === 'REJECT') {
        // --- Mark change_request as REJECTED ---
        $reject = $pdo->prepare(
            "UPDATE change_requests
             SET status = 'REJECTED', reviewed_at = NOW(), reviewed_by = :admin_id
             WHERE request_id = :id"
        );
        $reject->execute([':admin_id' => $adminId, ':id' => $requestId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Change request rejected']);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
