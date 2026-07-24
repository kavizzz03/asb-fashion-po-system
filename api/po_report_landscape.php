<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if (!$po_id) die('Invalid PO ID.');

$conn = getConnection();
$qcConn = getQcConnection();

// Header
$header_sql = "SELECT h.*, s.supplier_name FROM po_header h LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id WHERE h.po_id = ?";
$stmt = $conn->prepare($header_sql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
if (!$po) die('PO not found.');
$stmt->close();

// Items with allocations
$items_sql = "SELECT pi.*, i.item_code, i.item_name, i.system_code,
                     (SELECT SUM(quantity) FROM po_item_allocations WHERE po_item_id = pi.po_item_id AND location_id = 6) AS qty_fashion,
                     (SELECT SUM(quantity) FROM po_item_allocations WHERE po_item_id = pi.po_item_id AND location_id = 7) AS qty_glamour,
                     (SELECT SUM(quantity) FROM po_item_allocations WHERE po_item_id = pi.po_item_id AND location_id = 8) AS qty_gate
              FROM po_items pi
              JOIN items i ON pi.item_id = i.item_id
              WHERE pi.po_id = ?
              ORDER BY pi.po_item_id";
$stmt = $conn->prepare($items_sql);
$stmt->bind_param("i", $po_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PO Report – <?= htmlspecialchars($po['po_number']) ?></title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 9pt; }
        .header { text-align: center; border-bottom: 2px solid #b71c1c; padding-bottom: 8px; margin-bottom: 12px; }
        .header h1 { color: #b71c1c; margin: 0; font-size: 18pt; }
        .header h2 { margin: 2px 0; font-size: 14pt; }
        .po-details { display: flex; flex-wrap: wrap; justify-content: space-between; margin-bottom: 12px; font-size: 8pt; }
        .po-details div { background: #f5f5f5; padding: 4px 10px; border-radius: 3px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
        th { background: #f1f3f5; border: 1px solid #ccc; padding: 4px; text-align: left; }
        td { border: 1px solid #ccc; padding: 3px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td { font-weight: bold; background: #f8f9fa; }
        .footer { margin-top: 15px; text-align: center; font-size: 7pt; color: #888; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>ASB Fashion – Purchase Order</h1>
        <h2><?= htmlspecialchars($po['po_number']) ?></h2>
    </div>

    <div class="po-details">
        <div><strong>Supplier:</strong> <?= htmlspecialchars($po['supplier_name']) ?></div>
        <div><strong>Date:</strong> <?= date('d-m-Y', strtotime($po['purchase_date'])) ?></div>
        <div><strong>Expected Delivery:</strong> <?= $po['expected_delivery_date'] ? date('d-m-Y', strtotime($po['expected_delivery_date'])) : 'N/A' ?></div>
        <div><strong>Status:</strong> <?= $po['status'] ?></div>
        <div><strong>Attention:</strong> <?= htmlspecialchars($po['attention'] ?? '') ?></div>
    </div>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>System Code</th>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th class="text-center">ASB Fashion</th>
                    <th class="text-center">ASB Glamour</th>
                    <th class="text-center">Glamour Gate</th>
                    <th class="text-center">Total</th>
                    <th class="text-right">PO Cost</th>
                    <th class="text-right">PO Sell</th>
                    <th class="text-center">Received</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1; 
                $grand_total = 0;
                $total_fashion = 0; $total_glamour = 0; $total_gate = 0;
                foreach ($items as $item):
                    $qty_fashion = (int)($item['qty_fashion'] ?? 0);
                    $qty_glamour = (int)($item['qty_glamour'] ?? 0);
                    $qty_gate    = (int)($item['qty_gate'] ?? 0);
                    $total_qty   = $qty_fashion + $qty_glamour + $qty_gate;
                    $grand_total += $total_qty;
                    $total_fashion += $qty_fashion;
                    $total_glamour += $qty_glamour;
                    $total_gate += $qty_gate;
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($item['system_code']) ?></td>
                    <td><?= htmlspecialchars($item['item_code']) ?></td>
                    <td><?= htmlspecialchars($item['item_name']) ?></td>
                    <td class="text-center"><?= $qty_fashion ?></td>
                    <td class="text-center"><?= $qty_glamour ?></td>
                    <td class="text-center"><?= $qty_gate ?></td>
                    <td class="text-center"><strong><?= $total_qty ?></strong></td>
                    <td class="text-right"><?= number_format($item['cost_price'], 2) ?></td>
                    <td class="text-right"><?= number_format($item['selling_price'], 2) ?></td>
                    <td class="text-center"><?= $item['received_qty'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" class="text-right"><strong>Totals</strong></td>
                    <td class="text-center"><strong><?= $total_fashion ?></strong></td>
                    <td class="text-center"><strong><?= $total_glamour ?></strong></td>
                    <td class="text-center"><strong><?= $total_gate ?></strong></td>
                    <td class="text-center"><strong><?= $grand_total ?></strong></td>
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if (!empty($po['remarks'])): ?>
        <div style="margin-top:12px; background:#f9f9f9; padding:6px; border-left:3px solid #b71c1c;">
            <strong>Remarks:</strong> <?= nl2br(htmlspecialchars($po['remarks'])) ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        Generated on <?= date('d-m-Y H:i') ?> – ASB Fashion Inventory System
    </div>

    <script>
        window.print();
    </script>
</body>
</html>