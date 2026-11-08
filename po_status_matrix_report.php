<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';
requireLogin();
/**
 * PO Status Matrix Ledger & High-Capacity Audit System - ASB Fashion
 * Designed & Developed by Vexel IT by Kavizz
 * Enterprise-grade optimization for Large Dataset Handling (2M+ records)
 */

date_default_timezone_set('Asia/Colombo');

$dbHost = '127.0.0.1';
$dbName = 'po_system';
$dbUser = 'root';
$dbPass = '';

if (!defined('MAX_EXPORT_ROWS')) define('MAX_EXPORT_ROWS', 250000);
if (!defined('PAGINATION_LIMIT')) define('PAGINATION_LIMIT', 40);
if (!defined('QUERY_TIMEOUT')) define('QUERY_TIMEOUT', 600);

@ini_set('memory_limit', '1024M');
@set_time_limit(QUERY_TIMEOUT);

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            PDO::ATTR_TIMEOUT => QUERY_TIMEOUT,
        ]
    );
    $pdo->exec("SET SESSION wait_timeout = " . QUERY_TIMEOUT);
    $pdo->exec("SET SESSION interactive_timeout = " . QUERY_TIMEOUT);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ===== GET FILTER VALUES & DATE PRESETS =====
$preset = isset($_GET['preset']) ? trim($_GET['preset']) : '';
$fromDate = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$toDate   = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

// If preset is explicitly chosen, calculate date range
if ($preset === 'today') {
    $fromDate = date('Y-m-d');
    $toDate   = date('Y-m-d');
} elseif ($preset === '7days') {
    $fromDate = date('Y-m-d', strtotime('-7 days'));
    $toDate   = date('Y-m-d');
} elseif ($preset === '30days') {
    $fromDate = date('Y-m-d', strtotime('-30 days'));
    $toDate   = date('Y-m-d');
} elseif ($preset === 'this_month') {
    $fromDate = date('Y-m-01');
    $toDate   = date('Y-m-t');
} elseif ($preset === 'last_month') {
    $fromDate = date('Y-m-01', strtotime('first day of last month'));
    $toDate   = date('Y-m-t', strtotime('last day of last month'));
} elseif ($preset === 'this_year') {
    $fromDate = date('Y-01-01');
    $toDate   = date('Y-12-31');
} elseif ($preset === 'all') {
    $fromDate = '';
    $toDate   = '';
} elseif (empty($preset) && !isset($_GET['from_date']) && !isset($_GET['to_date'])) {
    // Default initial visit: All Time so that 100% of data is visible immediately
    $preset = 'all';
    $fromDate = '';
    $toDate   = '';
}

$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit        = isset($_GET['limit']) ? min(200, max(10, (int)$_GET['limit'])) : PAGINATION_LIMIT;
$offset       = ($page - 1) * $limit;
$exportCSV    = isset($_GET['export']) && $_GET['export'] === 'csv';
$supplierIds  = isset($_GET['supplier_ids']) && is_array($_GET['supplier_ids']) ? array_map('intval', $_GET['supplier_ids']) : [];

// Helper function to build safe IN clause
if (!function_exists('buildInClause')) {
    function buildInClause($ids, $prefix, &$params, &$paramIndex) {
        if (empty($ids)) return null;
        $placeholders = [];
        foreach ($ids as $id) {
            $name = $prefix . $paramIndex++;
            $placeholders[] = ":$name";
            $params[$name] = $id;
        }
        return implode(',', $placeholders);
    }
}

// Build WHERE conditions safely
if (!function_exists('buildWhereClause')) {
    function buildWhereClause($filters, &$params) {
        $conditions = [
            'ph.status != "Cancelled"'
        ];
        $paramIndex = 0;

        // Date filtering
        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $conditions[] = 'ph.purchase_date BETWEEN :from_date AND :to_date';
            $params['from_date'] = $filters['from_date'];
            $params['to_date']   = $filters['to_date'];
        } elseif (!empty($filters['from_date'])) {
            $conditions[] = 'ph.purchase_date >= :from_date';
            $params['from_date'] = $filters['from_date'];
        } elseif (!empty($filters['to_date'])) {
            $conditions[] = 'ph.purchase_date <= :to_date';
            $params['to_date'] = $filters['to_date'];
        }

        // Status filtering
        if (!empty($filters['status_filter']) && in_array($filters['status_filter'], ['Pending', 'Received', 'Completed'])) {
            $conditions[] = 'ph.status = :status_filter';
            $params['status_filter'] = $filters['status_filter'];
        }

        // Supplier multi-select
        if (!empty($filters['supplier_ids'])) {
            $in = buildInClause($filters['supplier_ids'], 'sup', $params, $paramIndex);
            $conditions[] = "ph.supplier_id IN ($in)";
        }

        // Keyword Search (Supplier Name, System ID, or PO Number)
        if (!empty($filters['search'])) {
            $conditions[] = "(s.supplier_name LIKE :search_sup OR s_qc.supplier_name LIKE :search_qc OR ph.po_number LIKE :search_po)";
            $params['search_sup'] = "%{$filters['search']}%";
            $params['search_qc']  = "%{$filters['search']}%";
            $params['search_po']  = "%{$filters['search']}%";
        }

        return 'WHERE ' . implode(' AND ', $conditions);
    }
}

$filters = [
    'from_date'     => $fromDate,
    'to_date'       => $toDate,
    'status_filter' => $statusFilter,
    'search'        => $search,
    'supplier_ids'  => $supplierIds
];

$params = [];
$whereClause = buildWhereClause($filters, $params);

// ============================================================
// 1. OVERALL KPI TOTALS (High-Speed Index Aggregation)
// ============================================================
$totalsSql = "
    SELECT
        COUNT(DISTINCT ph.po_id) AS total_pos,
        COUNT(DISTINCT ph.supplier_id) AS total_suppliers,
        COALESCE(SUM(CASE WHEN ph.status = 'Completed' THEN pi.quantity ELSE 0 END), 0) AS completed_qty,
        COALESCE(SUM(CASE WHEN ph.status = 'Pending' THEN pi.quantity ELSE 0 END), 0) AS pending_qty,
        COALESCE(SUM(CASE WHEN ph.status = 'Received' THEN pi.quantity ELSE 0 END), 0) AS received_qty,
        COALESCE(SUM(pi.quantity), 0) AS grand_total_qty,
        COALESCE(SUM(pi.received_qty), 0) AS grand_actual_received_qty,
        COALESCE(SUM(pi.quantity * pi.cost_price), 0) AS grand_total_cost
    FROM po_header ph
    LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
    LEFT JOIN return_qc.suppliers s_qc ON ph.supplier_id = s_qc.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    $whereClause
