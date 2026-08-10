<?php
/**
 * PO Summary Report - ASB Fashion PO System
 * Designed & Developed by Vexel IT (Kavizz)
 */

date_default_timezone_set('Asia/Colombo');

$dbHost = '127.0.0.1';
$dbName = 'po_system';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ----- GET FILTER VALUES -----
$fromDate  = isset($_GET['from_date']) && $_GET['from_date'] ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$toDate    = isset($_GET['to_date']) && $_GET['to_date'] ? $_GET['to_date'] : date('Y-m-d');
$search    = isset($_GET['search']) ? trim($_GET['search']) : '';

$supplierIds= isset($_GET['supplier_ids']) && is_array($_GET['supplier_ids']) ? array_map('intval', $_GET['supplier_ids']) : [];
$deptIds    = isset($_GET['department_ids']) && is_array($_GET['department_ids']) ? array_map('intval', $_GET['department_ids']) : [];
$subDeptIds = isset($_GET['sub_department_ids']) && is_array($_GET['sub_department_ids']) ? array_map('intval', $_GET['sub_department_ids']) : [];
$catIds     = isset($_GET['category_ids']) && is_array($_GET['category_ids']) ? array_map('intval', $_GET['category_ids']) : [];
$colorIds   = isset($_GET['color_ids']) && is_array($_GET['color_ids']) ? array_map('intval', $_GET['color_ids']) : [];
$sizeIds    = isset($_GET['size_ids']) && is_array($_GET['size_ids']) ? array_map('intval', $_GET['size_ids']) : [];

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

// ===== BASE WHERE CLAUSE (exclude cancelled POs) =====
$whereConditions = [
    'ph.purchase_date BETWEEN :from_date AND :to_date',
    'ph.status != "Cancelled"'
];
$params = ['from_date' => $fromDate, 'to_date' => $toDate];
$paramIndex = 0;

if (!empty($supplierIds)) {
    $in = buildInClause($supplierIds, 'sup', $params, $paramIndex);
    $whereConditions[] = "s.supplier_id IN ($in)";
}
if (!empty($deptIds)) {
    $in = buildInClause($deptIds, 'dept', $params, $paramIndex);
    $whereConditions[] = "i.department_id IN ($in)";
}
if (!empty($subDeptIds)) {
    $in = buildInClause($subDeptIds, 'subdept', $params, $paramIndex);
    $whereConditions[] = "i.sub_department_id IN ($in)";
}
if (!empty($catIds)) {
    $in = buildInClause($catIds, 'cat', $params, $paramIndex);
    $whereConditions[] = "i.category_id IN ($in)";
}
if (!empty($colorIds)) {
    $in = buildInClause($colorIds, 'color', $params, $paramIndex);
    $whereConditions[] = "i.color_id IN ($in)";
}
if (!empty($sizeIds)) {
    $in = buildInClause($sizeIds, 'size', $params, $paramIndex);
    $whereConditions[] = "i.size_id IN ($in)";
}
if (!empty($search)) {
    $whereConditions[] = "s.supplier_name LIKE :search";
    $params['search'] = "%$search%";
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// ----- MAIN QUERY: Supplier Summary (with PO numbers) -----
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

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage() . "<br>SQL: " . $sql);
}

// ----- OVERALL TOTALS -----
$overall = [
    'po_count'      => 0,
    'total_quantity'=> 0,
    'total_cost'    => 0,
    'total_selling' => 0,
    'avg_days'      => 0,
];
foreach ($suppliers as $row) {
    $overall['po_count']       += $row['po_count'];
    $overall['total_quantity'] += $row['total_quantity'];
    $overall['total_cost']     += $row['total_cost'];
    $overall['total_selling']  += $row['total_selling'];
}
$overall['avg_days'] = count($suppliers) > 0 ? array_sum(array_column($suppliers, 'avg_days_since_purchase')) / count($suppliers) : 0;
$overall['profit'] = $overall['total_selling'] - $overall['total_cost'];

// ----- CHART DATA: Monthly Trend (bar chart data) -----
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
    ORDER BY month
";
$stmtMonth = $pdo->prepare($monthlySql);
$stmtMonth->execute($params);
$monthlyData = $stmtMonth->fetchAll(PDO::FETCH_ASSOC);

$chartMonths = array_column($monthlyData, 'month');
$chartCost = array_column($monthlyData, 'total_cost');
$chartSelling = array_column($monthlyData, 'total_selling');

// ----- CHART DATA: Supplier Cost Distribution (Top 10) -----
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
$stmtPie = $pdo->prepare($pieSql);
$stmtPie->execute($params);
$pieData = $stmtPie->fetchAll(PDO::FETCH_ASSOC);

$pieLabels = array_column($pieData, 'supplier_name');
$pieValues = array_column($pieData, 'total_cost');

// ----- FETCH FILTER OPTIONS -----
function fetchOptions($pdo, $table, $idField, $nameField) {
    $sql = "SELECT $idField, $nameField FROM $table ORDER BY $nameField";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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

function fmt($val) {
    return number_format((float)$val, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Summary Report - ASB Fashion</title>
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
        }

        /* ============================================================
               PROFESSIONAL PRINT STYLES – OPTIMISED FOR 2 A4 SHEETS
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
            .select2-container {
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

    <!-- ===== FILTER CARD (hidden in print) ===== -->
    <div class="asb-card filter-section">
        <div class="asb-card-header">
            <span><i class="fas fa-filter"></i> Filter Options</span>
            <div style="display: flex; align-items: center; gap: 15px;">
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
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($suppliers)): ?>
    <!-- ===== PAGE 1: STATS + CHARTS ===== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-chart-pie"></i> Key Metrics</span>
            <span style="font-size:11px; color:#888;">Report Period: <?= htmlspecialchars($fromDate) ?> – <?= htmlspecialchars($toDate) ?></span>
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

    <!-- ===== PAGE 2: DETAILED TABLE (with PO numbers) ===== -->
    <div class="asb-card table-card">
        <div class="asb-card-header">
            <span><i class="fas fa-table"></i> Supplier Breakdown</span>
            <span style="font-size:11px; color:#888;">
                <i class="far fa-calendar-alt"></i> <?= htmlspecialchars($fromDate) ?> – <?= htmlspecialchars($toDate) ?>
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
        width: '100%'
    });

    <?php if (!empty($suppliers)): ?>
    // ----- BAR CHART (Monthly Cost vs. Selling) -----
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
                            return 'LKR ' + value.toLocaleString();
                        }
                    }
                },
                x: {
                    stacked: false,
                }
            }
        }
    });

    // ----- PIE CHART (Cost by Supplier) -----
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
</script>
</body>
</html>