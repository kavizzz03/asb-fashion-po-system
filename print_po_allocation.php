<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$conn = getConnection();

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if (!$po_id) {
    die('Invalid PO ID.');
}

// Fetch PO data
$sql = "SELECT h.*, s.supplier_name FROM po_header h
        LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id
        WHERE h.po_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$header = $stmt->get_result()->fetch_assoc();
if (!$header) {
    die('PO not found.');
}

// Branches with company info
$branch_sql = "SELECT l.location_id, l.location_name, c.company_id, c.company_name
               FROM store_locations l
               LEFT JOIN companies c ON l.company_id = c.company_id
               ORDER BY c.company_name, l.location_name";
$branches = $conn->query($branch_sql)->fetch_all(MYSQLI_ASSOC);

// Items with allocations
$items_sql = "SELECT pi.po_item_id, pi.quantity AS po_qty,
                     i.item_code, i.item_name,
                     (SELECT COALESCE(SUM(quantity),0) FROM po_item_allocations WHERE po_item_id = pi.po_item_id) AS total_allocated
              FROM po_items pi
              JOIN items i ON pi.item_id = i.item_id
              WHERE pi.po_id = ?
              ORDER BY pi.po_item_id";
$stmt = $conn->prepare($items_sql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Allocation per item per branch
$alloc_sql = "SELECT a.po_item_id, a.location_id, a.quantity
              FROM po_item_allocations a
              JOIN po_items pi ON a.po_item_id = pi.po_item_id
              WHERE pi.po_id = ?";
$stmt = $conn->prepare($alloc_sql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$alloc_res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$alloc_map = [];
foreach ($alloc_res as $row) {
    $alloc_map[$row['po_item_id']][$row['location_id']] = (int)$row['quantity'];
}

// Prepare data for display
$items_data = [];
foreach ($items as $item) {
    $po_item_id = $item['po_item_id'];
    $branch_qty = [];
    foreach ($branches as $b) {
        $branch_qty[$b['location_id']] = $alloc_map[$po_item_id][$b['location_id']] ?? 0;
    }
    $items_data[] = [
        'item_code' => $item['item_code'],
        'item_name' => $item['item_name'],
        'po_qty' => (int)$item['po_qty'],
        'total_allocated' => (int)$item['total_allocated'],
        'remaining' => (int)$item['po_qty'] - (int)$item['total_allocated'],
        'branches' => $branch_qty
    ];
}

// Group branches by company for header grouping
$companyMap = [];
foreach ($branches as $b) {
    $cid = $b['company_id'] ?? 0;
    if (!isset($companyMap[$cid])) {
        $companyMap[$cid] = ['company_name' => $b['company_name'] ?? 'Unassigned', 'branches' => []];
    }
    $companyMap[$cid]['branches'][] = $b;
}
$companyIds = array_keys($companyMap);

// Compute totals
$grandTotalPO = 0;
$grandTotalAlloc = 0;
$grandRemaining = 0;
$branchTotals = [];
foreach ($branches as $b) { $branchTotals[$b['location_id']] = 0; }
$companyTotals = [];
foreach ($companyIds as $cid) { $companyTotals[$cid] = 0; }

foreach ($items_data as $item) {
    $grandTotalPO += $item['po_qty'];
    $grandTotalAlloc += $item['total_allocated'];
    $grandRemaining += $item['remaining'];
    foreach ($companyIds as $cid) {
        $comp = $companyMap[$cid];
        foreach ($comp['branches'] as $b) {
            $locId = $b['location_id'];
            $qty = $item['branches'][$locId] ?? 0;
            $branchTotals[$locId] += $qty;
            $companyTotals[$cid] += $qty;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO Allocation Report – <?php echo htmlspecialchars($header['po_number']); ?></title>
    <style>
        :root {
            --primary: #c0392b;       
            --primary-dark: #a93226;  
            --light-bg: #fdf2f0;      
            --border-color: #d98880;  
            --text-main: #2c3e50;     
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 8.5pt;
            line-height: 1.3;
            color: var(--text-main);
            background: #fff;
            padding: 8px;
        }
        .container {
            max-width: 100%;
        }
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2.5px solid var(--primary);
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .report-header .brand h1 {
            font-size: 15pt;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .report-header .brand small {
            font-size: 8pt;
            color: #7f8c8d;
        }
        .report-header .info {
            text-align: right;
            font-size: 8pt;
        }
        .report-header .info strong {
            color: var(--primary);
        }
        .po-details {
            display: flex;
            justify-content: space-between;
            background: var(--light-bg);
            padding: 6px 10px;
            border-radius: 4px;
            border-left: 4px solid var(--primary);
            border: 1px solid var(--border-color);
            border-left-width: 4px;
            margin-bottom: 10px;
            font-size: 8pt;
        }
        .po-details div {
            flex: 1;
        }
        
        /* Table Styles */
        .table-wrapper {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            border: 1px solid var(--border-color);
        }
        th, td {
            padding: 4px 3px;
            border: 1px solid var(--border-color);
            text-align: center;
            vertical-align: middle;
        }
        th {
            background: var(--primary);
            color: #fff;
            font-weight: 600;
        }
        
        /* Vertical Branch Headers */
        .vertical-header-container {
            height: 95px;
            width: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .vertical-header {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            font-size: 7pt;
            font-weight: 500;
            color: var(--text-main);
            letter-spacing: 0.2px;
        }
        
        .company-group-th {
            background: #fadbd8;
            color: #78281f;
            font-size: 8pt;
            border-bottom: 2px solid var(--primary);
        }
        .company-total-th {
            background: #f5b7b1;
            color: #641e16;
            font-size: 7.5pt;
        }
        
        .text-left { text-align: left; }
        .item-name { font-weight: 500; }
        .totals-row {
            background: #fadbd8;
            font-weight: bold;
            color: #641e16;
        }
        
        /* Summary Footer Card */
        .summary-footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            background: var(--light-bg);
            padding: 6px 10px;
            border-left: 4px solid var(--primary);
            border: 1px solid var(--border-color);
            border-left-width: 4px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 8pt;
        }
        .summary-footer div {
            margin: 2px 8px;
        }
        .summary-footer strong {
            color: var(--primary);
        }
        
        .footer-text {
            margin-top: 10px;
            border-top: 1.5px solid var(--primary);
            padding-top: 5px;
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            color: #7f8c8d;
        }

        /* Print Settings (Optimized for both Color & B&W Printers) */
        @page {
            size: A4 landscape;
            margin: 5mm;
        }
        @media print {
            body { padding: 0; margin: 0; }
            .no-print { display: none; }
            
            /* High contrast adjustments for Black & White laser printing */
            table, th, td { border-color: #333 !important; }
            th { background: #2c3e50 !important; color: #fff !important; }
            .company-group-th { background: #e2e8f0 !important; color: #000 !important; }
            .company-total-th { background: #cbd5e0 !important; color: #000 !important; }
            .totals-row { background: #cbd5e0 !important; color: #000 !important; }
            .po-details, .summary-footer { background: #f8f9fa !important; border-color: #333 !important; }
            
            th, td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        
        .auto-print-btn {
            text-align: right;
            margin-bottom: 10px;
        }
        .auto-print-btn button {
            padding: 5px 14px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            margin-left: 4px;
            font-size: 8pt;
        }
        .auto-print-btn button:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Action Buttons -->
        <div class="auto-print-btn no-print">
            <button onclick="window.print()">Print / PDF</button>
            <button onclick="window.close()" style="background:#2c3e50;">Close</button>
        </div>

        <!-- Report Header -->
        <div class="report-header">
            <div class="brand">
                <h1>ASB Group Of Companies</h1>
                <small>Purchase Order Allocation Report</small>
            </div>
            <div class="info">
                <strong>PO Number:</strong> <?php echo htmlspecialchars($header['po_number']); ?><br>
                <strong>Supplier:</strong> <?php echo htmlspecialchars($header['supplier_name']); ?><br>
                <strong>Date:</strong> <?php echo htmlspecialchars($header['purchase_date']); ?>
            </div>
        </div>

        <div class="po-details">
            <div><strong>Expected Delivery:</strong> <?php echo htmlspecialchars($header['expected_delivery_date'] ?? 'N/A'); ?></div>
            <div><strong>Order By:</strong> <?php echo htmlspecialchars($header['attention'] ?? '-'); ?></div>
            <div style="text-align:right;"><strong>Remarks:</strong> <?php echo htmlspecialchars($header['remarks'] ?? '-'); ?></div>
        </div>

        <!-- Table Grid -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2" style="width: 30px;">#</th>
                        <th rowspan="2" style="text-align:left; min-width: 160px;">Item Description</th>
                        <th rowspan="2" style="width: 45px;">PO Qty</th>
                        
                        <!-- Company Group Header -->
                        <?php foreach ($companyIds as $cid): ?>
                            <?php $comp = $companyMap[$cid]; ?>
                            <th colspan="<?php echo count($comp['branches']); ?>" class="company-group-th">
                                <?php echo htmlspecialchars($comp['company_name']); ?>
                            </th>
                        <?php endforeach; ?>
                        
                        <!-- Company Totals Header -->
                        <?php foreach ($companyIds as $cid): ?>
                            <th rowspan="2" class="company-total-th">
                                <div class="vertical-header-container" style="height:auto; width:auto;">
                                    <span style="font-size:7pt; font-weight:bold;"><?php echo htmlspecialchars($companyMap[$cid]['company_name']); ?> Total</span>
                                </div>
                            </th>
                        <?php endforeach; ?>
                        
                        <th rowspan="2" style="width: 50px;">Total Alloc</th>
                        <th rowspan="2" style="width: 50px;">Remaining</th>
                    </tr>
                    <tr>
                        <!-- Vertical Branch Sub-headers -->
                        <?php foreach ($companyIds as $cid): ?>
                            <?php $comp = $companyMap[$cid]; ?>
                            <?php foreach ($comp['branches'] as $b): ?>
                                <th style="background: #ffffff; padding: 2px;">
                                    <div class="vertical-header-container">
                                        <span class="vertical-header"><?php echo htmlspecialchars($b['location_name']); ?></span>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_data as $idx => $item): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td class="text-left item-name">
                                <strong><?php echo htmlspecialchars($item['item_code']); ?></strong><br>
                                <small style="color: #7f8c8d;"><?php echo htmlspecialchars($item['item_name']); ?></small>
                            </td>
                            <td><?php echo $item['po_qty']; ?></td>
                            
                            <!-- Branch Allocations -->
                            <?php foreach ($companyIds as $cid): ?>
                                <?php $comp = $companyMap[$cid]; ?>
                                <?php foreach ($comp['branches'] as $b): ?>
                                    <td><?php echo $item['branches'][$b['location_id']] ?? 0; ?></td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            
                            <!-- Company Subtotals -->
                            <?php foreach ($companyIds as $cid): ?>
                                <?php
                                    $total = 0;
                                    $comp = $companyMap[$cid];
                                    foreach ($comp['branches'] as $b) {
                                        $total += $item['branches'][$b['location_id']] ?? 0;
                                    }
                                ?>
                                <td style="background:#fef9e7; font-weight:bold;"><?php echo $total; ?></td>
                            <?php endforeach; ?>
                            
                            <td style="font-weight:bold;"><?php echo $item['total_allocated']; ?></td>
                            <td style="<?php echo $item['remaining'] > 0 ? 'color:#d35400; font-weight:bold;' : 'color:#27ae60; font-weight:bold;'; ?>">
                                <?php echo $item['remaining']; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <!-- Grand Totals Row -->
                    <tr class="totals-row">
                        <td colspan="3" style="text-align:right;">GRAND TOTALS</td>
                        <?php foreach ($companyIds as $cid): ?>
                            <?php $comp = $companyMap[$cid]; ?>
                            <?php foreach ($comp['branches'] as $b): ?>
                                <td><?php echo $branchTotals[$b['location_id']]; ?></td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        
                        <?php foreach ($companyIds as $cid): ?>
                            <td style="background:#f5b7b1;"><?php echo $companyTotals[$cid]; ?></td>
                        <?php endforeach; ?>
                        
                        <td><?php echo $grandTotalAlloc; ?></td>
                        <td><?php echo $grandRemaining; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Summary Footer Card -->
        <div class="summary-footer">
            <?php foreach ($companyIds as $cid): ?>
                <div><strong><?php echo htmlspecialchars($companyMap[$cid]['company_name']); ?>:</strong> <?php echo $companyTotals[$cid]; ?></div>
            <?php endforeach; ?>
            <div><strong>PO Qty:</strong> <?php echo $grandTotalPO; ?></div>
            <div><strong>Allocated:</strong> <?php echo $grandTotalAlloc; ?></div>
            <div><strong>Remaining:</strong> <?php echo $grandRemaining; ?></div>
        </div>

        <div class="footer-text">
            <div>Developed by <strong>Vexel IT by Kavizz</strong></div>
            <div class="right">Generated on <span><?php echo date('Y-m-d H:i'); ?></span></div>
        </div>
    </div>
</body>
</html>