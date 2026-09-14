<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';

$conn = getConnection();

$grn_id = isset($_GET['grn_id']) ? (int)$_GET['grn_id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'supplier'; // 'supplier' or 'internal'

// Fetch the GRN Header along with Master PO Details, Cross-Database Supplier Identity, and tracking properties
$query = "SELECT gh.*, ph.po_number, ph.purchase_date, s.supplier_name 
          FROM grn_header gh
          INNER JOIN po_header ph ON gh.po_id = ph.po_id
          LEFT JOIN return_qc.suppliers s ON ph.supplier_id = s.supplier_id
          WHERE gh.grn_id = ?";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Database Error [GRN Header Fetch Failed]: " . $conn->error);
}
$stmt->bind_param("i", $grn_id);
$stmt->execute();
$grn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$grn) {
    die("Error: Goods Received Note record not found.");
}

// Fetch the items brought in during this specific GRN shipment batch
$item_query = "SELECT gi.qty_received AS batch_received_qty, it.item_name, it.item_code, pi.quantity as original_po_qty, pi.received_qty as running_total_received
               FROM grn_items gi
               INNER JOIN po_items pi ON gi.po_item_id = pi.po_item_id
               INNER JOIN items it ON pi.item_id = it.item_id
               WHERE gi.grn_id = ?";

