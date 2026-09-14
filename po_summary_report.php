<?php
/**
 * PO Summary Report - ASB Fashion PO System
 * Designed & Developed by Vexel IT (Kavizz)
 * Optimized for Large Dataset Handling (1M+ records)
 */

date_default_timezone_set('Asia/Colombo');

// ===== CONFIGURATION =====
$dbHost = '127.0.0.1';
$dbName = 'po_system';
$dbUser = 'root';
$dbPass = '';

// Performance configuration
define('MAX_EXPORT_ROWS', 10000);
define('CHART_LIMIT', 500);
define('PAGINATION_LIMIT', 100);
define('QUERY_TIMEOUT', 300); // 5 minutes for large queries

try {
    // MySQL connection with proper PDO attributes
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            // Removed invalid constant: PDO::MYSQL_ATTR_MAX_BUFFER_SIZE
            // Use these instead for memory management
            PDO::MYSQL_ATTR_LOCAL_INFILE => false,
            PDO::ATTR_TIMEOUT => QUERY_TIMEOUT,
        ]
    );
    
    // Set session timeouts for large queries
    $pdo->exec("SET SESSION wait_timeout = " . QUERY_TIMEOUT);
    $pdo->exec("SET SESSION interactive_timeout = " . QUERY_TIMEOUT);
    
    // Try to set max_execution_time if available (MySQL 5.7+)
    try {
        $pdo->exec("SET SESSION max_execution_time = " . (QUERY_TIMEOUT * 1000));
    } catch (PDOException $e) {
        // Ignore - some MySQL versions don't support this
    }
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ===== GET FILTER VALUES =====
$fromDate  = isset($_GET['from_date']) && $_GET['from_date'] ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$toDate    = isset($_GET['to_date']) && $_GET['to_date'] ? $_GET['to_date'] : date('Y-m-d');
$search    = isset($_GET['search']) ? trim($_GET['search']) : '';
$page      = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit     = isset($_GET['limit']) ? min(PAGINATION_LIMIT, max(10, (int)$_GET['limit'])) : PAGINATION_LIMIT;
$offset    = ($page - 1) * $limit;
$exportCSV = isset($_GET['export']) && $_GET['export'] === 'csv';

$supplierIds = isset($_GET['supplier_ids']) && is_array($_GET['supplier_ids']) ? array_map('intval', $_GET['supplier_ids']) : [];
$deptIds     = isset($_GET['department_ids']) && is_array($_GET['department_ids']) ? array_map('intval', $_GET['department_ids']) : [];
$subDeptIds  = isset($_GET['sub_department_ids']) && is_array($_GET['sub_department_ids']) ? array_map('intval', $_GET['sub_department_ids']) : [];
$catIds      = isset($_GET['category_ids']) && is_array($_GET['category_ids']) ? array_map('intval', $_GET['category_ids']) : [];
$colorIds    = isset($_GET['color_ids']) && is_array($_GET['color_ids']) ? array_map('intval', $_GET['color_ids']) : [];
$sizeIds     = isset($_GET['size_ids']) && is_array($_GET['size_ids']) ? array_map('intval', $_GET['size_ids']) : [];

// ===== HELPER FUNCTIONS =====
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

function buildWhereClause($filters, &$params) {
    $conditions = [
        'ph.purchase_date BETWEEN :from_date AND :to_date',
        'ph.status != "Cancelled"'
    ];
    $paramIndex = 0;
    
    $params['from_date'] = $filters['from_date'];
    $params['to_date'] = $filters['to_date'];
    
    if (!empty($filters['supplier_ids'])) {
        $in = buildInClause($filters['supplier_ids'], 'sup', $params, $paramIndex);
        $conditions[] = "s.supplier_id IN ($in)";
    }
    if (!empty($filters['dept_ids'])) {
        $in = buildInClause($filters['dept_ids'], 'dept', $params, $paramIndex);
        $conditions[] = "i.department_id IN ($in)";
    }
    if (!empty($filters['sub_dept_ids'])) {
        $in = buildInClause($filters['sub_dept_ids'], 'subdept', $params, $paramIndex);
        $conditions[] = "i.sub_department_id IN ($in)";
    }
    if (!empty($filters['cat_ids'])) {
        $in = buildInClause($filters['cat_ids'], 'cat', $params, $paramIndex);
        $conditions[] = "i.category_id IN ($in)";
    }
    if (!empty($filters['color_ids'])) {
        $in = buildInClause($filters['color_ids'], 'color', $params, $paramIndex);
        $conditions[] = "i.color_id IN ($in)";
    }
    if (!empty($filters['size_ids'])) {
        $in = buildInClause($filters['size_ids'], 'size', $params, $paramIndex);
        $conditions[] = "i.size_id IN ($in)";
    }
    if (!empty($filters['search'])) {
        $conditions[] = "s.supplier_name LIKE :search";
        $params['search'] = "%{$filters['search']}%";
    }
    
    return 'WHERE ' . implode(' AND ', $conditions);
}

