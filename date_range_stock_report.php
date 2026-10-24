<?php
/**
 * ASB Fashion - Date Range Stock & GRN Audit Ledger Report
 * Ultra-Professional A4 Landscape Layout with PO Total vs Received Analysis
 * Designed & Developed by Vexel IT by Kavizz
 */

if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

startSession();
requireLogin();

date_default_timezone_set('Asia/Colombo');
$server_printed_time = date('d/m/Y h:i:s A');

$page_title = 'ASB Fashion | Date Range Stock & GRN Report';
$page = 'reports';

$conn = getConnection();

// ========== FETCH SUPPLIERS & FLOORS FOR FILTERS ==========
$suppliers_result = $conn->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
$suppliers_list = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

$floors_result = $conn->query("SELECT floor_id, floor_name FROM floors ORDER BY floor_name ASC");
$floors_list = $floors_result ? $floors_result->fetch_all(MYSQLI_ASSOC) : [];

// ========== FILTER & PAGINATION INPUTS ==========
$start_date   = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date     = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$supplier_id  = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$floor_filter = isset($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;

$items_per_page = 50; // Batch size
$current_page   = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
if ($current_page < 1) $current_page = 1;
$offset         = ($current_page - 1) * $items_per_page;

// ========== BUILD WHERE CLAUSES ==========
$where_clauses = ["gh.received_date BETWEEN ? AND ?"];
$types = "ss";
$params = [$start_date . " 00:00:00", $end_date . " 23:59:59"];

if ($supplier_id > 0) {
    $where_clauses[] = "ph.supplier_id = ?";
    $types .= "i";
    $params[] = $supplier_id;
}

if ($floor_filter > 0) {
    $where_clauses[] = "gh.floor_id = ?";
    $types .= "i";
    $params[] = $floor_filter;
}

$where_str = implode(" AND ", $where_clauses);

// Fetch Supplier and Floor names for display headers
$selected_supplier_name = "All Suppliers";
if ($supplier_id > 0) {
    foreach ($suppliers_list as $sup) {
        if ($sup['supplier_id'] == $supplier_id) { $selected_supplier_name = $sup['supplier_name']; break; }
    }
}
$selected_floor_name = "All Floors / Sections";
if ($floor_filter > 0) {
    foreach ($floors_list as $fl) {
        if ($fl['floor_id'] == $floor_filter) { $selected_floor_name = $fl['floor_name']; break; }
    }
}

// ========== 1. LIGHTNING-FAST COUNT & TOTALS QUERY ==========
$summary_sql = "SELECT 
                    COUNT(DISTINCT gh.grn_id) as total_grns,
                    COALESCE(SUM(gi.qty_received), 0) as total_qty,
                    COALESCE(SUM(gi.qty_received * COALESCE(pi.cost_price, it.cost_price, 0)), 0) as total_net
                FROM grn_items gi
                INNER JOIN grn_header gh ON gi.grn_id = gh.grn_id
                INNER JOIN po_header ph ON gh.po_id = ph.po_id
                INNER JOIN po_items pi ON gi.po_item_id = pi.po_item_id
                INNER JOIN items it ON gi.item_id = it.item_id
                WHERE $where_str";

$sum_stmt = $conn->prepare($summary_sql);
if (!$sum_stmt) {
    die("<div style='font-family:sans-serif; padding:20px; border:1px solid #cc0000; background:#fff5f5; border-radius:6px; margin:20px;'>
            <h3 style='color:#cc0000; margin-top:0;'>Database Query Error (Summary Matrix)</h3>
            <p><strong>SQL Error:</strong> " . htmlspecialchars($conn->error) . "</p>
         </div>");
}

if (!empty($params)) $sum_stmt->bind_param($types, ...$params);
$sum_stmt->execute();
$summary_data = $sum_stmt->get_result()->fetch_assoc();
$sum_stmt->close();

$total_records      = (int)$summary_data['total_grns'];
$total_qty_received = (int)$summary_data['total_qty'];
$grand_net_amount   = (float)$summary_data['total_net'];
$total_pages        = ceil($total_records / $items_per_page);

// Count total box counts for the filtered range
$box_sql = "SELECT COALESCE(SUM(total_box_count), 0) FROM (
                SELECT DISTINCT gh.grn_id, gh.total_box_count 
                FROM grn_header gh 
                INNER JOIN po_header ph ON gh.po_id = ph.po_id 
                WHERE $where_str
            ) as distinct_boxes";
$box_stmt = $conn->prepare($box_sql);
if ($box_stmt) {
    if (!empty($params)) $box_stmt->bind_param($types, ...$params);
    $box_stmt->execute();
    $total_boxes_sum = (int)$box_stmt->get_result()->fetch_row()[0];
    $box_stmt->close();
} else {
    $total_boxes_sum = 0;
}

// Total PO Ordered Quantity across all distinct POs associated with these GRNs
$po_tot_sql = "SELECT COALESCE(SUM(poi.quantity), 0) 
               FROM po_items poi 
               WHERE poi.po_id IN (
                   SELECT DISTINCT gh.po_id 
                   FROM grn_header gh 
                   INNER JOIN po_header ph ON gh.po_id = ph.po_id 
                   WHERE $where_str
               )";
$po_tot_stmt = $conn->prepare($po_tot_sql);
if ($po_tot_stmt) {
    if (!empty($params)) $po_tot_stmt->bind_param($types, ...$params);
    $po_tot_stmt->execute();
    $total_po_ordered_sum = (int)$po_tot_stmt->get_result()->fetch_row()[0];
    $po_tot_stmt->close();
} else {
    $total_po_ordered_sum = 0;
}

// PO Total Qty - Received Qty (Total Balance / Pending Units)
$total_balance_qty = $total_po_ordered_sum - $total_qty_received;
$fulfillment_rate  = $total_po_ordered_sum > 0 ? ($total_qty_received / $total_po_ordered_sum) * 100 : 0;

// ========== 2. PAGINATED DATA QUERY GROUPED BY GRN ==========
$report_query = "SELECT 
                    gh.grn_id, gh.grn_number, gh.received_date, gh.delivery_note_no, gh.total_box_count,
                    ph.po_id, ph.po_number, ph.purchase_date, ph.attention, ph.remarks,
                    s.supplier_name,
                    f.floor_name,
                    SUM(gi.qty_received) as total_received_qty,
                    (SELECT SUM(poi.quantity) FROM po_items poi WHERE poi.po_id = ph.po_id) as po_total_ordered_qty,
                    SUM(gi.qty_received * COALESCE(pi.cost_price, it.cost_price, 0)) as grn_net_amount
                 FROM grn_items gi
                 INNER JOIN grn_header gh ON gi.grn_id = gh.grn_id
                 INNER JOIN po_header ph ON gh.po_id = ph.po_id
                 INNER JOIN po_items pi ON gi.po_item_id = pi.po_item_id
                 INNER JOIN items it ON gi.item_id = it.item_id
                 LEFT JOIN suppliers s ON ph.supplier_id = s.supplier_id
                 LEFT JOIN floors f ON gh.floor_id = f.floor_id
                 WHERE $where_str
                 GROUP BY gh.grn_id, gh.grn_number, gh.received_date, gh.delivery_note_no, gh.total_box_count, ph.po_id, ph.po_number, ph.purchase_date, ph.attention, ph.remarks, s.supplier_name, f.floor_name
                 ORDER BY gh.received_date DESC, gh.grn_id DESC 
                 LIMIT ? OFFSET ?";

$types_paginated = $types . "ii";
$params_paginated = array_merge($params, [$items_per_page, $offset]);

$stmt = $conn->prepare($report_query);
if (!$stmt) {
    die("<div style='font-family:sans-serif; padding:20px; border:1px solid #cc0000; background:#fff5f5; border-radius:6px; margin:20px;'>
            <h3 style='color:#cc0000; margin-top:0;'>Database Query Error (Report Data Matrix)</h3>
            <p><strong>SQL Error:</strong> " . htmlspecialchars($conn->error) . "</p>
         </div>");
}

$stmt->bind_param($types_paginated, ...$params_paginated);
$stmt->execute();
$report_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #2d3748; font-size: 12px; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; border-left: 6px solid #b71c1c; padding-left: 15px; margin-bottom: 20px; font-size: 20px; }
    
    /* Screen Card Styles */
    .asb-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 30px; }
    .asb-card-header { background: #ffffff; border-bottom: 2px solid #edf2f7; padding: 18px 24px; color: #b71c1c; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
    
    /* Tables */
    .asb-table { width: 100%; border-collapse: collapse; }
    .asb-table th { background: #edf2f7 !important; color: #1a202c !important; font-weight: 700 !important; text-transform: uppercase; font-size: 10px; letter-spacing: 0.6px; padding: 11px 12px !important; border-bottom: 2px solid #cbd5e0 !important; }
    .asb-table td { padding: 11px 12px !important; vertical-align: middle !important; border-bottom: 1px solid #f1f5f9 !important; font-size: 11.5px; color: #2d3748; }
    .asb-table tbody tr:hover { background-color: #fef2f2; }
    .asb-table tfoot td { background: #f8fafc; font-weight: 800; border-top: 2px solid #cbd5e0; border-bottom: 2px solid #cbd5e0; padding: 10px 12px; }

    /* Buttons & Inputs */
    .btn-asb-action { background: linear-gradient(135deg, #b71c1c 0%, #7f1d1d 100%); color: #fff; border: none; font-weight: 700; padding: 10px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(183,28,28,0.25); transition: all 0.2s ease; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-size: 12.5px; gap: 6px; }
    .btn-asb-action:hover { background: linear-gradient(135deg, #991b1b 0%, #6b1515 100%); box-shadow: 0 6px 16px rgba(183,28,28,0.35); color: #fff; transform: translateY(-1px); }
    
    .btn-asb-secondary { background: #ffffff; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; padding: 9px 18px; border-radius: 8px; font-size: 12.5px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.2s ease; }
    .btn-asb-secondary:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; transform: translateY(-1px); }
    
    .btn-asb-print { background: #1e293b; color: #ffffff; border: none; font-weight: 700; padding: 10px 20px; border-radius: 8px; font-size: 12.5px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: all 0.2s ease; }
    .btn-asb-print:hover { background: #0f172a; color: #ffffff; transform: translateY(-1px); }

    .asb-input { border: 1px solid #cbd5e0; padding: 10px 12px; border-radius: 8px; width: 100%; transition: all 0.2s; font-size: 12.5px; background: #fff; }
    .asb-input:focus { border-color: #b71c1c; box-shadow: 0 0 0 3px rgba(183,28,28,0.12); outline: none; }
    
    /* Summary Grid */
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 25px; }
    .summary-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); position: relative; overflow: hidden; border-left: 4px solid #b71c1c; }
    .summary-box.theme-warning { border-left-color: #d97706; }
    .summary-box.theme-success { border-left-color: #16a34a; }
    .summary-box.theme-dark { border-left-color: #1e293b; }
    .summary-box .title { font-size: 10.5px; text-transform: uppercase; color: #718096; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px; }
    .summary-box .val { font-size: 20px; font-weight: 800; color: #1a202c; font-family: 'Segoe UI', Tahoma, monospace; }
    .summary-box .sub-val { font-size: 11px; color: #94a3b8; margin-top: 4px; font-weight: 500; }
    
    .po-link { color: #b71c1c; text-decoration: none; font-weight: 700; }
    .po-link:hover { text-decoration: underline; color: #7f1d1d; }

    /* Elements hidden on screen */
    .print-document-header, .print-meta-grid, .print-signature-section, .print-document-footer { display: none; }

    /* =========================================================
       ULTRA-PROFESSIONAL A4 LANDSCAPE PRINT STYLING
       ========================================================= */
    @media print {
        @page { size: A4 landscape; margin: 8mm 10mm 10mm 10mm; }
        
        /* Hide screen-only controls, navigation & links annotations */
        .no-print, .asb-header-title, .asb-card-header, .pagination-container, .asb-footer { display: none !important; }
        
        /* Disable printed URLs, icons and link styles */
        a { text-decoration: none !important; color: #000000 !important; font-weight: bold !important; pointer-events: none !important; }
        a[href]:after { content: none !important; }
        .po-link-icon { display: none !important; }

        body { background: #fff !important; margin: 0; padding: 0; font-size: 8.5pt; color: #1a202c; line-height: 1.3; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-family: 'Segoe UI', Arial, sans-serif !important; }
        .container-fluid { padding: 0 !important; margin: 0 !important; width: 100% !important; max-width: 100% !important; }
        
        /* Document Card Reset */
        .asb-card { border: none !important; box-shadow: none !important; margin-bottom: 0 !important; border-radius: 0 !important; background: transparent !important; }
        
        /* Executive Print Header Branding */
        .print-document-header { display: flex !important; justify-content: space-between; align-items: flex-start; border-bottom: 2.5px solid #b71c1c; padding-bottom: 8px; margin-bottom: 10px; }
        .print-brand-left { display: flex !important; align-items: center; gap: 10px; }
        .print-logo-badge { background: #b71c1c !important; color: #ffffff !important; font-weight: 900; font-size: 20px; width: 44px; height: 44px; border-radius: 6px; display: flex; align-items: center; justify-content: center; }
        .print-brand-title { font-size: 17pt; font-weight: 900; color: #b71c1c !important; margin: 0; text-transform: uppercase; letter-spacing: 0.6px; line-height: 1; }
        .print-brand-subtitle { font-size: 8pt; color: #4a5568; margin: 3px 0 0 0; font-weight: 600; }
        .print-doc-title { font-size: 9.5pt; font-weight: 800; color: #0f172a; margin: 3px 0 0 0; text-transform: uppercase; letter-spacing: 0.3px; }
        
        .print-meta-right { text-align: right; font-size: 8pt; color: #334155; line-height: 1.45; border-left: 2px solid #cbd5e1; padding-left: 12px; }
        .print-meta-right .lbl { color: #64748b; font-weight: 600; margin-right: 4px; }
        .print-meta-right .val { color: #0f172a; font-weight: 700; }
        .print-meta-right .time-highlight { color: #b71c1c !important; font-weight: 800; }

        /* Metadata Grid */
        .print-meta-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 8px; background: #fdf2f2 !important; border: 1px solid #feb2b2; padding: 6px 10px; border-radius: 5px; margin-bottom: 10px; font-size: 7.5pt; }
        .print-meta-grid div { margin-bottom: 1px; color: #2d3748; }
        .print-meta-grid strong { color: #b71c1c; }

        /* Summary Cards on Landscape Print */
        .summary-grid { display: grid !important; grid-template-columns: repeat(6, 1fr) !important; gap: 8px !important; margin-bottom: 10px !important; page-break-inside: avoid; break-inside: avoid; }
        .summary-box { background: #fff !important; border: 1px solid #cbd5e0 !important; padding: 6px 10px !important; box-shadow: none !important; border-radius: 4px !important; }
        .summary-box .title { font-size: 6.5pt !important; color: #4a5568 !important; margin-bottom: 2px; }
        .summary-box .val { font-size: 10.5pt !important; color: #1a202c !important; }
        .summary-box .sub-val { font-size: 6pt !important; }

        /* Table Styling with Balanced Margins */
        .table-responsive { overflow: visible !important; }
        .asb-table { width: 100% !important; border-collapse: collapse !important; font-size: 7.5pt !important; }
        .asb-table thead { display: table-header-group !important; }
        .asb-table th { background-color: #edf2f7 !important; color: #1a202c !important; border-bottom: 1.5px solid #b71c1c !important; font-size: 7.5pt !important; padding: 5px 6px !important; }
        .asb-table tbody tr { page-break-inside: avoid !important; break-inside: avoid !important; }
        .asb-table td { padding: 4px 6px !important; font-size: 7.5pt !important; border-bottom: 1px solid #e2e8f0 !important; color: #2d3748 !important; }
        .asb-table tfoot td { background-color: #edf2f7 !important; border-top: 1.5px solid #000000 !important; border-bottom: 1.5px solid #000000 !important; padding: 5px 6px !important; font-size: 7.5pt !important; }
        
        /* Signatures Section */
        .print-signature-section { display: grid !important; grid-template-columns: 1fr 1fr 1fr; gap: 35px; margin-top: 25px; text-align: center; page-break-inside: avoid; break-inside: avoid; }
        .print-sig-box { border-top: 1px solid #000000; padding-top: 5px; font-size: 8pt; color: #000000; font-weight: 700; }
        .print-sig-sub { font-size: 7pt; color: #555555; margin-top: 2px; }
        .print-sig-gap { height: 32px; }

        /* Print Footer */
        .print-document-footer {
            display: flex !important;
            border-top: 1.5px solid #b71c1c;
            padding-top: 6px;
            margin-top: 18px;
            font-size: 7.5pt;
            color: #4a5568;
            justify-content: space-between;
            align-items: center;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .print-document-footer strong { color: #000000; }
        .print-document-footer .footer-center { font-weight: bold; color: #b71c1c; text-transform: uppercase; font-size: 7pt; }
        .print-document-footer .footer-right strong { color: #b71c1c; }
    }
</style>

<div class="container-fluid" style="padding: 15px 25px 40px 25px;">
    
    <!-- Top Action Bar (Screen Only) -->
    <div class="page-header no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <h2 class="asb-header-title" style="margin-bottom: 0;">
            <i class="fas fa-boxes"></i> ASB Fashion 
            <span style="font-weight:400; color:#718096; font-size:16px;">| Date Range Stock &amp; GRN Report</span>
        </h2>
        
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <!-- BACK TO DASHBOARD BUTTON -->
            <a href="dashboard.php" class="btn-asb-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            
            <!-- PRINT REPORT BUTTON -->
            <button type="button" onclick="prepareAndPrint();" class="btn-asb-print">
                <i class="fas fa-print"></i> Print Landscape Report
            </button>
        </div>
    </div>

    <!-- =========================================================
         PRINT-ONLY EXECUTIVE LANDSCAPE A4 HEADER & METADATA
         ========================================================= -->
    <div class="print-document-header">
        <div class="print-brand-left">
            <div class="print-logo-badge">ASB</div>
            <div>
                <h1 class="print-brand-title">ASB FASHION</h1>
                <p class="print-brand-subtitle">ASB Group Of Companies • Head Office Supply Chain Complex</p>
                <p class="print-doc-title">DATE RANGE STOCK INWARD &amp; GRN AUDIT LEDGER REPORT</p>
            </div>
        </div>

        <div class="print-meta-right">
            <div><span class="lbl">Reporting Period:</span> <span class="val"><?= date('d/m/Y', strtotime($start_date)); ?> to <?= date('d/m/Y', strtotime($end_date)); ?></span></div>
            <div><span class="lbl">Printed Date &amp; Time:</span> <span class="val time-highlight print-time-slot"><?= $server_printed_time; ?></span></div>
            <div><span class="lbl">Printed By:</span> <span class="val"><?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span></div>
            <div><span class="lbl">Supplier Scope:</span> <span class="val"><?= htmlspecialchars($selected_supplier_name); ?></span></div>
            <div><span class="lbl">Floor / Section:</span> <span class="val"><?= htmlspecialchars($selected_floor_name); ?></span></div>
        </div>
    </div>

    <!-- Dynamic Landscape Print Metadata Box -->
    <div class="print-meta-grid">
        <div><strong>Reporting Period:</strong> <?= date('d/m/Y', strtotime($start_date)); ?> to <?= date('d/m/Y', strtotime($end_date)); ?></div>
        <div><strong>Selected Supplier:</strong> <?= htmlspecialchars($selected_supplier_name); ?></div>
        <div><strong>Floor / Section:</strong> <?= htmlspecialchars($selected_floor_name); ?></div>
        <div><strong>Total Shipments Logged:</strong> <?= number_format($total_records); ?> GRN Batches</div>
    </div>

    <!-- ===== FILTER FORM (SCREEN ONLY) ===== -->
    <div class="asb-card no-print" style="margin-bottom: 25px;">
        <div style="padding: 20px 24px; background: #fff;">
            <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">
                <div style="flex:1; min-width:150px;">
                    <label style="font-weight:700; margin-bottom:6px; display:block; font-size:11.5px; color:#4a5568;">
                        <i class="fas fa-calendar-alt text-red-700"></i> Start Date *
                    </label>
                    <input type="date" name="start_date" class="asb-input" value="<?= htmlspecialchars($start_date); ?>" required>
                </div>
                <div style="flex:1; min-width:150px;">
                    <label style="font-weight:700; margin-bottom:6px; display:block; font-size:11.5px; color:#4a5568;">
                        <i class="fas fa-calendar-check text-red-700"></i> End Date *
                    </label>
                    <input type="date" name="end_date" class="asb-input" value="<?= htmlspecialchars($end_date); ?>" required>
                </div>
                <div style="flex:2; min-width:210px;">
                    <label style="font-weight:700; margin-bottom:6px; display:block; font-size:11.5px; color:#4a5568;">
                        <i class="fas fa-truck text-red-700"></i> Supplier Filter
                    </label>
                    <select name="supplier_id" class="asb-input">
                        <option value="0">-- All Suppliers --</option>
                        <?php foreach ($suppliers_list as $sup): ?>
                            <option value="<?= $sup['supplier_id']; ?>" <?= $supplier_id == $sup['supplier_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($sup['supplier_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1; min-width:170px;">
                    <label style="font-weight:700; margin-bottom:6px; display:block; font-size:11.5px; color:#4a5568;">
                        <i class="fas fa-warehouse text-red-700"></i> Floor / Section
                    </label>
                    <select name="floor_id" class="asb-input">
                        <option value="0">-- All Floors --</option>
                        <?php foreach ($floors_list as $fl): ?>
                            <option value="<?= $fl['floor_id']; ?>" <?= $floor_filter == $fl['floor_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($fl['floor_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn-asb-action">
                        <i class="fas fa-search"></i> Filter Records
                    </button>
                    <a href="date_range_stock_report.php" class="btn-asb-secondary" title="Reset Filters">
                        <i class="fas fa-sync-alt"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- =========================================================
         SUMMARY METRICS CARDS (INCLUDING PO TOTAL - RECEIVED QTY)
         ========================================================= -->
    <div class="summary-grid">
        <div class="summary-box">
            <div class="title">Total GRN Batches</div>
            <div class="val"><?= number_format($total_records); ?></div>
            <div class="sub-val">Inward Shipments</div>
        </div>

        <div class="summary-box theme-dark">
            <div class="title">PO Total Ordered Qty</div>
            <div class="val"><?= number_format($total_po_ordered_sum); ?></div>
            <div class="sub-val">Units Committed</div>
        </div>

        <div class="summary-box">
            <div class="title">Total Units Received</div>
            <div class="val" style="color:#b71c1c;"><?= number_format($total_qty_received); ?></div>
            <div class="sub-val"><?= number_format($fulfillment_rate, 1); ?>% Fulfilled</div>
        </div>

        <!-- Remaining Balance Qty Section (PO Total - Received) -->
        <div class="summary-box theme-warning">
            <div class="title" style="color:#b45309;">Remaining Balance Qty</div>
            <div class="val" style="color:#b45309;"><?= number_format($total_balance_qty); ?></div>
            <div class="sub-val">Pending Units (PO Total - Received)</div>
        </div>

        <div class="summary-box">
            <div class="title">Total Cartons / Boxes</div>
            <div class="val"><?= number_format($total_boxes_sum); ?> <span style="font-size:12px; font-weight:normal; color:#718096;">Ctn</span></div>
            <div class="sub-val">Physical Cartons</div>
        </div>

        <div class="summary-box theme-success">
            <div class="title" style="color: #16a34a;">Total Net Financial Amount</div>
            <div class="val" style="color: #16a34a; font-size:17px;">Rs. <?= number_format($grand_net_amount, 2); ?></div>
            <div class="sub-val">Procurement Valuation</div>
        </div>
    </div>

    <!-- ===== REPORT TABLE CONTAINER ===== -->
    <div class="asb-card">
        <div class="asb-card-header no-print">
            <span><i class="fas fa-clipboard-list"></i> Stock Inward Ledger Statement (From <?= date('d/m/Y', strtotime($start_date)); ?> To <?= date('d/m/Y', strtotime($end_date)); ?>)</span>
            <span style="font-size:12px; color:#718096; font-weight:normal;">Showing page <?= $current_page; ?> of <?= max(1, $total_pages); ?> (<?= number_format($total_records); ?> GRNs)</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($report_items)): ?>
                <div style="text-align:center; padding:60px; color:#a0aec0; font-size:14px;">
                    <i class="fas fa-inbox" style="font-size:32px; display:block; margin-bottom:10px;"></i>
                    No stock receiving records found matching your active filter criteria.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table asb-table" style="width:100%; margin-bottom:0; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th>GRN Date &amp; #</th>
                                <th>PO Date &amp; #</th>
                                <th>Supplier Name</th>
                                <th>Attention / Person</th>
                                <th>Floor</th>
                                <th style="text-align:right;">Boxes</th>
                                <th style="text-align:right;">PO Total Qty</th>
                                <th style="text-align:right;">Received Qty</th>
                                <!-- Balance Qty Column (PO Total - Received) -->
                                <th style="text-align:right;">Balance Qty</th>
                                <th style="text-align:right;">Net Amount</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $page_boxes    = 0;
                            $page_po_total = 0;
                            $page_received = 0;
                            $page_balance  = 0;
                            $page_net      = 0;

                            foreach ($report_items as $row): 
                                $po_ordered   = (int)$row['po_total_ordered_qty'];
                                $po_received  = (int)$row['total_received_qty'];
                                $po_diff      = $po_ordered - $po_received;
                                $row_boxes    = (int)$row['total_box_count'];
                                $row_net      = (float)$row['grn_net_amount'];

                                $page_boxes    += $row_boxes;
                                $page_po_total += $po_ordered;
                                $page_received += $po_received;
                                $page_balance  += $po_diff;
                                $page_net      += $row_net;
                            ?>
                                <tr>
                                    <td>
                                        <strong style="color:#b71c1c;"><?= htmlspecialchars($row['grn_number']); ?></strong><br>
                                        <span style="font-size:10px; color:#718096;"><?= date('d/m/Y H:i', strtotime($row['received_date'])); ?></span>
                                    </td>
                                    <td>
                                        <!-- Clean display for print (NO emoji, NO links) and screen interactive link -->
                                        <a href="po_audit_report.php?po_id=<?= (int)$row['po_id']; ?>" class="po-link" target="_blank" title="View Full PO Audit Statement">
                                            <?= htmlspecialchars($row['po_number']); ?><span class="po-link-icon no-print"> 🔗</span>
                                        </a><br>
                                        <span style="font-size:10px; color:#718096;"><?= date('d/m/Y', strtotime($row['purchase_date'])); ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown'); ?></td>
                                    <td><strong style="color:#4a5568;"><?= htmlspecialchars($row['attention'] ?? '—'); ?></strong></td>
                                    <td>
                                        <span style="background:#fff5f5; color:#b71c1c; padding:3px 8px; border-radius:4px; font-size:10.5px; font-weight:700; border:1px solid #fed7d7;">
                                            <?= htmlspecialchars($row['floor_name'] ?? '—'); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right; font-weight:600;"><?= number_format($row_boxes); ?> Ctn</td>
                                    <td style="text-align:right; font-weight:700; color:#4a5568;"><?= number_format($po_ordered); ?></td>
                                    <td style="text-align:right; font-weight:700; color:#b71c1c;"><?= number_format($po_received); ?></td>
                                    
                                    <!-- PO Total Qty - Received Qty -->
                                    <td style="text-align:right; font-weight:800; color:<?= $po_diff > 0 ? '#b45309' : ($po_diff < 0 ? '#dc2626' : '#16a34a'); ?>;">
                                        <?= number_format($po_diff); ?>
                                    </td>

                                    <td style="text-align:right; font-weight:800; color:#2f855a;">Rs. <?= number_format($row_net, 2); ?></td>
                                    <td style="color:#4a5568; font-style:italic; max-width:140px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($row['remarks'] ?? ''); ?>">
                                        <?= htmlspecialchars($row['remarks'] ?? '—'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#edf2f7; font-weight:bold;">
                                <td colspan="5" style="text-align:right; text-transform:uppercase; font-size:10.5px;">Page Subtotal:</td>
                                <td style="text-align:right; font-weight:700;"><?= number_format($page_boxes); ?> Ctn</td>
                                <td style="text-align:right; font-weight:700; color:#4a5568;"><?= number_format($page_po_total); ?></td>
                                <td style="text-align:right; font-weight:700; color:#b71c1c;"><?= number_format($page_received); ?></td>
                                <td style="text-align:right; font-weight:800; color:#b45309;"><?= number_format($page_balance); ?></td>
                                <td style="text-align:right; font-weight:800; color:#2f855a;">Rs. <?= number_format($page_net, 2); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Pagination Links (Screen Only) -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-container no-print" style="padding:16px 24px; background:#f8f9fa; border-top:1px solid #e2e8f0; display:flex; justify-content:center; align-items:center; gap:6px; flex-wrap:wrap;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || abs($i - $current_page) <= 2): ?>
                                <a href="?page_num=<?= $i ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&supplier_id=<?= $supplier_id ?>&floor_id=<?= $floor_filter ?>" class="btn-asb-action" style="background:<?= $i==$current_page?'#b71c1c':'#edf2f7'; ?>; color:<?= $i==$current_page?'#fff':'#4a5568'; ?>; padding:6px 12px; font-size:12px; box-shadow:none;"><?= $i ?></a>
                            <?php elseif (abs($i - $current_page) == 3): ?>
                                <span style="padding:0 6px; color:#cbd5e0;">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- =========================================================
         PRINT-ONLY LANDSCAPE SIGNATURE & VERIFICATION SECTION
         ========================================================= -->
    <div class="print-signature-section">
        <div class="sig-col">
            <div class="print-sig-gap"></div>
            <div class="print-sig-box">Prepared By (Goods Receiving Officer)</div>
            <div class="print-sig-sub">Signature &amp; Date: _________________</div>
        </div>
        <div class="sig-col">
            <div class="print-sig-gap"></div>
            <div class="print-sig-box">Verified &amp; Audited By (Warehouse Lead)</div>
            <div class="print-sig-sub">Signature &amp; Date: _________________</div>
        </div>
        <div class="sig-col">
            <div class="print-sig-gap"></div>
            <div class="print-sig-box">Authorized Approval (Operations / Finance)</div>
            <div class="print-sig-sub">Official Seal &amp; Date: _________________</div>
        </div>
    </div>

    <!-- =========================================================
         PRINT-ONLY CORPORATE FOOTER WITH SERVER TIME & VEXEL IT
         ========================================================= -->
    <div class="print-document-footer">
        <div class="footer-left">
            <span><strong>Printed on:</strong> <span class="print-time-slot"><?= $server_printed_time; ?></span> (Server Time)</span>
            <span style="margin: 0 6px;">|</span>
            <span><strong>User:</strong> <?= htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
        </div>
        <div class="footer-center">
            <span>ASB Fashion Central Inventory Ledger • Landscape Audit Copy</span>
        </div>
        <div class="footer-right">
            <span>Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
        </div>
    </div>

    <!-- Screen Footer -->
    <div class="asb-footer no-print" style="text-align: center; margin-top: 50px; padding: 25px; color: #718096; border-top: 1px solid #e2e8f0; font-size: 13px;">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:6px; display:inline-block; color:#a0aec0;">
            System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong> • Server Time: <?= $server_printed_time; ?>
        </span>
    </div>
</div>

<script>
function prepareAndPrint() {
    // Dynamically refresh printed timestamp to current time before print dialog opens
    let now = new Date();
    let day = String(now.getDate()).padStart(2, '0');
    let month = String(now.getMonth() + 1).padStart(2, '0');
    let year = now.getFullYear();
    let hours = now.getHours();
    let minutes = String(now.getMinutes()).padStart(2, '0');
    let seconds = String(now.getSeconds()).padStart(2, '0');
    let ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    let formattedHours = String(hours).padStart(2, '0');
    let formattedTime = day + '/' + month + '/' + year + ' ' + formattedHours + ':' + minutes + ':' + seconds + ' ' + ampm;
    
    document.querySelectorAll('.print-time-slot').forEach(function(el) {
        el.innerText = formattedTime;
    });

    window.print();
}
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>
