<?php
/**
 * farmers.php
 * Farmer Management – role-aware (Administrator / Staff).
 * Admin (role_id = 1): direct INSERT, UPDATE, DELETE on farmers table.
 *                      Resolves or inserts typed barangay directly.
 * Staff (role_id = 2): VIEW all records; ALL write actions (Add, Edit, Delete)
 *                      go to change_requests (PENDING) for Admin approval,
 *                      including typed barangay_name in proposed_data.
 */

ob_start();
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/includes/auth.php';
ob_clean();

if (!isset($pdo) || !$pdo) {
    require_once __DIR__ . '/config/database.php';
}

requireLogin('login.html');

$user     = currentUser();
$roleId   = currentRoleId();
$roleName = ($roleId === ROLE_ADMIN) ? 'Administrator' : 'Staff';

/**
 * Helper function for Admin to resolve or insert a barangay by name.
 */
function resolveBarangayId(PDO $pdo, string $name): ?int {
    $cleanName = trim($name);
    if ($cleanName === '') {
        return null;
    }

    // Case-insensitive check to prevent duplicates
    $stmt = $pdo->prepare("
        SELECT barangay_id
        FROM barangays
        WHERE LOWER(barangay_name) = LOWER(:name)
        LIMIT 1
    ");
    $stmt->execute([':name' => $cleanName]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        return (int)$existing;
    }

    // Insert new barangay
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

// ─── POST Handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD FARMER
    if ($action === 'add') {
        $firstName     = trim($_POST['first_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $contactNo     = trim($_POST['contact_no'] ?? '');
        $barangayName  = trim($_POST['barangay_name'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? '');
        $userId        = (int)$user['user_id'];

        if ($firstName === '' || $lastName === '') {
            $_SESSION['flash_msg']  = 'First name and last name are required.';
            $_SESSION['flash_type'] = 'error';
        } elseif ($barangayName === '') {
            $_SESSION['flash_msg']  = 'Barangay name is required.';
            $_SESSION['flash_type'] = 'error';
        } elseif (isAdmin()) {
            // Admin (role_id = 1): Direct INSERT (resolves or inserts barangay first)
            try {
                $barangayId = resolveBarangayId($pdo, $barangayName);

                $stmt = $pdo->prepare("
                    INSERT INTO farmers
                        (first_name, last_name, contact_no, barangay_id, address_detail,
                         created_by, updated_by, created_at, updated_at)
                    VALUES
                        (:first_name, :last_name, :contact_no, :barangay_id, :address_detail,
                         :created_by, :updated_by, NOW(), NOW())
                ");
                $stmt->execute([
                    ':first_name'     => $firstName,
                    ':last_name'      => $lastName,
                    ':contact_no'     => $contactNo !== '' ? $contactNo : null,
                    ':barangay_id'    => $barangayId,
                    ':address_detail' => $addressDetail !== '' ? $addressDetail : null,
                    ':created_by'     => $userId,
                    ':updated_by'     => $userId,
                ]);

                $_SESSION['flash_msg']  = 'Farmer added successfully.';
                $_SESSION['flash_type'] = 'success';
            } catch (Throwable $e) {
                $_SESSION['flash_msg']  = 'Error adding farmer: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            // Staff (role_id = 2): Do NOT touch barangays or farmers. Insert PENDING into change_requests
            $fields = [
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'contact_no'     => $contactNo !== '' ? $contactNo : null,
                'barangay_name'  => $barangayName,
                'address_detail' => $addressDetail !== '' ? $addressDetail : null,
            ];

            $proposedJson = json_encode($fields);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO change_requests
                        (requested_by, target_table, record_id, action_type, proposed_data, status, created_at)
                    VALUES
                        (:requested_by, 'farmers', :record_id, 'INSERT', :proposed_data, 'PENDING', NOW())
                ");
                $stmt->bindValue(':requested_by', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':record_id', null, PDO::PARAM_NULL);
                $stmt->bindValue(':proposed_data', $proposedJson, PDO::PARAM_STR);
                $stmt->execute();

                $_SESSION['flash_msg']  = 'Your request has been submitted for Admin approval.';
                $_SESSION['flash_type'] = 'pending';
            } catch (Throwable $e) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO change_requests
                            (requested_by, target_table, record_id, action_type, proposed_data, status)
                        VALUES
                            (:requested_by, 'farmers', :record_id, 'INSERT', :proposed_data, 'PENDING')
                    ");
                    $stmt->bindValue(':requested_by', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':record_id', null, PDO::PARAM_NULL);
                    $stmt->bindValue(':proposed_data', $proposedJson, PDO::PARAM_STR);
                    $stmt->execute();

                    $_SESSION['flash_msg']  = 'Your request has been submitted for Admin approval.';
                    $_SESSION['flash_type'] = 'pending';
                } catch (Throwable $e2) {
                    $_SESSION['flash_msg']  = 'Error submitting request: ' . $e2->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
        }
        header('Location: farmers.php');
        exit;
    }

    // 2. EDIT FARMER
    if ($action === 'edit') {
        $farmerId      = (int)($_POST['farmer_id'] ?? 0);
        $firstName     = trim($_POST['first_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $contactNo     = trim($_POST['contact_no'] ?? '');
        $barangayName  = trim($_POST['barangay_name'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? '');
        $userId        = (int)$user['user_id'];

        if ($farmerId <= 0 || $firstName === '' || $lastName === '') {
            $_SESSION['flash_msg']  = 'Invalid farmer ID or missing required name fields.';
            $_SESSION['flash_type'] = 'error';
        } elseif ($barangayName === '') {
            $_SESSION['flash_msg']  = 'Barangay name is required.';
            $_SESSION['flash_type'] = 'error';
        } elseif (isAdmin()) {
            // Admin (role_id = 1): Direct UPDATE (resolves or inserts barangay first)
            try {
                $barangayId = resolveBarangayId($pdo, $barangayName);

                $stmt = $pdo->prepare("
                    UPDATE farmers
                    SET first_name     = :first_name,
                        last_name      = :last_name,
                        contact_no     = :contact_no,
                        barangay_id    = :barangay_id,
                        address_detail = :address_detail,
                        updated_by     = :updated_by,
                        updated_at     = NOW()
                    WHERE farmer_id    = :farmer_id
                ");
                $stmt->execute([
                    ':first_name'     => $firstName,
                    ':last_name'      => $lastName,
                    ':contact_no'     => $contactNo !== '' ? $contactNo : null,
                    ':barangay_id'    => $barangayId,
                    ':address_detail' => $addressDetail !== '' ? $addressDetail : null,
                    ':updated_by'     => $userId,
                    ':farmer_id'      => $farmerId,
                ]);

                $_SESSION['flash_msg']  = 'Farmer updated successfully.';
                $_SESSION['flash_type'] = 'success';
            } catch (Throwable $e) {
                $_SESSION['flash_msg']  = 'Error updating farmer: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            // Staff (role_id = 2): Do NOT touch barangays or farmers. Put fields in proposed_data
            $fields = [
                'first_name'     => $firstName,
                'last_name'      => $lastName,
                'contact_no'     => $contactNo !== '' ? $contactNo : null,
                'barangay_name'  => $barangayName,
                'address_detail' => $addressDetail !== '' ? $addressDetail : null,
            ];

            $proposedJson = json_encode($fields);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO change_requests
                        (requested_by, target_table, record_id, action_type, proposed_data, status, created_at)
                    VALUES
                        (:requested_by, 'farmers', :record_id, 'UPDATE', :proposed_data, 'PENDING', NOW())
                ");
                $stmt->bindValue(':requested_by', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':record_id', $farmerId, PDO::PARAM_INT);
                $stmt->bindValue(':proposed_data', $proposedJson, PDO::PARAM_STR);
                $stmt->execute();

                $_SESSION['flash_msg']  = 'Your request has been submitted for Admin approval.';
                $_SESSION['flash_type'] = 'pending';
            } catch (Throwable $e) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO change_requests
                            (requested_by, target_table, record_id, action_type, proposed_data, status)
                        VALUES
                            (:requested_by, 'farmers', :record_id, 'UPDATE', :proposed_data, 'PENDING')
                    ");
                    $stmt->bindValue(':requested_by', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':record_id', $farmerId, PDO::PARAM_INT);
                    $stmt->bindValue(':proposed_data', $proposedJson, PDO::PARAM_STR);
                    $stmt->execute();

                    $_SESSION['flash_msg']  = 'Your request has been submitted for Admin approval.';
                    $_SESSION['flash_type'] = 'pending';
                } catch (Throwable $e2) {
                    $_SESSION['flash_msg']  = 'Error submitting request: ' . $e2->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
        }
        header('Location: farmers.php');
        exit;
    }

    // 3. DELETE FARMER
    if ($action === 'delete') {
        $farmerId = (int)($_POST['farmer_id'] ?? 0);
        $userId   = (int)$user['user_id'];

        if ($farmerId <= 0) {
            $_SESSION['flash_msg']  = 'Invalid farmer ID for deletion.';
            $_SESSION['flash_type'] = 'error';
        } elseif (isAdmin()) {
            // Admin (role_id = 1): Direct DELETE
            try {
                $stmt = $pdo->prepare("DELETE FROM farmers WHERE farmer_id = :farmer_id");
                $stmt->execute([':farmer_id' => $farmerId]);

                $_SESSION['flash_msg']  = 'Farmer deleted successfully.';
                $_SESSION['flash_type'] = 'success';
            } catch (Throwable $e) {
                $_SESSION['flash_msg']  = 'Error deleting farmer: ' . $e->getMessage();
                $_SESSION['flash_type'] = 'error';
            }
        } else {
            // Staff (role_id = 2): Do NOT touch farmers. Insert PENDING into change_requests
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO change_requests
                        (requested_by, target_table, record_id, action_type, proposed_data, status, created_at)
                    VALUES
                        (:requested_by, 'farmers', :record_id, 'DELETE', :proposed_data, 'PENDING', NOW())
                ");
                $stmt->bindValue(':requested_by', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':record_id', $farmerId, PDO::PARAM_INT);
                $stmt->bindValue(':proposed_data', null, PDO::PARAM_NULL);
                $stmt->execute();

                $_SESSION['flash_msg']  = 'Your request has been submitted for Admin approval.';
                $_SESSION['flash_type'] = 'pending';
            } catch (Throwable $e) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO change_requests
                            (requested_by, target_table, record_id, action_type, proposed_data, status)
                        VALUES
                            (:requested_by, 'farmers', :record_id, 'DELETE', :proposed_data, 'PENDING')
                    ");
                    $stmt->bindValue(':requested_by', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':record_id', $farmerId, PDO::PARAM_INT);
                    $stmt->bindValue(':proposed_data', null, PDO::PARAM_NULL);
                    $stmt->execute();

                    $_SESSION['flash_msg']  = 'Your request has been submitted for Admin approval.';
                    $_SESSION['flash_type'] = 'pending';
                } catch (Throwable $e2) {
                    $_SESSION['flash_msg']  = 'Error submitting request: ' . $e2->getMessage();
                    $_SESSION['flash_type'] = 'error';
                }
            }
        }
        header('Location: farmers.php');
        exit;
    }
}

// ─── Fetch Data (Rule 1: Both Admin and Staff view all records) ──
$farmers = [];
try {
    $stmt = $pdo->query("
        SELECT f.farmer_id, f.first_name, f.last_name, f.contact_no,
               f.barangay_id, f.address_detail, f.created_at, f.updated_at,
               b.barangay_name, b.city_municipality, b.province
        FROM farmers f
        LEFT JOIN barangays b ON b.barangay_id = f.barangay_id
        ORDER BY f.farmer_id DESC
    ");
    $farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $fetchError = $e->getMessage();
}

$barangays = [];
try {
    $stmt = $pdo->query("
        SELECT barangay_name
        FROM barangays
        ORDER BY barangay_name ASC
    ");
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // If table fetch failed, barangays stays empty
}

// Flash message handling
$flashMsg  = $_SESSION['flash_msg']  ?? ($_GET['msg'] ?? '');
$flashType = $_SESSION['flash_type'] ?? ($_GET['type'] ?? 'success');
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Farmers – Agriculture Information System</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #f3f4f6;
    color: #111827;
    min-height: 100vh;
  }

  /* ── Nav ─────────────────────────────────────────────────── */
  nav {
    background: linear-gradient(90deg, #1a4731 0%, #2d7a4f 100%);
    color: #fff;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 58px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,.25);
  }
  .brand {
    font-weight: 700;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: #fff;
  }
  .brand svg { width: 26px; height: 26px; flex-shrink: 0; }

  .nav-links { display: flex; gap: 4px; }
  .nav-links a {
    color: rgba(255,255,255,.82);
    text-decoration: none;
    padding: 6px 13px;
    border-radius: 6px;
    font-size: .88rem;
    transition: background .15s, color .15s;
  }
  .nav-links a:hover { background: rgba(255,255,255,.15); color: #fff; }
  .nav-links a.active { background: rgba(255,255,255,.18); color: #fff; font-weight: 600; }

  .user-area { display: flex; align-items: center; gap: 10px; font-size: .85rem; }
  .user-name { opacity: .9; }
  .role-badge {
    background: rgba(255,255,255,.22);
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 700;
    letter-spacing: .03em;
    text-transform: uppercase;
  }
  .role-badge.admin { background: rgba(251,191,36,.35); color: #fef3c7; }
  .logout-btn {
    background: rgba(239,68,68,.75);
    color: #fff;
    border: none;
    padding: 5px 13px;
    border-radius: 6px;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s;
  }
  .logout-btn:hover { background: #dc2626; }

  /* ── Page ─────────────────────────────────────────────────── */
  .page { max-width: 1140px; margin: 0 auto; padding: 32px 20px 48px; }

  .page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
  }
  .page-title h1 {
    font-size: 1.45rem;
    color: #1a4731;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .page-title p { color: #6b7280; font-size: .88rem; margin-top: 2px; }

  .back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #2d7a4f;
    text-decoration: none;
    font-size: .85rem;
    font-weight: 600;
    margin-bottom: 6px;
    transition: color .15s;
  }
  .back-btn:hover { color: #1a4731; text-decoration: underline; }
  .back-btn svg { width: 16px; height: 16px; }

  .btn-primary {
    background: linear-gradient(135deg, #1a4731 0%, #2d7a4f 100%);
    color: #fff;
    border: none;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 2px 6px rgba(26,71,49,.2);
    transition: transform .12s, box-shadow .15s, opacity .15s;
  }
  .btn-primary:hover {
    opacity: .95;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(26,71,49,.3);
  }
  .btn-primary svg { width: 18px; height: 18px; }

  /* ── Staff Information Banner ────────────────────────────── */
  .staff-notice {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 12px 18px;
    margin-bottom: 24px;
    font-size: .86rem;
    color: #92400e;
  }
  .staff-notice svg { width: 20px; height: 20px; flex-shrink: 0; color: #d97706; }

  /* ── Flash Banners ────────────────────────────────────────── */
  .alert-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 24px;
    font-size: .9rem;
    font-weight: 500;
    animation: fadeIn .2s ease;
  }
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .alert-banner.success {
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
  }
  .alert-banner.pending {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
  }
  .alert-banner.error {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
  }
  .alert-banner svg { width: 22px; height: 22px; flex-shrink: 0; }

  /* ── Section Block & Table ────────────────────────────────── */
  .section-block {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 1px 6px rgba(0,0,0,.07);
    overflow: hidden;
  }
  .block-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    flex-wrap: wrap;
    gap: 12px;
  }
  .block-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .block-header h3 {
    font-size: 1rem;
    font-weight: 700;
    color: #1a4731;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .badge-pill {
    background: #e5e7eb;
    color: #374151;
    font-size: .75rem;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 999px;
  }

  .table-search {
    position: relative;
    width: 260px;
  }
  .table-search input {
    width: 100%;
    padding: 7px 12px 7px 34px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: .84rem;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    font-family: inherit;
  }
  .table-search input:focus {
    border-color: #2d7a4f;
    box-shadow: 0 0 0 3px rgba(45,122,79,.15);
  }
  .table-search svg {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: #9ca3af;
  }

  .table-responsive {
    overflow-x: auto;
    width: 100%;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: .87rem;
    text-align: left;
  }
  thead { background: #f9fafb; }
  thead th {
    padding: 12px 18px;
    font-size: .76rem;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
    border-bottom: 1px solid #e5e7eb;
  }
  tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: background .12s;
  }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: #f9fafb; }
  td {
    padding: 13px 18px;
    vertical-align: middle;
  }

  .farmer-name {
    font-weight: 600;
    color: #111827;
  }
  .farmer-sub {
    font-size: .78rem;
    color: #6b7280;
    margin-top: 2px;
  }

  .tag-barangay {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 600;
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #dcfce7;
  }
  .tag-unassigned {
    color: #9ca3af;
    font-style: italic;
    font-size: .82rem;
  }

  .date-text {
    font-size: .8rem;
    color: #6b7280;
    white-space: nowrap;
  }

  /* ── Action Buttons ───────────────────────────────────────── */
  .actions-cell {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .btn-action {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 11px;
    border-radius: 6px;
    font-size: .78rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: background .14s, color .14s;
    text-decoration: none;
  }
  .btn-action.edit {
    background: #eff6ff;
    color: #1d4ed8;
  }
  .btn-action.edit:hover { background: #dbeafe; }
  .btn-action.delete {
    background: #fef2f2;
    color: #b91c1c;
  }
  .btn-action.delete:hover { background: #fee2e2; }

  /* ── Empty State ─────────────────────────────────────────── */
  .empty-state {
    text-align: center;
    padding: 48px 24px;
    color: #9ca3af;
  }
  .empty-state svg {
    width: 44px;
    height: 44px;
    margin-bottom: 12px;
    opacity: .45;
    color: #1a4731;
  }
  .empty-state p { font-size: .95rem; color: #4b5551; font-weight: 500; }
  .empty-state span { font-size: .82rem; color: #9ca3af; }

  /* ── Modals ───────────────────────────────────────────────── */
  .modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(17,24,39,.55);
    backdrop-filter: blur(2px);
    z-index: 200;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .modal-overlay.active { display: flex; }

  .modal-box {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    box-shadow: 0 10px 40px rgba(0,0,0,.25);
    animation: modalSlide .2s ease-out;
    overflow-y: auto;
  }
  @keyframes modalSlide {
    from { opacity: 0; transform: translateY(18px) scale(.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }

  .modal-header {
    background: linear-gradient(90deg, #1a4731 0%, #2d7a4f 100%);
    color: #fff;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .modal-header h3 {
    font-size: 1.05rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .modal-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.8);
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    transition: color .15s;
  }
  .modal-close:hover { color: #fff; }

  .modal-body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }
  .form-group label {
    font-size: .82rem;
    font-weight: 600;
    color: #374151;
  }
  .form-group label .req { color: #ef4444; }

  .form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: .88rem;
    font-family: inherit;
    color: #111827;
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
  }
  .form-control:focus {
    border-color: #2d7a4f;
    box-shadow: 0 0 0 3px rgba(45,122,79,.15);
  }
  textarea.form-control {
    resize: vertical;
    min-height: 72px;
  }

  .staff-modal-note {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: .8rem;
    color: #1e40af;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .staff-modal-note svg { width: 18px; height: 18px; flex-shrink: 0; color: #2563eb; }

  .modal-footer {
    padding: 14px 20px;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
  .btn-secondary {
    background: #fff;
    color: #374151;
    border: 1px solid #d1d5db;
    padding: 8px 16px;
    border-radius: 7px;
    font-size: .85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .12s;
  }
  .btn-secondary:hover { background: #f3f4f6; }

  /* ── Toast Notification ──────────────────────────────────── */
  #toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    min-width: 250px;
    max-width: 420px;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: .88rem;
    font-weight: 600;
    color: #fff;
    box-shadow: 0 4px 20px rgba(0,0,0,.18);
    opacity: 0;
    transform: translateY(10px);
    transition: opacity .25s, transform .25s;
    pointer-events: none;
    z-index: 999;
  }
  #toast.show { opacity: 1; transform: translateY(0); }
  #toast.success { background: #16a34a; }
  #toast.pending { background: #2563eb; }
  #toast.error   { background: #dc2626; }

  @media (max-width: 680px) {
    .nav-links { display: none; }
    .form-row { grid-template-columns: 1fr; }
    .table-search { width: 100%; }
  }
</style>
</head>
<body>

<!-- ── Navbar ──────────────────────────────────────────────── -->
<nav>
  <a class="brand" href="index.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 20v-8"/>
      <path d="M12 12C12 12 8 10 8 6c0 0 4 1 4 6z" fill="rgba(255,255,255,.4)"/>
      <path d="M12 12c0 0 4-2 4-6 0 0-4 1-4 6z" fill="rgba(255,255,255,.75)"/>
    </svg>
    Agriculture IS
  </a>

  <div class="nav-links">
    <a href="index.php">Dashboard</a>
    <a href="farmers.php" class="active">Farmers</a>
    <a href="farms.php">Farms</a>
    <a href="crops.php">Crops</a>
    <a href="plantings.php">Plantings</a>
    <a href="harvests.php">Harvests</a>
    <?php if (isAdmin()): ?>
      <a href="requests.php">Approval Queue</a>
    <?php endif; ?>
  </div>

  <div class="user-area">
    <span class="user-name"><?= htmlspecialchars($user['username']) ?></span>
    <span class="role-badge <?= isAdmin() ? 'admin' : '' ?>">
      <?= htmlspecialchars($roleName) ?>
    </span>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>

<!-- ── Main Page Body ──────────────────────────────────────── -->
<div class="page">

  <!-- Breadcrumb / Back Link -->
  <a href="index.php" class="back-btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="19" y1="12" x2="5" y2="12"></line>
      <polyline points="12 19 5 12 12 5"></polyline>
    </svg>
    Back to Dashboard
  </a>

  <!-- Header -->
  <div class="page-header">
    <div class="page-title">
      <h1>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1a4731" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        Farmers Directory
      </h1>
      <p>View registered farmers, manage personal records, and maintain agricultural affiliations.</p>
    </div>
    <button type="button" class="btn-primary" onclick="openAddModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="12" y1="5" x2="12" y2="19"></line>
        <line x1="5" y1="12" x2="19" y2="12"></line>
      </svg>
      Add Farmer
    </button>
  </div>

  <!-- Role Notice for Staff -->
  <?php if (isStaff()): ?>
  <div class="staff-notice">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"></circle>
      <line x1="12" y1="8" x2="12" y2="12"></line>
      <line x1="12" y1="16" x2="12.01" y2="16"></line>
    </svg>
    <div>
      <strong>Staff Access Mode:</strong> You can view all records. All additions, edits, and deletions will be submitted as change requests for Administrator approval.
    </div>
  </div>
  <?php endif; ?>

  <!-- Confirmation / Flash Banner -->
  <?php if ($flashMsg): ?>
  <div class="alert-banner <?= htmlspecialchars($flashType) ?>">
    <?php if ($flashType === 'success'): ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
        <polyline points="22 4 12 14.01 9 11.01"></polyline>
      </svg>
    <?php elseif ($flashType === 'pending'): ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <polyline points="12 6 12 12 16 14"></polyline>
      </svg>
    <?php else: ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="15" y1="9" x2="9" y2="15"></line>
        <line x1="9" y1="9" x2="15" y2="15"></line>
      </svg>
    <?php endif; ?>
    <div><?= htmlspecialchars($flashMsg) ?></div>
  </div>
  <?php endif; ?>

  <!-- Farmers Directory Table Block -->
  <div class="section-block">
    <div class="block-header">
      <div class="block-header-left">
        <h3>Registered Farmers</h3>
        <span class="badge-pill" id="rowCountBadge"><?= count($farmers) ?> total</span>
      </div>
      <div class="table-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="text" id="searchInput" placeholder="Search by name, contact, barangay..." onkeyup="filterTable()">
      </div>
    </div>

    <div class="table-responsive">
      <table id="farmersTable">
        <thead>
          <tr>
            <th style="width: 60px;">ID</th>
            <th>Farmer Name</th>
            <th>Contact No.</th>
            <th>Barangay</th>
            <th>Address Details</th>
            <th>Registered</th>
            <th style="text-align: right; width: 140px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($farmers)): ?>
          <tr id="noDataRow">
            <td colspan="7">
              <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                  <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <p>No farmers registered yet</p>
                <span>Click the "Add Farmer" button above to create the first record.</span>
              </div>
            </td>
          </tr>
          <?php else: foreach ($farmers as $f): ?>
          <tr class="farmer-row"
              data-search="<?= htmlspecialchars(strtolower($f['first_name'] . ' ' . $f['last_name'] . ' ' . ($f['contact_no'] ?? '') . ' ' . ($f['barangay_name'] ?? '') . ' ' . ($f['address_detail'] ?? ''))) ?>">
            <td style="color: #6b7280; font-weight: 600;">#<?= $f['farmer_id'] ?></td>
            <td>
              <div class="farmer-name"><?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?></div>
            </td>
            <td>
              <?= !empty($f['contact_no']) ? htmlspecialchars($f['contact_no']) : '<span class="tag-unassigned">None</span>' ?>
            </td>
            <td>
              <?php if (!empty($f['barangay_name'])): ?>
                <span class="tag-barangay"><?= htmlspecialchars($f['barangay_name']) ?></span>
                <?php if (!empty($f['city_municipality'])): ?>
                  <div class="farmer-sub"><?= htmlspecialchars($f['city_municipality']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span class="tag-unassigned">Not assigned</span>
              <?php endif; ?>
            </td>
            <td>
              <?= !empty($f['address_detail']) ? htmlspecialchars($f['address_detail']) : '<span class="tag-unassigned">—</span>' ?>
            </td>
            <td>
              <span class="date-text">
                <?= $f['created_at'] ? date('M j, Y', strtotime($f['created_at'])) : '—' ?>
              </span>
            </td>
            <td style="text-align: right;">
              <div class="actions-cell" style="justify-content: flex-end;">
                <button type="button" class="btn-action edit"
                  data-id="<?= $f['farmer_id'] ?>"
                  data-fname="<?= htmlspecialchars($f['first_name'], ENT_QUOTES) ?>"
                  data-lname="<?= htmlspecialchars($f['last_name'], ENT_QUOTES) ?>"
                  data-contact="<?= htmlspecialchars($f['contact_no'] ?? '', ENT_QUOTES) ?>"
                  data-barangay="<?= htmlspecialchars($f['barangay_name'] ?? '', ENT_QUOTES) ?>"
                  data-address="<?= htmlspecialchars($f['address_detail'] ?? '', ENT_QUOTES) ?>"
                  onclick="openEditModal(this)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                  </svg>
                  Edit
                </button>
                <form method="POST" action="farmers.php" style="display:inline;"
                      onsubmit="return confirmDelete('<?= htmlspecialchars(addslashes($f['first_name'] . ' ' . $f['last_name'])) ?>');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="farmer_id" value="<?= $f['farmer_id'] ?>">
                  <button type="submit" class="btn-action delete">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="3 6 5 6 21 6"></polyline>
                      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    </svg>
                    Delete
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ── Modal: Add Farmer ────────────────────────────────────── -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="8.5" cy="7" r="4"/>
          <line x1="20" y1="8" x2="20" y2="14"/>
          <line x1="23" y1="11" x2="17" y2="11"/>
        </svg>
        Add New Farmer
      </h3>
      <button type="button" class="modal-close" onclick="closeModal('addModal')">&times;</button>
    </div>
    <form method="POST" action="farmers.php">
      <input type="hidden" name="action" value="add">
      <div class="modal-body">
        <?php if (isStaff()): ?>
        <div class="staff-modal-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
          <div>As Staff, adding this farmer will create a <strong>Pending Approval Request</strong> for Administrator review.</div>
        </div>
        <?php endif; ?>

        <div class="form-row">
          <div class="form-group">
            <label for="add_first_name">First Name <span class="req">*</span></label>
            <input type="text" id="add_first_name" name="first_name" class="form-control" placeholder="e.g. Juan" required>
          </div>
          <div class="form-group">
            <label for="add_last_name">Last Name <span class="req">*</span></label>
            <input type="text" id="add_last_name" name="last_name" class="form-control" placeholder="e.g. dela Cruz" required>
          </div>
        </div>

        <div class="form-group">
          <label for="add_contact_no">Contact Number</label>
          <input type="text" id="add_contact_no" name="contact_no" class="form-control" placeholder="e.g. 09171234567">
        </div>

        <div class="form-group">
          <label for="add_barangay_name">Barangay <span class="req">*</span></label>
          <input type="text" id="add_barangay_name" name="barangay_name" list="barangay-list" class="form-control" placeholder="Enter barangay name" required autocomplete="off">
        </div>

        <div class="form-group">
          <label for="add_address_detail">Address Detail</label>
          <textarea id="add_address_detail" name="address_detail" class="form-control" placeholder="Purok, Sitio, Street, or Landmark"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-primary">
          <?= isAdmin() ? 'Save Farmer' : 'Submit for Approval' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Edit Farmer ───────────────────────────────────── -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
        </svg>
        Edit Farmer Details
      </h3>
      <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>
    <form method="POST" action="farmers.php">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="farmer_id" id="edit_farmer_id">
      <div class="modal-body">
        <?php if (isStaff()): ?>
        <div class="staff-modal-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
          <div>As Staff, updating this record will create a <strong>Pending Approval Request</strong> for Administrator review.</div>
        </div>
        <?php endif; ?>

        <div class="form-row">
          <div class="form-group">
            <label for="edit_first_name">First Name <span class="req">*</span></label>
            <input type="text" id="edit_first_name" name="first_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="edit_last_name">Last Name <span class="req">*</span></label>
            <input type="text" id="edit_last_name" name="last_name" class="form-control" required>
          </div>
        </div>

        <div class="form-group">
          <label for="edit_contact_no">Contact Number</label>
          <input type="text" id="edit_contact_no" name="contact_no" class="form-control">
        </div>

        <div class="form-group">
          <label for="edit_barangay_name">Barangay <span class="req">*</span></label>
          <input type="text" id="edit_barangay_name" name="barangay_name" list="barangay-list" class="form-control" placeholder="Enter barangay name" required autocomplete="off">
        </div>

        <div class="form-group">
          <label for="edit_address_detail">Address Detail</label>
          <textarea id="edit_address_detail" name="address_detail" class="form-control"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn-primary">
          <?= isAdmin() ? 'Update Farmer' : 'Submit for Approval' ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Shared Datalist for Barangay Autocomplete ───────────── -->
<datalist id="barangay-list">
  <?php foreach ($barangays as $b): ?>
    <option value="<?= htmlspecialchars($b['barangay_name']) ?>">
  <?php endforeach; ?>
</datalist>

<!-- ── Toast Notification Container ────────────────────────── -->
<div id="toast"></div>

<!-- ── Scripts ─────────────────────────────────────────────── -->
<script>
// Modal Controls
function openAddModal() {
  document.getElementById('addModal').classList.add('active');
  document.getElementById('add_barangay_name').value = '';
  document.getElementById('add_first_name').focus();
}

function openEditModal(button) {
  document.getElementById('edit_farmer_id').value      = button.dataset.id;
  document.getElementById('edit_first_name').value     = button.dataset.fname;
  document.getElementById('edit_last_name').value      = button.dataset.lname;
  document.getElementById('edit_contact_no').value     = button.dataset.contact;
  document.getElementById('edit_barangay_name').value  = button.dataset.barangay;
  document.getElementById('edit_address_detail').value = button.dataset.address;

  document.getElementById('editModal').classList.add('active');
  document.getElementById('edit_first_name').focus();
}

function closeModal(modalId) {
  document.getElementById(modalId).classList.remove('active');
}

// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
      overlay.classList.remove('active');
    }
  });
});

// Escape key to close modals
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
      m.classList.remove('active');
    });
  }
});

// Delete Confirmation depending on Role
function confirmDelete(name) {
  var isAdminRole = <?= isAdmin() ? 'true' : 'false' ?>;
  if (isAdminRole) {
    return confirm('Are you sure you want to permanently delete farmer "' + name + '"?\nThis action cannot be undone.');
  } else {
    return confirm('As Staff, deleting farmer "' + name + '" will submit a deletion request to the Administrator Approval Queue.\n\nDo you want to proceed?');
  }
}

// Real-time Search Filtering
function filterTable() {
  var input = document.getElementById('searchInput');
  var filter = input.value.toLowerCase().trim();
  var rows = document.querySelectorAll('#farmersTable tbody tr.farmer-row');
  var visibleCount = 0;

  rows.forEach(function(row) {
    var searchTarget = row.getAttribute('data-search') || '';
    if (filter === '' || searchTarget.indexOf(filter) > -1) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  var badge = document.getElementById('rowCountBadge');
  if (badge) {
    badge.textContent = visibleCount + (filter === '' ? ' total' : ' found');
  }
}

// Toast Display
function showToast(message, type) {
  var toast = document.getElementById('toast');
  toast.textContent = message;
  toast.className = 'show ' + (type || 'success');
  setTimeout(function() {
    toast.className = '';
  }, 4000);
}

<?php if ($flashMsg): ?>
window.addEventListener('DOMContentLoaded', function() {
  showToast(<?= json_encode($flashMsg) ?>, <?= json_encode($flashType) ?>);
});
<?php endif; ?>
</script>
</body>
</html>
