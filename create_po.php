<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Suppress HTML errors and ensure we catch everything
ini_set('display_errors', 0);
error_reporting(E_ALL);

$page_title = 'ASB Fashion | Create Purchase Order';
$page = 'po_ledger';

$conn = getConnection();      // po_system
$qcConn = getQcConnection();  // return_qc

// ------------------------------------------------------------
// Ensure suppliers table exists in po_system with correct data type
// ------------------------------------------------------------
function ensureSuppliersTableExists($conn, $qcConn) {
    // Check if table exists
    $check = $conn->query("SHOW TABLES LIKE 'suppliers'");
    if ($check->num_rows > 0) {
        return true;
    }

    // Create table with BIGINT UNSIGNED to match po_header.supplier_id
    $createSQL = "CREATE TABLE suppliers (
        supplier_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        supplier_name VARCHAR(100) NOT NULL,
        system_id VARCHAR(50) DEFAULT NULL,
        contact_number VARCHAR(20) DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        PRIMARY KEY (supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($createSQL)) {
        throw new Exception("Failed to create suppliers table: " . $conn->error);
    }

    // Copy all data from return_qc.suppliers
    $copySQL = "INSERT INTO suppliers (supplier_id, supplier_name, system_id, contact_number, email, address)
                SELECT supplier_id, supplier_name, system_id, contact_number, email, address
                FROM return_qc.suppliers";
    if (!$conn->query($copySQL)) {
        throw new Exception("Failed to copy suppliers: " . $conn->error);
    }
    return true;
}

// Call it – if it fails, stop and show error
try {
    ensureSuppliersTableExists($conn, $qcConn);
} catch (Exception $e) {
    die("Fatal error: " . $e->getMessage());
}

// Helper: ensure a specific supplier exists in po_system
function ensureSupplierExists($supplier_id, $conn, $qcConn) {
    // Check if exists
    $check = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ?");
    $check->bind_param("i", $supplier_id);
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows > 0) {
        $check->close();
        return true;
    }
    $check->close();

    // Fetch from QC
    $fetch = $qcConn->prepare("SELECT supplier_name, system_id, contact_number, email, address FROM suppliers WHERE supplier_id = ?");
    $fetch->bind_param("i", $supplier_id);
    $fetch->execute();
    $res = $fetch->get_result();
    if ($row = $res->fetch_assoc()) {
        // Insert into po_system
        $insert = $conn->prepare("INSERT INTO suppliers (supplier_id, supplier_name, system_id, contact_number, email, address)
                                  VALUES (?, ?, ?, ?, ?, ?)");
        $insert->bind_param("isssss", $supplier_id, $row['supplier_name'], $row['system_id'], $row['contact_number'], $row['email'], $row['address']);
        if ($insert->execute()) {
            $insert->close();
            $fetch->close();
            return true;
        }
        $insert->close();
    }
    $fetch->close();
    return false;
}

