<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$page_title = 'ASB Fashion | Purchase Order Editor';
$page = 'po_ledger';

$conn = getConnection();      // po_system
$qcConn = getQcConnection();  // return_qc

// ------------------------------------------------------------
// Ensure suppliers table exists in po_system – with all columns
// ------------------------------------------------------------
function ensureSuppliersTableExists($conn, $qcConn) {
    $check = $conn->query("SHOW TABLES LIKE 'suppliers'");
    if ($check->num_rows > 0) {
        // Table exists – ensure it has at least supplier_id and supplier_name
        $colCheck = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'supplier_id'");
        if ($colCheck->num_rows == 0) {
            throw new Exception("suppliers table missing supplier_id column");
        }
        $colCheck = $conn->query("SHOW COLUMNS FROM suppliers LIKE 'supplier_name'");
        if ($colCheck->num_rows == 0) {
            throw new Exception("suppliers table missing supplier_name column");
        }
        return true;
    }

    // Create table with all columns we might need (matching return_qc.suppliers)
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
        KEY idx_supplier_name (supplier_name),
        KEY idx_contact_person (contact_person),
        KEY idx_contact_number (contact_number),
        KEY idx_status (status),
        KEY idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($createSQL)) {
        throw new Exception("Failed to create suppliers table: " . $conn->error);
    }

    // Copy all data from return_qc.suppliers
    $copySQL = "INSERT INTO suppliers 
                (supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status)
                SELECT supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status
                FROM return_qc.suppliers";
    if (!$conn->query($copySQL)) {
        throw new Exception("Failed to copy suppliers: " . $conn->error);
    }
    return true;
}

try {
    ensureSuppliersTableExists($conn, $qcConn);
} catch (Exception $e) {
    die("Fatal error: " . $e->getMessage());
}