";

$stmtTotals = $pdo->prepare($totalsSql);
$stmtTotals->execute($params);
$overall = $stmtTotals->fetch() ?: [
    'total_pos' => 0,
    'total_suppliers' => 0,
    'completed_qty' => 0,
    'pending_qty' => 0,
    'received_qty' => 0,
    'grand_total_qty' => 0,
    'grand_actual_received_qty' => 0,
    'grand_total_cost' => 0
];

// ============================================================
// 2. CSV / EXCEL STREAMING EXPORT HANDLER (Zero-Memory for 2M+ Records)
// ============================================================
if ($exportCSV) {
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ASB_PO_Matrix_Report_' . date('Y-m-d_His') . '.csv"');
    }
    
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Report Title & Metadata Banner
    fputcsv($output, ['ASB Fashion - PO Status Matrix Ledger & Audit Report']);
    fputcsv($output, [
        'Filter Period:', 
        (!empty($fromDate) && !empty($toDate) ? "From: $fromDate To: $toDate" : 'All Time / Unrestricted'),
        'Generated On:', 
        date('Y-m-d H:i:s')
    ]);
    fputcsv($output, [
        'Total Suppliers:', number_format($overall['total_suppliers']),
        'Total Active POs:', number_format($overall['total_pos']),
        'Grand Total Units:', number_format($overall['grand_total_qty']),
        'Units Received:', number_format($overall['grand_actual_received_qty']),
        'Total Value (LKR):', number_format($overall['grand_total_cost'], 2)
    ]);
    fputcsv($output, []); // Blank separator row
    
    // Column Headers
    fputcsv($output, [
        'Row Type',
        'Supplier / PO Identifier',
        'Status',
        'Order Date',
        'Expected Delivery Date',
        'Completed PO Qty',
        'Pending PO Qty',
        'Partially Received PO Qty',
        'Actual Units Received',
        'Total Ordered Units',
        'Total Value / Cost (LKR)'
    ]);

    // Stream line by line with unbuffered query to handle 2M+ records
    $streamSql = "
        SELECT 
            ph.supplier_id,
            COALESCE(s.supplier_name, s_qc.supplier_name, CONCAT('Supplier #', ph.supplier_id)) AS supplier_name,
            ph.po_id,
            ph.po_number,
            ph.status,
            ph.purchase_date,
            ph.expected_delivery_date,
            SUM(pi.quantity) AS total_po_qty,
            SUM(pi.received_qty) AS total_received_qty,
            SUM(pi.quantity * pi.cost_price) AS total_po_cost
        FROM po_header ph
        LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
        LEFT JOIN return_qc.suppliers s_qc ON ph.supplier_id = s_qc.supplier_id
        INNER JOIN po_items pi ON ph.po_id = pi.po_id
        $whereClause
        GROUP BY ph.supplier_id, supplier_name, ph.po_id, ph.po_number, ph.status, ph.purchase_date, ph.expected_delivery_date
        ORDER BY supplier_name ASC, ph.purchase_date DESC
    ";

    $stmtStream = $pdo->prepare($streamSql);
    $stmtStream->execute($params);

    $currentSupId = null;
    $supBuffer = [];
    $supTotals = [
        'name' => '',
        'completed_qty' => 0,
        'pending_qty' => 0,
        'received_qty' => 0,
        'actual_received' => 0,
        'grand_total' => 0,
        'total_cost' => 0,
        'po_count' => 0
    ];

    $flushSupplier = function() use (&$output, &$supTotals, &$supBuffer) {
        if ($supTotals['po_count'] === 0) return;
        
        // 1. Output Supplier Summary Row
        fputcsv($output, [
            'SUPPLIER SUMMARY',
            $supTotals['name'] . ' (' . $supTotals['po_count'] . ' POs)',
            'ACTIVE',
            '—',
            '—',
            $supTotals['completed_qty'],
            $supTotals['pending_qty'],
            $supTotals['received_qty'],
            $supTotals['actual_received'],
            $supTotals['grand_total'],
            number_format($supTotals['total_cost'], 2, '.', '')
        ]);

        // 2. Output Individual PO Detailed Breakdown Rows
        foreach ($supBuffer as $po) {
            fputcsv($output, [
                '   -> PO DETAIL',
                '   ' . $po['po_number'],
                $po['status'],
                $po['purchase_date'],
                $po['expected_delivery_date'] ?? 'N/A',
                ($po['status'] === 'Completed' ? $po['total_po_qty'] : '0'),
                ($po['status'] === 'Pending' ? $po['total_po_qty'] : '0'),
                ($po['status'] === 'Received' ? $po['total_po_qty'] : '0'),
                $po['total_received_qty'],
                $po['total_po_qty'],
                number_format($po['total_po_cost'], 2, '.', '')
            ]);
        }

        // Reset
        $supBuffer = [];
        $supTotals = [
            'name' => '',
            'completed_qty' => 0,
            'pending_qty' => 0,
            'received_qty' => 0,
            'actual_received' => 0,
            'grand_total' => 0,
            'total_cost' => 0,
            'po_count' => 0
        ];
    };

    while ($row = $stmtStream->fetch(PDO::FETCH_ASSOC)) {
        if ($currentSupId !== null && $currentSupId !== $row['supplier_id']) {
            $flushSupplier();
        }

        $currentSupId = $row['supplier_id'];
        $supTotals['name'] = $row['supplier_name'];
        $supTotals['po_count']++;
        $supTotals['grand_total'] += (int)$row['total_po_qty'];
        $supTotals['actual_received'] += (int)$row['total_received_qty'];
        $supTotals['total_cost'] += (float)$row['total_po_cost'];

        if ($row['status'] === 'Completed') {
            $supTotals['completed_qty'] += (int)$row['total_po_qty'];
        } elseif ($row['status'] === 'Pending') {
            $supTotals['pending_qty'] += (int)$row['total_po_qty'];
        } elseif ($row['status'] === 'Received') {
            $supTotals['received_qty'] += (int)$row['total_po_qty'];
        }

        $supBuffer[] = $row;
    }
    $flushSupplier();

    // Overall Grand Total Footer in CSV
    fputcsv($output, []);
    fputcsv($output, [
        'OVERALL GRAND TOTAL',
        'All Suppliers (' . $overall['total_suppliers'] . ')',
        'OVERALL',
        '—',
        '—',
        $overall['completed_qty'],
        $overall['pending_qty'],
        $overall['received_qty'],
        $overall['grand_actual_received_qty'],
        $overall['grand_total_qty'],
        number_format($overall['grand_total_cost'], 2, '.', '')
    ]);

    fclose($output);
    exit;
}