function fmt($val) {
    return number_format((float)$val, 2);
}

// ===== FILTERS ARRAY =====
$filters = [
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'search' => $search,
    'supplier_ids' => $supplierIds,
    'dept_ids' => $deptIds,
    'sub_dept_ids' => $subDeptIds,
    'cat_ids' => $catIds,
    'color_ids' => $colorIds,
    'size_ids' => $sizeIds
];

$params = [];
$whereClause = buildWhereClause($filters, $params);

// ===== COUNT QUERY (for pagination) =====
$countSql = "
    SELECT COUNT(DISTINCT s.supplier_id) as total
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    INNER JOIN items i ON pi.item_id = i.item_id
    $whereClause
";

try {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalSuppliers = (int)$stmtCount->fetchColumn();
    $totalPages = ceil($totalSuppliers / $limit);
} catch (PDOException $e) {
    // Fallback: if count query fails, assume 0 records
    $totalSuppliers = 0;
    $totalPages = 0;
}

// ===== MAIN QUERY: Supplier Summary =====
$sql = "
    SELECT
        s.supplier_id,
        s.supplier_name,
        COUNT(DISTINCT ph.po_id) AS po_count,
        SUM(pi.quantity) AS total_quantity,
        SUM(pi.quantity * pi.cost_price) AS total_cost,
        SUM(pi.quantity * COALESCE(pi.selling_price, 0)) AS total_selling,
        AVG(DATEDIFF(NOW(), ph.purchase_date)) AS avg_days_since_purchase,
        GROUP_CONCAT(DISTINCT ph.po_number ORDER BY ph.po_number SEPARATOR ', ') AS po_numbers
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    INNER JOIN items i ON pi.item_id = i.item_id
    $whereClause
    GROUP BY s.supplier_id, s.supplier_name
    ORDER BY s.supplier_name
";

// Add pagination only if not exporting CSV
if (!$exportCSV) {
    $sql .= " LIMIT :offset, :limit";
}

try {
    $stmt = $pdo->prepare($sql);
    
    // Bind filter params
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    
    // Bind pagination params only if not exporting
    if (!$exportCSV) {
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $suppliers = $stmt->fetchAll();
    
    // If exporting CSV, output and exit
    if ($exportCSV && !empty($suppliers)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="po_summary_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Supplier', 'POs', 'PO Numbers', 'Total Qty', 'Total Cost (LKR)', 'Total Selling (LKR)', 'Profit (LKR)', 'Avg Days']);
        
        foreach ($suppliers as $row) {
            $profit = $row['total_selling'] - $row['total_cost'];
            fputcsv($output, [
                $row['supplier_name'],
                $row['po_count'],
                $row['po_numbers'] ?? '',
                $row['total_quantity'],
                number_format($row['total_cost'], 2),
                number_format($row['total_selling'], 2),
                number_format($profit, 2),
                round($row['avg_days_since_purchase'], 1)
            ]);
        }
        fclose($output);
        exit;
    }
    
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage() . "<br>SQL: " . $sql);
}

// ===== OPTIMIZED OVERALL TOTALS =====
$totalsSql = "
    SELECT
        COUNT(DISTINCT ph.po_id) AS po_count,
        SUM(pi.quantity) AS total_quantity,
        SUM(pi.quantity * pi.cost_price) AS total_cost,
        SUM(pi.quantity * COALESCE(pi.selling_price, 0)) AS total_selling,
        AVG(DATEDIFF(NOW(), ph.purchase_date)) AS avg_days
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    INNER JOIN items i ON pi.item_id = i.item_id
    $whereClause
";