// ------------------------------------------------------------
// AJAX endpoints (with error handling)
// ------------------------------------------------------------
if (isset($_GET['ajax_action'])) {
    // Discard any previous output to ensure JSON
    ob_clean();
    header('Content-Type: application/json');

    $action = $_GET['ajax_action'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;

    $response = ['results' => [], 'pagination' => ['more' => false]];

    try {
        switch ($action) {
            case 'get_suppliers':
                // Fetch from QC
                $sql = "SELECT supplier_id AS id, supplier_name AS text FROM suppliers";
                $count_sql = "SELECT COUNT(*) AS total FROM suppliers";
                $where = "";
                if (!empty($search)) {
                    $where = " WHERE supplier_name LIKE ?";
                    $like = "%$search%";
                }
                $stmt = $qcConn->prepare($sql . $where . " ORDER BY supplier_name LIMIT ? OFFSET ?");
                $count_stmt = $qcConn->prepare($count_sql . $where);
                if (!empty($search)) {
                    $stmt->bind_param("sii", $like, $per_page, $offset);
                    $count_stmt->bind_param("s", $like);
                } else {
                    $stmt->bind_param("ii", $per_page, $offset);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                break;

            case 'get_items':
                $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : null;
                $supplier_id   = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
                $sql = "SELECT i.item_id AS id, CONCAT(i.item_code, ' - ', i.item_name) AS text, 
                               i.system_code, i.item_code, i.item_name, i.department_id, i.sub_department_id, 
                               i.category_id, i.color_id, i.size_id, i.cost_price AS master_cost_price, i.selling_price AS master_selling_price
                        FROM items i";
                $count_sql = "SELECT COUNT(*) AS total FROM items i";
                $where = [];
                $params = [];
                $types = "";

                if (!empty($search)) {
                    $where[] = "(i.item_code LIKE ? OR i.item_name LIKE ?)";
                    $like = "%$search%";
                    $params[] = $like;
                    $params[] = $like;
                    $types .= "ss";
                }
                if ($department_id) {
                    $where[] = "i.department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                if ($supplier_id) {
                    $where[] = "i.item_id IN (SELECT item_id FROM supplier_items WHERE supplier_id = ?)";
                    $params[] = $supplier_id;
                    $types .= "i";
                }

                $where_clause = "";
                if (!empty($where)) {
                    $where_clause = " WHERE " . implode(" AND ", $where);
                }

                $stmt = $conn->prepare($sql . $where_clause . " ORDER BY i.item_code LIMIT ? OFFSET ?");
                $count_stmt = $conn->prepare($count_sql . $where_clause);

                $bind_params = array_merge($params, [$per_page, $offset]);
                $types_final = $types . "ii";
                if (!empty($bind_params)) {
                    $stmt->bind_param($types_final, ...$bind_params);
                    $count_stmt->bind_param($types, ...$params);
                } else {
                    $stmt->bind_param("ii", $per_page, $offset);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                break;

            case 'get_name_suggestions':
                $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
                $sql = "SELECT suggestion_id AS id, suggested_name AS text FROM item_name_suggestions";
                $count_sql = "SELECT COUNT(*) AS total FROM item_name_suggestions";
                $where = [];
                $params = [];
                $types = "";
                if ($department_id > 0) {
                    $where[] = "department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                if (!empty($search)) {
                    $where[] = "suggested_name LIKE ?";
                    $like = "%$search%";
                    $params[] = $like;
                    $types .= "s";
                }
                $where_clause = empty($where) ? "" : " WHERE " . implode(" AND ", $where);
                $stmt = $conn->prepare($sql . $where_clause . " ORDER BY suggested_name LIMIT ? OFFSET ?");
                $count_stmt = $conn->prepare($count_sql . $where_clause);
                $bind_params = array_merge($params, [$per_page, $offset]);
                $types_final = $types . "ii";
                if (!empty($bind_params)) {
                    $stmt->bind_param($types_final, ...$bind_params);
                    $count_stmt->bind_param($types, ...$params);
                } else {
                    $stmt->bind_param("ii", $per_page, $offset);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                break;

            case 'get_departments':
            case 'get_subdepartments':
            case 'get_categories':
            case 'get_colors':
            case 'get_sizes':
                $table_map = [
                    'get_departments' => ['departments', 'department_id', 'department_name'],
                    'get_subdepartments' => ['sub_departments', 'sub_department_id', 'sub_department_name'],
                    'get_categories' => ['categories', 'category_id', 'category_name'],
                    'get_colors' => ['colors', 'color_id', 'color_name'],
                    'get_sizes' => ['sizes', 'size_id', 'size_name']
                ];
                list($table, $id_col, $name_col) = $table_map[$action];
                $sql = "SELECT $id_col AS id, $name_col AS text FROM $table";
                $count_sql = "SELECT COUNT(*) AS total FROM $table";
                $where = "";
                if (!empty($search)) {
                    $where = " WHERE $name_col LIKE ?";
                    $like = "%$search%";
                }
                $stmt = $conn->prepare($sql . $where . " ORDER BY $name_col LIMIT ? OFFSET ?");
                $count_stmt = $conn->prepare($count_sql . $where);
                if (!empty($search)) {
                    $stmt->bind_param("sii", $like, $per_page, $offset);
                    $count_stmt->bind_param("s", $like);
                } else {
                    $stmt->bind_param("ii", $per_page, $offset);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                break;

            default:
                $response = ['results' => [], 'pagination' => ['more' => false]];
        }
    } catch (Exception $e) {
        $response = ['error' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// Handle AJAX save (with supplier sync)
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_new_po') {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        // Verify manager password
        $input_password = isset($_POST['manager_password']) ? trim($_POST['manager_password']) : '';
        if (empty($input_password)) {
            throw new Exception('Manager password is required.');
        }
        $pwd_stmt = $conn->prepare("SELECT password FROM manager_credentials LIMIT 1");
        $pwd_stmt->execute();
        $result = $pwd_stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stored_password = $row['password'];
        } else {
            throw new Exception('Manager credentials not set up.');
        }
        $pwd_stmt->close();
        if ($input_password !== $stored_password) {
            throw new Exception('Invalid manager password.');
        }

        // Get header data
        $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
        $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '';
        $attention = isset($_POST['attention']) ? trim($_POST['attention']) : '';
        $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
        $expected_delivery_date = isset($_POST['expected_delivery_date']) && $_POST['expected_delivery_date'] !== '' ? $_POST['expected_delivery_date'] : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';

        if ($supplier_id <= 0 || empty($purchase_date)) {
            throw new Exception('Supplier and Purchase Date are required.');
        }

        // Ensure supplier exists in po_system
        if (!ensureSupplierExists($supplier_id, $conn, $qcConn)) {
            throw new Exception('Selected supplier does not exist in QC database or could not be copied.');
        }

        // PO number generation
        $date_prefix = date('Ymd');
        $prefix = "PO-{$date_prefix}-";
        $seq_sql = "SELECT MAX(CAST(SUBSTRING(po_number, LENGTH(?) + 1) AS UNSIGNED)) AS max_seq 
                    FROM po_header 
                    WHERE po_number LIKE ?";
        $stmt = $conn->prepare($seq_sql);
        $like_pattern = $prefix . '%';
        $stmt->bind_param("ss", $prefix, $like_pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $next_seq = ($row['max_seq'] ?? 0) + 1;
        $po_number = $prefix . str_pad($next_seq, 4, '0', STR_PAD_LEFT);
        $stmt->close();

        $conn->begin_transaction();

        // Insert PO header
        $insert_header = $conn->prepare("INSERT INTO po_header 
            (po_number, supplier_id, purchase_date, attention, remarks, expected_delivery_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_header->bind_param("sisssss", $po_number, $supplier_id, $purchase_date, $attention, $remarks, $expected_delivery_date, $status);
        if (!$insert_header->execute()) {
            throw new Exception("Header insert failed: " . $insert_header->error);
        }
        $po_id = $insert_header->insert_id;
        $insert_header->close();

        // Status log
        $log_sql = "INSERT INTO po_status_log (po_id, status, remarks) VALUES (?, ?, ?)";
        $log_stmt = $conn->prepare($log_sql);
        $log_status = 'Ordered';
        $log_remarks = 'PO created via system';
        $log_stmt->bind_param("iss", $po_id, $log_status, $log_remarks);
        if (!$log_stmt->execute()) {
            throw new Exception("Status log insert failed: " . $log_stmt->error);
        }
        $log_stmt->close();

        // Process items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $insert_po_item = $conn->prepare("INSERT INTO po_items 
                (po_id, item_id, quantity, cost_price, selling_price, received_qty) 
                VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($_POST['items'] as $item) {
                $system_code   = trim($item['system_code'] ?? '');
                $item_code     = trim($item['item_code'] ?? '');
                $item_name     = trim($item['item_name'] ?? '');
                
                // Foreign keys – set to NULL if empty or 0
                $department_id = isset($item['department_id']) && $item['department_id'] > 0 ? (int)$item['department_id'] : null;
                $sub_department_id = isset($item['sub_department_id']) && $item['sub_department_id'] > 0 ? (int)$item['sub_department_id'] : null;
                $category_id = isset($item['category_id']) && $item['category_id'] > 0 ? (int)$item['category_id'] : null;
                $color_id = isset($item['color_id']) && $item['color_id'] > 0 ? (int)$item['color_id'] : null;
                $size_id = isset($item['size_id']) && $item['size_id'] > 0 ? (int)$item['size_id'] : null;
                
                // Cost & selling – default to 0 if not set
                $cost = (float)($item['cost'] ?? 0);
                $sell = (float)($item['selling'] ?? 0);
                $recv_qty = (int)($item['received_qty'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);

                if ($item_code === '' || $item_name === '' || $qty <= 0) continue;

                // Master item – create or update
                $find_item = $conn->prepare("SELECT item_id FROM items WHERE item_code = ?");
                $find_item->bind_param("s", $item_code);
                $find_item->execute();
                $res = $find_item->get_result();
                if ($row = $res->fetch_assoc()) {
                    $item_id = $row['item_id'];
                    $update_item = $conn->prepare("UPDATE items SET 
                        system_code = ?, item_name = ?, department_id = ?, sub_department_id = ?, 
                        category_id = ?, color_id = ?, size_id = ?, cost_price = ?, selling_price = ? 
                        WHERE item_id = ?");
                    $update_item->bind_param("ssiiiiiddi", 
                        $system_code, $item_name, $department_id, $sub_department_id, 
                        $category_id, $color_id, $size_id, $cost, $sell, $item_id);
                    if (!$update_item->execute()) {
                        throw new Exception("Item update failed: " . $update_item->error);
                    }
                    $update_item->close();
                } else {
                    $insert_item = $conn->prepare("INSERT INTO items 
                        (system_code, item_code, item_name, department_id, sub_department_id, 
                         category_id, color_id, size_id, cost_price, selling_price) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    // Bind with NULL allowed – all parameters are passed as strings (NULL is sent as null)
                    $insert_item->bind_param("sssiiiiidd", 
                        $system_code, $item_code, $item_name, $department_id, $sub_department_id, 
                        $category_id, $color_id, $size_id, $cost, $sell);
                    if (!$insert_item->execute()) {
                        throw new Exception("Item insert failed: " . $insert_item->error);
                    }
                    $item_id = $insert_item->insert_id;
                    $insert_item->close();
                }
                $find_item->close();

                // Insert PO item – using same cost & selling
                $insert_po_item->bind_param("iiiddi", $po_id, $item_id, $qty, $cost, $sell, $recv_qty);
                if (!$insert_po_item->execute()) {
                    throw new Exception("PO item insert failed: " . $insert_po_item->error);
                }
            }
            $insert_po_item->close();
        }

        $conn->commit();
        $response['success'] = true;
        $response['message'] = "PO created successfully. PO Number: {$po_number}";
        $response['po_id'] = $po_id;
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Creation failed: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// HTML & JS (full front‑end)
// ------------------------------------------------------------
include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
    /* ─── Styles ─── */
    body { background-color: #fbfbfb; font-family: 'Segoe UI', Arial, sans-serif; color: #333; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; margin-bottom: 25px; }
    .asb-card { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 25px; }
    .asb-card-header { background: #fff; border-bottom: 2px solid #eaeaea; padding: 15px 20px; color: #b71c1c; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
    .btn-asb { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .btn-asb:hover { background: #b71c1c; color: #fff; }
    .btn-asb-secondary { background: #f5f5f5; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; text-decoration: none; }
    .btn-asb-secondary:hover { background: #e0e0e0; }
    .asb-input { border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; width: 100%; font-size: 12px; box-sizing: border-box; }
    .asb-input:focus { border-color: #d32f2f; outline: none; box-shadow: 0 0 4px rgba(211,47,47,0.2); }
    .asb-footer { text-align: center; margin-top: 40px; padding: 15px; color: #777; border-top: 1px solid #eee; font-size: 12px; }
    .asb-footer strong { color: #b71c1c; }
    .modal-mask { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:30px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .modal-content h4 { margin-top:0; color:#b71c1c; }
    .modal-content .form-group { margin-bottom:15px; }
    .modal-content .form-group label { display:block; font-weight:600; font-size:12px; margin-bottom:5px; }
    .modal-content .form-group input { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; }
    .modal-content .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }
    .edit-form .form-row { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
    .edit-form .form-group { flex: 1; min-width: 150px; }
    .edit-form .form-group label { display: block; font-weight: 600; font-size: 11px; color: #555; margin-bottom: 4px; }

    .item-table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        font-size: 13px;
        background: #fff;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .item-table thead { position: sticky; top: 0; z-index: 10; }
    .item-table th {
        background: #f1f3f5 !important;
        color: #333 !important;
        font-weight: 700 !important;
        font-size: 9px !important;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        padding: 6px 4px !important;
        border-bottom: 2px solid #d32f2f !important;
        white-space: nowrap;
        text-align: left;
    }
    .item-table td { padding: 4px 2px !important; vertical-align: middle; border-bottom: 1px solid #e9ecef; }
    .item-table tbody tr:hover { background-color: #f8f9fa; }
    .item-table tbody tr:nth-child(even) { background-color: #fcfcfc; }
    .item-table input, .item-table select {
        padding: 4px 6px !important;
        font-size: 11px !important;
        border: 1px solid #ced4da;
        border-radius: 4px;
        background: #fff;
        width: 100%;
        box-sizing: border-box;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .item-table input:focus, .item-table select:focus {
        border-color: #d32f2f;
        outline: 0;
        box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.15);
    }
    .item-table input::placeholder { color: #aaa; font-style: italic; }
    .item-table .action-col { text-align: center; width: 40px; min-width: 40px; }
    .item-table .remove-row {
        background: #dc3545;
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 24px;
        height: 24px;
        line-height: 24px;
        text-align: center;
        font-size: 11px;
        cursor: pointer;
        transition: background 0.2s;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .item-table .remove-row:hover { background: #c82333; }
    .add-row-btn {
        margin-top: 10px;
        background: #28a745;
        color: #fff;
        border: none;
        padding: 6px 14px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 12px;
        cursor: pointer;
        transition: background 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .add-row-btn:hover { background: #218838; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    .item-table .col-system { min-width: 70px; }
    .item-table .col-code { min-width: 90px; }
    .item-table .col-name { min-width: 120px; }
    .item-table .col-dept, .item-table .col-subdept, .item-table .col-cat, .item-table .col-color, .item-table .col-size { min-width: 70px; }
    .item-table .col-cost, .item-table .col-sell { min-width: 70px; }
    .item-table .col-qty { min-width: 55px; }

    #itemsFooter td {
        border-top: 2px solid #d32f2f;
        background: #f1f3f5;
        font-weight: 700;
        font-size: 12px;
        padding: 6px 4px !important;
        text-align: center;
    }
    #itemsFooter td:first-child { text-align: right; }

    .select2-container .select2-selection--single { height: 32px; border-color: #ccc; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 32px; padding-left: 8px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Create New Purchase Order</span></h2>

    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-plus-circle"></i> New Purchase Order</span>
            <span style="font-size:11px; color:#888;">All fields marked * are required</span>
        </div>
        <div style="padding: 20px;">
            <form id="createPoForm" class="edit-form">
                <!-- Header -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="supplier_id">Supplier *</label>
                        <select name="supplier_id" id="supplier_id" class="asb-input select2-ajax" style="width:100%;" required>
                            <option value="">-- Search Supplier --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="purchase_date">Purchase Date *</label>
                        <input type="date" name="purchase_date" id="purchase_date" class="asb-input" value="<?= date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="expected_delivery_date">Expected Delivery</label>
                        <input type="date" name="expected_delivery_date" id="expected_delivery_date" class="asb-input">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="asb-input">
                            <option value="Pending" selected>Pending</option>
                            <option value="Received">Received</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="attention">Attention</label>
                        <input type="text" name="attention" id="attention" class="asb-input" placeholder="Person or department">
                    </div>
                    <div class="form-group">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks" id="remarks" class="asb-input" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </div>

                <!-- Items -->
                <div style="margin-top: 30px;">
                    <h5 style="color:#b71c1c; font-weight:bold; margin-bottom:15px;">
                        <i class="fas fa-list"></i> Line Items
                        <span style="font-size:12px; font-weight:normal; color:#888; margin-left:10px;">
                            <i class="fas fa-info-circle" title="Enter quantity for each item"></i>
                        </span>
                    </h5>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="item-table" id="itemsTable">
                            <thead>
                                <tr>
                                    <th class="col-system">System<br>Code</th>
                                    <th class="col-code">Item Code *</th>
                                    <th class="col-name">Item Name *</th>
                                    <th class="col-dept">Dept</th>
                                    <th class="col-subdept">Sub Dept</th>
                                    <th class="col-cat">Category</th>
                                    <th class="col-color">Color</th>
                                    <th class="col-size">Size</th>
                                    <th class="col-cost">Cost</th>
                                    <th class="col-sell">Selling</th>
                                    <th class="col-qty">Qty *</th>
                                    <th class="action-col">Action</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <!-- Rows added by JS -->
                            </tbody>
                            <tfoot id="itemsFooter">
                                <tr>
                                    <td colspan="10" style="text-align:right; font-weight:700;">Grand Total Qty</td>
                                    <td id="totalQty">0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <button type="button" id="addRowBtn" class="add-row-btn"><i class="fas fa-plus"></i> Add Item</button>
                </div>

                <!-- Save -->
                <div style="margin-top: 25px; text-align: right;">
                    <a href="view_pos.php" class="btn-asb-secondary">Cancel</a>
                    <button type="button" id="savePoBtn" class="btn-asb" style="padding: 10px 24px;"><i class="fas fa-save"></i> Create PO</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Modal -->
    <div id="passwordModal" class="modal-mask">
        <div class="modal-content">
            <h4><i class="fas fa-lock"></i> Manager Authorization</h4>
            <p style="font-size:12px; color:#666;">Enter the manager password to confirm creation.</p>
            <div class="form-group">
                <label for="modalPassword">Password</label>
                <input type="password" id="modalPassword" class="asb-input" placeholder="Enter manager password">
            </div>
            <div id="modalError" style="color:#d32f2f; font-size:12px; margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn-asb-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-asb" onclick="submitSave()">Confirm Create</button>
            </div>
        </div>
    </div>

    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:4px; display:inline-block; color:#aaa;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>
</div>

<script>
$(document).ready(function() {

    // ─── AJAX Select2 helpers ───
    function ajaxSelect2(url, placeholder, extraData) {
        return {
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    var data = {
                        ajax_action: url,
                        search: params.term || '',
                        page: params.page || 1
                    };
                    if (extraData) {
                        $.extend(data, extraData);
                    }
                    return data;
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data.results,
                        pagination: { more: data.pagination.more }
                    };
                },
                cache: true
            },
            placeholder: placeholder,
            minimumInputLength: 0,
            allowClear: true,
            templateResult: function(item) { return item.loading ? item.text : item.text; },
            templateSelection: function(item) { return item.text || placeholder; }
        };
    }

    // ─── Supplier ───
    var $supplier = $('#supplier_id');
    $supplier.select2(ajaxSelect2('get_suppliers', 'Search supplier...'));

    // ─── Item row management ───
    var rowIndex = 0;

    function createItemRow(index, data) {
        data = data || {};
        var html = `
            <tr class="item-row" data-row-index="${index}">
                <td><input type="text" name="items[${index}][system_code]" value="${data.system_code || ''}" placeholder="SysCode" class="asb-input"></td>
                <td>
                    <input type="text" name="items[${index}][item_code]" class="item_code_input asb-input" value="${data.item_code || ''}" placeholder="Item Code" required>
                    <select name="items[${index}][item_id]" class="item-search" style="width:100%; margin-top:4px;">
                        <option value="">-- Search & Auto‑fill --</option>
                        ${data.item_id ? `<option value="${data.item_id}" selected>${data.item_code} - ${data.item_name}</option>` : ''}
                    </select>
                </td>
                <td>
                    <input type="text" name="items[${index}][item_name]" class="item_name_input asb-input" value="${data.item_name || ''}" placeholder="Item Name" required>
                    <select name="items[${index}][suggestion]" class="name-suggestion" style="width:100%; margin-top:4px;">
                        <option value="">-- Suggested Names --</option>
                    </select>
                </td>
                <td>
                    <select name="items[${index}][department_id]" class="dept-select" style="width:100%;">
                        <option value="">--</option>
                        ${data.department_id ? `<option value="${data.department_id}" selected>${data.department_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][sub_department_id]" class="subdept-select" style="width:100%;">
                        <option value="">--</option>
                        ${data.sub_department_id ? `<option value="${data.sub_department_id}" selected>${data.sub_department_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][category_id]" class="cat-select" style="width:100%;">
                        <option value="">--</option>
                        ${data.category_id ? `<option value="${data.category_id}" selected>${data.category_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][color_id]" class="color-select" style="width:100%;">
                        <option value="">--</option>
                        ${data.color_id ? `<option value="${data.color_id}" selected>${data.color_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][size_id]" class="size-select" style="width:100%;">
                        <option value="">--</option>
                        ${data.size_id ? `<option value="${data.size_id}" selected>${data.size_name || ''}</option>` : ''}
                    </select>
                </td>
                <td><input type="number" step="0.01" name="items[${index}][cost]" value="${data.cost || 0}" placeholder="0.00" class="asb-input"></td>
                <td><input type="number" step="0.01" name="items[${index}][selling]" value="${data.selling || 0}" placeholder="0.00" class="asb-input"></td>
                <td><input type="number" name="items[${index}][quantity]" class="qty-input asb-input" min="0" value="${data.quantity || 0}" placeholder="0" required></td>
                <td class="action-col">
                    <button type="button" class="remove-row" title="Remove"><i class="fas fa-times"></i></button>
                </td>
            </tr>
        `;
        return html;
    }

    // ─── Grand total update ───
    function updateGrandTotal() {
        let total = 0;
        $('.qty-input').each(function() {
            total += parseInt($(this).val()) || 0;
        });
        $('#totalQty').text(total);
    }

    // ─── Keyboard Navigation helpers ───
    // 1. Highlight first result in item search dropdown
    $(document).on('select2:open', '.item-search', function(e) {
        var $select = $(this);
        setTimeout(function() {
            var $dropdown = $select.data('select2').dropdown.$dropdown;
            var $firstResult = $dropdown.find('.select2-results__option:not(.select2-results__option--disabled)');
            if ($firstResult.length) {
                $firstResult.attr('aria-selected', 'true');
                $firstResult.addClass('select2-results__option--highlighted');
                $dropdown.find('.select2-results__option--highlighted').not($firstResult).removeClass('select2-results__option--highlighted').attr('aria-selected', 'false');
            }
        }, 100);
    });

    // 2. After selecting an item, move focus to quantity field
    $(document).on('select2:select', '.item-search', function(e) {
        var $row = $(this).closest('tr');
        var $qty = $row.find('.qty-input');
        if ($qty.length) {
            setTimeout(function() {
                $qty.focus().select();
            }, 200);
        }
    });

    // 3. Enter key navigation within rows
    function setupEnterNavigation($row) {
        var $allInputs = $row.find('input:not(.select2-search__field), select:not(.item-search)');
        $allInputs.each(function() {
            var $input = $(this);
            $input.off('keydown.enterNav').on('keydown.enterNav', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault(); // prevent form submit
                    var $nextInput = null;
                    // If current is quantity, move to next row's item search
                    if ($(this).hasClass('qty-input')) {
                        var $nextRow = $row.next('tr');
                        if ($nextRow.length) {
                            $nextInput = $nextRow.find('.item-search');
                        } else {
                            // Add new row and focus its item search
                            $('#addRowBtn').click();
                            var $newRow = $('#itemsBody tr:last');
                            $nextInput = $newRow.find('.item-search');
                        }
                    } else {
                        // Find next input in the same row (excluding item-search)
                        var $allRowInputs = $row.find('input:not(.select2-search__field), select:not(.item-search)');
                        var currentIdx = $allRowInputs.index(this);
                        if (currentIdx < $allRowInputs.length - 1) {
                            $nextInput = $allRowInputs.eq(currentIdx + 1);
                        } else {
                            // Last input -> move to quantity
                            $nextInput = $row.find('.qty-input');
                        }
                    }
                    if ($nextInput && $nextInput.length) {
                        setTimeout(function() {
                            $nextInput.focus().select();
                        }, 50);
                    }
                }
            });
        });
    }

    function applyEnterNavigationToRow($row) {
        setupEnterNavigation($row);
    }

    // ─── Initialize Select2 for a row ───
    function initRowSelects($row) {
        var $deptSelect = $row.find('.dept-select');
        var $itemSelect = $row.find('.item-search');
        var $suggestionSelect = $row.find('.name-suggestion');

        // Get current supplier ID
        function getSupplierId() {
            return $supplier.val() || '';
        }

        // Item search (from items table, filtered by supplier and dept)
        $itemSelect.select2({
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    var data = {
                        ajax_action: 'get_items',
                        search: params.term || '',
                        page: params.page || 1
                    };
                    var deptId = $deptSelect.val();
                    if (deptId) data.department_id = deptId;
                    var supId = getSupplierId();
                    if (supId) data.supplier_id = supId;
                    return data;
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data.results,
                        pagination: { more: data.pagination.more }
                    };
                },
                cache: true
            },
            placeholder: 'Search item...',
            minimumInputLength: 0,
            allowClear: true,
            templateResult: function(item) { return item.loading ? item.text : item.text; },
            templateSelection: function(item) { return item.text || 'Search item...'; }
        }).on('select2:select', function(e) {
            var data = e.params.data;
            var $row = $(this).closest('tr');
            $row.find('input[name*="[system_code]"]').val(data.system_code || '');
            $row.find('.item_code_input').val(data.item_code || '');
            $row.find('.item_name_input').val(data.item_name || '');
            setSelectValue($row.find('.dept-select'), data.department_id, data.department_name);
            setSelectValue($row.find('.subdept-select'), data.sub_department_id, data.sub_department_name);
            setSelectValue($row.find('.cat-select'), data.category_id, data.category_name);
            setSelectValue($row.find('.color-select'), data.color_id, data.color_name);
            setSelectValue($row.find('.size-select'), data.size_id, data.size_name);
            // Fill combined cost & selling from master values
            $row.find('input[name*="[cost]"]').val(data.master_cost_price || 0);
            $row.find('input[name*="[selling]"]').val(data.master_selling_price || 0);
        });

        // When supplier changes globally, reset item search for all rows
        $supplier.on('change', function() {
            $('.item-search').each(function() {
                $(this).val(null).trigger('change');
            });
        });

        // Name suggestion (from item_name_suggestions, filtered by department)
        function refreshSuggestions() {
            var deptId = $deptSelect.val() || '';
            $suggestionSelect.select2({
                ajax: {
                    url: window.location.href,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            ajax_action: 'get_name_suggestions',
                            search: params.term || '',
                            page: params.page || 1,
                            department_id: deptId
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results,
                            pagination: { more: data.pagination.more }
                        };
                    },
                    cache: true
                },
                placeholder: 'Search suggestions...',
                minimumInputLength: 0,
                allowClear: true,
                templateResult: function(item) { return item.loading ? item.text : item.text; },
                templateSelection: function(item) { return item.text || 'Suggestions...'; }
            }).on('select2:select', function(e) {
                var data = e.params.data;
                var $row = $(this).closest('tr');
                $row.find('.item_name_input').val(data.text);
            });
            $suggestionSelect.val(null).trigger('change');
        }

        // When department changes, refresh suggestions and item search
        $deptSelect.on('change', function() {
            refreshSuggestions();
            $itemSelect.val(null).trigger('change');
        });

        // Initial suggestion load
        refreshSuggestions();

        // Other selects
        $row.find('.dept-select').select2(ajaxSelect2('get_departments', 'Search dept...'));
        $row.find('.subdept-select').select2(ajaxSelect2('get_subdepartments', 'Search sub dept...'));
        $row.find('.cat-select').select2(ajaxSelect2('get_categories', 'Search category...'));
        $row.find('.color-select').select2(ajaxSelect2('get_colors', 'Search color...'));
        $row.find('.size-select').select2(ajaxSelect2('get_sizes', 'Search size...'));

        // Apply Enter navigation to this row
        applyEnterNavigationToRow($row);
    }

    function setSelectValue($select, value, text) {
        if (value) {
            var option = new Option(text, value, true, true);
            $select.append(option).trigger('change');
            $select.val(value).trigger('change');
        } else {
            $select.val(null).trigger('change');
        }
    }

    // Add first row
    $('#itemsBody').append(createItemRow(rowIndex));
    initRowSelects($('#itemsBody tr:first'));
    rowIndex++;

    // Add row button – enhanced with focus
    $('#addRowBtn').on('click', function() {
        var newRowHtml = createItemRow(rowIndex);
        var $newRow = $(newRowHtml);
        $('#itemsBody').append($newRow);
        initRowSelects($newRow);
        rowIndex++;
        updateGrandTotal();
        // Focus the item search of new row
        setTimeout(function() {
            $newRow.find('.item-search').focus();
        }, 300);
    });

    // ─── Qty input triggers grand total ───
    $(document).on('input', '.qty-input', function() {
        updateGrandTotal();
    });

    // ─── Remove row ───
    $(document).on('click', '.remove-row', function() {
        var $row = $(this).closest('tr');
        if ($('#itemsBody tr').length > 1) {
            $row.remove();
            updateGrandTotal();
        } else {
            alert('You must keep at least one item row.');
        }
    });

    // ─── Save button triggers modal ───
    $('#savePoBtn').on('click', function() {
        var supplier = $supplier.val();
        var date = $('#purchase_date').val();
        if (!supplier || !date) {
            alert('Please fill in Supplier and Purchase Date.');
            return;
        }
        var valid = false;
        $('#itemsBody tr').each(function() {
            var $row = $(this);
            var code = $row.find('.item_code_input').val();
            var name = $row.find('.item_name_input').val();
            var qty = parseInt($row.find('.qty-input').val()) || 0;
            if (code && name && qty > 0) valid = true;
        });
        if (!valid) {
            alert('Please add at least one item with a code, name, and positive quantity.');
            return;
        }

        $('#passwordModal').css('display', 'flex');
        $('#modalPassword').val('');
        $('#modalError').text('');
    });

    // ─── Modal functions ───
    window.closeModal = function() {
        $('#passwordModal').css('display', 'none');
    };

    window.submitSave = function() {
        var password = $('#modalPassword').val().trim();
        if (!password) {
            $('#modalError').text('Please enter the manager password.');
            return;
        }

        var form = document.getElementById('createPoForm');
        var formData = new FormData(form);
        formData.append('ajax_action', 'save_new_po');
        formData.append('manager_password', password);

        var items = [];
        $('#itemsBody tr').each(function(idx) {
            var $row = $(this);
            var code = $row.find('.item_code_input').val();
            var name = $row.find('.item_name_input').val();
            if (!code || !name) return;
            var qty = parseInt($row.find('.qty-input').val()) || 0;
            if (qty <= 0) return;

            items.push({
                system_code: $row.find('input[name*="[system_code]"]').val(),
                item_code: code,
                item_name: name,
                department_id: $row.find('.dept-select').val(),
                sub_department_id: $row.find('.subdept-select').val(),
                category_id: $row.find('.cat-select').val(),
                color_id: $row.find('.color-select').val(),
                size_id: $row.find('.size-select').val(),
                cost: parseFloat($row.find('input[name*="[cost]"]').val()) || 0,
                selling: parseFloat($row.find('input[name*="[selling]"]').val()) || 0,
                quantity: qty,
                received_qty: 0
            });
        });

        formData.delete('items');
        items.forEach(function(item, idx) {
            formData.append(`items[${idx}][system_code]`, item.system_code);
            formData.append(`items[${idx}][item_code]`, item.item_code);
            formData.append(`items[${idx}][item_name]`, item.item_name);
            formData.append(`items[${idx}][department_id]`, item.department_id);
            formData.append(`items[${idx}][sub_department_id]`, item.sub_department_id);
            formData.append(`items[${idx}][category_id]`, item.category_id);
            formData.append(`items[${idx}][color_id]`, item.color_id);
            formData.append(`items[${idx}][size_id]`, item.size_id);
            formData.append(`items[${idx}][cost]`, item.cost);
            formData.append(`items[${idx}][selling]`, item.selling);
            formData.append(`items[${idx}][quantity]`, item.quantity);
            formData.append(`items[${idx}][received_qty]`, item.received_qty);
        });

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.href = 'view_pos.php';
            } else {
                $('#modalError').text(data.message);
            }
        })
        .catch(error => {
            $('#modalError').text('An error occurred: ' + error);
        });
    };

    $('#passwordModal').on('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Initial grand total
    updateGrandTotal();
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>