// Helper: ensure a specific supplier exists in po_system
function ensureSupplierExists($supplier_id, $conn, $qcConn) {
    $check = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = ?");
    $check->bind_param("i", $supplier_id);
    $check->execute();
    $result = $check->get_result();
    if ($result->num_rows > 0) {
        $check->close();
        return true;
    }
    $check->close();

    $fetch = $qcConn->prepare("SELECT supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status FROM suppliers WHERE supplier_id = ?");
    $fetch->bind_param("i", $supplier_id);
    $fetch->execute();
    $res = $fetch->get_result();
    if ($row = $res->fetch_assoc()) {
        $insert = $conn->prepare("INSERT INTO suppliers 
            (supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, contact_person, whatsapp, email, address, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("issssssssss", 
            $row['supplier_id'], $row['supplier_name'], $row['system_id'], $row['contact_number'],
            $row['land_number'], $row['fax_number'], $row['contact_person'], $row['whatsapp'],
            $row['email'], $row['address'], $row['status']
        );
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
// AJAX endpoints
// ------------------------------------------------------------
if (isset($_GET['ajax_action'])) {
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
            // ---------- Select2 endpoints ----------
            case 'get_suppliers':
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
                $sql = "SELECT 
                            s.suggestion_id AS id, 
                            s.suggested_name AS text,
                            s.department_id,
                            s.sub_department_id,
                            d.department_name,
                            sd.sub_department_name
                        FROM item_name_suggestions s
                        LEFT JOIN departments d ON s.department_id = d.department_id
                        LEFT JOIN sub_departments sd ON s.sub_department_id = sd.sub_department_id";
                $count_sql = "SELECT COUNT(*) AS total FROM item_name_suggestions s";
                $where = [];
                $params = [];
                $types = "";
                if ($department_id > 0) {
                    $where[] = "s.department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                if (!empty($search)) {
                    $where[] = "s.suggested_name LIKE ?";
                    $like = "%$search%";
                    $params[] = $like;
                    $types .= "s";
                }
                $where_clause = empty($where) ? "" : " WHERE " . implode(" AND ", $where);
                $stmt = $conn->prepare($sql . $where_clause . " ORDER BY s.suggested_name LIMIT ? OFFSET ?");
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

            // ---------- PO list with optimized query ----------
            case 'get_pos':
                $from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
                $to_date   = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
                $supplier_filter = isset($_GET['supplier']) ? (int)$_GET['supplier'] : 0;
                $search_po = isset($_GET['search']) ? trim($_GET['search']) : '';

                $where = [];
                $params = [];
                $types = "";

                if (!empty($from_date)) {
                    $where[] = "h.purchase_date >= ?";
                    $params[] = $from_date;
                    $types .= "s";
                }
                if (!empty($to_date)) {
                    $where[] = "h.purchase_date <= ?";
                    $params[] = $to_date;
                    $types .= "s";
                }
                if ($supplier_filter > 0) {
                    $where[] = "h.supplier_id = ?";
                    $params[] = $supplier_filter;
                    $types .= "i";
                }
                if (!empty($search_po)) {
                    $where[] = "(h.po_number LIKE ? OR s.supplier_name LIKE ?)";
                    $like = "%$search_po%";
                    $params[] = $like;
                    $params[] = $like;
                    $types .= "ss";
                }

                $where_clause = empty($where) ? "" : " WHERE " . implode(" AND ", $where);

                $sql = "SELECT h.po_id, h.po_number, h.purchase_date, h.expected_delivery_date, h.status,
                               s.supplier_name,
                               COUNT(pi.po_item_id) AS total_items,
                               COALESCE(SUM(pi.quantity), 0) AS total_qty
                        FROM po_header h
                        LEFT JOIN suppliers s ON h.supplier_id = s.supplier_id
                        LEFT JOIN po_items pi ON h.po_id = pi.po_id
                        $where_clause
                        GROUP BY h.po_id, h.po_number, h.purchase_date, h.expected_delivery_date, h.status, s.supplier_name
                        ORDER BY h.purchase_date DESC, h.po_id DESC
                        LIMIT ? OFFSET ?";

                $count_sql = "SELECT COUNT(DISTINCT h.po_id) AS total
                              FROM po_header h
                              LEFT JOIN suppliers s ON h.supplier_id = s.supplier_id
                              $where_clause";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed for PO list: " . $conn->error);
                }
                $count_stmt = $conn->prepare($count_sql);
                if (!$count_stmt) {
                    throw new Exception("Prepare failed for count: " . $conn->error);
                }

                $bind_params = array_merge($params, [$per_page, $offset]);
                $types_final = $types . "ii";
                if (!empty($bind_params)) {
                    $stmt->bind_param($types_final, ...$bind_params);
                    $count_stmt->bind_param($types, ...$params);
                } else {
                    $stmt->bind_param("ii", $per_page, $offset);
                }

                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);

                if (!$count_stmt->execute()) {
                    throw new Exception("Count execute failed: " . $count_stmt->error);
                }
                $total = $count_stmt->get_result()->fetch_assoc()['total'];
                $response['pagination']['more'] = ($offset + $per_page) < $total;

                $stmt->close();
                $count_stmt->close();
                break;

            case 'load_po':
                $po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
                if (!$po_id) throw new Exception('Invalid PO ID');

                $header_sql = "SELECT h.*, s.supplier_name
                               FROM po_header h
                               LEFT JOIN suppliers s ON h.supplier_id = s.supplier_id
                               WHERE h.po_id = ?";
                $stmt = $conn->prepare($header_sql);
                if (!$stmt) throw new Exception("Prepare header failed: " . $conn->error);
                $stmt->bind_param("i", $po_id);
                if (!$stmt->execute()) throw new Exception("Execute header failed: " . $stmt->error);
                $header = $stmt->get_result()->fetch_assoc();
                if (!$header) throw new Exception('PO not found');
                $stmt->close();

                $item_sql = "SELECT pi.*, i.system_code, i.item_code, i.item_name, 
                                    i.department_id, i.sub_department_id, i.category_id, i.color_id, i.size_id,
                                    d.department_name, sd.sub_department_name, c.category_name, col.color_name, sz.size_name
                             FROM po_items pi
                             JOIN items i ON pi.item_id = i.item_id
                             LEFT JOIN departments d ON i.department_id = d.department_id
                             LEFT JOIN sub_departments sd ON i.sub_department_id = sd.sub_department_id
                             LEFT JOIN categories c ON i.category_id = c.category_id
                             LEFT JOIN colors col ON i.color_id = col.color_id
                             LEFT JOIN sizes sz ON i.size_id = sz.size_id
                             WHERE pi.po_id = ?";
                $stmt = $conn->prepare($item_sql);
                if (!$stmt) throw new Exception("Prepare items failed: " . $conn->error);
                $stmt->bind_param("i", $po_id);
                if (!$stmt->execute()) throw new Exception("Execute items failed: " . $stmt->error);
                $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $response = [
                    'header' => $header,
                    'items'  => $items
                ];
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
// Handle AJAX save
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_po') {
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
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

        $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
        $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
        $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '';
        $attention = isset($_POST['attention']) ? trim($_POST['attention']) : '';
        $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
        $expected_delivery_date = isset($_POST['expected_delivery_date']) && $_POST['expected_delivery_date'] !== '' ? $_POST['expected_delivery_date'] : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';

        if ($supplier_id <= 0 || empty($purchase_date)) {
            throw new Exception('Supplier and Purchase Date are required.');
        }

        if (!ensureSupplierExists($supplier_id, $conn, $qcConn)) {
            throw new Exception('Selected supplier does not exist in QC database or could not be copied.');
        }

        $conn->begin_transaction();

        if ($po_id > 0) {
            // Update
            $update_header = $conn->prepare("UPDATE po_header SET 
                supplier_id = ?, purchase_date = ?, attention = ?, remarks = ?, expected_delivery_date = ?, status = ?
                WHERE po_id = ?");
            $update_header->bind_param("isssssi", $supplier_id, $purchase_date, $attention, $remarks, $expected_delivery_date, $status, $po_id);
            if (!$update_header->execute()) {
                throw new Exception("Header update failed: " . $update_header->error);
            }
            $update_header->close();

            // Delete old items
            $del = $conn->prepare("DELETE FROM po_items WHERE po_id = ?");
            $del->bind_param("i", $po_id);
            if (!$del->execute()) {
                throw new Exception("Failed to remove old items: " . $del->error);
            }
            $del->close();

            // Status log
            $log_sql = "INSERT INTO po_status_log (po_id, status, remarks) VALUES (?, ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_status = $status;
            $log_remarks = "PO updated via system";
            $log_stmt->bind_param("iss", $po_id, $log_status, $log_remarks);
            if (!$log_stmt->execute()) {
                throw new Exception("Status log insert failed: " . $log_stmt->error);
            }
            $log_stmt->close();

            $po_number = '';
        } else {
            // Create new
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
        }

        // Insert items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $insert_po_item = $conn->prepare("INSERT INTO po_items 
                (po_id, item_id, quantity, cost_price, selling_price, received_qty) 
                VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($_POST['items'] as $item) {
                $system_code   = trim($item['system_code'] ?? '');
                $item_code     = trim($item['item_code'] ?? '');
                $item_name     = trim($item['item_name'] ?? '');
                $department_id = isset($item['department_id']) && $item['department_id'] > 0 ? (int)$item['department_id'] : null;
                $sub_department_id = isset($item['sub_department_id']) && $item['sub_department_id'] > 0 ? (int)$item['sub_department_id'] : null;
                $category_id = isset($item['category_id']) && $item['category_id'] > 0 ? (int)$item['category_id'] : null;
                $color_id = isset($item['color_id']) && $item['color_id'] > 0 ? (int)$item['color_id'] : null;
                $size_id = isset($item['size_id']) && $item['size_id'] > 0 ? (int)$item['size_id'] : null;
                $cost = (float)($item['cost'] ?? 0);
                $sell = (float)($item['selling'] ?? 0);
                $recv_qty = (int)($item['received_qty'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);

                if ($item_code === '' || $item_name === '' || $qty <= 0) continue;

                // Master item
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

                $insert_po_item->bind_param("iiiddi", $po_id, $item_id, $qty, $cost, $sell, $recv_qty);
                if (!$insert_po_item->execute()) {
                    throw new Exception("PO item insert failed: " . $insert_po_item->error);
                }
            }
            $insert_po_item->close();
        }

        $conn->commit();

        if (empty($po_number)) {
            $fetch_num = $conn->prepare("SELECT po_number FROM po_header WHERE po_id = ?");
            $fetch_num->bind_param("i", $po_id);
            $fetch_num->execute();
            $num_row = $fetch_num->get_result()->fetch_assoc();
            $po_number = $num_row['po_number'] ?? '';
            $fetch_num->close();
        }

        $response['success'] = true;
        $response['message'] = "PO saved successfully. PO Number: {$po_number}";
        $response['po_id'] = $po_id;

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Save failed: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// HTML & JS
// ------------------------------------------------------------
include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
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
    .item-table { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; background: #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .item-table thead { position: sticky; top: 0; z-index: 10; }
    .item-table th { background: #f1f3f5 !important; color: #333 !important; font-weight: 700 !important; font-size: 9px !important; text-transform: uppercase; letter-spacing: 0.3px; padding: 6px 4px !important; border-bottom: 2px solid #d32f2f !important; white-space: nowrap; text-align: left; }
    .item-table td { padding: 4px 2px !important; vertical-align: middle; border-bottom: 1px solid #e9ecef; }
    .item-table tbody tr:hover { background-color: #f8f9fa; }
    .item-table tbody tr:nth-child(even) { background-color: #fcfcfc; }
    .item-table input, .item-table select { padding: 4px 6px !important; font-size: 11px !important; border: 1px solid #ced4da; border-radius: 4px; background: #fff; width: 100%; box-sizing: border-box; transition: border-color 0.15s, box-shadow 0.15s; }
    .item-table input:focus, .item-table select:focus { border-color: #d32f2f; outline: 0; box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.15); }
    .item-table input::placeholder { color: #aaa; font-style: italic; }
    .item-table .action-col { text-align: center; width: 40px; min-width: 40px; }
    .item-table .remove-row { background: #dc3545; color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; line-height: 24px; text-align: center; font-size: 11px; cursor: pointer; transition: background 0.2s; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
    .item-table .remove-row:hover { background: #c82333; }
    .add-row-btn { margin-top: 10px; background: #28a745; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; font-weight: 600; font-size: 12px; cursor: pointer; transition: background 0.2s; display: inline-flex; align-items: center; gap: 6px; }
    .add-row-btn:hover { background: #218838; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .item-table .col-system { min-width: 70px; }
    .item-table .col-code { min-width: 90px; }
    .item-table .col-name { min-width: 120px; }
    .item-table .col-dept, .item-table .col-subdept, .item-table .col-cat, .item-table .col-color, .item-table .col-size { min-width: 70px; }
    .item-table .col-cost, .item-table .col-sell { min-width: 70px; }
    .item-table .col-qty { min-width: 55px; }
    #itemsFooter td { border-top: 2px solid #d32f2f; background: #f1f3f5; font-weight: 700; font-size: 12px; padding: 6px 4px !important; text-align: center; }
    #itemsFooter td:first-child { text-align: right; }
    .select2-container .select2-selection--single { height: 32px; border-color: #ccc; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 32px; padding-left: 8px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
    .po-list-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
    .po-list-table th { background: #f8f9fa; padding: 8px 6px; border-bottom: 2px solid #d32f2f; text-align: left; font-weight: 700; font-size: 11px; text-transform: uppercase; }
    .po-list-table td { padding: 8px 6px; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
    .po-list-table tr:hover { background-color: #f8f9fa; cursor: pointer; }
    .po-list-table .edit-btn { background: #0d6efd; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; cursor: pointer; }
    .po-list-table .edit-btn:hover { background: #0b5ed7; }
    .filter-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; margin-bottom: 15px; }
    .filter-row .filter-group { display: flex; flex-direction: column; gap: 4px; flex: 1 0 150px; }
    .filter-row .filter-group label { font-size: 11px; font-weight: 600; color: #555; }
    .filter-row .filter-group input, .filter-row .filter-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; }
    .filter-row .filter-actions { display: flex; gap: 8px; align-items: center; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
    .badge-warning { background: #ffc107; color: #212529; }
    .badge-primary { background: #0d6efd; color: #fff; }
    .badge-success { background: #198754; color: #fff; }
    .badge-danger { background: #dc3545; color: #fff; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Purchase Order Editor</span></h2>

    <!-- PO List -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-list"></i> Existing Purchase Orders</span>
            <button type="button" class="btn-asb" onclick="resetForm()"><i class="fas fa-plus"></i> New PO</button>
        </div>
        <div style="padding: 15px 20px 10px 20px;">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="filter_from_date">From Date</label>
                    <input type="date" id="filter_from_date" class="asb-input">
                </div>
                <div class="filter-group">
                    <label for="filter_to_date">To Date</label>
                    <input type="date" id="filter_to_date" class="asb-input">
                </div>
                <div class="filter-group">
                    <label for="filter_supplier">Supplier</label>
                    <select id="filter_supplier" class="asb-input" style="width:100%;">
                        <option value="">All Suppliers</option>
                        <?php
                        $sup_res = $conn->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name");
                        while ($s = $sup_res->fetch_assoc()) {
                            echo "<option value=\"{$s['supplier_id']}\">" . htmlspecialchars($s['supplier_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter_search">Search</label>
                    <input type="text" id="filter_search" class="asb-input" placeholder="PO # or Supplier...">
                </div>
                <div class="filter-actions">
                    <button type="button" class="btn-asb" onclick="loadPOList(true)"><i class="fas fa-search"></i> Filter</button>
                    <button type="button" class="btn-asb-secondary" onclick="resetFilters()">Reset</button>
                </div>
            </div>
            <div style="max-height: 300px; overflow-y: auto;">
                <table class="po-list-table" id="poListTable">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Expected</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Qty</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="poListBody">
                        <tr><td colspan="8" style="text-align:center; color:#888;">Loading POs...</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px; display:flex; justify-content:center;">
                <button type="button" class="btn-asb-secondary" id="loadMorePOs" style="display:none;">Load More</button>
            </div>
        </div>
    </div>

    <!-- Edit Form -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-edit"></i> <span id="formTitle">Create New PO</span></span>
            <span style="font-size:11px; color:#888;">All fields marked * are required</span>
        </div>
        <div style="padding: 20px;">
            <form id="poForm" class="edit-form">
                <input type="hidden" name="po_id" id="po_id" value="0">
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
                    <button type="button" class="btn-asb-secondary" onclick="resetForm()">New / Clear</button>
                    <button type="button" id="savePoBtn" class="btn-asb" style="padding: 10px 24px;"><i class="fas fa-save"></i> <span id="saveBtnLabel">Create PO</span></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Modal -->
    <div id="passwordModal" class="modal-mask">
        <div class="modal-content">
            <h4><i class="fas fa-lock"></i> Manager Authorization</h4>
            <p style="font-size:12px; color:#666;">Enter the manager password to confirm this action.</p>
            <div class="form-group">
                <label for="modalPassword">Password</label>
                <input type="password" id="modalPassword" class="asb-input" placeholder="Enter manager password">
            </div>
            <div id="modalError" style="color:#d32f2f; font-size:12px; margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn-asb-secondary" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-asb" onclick="submitSave()">Confirm</button>
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

    // ─── Select2 helper ───
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
                    if (extraData) $.extend(data, extraData);
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

    var $supplier = $('#supplier_id');
    $supplier.select2(ajaxSelect2('get_suppliers', 'Search supplier...'));

    // ─── PO List ───
    var poListPage = 1;
    var poListLoading = false;
    var poListMore = false;

    window.loadPOList = function(resetPage) {
        if (resetPage) poListPage = 1;
        if (poListLoading) return;
        poListLoading = true;

        var from_date = $('#filter_from_date').val();
        var to_date = $('#filter_to_date').val();
        var supplier = $('#filter_supplier').val();
        var search = $('#filter_search').val();

        $.ajax({
            url: window.location.href,
            dataType: 'json',
            data: {
                ajax_action: 'get_pos',
                from_date: from_date,
                to_date: to_date,
                supplier: supplier,
                search: search,
                page: poListPage
            },
            success: function(data) {
                if (data.error) {
                    $('#poListBody').html('<tr><td colspan="8" style="text-align:center; color:#d32f2f;">Error: ' + data.error + '</td></tr>');
                    poListLoading = false;
                    return;
                }
                if (data.results && data.results.length) {
                    if (poListPage === 1) $('#poListBody').empty();
                    $.each(data.results, function(i, po) {
                        var statusClass = po.status == 'Pending' ? 'warning' : po.status == 'Received' ? 'primary' : po.status == 'Completed' ? 'success' : 'danger';
                        var row = `<tr data-po-id="${po.po_id}">
                            <td><strong>${po.po_number}</strong></td>
                            <td>${po.supplier_name || ''}</td>
                            <td>${po.purchase_date}</td>
                            <td>${po.expected_delivery_date || '-'}</td>
                            <td><span class="badge badge-${statusClass}">${po.status}</span></td>
                            <td>${po.total_items || 0}</td>
                            <td>${po.total_qty || 0}</td>
                            <td style="text-align:center;">
                                <button class="edit-btn" onclick="loadPOForEdit(${po.po_id})"><i class="fas fa-edit"></i> Edit</button>
                            </td>
                        </tr>`;
                        $('#poListBody').append(row);
                    });
                    poListMore = data.pagination.more;
                    if (poListMore) $('#loadMorePOs').show();
                    else $('#loadMorePOs').hide();
                } else {
                    if (poListPage === 1) {
                        $('#poListBody').html('<tr><td colspan="8" style="text-align:center; color:#888;">No POs found.</td></tr>');
                    }
                    poListMore = false;
                    $('#loadMorePOs').hide();
                }
                poListLoading = false;
            },
            error: function(xhr, status, error) {
                poListLoading = false;
                var msg = xhr.responseText || error;
                $('#poListBody').html('<tr><td colspan="8" style="text-align:center; color:#d32f2f;">Error loading POs: ' + msg + '</td></tr>');
            }
        });
    };

    $('#loadMorePOs').on('click', function() {
        poListPage++;
        loadPOList(false);
    });

    window.resetFilters = function() {
        $('#filter_from_date').val('');
        $('#filter_to_date').val('');
        $('#filter_supplier').val('');
        $('#filter_search').val('');
        loadPOList(true);
    };

    // ─── Load PO for edit (with supplier fix) ───
    window.loadPOForEdit = function(po_id) {
        $.ajax({
            url: window.location.href,
            dataType: 'json',
            data: {
                ajax_action: 'load_po',
                po_id: po_id
            },
            success: function(data) {
                if (data.error) {
                    alert(data.error);
                    return;
                }
                var header = data.header;
                $('#po_id').val(header.po_id);

                // ---- FIX: Add the selected supplier option to Select2 ----
                var supplierSelect = $('#supplier_id');
                // Remove any previously added custom options (keep the default placeholder)
                supplierSelect.find('option').not('[value=""]').remove();
                // Add the selected supplier if name exists
                if (header.supplier_name) {
                    var option = new Option(header.supplier_name, header.supplier_id, true, true);
                    supplierSelect.append(option);
                }
                supplierSelect.val(header.supplier_id).trigger('change');
                // -----------------------------------------------------------

                $('#purchase_date').val(header.purchase_date);
                $('#expected_delivery_date').val(header.expected_delivery_date || '');
                $('#status').val(header.status);
                $('#attention').val(header.attention || '');
                $('#remarks').val(header.remarks || '');
                $('#formTitle').text('Edit PO: ' + header.po_number);
                $('#saveBtnLabel').text('Update PO');

                $('#itemsBody').empty();
                $.each(data.items, function(idx, item) {
                    var rowData = {
                        system_code: item.system_code || '',
                        item_code: item.item_code || '',
                        item_name: item.item_name || '',
                        department_id: item.department_id || '',
                        department_name: item.department_name || '',
                        sub_department_id: item.sub_department_id || '',
                        sub_department_name: item.sub_department_name || '',
                        category_id: item.category_id || '',
                        category_name: item.category_name || '',
                        color_id: item.color_id || '',
                        color_name: item.color_name || '',
                        size_id: item.size_id || '',
                        size_name: item.size_name || '',
                        cost: item.cost_price || 0,
                        selling: item.selling_price || 0,
                        quantity: item.quantity || 0,
                        received_qty: item.received_qty || 0
                    };
                    addItemRow(rowData);
                });
                if (data.items.length === 0) addItemRow({});
                updateGrandTotal();
                $('html, body').animate({ scrollTop: $('.asb-card:last').offset().top - 100 }, 500);
            },
            error: function() {
                alert('Error loading PO details.');
            }
        });
    };

    // ─── Reset Form (with supplier clear fix) ───
    window.resetForm = function() {
        $('#po_id').val(0);
        // Clear supplier select: remove all options except the default placeholder
        var supplierSelect = $('#supplier_id');
        supplierSelect.find('option').not('[value=""]').remove();
        supplierSelect.val(null).trigger('change');
        $('#purchase_date').val('<?= date('Y-m-d'); ?>');
        $('#expected_delivery_date').val('');
        $('#status').val('Pending');
        $('#attention').val('');
        $('#remarks').val('');
        $('#formTitle').text('Create New PO');
        $('#saveBtnLabel').text('Create PO');
        $('#itemsBody').empty();
        addItemRow({});
        updateGrandTotal();
        $('html, body').animate({ scrollTop: $('.asb-card:last').offset().top - 100 }, 500);
    };

    // ─── Item row management ───
    var rowIndex = 0;

    function addItemRow(data) {
        data = data || {};
        var index = rowIndex++;
        var html = `
            <tr class="item-row" data-row-index="${index}">
                <td><input type="text" name="items[${index}][system_code]" value="${data.system_code || ''}" placeholder="SysCode" class="asb-input"></td>
                <td>
                    <input type="text" name="items[${index}][item_code]" class="item_code_input asb-input" value="${data.item_code || ''}" placeholder="Item Code" required>
                    <select name="items[${index}][item_id]" class="item-search" style="width:100%; margin-top:4px;">
                        <option value="">-- Search & Auto‑fill --</option>
                        ${data.item_code ? `<option value="${data.item_code}" selected>${data.item_code} - ${data.item_name}</option>` : ''}
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
        var $row = $(html);
        $('#itemsBody').append($row);
        initRowSelects($row);
        applyEnterNavigationToRow($row);
        updateGrandTotal();
        return $row;
    }

    function updateGrandTotal() {
        let total = 0;
        $('.qty-input').each(function() {
            total += parseInt($(this).val()) || 0;
        });
        $('#totalQty').text(total);
    }

    // ─── Keyboard navigation ───
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

    $(document).on('select2:select', '.item-search', function(e) {
        var $row = $(this).closest('tr');
        var $qty = $row.find('.qty-input');
        if ($qty.length) {
            setTimeout(function() { $qty.focus().select(); }, 200);
        }
    });

    function setupEnterNavigation($row) {
        var $allInputs = $row.find('input:not(.select2-search__field), select:not(.item-search)');
        $allInputs.each(function() {
            var $input = $(this);
            $input.off('keydown.enterNav').on('keydown.enterNav', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    var $nextInput = null;
                    if ($(this).hasClass('qty-input')) {
                        var $nextRow = $row.next('tr');
                        if ($nextRow.length) {
                            $nextInput = $nextRow.find('.item-search');
                        } else {
                            $('#addRowBtn').click();
                            var $newRow = $('#itemsBody tr:last');
                            $nextInput = $newRow.find('.item-search');
                        }
                    } else {
                        var $allRowInputs = $row.find('input:not(.select2-search__field), select:not(.item-search)');
                        var currentIdx = $allRowInputs.index(this);
                        if (currentIdx < $allRowInputs.length - 1) {
                            $nextInput = $allRowInputs.eq(currentIdx + 1);
                        } else {
                            $nextInput = $row.find('.qty-input');
                        }
                    }
                    if ($nextInput && $nextInput.length) {
                        setTimeout(function() { $nextInput.focus().select(); }, 50);
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

        function getSupplierId() {
            return $supplier.val() || '';
        }

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
            $row.find('input[name*="[cost]"]').val(data.master_cost_price || 0);
            $row.find('input[name*="[selling]"]').val(data.master_selling_price || 0);
        });

        $supplier.on('change', function() {
            $('.item-search').each(function() { $(this).val(null).trigger('change'); });
        });

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
                if (data.department_id) {
                    setSelectValue($row.find('.dept-select'), data.department_id, data.department_name);
                } else {
                    $row.find('.dept-select').val(null).trigger('change');
                }
                if (data.sub_department_id) {
                    setSelectValue($row.find('.subdept-select'), data.sub_department_id, data.sub_department_name);
                } else {
                    $row.find('.subdept-select').val(null).trigger('change');
                }
            });
            $suggestionSelect.val(null).trigger('change');
        }

        $deptSelect.on('change', function() {
            refreshSuggestions();
            $itemSelect.val(null).trigger('change');
        });

        refreshSuggestions();

        $row.find('.dept-select').select2(ajaxSelect2('get_departments', 'Search dept...'));
        $row.find('.subdept-select').select2(ajaxSelect2('get_subdepartments', 'Search sub dept...'));
        $row.find('.cat-select').select2(ajaxSelect2('get_categories', 'Search category...'));
        $row.find('.color-select').select2(ajaxSelect2('get_colors', 'Search color...'));
        $row.find('.size-select').select2(ajaxSelect2('get_sizes', 'Search size...'));

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

    // ─── Add row button ───
    $('#addRowBtn').on('click', function() {
        addItemRow({});
    });

    $(document).on('input', '.qty-input', updateGrandTotal);

    $(document).on('click', '.remove-row', function() {
        var $row = $(this).closest('tr');
        if ($('#itemsBody tr').length > 1) {
            $row.remove();
            updateGrandTotal();
        } else {
            alert('You must keep at least one item row.');
        }
    });

    // ─── Save button ───
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

    window.closeModal = function() {
        $('#passwordModal').css('display', 'none');
    };

    window.submitSave = function() {
        var password = $('#modalPassword').val().trim();
        if (!password) {
            $('#modalError').text('Please enter the manager password.');
            return;
        }

        var form = document.getElementById('poForm');
        var formData = new FormData(form);
        formData.append('ajax_action', 'save_po');
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
                loadPOList(true);
                resetForm();
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

    // ─── Initial load ───
    loadPOList(true);
    addItemRow({});
    updateGrandTotal();
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>