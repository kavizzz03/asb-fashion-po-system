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
 * Log a successful login with IP and User-Agent
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

/**
 * Check if current logged-in user is a "received" user (GRN only)
 */
function isReceivedUser() {
    if (!isset($_SESSION['user_id'])) return false;
    static $role = null;
    if ($role === null) {
        $role = getUserRole($_SESSION['user_id']);
    }
    return $role === 'received';
}

/**
 * Authenticate using plain text or MD5 password from po_users
 */
function authenticate($username, $password) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT id, password, role FROM po_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if ($user && ($password === $user['password'] || md5($password) === $user['password'])) {
        return $user;
    }
    return false;
}

// ============================================================
// ATOMIC MULTI-USER PO GENERATOR
// ============================================================

/**
 * Thread-safe PO Number Generator using row-level locking (FOR UPDATE)
 * Prevents race condition collisions across multiple user sessions.
 * 
 * Target Format: PO-YYYYMMDD-0002
 */
function generateAtomicPONumber($conn) {
    $today = date('Y-m-d');
    $datePrefix = date('Ymd');
    $prefix = "PO-{$datePrefix}-";
    
    $conn->query("CREATE TABLE IF NOT EXISTS `po_sequences` (
        `sequence_date` DATE NOT NULL,
        `last_number` INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`sequence_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $conn->prepare("SELECT last_number FROM po_sequences WHERE sequence_date = ? FOR UPDATE");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    if ($res) {
        $nextSeq = (int)$res['last_number'] + 1;
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

    return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
}

// ============================================================
// CATALOG TAXONOMY FUNCTIONS (Departments, SubDepts, Categories, Colors, Sizes)
// ============================================================

function getDepartments() {
    $conn = getConnection();
    $result = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getSubDepartments($department_id = null) {
    $conn = getConnection();
    if ($department_id) {
        $stmt = $conn->prepare("SELECT sub_department_id, sub_department_name FROM sub_departments WHERE department_id = ? ORDER BY sub_department_name");
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $result = $conn->query("SELECT sub_department_id, sub_department_name FROM sub_departments ORDER BY sub_department_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getCategories() {
    $conn = getConnection();
    $result = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getColors() {
    $conn = getConnection();
    $result = $conn->query("SELECT color_id, color_name FROM colors ORDER BY color_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getSizes() {
    $conn = getConnection();
    $result = $conn->query("SELECT size_id, size_name FROM sizes ORDER BY size_name");
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// ============================================================
// DRAFT SYSTEM (Auto-Backup & Manual Restore Helpers)
// ============================================================

/**
 * Check if the active session has an existing draft with saved line items.
 */
function getActiveSessionDraftCount() {
    startSession();
    $sessionId = session_id();
    if (empty($sessionId)) return 0;
    
    $conn = getConnection();
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(pdi.draft_item_id) as total 
            FROM po_draft_items pdi 
            JOIN po_draft pd ON pdi.draft_id = pd.draft_id 
            WHERE pd.session_id = ?
        ");
        $stmt->bind_param("s", $sessionId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($res['total'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Retrieve draft data for the current session to print or restore.
 */
function getSessionDraftData() {
    startSession();
    $sessionId = session_id();
    if (empty($sessionId)) return null;

    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM po_draft WHERE session_id = ?");
    $stmt->bind_param("s", $sessionId);
    $stmt->execute();
    $draft = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($draft) {
        $itemStmt = $conn->prepare("
            SELECT pdi.*, 
                   d.department_name, sd.sub_department_name, c.category_name, col.color_name, s.size_name
            FROM po_draft_items pdi
            LEFT JOIN departments d ON pdi.department_id = d.department_id
            LEFT JOIN sub_departments sd ON pdi.sub_department_id = sd.sub_department_id
            LEFT JOIN categories c ON pdi.category_id = c.category_id
            LEFT JOIN colors col ON pdi.color_id = col.color_id
            LEFT JOIN sizes s ON pdi.size_id = s.size_id
            WHERE pdi.draft_id = ?
        ");
        $itemStmt->bind_param("i", $draft['draft_id']);
        $itemStmt->execute();
        $draft['items'] = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemStmt->close();
    }

    return $draft;
}

// ============================================================
// SUPPLIER FUNCTIONS
// ============================================================

function getSuppliers($search = '', $limit = 50, $offset = 0) {
    $conn = getQcConnection();
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
// ITEMS LINKED TO SUPPLIER
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
// ALL ITEMS (Master List Catalog)
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
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
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
    $stmt = $conn->prepare("SELECT pi.*, i.item_name, i.item_code FROM po_items pi JOIN items i ON pi.item_id = i.item_id WHERE pi.po_id = ?");
    $stmt->bind_param("i", $poId);
    $stmt->execute();
    $po['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $po;
}

// ============================================================
// STATISTICS & METRICS
// ============================================================

function getPOStats() {
    $conn = getConnection();
    try {
        $res = $conn->query("SELECT status, COUNT(*) as count FROM po_header GROUP BY status");
        if ($res === false) throw new Exception($conn->error);
        $stats = ['total' => 0, 'pending' => 0, 'received' => 0, 'completed' => 0, 'cancelled' => 0];
        while ($row = $res->fetch_assoc()) {
            $key = strtolower($row['status']);
            if (isset($stats[$key])) $stats[$key] = (int)$row['count'];
            $stats['total'] += (int)$row['count'];
        }
        return $stats;
    } catch (Exception $e) {
        error_log("getPOStats error: " . $e->getMessage());
        return ['total' => 0, 'pending' => 0, 'received' => 0, 'completed' => 0, 'cancelled' => 0];
    }
}

function getTotalSuppliers() {
    $conn = getQcConnection();
    try {
        $result = $conn->query("SELECT COUNT(*) as total FROM suppliers");
        if ($result === false) throw new Exception($conn->error);
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    } catch (Exception $e) {
        error_log("getTotalSuppliers error: " . $e->getMessage());
        return 0;
    }
}

function getTotalPOs() {
    $conn = getConnection();
    try {
        $result = $conn->query("SELECT COUNT(*) as total FROM po_header");
        if ($result === false) throw new Exception($conn->error);
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    } catch (Exception $e) {
        error_log("getTotalPOs error: " . $e->getMessage());
        return 0;
    }
}

function getPendingPOs() {
    $conn = getConnection();
    try {
        $result = $conn->query("SELECT COUNT(*) as total FROM po_header WHERE status = 'Pending'");
        if ($result === false) throw new Exception($conn->error);
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    } catch (Exception $e) {
        error_log("getPendingPOs error: " . $e->getMessage());
        return 0;
    }
}

function getTotalItems() {
    $conn = getConnection();
    try {
        $result = $conn->query("SELECT COUNT(*) as total FROM items");
        if ($result === false) throw new Exception($conn->error);
        $row = $result->fetch_assoc();
        $result->free();
        return (int)($row['total'] ?? 0);
    } catch (Exception $e) {
        error_log("getTotalItems error: " . $e->getMessage());
        return 0;
    }
}

function getRecentPOs($limit = 5) {
    $conn = getConnection();
    try {
        $stmt = $conn->prepare("SELECT h.po_number, h.status, h.purchase_date, s.supplier_name 
                                FROM po_header h 
                                LEFT JOIN " . DB_QC . ".suppliers s ON h.supplier_id = s.supplier_id 
                                ORDER BY h.created_at DESC LIMIT ?");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("getRecentPOs error: " . $e->getMessage());
        return [];
    }
}

function getPOItemsWithBalances($po_id) {
    $conn = getConnection();
    try {
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
    } catch (Exception $e) {
        error_log("getPOItemsWithBalances error: " . $e->getMessage());
        return [];
    }
}

function generateGRNNumber() {
    return "GRN-" . date('Ymd') . "-" . rand(1000, 9999);
}

// ============================================================
// FORMAT HELPERS & BADGES
// ============================================================

function formatCurrency($amount) {
    return 'LKR ' . number_format($amount, 2);
}

function getSupplierStatusBadge($status) {
    $badgeClass = 'badge-secondary';
    if (strpos($status, 'New') !== false) $badgeClass = 'badge-info';
    elseif (strpos($status, 'Active') !== false) $badgeClass = 'badge-success';
    elseif (strpos($status, 'Inactive') !== false) $badgeClass = 'badge-danger';
    return '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($status) . '</span>';
}

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
// LOGIN LOGS & AUDIT TRAIL
// ============================================================

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
    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

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

function archiveOldLoginLogs($months = 6) {
    $conn = getConnection();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));
    
    $conn->query("CREATE TABLE IF NOT EXISTS po_login_logs_archive LIKE po_login_logs");
    
    $stmt = $conn->prepare("INSERT INTO po_login_logs_archive SELECT * FROM po_login_logs WHERE login_time < ?");
    $stmt->bind_param("s", $cutoff);
    $stmt->execute();
    $inserted = $stmt->affected_rows;
    $stmt->close();
    
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
    define('DB_QC', 'return_qc');
}