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

$allocData = []; // $allocData[po_item_id][company_id] = quantity
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
$companyNames = array_values($companies);

// Prepare item rows and totals
$total_qty = 0;
$total_cost = 0;
$total_alloc = array_fill_keys($companyIds, 0); // sum per company
$rows = [];

while ($item = $items->fetch_assoc()) {
    $line_total = $item['quantity'] * $item['cost_price'];
    $total_qty += $item['quantity'];
    $total_cost += $line_total;

    // Get allocations for this item (default 0)
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

// Total allocated across all companies
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
        /* ----- RESET & BASE ----- */
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f1f4f9;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #1e293b;
            line-height: 1.5;
        }
        .report-container {
            max-width: 1200px;
            width: 100%;
            background: #ffffff;
            padding: 36px 44px 40px;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08), 0 8px 24px rgba(0,0,0,0.02);
            margin-bottom: 24px;
            border: 1px solid #e9edf4;
            transition: box-shadow 0.2s;
        }
        @media print {
            body { 
                background: #fff; 
                padding: 0; 
                margin: 0;
            }
            .report-container { 
                box-shadow: none; 
                border-radius: 0; 
                padding: 12mm; 
                max-width: 100%; 
                width: 100%;
                border: none; 
            }
            .no-print { display: none !important; }
            @page { 
                size: A4 portrait; 
                margin: 0; 
            }
        }

        /* ----- HEADER ----- */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 4px solid #b91c1c;
            padding-bottom: 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .brand .main {
            font-size: 30px;
            font-weight: 800;
            color: #b91c1c;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }
        .brand .sub {
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .doc-title .type {
            font-size: 24px;
            font-weight: 700;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 1px;
        }
        .doc-title .number {
            display: block;
            font-size: 20px;
            font-weight: 600;
            color: #b91c1c;
            margin-top: 2px;
        }

        /* ----- INFO GRID ----- */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px 30px;
            background: #f8fafc;
            padding: 18px 24px;
            border-radius: 14px;
            margin-bottom: 32px;
            border: 1px solid #e9edf4;
        }
        .info-item .label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
            color: #94a3b8;
            letter-spacing: 0.6px;
        }
        .info-item .value {
            font-size: 17px;
            font-weight: 600;
            color: #0f172a;
            margin-top: 2px;
        }
        .info-item .value.small { font-size: 15px; font-weight: 500; }

        /* ----- TABLE ----- */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 15px;  /* Increased from 13px */
            margin-bottom: 28px;
            table-layout: fixed;
            border-radius: 12px;
            overflow: hidden;  /* for rounded corners on the whole table */
        }
        .items-table thead th {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            color: #ffffff;
            text-transform: uppercase;
            font-size: 12px;   /* increased */
            letter-spacing: 0.8px;
            padding: 14px 10px;
            text-align: center;
            font-weight: 700;
            border: none;
        }
        .items-table thead th:first-child { text-align: left; padding-left: 16px; }
        .items-table thead th:nth-child(2) { text-align: left; }
        .items-table thead th.alloc-header {
            background: linear-gradient(135deg, #7f1d1d 0%, #6b1a1a 100%);
        }
        .items-table tbody td {
            padding: 13px 10px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: middle;
            text-align: center;
            font-size: 14px;   /* increased */
            background-color: #ffffff;
        }
        .items-table tbody td:first-child { 
            text-align: left; 
            font-weight: 600; 
            color: #0f172a; 
            padding-left: 16px; 
        }
        .items-table tbody td:nth-child(2) { 
            text-align: left; 
            font-weight: 500; 
            color: #334155; 
        }
        /* zebra stripes */
        .items-table tbody tr:nth-child(even) td {
            background-color: #fafbfd;
        }
        .items-table tbody tr:hover td {
            background-color: #f1f5f9;
            transition: background 0.15s;
        }

        /* ensure long item names wrap gracefully */
        .item-name-cell {
            word-break: break-word;
            white-space: normal;
            max-width: 220px;
        }

        /* Table footer (totals row) */
        .items-table tfoot tr {
            border-top: 3px solid #b91c1c;
            background: #fef6f5;
            font-weight: 700;
        }
        .items-table tfoot td {
            padding: 16px 10px;
            border-bottom: 2px solid #b91c1c;
            text-align: right;
            font-size: 15px;
            background: #fef6f5;
        }
        .items-table tfoot td:first-child {
            text-align: right;
            font-weight: 700;
            color: #0f172a;
        }
        .items-table tfoot td:last-child {
            font-size: 18px;
            color: #b91c1c;
        }

        /* ----- SUMMARY BOX (in footer) ----- */
        .summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px 28px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            gap: 18px 30px;
            margin: 16px 0 8px 0;
            width: 100%;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
        }
        .summary-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 0 1 auto;
        }
        .summary-item .label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
            color: #94a3b8;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .summary-item .number {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        .summary-item .number.company-color { color: #b91c1c; }
        .summary-item .number.remaining-color { color: #2563eb; }
        .summary-item .number.total-color { color: #0f172a; }

        /* ----- FOOTER SIGNATURES ----- */
        .footer-section {
            margin-top: 32px;
            border-top: 2px solid #e9edf4;
            padding-top: 28px;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 20px;
        }
        .signature-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px 40px;
        }
        .signature-block {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            flex: 1 0 120px;
        }
        .signature-block .label {
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 32px; /* space for signature */
        }
        .signature-line {
            width: 100%;
            max-width: 200px;
            border-bottom: 2px solid #1e293b;
            height: 1px;
            margin-bottom: 4px;
        }

        /* Developer credit */
        .dev-credit {
            font-size: 11px;
            color: #94a3b8;
            text-align: right;
            border-top: 1px solid #e9edf4;
            padding-top: 16px;
            margin-top: 8px;
            width: 100%;
        }
        .dev-credit strong { color: #b91c1c; }

        /* ----- PRINT / CONTROLS ----- */
        .print-btn {
            background: #b91c1c;
            color: #fff;
            border: none;
            padding: 14px 48px;
            font-size: 17px;
            font-weight: 600;
            border-radius: 40px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            box-shadow: 0 6px 16px rgba(185, 28, 28, 0.25);
            margin-bottom: 16px;
            letter-spacing: 0.3px;
        }
        .print-btn:hover { background: #991b1b; transform: translateY(-2px); }
        .print-btn:active { transform: translateY(0); }

        .back-link {
            display: inline-block;
            margin-top: 8px;
            color: #b91c1c;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: border-color 0.2s;
        }
        .back-link:hover { border-bottom-color: #b91c1c; }

        /* ----- RESPONSIVE TWEAKS ----- */
        @media (max-width: 700px) {
            .report-container { padding: 20px 16px; }
            .header { flex-direction: column; align-items: center; text-align: center; }
            .doc-title { text-align: center; }
            .info-grid { grid-template-columns: 1fr; }
            .summary-box { flex-direction: column; align-items: center; gap: 12px; }
            .items-table { font-size: 13px; }
            .items-table thead th { font-size: 10px; padding: 10px 6px; }
            .items-table tbody td { font-size: 12px; padding: 8px 6px; }
            .items-table tfoot td { font-size: 13px; padding: 10px 6px; }
            .signature-row { flex-direction: column; align-items: center; }
            .signature-line { max-width: 140px; }
        }
    </style>
</head>
<body>
<div class="report-container">
    <!-- Header -->
    <div class="header">
        <div class="brand">
            <span class="main">ASB FASHION</span>
            <span class="sub">Purchase Order &amp; Allocation Report</span>
        </div>
        <div class="doc-title">
            <span class="type">Purchase Order</span>
            <span class="number">#<?= htmlspecialchars($po['po_number']); ?></span>
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
            <span class="label">Order By</span>
            <span class="value small"><?= htmlspecialchars($po['attention'] ?? '—'); ?></span>
        </div>
        <?php if (!empty($po['remarks'])): ?>
        <div class="info-item" style="grid-column: 1 / -1;">
            <span class="label">Remarks</span>
            <span class="value small" style="font-weight:400; color:#475569;"><?= nl2br(htmlspecialchars($po['remarks'])); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="text-align:left; width:12%;">Item Code</th>
                <th style="text-align:left; width:22%;">Item Name</th>
                <th style="width:8%;">Colour</th>
                <th style="width:6%;">Size</th>
                <?php foreach ($companies as $cid => $cname): ?>
                <th style="width:8%; text-align:center;" class="alloc-header"><?= htmlspecialchars($cname); ?></th>
                <?php endforeach; ?>
                <th style="width:8%; text-align:right;">PO Qty</th>
                <th style="width:8%; text-align:right;">Unit Cost</th>
                <th style="width:12%; text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $rowData): 
            $item = $rowData['item'];
            $allocs = $rowData['allocations'];
        ?>
            <tr>
                <td><?= htmlspecialchars($item['item_code']); ?></td>
                <td class="item-name-cell" title="<?= htmlspecialchars($item['item_name']); ?>"><?= htmlspecialchars($item['item_name']); ?></td>
                <td><?= htmlspecialchars($item['color_name'] ?? '—'); ?></td>
                <td><?= htmlspecialchars($item['size_name'] ?? '—'); ?></td>
                <?php foreach ($companyIds as $cid): ?>
                <td style="text-align:center; font-weight:500;"><?= number_format($allocs[$cid] ?? 0); ?></td>
                <?php endforeach; ?>
                <td style="text-align:right; font-weight:600;"><?= number_format($item['quantity']); ?></td>
                <td style="text-align:right;"><?= number_format($item['cost_price'], 2); ?></td>
                <td style="text-align:right; font-weight:600;"><?= number_format($item['quantity'] * $item['cost_price'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right; font-weight:700;">TOTALS</td>
                <?php foreach ($companyIds as $cid): ?>
                <td style="text-align:center; font-weight:700; color:#b91c1c;"><?= number_format($total_alloc[$cid]); ?></td>
                <?php endforeach; ?>
                <td style="text-align:right; font-weight:700;"><?= number_format($total_qty); ?></td>
                <td style="text-align:right;"></td>
                <td style="text-align:right; font-weight:700; font-size:18px; color:#b91c1c;">LKR <?= number_format($total_cost, 2); ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Footer Section: Signatures + Summary Box -->
    <div class="footer-section">
        <!-- Signature Row (three blocks) -->
        <div class="signature-row">
            <div class="signature-block">
                <span class="label">Prepared By</span>
                <div class="signature-line"></div>
            </div>
            <div class="signature-block">
                <span class="label">Order Parser</span>
                <div class="signature-line"></div>
            </div>
            <div class="signature-block">
                <span class="label">Supplier Representative</span>
                <div class="signature-line"></div>
            </div>
        </div>

        <!-- Summary Box (added back) -->
        <div class="summary-box">
            <div class="summary-item">
                <span class="label">Total PO Qty</span>
                <span class="number total-color"><?= number_format($total_qty); ?></span>
            </div>
            <?php foreach ($companies as $cid => $cname): ?>
            <div class="summary-item">
                <span class="label"><?= htmlspecialchars($cname); ?></span>
                <span class="number company-color"><?= number_format($total_alloc[$cid]); ?></span>
            </div>
            <?php endforeach; ?>
            <div class="summary-item">
                <span class="label">Total Allocated</span>
                <span class="number company-color"><?= number_format($total_alloc_all); ?></span>
            </div>
            <div class="summary-item">
                <span class="label">Remaining</span>
                <span class="number remaining-color"><?= number_format($total_remaining); ?></span>
            </div>
        </div>

        <div class="dev-credit">
            Designed &amp; Developed by <strong>Vexel IT – Kavizz</strong>
        </div>
    </div>
</div>

<!-- Print Controls -->
<div class="no-print" style="text-align:center;">
    <button class="print-btn" onclick="window.print();">🖨️ Print / Save as PDF</button>
    <br>
    <a href="pos.php" class="back-link">← Back to Purchase Orders</a>
</div>

<script>
    // Auto‑print if requested
    if (window.location.search.includes('auto_print=1')) {
        window.onload = function() { setTimeout(function() { window.print(); }, 500); };
    }
</script>
</body>
</html>