// ============================================================
// 3. PAGINATED SUPPLIER RESOLUTION (Phase 1)
// ============================================================
// Total distinct suppliers matching filter
$countSql = "
    SELECT COUNT(DISTINCT ph.supplier_id) AS total
    FROM po_header ph
    LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
    LEFT JOIN return_qc.suppliers s_qc ON ph.supplier_id = s_qc.supplier_id
    $whereClause
";

try {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalSuppliers = (int)$stmtCount->fetchColumn();
    $totalPages = ceil($totalSuppliers / $limit);
} catch (PDOException $e) {
    $totalSuppliers = 0; 
    $totalPages = 0;
}

// Fetch Page's distinct suppliers
$pageSuppliersSql = "
    SELECT 
        ph.supplier_id,
        COALESCE(s.supplier_name, s_qc.supplier_name, CONCAT('Supplier #', ph.supplier_id)) AS supplier_name,
        COUNT(DISTINCT ph.po_id) AS total_pos_count
    FROM po_header ph
    LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
    LEFT JOIN return_qc.suppliers s_qc ON ph.supplier_id = s_qc.supplier_id
    $whereClause
    GROUP BY ph.supplier_id, supplier_name
    ORDER BY supplier_name ASC
    LIMIT :offset, :limit
";

$pageSuppliers = [];
try {
    $stmtPageSup = $pdo->prepare($pageSuppliersSql);
    foreach ($params as $k => $v) {
        $stmtPageSup->bindValue(":$k", $v);
    }
    $stmtPageSup->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtPageSup->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtPageSup->execute();
    $pageSuppliers = $stmtPageSup->fetchAll();
} catch (PDOException $e) {
    die("Supplier query error: " . $e->getMessage());
}

// ============================================================
// 4. FETCH PRECISE PO ITEMS FOR THIS PAGE'S SUPPLIERS (Phase 2)
// ============================================================
$suppliersData = [];
$supplierIdsOnPage = array_column($pageSuppliers, 'supplier_id');

if (!empty($supplierIdsOnPage)) {
    // Initialize supplier data structures
    foreach ($pageSuppliers as $ps) {
        $suppliersData[$ps['supplier_id']] = [
            'supplier_id'          => $ps['supplier_id'],
            'supplier_name'        => $ps['supplier_name'],
            'total_pos_count'      => (int)$ps['total_pos_count'],
            'completed_qty'        => 0,
            'pending_qty'          => 0,
            'received_qty'         => 0,
            'grand_total_qty'      => 0,
            'actual_received_qty'  => 0,
            'total_cost'           => 0.0,
            'pos'                  => []
        ];
    }

    $pageInPlaceholders = [];
    $pageParams = [];
    $idx = 0;
    foreach ($supplierIdsOnPage as $sId) {
        $phName = "p_sup_$idx";
        $pageInPlaceholders[] = ":$phName";
        $pageParams[$phName] = $sId;
        $idx++;
    }
    $pageInClause = implode(',', $pageInPlaceholders);

    // Build PO query for current page suppliers
    $poWhere = [
        "ph.supplier_id IN ($pageInClause)",
        "ph.status != 'Cancelled'"
    ];

    if (!empty($fromDate) && !empty($toDate)) {
        $poWhere[] = 'ph.purchase_date BETWEEN :p_from AND :p_to';
        $pageParams['p_from'] = $fromDate;
        $pageParams['p_to']   = $toDate;
    } elseif (!empty($fromDate)) {
        $poWhere[] = 'ph.purchase_date >= :p_from';
        $pageParams['p_from'] = $fromDate;
    } elseif (!empty($toDate)) {
        $poWhere[] = 'ph.purchase_date <= :p_to';
        $pageParams['p_to'] = $toDate;
    }

    if (!empty($statusFilter) && in_array($statusFilter, ['Pending', 'Received', 'Completed'])) {
        $poWhere[] = 'ph.status = :p_status';
        $pageParams['p_status'] = $statusFilter;
    }

    if (!empty($search)) {
        $poWhere[] = "(s.supplier_name LIKE :p_search_sup OR s_qc.supplier_name LIKE :p_search_qc OR ph.po_number LIKE :p_search_po)";
        $pageParams['p_search_sup'] = "%$search%";
        $pageParams['p_search_qc']  = "%$search%";
        $pageParams['p_search_po']  = "%$search%";
    }

    $poWhereStr = 'WHERE ' . implode(' AND ', $poWhere);

    $pagePoSql = "
        SELECT 
            ph.supplier_id,
            ph.po_id,
            ph.po_number,
            ph.status,
            ph.purchase_date,
            ph.expected_delivery_date,
            SUM(pi.quantity) AS total_po_qty,
            SUM(pi.received_qty) AS total_received_qty,
            SUM(pi.quantity * pi.cost_price) AS total_po_cost
        FROM po_header ph
        LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
        LEFT JOIN return_qc.suppliers s_qc ON ph.supplier_id = s_qc.supplier_id
        INNER JOIN po_items pi ON ph.po_id = pi.po_id
        $poWhereStr
        GROUP BY ph.po_id, ph.supplier_id, ph.po_number, ph.status, ph.purchase_date, ph.expected_delivery_date
        ORDER BY ph.purchase_date DESC, ph.po_id DESC
    ";

    $stmtPo = $pdo->prepare($pagePoSql);
    $stmtPo->execute($pageParams);
    $allPoRows = $stmtPo->fetchAll();

    foreach ($allPoRows as $po) {
        $sId = $po['supplier_id'];
        if (!isset($suppliersData[$sId])) continue;

        $qty         = (int)$po['total_po_qty'];
        $receivedQty = (int)$po['total_received_qty'];
        $cost        = (float)$po['total_po_cost'];

        $suppliersData[$sId]['grand_total_qty']     += $qty;
        $suppliersData[$sId]['actual_received_qty'] += $receivedQty;
        $suppliersData[$sId]['total_cost']          += $cost;

        if ($po['status'] === 'Completed') {
            $suppliersData[$sId]['completed_qty'] += $qty;
        } elseif ($po['status'] === 'Pending') {
            $suppliersData[$sId]['pending_qty']   += $qty;
        } elseif ($po['status'] === 'Received') {
            $suppliersData[$sId]['received_qty']  += $qty;
        }

        $suppliersData[$sId]['pos'][] = $po;
    }
}

