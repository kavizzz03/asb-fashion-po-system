<?php
// ============================================================
// Session & Authentication Helpers
// ============================================================

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn() {
    startSession();
    // Check for user_id set after login (using po_users)
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function logout() {
    startSession();
    $_SESSION = array();
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * Log a successful login
 */
function logLogin($userId, $ip = null, $userAgent = null) {
    if (!$ip) $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!$userAgent) $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $conn = getConnection();
    $stmt = $conn->prepare("INSERT INTO po_login_logs (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $ip, $userAgent);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get the current user's role
 */
function getUserRole($userId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT role FROM po_users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['role'] ?? null;
}

/**
 * Check if current logged-in user is admin
 */
function isAdmin() {
    if (!isset($_SESSION['user_id'])) return false;
    static $role = null;
    if ($role === null) {
        $role = getUserRole($_SESSION['user_id']);
    }
    return $role === 'admin';
}

// Authenticate using plain text password from po_users
function authenticate($username, $password) {
    $conn = getConnection(); // defined in config/database.php
    $stmt = $conn->prepare("SELECT id, password, role FROM po_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user && $password === $user['password']) {
        return $user; // returns the whole user row (id, role)
    }
    return false;
}

// ============================================================
// Status Badge Helpers (non‑conflicting names)
// ============================================================

/**
 * Generate badge HTML for supplier status (New, Active, Inactive)
 */
function getSupplierStatusBadge($status) {
    $badgeClass = 'badge-secondary';
    if (strpos($status, 'New') !== false) $badgeClass = 'badge-info';
    elseif (strpos($status, 'Active') !== false) $badgeClass = 'badge-success';
    elseif (strpos($status, 'Inactive') !== false) $badgeClass = 'badge-danger';
    return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>';
}

/**
 * Generate badge HTML for PO status (Pending, Received, etc.)
 */
function getStatusBadge($status) {
    $colors = [
        'Pending'   => 'badge-pending',
        'Received'  => 'badge-received',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled'
    ];
    $class = $colors[$status] ?? '';
    return "<span class='badge $class'>" . htmlspecialchars($status) . "</span>";
}

// ============================================================
// SUPPLIER FUNCTIONS (from return_qc)
// ============================================================

function getSuppliers($search = '', $limit = 50, $offset = 0) {
    $conn = getQcConnection(); // defined in config/database.php
    $query = "SELECT supplier_id, supplier_name, system_id, contact_number, email, address 
              FROM suppliers 
              WHERE 1=1";
    $params = [];
    $types = "";
    if ($search) {
        $query .= " AND (supplier_name LIKE ? OR contact_number LIKE ? OR email LIKE ? OR system_id LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "ssss";
    }
    $query .= " ORDER BY supplier_name LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset;
    $types .= "ii";
    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function countSuppliers($search = '') {
    $conn = getQcConnection();
    $query = "SELECT COUNT(*) as total FROM suppliers WHERE 1=1";
    $params = []; $types = "";
    if ($search) {
        $query .= " AND (supplier_name LIKE ? OR contact_number LIKE ? OR email LIKE ? OR system_id LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "ssss";
    }
    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getSupplierById($id) {
    $conn = getQcConnection();
    $stmt = $conn->prepare("SELECT supplier_id, supplier_name, system_id, contact_number, email, address FROM suppliers WHERE supplier_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ============================================================
// SUB‑DEPARTMENTS
// ============================================================

function getSubDepartments($department_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT sub_department_id, sub_department_name FROM sub_departments WHERE department_id = ? ORDER BY sub_department_name");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ============================================================
// ITEMS LINKED TO SUPPLIER (via supplier_items)
// ============================================================

function getSupplierItems($supplier_id, $search = '', $limit = 50, $offset = 0) {
    $conn = getConnection();
    $query = "SELECT i.item_id, i.item_code, i.system_code, i.item_name, 
                     si.cost_price, si.selling_price
              FROM items i
              INNER JOIN supplier_items si ON i.item_id = si.item_id
              WHERE si.supplier_id = ?";
    $params = [$supplier_id]; $types = "i";
    if ($search) {
        $query .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR i.system_code LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }
    $query .= " ORDER BY i.item_name LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= "ii";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function countSupplierItems($supplier_id, $search = '') {
    $conn = getConnection();
    $query = "SELECT COUNT(*) as total FROM items i INNER JOIN supplier_items si ON i.item_id = si.item_id WHERE si.supplier_id = ?";
    $params = [$supplier_id]; $types = "i";
    if ($search) {
        $query .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR i.system_code LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

// ============================================================
// ALL ITEMS (master list)
// ============================================================

function getItems($search = '', $limit = 10, $offset = 0) {
    $conn = getConnection();
    $query = "SELECT i.*, d.department_name, sd.sub_department_name, c.category_name, col.color_name, s.size_name
              FROM items i
              LEFT JOIN departments d ON i.department_id = d.department_id
              LEFT JOIN sub_departments sd ON i.sub_department_id = sd.sub_department_id
              LEFT JOIN categories c ON i.category_id = c.category_id
              LEFT JOIN colors col ON i.color_id = col.color_id
              LEFT JOIN sizes s ON i.size_id = s.size_id
              WHERE 1=1";
    $params = []; $types = "";
    if ($search) {
        $query .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR i.system_code LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }
    $query .= " ORDER BY i.item_name LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= "ii";
    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function countItems($search = '') {
    $conn = getConnection();
    $query = "SELECT COUNT(*) as total FROM items i WHERE 1=1";
    $params = []; $types = "";
    if ($search) {
        $query .= " AND (i.item_name LIKE ? OR i.item_code LIKE ? OR i.system_code LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }
    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

// ============================================================
// LOCATIONS
// ============================================================

function getLocations() {
    $conn = getConnection();
    $result = $conn->query("SELECT location_id, location_name FROM store_locations ORDER BY location_name");
    return $result->fetch_all(MYSQLI_ASSOC);
}

// ============================================================
// PURCHASE ORDERS
// ============================================================

function getPOs($status = '', $search = '', $limit = 10, $offset = 0) {
    $conn = getConnection();
    $query = "SELECT h.*, s.supplier_name 
              FROM po_header h 
              LEFT JOIN " . DB_QC . ".suppliers s ON h.supplier_id = s.supplier_id 
              WHERE 1=1";
    $params = []; $types = "";
    if ($status) { $query .= " AND h.status = ?"; $params[] = $status; $types .= "s"; }
    if ($search) {
        $query .= " AND (h.po_number LIKE ? OR s.supplier_name LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like;
        $types .= "ss";
    }
    $query .= " ORDER BY h.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset; $types .= "ii";
    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function countPOs($status = '', $search = '') {
    $conn = getConnection();
    $query = "SELECT COUNT(*) as total 
              FROM po_header h 
              LEFT JOIN " . DB_QC . ".suppliers s ON h.supplier_id = s.supplier_id 
              WHERE 1=1";
    $params = []; $types = "";
    if ($status) { $query .= " AND h.status = ?"; $params[] = $status; $types .= "s"; }
    if ($search) {
        $query .= " AND (h.po_number LIKE ? OR s.supplier_name LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like;
        $types .= "ss";
    }
    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

// ============================================================
// PO DETAILS
// ============================================================

function getPOById($poId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT h.*, s.supplier_name FROM po_header h LEFT JOIN " . DB_QC . ".suppliers s ON h.supplier_id = s.supplier_id WHERE h.po_id = ?");
    $stmt->bind_param("i", $poId);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();
    if (!$po) return null;
    $stmt = $conn->prepare("SELECT pi.*, i.item_name FROM po_items pi JOIN items i ON pi.item_id = i.item_id WHERE pi.po_id = ?");
    $stmt->bind_param("i", $poId);
    $stmt->execute();
    $po['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $po;
}

// ============================================================
// STATISTICS
// ============================================================

function getPOStats() {
    $conn = getConnection();
    $res = $conn->query("SELECT status, COUNT(*) as count FROM po_header GROUP BY status");
    $stats = ['total' => 0, 'pending' => 0, 'received' => 0, 'completed' => 0, 'cancelled' => 0];
    while ($row = $res->fetch_assoc()) {
        $key = strtolower($row['status']);
        if (isset($stats[$key])) $stats[$key] = (int)$row['count'];
        $stats['total'] += (int)$row['count'];
    }
    return $stats;
}

function getTotalSuppliers() {
    $conn = getQcConnection();
    $result = $conn->query("SELECT COUNT(*) as total FROM suppliers");
    return $result->fetch_assoc()['total'] ?? 0;
}

function getTotalPOs() {
    $conn = getConnection();
    $result = $conn->query("SELECT COUNT(*) as total FROM po_header");
    return $result->fetch_assoc()['total'] ?? 0;
}

function getPendingPOs() {
    $conn = getConnection();
    $result = $conn->query("SELECT COUNT(*) as total FROM po_header WHERE status = 'Pending'");
    return $result->fetch_assoc()['total'] ?? 0;
}

function getTotalItems() {
    $conn = getConnection();
    $result = $conn->query("SELECT COUNT(*) as total FROM items");
    return $result->fetch_assoc()['total'] ?? 0;
}

function getRecentPOs($limit = 5) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT h.po_number, h.status, h.purchase_date, s.supplier_name 
                            FROM po_header h 
                            LEFT JOIN " . DB_QC . ".suppliers s ON h.supplier_id = s.supplier_id 
                            ORDER BY h.created_at DESC LIMIT ?");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getPOItemsWithBalances($po_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT pi.*, i.item_name, i.item_code,
               (pi.quantity - pi.received_qty) AS remaining_qty
        FROM po_items pi
        JOIN items i ON pi.item_id = i.item_id
        WHERE pi.po_id = ?
    ");
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function generateGRNNumber() {
    return "GRN-" . date('Ymd') . "-" . rand(1000, 9999);
}

// ============================================================
// FORMAT HELPERS
// ============================================================

function formatCurrency($amount) {
    return 'LKR ' . number_format($amount, 2);
}

// ============================================================
// LOGIN LOGS – OPTIMIZED FOR LARGE DATASETS (1M+)
// ============================================================

/**
 * Fetch login logs with optional filters – optimized for large tables.
 * 
 * ⚡ Performance tips:
 * - Ensure indexes exist: 
 *   ALTER TABLE po_login_logs ADD INDEX idx_user_id (user_id);
 *   ALTER TABLE po_login_logs ADD INDEX idx_login_time (login_time);
 *   ALTER TABLE po_login_logs ADD INDEX idx_user_time (user_id, login_time);
 * - For deep pagination (> page 100), consider using a cursor (last_id) approach.
 */
function getLoginLogs($userId = null, $dateFrom = null, $dateTo = null, $search = '', $limit = 50, $offset = 0) {
    $conn = getConnection();
    $query = "SELECT l.id, l.login_time, l.ip_address, l.user_agent, u.username, u.role
              FROM po_login_logs l
              JOIN po_users u ON l.user_id = u.id
              WHERE 1=1";
    $params = [];
    $types = "";

    if ($userId) {
        $query .= " AND l.user_id = ?";
        $params[] = $userId;
        $types .= "i";
    }
    if ($dateFrom) {
        $query .= " AND DATE(l.login_time) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }
    if ($dateTo) {
        $query .= " AND DATE(l.login_time) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }
    if ($search) {
        // For large datasets, consider adding a FULLTEXT index on user_agent and ip_address
        $query .= " AND (u.username LIKE ? OR l.ip_address LIKE ? OR l.user_agent LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }

    $query .= " ORDER BY l.login_time DESC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Count login logs – uses indexed columns for speed.
 */
function countLoginLogs($userId = null, $dateFrom = null, $dateTo = null, $search = '') {
    $conn = getConnection();
    $query = "SELECT COUNT(*) as total
              FROM po_login_logs l
              JOIN po_users u ON l.user_id = u.id
              WHERE 1=1";
    $params = [];
    $types = "";

    if ($userId) {
        $query .= " AND l.user_id = ?";
        $params[] = $userId;
        $types .= "i";
    }
    if ($dateFrom) {
        $query .= " AND DATE(l.login_time) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }
    if ($dateTo) {
        $query .= " AND DATE(l.login_time) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }
    if ($search) {
        $query .= " AND (u.username LIKE ? OR l.ip_address LIKE ? OR l.user_agent LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }

    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['total'] ?? 0;
}

/**
 * Optimized pagination using "seek" method (cursor) for very large datasets.
 * Instead of OFFSET, use a last_seen_time and last_id to fetch the next page.
 * This is much faster for deep pagination (> 100,000 rows).
 *
 * @param int $userId        Filter by user ID
 * @param string $dateFrom   Start date (YYYY-MM-DD)
 * @param string $dateTo     End date (YYYY-MM-DD)
 * @param string $search     Search term (username, IP, user agent)
 * @param int $limit         Rows per page
 * @param string $lastTime   ISO timestamp of last record from previous page (for next page)
 * @param int $lastId        ID of last record from previous page (for tie-breaking)
 * @return array             Array of log entries
 */
function getLoginLogsCursor($userId = null, $dateFrom = null, $dateTo = null, $search = '', $limit = 50, $lastTime = null, $lastId = null) {
    $conn = getConnection();
    $query = "SELECT l.id, l.login_time, l.ip_address, l.user_agent, u.username, u.role
              FROM po_login_logs l
              JOIN po_users u ON l.user_id = u.id
              WHERE 1=1";
    $params = [];
    $types = "";

    if ($userId) {
        $query .= " AND l.user_id = ?";
        $params[] = $userId;
        $types .= "i";
    }
    if ($dateFrom) {
        $query .= " AND DATE(l.login_time) >= ?";
        $params[] = $dateFrom;
        $types .= "s";
    }
    if ($dateTo) {
        $query .= " AND DATE(l.login_time) <= ?";
        $params[] = $dateTo;
        $types .= "s";
    }
    if ($search) {
        $query .= " AND (u.username LIKE ? OR l.ip_address LIKE ? OR l.user_agent LIKE ?)";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }

    // Cursor condition: fetch rows with login_time < lastTime, or if same time, id < lastId
    if ($lastTime !== null && $lastId !== null) {
        $query .= " AND (l.login_time < ? OR (l.login_time = ? AND l.id < ?))";
        $params[] = $lastTime;
        $params[] = $lastTime;
        $params[] = $lastId;
        $types .= "ssi";
    }

    $query .= " ORDER BY l.login_time DESC, l.id DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Optionally archive old logs to keep the main table small.
 * Run this as a scheduled job (e.g., monthly).
 */
function archiveOldLoginLogs($months = 6) {
    $conn = getConnection();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
    
    // Create archive table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS po_login_logs_archive LIKE po_login_logs");
    
    // Move old records to archive
    $stmt = $conn->prepare("INSERT INTO po_login_logs_archive SELECT * FROM po_login_logs WHERE login_time < ?");
    $stmt->bind_param("s", $cutoff);
    $stmt->execute();
    $inserted = $stmt->affected_rows;
    $stmt->close();
    
    // Delete moved records from main table
    if ($inserted > 0) {
        $stmt = $conn->prepare("DELETE FROM po_login_logs WHERE login_time < ?");
        $stmt->bind_param("s", $cutoff);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        return "Archived $inserted records, deleted $deleted from main table.";
    }
    return "No records to archive (cutoff: $cutoff).";
}

// ============================================================
// ENSURE REQUIRED CONSTANTS ARE DEFINED
// ============================================================
if (!defined('DB_QC')) {
    // Define the QC database name if not already set
    // This should match the constant in config/database.php
    define('DB_QC', 'return_qc');
}