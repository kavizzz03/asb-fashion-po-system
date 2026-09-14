<?php
// Start session for persistent draft ID
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Resource Limits & High-Capacity Optimization (150+ Items)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('memory_limit', '2048M');
ini_set('max_execution_time', 900);
ini_set('max_input_time', 900);

$page_title = 'ASB Fashion | Enterprise PO Matrix Engine';
$page = 'po_ledger';

// Establish Resilient Connections
$dbError = null;
try {
    $conn = getConnection();      // po_system
    $qcConn = getQcConnection();  // return_qc
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// ------------------------------------------------------------
// Hierarchical File Logging (Year / Month / Date)
// ------------------------------------------------------------
function logPoEnterprise($message, $po_number = 'NO_PO', $level = 'INFO') {
    $year  = date('Y');
    $month = date('m');
    $date  = date('Y-m-d');
    
    $logDir = ROOT_PATH . "logs/{$year}/{$month}/";
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile   = $logDir . "{$date}_po_creation.log";
    $timestamp = date('Y-m-d H:i:s');
    $session   = session_id() ?: 'guest';
    $user_id   = $_SESSION['user_id'] ?? 'system';
    
    $entry = "[$timestamp] [$level] [PO: $po_number] [Session: $session] [User: $user_id] $message" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function getDefaultUserId($conn) {
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        return (int)$_SESSION['user_id'];
    }
    $result = $conn->query("SELECT id FROM po_users LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return (int)$result->fetch_assoc()['id'];
    }
    return 1;
}

// ------------------------------------------------------------
// Multi-User Concurrency Atomic PO Generator (Row Locking)
// ------------------------------------------------------------
if (!function_exists('generateAtomicPONumber')) {
    function generateAtomicPONumber($conn) {
        $today = date('Y-m-d');
        $datePrefix = date('Ymd');
        
        $conn->query("CREATE TABLE IF NOT EXISTS `po_sequences` (
            `sequence_date` DATE PRIMARY KEY,
            `last_number` INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Transaction FOR UPDATE locks row preventing race conditions across multi-users
        $stmt = $conn->prepare("SELECT last_number FROM po_sequences WHERE sequence_date = ? FOR UPDATE");
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $nextSeq = $row['last_number'] + 1;
            $upd = $conn->prepare("UPDATE po_sequences SET last_number = ? WHERE sequence_date = ?");
            $upd->bind_param("is", $nextSeq, $today);
            $upd->execute();
            $upd->close();
        } else {
            $nextSeq = 1;
            $ins = $conn->prepare("INSERT INTO po_sequences (sequence_date, last_number) VALUES (?, ?)");
            $ins->bind_param("si", $today, $nextSeq);
            $ins->execute();
            $ins->close();
        }
        $stmt->close();
        
        return "PO-" . $datePrefix . "-" . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }
}

// ------------------------------------------------------------
// Database Safeguards & Draft Utilities
// ------------------------------------------------------------
function ensureAllTablesExist($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `po_draft` (
        `draft_id` INT(11) NOT NULL AUTO_INCREMENT,
        `session_id` VARCHAR(128) NOT NULL,
        `supplier_id` INT(11) UNSIGNED DEFAULT NULL,
        `supplier_name` VARCHAR(100) DEFAULT NULL,
        `purchase_date` DATE DEFAULT NULL,
        `attention` VARCHAR(255) DEFAULT NULL,
        `remarks` TEXT DEFAULT NULL,
        `expected_delivery_date` DATE DEFAULT NULL,
        `status` VARCHAR(50) DEFAULT 'Pending',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`draft_id`),
        UNIQUE KEY `session_id` (`session_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $conn->query("CREATE TABLE IF NOT EXISTS `po_draft_items` (
        `draft_item_id` INT(11) NOT NULL AUTO_INCREMENT,
        `draft_id` INT(11) NOT NULL,
        `system_code` VARCHAR(50) DEFAULT NULL,
        `item_code` VARCHAR(50) NOT NULL,
        `item_name` VARCHAR(255) NOT NULL,
        `department_id` INT(11) DEFAULT NULL,
        `sub_department_id` INT(11) DEFAULT NULL,
        `category_id` INT(11) DEFAULT NULL,
        `color_id` INT(11) DEFAULT NULL,
        `size_id` INT(11) DEFAULT NULL,
        `cost` DECIMAL(12,2) DEFAULT 0.00,
        `selling` DECIMAL(12,2) DEFAULT 0.00,
        `quantity` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`draft_item_id`),
        KEY `draft_id` (`draft_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function clearDraftBySession($conn, $session_id) {
    $stmt = $conn->prepare("SELECT draft_id FROM po_draft WHERE session_id = ?");
    $stmt->bind_param("s", $session_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $draft_id = $row['draft_id'];
        $delItems = $conn->prepare("DELETE FROM po_draft_items WHERE draft_id = ?");
        $delItems->bind_param("i", $draft_id);
        $delItems->execute();
        $delItems->close();
    }
    $stmt->close();

    $delDraft = $conn->prepare("DELETE FROM po_draft WHERE session_id = ?");
    $delDraft->bind_param("s", $session_id);
    $delDraft->execute();
    $delDraft->close();
}

function saveOrUpdateItemBulk($conn, $item_data, $supplier_id) {
    $item_code = trim($item_data['item_code'] ?? '');
    $item_name = trim($item_data['item_name'] ?? '');
    if (empty($item_code) || empty($item_name)) return null;

    $system_code = trim($item_data['system_code'] ?? '');
    $department_id = !empty($item_data['department_id']) ? (int)$item_data['department_id'] : null;
    $sub_department_id = !empty($item_data['sub_department_id']) ? (int)$item_data['sub_department_id'] : null;
    $category_id = !empty($item_data['category_id']) ? (int)$item_data['category_id'] : null;
    $color_id = !empty($item_data['color_id']) ? (int)$item_data['color_id'] : null;
    $size_id = !empty($item_data['size_id']) ? (int)$item_data['size_id'] : null;
    $cost = (float)($item_data['cost'] ?? 0);
    $sell = (float)($item_data['selling'] ?? 0);

    $check = $conn->prepare("SELECT item_id FROM items WHERE item_code = ?");
    $check->bind_param("s", $item_code);
    $check->execute();
    $res = $check->get_result();

    if ($row = $res->fetch_assoc()) {
        $item_id = $row['item_id'];
        $update = $conn->prepare("UPDATE items SET system_code=?, item_name=?, department_id=?, sub_department_id=?, category_id=?, color_id=?, size_id=?, supplier_id=?, cost_price=?, selling_price=? WHERE item_id=?");
        $update->bind_param("ssiiiiiiddi", $system_code, $item_name, $department_id, $sub_department_id, $category_id, $color_id, $size_id, $supplier_id, $cost, $sell, $item_id);
        $update->execute();
        $update->close();
        $check->close();
        return $item_id;
    } else {
        $insert = $conn->prepare("INSERT INTO items (system_code, item_code, item_name, department_id, sub_department_id, category_id, color_id, size_id, supplier_id, cost_price, selling_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("sssiiiiiidd", $system_code, $item_code, $item_name, $department_id, $sub_department_id, $category_id, $color_id, $size_id, $supplier_id, $cost, $sell);
        $insert->execute();
        $item_id = $insert->insert_id;
        $insert->close();
        $check->close();
        return $item_id;
    }
}

// ------------------------------------------------------------
// AJAX Endpoints & Direct Live Search API
// ------------------------------------------------------------
if ((isset($_GET['ajax_action']) || isset($_POST['ajax_action'])) && (($_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '') !== 'save_new_po')) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    if ($dbError) {
        echo json_encode(['success' => false, 'error' => 'Database Outage: ' . $dbError, 'db_down' => true]);
        exit;
    }

    $action = $_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '';
    $search = trim($_GET['search'] ?? '');
    ensureAllTablesExist($conn);

    try {
        switch ($action) {
            case 'search_item_code_direct':
                $sql = "SELECT i.system_code, i.item_code, i.item_name, i.department_id, i.sub_department_id, 
                               i.category_id, i.color_id, i.size_id, i.cost_price AS cost, i.selling_price AS selling
                        FROM items i
                        WHERE i.item_code LIKE ? OR i.system_code LIKE ?
                        ORDER BY i.item_code ASC LIMIT 15";
                $stmt = $conn->prepare($sql);
                $like = "%$search%";
                $stmt->bind_param("ss", $like, $like);
                $stmt->execute();
                echo json_encode(['success' => true, 'results' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
                $stmt->close();
                exit;

            case 'search_name_suggestions':
                $sql = "SELECT s.suggestion_id, s.suggested_name AS item_name, s.department_id, s.sub_department_id,
                               d.department_name, sd.sub_department_name
                        FROM item_name_suggestions s
                        LEFT JOIN departments d ON s.department_id = d.department_id
                        LEFT JOIN sub_departments sd ON s.sub_department_id = sd.sub_department_id
                        WHERE s.suggested_name LIKE ?
                        ORDER BY s.suggested_name ASC LIMIT 15";
                $stmt = $conn->prepare($sql);
                $like = "%$search%";
                $stmt->bind_param("s", $like);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                echo json_encode(['success' => true, 'results' => $res]);
                exit;

            case 'get_suppliers_direct':
                $sql = "SELECT supplier_id AS id, supplier_name AS name FROM suppliers WHERE supplier_name LIKE ? ORDER BY supplier_name ASC LIMIT 25";
                $stmt = $conn->prepare($sql);
                $like = "%$search%";
                $stmt->bind_param("s", $like);
                $stmt->execute();
                echo json_encode(['success' => true, 'results' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
                $stmt->close();
                exit;

            case 'get_options_master':
                $depts = $conn->query("SELECT department_id AS id, department_name AS name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC);
                $subdepts = $conn->query("SELECT sub_department_id AS id, sub_department_name AS name FROM sub_departments ORDER BY sub_department_name")->fetch_all(MYSQLI_ASSOC);
                $cats = $conn->query("SELECT category_id AS id, category_name AS name FROM categories ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);
                $colors = $conn->query("SELECT color_id AS id, color_name AS name FROM colors ORDER BY color_name")->fetch_all(MYSQLI_ASSOC);
                $sizes = $conn->query("SELECT size_id AS id, size_name AS name FROM sizes ORDER BY size_name")->fetch_all(MYSQLI_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'options' => [
                        'departments' => $depts,
                        'sub_departments' => $subdepts,
                        'categories' => $cats,
                        'colors' => $colors,
                        'sizes' => $sizes
                    ]
                ]);
                exit;

            case 'get_all_drafts':
                $sql = "SELECT d.*, COUNT(i.draft_item_id) as item_count 
                        FROM po_draft d 
                        LEFT JOIN po_draft_items i ON d.draft_id = i.draft_id 
                        GROUP BY d.draft_id ORDER BY d.updated_at DESC LIMIT 20";
                $res = $conn->query($sql);
                echo json_encode(['success' => true, 'drafts' => $res->fetch_all(MYSQLI_ASSOC)]);
                exit;

            case 'load_specific_draft':
                $draft_id = (int)($_GET['draft_id'] ?? 0);
                $stmt = $conn->prepare("SELECT * FROM po_draft WHERE draft_id = ?");
                $stmt->bind_param("i", $draft_id);
                $stmt->execute();
                $draft = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($draft) {
                    $itemStmt = $conn->prepare("SELECT * FROM po_draft_items WHERE draft_id = ?");
                    $itemStmt->bind_param("i", $draft['draft_id']);
                    $itemStmt->execute();
                    $draft['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $itemStmt->close();
                }
                echo json_encode(['success' => true, 'draft' => $draft]);
                exit;

            case 'auto_save_draft':
                $session_id = session_id() ?: 'guest_' . uniqid();
                $supplier_id = (int)($_POST['supplier_id'] ?? 0);
                $supplier_name = trim($_POST['supplier_name'] ?? '');
                $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
                $attention = trim($_POST['attention'] ?? '');
                $remarks = trim($_POST['remarks'] ?? '');
                $expected_delivery_date = $_POST['expected_delivery_date'] ?: null;
                $status = trim($_POST['status'] ?? 'Pending');

                clearDraftBySession($conn, $session_id);

                $insert = $conn->prepare("INSERT INTO po_draft (session_id, supplier_id, supplier_name, purchase_date, attention, remarks, expected_delivery_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->bind_param("sissssss", $session_id, $supplier_id, $supplier_name, $purchase_date, $attention, $remarks, $expected_delivery_date, $status);
                $insert->execute();
                $draft_id = $insert->insert_id;
                $insert->close();

                $items = json_decode($_POST['items_json'] ?? '[]', true);
                if (!empty($items) && is_array($items)) {
                    $stmt = $conn->prepare("INSERT INTO po_draft_items (draft_id, system_code, item_code, item_name, department_id, sub_department_id, category_id, color_id, size_id, cost, selling, quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $code = trim($item['item_code'] ?? '');
                        $name = trim($item['item_name'] ?? '');
                        $qty = (int)($item['quantity'] ?? 0);
                        if (empty($code) || empty($name) || $qty <= 0) continue;

                        $sys = trim($item['system_code'] ?? '');
                        $dept = !empty($item['department_id']) ? (int)$item['department_id'] : null;
                        $subdept = !empty($item['sub_department_id']) ? (int)$item['sub_department_id'] : null;
                        $cat = !empty($item['category_id']) ? (int)$item['category_id'] : null;
                        $col = !empty($item['color_id']) ? (int)$item['color_id'] : null;
                        $sz = !empty($item['size_id']) ? (int)$item['size_id'] : null;
                        $cost = (float)($item['cost'] ?? 0);
                        $sell = (float)($item['selling'] ?? 0);

                        $stmt->bind_param("isssiiiiiddi", $draft_id, $sys, $code, $name, $dept, $subdept, $cat, $col, $sz, $cost, $sell, $qty);
                        $stmt->execute();
                    }
                    $stmt->close();
                }
                echo json_encode(['success' => true, 'message' => "Draft synced to server DB"]);
                exit;

            case 'load_draft':
                $session_id = session_id() ?: 'guest_' . uniqid();
                $stmt = $conn->prepare("SELECT * FROM po_draft WHERE session_id = ?");
                $stmt->bind_param("s", $session_id);
                $stmt->execute();
                $draft = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($draft) {
                    $itemStmt = $conn->prepare("SELECT * FROM po_draft_items WHERE draft_id = ?");
                    $itemStmt->bind_param("i", $draft['draft_id']);
                    $itemStmt->execute();
                    $draft['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $itemStmt->close();
                }
                echo json_encode(['success' => true, 'draft' => $draft]);
                exit;

            case 'clear_draft_manual':
                $session_id = session_id() ?: 'unknown';
                clearDraftBySession($conn, $session_id);
                echo json_encode(['success' => true, 'message' => 'Draft cleared']);
                exit;
        }
    } catch (Exception $e) {
        logPoEnterprise("AJAX Exception: " . $e->getMessage(), 'ERROR');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------
// Final PO Submission, Multi-User Safety & Full Error Handling
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_new_po') {
    $session_id = session_id() ?: 'unknown';
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    if ($dbError) {
        echo json_encode(['success' => false, 'message' => 'Database Connection Failure: ' . $dbError]);
        exit;
    }

    $response = ['success' => false, 'message' => '', 'po_id' => null, 'po_number' => null];

    try {
        ensureAllTablesExist($conn);

        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $purchase_date = $_POST['purchase_date'] ?? '';
        $attention = trim($_POST['attention'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $expected_delivery_date = $_POST['expected_delivery_date'] ?: null;
        $status = trim($_POST['status'] ?? 'Pending');

        // Validation 1: Header Inputs
        if ($supplier_id <= 0) {
            throw new Exception('Invalid or missing Supplier selection.');
        }
        if (empty($purchase_date)) {
            throw new Exception('Purchase Date field is required.');
        }

        // Validation 2: Items Array Parsing
        $items = json_decode($_POST['items_json'] ?? '[]', true);
        if (!is_array($items) || empty($items)) {
            throw new Exception("Matrix contain no items to finalize.");
        }

        // Start InnoDB Isolated Transaction
        $conn->begin_transaction();
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Atomic Multi-User PO Number Generation
        $po_number = generateAtomicPONumber($conn);
        logPoEnterprise("Beginning commit processing for PO: {$po_number} with " . count($items) . " items", $po_number);

        $added_by = getDefaultUserId($conn);

        // Header Insert
        $insert_header = $conn->prepare("INSERT INTO po_header (po_number, supplier_id, purchase_date, attention, remarks, expected_delivery_date, status, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$insert_header) {
            throw new Exception("Header Query Preparation Failed: " . $conn->error);
        }

        $insert_header->bind_param("sisssssi", $po_number, $supplier_id, $purchase_date, $attention, $remarks, $expected_delivery_date, $status, $added_by);
        if (!$insert_header->execute()) {
            throw new Exception("Header Insert Failed: " . $insert_header->error);
        }
        $po_id = $insert_header->insert_id;
        $insert_header->close();

        // Items Loop & Matrix Verification
        $po_items_values = [];
        $po_items_params = [];
        $po_items_types = "";
        $item_count = 0;

        foreach ($items as $idx => $item) {
            $code = trim($item['item_code'] ?? '');
            $name = trim($item['item_name'] ?? '');
            $qty = (int)($item['quantity'] ?? 0);

            if (empty($code)) {
                throw new Exception("Row #" . ($idx + 1) . ": Item Code cannot be empty.");
            }
            if (empty($name)) {
                throw new Exception("Row #" . ($idx + 1) . ": Item Description cannot be empty.");
            }
            if ($qty <= 0) {
                throw new Exception("Row #" . ($idx + 1) . ": Quantity must be greater than zero.");
            }

            $item_id = saveOrUpdateItemBulk($conn, $item, $supplier_id);
            if (!$item_id) {
                throw new Exception("Row #" . ($idx + 1) . ": Failed to register item catalog details.");
            }

            $po_items_values[] = "(?, ?, ?, ?, ?, ?)";
            $po_items_params[] = $po_id;
            $po_items_params[] = $item_id;
            $po_items_params[] = $qty;
            $po_items_params[] = (float)($item['cost'] ?? 0);
            $po_items_params[] = (float)($item['selling'] ?? 0);
            $po_items_params[] = 0;
            $po_items_types .= "iiiddi";
            $item_count++;
        }

        if (!empty($po_items_values)) {
            $sql = "INSERT INTO po_items (po_id, item_id, quantity, cost_price, selling_price, received_qty) VALUES " . implode(", ", $po_items_values);
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Items Bulk Preparation Failed: " . $conn->error);
            }
            $stmt->bind_param($po_items_types, ...$po_items_params);
            if (!$stmt->execute()) {
                throw new Exception("Items Insertion Error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("No valid matrix line items found to commit.");
        }

        // Wipe Draft Tables for Active Session
        clearDraftBySession($conn, $session_id);

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->commit();

        logPoEnterprise("PO Committed Successfully. Sequence: {$po_number}. Items Processed: {$item_count}", $po_number, 'SUCCESS');

        $response['success'] = true;
        $response['message'] = "Purchase Order created successfully: {$po_number}";
        $response['po_id'] = $po_id;
        $response['po_number'] = $po_number;
        $response['item_count'] = $item_count;

    } catch (Exception $e) {
        if (isset($conn)) {
            @$conn->rollback();
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        }
        logPoEnterprise("Finalize Execution Error: " . $e->getMessage(), 'ERROR');
        $response['success'] = false;
        $response['message'] = 'Order Execution Failed: ' . $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// View UI Rendering
// ------------------------------------------------------------
include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .asb-input-sm { padding: 4px 6px; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100%; outline: none; }
    .asb-input-sm:focus { border-color: #b71c1c; box-shadow: 0 0 0 2px rgba(183,28,28,0.15); }
    
    .matrix-table { overflow: visible !important; }
    .matrix-table th { position: sticky; top: 0; background: #f1f5f9; z-index: 20; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #b71c1c; }
    .matrix-table td { padding: 3px 2px; position: relative; }
    .matrix-row:hover { background-color: #fcfcfd; }

    /* Downward Dropdowns */
    .search-dropdown { 
        position: absolute; 
        left: 0; 
        right: 0; 
        top: 100%; 
        margin-top: 2px; 
        background: #ffffff; 
        border: 1px solid #94a3b8; 
        border-radius: 6px; 
        max-height: 220px; 
        overflow-y: auto; 
        z-index: 9999 !important; 
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); 
    }

    /* Upper Dropup for Item Description Search */
    .search-dropdown-upper { 
        position: absolute; 
        left: 0; 
        right: 0; 
        bottom: 100%; 
        top: auto;
        margin-bottom: 2px; 
        background: #ffffff; 
        border: 1px solid #94a3b8; 
        border-radius: 6px; 
        max-height: 220px; 
        overflow-y: auto; 
        z-index: 9999 !important; 
        box-shadow: 0 -10px 15px -3px rgba(0, 0, 0, 0.2); 
    }

    .search-dropdown-item { padding: 7px 10px; font-size: 11px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: all 0.15s; }
    .search-dropdown-item:hover, .search-dropdown-item.active { background-color: #fee2e2; color: #991b1b; font-weight: 600; }

    /* Interactive Inherit Toast Bar */
    .inherit-toast {
        position: absolute;
        top: -38px;
        left: 0;
        z-index: 9999;
        background: #1e293b;
        color: #ffffff;
        font-size: 10px;
        padding: 4px 8px;
        border-radius: 4px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3);
        display: flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }
</style>

<div class="p-4 md:p-6 max-w-[1700px] mx-auto">
    
    <!-- DB Outage Alert Banner -->
    <?php if ($dbError): ?>
    <div class="bg-red-50 border-l-4 border-red-600 p-4 mb-4 rounded-r shadow-sm">
        <div class="flex items-center gap-2 text-red-800 font-bold text-sm">
            <i class="fas fa-triangle-exclamation"></i> Backend Database Connection Failure
        </div>
        <p class="text-xs text-red-700 mt-1">Error Details: <?= htmlspecialchars($dbError); ?></p>
        <p class="text-[11px] text-slate-500 mt-1">Client-Side Storage Engine active. Your Matrix inputs are saved safely in local storage.</p>
    </div>
    <?php endif; ?>

    <!-- Top Action Bar -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-5 pb-4 border-b border-slate-200 gap-4">
        <div>
            <h1 class="text-xl font-black text-red-700 tracking-tight flex items-center gap-2">
                <i class="fas fa-boxes-packing"></i> ASB FASHION 
                <span class="text-slate-400 font-normal text-base">| Enterprise PO Matrix Engine</span>
            </h1>
            <p class="text-xs text-slate-500">
                Shortcuts: 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+S</kbd> Supplier | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+I</kbd> Code | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+D</kbd> Description | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+1..5</kbd> Select Dropdowns | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Enter</kbd> Close &amp; Next | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Esc</kbd> Close
            </p>
        </div>
        
        <div class="flex flex-wrap items-center gap-3">
            <div class="bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm flex items-center gap-4 text-xs">
                <div>Items: <span id="lbl-item-count" class="font-bold text-red-700">0</span></div>
                <div class="h-4 w-px bg-slate-200"></div>
                <div>Units: <span id="lbl-unit-count" class="font-bold text-slate-800">0</span></div>
                <div class="h-4 w-px bg-slate-200"></div>
                <div>Total Cost: <span id="lbl-total-cost" class="font-bold text-slate-800">LKR 0.00</span></div>
            </div>

            <button type="button" id="btn-open-drafts" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 font-bold text-xs px-3 py-2 rounded-md transition-all flex items-center gap-1">
                <i class="fas fa-folder-open"></i> Load Drafts
            </button>
            <button type="button" id="btn-add-10" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs px-3 py-2 rounded-md transition-all">+10 Rows</button>
            <button type="button" id="btn-add-50" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs px-3 py-2 rounded-md transition-all">+50 Rows</button>
            <button type="button" id="btn-clear-draft" class="bg-amber-100 hover:bg-amber-200 text-amber-800 font-bold text-xs px-3 py-2 rounded-md transition-all">Reset Matrix</button>
            <a href="view_pos.php" class="bg-white border border-slate-300 text-slate-600 font-bold text-xs px-3 py-2 rounded-md hover:bg-slate-100">Cancel</a>
            <button type="button" id="btn-open-confirm" class="bg-red-700 hover:bg-red-800 text-white font-bold text-xs px-4 py-2 rounded-md shadow-sm flex items-center gap-1">
                <i class="fas fa-paper-plane"></i> Finalize PO
            </button>
        </div>
    </div>

    <!-- Header Details Form -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm mb-5">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="relative">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Supplier * (Alt+S)</label>
                <input type="text" id="supplier_search_input" placeholder="Search supplier..." autocomplete="off" class="asb-input-sm font-semibold">
                <input type="hidden" id="supplier_id" name="supplier_id">
                <div id="supplier_dropdown" class="search-dropdown hidden"></div>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Purchase Date *</label>
                <input type="date" id="purchase_date" value="<?= date('Y-m-d'); ?>" class="asb-input-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Expected Delivery</label>
                <input type="date" id="expected_delivery_date" class="asb-input-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Status</label>
                <select id="status" class="asb-input-sm bg-white">
                    <option value="Pending">Pending</option>
                    <option value="Received">Received</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Auto-Save Status</label>
                <div id="autosave-status" class="text-xs font-semibold text-slate-400 py-1 flex items-center gap-1">
                    <i class="fas fa-signal"></i> System Idle
                </div>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
            <input type="text" id="attention" placeholder="Attention (Contact person / Department)" class="asb-input-sm">
            <input type="text" id="remarks" placeholder="Order remarks..." class="asb-input-sm">
        </div>
    </div>

    <!-- Items Matrix Table -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-5" style="overflow: visible;">
        <div class="max-h-[600px] overflow-y-auto overflow-x-visible relative">
            <table class="w-full matrix-table text-left border-collapse">
                <thead>
                    <tr class="text-slate-600">
                        <th class="p-2 w-8 text-center">#</th>
                        <th class="p-2 w-24">Sys Code</th>
                        <th class="p-2 w-40">Item Code (Alt+I) *</th>
                        <th class="p-2 w-48">Item Description (Alt+D) *</th>
                        <th class="p-2 w-28">Department (Alt+1)</th>
                        <th class="p-2 w-28">Sub Dept (Alt+2)</th>
                        <th class="p-2 w-24">Category (Alt+3)</th>
                        <th class="p-2 w-16">Color (Alt+4)</th>
                        <th class="p-2 w-14">Size (Alt+5)</th>
                        <th class="p-2 w-28 text-right">Cost Price *</th>
                        <th class="p-2 w-28 text-right">Selling Price *</th>
                        <th class="p-2 w-24 text-center">Qty *</th>
                        <th class="p-2 w-32 text-right">Subtotal</th>
                        <th class="p-2 w-8 text-center"><i class="fas fa-cog"></i></th>
                    </tr>
                </thead>
                <tbody id="matrixBody"></tbody>
            </table>
        </div>
        <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-between items-center text-xs">
            <button type="button" id="btn-add-row" class="bg-red-700 hover:bg-red-800 text-white font-bold px-3 py-1.5 rounded flex items-center gap-1">
                <i class="fas fa-plus"></i> Add Row
            </button>
            <div class="text-slate-400 font-mono">Designed &amp; Developed by VEXEL IT by Kavizz</div>
        </div>
    </div>
</div>

<!-- Draft Selector Modal -->
<div id="draftModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl max-w-xl w-full p-6 shadow-xl border border-slate-200">
        <h3 class="text-base font-extrabold text-slate-800 mb-2 flex items-center gap-2">
            <i class="fas fa-folder-open text-indigo-600"></i> Active Server Drafts
        </h3>
        <p class="text-xs text-slate-500 mb-4">Select a draft saved on the server to restore its matrix data into the workspace.</p>
        <div id="draftListContainer" class="max-h-60 overflow-y-auto space-y-2 mb-4"></div>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="$('#draftModal').addClass('hidden')" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded">Close</button>
        </div>
    </div>
</div>

<!-- Confirm Execution Modal -->
<div id="confirmModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl max-w-md w-full p-6 shadow-xl border border-slate-200">
        <h3 class="text-base font-extrabold text-red-700 mb-2 flex items-center gap-2">
            <i class="fas fa-shield-halved"></i> Confirm Purchase Order Execution
        </h3>
        <p class="text-xs text-slate-600 mb-4">Committing locks sequence generation and removes active draft records.</p>
        <div id="modalSummary" class="bg-slate-50 p-3 rounded-lg border border-slate-200 text-xs space-y-1 mb-4 font-mono"></div>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="$('#confirmModal').addClass('hidden')" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded">Cancel</button>
            <button type="button" id="btn-submit-po" class="px-4 py-1.5 bg-red-700 hover:bg-red-800 text-white font-bold text-xs rounded flex items-center gap-1">
                <i class="fas fa-check"></i> Commit Order
            </button>
        </div>
    </div>
</div>

<script>
let masterOptions = { departments: [], sub_departments: [], categories: [], colors: [], sizes: [] };
let rowCounter = 0;
let autoSaveDebounceTimer = null;
const LOCAL_STORAGE_KEY = 'ASB_PO_MATRIX_LOCAL_DRAFT';

$(document).ready(function() {
    loadMasterOptions();
    initSupplierSearch();
    loadServerDraft();

    // Global Keybindings & Direct Field Shortcuts Engine
    $(document).on('keydown', function(e) {
        let $lastTr = $('#matrixBody tr:last-child');

        if (e.altKey && (e.key === 'a' || e.key === 'A')) {
            e.preventDefault();
            addMatrixRows(1);
        } else if (e.altKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            $('#supplier_search_input').focus().select();
            triggerSearchInput($('#supplier_search_input'));
        } else if (e.altKey && (e.key === 'i' || e.key === 'I')) {
            e.preventDefault();
            let $code = $lastTr.find('.item-code');
            if ($code.length) { $code.focus().select(); triggerSearchInput($code); }
        } else if (e.altKey && (e.key === 'd' || e.key === 'D')) {
            e.preventDefault();
            let $name = $lastTr.find('.item-name');
            if ($name.length) { $name.focus().select(); triggerSearchInput($name); }
        } else if (e.altKey && e.key === '1') {
            e.preventDefault();
            $lastTr.find('.sel-dept').focus();
        } else if (e.altKey && e.key === '2') {
            e.preventDefault();
            $lastTr.find('.sel-subdept').focus();
        } else if (e.altKey && e.key === '3') {
            e.preventDefault();
            $lastTr.find('.sel-cat').focus();
        } else if (e.altKey && e.key === '4') {
            e.preventDefault();
            $lastTr.find('.sel-color').focus();
        } else if (e.altKey && e.key === '5') {
            e.preventDefault();
            $lastTr.find('.sel-size').focus();
        } else if (e.key === 'Escape') {
            $('.inherit-toast').remove();
            $('.search-dropdown, .search-dropdown-upper').addClass('hidden');
        }
    });

    $(document).on('mousedown', function(e) {
        if (!$(e.target).closest('.relative').length) {
            $('.search-dropdown, .search-dropdown-upper').addClass('hidden');
            $('.inherit-toast').remove();
        }
    });

    $(document).on('keydown', '.matrix-field', function(e) {
        if (e.key === 'Enter') {
            let $dd = $(this).parent().find('.search-dropdown, .search-dropdown-upper');
            
            if (!$dd.hasClass('hidden')) {
                let $activeOpt = $dd.find('.search-dropdown-item.active');
                if ($activeOpt.length) {
                    $activeOpt.trigger('click');
                } else {
                    let $firstOpt = $dd.find('.search-dropdown-item').first();
                    if ($firstOpt.length) $firstOpt.trigger('click');
                }
                $dd.addClass('hidden');
                e.preventDefault();
                return;
            }

            e.preventDefault();
            let fields = $('.matrix-field');
            let idx = fields.index(this);
            if (idx > -1 && idx < fields.length - 1) {
                fields.eq(idx + 1).focus().select();
            } else {
                addMatrixRows(1);
            }
        }
    });

    $('#btn-add-row').click(() => addMatrixRows(1));
    $('#btn-add-10').click(() => addMatrixRows(10));
    $('#btn-add-50').click(() => addMatrixRows(50));
    
    $('#btn-clear-draft').click(function() {
        if (confirm('Reset current matrix items and clear local state?')) {
            localStorage.removeItem(LOCAL_STORAGE_KEY);
            $.getJSON(window.location.href, { ajax_action: 'clear_draft_manual' }, function() {
                $('#matrixBody').empty();
                rowCounter = 0;
                addMatrixRows(1);
                calculateMatrixTotals();
            });
        }
    });

    $('#btn-open-drafts').click(fetchAndShowDraftsModal);

    $(document).on('input change', '.matrix-field, #purchase_date, #expected_delivery_date, #status, #attention, #remarks', function() {
        calculateMatrixTotals();
        triggerAutoSaveDebounced();
    });

    $(document).on('click', '.btn-remove-row', function() {
        if ($('#matrixBody tr').length > 1) {
            $(this).closest('tr').remove();
            reindexRows();
            calculateMatrixTotals();
            triggerAutoSaveDebounced();
        }
    });

    // Option suggestion click handler
    $(document).on('click', '.suggestion-opt', function() {
        let $opt = $(this).closest('.suggestion-opt');
        let rawData = $opt.attr('data-suggestion');
        if (!rawData) return;
        
        let sug = JSON.parse(rawData);
        let $tr = $opt.closest('tr');
        
        $tr.find('.item-name').val(sug.item_name);
        if (sug.department_id) $tr.find('.sel-dept').val(sug.department_id);
        if (sug.sub_department_id) $tr.find('.sel-subdept').val(sug.sub_department_id);

        $('.search-dropdown-upper, .search-dropdown').addClass('hidden');
        calculateMatrixTotals();
        triggerAutoSaveDebounced();
    });

    // Catalog search click handler
    $(document).on('click', '.catalog-item-opt', function() {
        let $opt = $(this).closest('.catalog-item-opt');
        let rawData = $opt.attr('data-item');
        if (!rawData) return;

        let item = JSON.parse(rawData);
        let $tr = $opt.closest('tr');

        $tr.find('.sys-code').val(item.system_code || '');
        $tr.find('.item-code').val(item.item_code || '');
        $tr.find('.item-name').val(item.item_name || '');
        if (item.department_id) $tr.find('.sel-dept').val(item.department_id);
        if (item.sub_department_id) $tr.find('.sel-subdept').val(item.sub_department_id);
        if (item.category_id) $tr.find('.sel-cat').val(item.category_id);
        if (item.color_id) $tr.find('.sel-color').val(item.color_id);
        if (item.size_id) $tr.find('.sel-size').val(item.size_id);
        $tr.find('.inp-cost').val(item.cost || 0);
        $tr.find('.inp-selling').val(item.selling || 0);

        $('.search-dropdown-upper, .search-dropdown').addClass('hidden');
        calculateMatrixTotals();
        triggerAutoSaveDebounced();
    });

    // Validate and Open Finalize Confirmation
    $('#btn-open-confirm').click(function() {
        let sup = $('#supplier_id').val();
        if (!sup) { 
            alert('Error: Please select a valid supplier from the supplier search dropdown.'); 
            $('#supplier_search_input').focus();
            return; 
        }
        
        let validRows = getValidItems();
        if (validRows.length === 0) { 
            alert('Error: Please populate at least one valid row (Item Code, Description, and Qty > 0).'); 
            return; 
        }

        // Comprehensive Item Validation before confirmation
        for (let i = 0; i < validRows.length; i++) {
            let row = validRows[i];
            if (!row.item_code) {
                alert(`Matrix Validation Error on Row #${i+1}: Item Code is missing.`);
                return;
            }
            if (!row.item_name) {
                alert(`Matrix Validation Error on Row #${i+1}: Item Description is missing.`);
                return;
            }
            if (row.quantity <= 0) {
                alert(`Matrix Validation Error on Row #${i+1}: Quantity must be at least 1.`);
                return;
            }
        }

        let totalCost = validRows.reduce((s, r) => s + (r.cost * r.quantity), 0);
        let totalQty = validRows.reduce((s, r) => s + r.quantity, 0);

        $('#modalSummary').html(`
            <div><strong>Supplier:</strong> ${$('#supplier_search_input').val()}</div>
            <div><strong>Purchase Date:</strong> ${$('#purchase_date').val()}</div>
            <div><strong>Total Line Items:</strong> ${validRows.length}</div>
            <div><strong>Total Order Units:</strong> ${totalQty}</div>
            <div><strong>Total Value:</strong> LKR ${totalCost.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
        `);
        $('#confirmModal').removeClass('hidden');
    });

    $('#btn-submit-po').click(submitFinalPO);
});

function triggerSearchInput($elem) {
    $elem.trigger('focus');
    $elem.trigger('input');
}

function loadMasterOptions() {
    $.getJSON(window.location.href, { ajax_action: 'get_options_master' }, function(res) {
        if (res.success) {
            masterOptions = res.options;
        }
    });
}

function initSupplierSearch() {
    $('#supplier_search_input').on('input focus', function() {
        let q = $(this).val().trim();
        $.getJSON(window.location.href, { ajax_action: 'get_suppliers_direct', search: q }, function(res) {
            if (res.success && res.results.length > 0) {
                let html = '';
                res.results.forEach(s => {
                    html += `<div class="search-dropdown-item supplier-opt" data-id="${s.id}" data-name="${s.name}">${s.name}</div>`;
                });
                $('#supplier_dropdown').html(html).removeClass('hidden');
            } else {
                $('#supplier_dropdown').addClass('hidden');
            }
        });
    });

    $(document).on('click', '.supplier-opt', function() {
        let $opt = $(this).closest('.supplier-opt');
        $('#supplier_id').val($opt.data('id'));
        $('#supplier_search_input').val($opt.data('name'));
        $('#supplier_dropdown').addClass('hidden');
        triggerAutoSaveDebounced();
    });
}

function renderOptionTags(list, selectedId) {
    let html = '<option value="">--</option>';
    if (!list) return html;
    list.forEach(o => {
        let sel = (o.id == selectedId) ? 'selected' : '';
        html += `<option value="${o.id}" ${sel}>${o.name}</option>`;
    });
    return html;
}

function addMatrixRows(count = 1, dataArray = null) {
    let fragment = document.createDocumentFragment();

    for (let i = 0; i < count; i++) {
        rowCounter++;
        let data = (dataArray && dataArray[i]) ? dataArray[i] : {};
        let tr = document.createElement('tr');
        tr.className = 'border-b border-slate-100 matrix-row';
        tr.innerHTML = `
            <td class="text-center text-xs text-slate-400 row-num font-mono">${rowCounter}</td>
            <td><input type="text" value="${data.system_code || ''}" class="asb-input-sm matrix-field sys-code"></td>
            <td class="relative">
                <input type="text" value="${data.item_code || ''}" placeholder="Type Code..." class="asb-input-sm matrix-field item-code font-bold text-red-700" autocomplete="off">
                <div class="search-dropdown item-code-dd hidden"></div>
            </td>
            <td class="relative">
                <input type="text" value="${data.item_name || ''}" placeholder="Type Name or Search..." class="asb-input-sm matrix-field item-name" autocomplete="off">
                <div class="search-dropdown-upper item-name-dd hidden"></div>
            </td>
            <td><select class="asb-input-sm matrix-field sel-dept">${renderOptionTags(masterOptions.departments, data.department_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-subdept">${renderOptionTags(masterOptions.sub_departments, data.sub_department_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-cat">${renderOptionTags(masterOptions.categories, data.category_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-color">${renderOptionTags(masterOptions.colors, data.color_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-size">${renderOptionTags(masterOptions.sizes, data.size_id)}</select></td>
            <td><input type="number" step="0.01" value="${data.cost || 0}" class="asb-input-sm matrix-field inp-cost text-right font-mono"></td>
            <td><input type="number" step="0.01" value="${data.selling || 0}" class="asb-input-sm matrix-field inp-selling text-right font-mono"></td>
            <td><input type="number" value="${data.quantity || 1}" min="1" class="asb-input-sm matrix-field inp-qty text-center font-bold"></td>
            <td class="text-right text-xs font-bold text-slate-700 pr-2 lbl-subtotal font-mono">LKR 0.00</td>
            <td class="text-center">
                <button type="button" class="btn-remove-row text-slate-400 hover:text-red-600"><i class="fas fa-times"></i></button>
            </td>
        `;
        bindDirectItemSearch($(tr));
        fragment.appendChild(tr);
    }
    document.getElementById('matrixBody').appendChild(fragment);
    reindexRows();
    calculateMatrixTotals();
}

function bindDirectItemSearch($tr) {
    let $codeInp = $tr.find('.item-code');
    let $nameInp = $tr.find('.item-name');
    let $codeDd = $tr.find('.item-code-dd');
    let $nameDd = $tr.find('.item-name-dd');

    $codeInp.on('focus', function() {
        let $prevTr = $tr.prev('tr');
        $('.inherit-toast').remove();

        if ($prevTr.length > 0 && !$codeInp.val() && !$nameInp.val()) {
            let prevName = $prevTr.find('.item-name').val();
            let prevNum = $prevTr.find('.row-num').text();

            if (prevName) {
                let toastHtml = `
                    <div class="inherit-toast animate-bounce">
                        <i class="fas fa-copy text-amber-400"></i> Copy details from Line ${prevNum}?
                        <button type="button" class="btn-confirm-inherit bg-emerald-600 hover:bg-emerald-700 text-white px-1.5 py-0.5 rounded text-[9px]">Yes (Enter)</button>
                        <button type="button" class="btn-cancel-inherit bg-slate-700 hover:bg-slate-600 text-slate-300 px-1.5 py-0.5 rounded text-[9px]">No</button>
                    </div>
                `;
                $tr.find('.item-code').parent().append(toastHtml);

                $tr.find('.btn-confirm-inherit').on('click', function(e) {
                    e.stopPropagation();
                    copyAboveRowDetails($tr, $prevTr);
                    $('.inherit-toast').remove();
                });

                $tr.find('.btn-cancel-inherit').on('click', function(e) {
                    e.stopPropagation();
                    $('.inherit-toast').remove();
                });
            }
        }
    });

    $codeInp.on('input', function() {
        $('.inherit-toast').remove();
        let q = $(this).val().trim();
        
        $.getJSON(window.location.href, { ajax_action: 'search_item_code_direct', search: q }, function(res) {
            if (res.success && res.results.length > 0) {
                let html = '';
                res.results.forEach(item => {
                    let jsonStr = htmlEscape(JSON.stringify(item));
                    html += `<div class="search-dropdown-item catalog-item-opt" data-item="${jsonStr}">
                                <strong>${item.item_code}</strong> - ${item.item_name}
                             </div>`;
                });
                $codeDd.html(html).removeClass('hidden');
            } else {
                $codeDd.addClass('hidden');
            }
        });
    });

    $nameInp.on('input focus', function() {
        let q = $(this).val().trim();
        $.getJSON(window.location.href, { ajax_action: 'search_name_suggestions', search: q }, function(res) {
            if (res.success && res.results.length > 0) {
                let html = '';
                res.results.forEach(sug => {
                    let jsonStr = htmlEscape(JSON.stringify(sug));
                    let deptLabel = sug.department_name ? ` <span class="text-slate-400">(${sug.department_name} / ${sug.sub_department_name || ''})</span>` : '';
                    html += `<div class="search-dropdown-item suggestion-opt" data-suggestion="${jsonStr}">
                                <strong>${sug.item_name}</strong>${deptLabel}
                             </div>`;
                });
                $nameDd.html(html).removeClass('hidden');
            } else {
                $nameDd.addClass('hidden');
            }
        });
    });
}

function copyAboveRowDetails($tr, $prevTr) {
    let prevName = $prevTr.find('.item-name').val();
    let prevDept = $prevTr.find('.sel-dept').val();
    let prevSubDept = $prevTr.find('.sel-subdept').val();
    let prevCat = $prevTr.find('.sel-cat').val();

    if (prevName) $tr.find('.item-name').val(prevName);
    if (prevDept) $tr.find('.sel-dept').val(prevDept);
    if (prevSubDept) $tr.find('.sel-subdept').val(prevSubDept);
    if (prevCat) $tr.find('.sel-cat').val(prevCat);

    triggerAutoSaveDebounced();
}

function htmlEscape(str) {
    return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function reindexRows() {
    $('#matrixBody tr').each((idx, el) => {
        $(el).find('.row-num').text(idx + 1);
    });
}

function calculateMatrixTotals() {
    let totalItems = 0;
    let totalUnits = 0;
    let grandCost = 0;

    $('#matrixBody tr').each(function() {
        let cost = parseFloat($(this).find('.inp-cost').val()) || 0;
        let qty = parseInt($(this).find('.inp-qty').val()) || 0;
        let code = $(this).find('.item-code').val().trim();

        let subtotal = cost * qty;
        $(this).find('.lbl-subtotal').text('LKR ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2}));

        if (code !== '' && qty > 0) {
            totalItems++;
            totalUnits += qty;
            grandCost += subtotal;
        }
    });

    $('#lbl-item-count').text(totalItems);
    $('#lbl-unit-count').text(totalUnits);
    $('#lbl-total-cost').text('LKR ' + grandCost.toLocaleString('en-US', {minimumFractionDigits: 2}));
}

function getValidItems() {
    let items = [];
    $('#matrixBody tr').each(function() {
        let code = $(this).find('.item-code').val().trim();
        let name = $(this).find('.item-name').val().trim();
        let qty = parseInt($(this).find('.inp-qty').val()) || 0;

        if (code || name || qty > 0) {
            items.push({
                system_code: $(this).find('.sys-code').val().trim(),
                item_code: code,
                item_name: name,
                department_id: $(this).find('.sel-dept').val(),
                sub_department_id: $(this).find('.sel-subdept').val(),
                category_id: $(this).find('.sel-cat').val(),
                color_id: $(this).find('.sel-color').val(),
                size_id: $(this).find('.sel-size').val(),
                cost: parseFloat($(this).find('.inp-cost').val()) || 0,
                selling: parseFloat($(this).find('.inp-selling').val()) || 0,
                quantity: qty
            });
        }
    });
    return items;
}

function triggerAutoSaveDebounced() {
    $('#autosave-status').html('<i class="fas fa-spinner fa-spin text-amber-500"></i> Syncing...');
    
    let state = {
        supplier_id: $('#supplier_id').val(),
        supplier_name: $('#supplier_search_input').val(),
        purchase_date: $('#purchase_date').val(),
        expected_delivery_date: $('#expected_delivery_date').val(),
        status: $('#status').val(),
        attention: $('#attention').val(),
        remarks: $('#remarks').val(),
        items: getValidItems()
    };
    localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(state));

    clearTimeout(autoSaveDebounceTimer);
    autoSaveDebounceTimer = setTimeout(executeAutoSave, 2000);
}

function executeAutoSave() {
    let validItems = getValidItems();
    let formData = new FormData();
    formData.append('ajax_action', 'auto_save_draft');
    formData.append('supplier_id', $('#supplier_id').val());
    formData.append('supplier_name', $('#supplier_search_input').val());
    formData.append('purchase_date', $('#purchase_date').val());
    formData.append('expected_delivery_date', $('#expected_delivery_date').val());
    formData.append('status', $('#status').val());
    formData.append('attention', $('#attention').val());
    formData.append('remarks', $('#remarks').val());
    formData.append('items_json', JSON.stringify(validItems));

    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#autosave-status').html('<i class="fas fa-check-circle text-emerald-600"></i> Synced to Server DB');
            } else if (res.db_down) {
                $('#autosave-status').html('<i class="fas fa-database text-amber-600"></i> Client Storage Engine Active');
            }
        },
        error: function() {
            $('#autosave-status').html('<i class="fas fa-wifi text-red-600"></i> Offline (Saved Locally)');
        }
    });
}

function loadServerDraft() {
    $.getJSON(window.location.href, { ajax_action: 'load_draft' }, function(res) {
        if (res.success && res.draft) {
            populateFormAndMatrix(res.draft);
        } else {
            let cached = localStorage.getItem(LOCAL_STORAGE_KEY);
            if (cached) {
                try {
                    populateFormAndMatrix(JSON.parse(cached));
                    return;
                } catch(e) {}
            }
            addMatrixRows(1);
        }
    }).fail(function() {
        let cached = localStorage.getItem(LOCAL_STORAGE_KEY);
        if (cached) {
            try { populateFormAndMatrix(JSON.parse(cached)); } catch(e) {}
        } else {
            addMatrixRows(1);
        }
    });
}

function populateFormAndMatrix(data) {
    $('#matrixBody').empty();
    rowCounter = 0;
    
    if (data.supplier_id) {
        $('#supplier_id').val(data.supplier_id);
        $('#supplier_search_input').val(data.supplier_name || 'Selected Supplier');
    }
    if (data.purchase_date) $('#purchase_date').val(data.purchase_date);
    if (data.expected_delivery_date) $('#expected_delivery_date').val(data.expected_delivery_date);
    if (data.status) $('#status').val(data.status);
    if (data.attention) $('#attention').val(data.attention);
    if (data.remarks) $('#remarks').val(data.remarks);

    if (data.items && data.items.length > 0) {
        addMatrixRows(data.items.length, data.items);
    } else {
        addMatrixRows(1);
    }
}

function fetchAndShowDraftsModal() {
    $.getJSON(window.location.href, { ajax_action: 'get_all_drafts' }, function(res) {
        if (res.success && res.drafts.length > 0) {
            let html = '';
            res.drafts.forEach(d => {
                html += `<div class="p-3 bg-slate-50 border border-slate-200 rounded-lg flex justify-between items-center hover:bg-slate-100 cursor-pointer text-xs" onclick="restoreSpecificDraft(${d.draft_id})">
                            <div>
                                <div class="font-bold text-slate-800">${d.supplier_name || 'Unassigned Supplier'}</div>
                                <div class="text-[11px] text-slate-500">Date: ${d.purchase_date || 'N/A'} | Items: ${d.item_count}</div>
                            </div>
                            <button class="px-2 py-1 bg-indigo-600 text-white font-bold rounded text-[10px]">Load</button>
                         </div>`;
            });
            $('#draftListContainer').html(html);
        } else {
            $('#draftListContainer').html('<div class="text-xs text-slate-400 p-3 text-center">No active server drafts available.</div>');
        }
        $('#draftModal').removeClass('hidden');
    });
}

function restoreSpecificDraft(draftId) {
    $.getJSON(window.location.href, { ajax_action: 'load_specific_draft', draft_id: draftId }, function(res) {
        if (res.success && res.draft) {
            populateFormAndMatrix(res.draft);
            $('#draftModal').addClass('hidden');
            calculateMatrixTotals();
        }
    });
}

// ------------------------------------------------------------
// Final PO Submission with Comprehensive Error Handlers
// ------------------------------------------------------------
function submitFinalPO() {
    let validItems = getValidItems();
    
    if (validItems.length === 0) {
        alert("Execution Error: No valid items found in the matrix table.");
        return;
    }

    let formData = new FormData();
    formData.append('ajax_action', 'save_new_po');
    formData.append('supplier_id', $('#supplier_id').val());
    formData.append('purchase_date', $('#purchase_date').val());
    formData.append('expected_delivery_date', $('#expected_delivery_date').val());
    formData.append('status', $('#status').val());
    formData.append('attention', $('#attention').val());
    formData.append('remarks', $('#remarks').val());
    formData.append('items_json', JSON.stringify(validItems));

    let $btn = $('#btn-submit-po');
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing PO...');

    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                // Clear Local Storage Draft Engine State
                localStorage.removeItem(LOCAL_STORAGE_KEY);
                alert('Purchase Order Committed Successfully!\nGenerated PO Number: ' + res.po_number);
                window.location.href = 'view_pos.php';
            } else {
                alert('Commit Failure: ' + res.message);
                $btn.prop('disabled', false).html('<i class="fas fa-check"></i> Commit Order');
            }
        },
        error: function(xhr, status, error) {
            let errorMsg = "Server Communication Error: " + error;
            if (xhr.responseText) {
                try {
                    let parsed = JSON.parse(xhr.responseText);
                    if (parsed.message) errorMsg = parsed.message;
                } catch(e) {
                    errorMsg = "Server Execution Error:\n" + xhr.responseText.substring(0, 300);
                }
            }
            alert(errorMsg);
            $btn.prop('disabled', false).html('<i class="fas fa-check"></i> Commit Order');
        }
    });
}
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>