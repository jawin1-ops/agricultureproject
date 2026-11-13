<?php

/**
 * example_module_handler.php
 * ─────────────────────────────────────────────────────────────
 * Template for any module's POST handler (farmer, farm, crop,
 * planting, harvest).
 *
 * Copy this file, rename it, and adjust the table name, primary
 * key column, and field list for each module.
 *
 * Actions handled:
 *   create → direct INSERT (Admin & Staff both allowed)
 *   update → Admin: direct UPDATE | Staff: insert change_request
 *   delete → Admin: direct DELETE | Staff: insert change_request
 * ─────────────────────────────────────────────────────────────
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireStaffOrAdmin();   // must be logged in as Admin or Staff

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

/* ── Config for this module ──────────────────────────────── */
$MODULE_TABLE  = 'farmer';          // change per module
$MODULE_PK     = 'farmer_id';       // change per module
$MODULE_FIELDS = [                  // list of editable columns
    'first_name', 'last_name', 'contact_number', 'address',
];

/* ── Dispatch ────────────────────────────────────────────── */
$action   = trim($_POST['action'] ?? '');
$recordId = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);

switch ($action) {

    /* ── CREATE (Admin & Staff direct) ──────────────────── */
    case 'create':
        $data   = [];
        $cols   = [];
        $phs    = [];
        $params = [];

        foreach ($MODULE_FIELDS as $field) {
            $val = $_POST[$field] ?? null;
            if ($val !== null && $val !== '') {
                $cols[]          = "\"$field\"";
                $phs[]           = ":$field";
                $params[":$field"] = $val;
            }
        }

        if (empty($cols)) {
            echo json_encode(['ok' => false, 'error' => 'No data provided.']);
            exit;
        }

        $sql = "INSERT INTO \"{$MODULE_TABLE}\" (" . implode(',', $cols) . ")
                VALUES ("                           . implode(',', $phs)  . ")";
        try {
            $pdo->prepare($sql)->execute($params);
            echo json_encode(['ok' => true, 'message' => 'Record created successfully.']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        break;

    /* ── UPDATE (Admin direct | Staff → change_request) ─── */
    case 'update':
        if (!$recordId) {
            echo json_encode(['ok' => false, 'error' => 'record_id required.']);
            exit;
        }

        $proposedData = [];
        foreach ($MODULE_FIELDS as $field) {
            if (isset($_POST[$field])) {
                $proposedData[$field] = $_POST[$field];
            }
        }

        if (empty($proposedData)) {
            echo json_encode(['ok' => false, 'error' => 'No fields to update.']);
            exit;
        }

        $result = submitOrRequestChange(
            'UPDATE',
            $MODULE_TABLE,
            $recordId,
            $proposedData,
            function () use ($pdo, $MODULE_TABLE, $MODULE_PK, $recordId, $proposedData) {
                $setClauses = [];
                $params     = [':record_id' => $recordId];
                foreach ($proposedData as $col => $val) {
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) continue;
                    $ph           = ":col_{$col}";
                    $setClauses[] = "\"$col\" = $ph";
                    $params[$ph]  = $val;
                }
                $sql = "UPDATE \"{$MODULE_TABLE}\" SET "
                     . implode(', ', $setClauses)
                     . " WHERE \"{$MODULE_PK}\" = :record_id";
                $pdo->prepare($sql)->execute($params);
            }
        );

        echo json_encode($result);
        break;

    /* ── DELETE (Admin direct | Staff → change_request) ─── */
    case 'delete':
        if (!$recordId) {
            echo json_encode(['ok' => false, 'error' => 'record_id required.']);
            exit;
        }

        $result = submitOrRequestChange(
            'DELETE',
            $MODULE_TABLE,
            $recordId,
            [],
            function () use ($pdo, $MODULE_TABLE, $MODULE_PK, $recordId) {
                $sql = "DELETE FROM \"{$MODULE_TABLE}\" WHERE \"{$MODULE_PK}\" = :id";
                $pdo->prepare($sql)->execute([':id' => $recordId]);
            }
        );

        echo json_encode($result);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => "Unknown action: {$action}"]);
}
