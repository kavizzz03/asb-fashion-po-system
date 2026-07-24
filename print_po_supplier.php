<?php
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$conn = getConnection();

$po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : (isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0);
if (!$po_id) die('No Purchase Order specified.');

// Fetch PO header with supplier
$stmt = $conn->prepare("
    SELECT ph.*, s.supplier_name 
    FROM po_header ph 
    JOIN suppliers s ON ph.supplier_id = s.supplier_id 
    WHERE ph.po_id = ?
");
$stmt->bind_param("i", $po_id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
if (!$po) die('PO not found.');

// Fetch items with colour and size
$stmt_items = $conn->prepare("
    SELECT 
        pi.*,
        i.item_code,
        i.item_name,
        i.cost_price,
        col.color_name,
        s.size_name
    FROM po_items pi 
    JOIN items i ON pi.item_id = i.item_id
    LEFT JOIN colors col ON i.color_id = col.color_id
    LEFT JOIN sizes s ON i.size_id = s.size_id
    WHERE pi.po_id = ?
    ORDER BY pi.po_item_id
");
$stmt_items->bind_param("i", $po_id);
$stmt_items->execute();
$items = $stmt_items->get_result();

$total_qty = 0;
$total_cost = 0;
$rows = [];
while ($row = $items->fetch_assoc()) {
    $line_total = $row['quantity'] * $row['cost_price'];
    $total_qty += $row['quantity'];
    $total_cost += $line_total;
    $rows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier PO – <?= htmlspecialchars($po['po_number']); ?></title>
    <style>
        /* Reset & Page */
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', 'Roboto', system-ui, -apple-system, sans-serif;
            background: #f2f4f8;
            padding:20px;
            display:flex;
            flex-direction:column;
            align-items:center;
        }
        .report-container {
            max-width: 210mm;
            width:100%;
            background:#ffffff;
            padding:24px 30px 30px;
            box-shadow:0 10px 40px rgba(0,0,0,0.04);
            border-radius:8px;
            margin-bottom:20px;
            border:1px solid #e9ecf2;
        }
        @media print {
            body { background:#fff; padding:0; }
            .report-container { box-shadow:none; border-radius:0; padding:20px 25px 30px; max-width:100%; border:none; }
            .no-print { display:none !important; }
            @page { size:A4 portrait; margin:1.8cm 1.5cm; }
        }

        /* Header */
        .header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            border-bottom:3px solid #b71c1c;
            padding-bottom:12px;
            margin-bottom:24px;
        }
        .brand {
            font-size:26px;
            font-weight:800;
            color:#b71c1c;
            letter-spacing:0.3px;
        }
        .brand small {
            display:block;
            font-size:13px;
            font-weight:300;
            color:#6b7280;
            letter-spacing:0.8px;
        }
        .doc-title {
            text-align:right;
            font-size:20px;
            font-weight:700;
            text-transform:uppercase;
            color:#1f2937;
            border-left:3px solid #b71c1c;
            padding-left:16px;
        }
        .doc-title span {
            display:block;
            font-size:14px;
            font-weight:400;
            color:#6b7280;
            text-transform:none;
            margin-top:2px;
        }

        /* Info Grid */
        .info-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:12px 30px;
            background:#f9fafc;
            padding:16px 20px;
            border-radius:6px;
            margin-bottom:24px;
            border:1px solid #e5e9f0;
        }
        .info-item .label {
            font-size:10px;
            text-transform:uppercase;
            font-weight:700;
            color:#8b95a9;
            letter-spacing:0.4px;
        }
        .info-item .value {
            font-size:15px;
            font-weight:600;
            color:#1e293b;
            margin-top:2px;
        }
        .info-item .value.small { font-size:14px; font-weight:500; }

        /* Table */
        .items-table {
            width:100%;
            border-collapse:collapse;
            margin-bottom:18px;
            font-size:12px;
        }
        .items-table thead th {
            background:#b71c1c;
            color:#ffffff;
            text-transform:uppercase;
            font-size:10px;
            letter-spacing:0.6px;
            padding:10px 8px;
            text-align:center;
            font-weight:600;
            border:1px solid #b71c1c;
        }
        .items-table thead th:first-child { text-align:left; padding-left:12px; }
        .items-table thead th:nth-child(2) { text-align:left; }
        .items-table tbody td {
            padding:8px 8px;
            border-bottom:1px solid #eef2f7;
            vertical-align:middle;
            text-align:center;
            font-size:11.5px;
        }
        .items-table tbody td:first-child { text-align:left; font-weight:600; color:#1e293b; padding-left:12px; }
        .items-table tbody td:nth-child(2) { text-align:left; font-weight:500; color:#334155; }
        .items-table tbody td:last-child,
        .items-table tbody td:nth-last-child(2) { text-align:right; }
        .items-table tbody tr:nth-child(even) { background:#fafbfd; }
        .items-table tfoot tr {
            border-top:2px solid #b71c1c;
            background:#f9f0ee;
            font-weight:700;
        }
        .items-table tfoot td {
            padding:12px 8px;
            border-bottom:2px solid #b71c1c;
        }
        .items-table tfoot td:last-child {
            font-size:17px;
            color:#b71c1c;
        }

        /* Footer & Signature */
        .footer-section {
            margin-top:32px;
            border-top:2px solid #e9edf4;
            padding-top:24px;
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            flex-wrap:wrap;
            gap:20px;
        }
        .signature-block .label {
            font-size:10px;
            font-weight:600;
            color:#6b7280;
            text-transform:uppercase;
            letter-spacing:0.4px;
        }
        .signature-line {
            display:flex;
            align-items:center;
            gap:12px;
            margin-top:4px;
        }
        .signature-line .line {
            width:160px;
            border-bottom:1.5px solid #1e293b;
            height:1px;
        }
        .signature-line .name {
            font-weight:500;
            font-size:13px;
            color:#1e293b;
            white-space:nowrap;
        }
        .dev-credit {
            font-size:10px;
            color:#9ca3af;
            text-align:right;
            border-top:1px solid #e9edf4;
            padding-top:12px;
            margin-top:12px;
            width:100%;
        }
        .dev-credit strong { color:#b71c1c; }

        .print-btn {
            background:#b71c1c;
            color:#fff;
            border:none;
            padding:10px 34px;
            font-size:15px;
            border-radius:6px;
            cursor:pointer;
            font-weight:600;
            transition:background 0.2s;
            margin-bottom:14px;
            box-shadow:0 2px 4px rgba(183,28,28,0.2);
        }
        .print-btn:hover { background:#8e1515; }

        @media (max-width:600px) {
            .info-grid { grid-template-columns:1fr; }
            .header { flex-direction:column; text-align:center; }
            .doc-title { border-left:none; border-top:2px solid #b71c1c; padding-top:10px; margin-top:10px; text-align:center; }
            .footer-section { flex-direction:column; align-items:stretch; }
            .signature-line .line { width:100%; }
            .items-table { font-size:10px; }
            .items-table thead th { font-size:8px; padding:6px 4px; }
            .items-table tbody td { font-size:9px; padding:5px 4px; }
        }
    </style>
</head>
<body>
<div class="report-container">
    <!-- Header -->
    <div class="header">
        <div class="brand">
            ASB FASHION
            <small>Purchase Order</small>
        </div>
        <div class="doc-title">
            PURCHASE ORDER
            <span><?= htmlspecialchars($po['po_number']); ?></span>
        </div>
    </div>

    <!-- PO Information -->
    <div class="info-grid">
        <div class="info-item">
            <span class="label">Supplier</span>
            <span class="value"><?= htmlspecialchars($po['supplier_name']); ?></span>
        </div>
        <div class="info-item">
            <span class="label">Order Date</span>
            <span class="value"><?= date('d M Y', strtotime($po['purchase_date'])); ?></span>
        </div>
        <div class="info-item">
            <span class="label">Expected Delivery</span>
            <span class="value"><?= $po['expected_delivery_date'] ? date('d M Y', strtotime($po['expected_delivery_date'])) : '—'; ?></span>
        </div>
        <div class="info-item">
            <span class="label">Ordered By (Attention)</span>
            <span class="value small"><?= htmlspecialchars($po['attention'] ?? '—'); ?></span>
        </div>
        <?php if (!empty($po['remarks'])): ?>
        <div class="info-item" style="grid-column:span 2;">
            <span class="label">Remarks</span>
            <span class="value small" style="font-weight:400; color:#475569;"><?= nl2br(htmlspecialchars($po['remarks'])); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="text-align:left; width:18%;">Item Code</th>
                <th style="text-align:left; width:32%;">Item Name</th>
                <th style="width:12%;">Colour</th>
                <th style="width:10%;">Size</th>
                <th style="width:8%; text-align:right;">Qty</th>
                <th style="width:10%; text-align:right;">Unit Cost</th>
                <th style="width:12%; text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['item_code']); ?></td>
                <td><?= htmlspecialchars($item['item_name']); ?></td>
                <td><?= htmlspecialchars($item['color_name'] ?? '—'); ?></td>
                <td><?= htmlspecialchars($item['size_name'] ?? '—'); ?></td>
                <td style="text-align:right;"><?= number_format($item['quantity']); ?></td>
                <td style="text-align:right;"><?= number_format($item['cost_price'], 2); ?></td>
                <td style="text-align:right;"><?= number_format($item['quantity'] * $item['cost_price'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right; font-weight:700;">TOTALS</td>
                <td style="text-align:right; font-weight:700;"><?= number_format($total_qty); ?></td>
                <td style="text-align:right;"></td>
                <td style="text-align:right; font-weight:700; font-size:17px; color:#b71c1c;">LKR <?= number_format($total_cost, 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Footer Signatures & Credit -->
    <div class="footer-section">
        <div class="signature-block">
            <span class="label"></span>
            <div class="signature-line">
                <span class="line"></span>
                <span class="name">Person Confirm</span>
            </div>
        </div>
        <div class="signature-block">
            <span class="label"></span>
            <div class="signature-line">
                <span class="line"></span>
                <span class="name">Supplier Representative</span>
            </div>
        </div>
    </div>
    <div class="dev-credit">
        Designed &amp; Developed by <strong>Vexel IT – Kavizz</strong>
    </div>
</div>

<!-- Print Controls -->
<div class="no-print" style="text-align:center;">
    <button class="print-btn" onclick="window.print();">🖨️ Print / Save as PDF</button>
    <br>
    <a href="pos.php" style="color:#b71c1c; text-decoration:none; font-weight:600;">← Back to Purchase Orders</a>
</div>

<script>
    // Auto‑print if requested
    if (window.location.search.includes('auto_print=1')) {
        window.onload = function() { setTimeout(function() { window.print(); }, 500); };
    }
</script>
</body>
</html>