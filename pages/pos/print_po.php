<?php
// print_po.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['data'])) {
    $data = json_decode($_POST['data'], true);
    if (!$data) die('Invalid data');
    // Fetch supplier name from supplier_id
    $supplierId = $data['supplierId'];
    $conn = getConnection();
    $sup = $conn->query("SELECT supplier_name FROM suppliers WHERE supplier_id = $supplierId")->fetch_assoc();
    $supplierName = $sup['supplier_name'] ?? 'N/A';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Purchase Order <?php echo $data['poNumber']; ?></title>
        <style>
            @page { size: A4; margin: 20mm; }
            body { font-family: Arial, sans-serif; font-size: 12px; color: #333; }
            .header { text-align:center; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:20px; }
            .header h1 { margin:0; font-size:24px; }
            .po-info { display:flex; justify-content:space-between; margin-bottom:20px; }
            .po-info .left, .po-info .right { width:48%; }
            .po-info table { width:100%; border-collapse:collapse; }
            .po-info td { padding:4px 0; }
            table.items { width:100%; border-collapse:collapse; margin-top:20px; }
            table.items th { background:#f2f2f2; text-align:left; padding:8px; border:1px solid #ddd; }
            table.items td { padding:8px; border:1px solid #ddd; }
            .summary { margin-top:20px; text-align:right; }
            .footer { margin-top:40px; border-top:1px solid #ddd; padding-top:10px; text-align:center; font-size:10px; color:#777; }
            .print-btn { display:block; margin:20px auto; padding:10px 20px; font-size:16px; }
            @media print { .no-print { display:none; } }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>PURCHASE ORDER</h1>
            <p><strong>PO No:</strong> <?php echo htmlspecialchars($data['poNumber']); ?></p>
        </div>
        <div class="po-info">
            <div class="left">
                <table>
                    <tr><td><strong>Supplier:</strong></td><td><?php echo htmlspecialchars($supplierName); ?></td></tr>
                    <tr><td><strong>Purchase Date:</strong></td><td><?php echo $data['purchaseDate']; ?></td></tr>
                </table>
            </div>
            <div class="right">
                <table>
                    <tr><td><strong>Expected Delivery:</strong></td><td><?php echo $data['expectedDate'] ?: 'N/A'; ?></td></tr>
                    <tr><td><strong>Attention:</strong></td><td><?php echo htmlspecialchars($data['attention'] ?: '-'); ?></td></tr>
                </table>
            </div>
        </div>
        <?php if ($data['remarks']): ?>
            <p><strong>Remarks:</strong> <?php echo nl2br(htmlspecialchars($data['remarks'])); ?></p>
        <?php endif; ?>
        <table class="items">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Name</th>
                    <th>Cost (LKR)</th>
                    <th>Sell (LKR)</th>
                    <th>ASb Fashion</th>
                    <th>ASb Glamour</th>
                    <th>Glamour Gate</th>
                    <th>Total Qty</th>
                    <th>Total Cost</th>
                    <th>Total Sell</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totQty = $totCost = $totSell = 0;
                $counter = 1;
                foreach ($data['items'] as $item):
                    $qty = array_sum($item['allocs']);
                    $cost = (float)$item['cost'];
                    $sell = (float)$item['sell'];
                    $lineCost = $qty * $cost;
                    $lineSell = $qty * $sell;
                    $totQty += $qty; $totCost += $lineCost; $totSell += $lineSell;
                    $allocs = $item['allocs'];
                ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><?php echo htmlspecialchars($item['itemName']); ?></td>
                    <td><?php echo number_format($cost, 2); ?></td>
                    <td><?php echo number_format($sell, 2); ?></td>
                    <td><?php echo $allocs['ASb Fashion'] ?? 0; ?></td>
                    <td><?php echo $allocs['ASb Glamour'] ?? 0; ?></td>
                    <td><?php echo $allocs['Glamour Gate'] ?? 0; ?></td>
                    <td><?php echo $qty; ?></td>
                    <td><?php echo number_format($lineCost, 2); ?></td>
                    <td><?php echo number_format($lineSell, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:bold; background:#f9f9f9;">
                    <td colspan="7" style="text-align:right;">TOTALS</td>
                    <td><?php echo $totQty; ?></td>
                    <td><?php echo number_format($totCost, 2); ?></td>
                    <td><?php echo number_format($totSell, 2); ?></td>
                </tr>
            </tfoot>
        </table>
        <div class="summary">
            <p><strong>Total Quantity:</strong> <?php echo $totQty; ?></p>
            <p><strong>Total Cost Value:</strong> LKR <?php echo number_format($totCost, 2); ?></p>
            <p><strong>Total Sell Value:</strong> LKR <?php echo number_format($totSell, 2); ?></p>
        </div>
        <div class="footer">
            Generated on <?php echo date('Y-m-d H:i'); ?> – This is a system‑generated PO.
        </div>
        <div class="no-print" style="text-align:center; margin-top:20px;">
            <button onclick="window.print()" class="print-btn">🖨️ Print / PDF</button>
            <button onclick="window.close()" class="print-btn" style="background:#ccc;">Close</button>
        </div>
        <script>
            // Auto‑print on load? Not forced, user can click.
        </script>
    </body>
    </html>
    <?php
} else {
    echo 'Invalid request';
}