try {
    $stmtTotals = $pdo->prepare($totalsSql);
    $stmtTotals->execute($params);
    $overall = $stmtTotals->fetch();
    
    // Ensure all values are numeric
    $overall['po_count'] = (int)($overall['po_count'] ?? 0);
    $overall['total_quantity'] = (float)($overall['total_quantity'] ?? 0);
    $overall['total_cost'] = (float)($overall['total_cost'] ?? 0);
    $overall['total_selling'] = (float)($overall['total_selling'] ?? 0);
    $overall['avg_days'] = (float)($overall['avg_days'] ?? 0);
    $overall['profit'] = $overall['total_selling'] - $overall['total_cost'];
} catch (PDOException $e) {
    $overall = [
        'po_count' => 0,
        'total_quantity' => 0,
        'total_cost' => 0,
        'total_selling' => 0,
        'avg_days' => 0,
        'profit' => 0
    ];
}

// ===== CHART DATA: Monthly Trend =====
$monthlySql = "
    SELECT
        DATE_FORMAT(ph.purchase_date, '%Y-%m') AS month,
        SUM(pi.quantity * pi.cost_price) AS total_cost,
        SUM(pi.quantity * COALESCE(pi.selling_price, 0)) AS total_selling
    FROM po_header ph
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    INNER JOIN items i ON pi.item_id = i.item_id
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    $whereClause
    GROUP BY month
    ORDER BY month DESC
    LIMIT " . CHART_LIMIT . "
";

try {
    $stmtMonth = $pdo->prepare($monthlySql);
    $stmtMonth->execute($params);
    $monthlyData = $stmtMonth->fetchAll();
    $monthlyData = array_reverse($monthlyData);
} catch (PDOException $e) {
    $monthlyData = [];
}

$chartMonths = array_column($monthlyData, 'month');
$chartCost = array_column($monthlyData, 'total_cost');
$chartSelling = array_column($monthlyData, 'total_selling');

// ===== CHART DATA: Supplier Cost Distribution =====
$pieSql = "
    SELECT
        s.supplier_name,
        SUM(pi.quantity * pi.cost_price) AS total_cost
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    INNER JOIN items i ON pi.item_id = i.item_id
    $whereClause
    GROUP BY s.supplier_id
    ORDER BY total_cost DESC
    LIMIT 10
";

try {
    $stmtPie = $pdo->prepare($pieSql);
    $stmtPie->execute($params);
    $pieData = $stmtPie->fetchAll();
} catch (PDOException $e) {
    $pieData = [];
}

$pieLabels = array_column($pieData, 'supplier_name');
$pieValues = array_column($pieData, 'total_cost');