$stmt = $conn->prepare($item_query);
if (!$stmt) {
    die("<div style='font-family:sans-serif; padding:15px; border:1px solid #cc0000; background:#fff5f5; border-radius:6px;'>
            <strong style='color:#cc0000; font-size:14px;'>Database Schema Error [GRN Items Join Failed]</strong><br><br>
            <strong>Error Message:</strong> " . $conn->error . "
         </div>");
}
$stmt->bind_param("i", $grn_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Compute aggregate summaries for the entire PO context
$grand_po_qty = 0;
$grand_received_till_now = 0;
$grand_batch_received = 0;

foreach ($items as $item) {
    $grand_po_qty += $item['original_po_qty'];
    $grand_received_till_now += $item['running_total_received'];
    $grand_batch_received += $item['batch_received_qty'];
}
$grand_remaining_bal = $grand_po_qty - $grand_received_till_now;
if ($grand_remaining_bal < 0) $grand_remaining_bal = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASB Fashion | GRN Report - <?= htmlspecialchars($grn['grn_number']); ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #222; margin: 10px 15px; font-size: 11px; background-color: #fff; line-height: 1.3; }
        
        /* Preview Banner Styling */
        .preview-banner { background: #fff5f5; padding: 10px 20px; border: 1px solid #ffccd2; border-radius: 6px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
        .preview-banner strong { color: #b71c1c; }
        .btn-print { padding: 6px 14px; background: #d32f2f; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 12px; transition: 0.2s; }
        .btn-print:hover { background: #b71c1c; }
        
        /* Branding & Header Elements */
        .print-header { border-bottom: 2px solid #b71c1c; padding-bottom: 6px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-end; }
        .brand-title { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: 0.5px; color: #b71c1c; text-transform: uppercase; }
        .brand-subtitle { margin: 2px 0 0 0; font-size: 11px; color: #555; font-weight: 500; }
        
        /* Layout Configurations */
        .report-title { text-align: center; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 15px; background: #b71c1c; color: #fff; padding: 5px; border-radius: 3px; letter-spacing: 0.5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        .info-block table { width: 100%; border-collapse: collapse; }
        .info-block td { padding: 3px 0; vertical-align: top; font-size: 11px; }
        .info-block td.label { font-weight: 600; width: 130px; color: #555; }
        
        /* Section Dividers */
        .section-heading { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #b71c1c; margin: 15px 0 6px 0; border-left: 3px solid #b71c1c; padding-left: 6px; letter-spacing: 0.3px; }

        /* Data Table Matrices configured for ultimate single-page high-density scaling */
        table.items-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        table.items-table th, table.items-table td { border: 1px solid #dcdcdc; padding: 5px 6px; text-align: left; }
        table.items-table th { background-color: #f5f5f5; font-weight: 600; color: #333; text-transform: uppercase; font-size: 10px; letter-spacing: 0.3px; border-bottom: 2px solid #b71c1c; }
        
        /* Summary Highlight Panel Layout */
        .summary-panel-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .summary-card { background: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; padding: 8px; text-align: center; }
        .summary-card.accent { background: #fff9f9; border-color: #ffccd2; }
        .summary-card .value { font-size: 15px; font-weight: bold; margin-top: 2px; color: #111; }
        .summary-card.accent .value { color: #b71c1c; }
        .summary-card .label { font-size: 9px; text-transform: uppercase; color: #555; font-weight: 600; letter-spacing: 0.3px; }

        /* Cleaned Signature Blocks - Removed Seals, Kept Pure Corporate Base Lines */
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

    <!-- Print Operational Ribbon -->
    <div class="no-print preview-banner">
        <div>
            💡 <strong>ASB Fashion Print Engine:</strong> Active Document Context handles the <strong><?= $type === 'supplier' ? 'Supplier Copy (With Balances)' : 'Gate / Internal Copy (Received Breakdown Only)'; ?></strong>.
        </div>
        <div>
            <button onclick="window.print();" class="btn-print">🖨️ Execute Print / Save PDF</button>
        </div>
    </div>

    <!-- Master Corporate Branding Header -->
    <div class="print-header">
        <div>
            <h1 class="brand-title">ASB Fashion</h1>
            <p class="brand-subtitle">Head Office Warehouse Complex • Quality &amp; Inventory Logistics Control</p>
        </div>
        <div style="text-align: right; color: #666; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
            System Registry Record
        </div>
    </div>

    <!-- Dynamic Title Flag Area -->
    <div class="report-title">
        Goods Received Note &amp; Complete Procurement Audit Statement
    </div>

    <!-- Metadata Grid Context -->
    <div class="info-grid">
        <div class="info-block">
            <table>
                <tr><td class="label">GRN Voucher Number:</td><td><strong style="color:#b71c1c; font-size:12px;"><?= htmlspecialchars($grn['grn_number']); ?></strong></td></tr>
                <tr><td class="label">Associated PO Ref:</td><td style="font-weight:600; color:#222;"><?= htmlspecialchars($grn['po_number']); ?></td></tr>
                <tr><td class="label">Supplier Identity:</td><td><?= htmlspecialchars($grn['supplier_name'] ?? 'Not Specified'); ?></td></tr>
                <tr><td class="label">Delivery Note / Invoice:</td><td style="font-weight:600;"><?= htmlspecialchars($grn['delivery_note_no']); ?></td></tr>
                <tr><td class="label">Total Carton Box Count:</td><td><strong><?= htmlspecialchars($grn['total_box_count'] ?? '0'); ?> Boxes</strong></td></tr>
            </table>
        </div>
        <div class="info-block">
            <table>
                <tr><td class="label">Transaction Date:</td><td><?= date('Y-m-d H:i', strtotime($grn['received_date'] ?? date('Y-m-d H:i:s'))); ?></td></tr>
                <tr><td class="label">Vehicle Registration #:</td><td><strong style="color:#222;"><?= htmlspecialchars($grn['vehicle_no'] ?? 'N/A'); ?></strong></td></tr>
                <tr><td class="label">Goods Delivered By:</td><td><strong><?= htmlspecialchars($grn['delivered_by'] ?? 'N/A'); ?></strong></td></tr>
                <tr><td class="label">Receiving Memo Remarks:</td><td style="font-style: italic; color:#555;"><?= htmlspecialchars($grn['remarks'] ? $grn['remarks'] : 'N/A'); ?></td></tr>
            </table>
        </div>
    </div>

    <!-- REPORT TYPE 1: BATCH ITEM ARRIVAL BREAKDOWN -->
    <div class="section-heading">1. One-by-One Item Arrival Breakdown (Current Batch)</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px; text-align: center;">#</th>
                <th>Item Specifications (Code &amp; Description)</th>
                <th style="width: 100px; text-align: right;">PO Target Qty</th>
                <th style="width: 110px; text-align: right; background-color: #fff1f1;">Received This Batch</th>
                <th style="width: 110px; text-align: right;">Total Recd (Till Now)</th>
                <th style="width: 110px; text-align: right;">Remaining Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $i = 1;
            foreach ($items as $item): 
                $remaining_bal = $item['original_po_qty'] - $item['running_total_received'];
                if ($remaining_bal < 0) $remaining_bal = 0;
            ?>
                <tr>
                    <td style="text-align: center; color: #666;"><?= $i++; ?></td>
                    <td><strong style="color: #b71c1c;">[<?= htmlspecialchars($item['item_code'] ?? 'N/A'); ?>]</strong> <?= htmlspecialchars($item['item_name']); ?></td>
                    <td style="text-align: right; color: #444;"><?= number_format($item['original_po_qty']); ?></td>
                    <td style="text-align: right; font-weight: bold; color:#b71c1c; background-color: #fbfbfb;"><?= number_format($item['batch_received_qty']); ?></td>
                    <td style="text-align: right; color: #222; font-weight: 500;"><?= number_format($item['running_total_received']); ?></td>
                    <td style="text-align: right; font-weight: bold; color: <?= $remaining_bal > 0 ? '#d32f2f' : '#2e7d32'; ?>;">
                        <?= $remaining_bal == 0 ? '✓ Completed' : number_format($remaining_bal); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- REPORT TYPE 2: TOTAL CUMULATIVE PROCUREMENT SUMMARY -->
    <div class="section-heading">2. Full Purchase Order Fulfillment Summary</div>
    <div class="summary-panel-grid">
        <div class="summary-card">
            <div class="label">Total PO Ordered Qty</div>
            <div class="value"><?= number_format($grand_po_qty); ?></div>
        </div>
        <div class="summary-card accent">
            <div class="label">Received This Shipment</div>
            <div class="value"><?= number_format($grand_batch_received); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Accumulated Received</div>
            <div class="value" style="color: #2e7d32;"><?= number_format($grand_received_till_now); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Overall Remaining PO Balance</div>
            <div class="value" style="color: <?= $grand_remaining_bal > 0 ? '#d32f2f' : '#2e7d32'; ?>;">
                <?= number_format($grand_remaining_bal); ?>
            </div>
        </div>
    </div>

    <!-- Accountability Validation Footers (Pure Signature Tracks) -->
    <div class="signature-section">
        <div>
            <div class="signature-gap"></div>
            <div class="sig-box">
                <strong>Delivery Driver Signature</strong><br>
                <span style="font-size: 10px; margin-top:2px; display:inline-block; color:#666;">Name: <?= htmlspecialchars($grn['delivered_by'] ?? '______________________'); ?></span>
            </div>
        </div>
        <div>
            <div class="signature-gap"></div>
            <div class="sig-box">
                <strong>Goods Received Person</strong><br>
                <span style="font-size: 10px; margin-top:2px; display:inline-block; color:#666;">Storekeeper / Warehouse Executive</span>
            </div>
        </div>
        <div>
            <div class="signature-gap"></div>
            <div class="sig-box">
                <strong>Authorized Operations Signatory</strong><br>
                <span style="font-size: 10px; margin-top:2px; display:inline-block; color:#666;">Management / Checked By</span>
            </div>
        </div>
    </div>

    <!-- Structural Technical Attribution Footer -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:9px; color:#aaa; margin-top:2px; display:inline-block;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>

</body>
</html>