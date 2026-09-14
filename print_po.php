<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['po_id'])) {
        $poId = (int)$_POST['po_id'];
        $po = getPOById($poId);
        if (!$po) die('PO not found');

        $conn = getConnection();

        // 1. Fetch companies
        $companyQuery = "SELECT company_id, company_name FROM companies ORDER BY company_name";
        $companies = $conn->query($companyQuery)->fetch_all(MYSQLI_ASSOC);

        // 2. For each company, get location IDs
        $companyLocations = [];
        foreach ($companies as $c) {
            $locSql = "SELECT location_id FROM store_locations WHERE company_id = ?";
            $stmt = $conn->prepare($locSql);
            $stmt->bind_param("i", $c['company_id']);
            $stmt->execute();
            $locIds = $stmt->get_result()->fetch_all(MYSQLI_NUM);
            $companyLocations[$c['company_id']] = array_column($locIds, 0);
        }

        // 3. Fetch allocations aggregated per company
        $allocStmt = $conn->prepare("
            SELECT pia.po_item_id, sl.company_id, SUM(pia.quantity) AS total_qty
            FROM po_item_allocations pia
            JOIN store_locations sl ON pia.location_id = sl.location_id
            WHERE pia.po_item_id IN (SELECT po_item_id FROM po_items WHERE po_id = ?)
            GROUP BY pia.po_item_id, sl.company_id
        ");
        $allocStmt->bind_param("i", $poId);
        $allocStmt->execute();
        $allocRes = $allocStmt->get_result();
        $allocationMap = []; // po_item_id => [company_id => qty]
        while ($row = $allocRes->fetch_assoc()) {
            $poItemId = $row['po_item_id'];
            $companyId = $row['company_id'];
            if (!isset($allocationMap[$poItemId])) $allocationMap[$poItemId] = [];
            $allocationMap[$poItemId][$companyId] = (int)$row['total_qty'];
        }

        // 4. Build items array with attributes, including company-wise allocations
        $items = [];
        foreach ($po['items'] as $item) {
            // Get attributes + item_code
            $attrs = $conn->query("
                SELECT i.item_code, d.department_name, sd.sub_department_name, c.category_name, col.color_name, s.size_name
                FROM items i
                LEFT JOIN departments d ON i.department_id = d.department_id
                LEFT JOIN sub_departments sd ON i.sub_department_id = sd.sub_department_id
                LEFT JOIN categories c ON i.category_id = c.category_id
                LEFT JOIN colors col ON i.color_id = col.color_id
                LEFT JOIN sizes s ON i.size_id = s.size_id
                WHERE i.item_id = {$item['item_id']}
            ")->fetch_assoc();

            $alloc = $allocationMap[$item['po_item_id']] ?? [];
            $itemData = [
                'itemName' => $item['item_name'],
                'itemCode' => $attrs['item_code'] ?? '',
                'cost' => $item['cost_price'],
                'sell' => $item['selling_price'],
                'qty' => $item['quantity'],
                'dept' => $attrs['department_name'] ?? '',
                'sub' => $attrs['sub_department_name'] ?? '',
                'cat' => $attrs['category_name'] ?? '',
                'color' => $attrs['color_name'] ?? '',
                'size' => $attrs['size_name'] ?? '',
                'companyAllocs' => $alloc
            ];
            $items[] = $itemData;
        }

        // 5. Check if any allocations exist at all
        $hasAllocations = false;
        foreach ($allocationMap as $alloc) {
            if (array_sum($alloc) > 0) { $hasAllocations = true; break; }
        }

        $data = [
            'poNumber' => $po['po_number'],
            'supplierName' => $po['supplier_name'],
            'purchaseDate' => $po['purchase_date'],
            'expectedDate' => $po['expected_delivery_date'],
            'attention' => $po['attention'],
            'remarks' => $po['remarks'],
            'items' => $items,
            'companies' => $companies,
            'hasAllocations' => $hasAllocations
        ];

    } elseif (isset($_POST['data'])) {
        // For direct JSON input (if needed)
        $data = json_decode($_POST['data'], true);
        if (!$data) die('Invalid data');
        if (!isset($data['companies'])) {
            // fallback
        }
    } else {
        die('Invalid request');
    }
} else {
    die('Invalid method');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASB Group – PO <?php echo $data['poNumber']; ?></title>
    <style>
        /* Use nearly full A4 sheet – minimal margins */
        @page {
            size: A4 landscape;
            margin: 5mm 6mm; /* reduced from 12mm */
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 11px;   /* increased base */
            line-height: 1.4;
            color: #1e2a3a;
            background: #fff;
        }
        .container {
            width: 100%;
            padding: 0;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #c0392b;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .brand .logo-placeholder {
            width: 40px;
            height: 40px;
            background: #c0392b;
            color: #fff;
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
        }
        .brand h1 {
            font-size: 20px;
            color: #c0392b;
            letter-spacing: 1px;
            margin: 0;
        }
        .brand .sub {
            font-size: 12px;
            color: #7f8c8d;
            font-weight: 300;
            letter-spacing: 2px;
        }
        .po-number {
            text-align: right;
        }
        .po-number .label {
            font-size: 9px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .po-number .value {
            font-size: 20px;
            font-weight: 700;
            color: #c0392b;
            letter-spacing: 1px;
        }

        /* PO Info */
        .po-info {
            display: flex;
            justify-content: space-between;
            background: #fdf2f0;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 10px;
            border-left: 4px solid #c0392b;
            font-size: 11px;
        }
        .po-info div {
            width: 45%;
        }
        .po-info strong {
            color: #c0392b;
        }
        .remarks {
            background: #fef8f7;
            padding: 6px 12px;
            border-left: 4px solid #c0392b;
            margin-bottom: 10px;
            font-size: 10px;
        }
        .remarks strong {
            color: #c0392b;
        }

        .no-allocations {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 10px 14px;
            border-radius: 6px;
            margin: 10px 0;
            font-weight: 600;
            color: #856404;
            text-align: center;
            font-size: 12px;
        }

        /* Table – full width, no extra space */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 6px;
        }
        th {
            background: #c0392b;
            color: #fff;
            padding: 5px 4px;
            text-align: center;
            font-weight: 600;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        td {
            padding: 4px 4px;
            border-bottom: 1px solid #e5e5e5;
            text-align: center;
            vertical-align: middle;
        }
        tr:nth-child(even) td {
            background: #fdf7f6;
        }
        tr:hover td {
            background: #fce9e6;
        }
        .text-right {
            text-align: right;
        }
        .text-left {
            text-align: left;
        }
        .item-name {
            font-weight: 500;
            text-align: left;
        }

        /* === PRICE CELLS – larger, bold, pill-style badge === */
        .price-cell {
            font-size: 13px !important;      /* larger than rest */
            font-weight: 700 !important;
            color: #1a5276 !important;
            background: #eaf2f8 !important;   /* soft blue */
            border-radius: 20px !important;   /* pill shape */
            padding: 2px 10px !important;
            display: inline-block;
            min-width: 60px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        /* Override even row background for price cells */
        tr:nth-child(even) td .price-cell {
            background: #d4e6f1 !important;
        }
        tr:hover td .price-cell {
            background: #aed6f1 !important;
        }

        /* Totals row – keep distinct */
        .totals-row td {
            background: #fde8e4 !important;
            font-weight: 700;
            border-top: 2px solid #c0392b;
            border-bottom: 2px solid #c0392b;
            padding: 6px 4px;
            color: #a93226;
        }
        .totals-row .label {
            text-align: right;
            padding-right: 10px;
        }

        /* Summary */
        .summary {
            margin-top: 12px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 16px;
            font-size: 12px;
            background: #fdf2f0;
            padding: 8px 14px;
            border-radius: 6px;
            border-left: 4px solid #c0392b;
        }
        .summary-item {
            text-align: right;
        }
        .summary-item strong {
            color: #c0392b;
        }

        .footer {
            margin-top: 15px;
            border-top: 2px solid #c0392b;
            padding-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 8px;
            color: #5a6a7b;
            align-items: center;
        }
        .footer .left {
            font-weight: 300;
        }
        .footer .right {
            font-weight: 300;
        }
        .footer .right span {
            font-weight: 600;
            color: #c0392b;
        }

        .no-print {
            text-align: center;
            margin: 20px 0;
        }
        .no-print button {
            padding: 8px 24px;
            margin: 0 8px;
            border: none;
            border-radius: 4px;
            background: #c0392b;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .no-print button:hover {
            background: #a93226;
        }
        @media print {
            .no-print { display: none; }
            .po-info { background: #fdf2f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr:nth-child(even) td { background: #fdf7f6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr:nth-child(even) td .price-cell { background: #d4e6f1 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .totals-row td { background: #fde8e4 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .summary { background: #fdf2f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            th { background: #c0392b !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-allocations { background: #fff3cd !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="header">
            <div class="brand">
                <div class="logo-placeholder">ASB</div>
                <div>
                    <h1>ASB Group Of Companies</h1>
                    <div class="sub">PURCHASE ORDER</div>
                </div>
            </div>
            <div class="po-number">
                <div class="label">PO Number</div>
                <div class="value"><?php echo $data['poNumber']; ?></div>
            </div>
        </div>

        <!-- PO INFO -->
        <div class="po-info">
            <div>
                <strong>Supplier:</strong> <?php echo htmlspecialchars($data['supplierName']); ?><br>
                <strong>Date:</strong> <?php echo $data['purchaseDate']; ?>
            </div>
            <div style="text-align:right;">
                <strong>Expected Delivery:</strong> <?php echo $data['expectedDate'] ?? 'N/A'; ?><br>
                <strong>Attention:</strong> <?php echo htmlspecialchars($data['attention'] ?? '-'); ?>
            </div>
        </div>

        <!-- REMARKS -->
        <?php if (!empty($data['remarks'])): ?>
            <div class="remarks">
                <strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($data['remarks'])); ?>
            </div>
        <?php endif; ?>

        <!-- ALLOCATION STATUS -->
        <?php if (!$data['hasAllocations']): ?>
            <div class="no-allocations">
                ⚠️ No allocations have been made for this PO yet. All company quantities are zero.
            </div>
        <?php endif; ?>

        <!-- ITEMS TABLE -->
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Code</th>
                    <th style="text-align:left; min-width:100px;">Item</th>
                    <th>Dept</th>
                    <th>Sub</th>
                    <th>Category</th>
                    <th>Color</th>
                    <th>Size</th>
                    <?php foreach ($data['companies'] as $company): ?>
                        <th><?php echo htmlspecialchars($company['company_name']); ?></th>
                    <?php endforeach; ?>
                    <th>Total Qty</th>
                    <th>Cost (LKR)</th>
                    <th>Sell (LKR)</th>
                    <th>Total Cost</th>
                    <th>Total Sell</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalQty = $totalCost = $totalSell = 0;
                $companyTotals = array_fill_keys(array_column($data['companies'], 'company_id'), 0);
                $i = 1;
                foreach ($data['items'] as $item):
                    $qty = $item['qty'] ?? 0;
                    $cost = (float)($item['cost'] ?? 0);
                    $sell = (float)($item['sell'] ?? 0);
                    $lineCost = $qty * $cost;
                    $lineSell = $qty * $sell;
                    $totalQty += $qty;
                    $totalCost += $lineCost;
                    $totalSell += $lineSell;

                    // Company-wise quantities for this item
                    $itemCompanyQty = [];
                    foreach ($data['companies'] as $company) {
                        $cid = $company['company_id'];
                        $q = $item['companyAllocs'][$cid] ?? 0;
                        $itemCompanyQty[$cid] = $q;
                        $companyTotals[$cid] += $q;
                    }
                ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo htmlspecialchars($item['itemCode']); ?></td>
                    <td class="item-name"><?php echo htmlspecialchars($item['itemName']); ?></td>
                    <td><?php echo htmlspecialchars($item['dept']); ?></td>
                    <td><?php echo htmlspecialchars($item['sub']); ?></td>
                    <td><?php echo htmlspecialchars($item['cat']); ?></td>
                    <td><?php echo htmlspecialchars($item['color']); ?></td>
                    <td><?php echo htmlspecialchars($item['size']); ?></td>
                    <?php foreach ($data['companies'] as $company): ?>
                        <td class="text-right"><?php echo $itemCompanyQty[$company['company_id']]; ?></td>
                    <?php endforeach; ?>
                    <td class="text-right"><strong><?php echo $qty; ?></strong></td>
                    <td class="text-right"><span class="price-cell"><?php echo number_format($cost, 2); ?></span></td>
                    <td class="text-right"><span class="price-cell"><?php echo number_format($sell, 2); ?></span></td>
                    <td class="text-right"><span class="price-cell"><?php echo number_format($lineCost, 2); ?></span></td>
                    <td class="text-right"><span class="price-cell"><?php echo number_format($lineSell, 2); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <!-- TOTALS ROW -->
                <tr class="totals-row">
                    <td colspan="8" class="label">TOTALS</td>
                    <?php foreach ($data['companies'] as $company): ?>
                        <td class="text-right"><?php echo $companyTotals[$company['company_id']]; ?></td>
                    <?php endforeach; ?>
                    <td class="text-right"><?php echo $totalQty; ?></td>
                    <td></td>
                    <td></td>
                    <td class="text-right"><?php echo number_format($totalCost, 2); ?></td>
                    <td class="text-right"><?php echo number_format($totalSell, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- SUMMARY -->
        <div class="summary">
            <?php foreach ($data['companies'] as $company): ?>
                <div class="summary-item">
                    <strong><?php echo htmlspecialchars($company['company_name']); ?>:</strong>
                    <?php echo $companyTotals[$company['company_id']]; ?>
                </div>
            <?php endforeach; ?>
            <div class="summary-item">
                <strong>Total Qty:</strong> <?php echo $totalQty; ?>
            </div>
            <div class="summary-item">
                <strong>Total Cost:</strong> LKR <?php echo number_format($totalCost, 2); ?>
            </div>
            <div class="summary-item">
                <strong>Total Sell:</strong> LKR <?php echo number_format($totalSell, 2); ?>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <div class="left">Developed by <strong>Vexel It By Kavizz</strong></div>
            <div class="right">Generated on <span><?php echo date('Y-m-d H:i'); ?></span></div>
        </div>

        <!-- PRINT BUTTONS -->
        <div class="no-print">
            <button onclick="window.print()">🖨️ Print / PDF</button>
            <button onclick="window.close()">✖ Close</button>
        </div>
    </div>
</body>
</html>