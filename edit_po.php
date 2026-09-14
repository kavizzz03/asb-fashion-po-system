<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Resource Limits & High-Capacity Optimization (Large Datasets & 150+ Matrix Rows)
ini_set('display_errors', 0);
error_reporting(E_ALL);

ini_set('memory_limit', '2048M');
ini_set('max_execution_time', 900);
ini_set('max_input_time', 900);

$page_title = 'ASB Fashion | Pending PO Editor Engine';
$page = 'po_ledger';

$dbError = null;
try {
    $conn = getConnection();      // po_system
    $qcConn = getQcConnection();  // return_qc
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// ------------------------------------------------------------
// Database Safeguards & Master Sync
// ------------------------------------------------------------
function ensureSuppliersTableExists($conn, $qcConn) {
    $check = $conn->query("SHOW TABLES LIKE 'suppliers'");
    if ($check && $check->num_rows > 0) return true;

    $createSQL = "CREATE TABLE suppliers (
        supplier_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        supplier_name VARCHAR(100) NOT NULL,
        system_id VARCHAR(50) DEFAULT NULL,
        contact_number VARCHAR(20) DEFAULT NULL,
        land_number VARCHAR(20) DEFAULT NULL,
        fax_number VARCHAR(20) DEFAULT NULL,
        contact_person VARCHAR(100) DEFAULT NULL,
        whatsapp VARCHAR(20) DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        status VARCHAR(50) DEFAULT NULL,
        PRIMARY KEY (supplier_id),
        KEY idx_supplier_name (supplier_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($createSQL)) {
        throw new Exception("Failed to create suppliers table: " . $conn->error);
    }

    $copySQL = "INSERT INTO suppliers (supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status)
                SELECT supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status FROM return_qc.suppliers";
    $conn->query($copySQL);
    return true;
}

if (!$dbError) {
    try { ensureSuppliersTableExists($conn, $qcConn); } catch (Exception $e) {}
}

function ensureSupplierExists($supplier_id, $conn, $qcConn) {
    $check = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ?");
    $check->bind_param("i", $supplier_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $check->close();
        return true;
    }
    $check->close();

    $fetch = $qcConn->prepare("SELECT * FROM suppliers WHERE supplier_id = ?");
    $fetch->bind_param("i", $supplier_id);
    $fetch->execute();
    $res = $fetch->get_result();
    if ($row = $res->fetch_assoc()) {
        $insert = $conn->prepare("INSERT INTO suppliers (supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("issssssssss", $row['supplier_id'], $row['supplier_name'], $row['system_id'], $row['contact_number'], $row['land_number'], $row['fax_number'], $row['contact_person'], $row['whatsapp'], $row['email'], $row['address'], $row['status']);
        $insert->execute();
        $insert->close();
        $fetch->close();
        return true;
    }
    $fetch->close();
    return false;
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
// AJAX Endpoints (Optimized for 2M+ Record Queries)
// ------------------------------------------------------------
if ((isset($_GET['ajax_action']) || isset($_POST['ajax_action'])) && (($_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '') !== 'save_po')) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $action = $_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;

    try {
        switch ($action) {
            case 'get_pos': // RESTRICTED EXCLUSIVELY TO PENDING POS
                $from_date = trim($_GET['from_date'] ?? '');
                $to_date   = trim($_GET['to_date'] ?? '');
                $search_po = trim($_GET['search'] ?? '');

                $where = ["h.status = 'Pending'"];
                $params = [];
                $types = "";

                if ($from_date) { $where[] = "h.purchase_date >= ?"; $params[] = $from_date; $types .= "s"; }
                if ($to_date)   { $where[] = "h.purchase_date <= ?"; $params[] = $to_date;   $types .= "s"; }
                if ($search_po) {
                    $where[] = "(h.po_number LIKE ? OR s.supplier_name LIKE ?)";
                    $like = "%$search_po%";
                    $params[] = $like; $params[] = $like;
                    $types .= "ss";
                }

                $where_clause = " WHERE " . implode(" AND ", $where);

                $sql = "SELECT h.po_id, h.po_number, h.purchase_date, h.expected_delivery_date, h.status,
                               s.supplier_name, COUNT(pi.po_item_id) AS total_items, COALESCE(SUM(pi.quantity), 0) AS total_qty
                        FROM po_header h
                        LEFT JOIN suppliers s ON h.supplier_id = s.supplier_id
                        LEFT JOIN po_items pi ON h.po_id = pi.po_id
                        $where_clause
                        GROUP BY h.po_id, h.po_number, h.purchase_date, h.expected_delivery_date, h.status, s.supplier_name
                        ORDER BY h.purchase_date DESC, h.po_id DESC LIMIT ? OFFSET ?";

                $stmt = $conn->prepare($sql);
                $bind_params = array_merge($params, [$per_page, $offset]);
                $stmt->bind_param($types . "ii", ...$bind_params);
                $stmt->execute();
                
                $res = $stmt->get_result();
                $results = [];
                while ($row = $res->fetch_assoc()) {
                    $results[] = $row;
                }
                $stmt->close();

                echo json_encode(['success' => true, 'results' => $results]);
                exit;

            case 'load_po':
                $po_id = (int)($_GET['po_id'] ?? 0);
                if (!$po_id) throw new Exception('Invalid PO ID');

                $stmt = $conn->prepare("SELECT h.*, s.supplier_name FROM po_header h LEFT JOIN suppliers s ON h.supplier_id = s.supplier_id WHERE h.po_id = ? AND h.status = 'Pending'");
                $stmt->bind_param("i", $po_id);
                $stmt->execute();
                $header = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$header) throw new Exception('Pending Purchase Order not found or status changed.');

                $itemStmt = $conn->prepare("SELECT pi.*, i.system_code, i.item_code, i.item_name, i.department_id, i.sub_department_id, i.category_id, i.color_id, i.size_id 
                                            FROM po_items pi JOIN items i ON pi.item_id = i.item_id WHERE pi.po_id = ?");
                $itemStmt->bind_param("i", $po_id);
                $itemStmt->execute();
                $resItems = $itemStmt->get_result();
                $items = [];
                while ($itemRow = $resItems->fetch_assoc()) {
                    $items[] = $itemRow;
                }
                $itemStmt->close();

                echo json_encode(['success' => true, 'header' => $header, 'items' => $items]);
                exit;

            case 'search_item_code_direct':
                $stmt = $conn->prepare("SELECT i.system_code, i.item_code, i.item_name, i.department_id, i.sub_department_id, i.category_id, i.color_id, i.size_id, i.cost_price AS cost, i.selling_price AS selling FROM items i WHERE i.item_code LIKE ? OR i.system_code LIKE ? ORDER BY i.item_code ASC LIMIT 15");
                $like = "%$search%";
                $stmt->bind_param("ss", $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                $results = [];
                while ($r = $res->fetch_assoc()) { $results[] = $r; }
                $stmt->close();
                echo json_encode(['success' => true, 'results' => $results]);
                exit;

            case 'search_name_suggestions':
                $stmt = $conn->prepare("SELECT s.suggestion_id, s.suggested_name AS item_name, s.department_id, s.sub_department_id, d.department_name, sd.sub_department_name FROM item_name_suggestions s LEFT JOIN departments d ON s.department_id = d.department_id LEFT JOIN sub_departments sd ON s.sub_department_id = sd.sub_department_id WHERE s.suggested_name LIKE ? ORDER BY s.suggested_name ASC LIMIT 15");
                $like = "%$search%";
                $stmt->bind_param("s", $like);
                $stmt->execute();
                $res = $stmt->get_result();
                $results = [];
                while ($r = $res->fetch_assoc()) { $results[] = $r; }
                $stmt->close();
                echo json_encode(['success' => true, 'results' => $results]);
                exit;

            case 'get_suppliers_direct':
                $stmt = $conn->prepare("SELECT supplier_id AS id, supplier_name AS name FROM suppliers WHERE supplier_name LIKE ? ORDER BY supplier_name ASC LIMIT 25");
                $like = "%$search%";
                $stmt->bind_param("s", $like);
                $stmt->execute();
                $res = $stmt->get_result();
                $results = [];
                while ($r = $res->fetch_assoc()) { $results[] = $r; }
                $stmt->close();
                echo json_encode(['success' => true, 'results' => $results]);
                exit;

            case 'get_options_master':
                echo json_encode([
                    'success' => true,
                    'options' => [
                        'departments' => $conn->query("SELECT department_id AS id, department_name AS name FROM departments ORDER BY department_name")->fetch_all(MYSQLI_ASSOC),
                        'sub_departments' => $conn->query("SELECT sub_department_id AS id, sub_department_name AS name FROM sub_departments ORDER BY sub_department_name")->fetch_all(MYSQLI_ASSOC),
                        'categories' => $conn->query("SELECT category_id AS id, category_name AS name FROM categories ORDER BY category_name")->fetch_all(MYSQLI_ASSOC),
                        'colors' => $conn->query("SELECT color_id AS id, color_name AS name FROM colors ORDER BY color_name")->fetch_all(MYSQLI_ASSOC),
                        'sizes' => $conn->query("SELECT size_id AS id, size_name AS name FROM sizes ORDER BY size_name")->fetch_all(MYSQLI_ASSOC)
                    ]
                ]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ------------------------------------------------------------
// Save / Synchronize PO & Master Items Deletion Engine
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_po') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => ''];

    try {
        if (empty($_SESSION['manager_authorized'])) {
            $input_password = trim($_POST['manager_password'] ?? '');
            if (empty($input_password)) {
                throw new Exception('Manager authorization password required.');
            }
            $pwd_stmt = $conn->prepare("SELECT password FROM manager_credentials LIMIT 1");
            $pwd_stmt->execute();
            $res = $pwd_stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($input_password !== $row['password']) {
                    throw new Exception('Invalid manager authorization password.');
                }
            } else {
                throw new Exception('Manager credentials not configured.');
            }
            $pwd_stmt->close();
            $_SESSION['manager_authorized'] = true;
        }

        $po_id = (int)($_POST['po_id'] ?? 0);
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $purchase_date = $_POST['purchase_date'] ?? '';
        $attention = trim($_POST['attention'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $expected_delivery_date = $_POST['expected_delivery_date'] ?: null;
        $status = trim($_POST['status'] ?? 'Pending');

        if ($po_id <= 0) throw new Exception('Invalid PO selected for editing.');
        if ($supplier_id <= 0 || empty($purchase_date)) throw new Exception('Supplier and Purchase Date are required.');

        ensureSupplierExists($supplier_id, $conn, $qcConn);

        $items = json_decode($_POST['items_json'] ?? '[]', true);
        if (!is_array($items)) {
            throw new Exception("Matrix data format invalid.");
        }

        $conn->begin_transaction();

        // 1. Update Header
        $update_header = $conn->prepare("UPDATE po_header SET supplier_id = ?, purchase_date = ?, attention = ?, remarks = ?, expected_delivery_date = ?, status = ? WHERE po_id = ?");
        $update_header->bind_param("isssssi", $supplier_id, $purchase_date, $attention, $remarks, $expected_delivery_date, $status, $po_id);
        if (!$update_header->execute()) throw new Exception("Header update failed: " . $update_header->error);
        $update_header->close();

        // 2. Fetch Existing PO Item Links
        $existing_stmt = $conn->prepare("SELECT po_item_id, item_id FROM po_items WHERE po_id = ?");
        $existing_stmt->bind_param("i", $po_id);
        $existing_stmt->execute();
        $resExisting = $existing_stmt->get_result();
        $existing_items = [];
        while ($exRow = $resExisting->fetch_assoc()) {
            $existing_items[] = $exRow;
        }
        $existing_stmt->close();

        $processed_po_item_ids = [];

        // 3. Upsert matrix lines and dynamic catalog items
        foreach ($items as $idx => $item) {
            $code = trim($item['item_code'] ?? '');
            $name = trim($item['item_name'] ?? '');
            $qty = (int)($item['quantity'] ?? 0);

            if (empty($code) || empty($name) || $qty <= 0) continue;

            $item_id = saveOrUpdateItemBulk($conn, $item, $supplier_id);
            if (!$item_id) throw new Exception("Row #" . ($idx + 1) . ": Item registration failed.");

            $cost = (float)($item['cost'] ?? 0);
            $sell = (float)($item['selling'] ?? 0);

            $check_pi = $conn->prepare("SELECT po_item_id FROM po_items WHERE po_id = ? AND item_id = ?");
            $check_pi->bind_param("ii", $po_id, $item_id);
            $check_pi->execute();
            $pi_res = $check_pi->get_result();

            if ($pi_row = $pi_res->fetch_assoc()) {
                $po_item_id = $pi_row['po_item_id'];
                $upd_pi = $conn->prepare("UPDATE po_items SET quantity = ?, cost_price = ?, selling_price = ? WHERE po_item_id = ?");
                $upd_pi->bind_param("iddi", $qty, $cost, $sell, $po_item_id);
                $upd_pi->execute();
                $upd_pi->close();
                $processed_po_item_ids[] = $po_item_id;
            } else {
                $ins_pi = $conn->prepare("INSERT INTO po_items (po_id, item_id, quantity, cost_price, selling_price, received_qty) VALUES (?, ?, ?, ?, ?, 0)");
                $ins_pi->bind_param("iiidd", $po_id, $item_id, $qty, $cost, $sell);
                $ins_pi->execute();
                $processed_po_item_ids[] = $ins_pi->insert_id;
                $ins_pi->close();
            }
            $check_pi->close();
        }

        // 4. Atomic Row Deletion: Delete unreferenced items from BOTH po_items AND items tables
        if (!empty($existing_items)) {
            foreach ($existing_items as $ex) {
                if (!in_array($ex['po_item_id'], $processed_po_item_ids)) {
                    $target_item_id = $ex['item_id'];
                    
                    // Delete link from po_items
                    $del_pi = $conn->prepare("DELETE FROM po_items WHERE po_item_id = ?");
                    $del_pi->bind_param("i", $ex['po_item_id']);
                    $del_pi->execute();
                    $del_pi->close();

                    // Check if item is used elsewhere before purging from master `items`
                    $check_usage = $conn->prepare("SELECT COUNT(*) AS ref_count FROM po_items WHERE item_id = ?");
                    $check_usage->bind_param("i", $target_item_id);
                    $check_usage->execute();
                    $ref_count = $check_usage->get_result()->fetch_assoc()['ref_count'];
                    $check_usage->close();

                    if ($ref_count == 0) {
                        $del_item = $conn->prepare("DELETE FROM items WHERE item_id = ?");
                        $del_item->bind_param("i", $target_item_id);
                        $del_item->execute();
                        $del_item->close();
                    }
                }
            }
        }

        // 5. Log status
        $log_stmt = $conn->prepare("INSERT INTO po_status_log (po_id, status, remarks) VALUES (?, ?, ?)");
        $log_remarks = "Pending PO matrix synchronized and saved";
        $log_stmt->bind_param("iss", $po_id, $status, $log_remarks);
        $log_stmt->execute();
        $log_stmt->close();

        $conn->commit();

        $response['success'] = true;
        $response['message'] = "Pending Purchase Order updated successfully! Local cache cleared.";

    } catch (Exception $e) {
        if (isset($conn)) @$conn->rollback();
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

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
    .search-dropdown { position: absolute; left: 0; right: 0; top: 100%; margin-top: 2px; background: #ffffff; border: 1px solid #94a3b8; border-radius: 6px; max-height: 220px; overflow-y: auto; z-index: 9999 !important; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); }
    .search-dropdown-upper { position: absolute; left: 0; right: 0; bottom: 100%; margin-bottom: 2px; background: #ffffff; border: 1px solid #94a3b8; border-radius: 6px; max-height: 220px; overflow-y: auto; z-index: 9999 !important; box-shadow: 0 -10px 15px -3px rgba(0, 0, 0, 0.2); }
    .search-dropdown-item { padding: 7px 10px; font-size: 11px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
    .search-dropdown-item:hover, .search-dropdown-item.active { background-color: #fee2e2; color: #991b1b; font-weight: 600; }
</style>

<div class="p-4 md:p-6 max-w-[1700px] mx-auto">
    
    <!-- Top Action Bar -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-5 pb-4 border-b border-slate-200 gap-4">
        <div>
            <h1 class="text-xl font-black text-red-700 tracking-tight flex items-center gap-2">
                <i class="fas fa-edit"></i> ASB FASHION 
                <span class="text-slate-400 font-normal text-base">| Pending PO Editor Engine</span>
            </h1>
            <p class="text-xs text-slate-500">Shortcuts: 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+S</kbd> Supplier | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+I</kbd> Code | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+D</kbd> Description | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Alt+1..5</kbd> Dropdowns | 
                <kbd class="px-1 py-0.5 bg-slate-200 rounded text-[10px]">Enter</kbd> Confirm
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
            <button type="button" id="btn-restore-cache" class="bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs px-3 py-2 rounded-md hidden shadow-sm flex items-center gap-1">
                <i class="fas fa-history"></i> Restore Draft Cache
            </button>
            <button type="button" id="btn-add-10" class="bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs px-3 py-2 rounded-md">+10 Rows</button>
            <button type="button" id="btn-save-po" class="bg-red-700 hover:bg-red-800 text-white font-bold text-xs px-4 py-2 rounded-md shadow-sm flex items-center gap-1">
                <i class="fas fa-save"></i> Save Pending PO
            </button>
        </div>
    </div>

    <!-- Pending POs Filter Section -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm mb-5">
        <div class="flex items-center justify-between mb-3 border-b border-slate-100 pb-2">
            <span class="text-xs font-bold text-amber-700 uppercase tracking-wider flex items-center gap-1">
                <i class="fas fa-clock"></i> Active Pending Purchase Orders
            </span>
            <span id="editing-po-label" class="text-xs font-bold text-red-700 bg-red-50 px-2 py-1 rounded">No PO Selected</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <input type="date" id="filter_from_date" class="asb-input-sm" title="From Date">
            <input type="date" id="filter_to_date" class="asb-input-sm" title="To Date">
            <input type="text" id="filter_search" placeholder="Search Pending PO # or Supplier..." class="asb-input-sm">
            <div class="flex gap-2">
                <button type="button" onclick="loadPOList()" class="bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold px-3 py-1.5 rounded w-full">Filter List</button>
                <button type="button" onclick="resetPOFilters()" class="bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold px-3 py-1.5 rounded">Reset</button>
            </div>
        </div>
        <div class="mt-3 max-h-40 overflow-y-auto border border-slate-200 rounded-lg">
            <table class="w-full text-left text-xs border-collapse">
                <thead class="bg-slate-100 sticky top-0 font-bold text-slate-600">
                    <tr>
                        <th class="p-2 border-b">PO #</th>
                        <th class="p-2 border-b">Supplier</th>
                        <th class="p-2 border-b">Date</th>
                        <th class="p-2 border-b">Status</th>
                        <th class="p-2 border-b text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="poListBody">
                    <tr><td colspan="5" class="p-3 text-center text-slate-400">Loading pending orders...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Header Details Form -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm mb-5">
        <input type="hidden" id="po_id" value="0">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="relative">
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Supplier * (Alt+S)</label>
                <input type="text" id="supplier_search_input" placeholder="Search supplier..." autocomplete="off" class="asb-input-sm font-semibold">
                <input type="hidden" id="supplier_id">
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
                    <option value="Pending" selected>Pending</option>
                    <option value="Received">Received</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-slate-600 mb-1">Cache Engine Status</label>
                <div id="cache-status" class="text-xs font-semibold text-emerald-600 py-1 flex items-center gap-1">
                    <i class="fas fa-check-circle"></i> Database Synced
                </div>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
            <input type="text" id="attention" placeholder="Attention (Contact person / Department)" class="asb-input-sm">
            <input type="text" id="remarks" placeholder="Order remarks..." class="asb-input-sm">
        </div>
    </div>

    <!-- Matrix Table with Local Cache Control Banner -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm mb-5" style="overflow: visible;">
        <div class="max-h-[600px] overflow-y-auto overflow-x-visible relative">
            <table class="w-full matrix-table text-left border-collapse">
                <thead>
                    <tr class="text-slate-600">
                        <th class="p-2 w-8 text-center">#</th>
                        <th class="p-2 w-20">Sys Code</th>
                        <th class="p-2 w-32">Item Code (Alt+I) *</th>
                        <th class="p-2 w-44">Item Description (Alt+D) *</th>
                        <th class="p-2 w-24">Dept</th>
                        <th class="p-2 w-24">Sub Dept</th>
                        <th class="p-2 w-24">Category</th>
                        <th class="p-2 w-28">Color (Alt+4) *</th>
                        <th class="p-2 w-24">Size (Alt+5) *</th>
                        <th class="p-2 w-28 text-right">Cost Price *</th>
                        <th class="p-2 w-28 text-right">Selling Price *</th>
                        <th class="p-2 w-20 text-center">Qty *</th>
                        <th class="p-2 w-28 text-right">Subtotal</th>
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

<!-- Manager Authorization Modal -->
<div id="passwordModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden z-50 flex items-center justify-center">
    <div class="bg-white rounded-xl max-w-sm w-full p-6 shadow-xl border border-slate-200">
        <h3 class="text-base font-extrabold text-red-700 mb-2 flex items-center gap-2">
            <i class="fas fa-lock"></i> Manager Authorization Required
        </h3>
        <p class="text-xs text-slate-500 mb-4">Enter manager password to authorize pending PO matrix edits.</p>
        <input type="password" id="modalPassword" placeholder="Enter password..." class="asb-input-sm mb-3">
        <div id="modalError" class="text-xs text-red-600 font-bold mb-3"></div>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="$('#passwordModal').addClass('hidden')" class="px-3 py-1.5 bg-slate-100 text-slate-700 text-xs font-bold rounded">Cancel</button>
            <button type="button" id="btn-confirm-auth" class="px-4 py-1.5 bg-red-700 text-white text-xs font-bold rounded">Authenticate &amp; Save</button>
        </div>
    </div>
</div>

<script>
let masterOptions = { departments: [], sub_departments: [], categories: [], colors: [], sizes: [] };
let rowCounter = 0;
let isAuthorizedSession = <?= !empty($_SESSION['manager_authorized']) ? 'true' : 'false'; ?>;
const LOCAL_EDIT_CACHE_KEY = 'ASB_PENDING_PO_EDIT_LIVE_CACHE';

$(document).ready(function() {
    loadMasterOptions();
    initSupplierSearch();
    loadPOList();
    checkExistingLocalCache();

    // Global Keybindings Shortcut Engine
    $(document).on('keydown', function(e) {
        let $lastTr = $('#matrixBody tr:last-child');
        if (e.altKey && (e.key === 'a' || e.key === 'A')) { e.preventDefault(); addMatrixRows(1); }
        else if (e.altKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); $('#supplier_search_input').focus().select(); }
        else if (e.altKey && (e.key === 'i' || e.key === 'I')) { e.preventDefault(); $lastTr.find('.item-code').focus().select(); }
        else if (e.altKey && (e.key === 'd' || e.key === 'D')) { e.preventDefault(); $lastTr.find('.item-name').focus().select(); }
        else if (e.altKey && e.key === '1') { e.preventDefault(); $lastTr.find('.sel-dept').focus(); }
        else if (e.altKey && e.key === '2') { e.preventDefault(); $lastTr.find('.sel-subdept').focus(); }
        else if (e.altKey && e.key === '3') { e.preventDefault(); $lastTr.find('.sel-cat').focus(); }
        else if (e.altKey && e.key === '4') { e.preventDefault(); $lastTr.find('.sel-color').focus(); }
        else if (e.altKey && e.key === '5') { e.preventDefault(); $lastTr.find('.sel-size').focus(); }
        else if (e.key === 'Escape') { $('.search-dropdown, .search-dropdown-upper').addClass('hidden'); }
    });

    $(document).on('mousedown', function(e) {
        if (!$(e.target).closest('.relative').length) { $('.search-dropdown, .search-dropdown-upper').addClass('hidden'); }
    });

    $(document).on('keydown', '.matrix-field', function(e) {
        if (e.key === 'Enter') {
            let $dd = $(this).parent().find('.search-dropdown, .search-dropdown-upper');
            if (!$dd.hasClass('hidden')) {
                let $activeOpt = $dd.find('.search-dropdown-item.active');
                if ($activeOpt.length) { $activeOpt.trigger('click'); } 
                else { $dd.find('.search-dropdown-item').first().trigger('click'); }
                $dd.addClass('hidden');
                e.preventDefault();
                return;
            }
            e.preventDefault();
            let fields = $('.matrix-field');
            let idx = fields.index(this);
            if (idx > -1 && idx < fields.length - 1) { fields.eq(idx + 1).focus().select(); }
            else { addMatrixRows(1); }
        }
    });

    $('#btn-add-row').click(() => addMatrixRows(1));
    $('#btn-add-10').click(() => addMatrixRows(10));
    $('#btn-restore-cache').click(restoreStateFromCache);

    $(document).on('input change', '.matrix-field, #purchase_date, #expected_delivery_date, #status, #attention, #remarks', function() {
        calculateMatrixTotals();
        cacheCurrentStateRealTime();
    });

    // Atomic Row Deletion with Master DB Link Purge Notice
    $(document).on('click', '.btn-remove-row', function() {
        let $tr = $(this).closest('tr');
        let lineNo = $tr.find('.row-num').text();
        let itemCode = $tr.find('.item-code').val().trim() || 'Unassigned';

        if (confirm(`Confirm deleting Line #${lineNo} (${itemCode}) from both PO items and master database tables?`)) {
            if ($('#matrixBody tr').length > 1) {
                $tr.remove();
            } else {
                $tr.find('input').val('');
                $tr.find('select').val('');
                $tr.find('.inp-qty').val(1);
            }
            reindexRows();
            calculateMatrixTotals();
            cacheCurrentStateRealTime();
        }
    });

    $('#btn-save-po').click(function() {
        let po_id = $('#po_id').val();
        if (po_id <= 0) { alert("Error: Select an active Pending Purchase Order from the table list first."); return; }
        if (isAuthorizedSession) {
            submitPOSave();
        } else {
            $('#modalPassword').val('');
            $('#modalError').text('');
            $('#passwordModal').removeClass('hidden');
        }
    });

    $('#btn-confirm-auth').click(submitPOSave);
});

function loadMasterOptions() {
    $.getJSON(window.location.href, { ajax_action: 'get_options_master' }, function(res) {
        if (res.success) masterOptions = res.options;
    });
}

function initSupplierSearch() {
    $('#supplier_search_input').on('input focus', function() {
        let q = $(this).val().trim();
        $.getJSON(window.location.href, { ajax_action: 'get_suppliers_direct', search: q }, function(res) {
            if (res.success && res.results.length > 0) {
                let html = '';
                res.results.forEach(s => { html += `<div class="search-dropdown-item supplier-opt" data-id="${s.id}" data-name="${s.name}">${s.name}</div>`; });
                $('#supplier_dropdown').html(html).removeClass('hidden');
            } else { $('#supplier_dropdown').addClass('hidden'); }
        });
    });

    $(document).on('click', '.supplier-opt', function() {
        $('#supplier_id').val($(this).data('id'));
        $('#supplier_search_input').val($(this).data('name'));
        $('#supplier_dropdown').addClass('hidden');
        cacheCurrentStateRealTime();
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
                <input type="text" value="${data.item_name || ''}" placeholder="Type Description..." class="asb-input-sm matrix-field item-name" autocomplete="off">
                <div class="search-dropdown-upper item-name-dd hidden"></div>
            </td>
            <td><select class="asb-input-sm matrix-field sel-dept">${renderOptionTags(masterOptions.departments, data.department_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-subdept">${renderOptionTags(masterOptions.sub_departments, data.sub_department_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-cat">${renderOptionTags(masterOptions.categories, data.category_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-color font-semibold">${renderOptionTags(masterOptions.colors, data.color_id)}</select></td>
            <td><select class="asb-input-sm matrix-field sel-size font-semibold">${renderOptionTags(masterOptions.sizes, data.size_id)}</select></td>
            <td><input type="number" step="0.01" value="${data.cost || data.cost_price || 0}" class="asb-input-sm matrix-field inp-cost text-right font-mono"></td>
            <td><input type="number" step="0.01" value="${data.selling || data.selling_price || 0}" class="asb-input-sm matrix-field inp-selling text-right font-mono"></td>
            <td><input type="number" value="${data.quantity || 1}" min="1" class="asb-input-sm matrix-field inp-qty text-center font-bold"></td>
            <td class="text-right text-xs font-bold text-slate-700 pr-2 lbl-subtotal font-mono">LKR 0.00</td>
            <td class="text-center"><button type="button" class="btn-remove-row text-slate-400 hover:text-red-600"><i class="fas fa-times"></i></button></td>
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

    $codeInp.on('input', function() {
        let q = $(this).val().trim();
        $.getJSON(window.location.href, { ajax_action: 'search_item_code_direct', search: q }, function(res) {
            if (res.success && res.results.length > 0) {
                let html = '';
                res.results.forEach(item => {
                    let jsonStr = JSON.stringify(item).replace(/"/g, '&quot;');
                    html += `<div class="search-dropdown-item catalog-item-opt" data-item="${jsonStr}"><strong>${item.item_code}</strong> - ${item.item_name}</div>`;
                });
                $codeDd.html(html).removeClass('hidden');
            } else { $codeDd.addClass('hidden'); }
        });
    });

    $nameInp.on('input focus', function() {
        let q = $(this).val().trim();
        $.getJSON(window.location.href, { ajax_action: 'search_name_suggestions', search: q }, function(res) {
            if (res.success && res.results.length > 0) {
                let html = '';
                res.results.forEach(sug => {
                    let jsonStr = JSON.stringify(sug).replace(/"/g, '&quot;');
                    html += `<div class="search-dropdown-item suggestion-opt" data-suggestion="${jsonStr}"><strong>${sug.item_name}</strong></div>`;
                });
                $nameDd.html(html).removeClass('hidden');
            } else { $nameDd.addClass('hidden'); }
        });
    });
}

$(document).on('click', '.catalog-item-opt', function() {
    let item = JSON.parse($(this).attr('data-item'));
    let $tr = $(this).closest('tr');
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
    $('.search-dropdown, .search-dropdown-upper').addClass('hidden');
    calculateMatrixTotals();
    cacheCurrentStateRealTime();
});

$(document).on('click', '.suggestion-opt', function() {
    let sug = JSON.parse($(this).attr('data-suggestion'));
    let $tr = $(this).closest('tr');
    $tr.find('.item-name').val(sug.item_name);
    if (sug.department_id) $tr.find('.sel-dept').val(sug.department_id);
    if (sug.sub_department_id) $tr.find('.sel-subdept').val(sug.sub_department_id);
    $('.search-dropdown, .search-dropdown-upper').addClass('hidden');
    calculateMatrixTotals();
    cacheCurrentStateRealTime();
});

function reindexRows() {
    $('#matrixBody tr').each((idx, el) => { $(el).find('.row-num').text(idx + 1); });
}

function calculateMatrixTotals() {
    let totalItems = 0, totalUnits = 0, grandCost = 0;
    $('#matrixBody tr').each(function() {
        let cost = parseFloat($(this).find('.inp-cost').val()) || 0;
        let qty = parseInt($(this).find('.inp-qty').val()) || 0;
        let code = $(this).find('.item-code').val().trim();
        let subtotal = cost * qty;
        $(this).find('.lbl-subtotal').text('LKR ' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2}));
        if (code !== '' && qty > 0) { totalItems++; totalUnits += qty; grandCost += subtotal; }
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

// ------------------------------------------------------------
// Local Storage Engine & Instant Restoration Utilities
// ------------------------------------------------------------
function checkExistingLocalCache() {
    let savedCache = localStorage.getItem(LOCAL_EDIT_CACHE_KEY);
    if (savedCache) {
        try {
            let data = JSON.parse(savedCache);
            if (data && data.items && data.items.length > 0) {
                $('#btn-restore-cache').removeClass('hidden');
                $('#cache-status').html('<i class="fas fa-exclamation-circle text-amber-500"></i> Unsaved Draft Cache Found');
            }
        } catch (e) {}
    }
}

function cacheCurrentStateRealTime() {
    let po_id = $('#po_id').val();
    if (po_id <= 0) return;

    let state = {
        po_id: po_id,
        supplier_id: $('#supplier_id').val(),
        supplier_name: $('#supplier_search_input').val(),
        purchase_date: $('#purchase_date').val(),
        expected_delivery_date: $('#expected_delivery_date').val(),
        status: $('#status').val(),
        attention: $('#attention').val(),
        remarks: $('#remarks').val(),
        items: getValidItems()
    };
    localStorage.setItem(LOCAL_EDIT_CACHE_KEY, JSON.stringify(state));
    $('#btn-restore-cache').removeClass('hidden');
    $('#cache-status').html('<i class="fas fa-sync fa-spin text-amber-500"></i> Local Syncing...');
    setTimeout(() => { $('#cache-status').html('<i class="fas fa-save text-amber-500"></i> Unsaved Local Cache Present'); }, 400);
}

function restoreStateFromCache() {
    let savedCache = localStorage.getItem(LOCAL_EDIT_CACHE_KEY);
    if (!savedCache) { alert('No unsaved local draft cache found.'); return; }

    try {
        let state = JSON.parse(savedCache);
        if (!state.items || state.items.length === 0) { alert('Draft cache contains no items.'); return; }

        if (confirm(`Restore draft cache for PO #${state.po_id || 'Active Draft'}? Current form contents will be replaced.`)) {
            $('#po_id').val(state.po_id || 0);
            $('#supplier_id').val(state.supplier_id || '');
            $('#supplier_search_input').val(state.supplier_name || '');
            $('#purchase_date').val(state.purchase_date || '');
            $('#expected_delivery_date').val(state.expected_delivery_date || '');
            $('#status').val(state.status || 'Pending');
            $('#attention').val(state.attention || '');
            $('#remarks').val(state.remarks || '');
            
            if (state.po_id > 0) {
                $('#editing-po-label').text('Editing PO (Restored Draft): #' + state.po_id);
            }

            $('#matrixBody').empty();
            rowCounter = 0;
            addMatrixRows(state.items.length, state.items);
            calculateMatrixTotals();
            $('#cache-status').html('<i class="fas fa-check-circle text-amber-500"></i> Restored Unsaved Draft');
        }
    } catch (e) {
        alert('Failed to parse cached draft: ' + e.message);
    }
}

function loadPOList() {
    $.getJSON(window.location.href, {
        ajax_action: 'get_pos',
        from_date: $('#filter_from_date').val(),
        to_date: $('#filter_to_date').val(),
        search: $('#filter_search').val()
    }, function(res) {
        if (res.success && res.results.length > 0) {
            let html = '';
            res.results.forEach(po => {
                html += `
                    <tr class="hover:bg-slate-50 border-b border-slate-100">
                        <td class="p-2 font-bold text-slate-800">${po.po_number}</td>
                        <td class="p-2">${po.supplier_name || 'N/A'}</td>
                        <td class="p-2">${po.purchase_date}</td>
                        <td class="p-2"><span class="px-2 py-0.5 text-[10px] font-bold rounded bg-amber-100 text-amber-800">${po.status}</span></td>
                        <td class="p-2 text-center">
                            <button type="button" onclick="loadPOForEdit(${po.po_id})" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-[10px] px-2 py-1 rounded">Load Pending PO</button>
                        </td>
                    </tr>
                `;
            });
            $('#poListBody').html(html);
        } else {
            $('#poListBody').html('<tr><td colspan="5" class="p-3 text-center text-slate-400">No active pending purchase orders found.</td></tr>');
        }
    });
}

function resetPOFilters() {
    $('#filter_from_date').val('');
    $('#filter_to_date').val('');
    $('#filter_search').val('');
    loadPOList();
}

function loadPOForEdit(po_id) {
    $.getJSON(window.location.href, { ajax_action: 'load_po', po_id: po_id }, function(res) {
        if (res.success) {
            let header = res.header;
            $('#po_id').val(header.po_id);
            $('#supplier_id').val(header.supplier_id);
            $('#supplier_search_input').val(header.supplier_name || '');
            $('#purchase_date').val(header.purchase_date);
            $('#expected_delivery_date').val(header.expected_delivery_date || '');
            $('#status').val(header.status);
            $('#attention').val(header.attention || '');
            $('#remarks').val(header.remarks || '');
            $('#editing-po-label').text('Editing PO: ' + header.po_number);

            $('#matrixBody').empty();
            rowCounter = 0;
            if (res.items && res.items.length > 0) {
                addMatrixRows(res.items.length, res.items);
            } else {
                addMatrixRows(1);
            }
            calculateMatrixTotals();
            cacheCurrentStateRealTime();
        } else {
            alert('Load Error: ' + res.error);
        }
    });
}

function submitPOSave() {
    let validItems = getValidItems();
    if (validItems.length === 0) { alert('Validation Failure: Matrix must contain valid line items.'); return; }

    let formData = new FormData();
    formData.append('ajax_action', 'save_po');
    formData.append('po_id', $('#po_id').val());
    formData.append('supplier_id', $('#supplier_id').val());
    formData.append('purchase_date', $('#purchase_date').val());
    formData.append('expected_delivery_date', $('#expected_delivery_date').val());
    formData.append('status', $('#status').val());
    formData.append('attention', $('#attention').val());
    formData.append('remarks', $('#remarks').val());
    formData.append('manager_password', $('#modalPassword').val().trim());
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
                isAuthorizedSession = true;
                
                // Automatically purge local draft cache upon successful DB sync
                localStorage.removeItem(LOCAL_EDIT_CACHE_KEY);
                $('#btn-restore-cache').addClass('hidden');
                $('#cache-status').html('<i class="fas fa-check-circle text-emerald-600"></i> Database Synced & Cache Purged');
                
                $('#passwordModal').addClass('hidden');
                alert(res.message);
                loadPOList();
            } else {
                $('#modalError').text(res.message);
            }
        },
        error: function(xhr, status, error) {
            $('#modalError').text('Execution error: ' + error);
        }
    });
}
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>