<?php
/**
 * PO Status Matrix & Detailed Line Report - ASB Fashion
 * Designed & Developed by Vexel IT by Kavizz
 */

date_default_timezone_set('Asia/Colombo');

$dbHost = '127.0.0.1';
$dbName = 'po_system';
$dbUser = 'root';
$dbPass = '';

define('MAX_EXPORT_ROWS', 50000);
define('PAGINATION_LIMIT', 40);
define('QUERY_TIMEOUT', 600);

@ini_set('memory_limit', '512M');
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
    $pdo->exec("SET SESSION group_concat_max_len = 1048576"); 
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ===== GET FILTER VALUES =====
$fromDate     = isset($_GET['from_date']) && $_GET['from_date'] ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$toDate       = isset($_GET['to_date']) && $_GET['to_date'] ? $_GET['to_date'] : date('Y-m-d');
$search       = isset($_GET['search']) ? trim($_GET['search']) : '';
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit        = isset($_GET['limit']) ? min(200, max(10, (int)$_GET['limit'])) : PAGINATION_LIMIT;
$offset       = ($page - 1) * $limit;
$exportCSV    = isset($_GET['export']) && $_GET['export'] === 'csv';
$supplierIds  = isset($_GET['supplier_ids']) && is_array($_GET['supplier_ids']) ? array_map('intval', $_GET['supplier_ids']) : [];

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
    if (!empty($filters['search'])) {
        $conditions[] = "s.supplier_name LIKE :search";
        $params['search'] = "%{$filters['search']}%";
    }
    return 'WHERE ' . implode(' AND ', $conditions);
}

$filters = ['from_date' => $fromDate, 'to_date' => $toDate, 'search' => $search, 'supplier_ids' => $supplierIds];
$params = [];
$whereClause = buildWhereClause($filters, $params);

// Count distinct suppliers for pagination
$countSql = "
    SELECT COUNT(DISTINCT s.supplier_id) as total
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    $whereClause
";

try {
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalSuppliers = (int)$stmtCount->fetchColumn();
    $totalPages = ceil($totalSuppliers / $limit);
} catch (PDOException $e) {
    $totalSuppliers = 0; $totalPages = 0;
}

// ===== MAIN MATRIX QUERY =====
$sql = "
    SELECT
        s.supplier_id,
        s.supplier_name,
        SUM(CASE WHEN ph.status = 'Completed' THEN pi.quantity ELSE 0 END) AS completed_qty,
        SUM(CASE WHEN ph.status = 'Pending' THEN pi.quantity ELSE 0 END) AS pending_qty,
        SUM(CASE WHEN ph.status = 'Received' THEN pi.quantity ELSE 0 END) AS received_qty,
        SUM(pi.quantity) AS grand_total_qty,
        GROUP_CONCAT(
            CONCAT_WS('||', 
                ph.po_number, 
                ph.status, 
                ph.purchase_date, 
                COALESCE(ph.expected_delivery_date, 'N/A'), 
                COALESCE(pi.item_qty_sum, pi.quantity)
            ) 
            ORDER BY ph.purchase_date ASC 
            SEPARATOR '::'
        ) AS po_breakdown_list
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN (
        SELECT po_id, SUM(quantity) as item_qty_sum, quantity 
        FROM po_items GROUP BY po_id
    ) pi ON ph.po_id = pi.po_id
    $whereClause
    GROUP BY s.supplier_id, s.supplier_name
    ORDER BY s.supplier_name
";

if (!$exportCSV) {
    $sql .= " LIMIT :offset, :limit";
} else {
    $sql .= " LIMIT " . MAX_EXPORT_ROWS;
}

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    if (!$exportCSV) {
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $suppliers = $stmt->fetchAll();
    
    if ($exportCSV && !empty($suppliers)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="asb_po_detailed_matrix_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Supplier', 'Completed Qty', 'Pending Qty', 'Received Qty', 'Grand Total Qty', 'PO Breakdown (Number | Status | Order Date | Expected Date | Qty)']);
        foreach ($suppliers as $row) {
            fputcsv($output, [$row['supplier_name'], $row['completed_qty'], $row['pending_qty'], $row['received_qty'], $row['grand_total_qty'], str_replace('::', ' | ', $row['po_breakdown_list'])]);
        }
        fclose($output);
        exit;
    }
} catch (PDOException $e) {
    die("Query execution failed: " . $e->getMessage());
}

