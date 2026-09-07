<?php
// Start session for persistent draft ID
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log all errors to a file
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . 'logs/php_errors.log');

// Increase limits for large datasets
ini_set('memory_limit', '2048M');
ini_set('max_execution_time', 600);
ini_set('max_input_time', 600);
ini_set('post_max_size', '256M');
ini_set('upload_max_filesize', '256M');

$page_title = 'ASB Fashion | Create Purchase Order';
$page = 'po_ledger';

$conn = getConnection();      // po_system
$qcConn = getQcConnection();  // return_qc

// ------------------------------------------------------------
// Logging Functions
// ------------------------------------------------------------
function logPoCreation($message, $session_id = '') {
    $logDir = ROOT_PATH . 'logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . 'po_creation.log';
    $timestamp = date('Y-m-d H:i:s');
    $session = $session_id ?: (session_id() ?: 'unknown');
    $entry = "[$timestamp] [Session: $session] $message" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function logError($message, $session_id = '') {
    $logDir = ROOT_PATH . 'logs/';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . 'error_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $session = $session_id ?: (session_id() ?: 'unknown');
    $entry = "[$timestamp] [Session: $session] ERROR: $message" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ------------------------------------------------------------
// Get or create default user
// ------------------------------------------------------------
function getDefaultUserId($conn) {
    $result = $conn->query("SELECT id FROM po_users LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (int)$row['id'];
    }
    
    $default_user = 'system';
    $default_pass = md5('system123');
    $insert = $conn->prepare("INSERT INTO po_users (username, password, role) VALUES (?, ?, 'admin')");
    $insert->bind_param("ss", $default_user, $default_pass);
    if ($insert->execute()) {
        $id = $insert->insert_id;
        $insert->close();
        return (int)$id;
    }
    $insert->close();
    return 1;
}

// ------------------------------------------------------------
// Ensure tables exist with proper indexes
// ------------------------------------------------------------
function ensureAllTablesExist($conn, $qcConn) {
    // Check suppliers
    $check = $conn->query("SHOW TABLES LIKE 'suppliers'");
    if ($check->num_rows == 0) {
        $conn->query("CREATE TABLE suppliers (
            supplier_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            supplier_name VARCHAR(100) NOT NULL,
            system_id VARCHAR(50) DEFAULT NULL,
            contact_number VARCHAR(20) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            PRIMARY KEY (supplier_id),
            INDEX idx_supplier_name (supplier_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        $conn->query("INSERT INTO suppliers (supplier_id, supplier_name, system_id, contact_number, email, address)
                      SELECT supplier_id, supplier_name, system_id, contact_number, email, address
                      FROM return_qc.suppliers");
    }

    // Check items with proper indexes
    $check = $conn->query("SHOW TABLES LIKE 'items'");
    if ($check->num_rows == 0) {
        $conn->query("CREATE TABLE items (
            item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            system_code VARCHAR(50) DEFAULT NULL,
            item_code VARCHAR(50) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            department_id BIGINT(20) UNSIGNED DEFAULT NULL,
            sub_department_id BIGINT(20) UNSIGNED DEFAULT NULL,
            category_id BIGINT(20) UNSIGNED DEFAULT NULL,
            color_id BIGINT(20) UNSIGNED DEFAULT NULL,
            size_id BIGINT(20) UNSIGNED DEFAULT NULL,
            supplier_id BIGINT(20) UNSIGNED DEFAULT NULL,
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (item_id),
            UNIQUE KEY item_code (item_code),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_department_id (department_id),
            INDEX idx_item_name (item_name(100)),
            INDEX idx_composite_search (item_code, supplier_id, department_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $colCheck = $conn->query("SHOW COLUMNS FROM items LIKE 'supplier_id'");
        if ($colCheck->num_rows == 0) {
            $conn->query("ALTER TABLE items ADD COLUMN supplier_id BIGINT(20) UNSIGNED NULL AFTER size_id");
            $conn->query("ALTER TABLE items ADD INDEX idx_supplier_id (supplier_id)");
        }
        // Add missing indexes
        $conn->query("ALTER TABLE items ADD INDEX IF NOT EXISTS idx_department_id (department_id)");
        $conn->query("ALTER TABLE items ADD INDEX IF NOT EXISTS idx_item_name (item_name(100))");
        $conn->query("ALTER TABLE items ADD INDEX IF NOT EXISTS idx_composite_search (item_code, supplier_id, department_id)");
    }

    // Check po_header
    $check = $conn->query("SHOW TABLES LIKE 'po_header'");
    if ($check->num_rows == 0) {
        $conn->query("CREATE TABLE po_header (
            po_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            po_number VARCHAR(50) NOT NULL,
            supplier_id BIGINT(20) UNSIGNED NOT NULL,
            purchase_date DATE NOT NULL,
            attention VARCHAR(255) DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            expected_delivery_date DATE DEFAULT NULL,
            added_by INT(11) DEFAULT NULL,
            status ENUM('Pending','Received','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (po_id),
            UNIQUE KEY po_number (po_number),
            INDEX idx_supplier_id (supplier_id),
            INDEX idx_purchase_date (purchase_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    // Check po_items
    $check = $conn->query("SHOW TABLES LIKE 'po_items'");
    if ($check->num_rows == 0) {
        $conn->query("CREATE TABLE po_items (
            po_item_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id BIGINT(20) UNSIGNED NOT NULL,
            item_id BIGINT(20) UNSIGNED NOT NULL,
            quantity INT(11) NOT NULL DEFAULT 0,
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            received_qty INT(11) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (po_item_id),
            INDEX idx_po_id (po_id),
            INDEX idx_item_id (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    // Check po_status_log
    $check = $conn->query("SHOW TABLES LIKE 'po_status_log'");
    if ($check->num_rows == 0) {
        $conn->query("CREATE TABLE po_status_log (
            status_log_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id BIGINT(20) UNSIGNED NOT NULL,
            status ENUM('Ordered','Reminder','Received','Sent_To_Floor','Completed') NOT NULL,
            remarks TEXT DEFAULT NULL,
            updated_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (status_log_id),
            INDEX idx_po_id (po_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    // Check po_users
    $check = $conn->query("SHOW TABLES LIKE 'po_users'");
    if ($check->num_rows == 0) {
        $conn->query("CREATE TABLE po_users (
            id INT(11) NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','user','received') DEFAULT 'user',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        $default_user = 'system';
        $default_pass = md5('system123');
        $insert = $conn->prepare("INSERT INTO po_users (username, password, role) VALUES (?, ?, 'admin')");
        $insert->bind_param("ss", $default_user, $default_pass);
        $insert->execute();
        $insert->close();
    }

    // Check po_draft
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
        UNIQUE KEY `session_id` (`session_id`),
        INDEX idx_supplier_id (supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Check po_draft_items
    $conn->query("CREATE TABLE IF NOT EXISTS `po_draft_items` (
        `draft_item_id` INT(11) NOT NULL AUTO_INCREMENT,
        `draft_id` INT(11) NOT NULL,
        `item_id` INT(11) DEFAULT NULL,
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
        KEY `draft_id` (`draft_id`),
        INDEX idx_item_code (item_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

// Helper: ensure supplier exists
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

    $fetch = $qcConn->prepare("SELECT supplier_name, system_id, contact_number, email, address FROM suppliers WHERE supplier_id = ?");
    $fetch->bind_param("i", $supplier_id);
    $fetch->execute();
    $res = $fetch->get_result();
    if ($row = $res->fetch_assoc()) {
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
// Save or update item in items table
// ------------------------------------------------------------
function saveOrUpdateItem($conn, $item_data, $supplier_id) {
    $item_code = trim($item_data['item_code'] ?? '');
    $item_name = trim($item_data['item_name'] ?? '');
    
    if ($item_code === '' || $item_name === '') {
        return null;
    }
    
    $system_code = trim($item_data['system_code'] ?? '');
    $department_id = isset($item_data['department_id']) && $item_data['department_id'] > 0 ? (int)$item_data['department_id'] : null;
    $sub_department_id = isset($item_data['sub_department_id']) && $item_data['sub_department_id'] > 0 ? (int)$item_data['sub_department_id'] : null;
    $category_id = isset($item_data['category_id']) && $item_data['category_id'] > 0 ? (int)$item_data['category_id'] : null;
    $color_id = isset($item_data['color_id']) && $item_data['color_id'] > 0 ? (int)$item_data['color_id'] : null;
    $size_id = isset($item_data['size_id']) && $item_data['size_id'] > 0 ? (int)$item_data['size_id'] : null;
    $cost = (float)($item_data['cost'] ?? 0);
    $sell = (float)($item_data['selling'] ?? 0);
    
    // Check if item exists by item_code
    $check = $conn->prepare("SELECT item_id, supplier_id FROM items WHERE item_code = ?");
    $check->bind_param("s", $item_code);
    $check->execute();
    $result = $check->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $item_id = $row['item_id'];
        
        // Update existing item
        $update = $conn->prepare("UPDATE items SET 
            system_code = ?, 
            item_name = ?, 
            department_id = ?, 
            sub_department_id = ?, 
            category_id = ?, 
            color_id = ?, 
            size_id = ?, 
            supplier_id = ?,
            cost_price = ?, 
            selling_price = ? 
            WHERE item_id = ?");
        $update->bind_param("ssiiiiiiddi", 
            $system_code, 
            $item_name, 
            $department_id, 
            $sub_department_id, 
            $category_id, 
            $color_id, 
            $size_id,
            $supplier_id,
            $cost, 
            $sell, 
            $item_id
        );
        $update->execute();
        $update->close();
        logPoCreation("Updated existing item: $item_code (ID: $item_id) with supplier: $supplier_id");
        $check->close();
        return $item_id;
    } else {
        // Insert new item
        $insert = $conn->prepare("INSERT INTO items 
            (system_code, item_code, item_name, department_id, sub_department_id, 
             category_id, color_id, size_id, supplier_id, cost_price, selling_price) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->bind_param("sssiiiiiidd", 
            $system_code, 
            $item_code, 
            $item_name, 
            $department_id, 
            $sub_department_id, 
            $category_id, 
            $color_id, 
            $size_id,
            $supplier_id,
            $cost, 
            $sell
        );
        $insert->execute();
        $item_id = $insert->insert_id;
        $insert->close();
        logPoCreation("Created new item: $item_code (ID: $item_id) with supplier: $supplier_id");
        $check->close();
        return $item_id;
    }
}

// ------------------------------------------------------------
// AJAX endpoints
// ------------------------------------------------------------
if (
    (isset($_GET['ajax_action']) || isset($_POST['ajax_action'])) &&
    (($_GET['ajax_action'] ?? $_POST['ajax_action'] ?? '') !== 'save_new_po')
) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    $session_id = session_id() ?: 'unknown';
    logPoCreation("AJAX request received: " . ($_GET['ajax_action'] ?? $_POST['ajax_action'] ?? 'unknown'));

    try {
        ensureAllTablesExist($conn, $qcConn);
    } catch (Exception $e) {
        logError("Setup failed: " . $e->getMessage());
        echo json_encode(['error' => 'Setup failed: ' . $e->getMessage()]);
        exit;
    }

    $action = isset($_GET['ajax_action']) ? $_GET['ajax_action'] : (isset($_POST['ajax_action']) ? $_POST['ajax_action'] : '');
    if (empty($action)) {
        echo json_encode(['error' => 'No action specified']);
        exit;
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
    if ($per_page > 500) $per_page = 500;
    $offset = ($page - 1) * $per_page;

    $response = ['results' => [], 'pagination' => ['more' => false, 'total' => 0]];

    try {
        switch ($action) {
            case 'get_suppliers':
                $sql = "SELECT supplier_id AS id, supplier_name AS text, system_id, contact_number, email, address FROM suppliers";
                $count_sql = "SELECT COUNT(*) AS total FROM suppliers";
                $where = "";
                if (!empty($search)) {
                    $where = " WHERE supplier_name LIKE ?";
                    $like = "%$search%";
                }
                $stmt = $conn->prepare($sql . $where . " ORDER BY supplier_name LIMIT ? OFFSET ?");
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
                $response['pagination']['total'] = $total;
                $stmt->close();
                $count_stmt->close();
                break;

            case 'get_items':
                $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : null;
                $supplier_id   = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
                
                // Get department, sub-department, category, color, size names
                $sql = "SELECT 
                            i.item_id AS id, 
                            CONCAT(i.item_code, ' - ', i.item_name) AS text, 
                            i.system_code, 
                            i.item_code, 
                            i.item_name, 
                            i.department_id, 
                            i.sub_department_id, 
                            i.category_id, 
                            i.color_id, 
                            i.size_id, 
                            i.supplier_id,
                            i.cost_price AS master_cost_price, 
                            i.selling_price AS master_selling_price,
                            d.department_name,
                            sd.sub_department_name,
                            c.category_name,
                            cl.color_name,
                            s.size_name
                        FROM items i
                        LEFT JOIN departments d ON i.department_id = d.department_id
                        LEFT JOIN sub_departments sd ON i.sub_department_id = sd.sub_department_id
                        LEFT JOIN categories c ON i.category_id = c.category_id
                        LEFT JOIN colors cl ON i.color_id = cl.color_id
                        LEFT JOIN sizes s ON i.size_id = s.size_id";
                
                $where = [];
                $params = [];
                $types = "";

                if (!empty($search)) {
                    $where[] = "(i.item_code LIKE ? OR i.item_name LIKE ? OR i.system_code LIKE ?)";
                    $like = "%$search%";
                    $params[] = $like;
                    $params[] = $like;
                    $params[] = $like;
                    $types .= "sss";
                }
                if ($department_id && $department_id > 0) {
                    $where[] = "i.department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                if ($supplier_id && $supplier_id > 0) {
                    $where[] = "i.supplier_id = ?";
                    $params[] = $supplier_id;
                    $types .= "i";
                }

                $where_clause = "";
                if (!empty($where)) {
                    $where_clause = " WHERE " . implode(" AND ", $where);
                }

                $stmt = $conn->prepare($sql . $where_clause . " ORDER BY i.item_code LIMIT ? OFFSET ?");
                $params[] = $per_page;
                $params[] = $offset;
                $types_final = $types . "ii";
                
                if (!empty($params)) {
                    $stmt->bind_param($types_final, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                
                // Get total count
                $count_sql = "SELECT COUNT(*) AS total FROM items i" . $where_clause;
                $count_stmt = $conn->prepare($count_sql);
                if (!empty($params) && count($params) > 2) {
                    // Remove per_page and offset for count
                    $count_params = array_slice($params, 0, count($params) - 2);
                    $count_types = substr($types_final, 0, -2);
                    if (!empty($count_params)) {
                        $count_stmt->bind_param($count_types, ...$count_params);
                    }
                }
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                $response['pagination']['total'] = $total;
                $stmt->close();
                $count_stmt->close();
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
                $params[] = $per_page;
                $params[] = $offset;
                $types_final = $types . "ii";
                if (!empty($params)) {
                    $stmt->bind_param($types_final, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                
                $count_sql = "SELECT COUNT(*) AS total FROM item_name_suggestions s" . $where_clause;
                $count_stmt = $conn->prepare($count_sql);
                if (!empty($params) && count($params) > 2) {
                    $count_params = array_slice($params, 0, count($params) - 2);
                    $count_types = substr($types_final, 0, -2);
                    if (!empty($count_params)) {
                        $count_stmt->bind_param($count_types, ...$count_params);
                    }
                }
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                $response['pagination']['total'] = $total;
                $stmt->close();
                $count_stmt->close();
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
                $where = "";
                if (!empty($search)) {
                    $where = " WHERE $name_col LIKE ?";
                    $like = "%$search%";
                }
                $stmt = $conn->prepare($sql . $where . " ORDER BY $name_col LIMIT ? OFFSET ?");
                if (!empty($search)) {
                    $stmt->bind_param("sii", $like, $per_page, $offset);
                } else {
                    $stmt->bind_param("ii", $per_page, $offset);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $response['results'] = $res->fetch_all(MYSQLI_ASSOC);
                
                $count_sql = "SELECT COUNT(*) AS total FROM $table" . $where;
                $count_stmt = $conn->prepare($count_sql);
                if (!empty($search)) {
                    $count_stmt->bind_param("s", $like);
                }
                $count_stmt->execute();
                $total = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
                $response['pagination']['more'] = ($offset + $per_page) < $total;
                $response['pagination']['total'] = $total;
                $stmt->close();
                $count_stmt->close();
                break;

            case 'auto_save_draft':
                $session_id = session_id() ?: 'guest_' . uniqid();
                $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
                $supplier_name = '';
                if ($supplier_id > 0) {
                    $name_stmt = $conn->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
                    $name_stmt->bind_param("i", $supplier_id);
                    $name_stmt->execute();
                    $name_res = $name_stmt->get_result();
                    if ($row = $name_res->fetch_assoc()) {
                        $supplier_name = $row['supplier_name'];
                    }
                    $name_stmt->close();
                }

                $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '';
                $attention = isset($_POST['attention']) ? trim($_POST['attention']) : '';
                $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
                $expected_delivery_date = isset($_POST['expected_delivery_date']) && $_POST['expected_delivery_date'] !== '' ? $_POST['expected_delivery_date'] : null;
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';

                if (empty($purchase_date)) {
                    throw new Exception('Purchase Date is required for draft.');
                }

                $conn->begin_transaction();

                $del = $conn->prepare("DELETE FROM po_draft WHERE session_id = ?");
                $del->bind_param("s", $session_id);
                $del->execute();
                $del->close();

                $insert = $conn->prepare("INSERT INTO po_draft 
                    (session_id, supplier_id, supplier_name, purchase_date, attention, remarks, expected_delivery_date, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->bind_param("sissssss", $session_id, $supplier_id, $supplier_name, $purchase_date, $attention, $remarks, $expected_delivery_date, $status);
                $insert->execute();
                $draft_id = $insert->insert_id;
                $insert->close();

                $itemCount = 0;
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    $values = [];
                    $params = [];
                    $types = "";
                    foreach ($_POST['items'] as $item) {
                        $system_code = trim($item['system_code'] ?? '');
                        $item_code = trim($item['item_code'] ?? '');
                        $item_name = trim($item['item_name'] ?? '');
                        if ($item_code === '' || $item_name === '' || (int)($item['quantity'] ?? 0) <= 0) continue;

                        $department_id = isset($item['department_id']) && $item['department_id'] > 0 ? (int)$item['department_id'] : null;
                        $sub_department_id = isset($item['sub_department_id']) && $item['sub_department_id'] > 0 ? (int)$item['sub_department_id'] : null;
                        $category_id = isset($item['category_id']) && $item['category_id'] > 0 ? (int)$item['category_id'] : null;
                        $color_id = isset($item['color_id']) && $item['color_id'] > 0 ? (int)$item['color_id'] : null;
                        $size_id = isset($item['size_id']) && $item['size_id'] > 0 ? (int)$item['size_id'] : null;
                        $cost = (float)($item['cost'] ?? 0);
                        $selling = (float)($item['selling'] ?? 0);
                        $quantity = (int)($item['quantity'] ?? 0);

                        $values[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $params[] = $draft_id;
                        $params[] = null;
                        $params[] = $system_code;
                        $params[] = $item_code;
                        $params[] = $item_name;
                        $params[] = $department_id;
                        $params[] = $sub_department_id;
                        $params[] = $category_id;
                        $params[] = $color_id;
                        $params[] = $size_id;
                        $params[] = $cost;
                        $params[] = $selling;
                        $params[] = $quantity;
                        $types .= "iisssiiiiiddi";
                        $itemCount++;
                    }

                    if (!empty($values)) {
                        $sql = "INSERT INTO po_draft_items 
                            (draft_id, item_id, system_code, item_code, item_name, department_id, sub_department_id, 
                             category_id, color_id, size_id, cost, selling, quantity)
                            VALUES " . implode(", ", $values);
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $conn->commit();
                $response = ['success' => true, 'message' => "Draft saved", 'draft_id' => $draft_id, 'item_count' => $itemCount];
                break;

            case 'load_draft':
                $session_id = session_id() ?: 'guest_' . uniqid();
                $draft = null;
                $stmt = $conn->prepare("SELECT * FROM po_draft WHERE session_id = ?");
                $stmt->bind_param("s", $session_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $draft = $row;
                    $itemStmt = $conn->prepare("SELECT * FROM po_draft_items WHERE draft_id = ?");
                    $itemStmt->bind_param("i", $draft['draft_id']);
                    $itemStmt->execute();
                    $itemsRes = $itemStmt->get_result();
                    $draft['items'] = $itemsRes->fetch_all(MYSQLI_ASSOC);
                    $itemStmt->close();
                }
                $stmt->close();
                $response = ['success' => true, 'draft' => $draft];
                break;

            default:
                $response = ['error' => 'Invalid action'];
        }
    } catch (Exception $e) {
        logError($e->getMessage());
        $response = ['error' => $e->getMessage()];
    }

    ob_clean();
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// Handle AJAX save (final PO creation)
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_new_po') {
    $session_id = session_id() ?: 'unknown';
    logPoCreation("=== SAVE_NEW_PO TRIGGERED ===", $session_id);
    
    // Parse items from JSON
    $items = [];
    
    if (isset($_POST['items_json']) && !empty($_POST['items_json'])) {
        logPoCreation("items_json found, parsing...", $session_id);
        $items = json_decode($_POST['items_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            logPoCreation("Successfully parsed " . count($items) . " items from JSON", $session_id);
        } else {
            logPoCreation("JSON parse error: " . json_last_error_msg(), $session_id);
            $items = [];
        }
    } else {
        logPoCreation("No items_json found in POST", $session_id);
    }
    
    ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'po_id' => null, 'po_number' => null];

    try {
        ensureAllTablesExist($conn, $qcConn);

        // Get header data
        $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
        $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '';
        $attention = isset($_POST['attention']) ? trim($_POST['attention']) : '';
        $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
        $expected_delivery_date = isset($_POST['expected_delivery_date']) && $_POST['expected_delivery_date'] !== '' ? $_POST['expected_delivery_date'] : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';

        logPoCreation("Header: supplier_id=$supplier_id, purchase_date=$purchase_date", $session_id);

        if ($supplier_id <= 0 || empty($purchase_date)) {
            throw new Exception('Supplier and Purchase Date are required.');
        }

        if (!ensureSupplierExists($supplier_id, $conn, $qcConn)) {
            throw new Exception('Selected supplier could not be found or copied.');
        }

        // Generate PO number
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
        logPoCreation("Generated PO: $po_number", $session_id);

        $conn->begin_transaction();
        
        $added_by = getDefaultUserId($conn);
        logPoCreation("User ID: $added_by", $session_id);

        // Disable foreign key checks for performance
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        // Insert PO header
        $insert_header = $conn->prepare("INSERT INTO po_header 
            (po_number, supplier_id, purchase_date, attention, remarks, expected_delivery_date, status, added_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_header->bind_param("sisssssi", $po_number, $supplier_id, $purchase_date, $attention, $remarks, $expected_delivery_date, $status, $added_by);
        
        if (!$insert_header->execute()) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            throw new Exception("Header insert failed: " . $insert_header->error);
        }
        $po_id = $insert_header->insert_id;
        $insert_header->close();
        logPoCreation("PO header inserted: $po_id", $session_id);

        // Status log
        $log_stmt = $conn->prepare("INSERT INTO po_status_log (po_id, status, remarks) VALUES (?, 'Ordered', 'PO created via system')");
        $log_stmt->bind_param("i", $po_id);
        $log_stmt->execute();
        $log_stmt->close();

        // Process items from parsed JSON
        $po_items_values = [];
        $po_items_params = [];
        $po_items_types = "";
        $item_count = 0;

        if (!empty($items)) {
            logPoCreation("Processing " . count($items) . " items from JSON", $session_id);
            
            foreach ($items as $index => $item) {
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
                $qty = (int)($item['quantity'] ?? 0);
                $recv_qty = (int)($item['received_qty'] ?? 0);

                logPoCreation("Item $index: code=$item_code, name=$item_name, qty=$qty", $session_id);

                if ($item_code === '' || $item_name === '' || $qty <= 0) {
                    logPoCreation("Skipping invalid item $index", $session_id);
                    continue;
                }

                // Save or update item in items table with supplier_id
                $item_data = [
                    'system_code' => $system_code,
                    'item_code' => $item_code,
                    'item_name' => $item_name,
                    'department_id' => $department_id,
                    'sub_department_id' => $sub_department_id,
                    'category_id' => $category_id,
                    'color_id' => $color_id,
                    'size_id' => $size_id,
                    'cost' => $cost,
                    'selling' => $sell
                ];
                
                $item_id = saveOrUpdateItem($conn, $item_data, $supplier_id);
                
                if ($item_id === null) {
                    logPoCreation("Failed to save item $item_code", $session_id);
                    continue;
                }

                // Collect for batch insert into po_items
                $po_items_values[] = "(?, ?, ?, ?, ?, ?)";
                $po_items_params[] = $po_id;
                $po_items_params[] = $item_id;
                $po_items_params[] = $qty;
                $po_items_params[] = $cost;
                $po_items_params[] = $sell;
                $po_items_params[] = $recv_qty;
                $po_items_types .= "iiiddi";
                $item_count++;
            }
        } else {
            throw new Exception("No items received. Please add items to the PO.");
        }

        // Bulk insert po_items
        if (!empty($po_items_values)) {
            $sql = "INSERT INTO po_items 
                (po_id, item_id, quantity, cost_price, selling_price, received_qty)
                VALUES " . implode(", ", $po_items_values);
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($po_items_types, ...$po_items_params);
            if (!$stmt->execute()) {
                throw new Exception("PO items bulk insert failed: " . $stmt->error);
            }
            $stmt->close();
            logPoCreation("Inserted $item_count PO items", $session_id);
        } else {
            throw new Exception("No valid items to add to PO.");
        }

        // Delete the draft
        $session_id = session_id();
        if (!empty($session_id)) {
            $delDraft = $conn->prepare("DELETE FROM po_draft WHERE session_id = ?");
            $delDraft->bind_param("s", $session_id);
            $delDraft->execute();
            $delDraft->close();
        }

        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        $conn->commit();
        $response['success'] = true;
        $response['message'] = "PO created successfully. PO Number: {$po_number}";
        $response['po_id'] = $po_id;
        $response['po_number'] = $po_number;
        $response['item_count'] = $item_count;
        logPoCreation("=== PO CREATED SUCCESSFULLY: $po_number with $item_count items ===", $session_id);
        
    } catch (Exception $e) {
        if (isset($conn)) {
            @$conn->rollback();
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        }
        $errorMsg = $e->getMessage();
        logPoCreation("ERROR: " . $errorMsg, $session_id);
        logError("ERROR: " . $errorMsg, $session_id);
        $response['message'] = 'Creation failed: ' . $errorMsg;
    } catch (Error $e) {
        if (isset($conn)) {
            @$conn->rollback();
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        }
        $errorMsg = $e->getMessage();
        logPoCreation("FATAL ERROR: " . $errorMsg, $session_id);
        logError("FATAL ERROR: " . $errorMsg, $session_id);
        $response['message'] = 'Fatal error: ' . $errorMsg;
    }

    ob_clean();
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// HTML & JS (front‑end)
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
    .asb-card-header { background: #fff; border-bottom: 2px solid #eaeaea; padding: 15px 20px; color: #b71c1c; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
    .btn-asb { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .btn-asb:hover { background: #b71c1c; color: #fff; }
    .btn-asb-secondary { background: #f5f5f5; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; text-decoration: none; }
    .btn-asb-secondary:hover { background: #e0e0e0; }
    .btn-asb-success { background: #28a745; color: #fff; border: none; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .btn-asb-success:hover { background: #218838; color: #fff; }
    .asb-input { border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; width: 100%; font-size: 12px; box-sizing: border-box; transition: border-color 0.15s, box-shadow 0.15s; }
    .asb-input:focus { border-color: #d32f2f; outline: none; box-shadow: 0 0 0 3px rgba(211,47,47,0.15); }
    .asb-footer { text-align: center; margin-top: 40px; padding: 15px; color: #777; border-top: 1px solid #eee; font-size: 12px; }
    .asb-footer strong { color: #b71c1c; }
    .modal-mask { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:30px; border-radius:8px; max-width:500px; width:90%; box-shadow:0 5px 15px rgba(0,0,0,0.3); max-height:90vh; overflow-y:auto; }
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
    .add-row-btn:focus { outline: 2px solid #d32f2f; outline-offset: 2px; }
    .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; max-height: 600px; overflow-y: auto; }

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

    #autosave-status {
        font-size: 12px;
        color: #888;
        margin-left: 15px;
        transition: all 0.3s;
    }
    #autosave-status.saving { color: #f0ad4e; }
    #autosave-status.saved { color: #5cb85c; }
    #autosave-status.error { color: #d9534f; }
    
    .debug-log {
        background: #1a1a2e;
        color: #00ff88;
        padding: 15px;
        border-radius: 6px;
        font-family: 'Courier New', monospace;
        font-size: 11px;
        max-height: 300px;
        overflow: auto;
        margin-top: 15px;
        display: none;
    }
    .debug-log.show { display: block; }
    
    .keyboard-nav-hint {
        font-size: 10px;
        color: #888;
        margin-left: 10px;
        background: #f1f1f1;
        padding: 2px 8px;
        border-radius: 3px;
    }
    
    .quick-add-btn { background: #17a2b8; color: #fff; border: none; padding: 6px 14px; border-radius: 4px; font-weight: 600; font-size: 12px; cursor: pointer; transition: background 0.2s; display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; margin-left: 10px; }
    .quick-add-btn:hover { background: #138496; }
    
    .loading-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #f3f3f3; border-top: 2px solid #d32f2f; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    
    .badge-count { background: #d32f2f; color: #fff; border-radius: 50%; padding: 2px 8px; font-size: 10px; margin-left: 8px; }
    
    #performance-indicator { font-size: 10px; color: #888; margin-left: 10px; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <h2 class="asb-header-title">
        ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Create New Purchase Order</span>
        <span id="performance-indicator" style="font-size:11px; font-weight:normal; color:#888; float:right; margin-top:8px;">
            <i class="fas fa-bolt"></i> Optimized
        </span>
    </h2>

    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-plus-circle"></i> New Purchase Order</span>
            <span style="font-size:11px; color:#888; display:flex; align-items:center; flex-wrap:wrap; gap:8px;">
                <span>All fields marked * are required</span>
                <span id="autosave-status">
                    <i class="fas fa-cloud-upload-alt"></i> Auto-save ready
                </span>
                <span class="keyboard-nav-hint"><i class="fas fa-keyboard"></i> Enter to navigate</span>
                <button type="button" onclick="toggleDebug()" style="background:#333; color:#fff; border:none; padding:2px 10px; border-radius:4px; font-size:10px; cursor:pointer;">🐞 Debug</button>
            </span>
        </div>
        <div style="padding: 20px;">
            <form id="createPoForm" class="edit-form" autocomplete="off">
                <!-- Header -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="supplier_id">Supplier *</label>
                        <select name="supplier_id" id="supplier_id" class="asb-input select2-ajax" style="width:100%;" required tabindex="1">
                            <option value="">-- Search Supplier --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="purchase_date">Purchase Date *</label>
                        <input type="date" name="purchase_date" id="purchase_date" class="asb-input" value="<?= date('Y-m-d'); ?>" required tabindex="2">
                    </div>
                    <div class="form-group">
                        <label for="expected_delivery_date">Expected Delivery</label>
                        <input type="date" name="expected_delivery_date" id="expected_delivery_date" class="asb-input" tabindex="3">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="asb-input" tabindex="4">
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
                        <input type="text" name="attention" id="attention" class="asb-input" placeholder="Person or department" tabindex="5">
                    </div>
                    <div class="form-group">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks" id="remarks" class="asb-input" rows="2" placeholder="Additional notes" tabindex="6"></textarea>
                    </div>
                </div>

                <!-- Items -->
                <div style="margin-top: 30px;">
                    <h5 style="color:#b71c1c; font-weight:bold; margin-bottom:15px; display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
                        <span><i class="fas fa-list"></i> Line Items</span>
                        <span style="font-size:12px; font-weight:normal; color:#888;">
                            <i class="fas fa-info-circle" title="Enter quantity for each item. Press Enter to move to next field."></i>
                            <span style="margin-left:10px; font-size:10px; color:#aaa;">(Press <kbd>Enter</kbd> to navigate fields)</span>
                        </span>
                        <span id="item-counter" style="font-size:12px; font-weight:normal; color:#888;">0 items</span>
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
                            <tbody id="itemsBody"></tbody>
                            <tfoot id="itemsFooter">
                                <tr>
                                    <td colspan="10" style="text-align:right; font-weight:700;">Grand Total Qty</td>
                                    <td id="totalQty">0</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;">
                        <button type="button" id="addRowBtn" class="add-row-btn" tabindex="7"><i class="fas fa-plus"></i> Add Item</button>
                        <button type="button" id="quickAddBtn" class="quick-add-btn" tabindex="8"><i class="fas fa-fast-forward"></i> Add 10</button>
                    </div>
                </div>

                <!-- Save / Restore Buttons -->
                <div style="margin-top: 25px; text-align: right; display:flex; flex-wrap:wrap; gap:10px; justify-content:flex-end;">
                    <a href="view_pos.php" class="btn-asb-secondary" tabindex="9">Cancel</a>
                    <button type="button" id="restoreDraftBtn" class="btn-asb-success" tabindex="10"><i class="fas fa-undo-alt"></i> Restore Draft</button>
                    <button type="button" id="savePoBtn" class="btn-asb" style="padding: 10px 24px;" tabindex="11"><i class="fas fa-save"></i> Create PO</button>
                </div>
            </form>
            
            <!-- Debug Log -->
            <div id="debugLog" class="debug-log">
                <strong>📋 Debug Log</strong><br>
                <div id="debugContent">Waiting for actions...</div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal-mask">
        <div class="modal-content">
            <h4><i class="fas fa-check-circle"></i> Confirm PO Creation</h4>
            <p style="font-size:14px; color:#333;">Are you sure you want to create this Purchase Order?</p>
            <div id="modalSummary" style="font-size:12px; color:#555; background:#f8f9fa; padding:10px; border-radius:4px; margin:10px 0;"></div>
            <div id="modalError" style="color:#d32f2f; font-size:12px; margin-bottom:10px;"></div>
            <div id="modalDebug" style="background:#1a1a2e; color:#00ff88; padding:10px; border-radius:4px; font-family:monospace; font-size:10px; max-height:150px; overflow:auto; display:none; margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn-asb-secondary" onclick="closeConfirmModal()" tabindex="12">Cancel</button>
                <button type="button" class="btn-asb" onclick="submitSave()" tabindex="13">Confirm Create</button>
            </div>
        </div>
    </div>

    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:4px; display:inline-block; color:#aaa;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>
</div>

<script>
// Debug toggle
function toggleDebug() {
    $('#debugLog').toggleClass('show');
    if ($('#debugLog').hasClass('show')) {
        debugLog('Debug mode enabled');
    }
}

var debugLogQueue = [];
var debugLogTimer = null;

function debugLog(message) {
    var content = $('#debugContent');
    var time = new Date().toLocaleTimeString();
    var entry = '[' + time + '] ' + message;
    
    debugLogQueue.push(entry);
    if (debugLogQueue.length > 100) debugLogQueue.shift();
    
    if (!debugLogTimer) {
        debugLogTimer = setTimeout(function() {
            var currentContent = content.html();
            var newEntries = debugLogQueue.join('<br>');
            content.html(currentContent + '<br>' + newEntries);
            debugLogQueue = [];
            debugLogTimer = null;
            content.scrollTop(content[0].scrollHeight);
        }, 100);
    }
}

var perfTimers = {};

function perfStart(name) { perfTimers[name] = performance.now(); }
function perfEnd(name) {
    if (perfTimers[name]) {
        var duration = (performance.now() - perfTimers[name]).toFixed(2);
        debugLog(name + ' took ' + duration + 'ms');
        delete perfTimers[name];
        return duration;
    }
    return 0;
}

$(document).ready(function() {
    perfStart('page_load');
    debugLog('Page loaded');
    
    // ─── Keyboard Navigation Handler ───
    $(document).on('keydown', function(e) {
        if (e.key !== 'Enter') return;
        var target = e.target;
        var tagName = target.tagName.toLowerCase();
        if (tagName === 'textarea') return;
        if (tagName === 'button') return;
        if ($(target).hasClass('select2-search__field')) return;
        if ($(target).closest('.modal-content').length > 0) return;
        e.preventDefault();
        
        var $row = $(target).closest('.item-row');
        if ($row.length > 0) {
            var rowFocusable = $row.find('input, select, button').filter(function() {
                return $(this).is(':visible') && !$(this).prop('disabled');
            });
            var currentIndex = rowFocusable.index(target);
            var nextIndex = currentIndex + 1;
            
            if (nextIndex >= rowFocusable.length) {
                var nextRow = $row.next('.item-row');
                if (nextRow.length > 0) {
                    var nextRowInput = nextRow.find('input, select').filter(':visible:not(:disabled)').first();
                    if (nextRowInput.length > 0) {
                        nextRowInput.focus().select();
                        return;
                    }
                } else {
                    $('#addRowBtn').focus();
                    return;
                }
            } else {
                var nextInput = rowFocusable.eq(nextIndex);
                if (nextInput.length > 0) {
                    if (nextInput.hasClass('remove-row') || nextInput.closest('.action-col').length > 0) {
                        var nextNext = rowFocusable.eq(nextIndex + 1);
                        if (nextNext.length > 0) {
                            nextNext.focus().select();
                        } else {
                            var nextRow = $row.next('.item-row');
                            if (nextRow.length > 0) {
                                var nextRowInput = nextRow.find('input, select').filter(':visible:not(:disabled)').first();
                                if (nextRowInput.length > 0) nextRowInput.focus().select();
                            } else {
                                $('#addRowBtn').focus();
                            }
                        }
                    } else {
                        nextInput.focus().select();
                    }
                    return;
                }
            }
        }
        
        var focusable = $('#createPoForm').find('input, select, textarea, button, a').filter(function() {
            return $(this).is(':visible') && !$(this).prop('disabled') && $(this).attr('tabindex') !== '-1';
        });
        var currentIndex = focusable.index(target);
        if (currentIndex === -1) return;
        var nextIndex = currentIndex + 1;
        if (nextIndex < focusable.length) {
            var nextEl = focusable.eq(nextIndex);
            if (nextEl.attr('id') === 'addRowBtn' || nextEl.attr('id') === 'quickAddBtn') {
                var hasRows = $('#itemsBody tr').length > 0;
                if (hasRows) {
                    var lastRow = $('#itemsBody tr:last');
                    var lastInput = lastRow.find('input, select').filter(':visible:not(:disabled)').first();
                    if (lastInput.length > 0) { lastInput.focus().select(); return; }
                }
                var saveBtn = $('#savePoBtn');
                if (saveBtn.length > 0) { saveBtn.focus(); return; }
            }
            nextEl.focus().select();
        } else {
            var firstRow = $('#itemsBody tr:first');
            if (firstRow.length > 0) {
                var firstInput = firstRow.find('input, select').filter(':visible:not(:disabled)').first();
                if (firstInput.length > 0) { firstInput.focus().select(); return; }
            }
            $('#addRowBtn').focus();
        }
    });
    
    // ─── AJAX Select2 helpers ───
    function ajaxSelect2(url, placeholder, extraData) {
        return {
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 300,
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
                    return { results: data.results, pagination: { more: data.pagination.more } };
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
        var qty = data.quantity || 1;
        return `
            <tr class="item-row" data-row-index="${index}">
                <td><input type="text" name="items[${index}][system_code]" value="${data.system_code || ''}" placeholder="SysCode" class="asb-input" tabindex="-1"></td>
                <td>
                    <input type="text" name="items[${index}][item_code]" class="item_code_input asb-input" value="${data.item_code || ''}" placeholder="Item Code" required tabindex="-1">
                    <select name="items[${index}][item_id]" class="item-search" style="width:100%; margin-top:4px;" tabindex="-1">
                        <option value="">-- Search & Auto‑fill --</option>
                        ${data.item_id ? `<option value="${data.item_id}" selected>${data.item_code} - ${data.item_name}</option>` : ''}
                    </select>
                </td>
                <td>
                    <input type="text" name="items[${index}][item_name]" class="item_name_input asb-input" value="${data.item_name || ''}" placeholder="Item Name" required tabindex="-1">
                    <select name="items[${index}][suggestion]" class="name-suggestion" style="width:100%; margin-top:4px;" tabindex="-1">
                        <option value="">-- Suggested Names --</option>
                    </select>
                </td>
                <td>
                    <select name="items[${index}][department_id]" class="dept-select" style="width:100%;" tabindex="-1">
                        <option value="">--</option>
                        ${data.department_id ? `<option value="${data.department_id}" selected>${data.department_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][sub_department_id]" class="subdept-select" style="width:100%;" tabindex="-1">
                        <option value="">--</option>
                        ${data.sub_department_id ? `<option value="${data.sub_department_id}" selected>${data.sub_department_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][category_id]" class="cat-select" style="width:100%;" tabindex="-1">
                        <option value="">--</option>
                        ${data.category_id ? `<option value="${data.category_id}" selected>${data.category_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][color_id]" class="color-select" style="width:100%;" tabindex="-1">
                        <option value="">--</option>
                        ${data.color_id ? `<option value="${data.color_id}" selected>${data.color_name || ''}</option>` : ''}
                    </select>
                </td>
                <td>
                    <select name="items[${index}][size_id]" class="size-select" style="width:100%;" tabindex="-1">
                        <option value="">--</option>
                        ${data.size_id ? `<option value="${data.size_id}" selected>${data.size_name || ''}</option>` : ''}
                    </select>
                </td>
                <td><input type="number" step="0.01" name="items[${index}][cost]" value="${data.cost || 0}" placeholder="0.00" class="asb-input" tabindex="-1"></td>
                <td><input type="number" step="0.01" name="items[${index}][selling]" value="${data.selling || 0}" placeholder="0.00" class="asb-input" tabindex="-1"></td>
                <td><input type="number" name="items[${index}][quantity]" class="qty-input asb-input" min="1" value="${qty}" placeholder="1" required tabindex="-1"></td>
                <td class="action-col">
                    <button type="button" class="remove-row" title="Remove" tabindex="-1"><i class="fas fa-times"></i></button>
                </td>
            </tr>
        `;
    }

    // ─── Grand total update ───
    function updateGrandTotal() {
        var total = 0, count = 0;
        $('.qty-input').each(function() {
            var val = parseInt($(this).val()) || 0;
            total += val;
            if (val > 0) count++;
        });
        $('#totalQty').text(total);
        $('#item-counter').text(count + ' items');
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

    // ─── Initialize Select2 for a row ───
    function initRowSelects($row, draftData) {
        draftData = draftData || {};
        var $deptSelect = $row.find('.dept-select');
        var $itemSelect = $row.find('.item-search');
        var $suggestionSelect = $row.find('.name-suggestion');

        function getSupplierId() { return $supplier.val() || ''; }

        $itemSelect.select2({
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 350,
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
                    return { results: data.results, pagination: { more: data.pagination.more } };
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
            debugLog('Item selected: ' + data.item_code + ' - ' + data.item_name);
            setTimeout(function() {
                var qtyInput = $row.find('.qty-input');
                if (qtyInput.length > 0) qtyInput.focus().select();
            }, 50);
        });

        if (draftData.item_id) {
            var option = new Option(draftData.item_code + ' - ' + draftData.item_name, draftData.item_id, true, true);
            $itemSelect.append(option).trigger('change');
            $itemSelect.val(draftData.item_id).trigger('change');
        }

        $supplier.on('change', function() {
            $('.item-search').each(function() { $(this).val(null).trigger('change'); });
        });

        function refreshSuggestions() {
            var deptId = $deptSelect.val() || '';
            $suggestionSelect.select2({
                ajax: {
                    url: window.location.href,
                    dataType: 'json',
                    delay: 300,
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
                        return { results: data.results, pagination: { more: data.pagination.more } };
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
                debugLog('Suggestion selected: ' + data.text);
                setTimeout(function() {
                    var qtyInput = $row.find('.qty-input');
                    if (qtyInput.length > 0) qtyInput.focus().select();
                }, 50);
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
    }

    // ─── Load and restore draft ───
    function applyDraftToForm(draft) {
        if (!draft) return;
        debugLog('Applying draft: ' + draft.draft_id);
        if (draft.supplier_id) {
            var supplierOption = new Option(draft.supplier_name, draft.supplier_id, true, true);
            $supplier.append(supplierOption).trigger('change');
            $supplier.val(draft.supplier_id).trigger('change');
        }
        $('#purchase_date').val(draft.purchase_date);
        $('#expected_delivery_date').val(draft.expected_delivery_date || '');
        $('#status').val(draft.status);
        $('#attention').val(draft.attention || '');
        $('#remarks').val(draft.remarks || '');

        $('#itemsBody').empty();
        if (draft.items && draft.items.length > 0) {
            $.each(draft.items, function(i, item) {
                var data = {
                    system_code: item.system_code,
                    item_code: item.item_code,
                    item_name: item.item_name,
                    department_id: item.department_id,
                    sub_department_id: item.sub_department_id,
                    category_id: item.category_id,
                    color_id: item.color_id,
                    size_id: item.size_id,
                    cost: item.cost,
                    selling: item.selling,
                    quantity: item.quantity || 1,
                    item_id: item.item_id
                };
                var newRowHtml = createItemRow(rowIndex, data);
                var $newRow = $(newRowHtml);
                $('#itemsBody').append($newRow);
                initRowSelects($newRow, {
                    item_id: item.item_id,
                    item_code: item.item_code,
                    item_name: item.item_name
                });
                rowIndex++;
            });
            updateGrandTotal();
        } else {
            $('#itemsBody').append(createItemRow(rowIndex));
            initRowSelects($('#itemsBody tr:first'));
            rowIndex++;
        }
        $('#autosave-status').html('<i class="fas fa-cloud-upload-alt"></i> Draft restored');
        debugLog('Draft applied with ' + (draft.items ? draft.items.length : 0) + ' items');
    }

    // ─── Page load: check for draft ───
    function checkForDraftOnLoad() {
        debugLog('Checking for saved draft...');
        perfStart('load_draft');
        $.ajax({
            url: window.location.href,
            method: 'GET',
            data: { ajax_action: 'load_draft' },
            dataType: 'json',
            timeout: 10000,
            success: function(resp) {
                perfEnd('load_draft');
                debugLog('Draft check response received');
                if (resp.success && resp.draft) {
                    var draft = resp.draft;
                    if (confirm('A saved draft was found from ' + draft.updated_at + '.\nDo you want to restore it?')) {
                        applyDraftToForm(draft);
                    } else {
                        if ($('#itemsBody tr').length === 0) {
                            $('#itemsBody').append(createItemRow(rowIndex));
                            initRowSelects($('#itemsBody tr:first'));
                            rowIndex++;
                        }
                    }
                } else {
                    if ($('#itemsBody tr').length === 0) {
                        $('#itemsBody').append(createItemRow(rowIndex));
                        initRowSelects($('#itemsBody tr:first'));
                        rowIndex++;
                    }
                }
                perfEnd('page_load');
            },
            error: function() {
                perfEnd('load_draft');
                if ($('#itemsBody tr').length === 0) {
                    $('#itemsBody').append(createItemRow(rowIndex));
                    initRowSelects($('#itemsBody tr:first'));
                    rowIndex++;
                }
                perfEnd('page_load');
            }
        });
    }

    // ─── Manual Restore Draft button ───
    $('#restoreDraftBtn').on('click', function() {
        debugLog('Manual restore draft clicked');
        perfStart('restore_draft');
        $.ajax({
            url: window.location.href,
            method: 'GET',
            data: { ajax_action: 'load_draft' },
            dataType: 'json',
            timeout: 10000,
            success: function(resp) {
                perfEnd('restore_draft');
                debugLog('Restore response received');
                if (resp.success && resp.draft) {
                    if (confirm('Restore the last saved draft? It will replace the current form data.')) {
                        applyDraftToForm(resp.draft);
                    }
                } else {
                    alert('No draft found to restore.');
                }
            },
            error: function() {
                perfEnd('restore_draft');
                alert('Error loading draft.');
            }
        });
    });

    // ─── Auto-save function ───
    var autoSaveTimer = null;
    var isSaving = false;
    
    function autoSaveDraft() {
        if (isSaving) { debugLog('Auto-save already in progress, skipping'); return; }
        
        var $status = $('#autosave-status');
        $status.html('<span class="loading-spinner"></span> Saving...').addClass('saving').removeClass('saved error');
        debugLog('Auto-save started');
        isSaving = true;
        perfStart('auto_save');

        var form = document.getElementById('createPoForm');
        var formData = new FormData(form);
        formData.append('ajax_action', 'auto_save_draft');

        var items = [];
        $('#itemsBody tr').each(function(idx) {
            var $row = $(this);
            var code = $row.find('.item_code_input').val();
            var name = $row.find('.item_name_input').val();
            var qty = parseInt($row.find('.qty-input').val()) || 0;
            if (!code || !name || qty <= 0) return;

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
                quantity: qty
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
        });

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 30000,
            success: function(resp) {
                perfEnd('auto_save');
                debugLog('Auto-save response received');
                if (resp.success) {
                    $status.html('<i class="fas fa-check-circle"></i> Auto-saved at ' + new Date().toLocaleTimeString()).removeClass('saving').addClass('saved');
                } else {
                    var errMsg = resp.error || resp.message || 'Unknown error';
                    $status.html('<i class="fas fa-exclamation-triangle"></i> Auto-save failed: ' + errMsg).removeClass('saving').addClass('error');
                    debugLog('Auto-save error: ' + errMsg);
                }
                isSaving = false;
            },
            error: function() {
                perfEnd('auto_save');
                $status.html('<i class="fas fa-exclamation-triangle"></i> Auto-save error').removeClass('saving').addClass('error');
                debugLog('Auto-save AJAX error');
                isSaving = false;
            }
        });
    }

    function triggerAutoSave() {
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function() {
            autoSaveTimer = null;
            autoSaveDraft();
        }, 2000);
    }

    // ─── Initialise ───
    checkForDraftOnLoad();

    // ─── Add row button ───
    $('#addRowBtn').on('click', function() {
        debugLog('Add row button clicked');
        perfStart('add_row');
        var newRowHtml = createItemRow(rowIndex);
        var $newRow = $(newRowHtml);
        $('#itemsBody').append($newRow);
        initRowSelects($newRow);
        rowIndex++;
        updateGrandTotal();
        perfEnd('add_row');
        setTimeout(function() { $newRow.find('.item-search').focus(); }, 300);
        triggerAutoSave();
    });

    // ─── Quick Add 10 rows ───
    $('#quickAddBtn').on('click', function() {
        debugLog('Quick add 10 rows');
        perfStart('quick_add');
        for (var i = 0; i < 10; i++) {
            var newRowHtml = createItemRow(rowIndex);
            var $newRow = $(newRowHtml);
            $('#itemsBody').append($newRow);
            initRowSelects($newRow);
            rowIndex++;
        }
        updateGrandTotal();
        perfEnd('quick_add');
        triggerAutoSave();
    });

    $(document).on('input', '.qty-input', function() {
        updateGrandTotal();
        triggerAutoSave();
    });

    $(document).on('change', '.item_code_input, .item_name_input, .dept-select, .subdept-select, .cat-select, .color-select, .size-select', function() {
        triggerAutoSave();
    });

    $(document).on('click', '.remove-row', function() {
        var $row = $(this).closest('tr');
        if ($('#itemsBody tr').length > 1) {
            perfStart('remove_row');
            $row.remove();
            updateGrandTotal();
            perfEnd('remove_row');
            debugLog('Row removed');
            triggerAutoSave();
        } else {
            alert('You must keep at least one item row.');
        }
    });

    // ─── Save button triggers confirmation modal ───
    $('#savePoBtn').on('click', function() {
        debugLog('Create PO button clicked');
        var supplier = $supplier.val();
        var date = $('#purchase_date').val();
        if (!supplier || !date) {
            alert('Please fill in Supplier and Purchase Date.');
            $('#supplier_id').focus();
            return;
        }
        var valid = false, itemCount = 0, totalQty = 0;
        $('#itemsBody tr').each(function() {
            var $row = $(this);
            var code = $row.find('.item_code_input').val();
            var name = $row.find('.item_name_input').val();
            var qty = parseInt($row.find('.qty-input').val()) || 0;
            if (code && name && qty > 0) {
                valid = true;
                itemCount++;
                totalQty += qty;
            }
        });
        if (!valid) {
            alert('Please add at least one item with a code, name, and positive quantity.');
            return;
        }
        debugLog('Validation passed. Items: ' + itemCount + ', Total Qty: ' + totalQty);
        
        var supplierName = $supplier.select2('data')[0]?.text || 'Selected Supplier';
        $('#modalSummary').html(
            '<strong>PO Summary</strong><br>' +
            'Supplier: ' + supplierName + '<br>' +
            'Purchase Date: ' + date + '<br>' +
            'Total Items: ' + itemCount + '<br>' +
            'Total Quantity: ' + totalQty
        );
        
        $('#confirmModal').css('display', 'flex');
        $('#modalError').text('');
        $('#modalDebug').hide();
    });

    window.closeConfirmModal = function() {
        $('#confirmModal').css('display', 'none');
    };

    window.submitSave = function() {
        debugLog('Submit save called');
        perfStart('save_po');
        var form = document.getElementById('createPoForm');
        var formData = new FormData(form);
        formData.append('ajax_action', 'save_new_po');

        var items = [];
        $('#itemsBody tr').each(function(idx) {
            var $row = $(this);
            var code = $row.find('.item_code_input').val();
            var name = $row.find('.item_name_input').val();
            if (!code || !name) return;
            var qty = parseInt($row.find('.qty-input').val()) || 0;
            if (qty <= 0) return;

            items.push({
                system_code: $row.find('input[name*="[system_code]"]').val() || '',
                item_code: code,
                item_name: name,
                department_id: $row.find('.dept-select').val() || '',
                sub_department_id: $row.find('.subdept-select').val() || '',
                category_id: $row.find('.cat-select').val() || '',
                color_id: $row.find('.color-select').val() || '',
                size_id: $row.find('.size-select').val() || '',
                cost: parseFloat($row.find('input[name*="[cost]"]').val()) || 0,
                selling: parseFloat($row.find('input[name*="[selling]"]').val()) || 0,
                quantity: qty,
                received_qty: 0
            });
        });

        debugLog('Items to save: ' + items.length);
        if (items.length > 0) debugLog('First item: ' + JSON.stringify(items[0]));

        formData.delete('items');
        formData.append('items_json', JSON.stringify(items));

        $('#modalDebug').show().html('Sending ' + items.length + ' items to server...<br><span class="loading-spinner"></span> Processing...');

        var $btn = $('#confirmModal .btn-asb');
        $btn.prop('disabled', true).html('<span class="loading-spinner"></span> Creating...');

        debugLog('Sending AJAX request to server...');
        
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 300000,
            success: function(data) {
                var duration = perfEnd('save_po');
                debugLog('Server response received');
                $btn.prop('disabled', false).html('Confirm Create');
                if (data.success) {
                    alert('✅ ' + data.message + '\n' + 
                          'PO Number: ' + data.po_number + '\n' +
                          'Items: ' + (data.item_count || items.length) + '\n' +
                          'Time: ' + duration + 'ms');
                    window.location.href = 'pos.php';
                } else {
                    var errorMsg = data.message || 'Unknown error occurred. Check logs for details.';
                    $('#modalError').text('❌ ' + errorMsg);
                    $('#modalDebug').html('Error: ' + errorMsg + '<br>Duration: ' + duration + 'ms');
                    debugLog('Error response: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                var duration = perfEnd('save_po');
                debugLog('AJAX error: ' + status + ' - ' + error);
                var responseText = xhr.responseText || 'No response';
                debugLog('Server response: ' + responseText.substring(0, 500));
                $btn.prop('disabled', false).html('Confirm Create');
                $('#modalError').text('Network error: ' + status + ' - ' + error);
                $('#modalDebug').html('Status: ' + status + '<br>Error: ' + error + '<br>Duration: ' + duration + 'ms<br>Response: ' + responseText.substring(0, 300));
            }
        });
    };

    $('#confirmModal').on('click', function(e) {
        if (e.target === this) closeConfirmModal();
    });

    // ─── Auto-save timer ───
    setInterval(function() {
        var hasItems = $('#itemsBody tr').length > 0;
        var hasData = false;
        $('#itemsBody tr').each(function() {
            var code = $(this).find('.item_code_input').val();
            var name = $(this).find('.item_name_input').val();
            if (code || name) { hasData = true; return false; }
        });
        if (hasItems && hasData) autoSaveDraft();
    }, 30000);

    // ─── Initial grand total ───
    updateGrandTotal();
    
    debugLog('Page initialization complete');
    perfEnd('page_load');
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>