// ===== FETCH FILTER OPTIONS =====
function fetchOptions($pdo, $table, $idField, $nameField, $limit = 1000) {
    try {
        $sql = "SELECT $idField, $nameField FROM $table ORDER BY $nameField LIMIT $limit";
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

$supplierOptions = fetchOptions($pdo, 'return_qc.suppliers', 'supplier_id', 'supplier_name');
$departments   = fetchOptions($pdo, 'departments', 'department_id', 'department_name');
$subDepartments= fetchOptions($pdo, 'sub_departments', 'sub_department_id', 'sub_department_name');
$categories    = fetchOptions($pdo, 'categories', 'category_id', 'category_name');
$colors        = fetchOptions($pdo, 'colors', 'color_id', 'color_name');
$sizes         = fetchOptions($pdo, 'sizes', 'size_id', 'size_name');

$currentDateTime = date('Y-m-d H:i:s');
$currentDate = date('Y-m-d');
$currentTime = date('h:i:s A');

// ===== PERFORMANCE INDICATORS =====
$executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
$memoryUsage = memory_get_peak_usage(true) / 1024 / 1024;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Summary Report - ASB Fashion</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ----- RESET & BASE ----- */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #fbfbfb;
            padding: 20px 25px;
            color: #333;
        }
        .container-fluid {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ----- ASB HEADER TITLE ----- */
        .asb-header-title {
            color: #b71c1c;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-left: 5px solid #d32f2f;
            padding-left: 15px;
            margin-bottom: 25px;
            font-size: 24px;
        }
        .asb-header-title span {
            font-weight: 300;
            color: #555;
            font-size: 16px;
        }

        /* ----- CARDS ----- */
        .asb-card {
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            overflow: hidden;
            margin-bottom: 25px;
        }
        .asb-card-header {
            background: #fff;
            border-bottom: 2px solid #eaeaea;
            padding: 15px 20px;
            color: #b71c1c;
            font-weight: bold;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .asb-card-body {
            padding: 20px;
        }

        /* ----- SUMMARY STATS ROW ----- */
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            flex: 1 0 120px;
            background: #f8f9fa;
            border-radius: 6px;
            padding: 12px 15px;
            text-align: center;
            border-left: 3px solid #d32f2f;
        }
        .stat-box .label {
            font-size: 11px;
            text-transform: uppercase;
            color: #777;
            letter-spacing: 0.5px;
        }
        .stat-box .value {
            font-size: 18px;
            font-weight: 700;
            color: #333;
            margin-top: 4px;
        }
        .stat-box .value.positive { color: #2e7d32; }
        .stat-box .value.negative { color: #c62828; }

        /* ----- BUTTONS ----- */
        .btn-asb {
            background: #d32f2f;
            color: #fff;
            border: none;
            font-weight: bold;
            padding: 6px 16px;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .btn-asb:hover {
            background: #b71c1c;
            color: #fff;
        }
        .btn-asb-secondary {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ccc;
            font-weight: bold;
            padding: 6px 16px;
            border-radius: 4px;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .btn-asb-secondary:hover {
            background: #e0e0e0;
        }
        .btn-print {
            background: #2c3e50;
            color: #fff;
        }
        .btn-print:hover {
            background: #1a252f;
        }
        .btn-export {
            background: #1b5e20;
            color: #fff;
        }
        .btn-export:hover {
            background: #0d3d13;
        }

        /* ----- PAGINATION ----- */
        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            padding: 15px 0 5px 0;
            border-top: 1px solid #eee;
            margin-top: 15px;
        }
        .pagination {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 5px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            font-size: 13px;
            background: #fff;
            transition: all 0.2s;
        }
        .pagination a:hover {
            background: #f5f5f5;
        }
        .pagination .active {
            background: #d32f2f;
            color: #fff;
            border-color: #d32f2f;
        }
        .pagination .disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        .per-page-selector {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        .per-page-selector select {
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        /* ----- FORM ELEMENTS ----- */
        .asb-input {
            border: 1px solid #ccc;
            padding: 6px 10px;
            border-radius: 4px;
            width: 100%;
            font-size: 13px;
            box-sizing: border-box;
            background: #fff;
        }
        .asb-input:focus {
            border-color: #d32f2f;
            outline: none;
            box-shadow: 0 0 4px rgba(211,47,47,0.2);
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1 0 160px;
            min-width: 140px;
        }
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
        }
        .filter-group .select2-container {
            width: 100% !important;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-left: auto;
        }

        /* ----- TABLE ----- */
        .table-wrapper {
            overflow-x: auto;
            padding: 0 0 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f1f3f5;
            color: #333;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 10px 8px;
            border-bottom: 2px solid #d32f2f;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        td {
            padding: 8px 8px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        tr:hover td {
            background: #f8f9fa;
        }
        .text-right {
            text-align: right;
        }
        .supplier-name {
            font-weight: 600;
            color: #333;
        }
        .profit-positive {
            color: #2e7d32;
            font-weight: 600;
        }
        .profit-negative {
            color: #c62828;
            font-weight: 600;
        }
        .overall-row td {
            background: #fce4e4 !important;
            font-weight: 700;
            border-top: 3px solid #b71c1c;
            border-bottom: 2px solid #b71c1c;
        }
        .empty-state {
            padding: 30px;
            text-align: center;
            color: #777;
            font-size: 15px;
        }

        /* ----- CHARTS ----- */
        .chart-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .chart-box {
            flex: 1 1 45%;
            min-width: 280px;
            background: #fafafa;
            border-radius: 6px;
            padding: 15px;
            border: 1px solid #eee;
        }
        .chart-box h4 {
            font-size: 14px;
            color: #b71c1c;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .chart-box canvas {
            max-height: 220px;
            width: 100% !important;
        }

        /* ----- FOOTER ----- */
        .asb-footer {
            text-align: center;
            margin-top: 40px;
            padding: 15px;
            color: #777;
            border-top: 1px solid #eee;
            font-size: 12px;
        }
        .asb-footer strong {
            color: #b71c1c;
        }
        .asb-footer .sub {
            font-size: 11px;
            margin-top: 4px;
            display: inline-block;
            color: #aaa;
        }
        .performance-metrics {
            font-size: 10px;
            color: #999;
            margin-top: 5px;
        }

        /* ----- RESPONSIVE ----- */
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-actions {
                margin-left: 0;
                justify-content: flex-start;
            }
            .asb-card-body {
                padding: 12px;
            }
            th, td {
                padding: 5px 4px;
                font-size: 11px;
            }
            .stat-box {
                flex: 1 0 80px;
                padding: 8px 10px;
            }
            .stat-box .value {
                font-size: 15px;
            }
            .chart-box {
                flex: 1 1 100%;
            }
            .pagination-wrapper {
                flex-direction: column;
                align-items: stretch;
            }
            .per-page-selector {
                justify-content: center;
            }
            .pagination {
                justify-content: center;
            }
        }

        /* ============================================================
           PROFESSIONAL PRINT STYLES
           ============================================================ */
        @media print {
            @page {
                size: A4;
                margin: 1.8cm 1.5cm;
            }

            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
                font-family: 'Helvetica', 'Arial', sans-serif !important;
                font-size: 10pt !important;
                color: #000 !important;
            }

            .container-fluid {
                max-width: 100% !important;
                padding: 0 !important;
            }

            .filter-section,
            .filter-actions,
            .btn,
            .btn-print,
            .btn-asb-secondary,
            .select2-container,
            .pagination-wrapper,
            .performance-metrics {
                display: none !important;
            }

            .asb-card {
                border: 1px solid #ccc !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                margin-bottom: 15px !important;
                page-break-inside: avoid;
            }

            .asb-card-header {
                background: #b71c1c !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-bottom: 2px solid #8b0000 !important;
                padding: 8px 15px !important;
                font-size: 12pt !important;
            }

            .asb-card-body {
                padding: 12px 15px !important;
            }

            .asb-header-title {
                font-size: 18pt !important;
                border-left: 5px solid #b71c1c !important;
                padding-left: 12px !important;
                margin-bottom: 15px !important;
                color: #b71c1c !important;
            }
            .asb-header-title span {
                font-size: 12pt !important;
                color: #555 !important;
            }

            .stat-box {
                background: #f5f5f5 !important;
                border-left: 3px solid #b71c1c !important;
                padding: 8px 10px !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .stat-box .label {
                font-size: 8pt !important;
                color: #555 !important;
            }
            .stat-box .value {
                font-size: 14pt !important;
                color: #000 !important;
            }
            .stat-box .value.positive { color: #2e7d32 !important; }
            .stat-box .value.negative { color: #c62828 !important; }

            .chart-box {
                border: 1px solid #ddd !important;
                background: #fafafa !important;
                page-break-inside: avoid;
                padding: 10px !important;
            }
            .chart-box canvas {
                max-height: 180px !important;
                width: 100% !important;
            }

            table {
                font-size: 9pt !important;
                border-collapse: collapse !important;
                width: 100% !important;
            }
            th {
                background: #b71c1c !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                padding: 5px 6px !important;
                border-bottom: 2px solid #8b0000 !important;
                font-size: 8pt !important;
            }
            td {
                padding: 4px 6px !important;
                border-bottom: 1px solid #ddd !important;
            }
            tr:nth-child(even) td {
                background: #f9f9f9 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .overall-row td {
                background: #fce4e4 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border-top: 2px solid #b71c1c !important;
                border-bottom: 2px solid #b71c1c !important;
            }

            .asb-footer {
                border-top: 1px solid #aaa !important;
                background: #f8f8f8 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                padding: 8px 0 !important;
                font-size: 8pt !important;
                color: #333 !important;
                margin-top: 20px !important;
            }
            .asb-footer .sub {
                font-size: 8pt !important;
                color: #555 !important;
            }
            .asb-footer .sub strong {
                color: #b71c1c !important;
            }

            .table-card {
                page-break-before: always;
            }

            .empty-state {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="container-fluid">

    <!-- ===== HEADER ===== -->
    <h2 class="asb-header-title">
        ASB Fashion <span>| PO Summary Report</span>
    </h2>

    <!-- ===== FILTER CARD ===== -->
    <div class="asb-card filter-section">
        <div class="asb-card-header">
            <span><i class="fas fa-filter"></i> Filter Options</span>
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <span style="font-size:11px; color:#888;">Select criteria and click Generate</span>
                <a href="dashboard.php" class="btn-asb-secondary" style="font-size:12px; padding:4px 12px;"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>
        <div class="asb-card-body">
            <form method="GET" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <label>From</label>
                        <input type="date" name="from_date" class="asb-input" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <label>To</label>
                        <input type="date" name="to_date" class="asb-input" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="filter-group" style="flex: 1 0 150px;">
                        <label><i class="fas fa-search"></i> Search Supplier</label>
                        <input type="text" name="search" class="asb-input" value="<?= htmlspecialchars($search) ?>" placeholder="Supplier name...">
                    </div>
                    <div class="filter-group">
                        <label>Suppliers</label>
                        <select name="supplier_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($supplierOptions as $s): ?>
                                <option value="<?= $s['supplier_id'] ?>" <?= in_array($s['supplier_id'], $supplierIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Departments</label>
                        <select name="department_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>" <?= in_array($d['department_id'], $deptIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Sub Departments</label>
                        <select name="sub_department_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($subDepartments as $sd): ?>
                                <option value="<?= $sd['sub_department_id'] ?>" <?= in_array($sd['sub_department_id'], $subDeptIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sd['sub_department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Categories</label>
                        <select name="category_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['category_id'] ?>" <?= in_array($c['category_id'], $catIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Colors</label>
                        <select name="color_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($colors as $c): ?>
                                <option value="<?= $c['color_id'] ?>" <?= in_array($c['color_id'], $colorIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['color_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Sizes</label>
                        <select name="size_ids[]" multiple="multiple" class="select2-multi">
                            <?php foreach ($sizes as $s): ?>
                                <option value="<?= $s['size_id'] ?>" <?= in_array($s['size_id'], $sizeIds) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['size_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-asb"><i class="fas fa-filter"></i> Generate</button>
                        <a href="?" class="btn-asb-secondary"><i class="fas fa-undo"></i> Clear</a>
                        <button type="button" class="btn-asb btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                        <button type="button" class="btn-asb btn-export" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($suppliers) && $totalSuppliers > 0): ?>
    <!-- ===== PAGE 1: STATS + CHARTS ===== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-chart-pie"></i> Key Metrics</span>
            <span style="font-size:11px; color:#888;">
                Report Period: <?= htmlspecialchars($fromDate) ?> – <?= htmlspecialchars($toDate) ?>
                <?php if ($totalSuppliers > $limit): ?>
                    | Showing <?= number_format($offset + 1) ?> – <?= number_format(min($offset + $limit, $totalSuppliers)) ?> of <?= number_format($totalSuppliers) ?> suppliers
                <?php endif; ?>
            </span>
        </div>
        <div class="asb-card-body">
            <div class="stats-row">
                <div class="stat-box">
                    <div class="label">Total POs</div>
                    <div class="value"><?= number_format($overall['po_count']) ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Total Quantity</div>
                    <div class="value"><?= number_format($overall['total_quantity']) ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Total Cost (LKR)</div>
                    <div class="value"><?= fmt($overall['total_cost']) ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Total Selling (LKR)</div>
                    <div class="value"><?= fmt($overall['total_selling']) ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Gross Profit (LKR)</div>
                    <div class="value <?= $overall['profit'] >= 0 ? 'positive' : 'negative' ?>">
                        <?= fmt($overall['profit']) ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="label">Avg. Days Since PO</div>
                    <div class="value"><?= round($overall['avg_days'], 1) ?> d</div>
                </div>
            </div>
        </div>
    </div>

    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-chart-bar"></i> Trend & Distribution Analysis</span>
        </div>
        <div class="asb-card-body">
            <div class="chart-row">
                <div class="chart-box">
                    <h4><i class="fas fa-chart-bar"></i> Monthly Cost vs. Selling</h4>
                    <canvas id="barChart"></canvas>
                </div>
                <div class="chart-box">
                    <h4><i class="fas fa-chart-pie"></i> Cost by Supplier (Top 10)</h4>
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== PAGE 2: DETAILED TABLE ===== -->
    <div class="asb-card table-card">
        <div class="asb-card-header">
            <span><i class="fas fa-table"></i> Supplier Breakdown</span>
            <span style="font-size:11px; color:#888;">
                <i class="far fa-calendar-alt"></i> <?= htmlspecialchars($fromDate) ?> – <?= htmlspecialchars($toDate) ?>
                <?php if ($totalSuppliers > $limit): ?>
                    | Page <?= $page ?> of <?= $totalPages ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="asb-card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th class="text-right">POs</th>
                            <th>PO Numbers</th>
                            <th class="text-right">Total Qty</th>
                            <th class="text-right">Total Cost (LKR)</th>
                            <th class="text-right">Total Selling (LKR)</th>
                            <th class="text-right">Profit (LKR)</th>
                            <th class="text-right">Avg Days</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $row): 
                            $profit = $row['total_selling'] - $row['total_cost'];
                            $profitClass = $profit >= 0 ? 'profit-positive' : 'profit-negative';
                        ?>
                        <tr>
                            <td class="supplier-name"><?= htmlspecialchars($row['supplier_name']) ?></td>
                            <td class="text-right"><?= number_format($row['po_count']) ?></td>
                            <td style="font-size:11px; max-width:200px; word-break:break-word;"><?= htmlspecialchars($row['po_numbers'] ?? '') ?></td>
                            <td class="text-right"><?= number_format($row['total_quantity']) ?></td>
                            <td class="text-right"><?= fmt($row['total_cost']) ?></td>
                            <td class="text-right"><?= fmt($row['total_selling']) ?></td>
                            <td class="text-right <?= $profitClass ?>"><?= fmt($profit) ?></td>
                            <td class="text-right"><?= round($row['avg_days_since_purchase'], 1) ?> d</td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- OVERALL TOTALS -->
                        <?php 
                            $overallProfit = $overall['total_selling'] - $overall['total_cost'];
                            $overallClass = $overallProfit >= 0 ? 'profit-positive' : 'profit-negative';
                        ?>
                        <tr class="overall-row">
                            <td><span style="color:#8b0000;">📌 TOTAL (All Suppliers)</span></td>
                            <td class="text-right"><?= number_format($overall['po_count']) ?></td>
                            <td>—</td>
                            <td class="text-right"><?= number_format($overall['total_quantity']) ?></td>
                            <td class="text-right"><?= fmt($overall['total_cost']) ?></td>
                            <td class="text-right"><?= fmt($overall['total_selling']) ?></td>
                            <td class="text-right <?= $overallClass ?>"><?= fmt($overallProfit) ?></td>
                            <td class="text-right"><?= round($overall['avg_days'], 1) ?> d</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- ===== PAGINATION ===== -->
            <?php if ($totalSuppliers > $limit): ?>
            <div class="pagination-wrapper">
                <div class="per-page-selector">
                    <label for="perPage">Rows per page:</label>
                    <select id="perPage" onchange="changeLimit(this.value)">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    <span style="color:#888; font-size:12px;">
                        Showing <?= number_format($offset + 1) ?> – <?= number_format(min($offset + $limit, $totalSuppliers)) ?> of <?= number_format($totalSuppliers) ?>
                    </span>
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1, 'limit' => $limit])) ?>">&laquo;</a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1, 'limit' => $limit])) ?>">&lsaquo;</a>
                    <?php else: ?>
                        <span class="disabled">&laquo;</span>
                        <span class="disabled">&lsaquo;</span>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    if ($startPage > 1) {
                        echo '<span>...</span>';
                    }
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i, 'limit' => $limit])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($endPage < $totalPages): ?>
                        <span>...</span>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1, 'limit' => $limit])) ?>">&rsaquo;</a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages, 'limit' => $limit])) ?>">&raquo;</a>
                    <?php else: ?>
                        <span class="disabled">&rsaquo;</span>
                        <span class="disabled">&raquo;</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
        <div class="asb-card">
            <div class="asb-card-body">
                <div class="empty-state">
                    <i class="fas fa-inbox" style="font-size:32px; color:#ccc; display:block; margin-bottom:10px;"></i>
                    <p>No purchase orders found for the selected filters.</p>
                    <p style="font-size:14px; color:#aaa;">Please adjust the date range or filter criteria.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ===== FOOTER ===== -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span class="sub">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
        <div style="margin-top:6px; font-size:10px; color:#aaa;">
            Generated on <?= $currentDate ?> at <?= $currentTime ?> &nbsp;|&nbsp; Sri Lanka (Colombo)
            <?php if (!empty($search) || !empty($supplierIds) || !empty($deptIds) || !empty($subDeptIds) || !empty($catIds) || !empty($colorIds) || !empty($sizeIds)): ?>
                <br>Active filters: 
                <?php 
                    $filterParts = [];
                    if (!empty($search)) $filterParts[] = "Search: " . htmlspecialchars($search);
                    if (!empty($supplierIds)) {
                        $names = array_column($supplierOptions, 'supplier_name', 'supplier_id');
                        $selected = array_intersect_key($names, array_flip($supplierIds));
                        $filterParts[] = "Suppliers: " . implode(', ', array_map('htmlspecialchars', $selected));
                    }
                    if (!empty($deptIds)) {
                        $names = array_column($departments, 'department_name', 'department_id');
                        $selected = array_intersect_key($names, array_flip($deptIds));
                        $filterParts[] = "Dept: " . implode(', ', array_map('htmlspecialchars', $selected));
                    }
                    if (!empty($subDeptIds)) {
                        $names = array_column($subDepartments, 'sub_department_name', 'sub_department_id');
                        $selected = array_intersect_key($names, array_flip($subDeptIds));
                        $filterParts[] = "Sub: " . implode(', ', array_map('htmlspecialchars', $selected));
                    }
                    if (!empty($catIds)) {
                        $names = array_column($categories, 'category_name', 'category_id');
                        $selected = array_intersect_key($names, array_flip($catIds));
                        $filterParts[] = "Cat: " . implode(', ', array_map('htmlspecialchars', $selected));
                    }
                    if (!empty($colorIds)) {
                        $names = array_column($colors, 'color_name', 'color_id');
                        $selected = array_intersect_key($names, array_flip($colorIds));
                        $filterParts[] = "Color: " . implode(', ', array_map('htmlspecialchars', $selected));
                    }
                    if (!empty($sizeIds)) {
                        $names = array_column($sizes, 'size_name', 'size_id');
                        $selected = array_intersect_key($names, array_flip($sizeIds));
                        $filterParts[] = "Size: " . implode(', ', array_map('htmlspecialchars', $selected));
                    }
                    echo implode(' | ', $filterParts);
                ?>
            <?php endif; ?>
        </div>
        <div class="performance-metrics">
            <i class="fas fa-tachometer-alt"></i> 
            <?= number_format($executionTime, 2) ?>s &nbsp;|&nbsp;
            <?= number_format($memoryUsage, 1) ?> MB memory &nbsp;|&nbsp;
            <?= number_format($totalSuppliers) ?> total suppliers
            <?php if ($totalSuppliers > $limit): ?>
                &nbsp;|&nbsp; Page <?= $page ?> of <?= $totalPages ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- jQuery & Select2 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2 for multi-selects
    $('.select2-multi').select2({
        placeholder: 'Select options',
        allowClear: true,
        width: '100%',
        closeOnSelect: false,
        maximumSelectionLength: 50
    });

    <?php if (!empty($suppliers) && $totalSuppliers > 0): ?>
    // ----- BAR CHART -----
    const ctxBar = document.getElementById('barChart').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartMonths) ?>,
            datasets: [
                {
                    label: 'Total Cost (LKR)',
                    data: <?= json_encode($chartCost) ?>,
                    backgroundColor: 'rgba(211, 47, 47, 0.7)',
                    borderColor: '#d32f2f',
                    borderWidth: 1,
                },
                {
                    label: 'Total Selling (LKR)',
                    data: <?= json_encode($chartSelling) ?>,
                    backgroundColor: 'rgba(46, 125, 50, 0.7)',
                    borderColor: '#2e7d32',
                    borderWidth: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) {
                                return 'LKR ' + (value / 1000000).toFixed(1) + 'M';
                            } else if (value >= 1000) {
                                return 'LKR ' + (value / 1000).toFixed(0) + 'K';
                            }
                            return 'LKR ' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // ----- PIE CHART -----
    const ctxPie = document.getElementById('pieChart').getContext('2d');
    const pieLabels = <?= json_encode($pieLabels) ?>;
    const pieValues = <?= json_encode($pieValues) ?>;
    const colors = [
        '#d32f2f', '#f44336', '#e57373', '#ef5350', '#b71c1c',
        '#2e7d32', '#388e3c', '#4caf50', '#81c784', '#a5d6a7'
    ];
    new Chart(ctxPie, {
        type: 'pie',
        data: {
            labels: pieLabels,
            datasets: [{
                data: pieValues,
                backgroundColor: colors.slice(0, pieLabels.length),
                borderColor: '#fff',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        font: { size: 10 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            if (total === 0) return label + ': LKR 0 (0%)';
                            let percentage = ((value / total) * 100).toFixed(1);
                            return label + ': LKR ' + value.toLocaleString() + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

// ----- PAGINATION FUNCTIONS -----
function changeLimit(limit) {
    var url = new URL(window.location.href);
    url.searchParams.set('limit', limit);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// ----- EXPORT CSV FUNCTION -----
function exportCSV() {
    var url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    url.searchParams.set('limit', <?= MAX_EXPORT_ROWS ?>);
    window.location.href = url.toString();
}
</script>
</body>
</html>