// Supplier dropdown options (from canonical suppliers table)
$supplierOptions = [];
try {
    $supplierOptions = $pdo->query("
        SELECT DISTINCT s.supplier_id, s.supplier_name 
        FROM suppliers s
        ORDER BY s.supplier_name ASC
    ")->fetchAll();
} catch (PDOException $e) {
    try {
        $supplierOptions = $pdo->query("SELECT supplier_id, supplier_name FROM return_qc.suppliers ORDER BY supplier_name ASC")->fetchAll();
    } catch (PDOException $e2) {}
}

$currentDate = date('Y-m-d');
$currentTime = date('h:i:s A');
$executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
?>
<?php
$page_title = 'ASB Fashion | PO Status Matrix Ledger';
$page = 'reports';
include ROOT_PATH . 'includes/header.php';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<style>
        :root {
            --asb-red: #b71c1c;
            --asb-red-hover: #8e0000;
            --asb-primary: #d32f2f;
            --asb-dark: #1a202c;
            --asb-bg: #f7fafc;
            --asb-border: #e2e8f0;
            --status-completed: #2e7d32;
            --status-completed-bg: #e8f5e9;
            --status-pending: #e65100;
            --status-pending-bg: #fff3e0;
            --status-received: #0277bd;
            --status-received-bg: #e1f5fe;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--asb-bg); padding: 15px; color: #2d3748; line-height: 1.4; }
        .container-fluid { max-width: 1580px; margin: 0 auto; }

        /* HEADER SECTION */
        .asb-top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 14px 20px;
            border-radius: 8px;
            border-left: 6px solid var(--asb-red);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .asb-title-group h1 {
            color: var(--asb-red);
            font-size: 21px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .asb-title-group span {
            font-size: 13px;
            font-weight: 400;
            color: #718096;
            text-transform: none;
        }
        .badge-engine {
            background: #ebf8ff;
            color: #2b6cb0;
            border: 1px solid #bee3f8;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* KPI SUMMARY CARDS */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .kpi-card {
            background: #fff;
            padding: 14px 16px;
            border-radius: 8px;
            border: 1px solid var(--asb-border);
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.07);
        }
        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .kpi-content { overflow: hidden; }
        .kpi-label { font-size: 11px; font-weight: 600; color: #718096; text-transform: uppercase; margin-bottom: 2px; }
        .kpi-value { font-size: 19px; font-weight: 800; color: #1a202c; white-space: nowrap; }

        .kpi-suppliers .kpi-icon { background: #f7fafc; color: #4a5568; border: 1px solid #e2e8f0; }
        .kpi-orders .kpi-icon { background: #ede7f6; color: #512da8; }
        .kpi-completed .kpi-icon { background: var(--status-completed-bg); color: var(--status-completed); }
        .kpi-pending .kpi-icon { background: var(--status-pending-bg); color: var(--status-pending); }
        .kpi-received .kpi-icon { background: var(--status-received-bg); color: var(--status-received); }
        .kpi-actual-received .kpi-icon { background: #e0f2f1; color: #00796b; }
        .kpi-grand .kpi-icon { background: #ffebee; color: var(--asb-red); }
        .kpi-cost .kpi-icon { background: #fff8e1; color: #f57f17; }

        /* CARD CONTAINERS */
        .asb-card {
            background: #ffffff;
            border: 1px solid var(--asb-border);
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .asb-card-header {
            background: #fafbfc;
            border-bottom: 1px solid var(--asb-border);
            padding: 12px 18px;
            color: var(--asb-red);
            font-weight: 700;
            font-size: 13.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .asb-card-body { padding: 16px 18px; }

        /* PRESET FILTER BUTTONS */
        .preset-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px dashed var(--asb-border);
            align-items: center;
        }
        .preset-label { font-size: 11px; font-weight: 700; color: #4a5568; text-transform: uppercase; margin-right: 4px; }
        .btn-preset {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .btn-preset:hover { background: #e2e8f0; color: #1e293b; }
        .btn-preset.active { background: var(--asb-red); color: #fff; border-color: var(--asb-red); }

        /* FILTER FORM */
        .filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; flex: 1 0 160px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: #4a5568; }
        .asb-input {
            border: 1px solid #cbd5e1;
            padding: 7px 10px;
            border-radius: 5px;
            width: 100%;
            font-size: 12px;
            background: #fff;
            color: #1e293b;
            transition: border-color 0.2s;
        }
        .asb-input:focus { border-color: var(--asb-red); outline: none; box-shadow: 0 0 0 2px rgba(183, 28, 28, 0.15); }
        
        .btn-asb {
            background: var(--asb-primary);
            color: #fff;
            border: none;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-asb:hover { background: var(--asb-red-hover); color: #fff; }
        .btn-asb-secondary {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 5px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-asb-secondary:hover { background: #e2e8f0; }
        .btn-print { background: #1e293b; color: #fff; }
        .btn-print:hover { background: #0f172a; }
        .btn-export { background: #15803d; color: #fff; }
        .btn-export:hover { background: #166534; }
        .btn-toggle-all { background: #0284c7; color: #fff; }
        .btn-toggle-all:hover { background: #0369a1; }

        /* TABLE MATRIX STYLING */
        .table-responsive { width: 100%; overflow-x: auto; }
        table.asb-matrix-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.asb-matrix-table th {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 11px 10px;
            border-bottom: 2px solid var(--asb-red);
            text-align: left;
            white-space: nowrap;
        }
        table.asb-matrix-table td {
            padding: 10px;
            border-bottom: 1px solid var(--asb-border);
            vertical-align: middle;
        }
        
        .supplier-row {
            cursor: pointer;
            transition: background-color 0.1s;
        }
        .supplier-row:hover td { background: #f8fafc; }
        .supplier-row td { background: #fff; font-weight: 700; border-top: 1px solid #e2e8f0; color: #1e293b; }
        .supplier-name-cell { display: flex; align-items: center; gap: 8px; }
        .supplier-toggle-btn {
            background: #f1f5f9;
            color: var(--asb-red);
            border: 1px solid #cbd5e1;
            width: 22px;
            height: 22px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .supplier-toggle-btn.open { transform: rotate(90deg); background: var(--asb-red); color: #fff; border-color: var(--asb-red); }

        /* BADGES */
        .badge-po-count {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
        }
        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
            letter-spacing: 0.3px;
        }
        .status-Pending { background: var(--status-pending-bg); color: var(--status-pending); border: 1px solid #ffcc80; }
        .status-Completed { background: var(--status-completed-bg); color: var(--status-completed); border: 1px solid #a5d6a7; }
        .status-Received { background: var(--status-received-bg); color: var(--status-received); border: 1px solid #90caf9; }
        .badge-overdue { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; font-size: 9px; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-left: 4px; }

        /* PROGRESS BAR */
        .progress-box { width: 100%; min-width: 90px; background: #e2e8f0; border-radius: 10px; height: 8px; overflow: hidden; position: relative; }
        .progress-bar-fill { height: 100%; background: var(--status-completed); border-radius: 10px; transition: width 0.3s ease; }
        .progress-text { font-size: 10px; font-weight: 700; color: #64748b; margin-top: 2px; text-align: right; }

        /* PO SUB-GRID TABLE */
        .po-breakdown-row { display: none; }
        .po-breakdown-row.show { display: table-row; }
        .po-breakdown-row td { background: #fafcff !important; padding: 10px 14px 16px 28px; border-bottom: 2px solid #e2e8f0; }
        .po-grid-table { width: 100%; border-collapse: collapse; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .po-grid-table th { background: #f1f5f9; color: #475569; font-size: 10.5px; padding: 7px 10px; border-bottom: 1px solid #cbd5e1; text-transform: uppercase; font-weight: 700; }
        .po-grid-table td { padding: 7px 10px; font-size: 11.5px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        .po-grid-table tr:hover td { background: #f8fafc; }
        .po-grid-table tr:last-child td { border-bottom: none; }
        
        .overall-row td {
            background: #fff1f2 !important;
            font-weight: 800;
            border-top: 3px solid var(--asb-red);
            border-bottom: 3px solid var(--asb-red);
            color: var(--asb-red);
            font-size: 13px;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* PAGINATION */
        .pagination-wrapper { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--asb-border); font-size: 12px; flex-wrap: wrap; gap: 10px; }
        .pagination { display: flex; gap: 4px; align-items: center; }
        .pagination a, .pagination span { padding: 5px 12px; border: 1px solid #cbd5e1; border-radius: 4px; text-decoration: none; color: #334155; font-size: 12px; font-weight: 600; background: #fff; }
        .pagination a:hover { background: #f1f5f9; }
        .pagination .active { background: var(--asb-red); color: #fff; border-color: var(--asb-red); }

        .asb-footer { text-align: center; margin-top: 35px; padding: 15px; color: #64748b; border-top: 1px solid var(--asb-border); font-size: 11px; line-height: 1.6; }
        .asb-footer strong { color: var(--asb-red); }

        /* ============================================================
           PROFESSIONAL FULL-PAGE A4 PRINT STYLES
           ============================================================ */
        .print-report-header { display: none; margin-bottom: 15px; border-bottom: 2px solid var(--asb-red); padding-bottom: 8px; }
        
        @media print {
            @page { 
                size: A4 portrait; 
                margin: 0.8cm;
                @bottom-right {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 8pt;
                    font-family: 'Segoe UI', Tahoma, sans-serif;
                    color: #555;
                }
            }
            body { background: #fff !important; font-size: 7.5pt !important; color: #000 !important; padding: 0 !important; }
            .container-fluid { max-width: 100% !important; padding: 0 !important; }
            .asb-top-nav, .kpi-grid, .filter-section, .btn, .pagination-wrapper, .supplier-toggle-btn { display: none !important; }
            
            .print-report-header { display: block !important; }
            .asb-card { border: none !important; box-shadow: none !important; margin: 0 !important; }
            .asb-card-header { background: var(--asb-red) !important; color: #fff !important; -webkit-print-color-adjust: exact; padding: 4px 8px !important; }
            
            table.asb-matrix-table th { background: var(--asb-red) !important; color: #fff !important; -webkit-print-color-adjust: exact; padding: 5px !important; font-size: 7.5pt !important; }
            .supplier-row td { background: #f1f5f9 !important; -webkit-print-color-adjust: exact; font-weight: bold; border-top: 1px solid #000 !important; font-size: 7.5pt !important; }
            .po-breakdown-row { display: table-row !important; }
            .po-breakdown-row td { background: #fff !important; padding: 2px 2px 6px 15px !important; }
            .po-grid-table { border: 1px solid #999 !important; font-size: 7pt !important; }
            .po-grid-table th { background: #e2e8f0 !important; -webkit-print-color-adjust: exact; color: #000 !important; padding: 3px 5px !important; }
            .po-grid-table td { padding: 3px 5px !important; }
            .overall-row td { background: #ffebee !important; -webkit-print-color-adjust: exact; border-top: 2px solid #000 !important; border-bottom: 2px solid #000 !important; font-size: 8pt !important; }
            
            .asb-footer { 
                display: block;
                page-break-inside: avoid;
                border-top: 1px solid #000 !important; 
                background: #fff !important; 
                -webkit-print-color-adjust: exact; 
                padding: 8px 0 !important; 
                font-size: 7.5pt !important;
                margin-top: 20px !important;
            }
        }
    </style>
<div class="container-fluid" style="margin-top: 10px;">
    <!-- SUB-HEADER / ENGINE BADGE -->
    <div class="no-print" style="margin-bottom: 20px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; background: white; padding: 14px 20px; border-radius: 12px; border-left: 6px solid #b71c1c; border-top: 1px solid #eddedf; border-right: 1px solid #eddedf; border-bottom: 1px solid #eddedf; box-shadow: 0 4px 14px rgba(185,28,28,0.04);">
        <div>
            <h2 style="color: #b71c1c; font-size: 19px; font-weight: 800; text-transform: uppercase; margin: 0; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-layer-group"></i> PO Status Matrix Ledger &amp; Audit Engine
            </h2>
            <p style="color: #718096; font-size: 13px; margin: 3px 0 0 0;">Dual Database Cross-Matrix &bull; 2M+ Records Optimized</p>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <span class="badge-engine"><i class="fas fa-microchip"></i> 2M+ Records Optimized</span>
        </div>
    </div>

    <!-- PRINT ONLY LETTERHEAD -->
    <div class="print-report-header">
        <h2 style="color: #b71c1c; font-size: 14pt; font-weight: 800; text-transform: uppercase;">ASB Fashion - Purchase Order Status Matrix</h2>
        <p style="font-size: 8.5pt; color: #333; margin-top: 4px;">
            <strong>Filter Period:</strong> <?= (!empty($fromDate) && !empty($toDate) ? "From " . htmlspecialchars($fromDate) . " To " . htmlspecialchars($toDate) : "All Time / Unrestricted") ?>
            &nbsp;|&nbsp; <strong>Suppliers:</strong> <?= number_format($overall['total_suppliers']) ?>
            &nbsp;|&nbsp; <strong>Active PO Orders:</strong> <?= number_format($overall['total_pos']) ?>
            &nbsp;|&nbsp; <strong>Printed On:</strong> <?= $currentDate ?> at <?= $currentTime ?>
        </p>
    </div>

    <!-- EXECUTIVE KPI SUMMARY CARDS -->
    <div class="kpi-grid">
        <div class="kpi-card kpi-suppliers">
            <div class="kpi-icon"><i class="fas fa-industry"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Suppliers</div>
                <div class="kpi-value"><?= number_format($overall['total_suppliers']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-orders">
            <div class="kpi-icon"><i class="fas fa-file-invoice"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Active POs</div>
                <div class="kpi-value"><?= number_format($overall['total_pos']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-completed">
            <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Completed Units</div>
                <div class="kpi-value" style="color: var(--status-completed);"><?= number_format($overall['completed_qty']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-pending">
            <div class="kpi-icon"><i class="fas fa-clock"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Pending Units</div>
                <div class="kpi-value" style="color: var(--status-pending);"><?= number_format($overall['pending_qty']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-received">
            <div class="kpi-icon"><i class="fas fa-truck-loading"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Partial Received</div>
                <div class="kpi-value" style="color: var(--status-received);"><?= number_format($overall['received_qty']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-actual-received">
            <div class="kpi-icon"><i class="fas fa-boxes-packing"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Units Received (GRN)</div>
                <div class="kpi-value" style="color: #00796b;"><?= number_format($overall['grand_actual_received_qty']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-grand">
            <div class="kpi-icon"><i class="fas fa-cubes"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Grand Total Units</div>
                <div class="kpi-value" style="color: var(--asb-red);"><?= number_format($overall['grand_total_qty']) ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-cost">
            <div class="kpi-icon"><i class="fas fa-coins"></i></div>
            <div class="kpi-content">
                <div class="kpi-label">Total Cost Value</div>
                <div class="kpi-value" style="font-size: 15px; color: #b78103;">Rs. <?= number_format($overall['grand_total_cost'] / 1000000, 2) ?>M</div>
            </div>
        </div>
    </div>

    <!-- FILTER SECTION -->
    <div class="asb-card filter-section">
        <div class="asb-card-header">
            <span><i class="fas fa-filter"></i> Matrix Filter &amp; Search Controls</span>
            <span style="font-size:11.5px; color:#64748b; font-weight:500;">
                Filtering <?php echo number_format($overall['total_pos']); ?> orders across <?php echo number_format($overall['total_suppliers']); ?> suppliers
            </span>
        </div>
        <div class="asb-card-body">
            <!-- QUICK DATE PRESETS -->
            <div class="preset-bar">
                <span class="preset-label"><i class="fas fa-calendar-alt"></i> Quick Presets:</span>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => 'all', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= ($preset === 'all' || (empty($fromDate) && empty($toDate))) ? 'active' : '' ?>">All Time</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => 'today', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= $preset === 'today' ? 'active' : '' ?>">Today</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => '7days', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= $preset === '7days' ? 'active' : '' ?>">Last 7 Days</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => '30days', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= $preset === '30days' ? 'active' : '' ?>">Last 30 Days</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => 'this_month', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= $preset === 'this_month' ? 'active' : '' ?>">This Month</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => 'last_month', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= $preset === 'last_month' ? 'active' : '' ?>">Last Month</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['preset' => 'this_year', 'from_date' => '', 'to_date' => '', 'page' => 1])) ?>" class="btn-preset <?= $preset === 'this_year' ? 'active' : '' ?>">This Year</a>
            </div>

            <form method="GET" id="matrixFilterForm">
                <input type="hidden" name="preset" value="<?= htmlspecialchars($preset) ?>">
                <div class="filter-row">
                    <div class="filter-group" style="flex: 0 0 140px;">
                        <label>From Date</label>
                        <input type="date" name="from_date" id="from_date" class="asb-input" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="filter-group" style="flex: 0 0 140px;">
                        <label>To Date</label>
                        <input type="date" name="to_date" id="to_date" class="asb-input" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="filter-group" style="flex: 0 0 130px;">
                        <label>PO Status</label>
                        <select name="status_filter" class="asb-input">
                            <option value="">All Statuses</option>
                            <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Received" <?= $statusFilter === 'Received' ? 'selected' : '' ?>>Received</option>
                            <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        </select>
                    </div>
                    <div class="filter-group" style="flex: 1 0 180px;">
                        <label>Search Keyword</label>
                        <input type="text" name="search" class="asb-input" value="<?= htmlspecialchars($search) ?>" placeholder="Supplier name or PO#...">
                    </div>
                    <div class="filter-group" style="flex: 2 0 250px;">
                        <label>Filter Suppliers</label>
                        <select name="supplier_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($supplierOptions as $s): ?>
                                <option value="<?= $s['supplier_id'] ?>" <?= in_array($s['supplier_id'], $supplierIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="flex: 0 0 90px;">
                        <label>Per Page</label>
                        <select name="limit" class="asb-input" onchange="this.form.submit()">
                            <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                            <option value="40" <?= $limit == 40 ? 'selected' : '' ?>>40</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                            <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: flex-end; margin-left: auto; flex-wrap: wrap;">
                        <button type="submit" class="btn-asb"><i class="fas fa-filter"></i> Apply</button>
                        <a href="?" class="btn-asb-secondary"><i class="fas fa-undo"></i> Reset</a>
                        <button type="button" class="btn-asb btn-toggle-all" onclick="toggleAllBreakdowns()"><i class="fas fa-layer-group"></i> Expand All</button>
                        <button type="button" class="btn-asb btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print A4</button>
                        <button type="button" class="btn-asb btn-export" onclick="exportCSV()"><i class="fas fa-file-excel"></i> Export Excel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN MATRIX TABLE -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-table"></i> Supplier Matrix Ledger &amp; PO Breakdown</span>
            <span style="font-size:11.5px; color:#64748b;">
                Showing <?= number_format(count($suppliersData)) ?> suppliers on Page <?= $page ?> of <?= max(1, $totalPages) ?>
            </span>
        </div>
        <div class="asb-card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="asb-matrix-table">
                    <thead>
                        <tr>
                            <th style="width: 26%;">Supplier Details</th>
                            <th class="text-right" style="width: 10%;">Completed Qty</th>
                            <th class="text-right" style="width: 10%;">Pending Qty</th>
                            <th class="text-right" style="width: 10%;">Received Qty</th>
                            <th class="text-right" style="width: 10%;">Units Received</th>
                            <th class="text-right" style="width: 11%;">Grand Total Qty</th>
                            <th class="text-right" style="width: 12%;">Total Value (Rs.)</th>
                            <th class="text-center" style="width: 11%;">Fulfillment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($suppliersData)): ?>
                            <?php foreach ($suppliersData as $row): 
                                $fulfillment = $row['grand_total_qty'] > 0 ? min(100, round(($row['actual_received_qty'] / $row['grand_total_qty']) * 100, 1)) : 0;
                            ?>
                            <!-- Supplier Summary Row -->
                            <tr class="supplier-row" onclick="toggleBreakdown(<?= $row['supplier_id'] ?>)">
                                <td>
                                    <div class="supplier-name-cell">
                                        <button type="button" class="supplier-toggle-btn" id="btn-toggle-<?= $row['supplier_id'] ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                        <div>
                                            <div style="font-size: 13px; color: #1e293b;"><?= htmlspecialchars($row['supplier_name']) ?></div>
                                            <div style="font-size: 10.5px; color: #64748b; font-weight: normal; margin-top: 1px;">
                                                <span class="badge-po-count"><?= number_format($row['total_pos_count']) ?> PO Orders</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right" style="color: var(--status-completed); font-weight: 700;">
                                    <?= $row['completed_qty'] > 0 ? number_format($row['completed_qty']) : '—' ?>
                                </td>
                                <td class="text-right" style="color: var(--status-pending); font-weight: 700;">
                                    <?= $row['pending_qty'] > 0 ? number_format($row['pending_qty']) : '—' ?>
                                </td>
                                <td class="text-right" style="color: var(--status-received); font-weight: 700;">
                                    <?= $row['received_qty'] > 0 ? number_format($row['received_qty']) : '—' ?>
                                </td>
                                <td class="text-right" style="color: #00796b; font-weight: 700;">
                                    <?= $row['actual_received_qty'] > 0 ? number_format($row['actual_received_qty']) : '0' ?>
                                </td>
                                <td class="text-right" style="color: var(--asb-red); font-weight: 800; font-size: 13.5px;">
                                    <?= number_format($row['grand_total_qty']) ?>
                                </td>
                                <td class="text-right" style="color: #334155; font-weight: 700;">
                                    Rs. <?= number_format($row['total_cost'], 2) ?>
                                </td>
                                <td>
                                    <div class="progress-box">
                                        <div class="progress-bar-fill" style="width: <?= $fulfillment ?>%;"></div>
                                    </div>
                                    <div class="progress-text"><?= $fulfillment ?>%</div>
                                </td>
                            </tr>
                            
                            <!-- Detailed PO-wise Clean Grid Sub-Row -->
                            <tr class="po-breakdown-row" id="breakdown-<?= $row['supplier_id'] ?>">
                                <td colspan="8">
                                    <table class="po-grid-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 20%;">PO Number</th>
                                                <th style="width: 12%;">Status</th>
                                                <th style="width: 14%;">Order Date</th>
                                                <th style="width: 16%;">Expected Delivery</th>
                                                <th class="text-right" style="width: 12%;">Ordered Qty</th>
                                                <th class="text-right" style="width: 12%;">Received Qty</th>
                                                <th class="text-right" style="width: 14%;">Total Amount (Rs.)</th>
                                                <th class="text-center" style="width: 12%;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($row['pos'])): ?>
                                                <?php foreach ($row['pos'] as $po): 
                                                    $isOverdue = (!empty($po['expected_delivery_date']) && $po['expected_delivery_date'] < $currentDate && $po['status'] !== 'Completed');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong>
                                                            <i class="fas fa-file-alt" style="color: var(--asb-red); margin-right: 5px;"></i>
                                                            <?= htmlspecialchars($po['po_number']) ?>
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?= htmlspecialchars($po['status']) ?>">
                                                            <?= htmlspecialchars($po['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars($po['purchase_date']) ?></td>
                                                    <td>
                                                        <?= htmlspecialchars($po['expected_delivery_date'] ?: 'N/A') ?>
                                                        <?php if ($isOverdue): ?>
                                                            <span class="badge-overdue"><i class="fas fa-exclamation-triangle"></i> Overdue</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-right" style="font-weight: 700; color: #1e293b;">
                                                        <?= number_format($po['total_po_qty']) ?>
                                                    </td>
                                                    <td class="text-right" style="font-weight: 700; color: #00796b;">
                                                        <?= number_format($po['total_received_qty']) ?>
                                                    </td>
                                                    <td class="text-right" style="font-weight: 700; color: #334155;">
                                                        Rs. <?= number_format($po['total_po_cost'], 2) ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <a href="print_po_report.php?po_id=<?= $po['po_id'] ?>" target="_blank" class="btn-asb-secondary" style="padding: 2px 8px; font-size: 10.5px;" title="Print PO Statement">
                                                            <i class="fas fa-print"></i> Report
                                                        </a>
                                                        <a href="edit_po.php?po_id=<?= $po['po_id'] ?>" target="_blank" class="btn-asb-secondary" style="padding: 2px 8px; font-size: 10.5px;" title="View / Edit PO">
                                                            <i class="fas fa-external-link-alt"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" style="text-align: center; color: #888; padding: 12px; font-style: italic;">
                                                        No purchase orders found matching active filters for this supplier.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <!-- OVERALL GRAND TOTAL ROW -->
                            <?php 
                                $overallFulfillment = $overall['grand_total_qty'] > 0 ? min(100, round(($overall['grand_actual_received_qty'] / $overall['grand_total_qty']) * 100, 1)) : 0;
                            ?>
                            <tr class="overall-row">
                                <td>📌 GRAND TOTAL (All <?= number_format($overall['total_suppliers']) ?> Suppliers / <?= number_format($overall['total_pos']) ?> POs)</td>
                                <td class="text-right"><?= number_format($overall['completed_qty']) ?></td>
                                <td class="text-right"><?= number_format($overall['pending_qty']) ?></td>
                                <td class="text-right"><?= number_format($overall['received_qty']) ?></td>
                                <td class="text-right"><?= number_format($overall['grand_actual_received_qty']) ?></td>
                                <td class="text-right" style="font-size: 14px; font-weight: 900;"><?= number_format($overall['grand_total_qty']) ?></td>
                                <td class="text-right" style="font-size: 13px;">Rs. <?= number_format($overall['grand_total_cost'], 2) ?></td>
                                <td class="text-center">
                                    <div class="progress-box" style="background: #fecdd3;">
                                        <div class="progress-bar-fill" style="width: <?= $overallFulfillment ?>%; background: var(--asb-red);"></div>
                                    </div>
                                    <div class="progress-text" style="color: var(--asb-red);"><?= $overallFulfillment ?>%</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #718096; font-size: 14px;">
                                    <i class="fas fa-search" style="font-size: 26px; color: #cbd5e1; display: block; margin-bottom: 10px;"></i>
                                    No purchase orders found for the selected criteria.
                                    <div style="margin-top: 8px;">
                                        <a href="?" class="btn-asb" style="padding: 4px 12px; font-size: 11px;">Clear All Filters</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <?php if ($totalSuppliers > $limit): ?>
            <div style="padding: 0 18px 18px 18px;">
                <div class="pagination-wrapper">
                    <span>
                        Showing suppliers <strong><?= number_format($offset + 1) ?> – <?= number_format(min($offset + $limit, $totalSuppliers)) ?></strong> of <strong><?= number_format($totalSuppliers) ?></strong>
                    </span>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">&laquo; First</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&lsaquo; Prev</a>
                        <?php endif; ?>

                        <?php 
                        $startP = max(1, $page - 2);
                        $endP   = min($totalPages, $page + 2);
                        for ($p = $startP; $p <= $endP; $p++): ?>
                            <?php if ($p == $page): ?>
                                <span class="active"><?= $p ?></span>
                            <?php else: ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next &rsaquo;</a>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>">Last &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SYSTEM FOOTER -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Matrix Ledger System. All Rights Reserved.<br>
        <span style="font-size: 11px; color: var(--asb-red); font-weight: 700;">System Designed &amp; Developed by Vexel IT by Kavizz</span><br>
        <span style="font-size: 10px; color: #718096;">Generated on <?= $currentDate ?> at <?= $currentTime ?> | High-Capacity 2M+ Records Engine | Execution Time: <?= number_format($executionTime, 3) ?>s</span>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.select2-multi').select2({
        placeholder: 'Search & select suppliers',
        allowClear: true,
        width: '100%'
    });
});

let allExpanded = false;

function toggleBreakdown(supId) {
    const row = document.getElementById('breakdown-' + supId);
    const btn = document.getElementById('btn-toggle-' + supId);
    if (!row) return;

    if (row.classList.contains('show')) {
        row.classList.remove('show');
        if (btn) btn.classList.remove('open');
    } else {
        row.classList.add('show');
        if (btn) btn.classList.add('open');
    }
}

function toggleAllBreakdowns() {
    allExpanded = !allExpanded;
    const rows = document.querySelectorAll('.po-breakdown-row');
    const btns = document.querySelectorAll('.supplier-toggle-btn');
    const toggleBtn = document.querySelector('.btn-toggle-all');

    rows.forEach(r => {
        if (allExpanded) r.classList.add('show');
        else r.classList.remove('show');
    });

    btns.forEach(b => {
        if (allExpanded) b.classList.add('open');
        else b.classList.remove('open');
    });

    if (toggleBtn) {
        toggleBtn.innerHTML = allExpanded 
            ? '<i class="fas fa-compress"></i> Collapse All' 
            : '<i class="fas fa-layer-group"></i> Expand All';
    }
}

function exportCSV() {
    const url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    window.location.href = url.toString();
}
</script>
<?php include ROOT_PATH . 'includes/footer.php'; ?>