// Overall Totals
$totalsSql = "
    SELECT
        SUM(CASE WHEN ph.status = 'Completed' THEN pi.quantity ELSE 0 END) AS completed_qty,
        SUM(CASE WHEN ph.status = 'Pending' THEN pi.quantity ELSE 0 END) AS pending_qty,
        SUM(CASE WHEN ph.status = 'Received' THEN pi.quantity ELSE 0 END) AS received_qty,
        SUM(pi.quantity) AS grand_total_qty
    FROM po_header ph
    INNER JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
    INNER JOIN po_items pi ON ph.po_id = pi.po_id
    $whereClause
";
$stmtTotals = $pdo->prepare($totalsSql);
$stmtTotals->execute($params);
$overall = $stmtTotals->fetch() ?: ['completed_qty' => 0, 'pending_qty' => 0, 'received_qty' => 0, 'grand_total_qty' => 0];

$supplierOptions = $pdo->query("SELECT supplier_id, supplier_name FROM return_qc.suppliers ORDER BY supplier_name LIMIT 1000")->fetchAll();
$currentDate = date('Y-m-d');
$currentTime = date('h:i:s A');
$executionTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Status Matrix & Detailed Breakdown - ASB Fashion</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; padding: 15px; color: #333; }
        .container-fluid { max-width: 1450px; margin: 0 auto; }
        
        .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; border-left: 5px solid #d32f2f; padding-left: 12px; margin-bottom: 20px; font-size: 20px; display: flex; justify-content: space-between; align-items: center; }
        .asb-header-title span { font-weight: 300; color: #555; font-size: 14px; }
        
        .print-report-header { display: none; margin-bottom: 15px; border-bottom: 2px solid #b71c1c; padding-bottom: 8px; }
        
        .asb-card { background: #ffffff; border: 1px solid #dcdcdc; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); margin-bottom: 20px; overflow: hidden; }
        .asb-card-header { background: #fafafa; border-bottom: 1px solid #e0e0e0; padding: 12px 15px; color: #b71c1c; font-weight: bold; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
        .asb-card-body { padding: 15px; }
        
        .filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 3px; flex: 1 0 150px; }
        .filter-group label { font-size: 11px; font-weight: 600; color: #555; }
        .asb-input { border: 1px solid #ccc; padding: 5px 8px; border-radius: 4px; width: 100%; font-size: 12px; background: #fff; }
        
        .btn-asb { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 5px 14px; border-radius: 4px; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .btn-asb:hover { background: #b71c1c; }
        .btn-asb-secondary { background: #f5f5f5; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 5px 14px; border-radius: 4px; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-print { background: #2c3e50; color: #fff; }
        .btn-export { background: #1b5e20; color: #fff; }
        
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #f1f3f5; color: #222; font-weight: 700; font-size: 11px; text-transform: uppercase; padding: 10px 8px; border-bottom: 2px solid #b71c1c; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
        
        .supplier-row td { background: #fff; font-weight: 700; border-top: 2px solid #cbd5e0; color: #1a202c; }
        
        .po-breakdown-row td { background: #fcfdfe !important; padding: 5px 8px 10px 25px; border-bottom: 1px solid #e2e8f0; }
        .po-grid-table { width: 100%; border-collapse: collapse; margin-top: 3px; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden; }
        .po-grid-table th { background: #edf2f7; color: #4a5568; font-size: 10px; padding: 5px 8px; border-bottom: 1px solid #cbd5e0; text-transform: uppercase; }
        .po-grid-table td { padding: 5px 8px; font-size: 11px; border-bottom: 1px solid #edf2f7; color: #2d3748; }
        .po-grid-table tr:last-child td { border-bottom: none; }
        
        .status-badge { padding: 2px 6px; border-radius: 10px; font-size: 9px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-Pending { background: #fffaf0; color: #9c4221; border: 1px solid #feebc8; }
        .status-Completed { background: #f0fff4; color: #22543d; border: 1px solid #c6f6d5; }
        .status-Received { background: #ebf8ff; color: #2b6cb0; border: 1px solid #bee3f8; }

        .overall-row td { background: #fce4e4 !important; font-weight: 700; border-top: 3px solid #b71c1c; border-bottom: 2px solid #b71c1c; color: #b71c1c; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .pagination-wrapper { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 12px; border-top: 1px solid #eee; font-size: 12px; }
        .pagination { display: flex; gap: 4px; }
        .pagination a, .pagination span { padding: 4px 10px; border: 1px solid #ddd; border-radius: 3px; text-decoration: none; color: #333; font-size: 12px; background: #fff; }
        .pagination .active { background: #d32f2f; color: #fff; border-color: #d32f2f; }
        
        .asb-footer { text-align: center; margin-top: 30px; padding: 12px; color: #666; border-top: 1px solid #e0e0e0; font-size: 11px; line-height: 1.5; }
        .asb-footer strong { color: #b71c1c; }

        /* ============================================================
           PROFESSIONAL FULL-PAGE A4 PRINT STYLES
           ============================================================ */
        @media print {
            @page { 
                size: A4 portrait; 
                margin: 1cm;
                @bottom-right {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 8pt;
                    font-family: 'Segoe UI', Tahoma, sans-serif;
                    color: #555;
                }
            }
            body { background: #fff !important; font-size: 8pt !important; color: #000 !important; padding: 0 !important; }
            .container-fluid { max-width: 100% !important; padding: 0 !important; }
            .filter-section, .filter-actions, .btn, .pagination-wrapper { display: none !important; }
            
            .print-report-header { display: block !important; }
            
            .asb-card { border: none !important; box-shadow: none !important; margin: 0 !important; }
            .asb-card-header { background: #b71c1c !important; color: #fff !important; -webkit-print-color-adjust: exact; padding: 4px 8px !important; }
            th { background: #b71c1c !important; color: #fff !important; -webkit-print-color-adjust: exact; padding: 5px !important; }
            .supplier-row td { background: #edf2f7 !important; -webkit-print-color-adjust: exact; font-weight: bold; }
            .po-breakdown-row td { background: #fff !important; padding: 2px 2px 5px 15px !important; }
            .po-grid-table { border: 1px solid #bbb !important; }
            .po-grid-table th { background: #e2e8f0 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
            .overall-row td { background: #fce4e4 !important; -webkit-print-color-adjust: exact; }
            
            .asb-footer { 
                display: block;
                page-break-inside: avoid;
                border-top: 1px solid #000 !important; 
                background: #fff !important; 
                -webkit-print-color-adjust: exact; 
                padding: 8px 0 !important; 
                font-size: 8pt !important;
                margin-top: 20px !important;
            }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <h2 class="asb-header-title">
        ASB Fashion <span>| PO Matrix &amp; Detailed Line Report</span>
        <a href="dashboard.php" class="btn-asb-secondary" style="font-size: 13px; padding: 6px 14px;"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </h2>

    <!-- PRINT ONLY HEADER METADATA (Includes From & To Dates) -->
    <div class="print-report-header">
        <h3 style="color: #b71c1c; font-size: 13pt; font-weight: bold; text-transform: uppercase;">ASB Fashion - Purchase Order Status Report</h3>
        <p style="font-size: 8.5pt; color: #333; margin-top: 3px;">
            <strong>Filter Period:</strong> From <u><?= htmlspecialchars($fromDate) ?></u> To <u><?= htmlspecialchars($toDate) ?></u>
            &nbsp;|&nbsp; <strong>Printed On:</strong> <?= $currentDate ?> at <?= $currentTime ?>
        </p>
    </div>

    <!-- FILTER SECTION -->
    <div class="asb-card filter-section">
        <div class="asb-card-header">
            <span><i class="fas fa-filter"></i> Filter Criteria</span>
        </div>
        <div class="asb-card-body">
            <form method="GET">
                <div class="filter-row">
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <label>From Date</label>
                        <input type="date" name="from_date" class="asb-input" value="<?= htmlspecialchars($fromDate) ?>">
                    </div>
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <label>To Date</label>
                        <input type="date" name="to_date" class="asb-input" value="<?= htmlspecialchars($toDate) ?>">
                    </div>
                    <div class="filter-group" style="flex: 1 0 140px;">
                        <label>Search Supplier</label>
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
                    <div style="display: flex; gap: 6px; align-items: flex-end; margin-left: auto;">
                        <button type="submit" class="btn-asb"><i class="fas fa-filter"></i> Generate</button>
                        <a href="?" class="btn-asb-secondary"><i class="fas fa-undo"></i> Clear</a>
                        <button type="button" class="btn-asb btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print A4</button>
                        <button type="button" class="btn-asb btn-export" onclick="exportCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- REPORT TABLE MATRIX -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-table"></i> Supplier Matrix &amp; Individual PO Breakdown Grid</span>
            <span style="font-size:11px; color:#555;">Active Filter Range: <?= htmlspecialchars($fromDate) ?> to <?= htmlspecialchars($toDate) ?></span>
        </div>
        <div class="asb-card-body">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 30%;">Supplier Name</th>
                            <th class="text-right" style="width: 13%;">Completed Qty</th>
                            <th class="text-right" style="width: 13%;">Pending Qty</th>
                            <th class="text-right" style="width: 13%;">Received Qty</th>
                            <th class="text-right" style="width: 15%;">Grand Total Qty</th>
                            <th class="text-center" style="width: 16%;">Action / Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($suppliers)): ?>
                            <?php foreach ($suppliers as $row): ?>
                            <!-- Supplier Summary Row -->
                            <tr class="supplier-row">
                                <td><i class="fas fa-chevron-right" style="font-size:10px; color:#b71c1c; margin-right:5px;"></i> <?= htmlspecialchars($row['supplier_name']) ?></td>
                                <td class="text-right"><?= $row['completed_qty'] > 0 ? number_format($row['completed_qty']) : '—' ?></td>
                                <td class="text-right"><?= $row['pending_qty'] > 0 ? number_format($row['pending_qty']) : '—' ?></td>
                                <td class="text-right"><?= $row['received_qty'] > 0 ? number_format($row['received_qty']) : '—' ?></td>
                                <td class="text-right" style="color: #b71c1c; font-weight:800;"><?= number_format($row['grand_total_qty']) ?></td>
                                <td class="text-center" style="font-size: 11px; color: #555;">Detailed Breakdown Below</td>
                            </tr>
                            
                            <!-- Detailed PO-wise Clean Grid Sub-Row -->
                            <tr class="po-breakdown-row">
                                <td colspan="6">
                                    <table class="po-grid-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 25%;">PO Number</th>
                                                <th style="width: 18%;">Status</th>
                                                <th style="width: 22%;">Order Date</th>
                                                <th style="width: 22%;">Expected Date</th>
                                                <th class="text-right" style="width: 13%;">Quantity</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            if (!empty($row['po_breakdown_list'])) {
                                                $pos = explode('::', $row['po_breakdown_list']);
                                                foreach ($pos as $poEntry) {
                                                    $parts = explode('||', $poEntry);
                                                    $poNum   = $parts[0] ?? '';
                                                    $poStat  = $parts[1] ?? 'Pending';
                                                    $ordDate = $parts[2] ?? '';
                                                    $expDate = $parts[3] ?? 'N/A';
                                                    $poQty   = $parts[4] ?? 0;
                                                    
                                                    echo '<tr>';
                                                    echo '<td><strong><i class="fas fa-file-alt" style="color:#b71c1c; margin-right:4px;"></i> ' . htmlspecialchars($poNum) . '</strong></td>';
                                                    echo '<td><span class="status-badge status-' . htmlspecialchars($poStat) . '">' . htmlspecialchars($poStat) . '</span></td>';
                                                    echo '<td>' . htmlspecialchars($ordDate) . '</td>';
                                                    echo '<td>' . htmlspecialchars($expDate) . '</td>';
                                                    echo '<td class="text-right" style="font-weight:700;">' . number_format($poQty) . '</td>';
                                                    echo '</tr>';
                                                }
                                            } else {
                                                echo '<tr><td colspan="5" style="text-align:center; color:#888; font-style:italic;">No purchase orders found.</td></tr>';
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <!-- GRAND TOTAL ROW -->
                            <tr class="overall-row">
                                <td>📌 GRAND TOTAL (All Suppliers)</td>
                                <td class="text-right"><?= number_format($overall['completed_qty']) ?></td>
                                <td class="text-right"><?= number_format($overall['pending_qty']) ?></td>
                                <td class="text-right"><?= number_format($overall['received_qty']) ?></td>
                                <td class="text-right"><?= number_format($overall['grand_total_qty']) ?></td>
                                <td class="text-center">—</td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 30px; color: #777;">No purchase orders found for the selected criteria.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION -->
            <?php if ($totalSuppliers > $limit): ?>
            <div class="pagination-wrapper">
                <span>
                    Showing suppliers <?= number_format($offset + 1) ?> – <?= number_format(min($offset + $limit, $totalSuppliers)) ?> of <?= number_format($totalSuppliers) ?>
                </span>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo; Prev</a>
                    <?php endif; ?>
                    <span class="active"><?= $page ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CREATIVE FOOTER (Appears ONLY on the Last Page of Printed Document) -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size: 11px; color: #b71c1c; font-weight: 700;">System Designed &amp; Developed by Vexel IT by Kavizz</span><br>
        <span style="font-size: 10px; color: #777;">Generated on <?= $currentDate ?> at <?= $currentTime ?> | Execution Time: <?= number_format($executionTime, 2) ?>s</span>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.select2-multi').select2({ placeholder: 'Select suppliers', allowClear: true, width: '100%' });
});
function exportCSV() {
    var url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    window.location.href = url.toString();
}
</script>
</body>
</html>