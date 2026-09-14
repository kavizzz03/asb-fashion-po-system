<?php
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Sri Lanka / Colombo local time for printed document timestamps
date_default_timezone_set('Asia/Colombo');
$printed_at = date('d M Y, h:i A');

$conn = getConnection();

$po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : (isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0);
if (!$po_id) die('No Purchase Order specified.');

// Fetch PO header from the current PO database.
// Supplier data is read ONLY from return_qc.suppliers.
$stmt = $conn->prepare("
    SELECT 
        ph.*,
        s.supplier_name
    FROM po_header ph
    LEFT JOIN return_qc.suppliers s
        ON ph.supplier_id = s.supplier_id
    WHERE ph.po_id = ?
    LIMIT 1
");

if (!$stmt) {
    die('PO query prepare failed: ' . $conn->error);
}

$stmt->bind_param("i", $po_id);

if (!$stmt->execute()) {
    die('PO query execute failed: ' . $stmt->error);
}

$po = $stmt->get_result()->fetch_assoc();

if (!$po) {
    die('PO not found. PO ID: ' . $po_id);
}

// Fetch items with colour, size, and cost
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

// ---- Fetch allocations per item per company ----
$allocStmt = $conn->prepare("
    SELECT 
        pi.po_item_id,
        c.company_id,
        c.company_name,
        SUM(a.quantity) AS alloc_qty
    FROM po_item_allocations a
    JOIN po_items pi ON a.po_item_id = pi.po_item_id
    JOIN store_locations l ON a.location_id = l.location_id
    JOIN companies c ON l.company_id = c.company_id
    WHERE pi.po_id = ?
    GROUP BY pi.po_item_id, c.company_id
");
$allocStmt->bind_param("i", $po_id);
$allocStmt->execute();
$allocResult = $allocStmt->get_result();

$allocData = []; 
while ($row = $allocResult->fetch_assoc()) {
    $allocData[$row['po_item_id']][$row['company_id']] = (int)$row['alloc_qty'];
}

// ---- Define companies in the desired order ----
$companies = [
    3 => 'Glamour Gate',
    2 => 'ASB Glamour',
    1 => 'ASB Fashion'
];
$companyIds = array_keys($companies);

// Prepare item rows and totals
$total_qty = 0;
$total_cost = 0;
$total_alloc = array_fill_keys($companyIds, 0);
$rows = [];

while ($item = $items->fetch_assoc()) {
    $line_total = $item['quantity'] * $item['cost_price'];
    $total_qty += $item['quantity'];
    $total_cost += $line_total;

    $itemAllocs = [];
    foreach ($companyIds as $cid) {
        $qty = isset($allocData[$item['po_item_id']][$cid]) ? (int)$allocData[$item['po_item_id']][$cid] : 0;
        $itemAllocs[$cid] = $qty;
        $total_alloc[$cid] += $qty;
    }

    $rows[] = [
        'item' => $item,
        'allocations' => $itemAllocs
    ];
}

$total_alloc_all = array_sum($total_alloc);
$total_remaining = $total_qty - $total_alloc_all;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier PO – <?= htmlspecialchars($po['po_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            padding: 20px 10px;
            display: flex;
            flex-direction: column;
            align-items: center;
            line-height: 1.4;
        }
        .report-container {
            max-width: 1200px;
            width: 100%;
            background: #ffffff;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }

        /* Print Override: Zero Margins & Narrow Layout */
        @media print {
            @page { 
                size: A4 portrait; 
                margin: 0; /* Zero margin for printed sheet */
            }
            body { 
                background: #fff; 
                padding: 5mm; /* Minimal bleed-safe buffer */
                color: #000;
            }
            .report-container { 
                box-shadow: none; 
                border-radius: 0; 
                padding: 0; 
                max-width: 100%; 
                border: none; 
            }
            .no-print, .grand-total-bar { 
                display: none !important; /* Hide total section on print */
            }
        }

        /* Header Styles */
        .header {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            overflow: hidden;
        }
        .header::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 6px;
            background: #dc2626;
        }
        .brand { padding-left: 10px; }
        .brand .eyebrow {
            font-size: 9px;
            font-weight: 800;
            color: #dc2626;
            text-transform: uppercase;
            letter-spacing: 1.6px;
            margin-bottom: 3px;
        }
        .brand .main {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.5px;
        }
        .brand .sub {
            font-size: 11px;
            font-weight: 500;
            color: #64748b;
            margin-top: 2px;
        }
        .doc-title {
            text-align: right;
            padding-left: 20px;
        }
        .doc-title .status {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .7px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .doc-title .type {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 1px;
        }
        .doc-title .number {
            display: block;
            font-size: 18px;
            font-weight: 700;
            color: #dc2626;
            margin-top: 2px;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            background: #f8fafc;
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #f1f5f9;
        }
        .info-item .label {
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.5px;
        }
        .info-item .value {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin-top: 2px;
        }

        /* Item Table: Font size reduced to 9px */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px; /* Reduced font size to 9px */
            text-align: left;
        }
        .items-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 8px 6px;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }
        .items-table th.col-num, 
        .items-table th.col-code, 
        .items-table th.col-name {
            text-align: left;
        }
        .items-table th.alloc-header {
            background: #e2e8f0;
            color: #334155;
        }
        .items-table td {
            padding: 6px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            text-align: center;
        }
        .items-table td.col-num {
            font-weight: 700;
            color: #64748b;
            width: 30px;
            text-align: left;
        }
        .items-table td.col-code {
            font-weight: 600;
            color: #0f172a;
            text-align: left;
        }
        .items-table td.col-name {
            text-align: left;
        }
        .items-table tbody tr:last-child td {
            border-bottom: 1px solid #e2e8f0;
        }
        .items-table tbody tr:hover td {
            background-color: #f8fafc;
        }

        /* Screen-Only Summary / Grand Total Bar */
        .grand-total-bar {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .grand-total-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            min-width: 90px;
        }
        .grand-total-col.align-left { align-items: flex-start; }
        .grand-total-col.align-right { align-items: flex-end; }
        .grand-total-col .gt-label {
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .grand-total-col .gt-value {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }
        .grand-total-col .gt-value.highlight {
            color: #dc2626;
            font-size: 15px;
        }

        /* Footer Section & Signatures */
        .footer-section {
            margin-top: 30px;
            border-top: 1px solid #e2e8f0;
            padding-top: 24px;
        }
        .approval-title {
            text-align: center;
            margin-bottom: 18px;
        }
        .approval-title .small {
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
        }
        .approval-title .big {
            font-size: 15px;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 800;
            margin-top: 3px;
        }
        .signature-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
        }
        .signature-block {
            min-height: 110px;
            padding: 14px 16px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }
        .signature-block .role {
            font-size: 9px;
            font-weight: 800;
            color: #dc2626;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 34px;
        }
        .signature-line {
            width: 100%;
            border-bottom: 1px solid #94a3b8;
            margin-bottom: 5px;
        }
        .signature-block .caption {
            font-size: 8px;
            color: #64748b;
        }

        /* Printed Document Timestamp */
        .print-meta {
            margin-top: 14px;
            text-align: right;
            font-size: 8px;
            color: #64748b;
            letter-spacing: 0.2px;
        }

        /* Developer Credit */
        .dev-credit {
            font-size: 10px;
            color: #94a3b8;
            text-align: center;
            margin-top: 24px;
        }
        .dev-credit strong { color: #dc2626; }

        /* Print Controls */
        .print-btn {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 10px 28px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s;
            box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.2);
            margin-bottom: 10px;
        }
        .print-btn:hover { background: #b91c1c; }
        .back-link {
            display: inline-block;
            color: #64748b;
            font-weight: 500;
            font-size: 13px;
            text-decoration: none;
            transition: color 0.2s;
        }
        .back-link:hover { color: #0f172a; }
    </style>
</head>
<body>

<div class="report-container">
    <!-- Header -->
    <div class="header">
        <div class="brand">
            <div class="eyebrow">Official Purchasing Document</div>
            <div class="main">ASB FASHION</div>
            <div class="sub">Purchase Order &amp; Allocation Report</div>
        </div>
        <div class="doc-title">
            <span class="status">Purchase Order</span>
            <span class="number">#<?= htmlspecialchars($po['po_number']); ?></span>
        </div>
    </div>

    <!-- PO Information Grid -->
    <div class="info-grid">
        <div class="info-item">
            <div class="label">Supplier</div>
            <div class="value"><?= htmlspecialchars($po['supplier_name'] ?? 'Supplier not found'); ?></div>
        </div>
        <div class="info-item">
            <div class="label">Order Date</div>
            <div class="value"><?= date('d M Y', strtotime($po['purchase_date'])); ?></div>
        </div>
        <div class="info-item">
            <div class="label">Expected Delivery</div>
            <div class="value"><?= $po['expected_delivery_date'] ? date('d M Y', strtotime($po['expected_delivery_date'])) : '—'; ?></div>
        </div>
        <div class="info-item">
            <div class="label">Order By</div>
            <div class="value"><?= htmlspecialchars($po['attention'] ?? '—'); ?></div>
        </div>
        <div class="info-item">
            <div class="label">Printed Date &amp; Time</div>
            <div class="value"><?= htmlspecialchars($printed_at); ?> <span style="font-size:10px; color:#64748b; font-weight:500;">(Colombo)</span></div>
        </div>
        <?php if (!empty($po['remarks'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
            <div class="label">Remarks</div>
            <div class="value" style="font-weight: 400; color: #475569;"><?= nl2br(htmlspecialchars($po['remarks'])); ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items Table Container (Font size 9px, with Row Numbers 1, 2, 3...) -->
    <div class="table-wrapper">
        <table class="items-table">
            <thead>
                <tr>
                    <th class="col-num">#</th>
                    <th class="col-code">Item Code</th>
                    <th class="col-name">Item Name</th>
                    <th>Colour</th>
                    <th>Size</th>
                    <?php foreach ($companies as $cid => $cname): ?>
                    <th class="alloc-header"><?= htmlspecialchars($cname); ?></th>
                    <?php endforeach; ?>
                    <th style="text-align: right;">PO Qty</th>
                    <th style="text-align: right;">Unit Cost</th>
                    <th style="text-align: right;">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            $item_index = 1;
            foreach ($rows as $rowData): 
                $item = $rowData['item'];
                $allocs = $rowData['allocations'];
            ?>
                <tr>
                    <td class="col-num"><?= $item_index++; ?></td>
                    <td class="col-code"><?= htmlspecialchars($item['item_code']); ?></td>
                    <td class="col-name"><?= htmlspecialchars($item['item_name']); ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($item['color_name'] ?? '—'); ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($item['size_name'] ?? '—'); ?></td>
                    <?php foreach ($companyIds as $cid): ?>
                    <td style="text-align: center;"><?= number_format($allocs[$cid] ?? 0); ?></td>
                    <?php endforeach; ?>
                    <td style="text-align: right; font-weight: 600;"><?= number_format($item['quantity']); ?></td>
                    <td style="text-align: right;"><?= number_format($item['cost_price'], 2); ?></td>
                    <td style="text-align: right; font-weight: 600;"><?= number_format($item['quantity'] * $item['cost_price'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Summary / Grand Total Bar (Visible on screen, hidden on printed sheet) -->
    <div class="grand-total-bar">
        <div class="grand-total-col align-left">
            <span class="gt-label">Total Line Items</span>
            <span class="gt-value"><?= count($rows); ?> Items</span>
        </div>
        <?php foreach ($companies as $cid => $cname): ?>
        <div class="grand-total-col">
            <span class="gt-label"><?= htmlspecialchars($cname); ?></span>
            <span class="gt-value highlight"><?= number_format($total_alloc[$cid]); ?></span>
        </div>
        <?php endforeach; ?>
        <div class="grand-total-col">
            <span class="gt-label">Total PO Qty</span>
            <span class="gt-value"><?= number_format($total_qty); ?></span>
        </div>
        <div class="grand-total-col align-right">
            <span class="gt-label">Grand Total Cost</span>
            <span class="gt-value highlight">LKR <?= number_format($total_cost, 2); ?></span>
        </div>
    </div>

    <!-- Footer Section with Approval Signatures -->
    <div class="footer-section">
        <div class="approval-title">
            <div class="small">Document Authorization</div>
            <div class="big">Approved &amp; Acknowledged By</div>
        </div>
        <div class="signature-row">
            <div class="signature-block">
                <span class="role">Prepared By</span>
                <div class="signature-line"></div>
                <span class="caption">Name / Signature / Date</span>
            </div>
            <div class="signature-block">
                <span class="role">Approved Person</span>
                <div class="signature-line"></div>
                <span class="caption">Authorized Signature / Date</span>
            </div>
            <div class="signature-block">
                <span class="role">Supplier Representative</span>
                <div class="signature-line"></div>
                <span class="caption">Name / Signature / Date</span>
            </div>
        </div>

        <div class="print-meta">
            Document printed: <?= htmlspecialchars($printed_at); ?> (Sri Lanka / Colombo Time)
        </div>

        <div class="dev-credit">
            Designed &amp; Developed by <strong>Vexel IT – Kavizz</strong>
        </div>
    </div>
</div>

<!-- Print & Navigation Controls -->
<div class="no-print" style="text-align: center; margin-bottom: 30px;">
    <button class="print-btn" onclick="window.print();">🖨️ Print / Save as PDF</button>
    <br>
    <a href="pos.php" class="back-link">&larr; Back to Purchase Orders</a>
</div>

<script>
    if (window.location.search.includes('auto_print=1')) {
        window.onload = function() { setTimeout(function() { window.print(); }, 500); };
    }
</script>
</body>
</html>