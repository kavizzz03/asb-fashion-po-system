<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';

$conn = getConnection();

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

// 1. Fetch Master PO Structural Header Details
$po_query = "SELECT ph.*, s.supplier_name 
             FROM po_header ph
             LEFT JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
             WHERE ph.po_id = ?";

$stmt = $conn->prepare($po_query);
if (!$stmt) {
    die("Database Error [PO Header Matrix Fetch Failed]: " . $conn->error);
}
$stmt->bind_param("i", $po_id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$po) {
    die("Error: Purchase Order record key (#" . htmlspecialchars($po_id) . ") not found in central ledger registry.");
}

// 2. Fetch Comprehensive Line Item Breakdown with Real-Time Cumulative Ledger Balances
$item_query = "SELECT pi.*, it.item_name, it.item_code 
               FROM po_items pi
               INNER JOIN items it ON pi.item_id = it.item_id
               WHERE pi.po_id = ?
               ORDER BY it.item_code ASC";

$stmt = $conn->prepare($item_query);
if (!$stmt) {
    die("Database Error [PO Line Items Join Failed]: " . $conn->error);
}
$stmt->bind_param("i", $po_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3. Fetch All Goods Received Notes (GRN Batches) & Driver Logs tied to this PO
$grn_query = "SELECT gh.grn_id, gh.grn_number, gh.received_date, gh.delivered_by, gh.vehicle_no, gh.total_box_count,
                     (SELECT SUM(gi.qty_received) FROM grn_items gi WHERE gi.grn_id = gh.grn_id) as batch_sum
              FROM grn_header gh
              WHERE gh.po_id = ?
              ORDER BY gh.received_date DESC";

$stmt = $conn->prepare($grn_query);
if (!$stmt) {
    die("Database Error [Historical Logistics Pull Failed]: " . $conn->error);
}
$stmt->bind_param("i", $po_id);
$stmt->execute();
$shipments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Compute Aggregate Global Matrix Summaries
$grand_ordered_qty = 0;
$grand_received_qty = 0;
$driver_registry = [];

foreach ($items as $item) {
    $grand_ordered_qty += $item['quantity'];
    $grand_received_qty += $item['received_qty'];
}
$grand_remaining_bal = $grand_ordered_qty - $grand_received_qty;
if ($grand_remaining_bal < 0) $grand_remaining_bal = 0;

foreach ($shipments as $ship) {
    if (!empty($ship['delivered_by'])) {
        $driver_registry[] = htmlspecialchars($ship['delivered_by']) . " (" . htmlspecialchars($ship['vehicle_no'] ?? 'N/A') . ")";
    }
}
$all_drivers = !empty($driver_registry) ? implode(', ', array_unique($driver_registry)) : 'No Logistics Cargo Logs Tracked';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASB Fashion | PO Audit Statement - <?= htmlspecialchars($po['po_number']); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #222; margin: 10px 15px; font-size: 11px; background-color: #fff; line-height: 1.3; }
        
        /* Preview Banner Styling */
        .preview-banner { background: #fff5f5; padding: 10px 20px; border: 1px solid #ffccd2; border-radius: 6px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .preview-banner strong { color: #b71c1c; }
        .btn-print { padding: 6px 14px; background: #d32f2f; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; transition: 0.2s; }
        .btn-print:hover { background: #b71c1c; }
        
        /* Branding Header */
        .print-header { border-bottom: 2px solid #b71c1c; padding-bottom: 6px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; }
        .brand-title { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px; color: #b71c1c; text-transform: uppercase; }
        .brand-subtitle { margin: 2px 0 0 0; font-size: 11px; color: #555; font-weight: 500; }
        
        /* Layout Structure */
        .report-title { text-align: center; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 15px; background: #b71c1c; color: #fff; padding: 5px; border-radius: 3px; letter-spacing: 0.5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        .info-block table { width: 100%; border-collapse: collapse; }
        .info-block td { padding: 3px 0; vertical-align: top; font-size: 11px; }
        .info-block td.label { font-weight: 600; width: 140px; color: #555; }
        
        /* Subtitles & Headings */
        .section-heading { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #b71c1c; margin: 15px 0 6px 0; border-left: 3px solid #b71c1c; padding-left: 6px; letter-spacing: 0.3px; }

        /* Compact Data Layout Configurations */
        table.items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.items-table th, table.items-table td { border: 1px solid #dcdcdc; padding: 5px 6px; text-align: left; }
        table.items-table th { background-color: #f5f5f5; font-weight: 600; color: #333; text-transform: uppercase; font-size: 10px; letter-spacing: 0.3px; border-bottom: 2px solid #b71c1c; }
        
        /* Summary Grid Highlights */
        .summary-panel-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .summary-card { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; padding: 8px; text-align: center; }
        .summary-card.accent { background: #fff9f9; border-color: #ffccd2; }
        .summary-card .value { font-size: 15px; font-weight: bold; margin-top: 2px; color: #111; }
        .summary-card.accent .value { color: #b71c1c; }
        .summary-card .label { font-size: 9px; text-transform: uppercase; color: #555; font-weight: 600; letter-spacing: 0.3px; }

        /* Signature Tracks */
        .signature-section { margin-top: 35px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; text-align: center; page-break-inside: avoid; }
        .sig-box { border-top: 1px dashed #b71c1c; padding-top: 6px; font-size: 11px; color: #333; }
        .signature-gap { height: 45px; }
        
        .asb-footer { text-align: center; margin-top: 35px; padding: 10px; color: #777; border-top: 1px solid #eee; font-size: 10px; page-break-inside: avoid; }
        .asb-footer strong { color: #b71c1c; }

        @media print {
            .no-print { display: none; }
            body { margin: 5mm 8mm; background-color: #fff; font-size: 10px; }
            .print-header { border-bottom: 2px solid #b71c1c; }
            .report-title { background: #b71c1c !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.items-table th { background-color: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .summary-card { background: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .summary-card.accent { background: #fff9f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.items-table th, table.items-table td { padding: 4px 5px !important; }
            .signature-gap { height: 40px; }
        }
    </style>
</head>
<body>

    <!-- Print Control Ribbon -->
    <div class="no-print preview-banner">
        <div>
            💡 <strong>ASB Fashion Audit Engine:</strong> Compiling Master Lifecycle Summary for Order Reference <strong><?= htmlspecialchars($po['po_number']); ?></strong>.
        </div>
        <div>
            <button onclick="window.print();" class="btn-print">🖨️ Print Audit Statement</button>
        </div>
    </div>

    <!-- Corporate Branding Block -->
    <div class="print-header">
        <div>
            <h1 class="brand-title">ASB Fashion</h1>
            <p class="brand-subtitle">Head Office Supply Chain Complex • Global Inventory Lifecycle Audit</p>
        </div>
        <div style="text-align: right; color: #666; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
            Full Lifecycle Report
        </div>
    </div>

    <!-- Title Sub-Header -->
    <div class="report-title">
        Purchase Order Progress Verification &amp; Procurement Audit Statement
    </div>

    <!-- Dynamic Context Grid Metadata -->
    <div class="info-grid">
        <div class="info-block">
            <table>
                <tr><td class="label">Purchase Order Number:</td><td><strong style="color:#b71c1c; font-size:12px;"><?= htmlspecialchars($po['po_number']); ?></strong></td></tr>
                <tr><td class="label">Supplier Identity Name:</td><td style="font-weight:600; color:#222;"><?= htmlspecialchars($po['supplier_name'] ?? 'Not Linked'); ?></td></tr>
                <tr><td class="label">PO Base Order Date:</td><td><?= date('Y-m-d', strtotime($po['purchase_date'])); ?></td></tr>
                <tr><td class="label">Active Ledger Status:</td><td><span style="text-transform: uppercase; font-weight: bold; color: #b71c1c;"><?= htmlspecialchars($po['status']); ?></span></td></tr>
            </table>
        </div>
        <div class="info-block">
            <table>
                <tr><td class="label">Generation Time:</td><td><?= date('Y-m-d H:i:s'); ?></td></tr>
                <tr><td class="label">All Time Drivers Logged:</td><td style="font-weight:600; font-style:italic; color:#333; max-width:250px;"><?= $all_drivers; ?></td></tr>
                <tr><td class="label">Total GRN Batches Tied:</td><td><strong><?= count($shipments); ?> Inbound Cargo Run(s)</strong></td></tr>
            </table>
        </div>
    </div>

    <!-- SECTION 1: COMPREHENSIVE BALANCES TRACKER -->
    <div class="section-heading">1. Procurement Material Breakdown &amp; Balance Verification Ledger</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px; text-align: center;">#</th>
                <th>Item Specification Code &amp; Matrix Description</th>
                <th style="width: 120px; text-align: right;">Total Target Ordered</th>
                <th style="width: 120px; text-align: right; background-color: #fff1f1;">Accumulated Received</th>
                <th style="width: 120px; text-align: right;">Current Remaining Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1;
            foreach ($items as $item): 
                $item_bal = $item['quantity'] - $item['received_qty'];
                if ($item_bal < 0) $item_bal = 0;
            ?>
                <tr>
                    <td style="text-align: center; color: #666;"><?= $i++; ?></td>
                    <td><strong style="color: #b71c1c;">[<?= htmlspecialchars($item['item_code'] ?? 'N/A'); ?>]</strong> <?= htmlspecialchars($item['item_name']); ?></td>
                    <td style="text-align: right; color: #444;"><?= number_format($item['quantity']); ?></td>
                    <td style="text-align: right; font-weight: bold; color:#b71c1c; background-color: #fbfbfb;"><?= number_format($item['received_qty']); ?></td>
                    <td style="text-align: right; font-weight: bold; color: <?= $item_bal > 0 ? '#d32f2f' : '#2e7d32'; ?>;">
                        <?= $item_bal == 0 ? '✓ Order Fulfilled' : number_format($item_bal); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- SECTION 2: TRACKING INBOUND LOGISTICS BATCH RUNS -->
    <div class="section-heading">2. Connected Goods Received Notes (GRN Batches) Cargo History</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px; text-align: center;">#</th>
                <th style="width: 130px;">GRN Voucher Number</th>
                <th style="width: 120px;">Processing Date</th>
                <th>Driver Name / Logistics Personnel (All Time)</th>
                <th style="width: 110px;">Vehicle Reg #</th>
                <th style="width: 100px; text-align: right;">Box Count</th>
                <th style="width: 110px; text-align: right;">Batch Volume</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shipments)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #888; font-style: italic; padding: 10px;">No inbound stock allocations processing matches found for this order.</td>
                </tr>
            <?php else: 
                $s_idx = 1;
                foreach ($shipments as $ship): ?>
                    <tr>
                        <td style="text-align: center; color: #666;"><?= $s_idx++; ?></td>
                        <td><strong style="color: #b71c1c;"><?= htmlspecialchars($ship['grn_number']); ?></strong></td>
                        <td><?= date('Y-m-d H:i', strtotime($ship['received_date'])); ?></td>
                        <td><strong style="color: #333;"><?= htmlspecialchars($ship['delivered_by'] ?? 'N/A'); ?></strong></td>
                        <td><?= htmlspecialchars($ship['vehicle_no'] ?? 'N/A'); ?></td>
                        <td style="text-align: right;"><?= number_format($ship['total_box_count'] ?? 0); ?> Ctn</td>
                        <td style="text-align: right; font-weight: bold; color: #b71c1c;"><?= number_format($ship['batch_sum'] ?? 0); ?> items</td>
                    </tr>
            <?php endforeach; 
            endif; ?>
        </tbody>
    </table>

    <!-- SECTION 3: LIFECYCLE SUMMARY COUNTERS -->
    <div class="section-heading">3. Total Cumulative Procurement Summary Counter Matrix</div>
    <div class="summary-panel-grid">
        <div class="summary-card">
            <div class="label">Total Volume Ordered</div>
            <div class="value"><?= number_format($grand_ordered_qty); ?></div>
        </div>
        <div class="summary-card accent">
            <div class="label">Total Inbound Runs</div>
            <div class="value"><?= count($shipments); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Accumulated Volume Received</div>
            <div class="value" style="color: #2e7d32;"><?= number_format($grand_received_qty); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Outstanding Purchase Balance</div>
            <div class="value" style="color: <?= $grand_remaining_bal > 0 ? '#d32f2f' : '#2e7d32'; ?>;">
                <?= number_format($grand_remaining_bal); ?>
            </div>
        </div>
    </div>

    <!-- Accountability Validation Footer Base Lines -->
    <div class="signature-section">
        <div>
            <div class="signature-gap"></div>
            <div class="sig-box">
                <strong>Warehouse Auditor Sign</strong><br>
                <span style="font-size: 10px; margin-top:2px; display:inline-block; color:#666;">Data Entry / Verified Staff</span>
            </div>
        </div>
        <div>
            <div class="signature-gap"></div>
            <div class="sig-box">
                <strong>Operations Manager Validation</strong><br>
                <span style="font-size: 10px; margin-top:2px; display:inline-block; color:#666;">Inventory Logistics Executive</span>
            </div>
        </div>
        <div>
            <div class="signature-gap"></div>
            <div class="sig-box">
                <strong>Procurement Approver</strong><br>
                <span style="font-size: 10px; margin-top:2px; display:inline-block; color:#666;">Supply Chain Management</span>
            </div>
        </div>
    </div>

    <!-- Technical Attribution Footer -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:9px; color:#aaa; margin-top:2px; display:inline-block;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>

</body>
</html>