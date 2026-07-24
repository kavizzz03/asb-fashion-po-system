<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$conn = getConnection();

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if (!$po_id) {
    die('Invalid PO ID.');
}

// ------------------------------------------------------------
// Fetch PO data (same logic as AJAX endpoint)
// ------------------------------------------------------------
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
        /* Print-optimized styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #1e2a3a;
            background: #fff;
            padding: 10px;
        }
        .container {
            max-width: 100%;
        }
        .report-header {
            display: flex;
            justify-content: space-between;
            border-bottom: 3px solid #c0392b;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .report-header .brand h1 {
            font-size: 18pt;
            color: #c0392b;
            margin: 0;
        }
        .report-header .brand small {
            font-size: 10pt;
            color: #7f8c8d;
        }
        .report-header .info {
            text-align: right;
            font-size: 9pt;
        }
        .report-header .info strong {
            color: #c0392b;
        }
        .po-details {
            display: flex;
            justify-content: space-between;
            background: #fdf2f0;
            padding: 6px 12px;
            border-left: 4px solid #c0392b;
            margin-bottom: 12px;
            font-size: 9pt;
        }
        .po-details div {
            width: 33%;
        }
        .po-details strong {
            color: #c0392b;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            border: 1px solid #ddd;
        }
        th {
            background: #c0392b;
            color: #fff;
            padding: 4px 3px;
            text-align: center;
            font-weight: 600;
            border: 1px solid #ddd;
        }
        td {
            padding: 3px 3px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
        }
        .text-left { text-align: left; }
        .item-name { font-weight: 500; }
        .totals-row {
            background: #fde8e4;
            font-weight: bold;
        }
        .company-total-header {
            background: #d5dbe3;
        }
        .summary-footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 15px;
            background: #fdf2f0;
            padding: 8px 14px;
            border-left: 4px solid #c0392b;
            margin-top: 12px;
            font-size: 9pt;
        }
        .summary-footer strong {
            color: #c0392b;
        }
        .footer-text {
            margin-top: 12px;
            border-top: 2px solid #c0392b;
            padding-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 7pt;
            color: #666;
        }
        .footer-text .right span {
            font-weight: 600;
            color: #c0392b;
        }

        /* Page settings for print */
        @page {
            size: A4 landscape;
            margin: 8mm 10mm;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
            th { background: #c0392b !important; color: #fff !important; }
            .totals-row { background: #fde8e4 !important; }
            .company-total-header { background: #d5dbe3 !important; }
            .po-details { background: #fdf2f0 !important; }
            .summary-footer { background: #fdf2f0 !important; }
            th, td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        /* Auto-print button (visible on screen) */
        .auto-print-btn {
            text-align: center;
            margin: 15px 0;
        }
        .auto-print-btn button {
            padding: 8px 24px;
            background: #c0392b;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
        }
        .auto-print-btn button:hover {
            background: #a93226;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Auto-print button (visible on screen, hidden in print) -->
        <div class="auto-print-btn no-print">
            <button onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
            <button onclick="window.close()">Close</button>
        </div>

        <!-- Report content -->
        <div class="report-header">
            <div class="brand">
                <h1>ASB Group Of Companies</h1>
                <small>Purchase Order Allocation Report</small>
            </div>
            <div class="info">
                <strong>PO:</strong> <?php echo htmlspecialchars($header['po_number']); ?><br>
                <strong>Supplier:</strong> <?php echo htmlspecialchars($header['supplier_name']); ?><br>
                <strong>Date:</strong> <?php echo htmlspecialchars($header['purchase_date']); ?>
            </div>
        </div>

        <div class="po-details">
            <div><strong>Expected Delivery:</strong> <?php echo htmlspecialchars($header['expected_delivery_date'] ?? 'N/A'); ?></div>
            <div><strong>Order By:</strong> <?php echo htmlspecialchars($header['attention'] ?? '-'); ?></div>
            <div style="text-align:right;"><strong>Remarks:</strong> <?php echo htmlspecialchars($header['remarks'] ?? '-'); ?></div>
        </div>

        <!-- Table -->
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th style="text-align:left;">Item</th>
                    <th>PO Qty</th>
                    <?php foreach ($companyIds as $cid): ?>
                        <?php $comp = $companyMap[$cid]; ?>
                        <th colspan="<?php echo count($comp['branches']); ?>" style="background:#e9ecef;"><?php echo htmlspecialchars($comp['company_name']); ?> Branches</th>
                    <?php endforeach; ?>
                    <?php foreach ($companyIds as $cid): ?>
                        <th style="background:#d5dbe3;"><?php echo htmlspecialchars($companyMap[$cid]['company_name']); ?> Total</th>
                    <?php endforeach; ?>
                    <th>Total Alloc</th>
                    <th>Remaining</th>
                </tr>
                <tr>
                    <th></th>
                    <th></th>
                    <th></th>
                    <?php foreach ($companyIds as $cid): ?>
                        <?php $comp = $companyMap[$cid]; ?>
                        <?php foreach ($comp['branches'] as $b): ?>
                            <th style="font-weight:normal; font-size:7pt;"><?php echo htmlspecialchars($b['location_name']); ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php foreach ($companyIds as $cid): ?>
                        <th style="font-weight:normal; font-size:7pt;">Total</th>
                    <?php endforeach; ?>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items_data as $idx => $item): ?>
                    <tr>
                        <td><?php echo $idx+1; ?></td>
                        <td class="text-left item-name"><strong><?php echo htmlspecialchars($item['item_code']); ?></strong><br><small><?php echo htmlspecialchars($item['item_name']); ?></small></td>
                        <td><?php echo $item['po_qty']; ?></td>
                        <?php foreach ($companyIds as $cid): ?>
                            <?php $comp = $companyMap[$cid]; ?>
                            <?php foreach ($comp['branches'] as $b): ?>
                                <td><?php echo $item['branches'][$b['location_id']] ?? 0; ?></td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <?php foreach ($companyIds as $cid): ?>
                            <?php
                                $total = 0;
                                $comp = $companyMap[$cid];
                                foreach ($comp['branches'] as $b) {
                                    $total += $item['branches'][$b['location_id']] ?? 0;
                                }
                            ?>
                            <td style="background:#f5f7fa; font-weight:bold;"><?php echo $total; ?></td>
                        <?php endforeach; ?>
                        <td style="font-weight:bold;"><?php echo $item['total_allocated']; ?></td>
                        <td style="<?php echo $item['remaining'] > 0 ? 'color:#e67e22;' : 'color:green;'; ?>"><?php echo $item['remaining']; ?></td>
                    </tr>
                <?php endforeach; ?>
                <!-- Totals row -->
                <tr class="totals-row">
                    <td colspan="3" style="text-align:right;">TOTALS</td>
                    <?php foreach ($companyIds as $cid): ?>
                        <?php $comp = $companyMap[$cid]; ?>
                        <?php foreach ($comp['branches'] as $b): ?>
                            <td><?php echo $branchTotals[$b['location_id']]; ?></td>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php foreach ($companyIds as $cid): ?>
                        <td style="background:#f5f7fa;"><?php echo $companyTotals[$cid]; ?></td>
                    <?php endforeach; ?>
                    <td><?php echo $grandTotalAlloc; ?></td>
                    <td><?php echo $grandRemaining; ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Summary footer -->
        <div class="summary-footer">
            <?php foreach ($companyIds as $cid): ?>
                <div><strong><?php echo htmlspecialchars($companyMap[$cid]['company_name']); ?> Total:</strong> <?php echo $companyTotals[$cid]; ?></div>
            <?php endforeach; ?>
            <div><strong>Total PO Qty:</strong> <?php echo $grandTotalPO; ?></div>
            <div><strong>Total Allocated:</strong> <?php echo $grandTotalAlloc; ?></div>
            <div><strong>Total Remaining:</strong> <?php echo $grandRemaining; ?></div>
        </div>

        <div class="footer-text">
            <div>Developed by <strong>Vexel IT by Kavizz</strong></div>
            <div class="right">Generated on <span><?php echo date('Y-m-d H:i'); ?></span></div>
        </div>
    </div>

    <!-- Auto-print on load (uncomment if you want the print dialog to appear automatically) -->
    <!-- <script>
        setTimeout(function() { window.print(); }, 500);
    </script> -->
</body>
</html>