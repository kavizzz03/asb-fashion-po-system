<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Date Range Stock & GRN Report';
$page = 'reports';

$conn = getConnection();

// ========== FETCH SUPPLIERS & FLOORS FOR FILTERS ==========
$suppliers_result = $conn->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
$suppliers_list = $suppliers_result ? $suppliers_result->fetch_all(MYSQLI_ASSOC) : [];

$floors_result = $conn->query("SELECT floor_id, floor_name FROM floors ORDER BY floor_name ASC");
$floors_list = $floors_result ? $floors_result->fetch_all(MYSQLI_ASSOC) : [];

// ========== FILTER & PAGINATION INPUTS ==========
$start_date   = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date     = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$supplier_id  = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$floor_filter = isset($_GET['floor_id']) ? (int)$_GET['floor_id'] : 0;

$items_per_page = 50; // Optimized batch size for 1M+ records
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

// ========== 1. LIGHTNING-FAST COUNT & TOTALS QUERY (Optimized for 1M+ rows) ==========
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
    $total_boxes_sum = $box_stmt->get_result()->fetch_row()[0];
    $box_stmt->close();
} else {
    $total_boxes_sum = 0;
}

// ========== 2. PAGINATED DATA QUERY GROUPED BY GRN (Including PO Total Qty, Attention, Remarks) ==========
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
    body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; font-size: 12px; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; margin-bottom: 25px; }
    
    /* Screen Card Styles */
    .asb-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 30px; }
    .asb-card-header { background: #fff; border-bottom: 2px solid #f1f3f5; padding: 20px 24px; color: #b71c1c; font-weight: bold; font-size: 14px; }
    
    /* Tables */
    .asb-table th { background: #f1f3f5 !important; color: #212529 !important; font-weight: 700 !important; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; padding: 10px 12px !important; border-bottom: 2px solid #dee2e6 !important; }
    .asb-table td { padding: 10px 12px !important; vertical-align: middle !important; border-bottom: 1px solid #f1f3f5 !important; font-size: 11.5px; color: #333; }
    .asb-table tbody tr:hover { background-color: #fafbfc; }

    /* Buttons & Inputs */
    .btn-asb-action { background: #d32f2f; color: #fff; border: none; font-weight: 700; padding: 11px 22px; border-radius: 6px; box-shadow: 0 2px 6px rgba(211,47,47,0.25); transition: all 0.2s ease; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; }
    .btn-asb-action:hover { background: #b71c1c; box-shadow: 0 4px 10px rgba(211,47,47,0.35); color: #fff; }
    .asb-input { border: 1px solid #ced4da; padding: 11px 14px; border-radius: 6px; width: 100%; transition: all 0.2s; font-size: 13px; background: #fff; }
    .asb-input:focus { border-color: #d32f2f; box-shadow: 0 0 0 3px rgba(211,47,47,0.12); outline: none; }
    
    /* Summary Grid */
    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 25px; }
    .summary-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px 22px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
    .summary-box .title { font-size: 11px; text-transform: uppercase; color: #6c757d; font-weight: 700; letter-spacing: 0.3px; }
    .summary-box .val { font-size: 22px; font-weight: 800; color: #b71c1c; margin-top: 6px; }
    
    .po-link { color: #b71c1c; text-decoration: none; font-weight: bold; }
    .po-link:hover { text-decoration: underline; color: #8a1010; }

    /* =========================================================
       ULTRA-PROFESSIONAL A4 LANDSCAPE PRINT STYLING
       ========================================================= */
    .print-document-header, .print-meta-grid, .print-signature-section { display: none; }

    @media print {
        @page { size: A4 landscape; margin: 10mm 12mm; }
        
        /* Hide UI clutter */
        .no-print, .asb-header-title, .asb-card-header, .pagination-container, .asb-footer { display: none !important; }
        
        body { background: #fff !important; margin: 0; padding: 0; font-size: 9pt; color: #111; line-height: 1.3; }
        .container-fluid { padding: 0 !important; margin: 0 !important; width: 100% !important; }
        
        /* Document Card Reset */
        .asb-card { border: none !important; box-shadow: none !important; margin-bottom: 0 !important; border-radius: 0 !important; }
        
        /* Print Header Branding */
        .print-document-header { display: flex !important; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #b71c1c; padding-bottom: 8px; margin-bottom: 12px; }
        .print-brand-title { font-size: 22pt; font-weight: 900; color: #b71c1c; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .print-brand-subtitle { font-size: 9pt; color: #495057; margin: 2px 0 0 0; font-weight: 600; }
        
        /* Metadata Grid */
        .print-meta-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 10px; background: #f8f9fa !important; border: 1px solid #ced4da; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 8.5pt; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .print-meta-grid div { margin-bottom: 2px; color: #212529; }
        .print-meta-grid strong { color: #b71c1c; }

        /* Summary Cards on Landscape Print */
        .summary-grid { display: grid !important; grid-template-columns: repeat(4, 1fr) !important; gap: 10px !important; margin-bottom: 12px !important; page-break-inside: avoid; }
        .summary-box { background: #fdfdfd !important; border: 1px solid #adb5bd !important; padding: 8px 12px !important; box-shadow: none !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .summary-box .title { font-size: 8pt !important; color: #495057 !important; }
        .summary-box .val { font-size: 12pt !important; color: #b71c1c !important; }

        /* Table Styling */
        .table-responsive { overflow: visible !important; }
        .asb-table { width: 100% !important; border-collapse: collapse !important; }
        .asb-table th { background-color: #e9ecef !important; color: #212529 !important; border-bottom: 2.5px solid #b71c1c !important; font-size: 8.5pt !important; padding: 6px 8px !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .asb-table td { padding: 6px 8px !important; font-size: 8.5pt !important; border-bottom: 1px solid #dee2e6 !important; color: #212529 !important; }
        
        /* Signatures Section */
        .print-signature-section { display: grid !important; grid-template-columns: 1fr 1fr 1fr; gap: 40px; margin-top: 30px; text-align: center; page-break-inside: avoid; }
        .print-sig-box { border-top: 1px dashed #6c757d; padding-top: 6px; font-size: 9pt; color: #333; font-weight: 600; }
        .print-sig-gap { height: 35px; }

        /* Print Footer */
        .print-doc-footer { display: block !important; text-align: center; margin-top: 25px; padding-top: 8px; border-top: 1px solid #ced4da; font-size: 8pt; color: #6c757d; page-break-inside: avoid; }
    }
</style>

<div class="container-fluid" style="padding: 20px 30px;">
    <div class="page-header no-print">
        <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:18px;">| Date Range Stock &amp; GRN Report (Landscape A4)</span></h2>
    </div>

    <!-- =========================================================
         PRINT-ONLY LANDSCAPE A4 HEADER & METADATA
         ========================================================= -->
    <div class="print-document-header">
        <div>
            <h1 class="print-brand-title">ASB Fashion</h1>
            <p class="print-brand-subtitle">Head Office Supply Chain Complex • Comprehensive Stock Inward &amp; GRN Audit Statement (Landscape)</p>
        </div>
        <div style="text-align: right; font-size: 9pt; color: #495057;">
            <strong>Generated Timestamp:</strong> <?= date('Y-m-d H:i:s'); ?><br>
            <strong>System Ledger:</strong> Central ERP v3.2
        </div>
    </div>

    <!-- Dynamic Landscape Print Metadata Box -->
    <div class="print-meta-grid">
        <div><strong>Reporting Period:</strong> <?= htmlspecialchars($start_date); ?> to <?= htmlspecialchars($end_date); ?></div>
        <div><strong>Selected Supplier:</strong> <?= htmlspecialchars($selected_supplier_name); ?></div>
        <div><strong>Floor / Section:</strong> <?= htmlspecialchars($selected_floor_name); ?></div>
        <div><strong>Total Batches:</strong> <?= number_format($total_records); ?> GRN Shipments</div>
    </div>

    <!-- ===== FILTER FORM (SCREEN ONLY) ===== -->
    <div class="asb-card no-print" style="margin-bottom: 25px;">
        <div style="padding: 22px; background: #fff;">
            <form method="GET" style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;">
                <div style="flex:1; min-width:160px;">
                    <label style="font-weight:700; margin-bottom:8px; display:block; font-size:12px; color:#495057;">Start Date</label>
                    <input type="date" name="start_date" class="asb-input" value="<?= htmlspecialchars($start_date); ?>" required>
                </div>
                <div style="flex:1; min-width:160px;">
                    <label style="font-weight:700; margin-bottom:8px; display:block; font-size:12px; color:#495057;">End Date</label>
                    <input type="date" name="end_date" class="asb-input" value="<?= htmlspecialchars($end_date); ?>" required>
                </div>
                <div style="flex:2; min-width:230px;">
                    <label style="font-weight:700; margin-bottom:8px; display:block; font-size:12px; color:#495057;">Supplier Filter</label>
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
                    <label style="font-weight:700; margin-bottom:8px; display:block; font-size:12px; color:#495057;">Floor / Section</label>
                    <select name="floor_id" class="asb-input">
                        <option value="0">-- All Floors --</option>
                        <?php foreach ($floors_list as $fl): ?>
                            <option value="<?= $fl['floor_id']; ?>" <?= $floor_filter == $fl['floor_id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($fl['floor_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn-asb-action" style="padding: 11px 24px;">🔍 Filter Records</button>
                    <button type="button" onclick="window.print();" class="btn-asb-action" style="background:#212529; margin-left:8px; padding: 11px 22px;">🖨️ Print Landscape Report</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== SUMMARY METRICS CARDS ===== -->
    <div class="summary-grid">
        <div class="summary-box">
            <div class="title">Total GRN Batches Logged</div>
            <div class="val"><?= number_format($total_records); ?></div>
        </div>
        <div class="summary-box">
            <div class="title">Total Units Received</div>
            <div class="val"><?= number_format($total_qty_received); ?></div>
        </div>
        <div class="summary-box">
            <div class="title">Total Cartons / Boxes</div>
            <div class="val"><?= number_format($total_boxes_sum); ?> Ctn</div>
        </div>
        <div class="summary-box">
            <div class="title">Total Net Financial Amount</div>
            <div class="val" style="color:#2e7d32;">Rs. <?= number_format($grand_net_amount, 2); ?></div>
        </div>
    </div>

    <!-- ===== REPORT TABLE CONTAINER ===== -->
    <div class="asb-card">
        <div class="asb-card-header no-print" style="display:flex; justify-content:space-between; align-items:center;">
            <span>📋 Stock Inward Ledger Statement (From <?= $start_date; ?> To <?= $end_date; ?>)</span>
            <span style="font-size:12px; color:#6c757d; font-weight:normal;">Showing page <?= $current_page; ?> of <?= max(1, $total_pages); ?> (<?= number_format($total_records); ?> GRNs)</span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($report_items)): ?>
                <div style="text-align:center; padding:60px; color:#adb5bd; font-size:14px;">No stock receiving records found matching your active filter criteria.</div>
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
                                <th style="text-align:right;">Net Amount</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_items as $row): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#b71c1c;"><?= htmlspecialchars($row['grn_number']); ?></strong><br>
                                        <span style="font-size:10px; color:#6c757d;"><?= date('Y-m-d H:i', strtotime($row['received_date'])); ?></span>
                                    </td>
                                    <td>
                                        <a href="po_audit_report.php?po_id=<?= (int)$row['po_id']; ?>" class="po-link" target="_blank" title="View Full PO Audit Statement">
                                            <?= htmlspecialchars($row['po_number']); ?> 🔗
                                        </a><br>
                                        <span style="font-size:10px; color:#6c757d;"><?= date('Y-m-d', strtotime($row['purchase_date'])); ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown'); ?></td>
                                    <td><strong style="color:#495057;"><?= htmlspecialchars($row['attention'] ?? '—'); ?></strong></td>
                                    <td><span style="background:#ffebee; color:#b71c1c; padding:2px 6px; border-radius:3px; font-size:10.5px; font-weight:700;"><?= htmlspecialchars($row['floor_name'] ?? '—'); ?></span></td>
                                    <td style="text-align:right; font-weight:600;"><?= number_format($row['total_box_count']); ?> Ctn</td>
                                    <td style="text-align:right; font-weight:700; color:#333;"><?= number_format($row['po_total_ordered_qty']); ?></td>
                                    <td style="text-align:right; font-weight:700; color:#b71c1c;"><?= number_format($row['total_received_qty']); ?></td>
                                    <td style="text-align:right; font-weight:800; color:#2e7d32;">Rs. <?= number_format($row['grn_net_amount'], 2); ?></td>
                                    <td style="color:#555; font-style:italic; max-width:150px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($row['remarks'] ?? ''); ?>">
                                        <?= htmlspecialchars($row['remarks'] ?? '—'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Links (Screen Only) -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination-container no-print" style="padding:16px 24px; background:#f8f9fa; border-top:1px solid #e5e7eb; display:flex; justify-content:center; align-items:center; gap:6px; flex-wrap:wrap;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || abs($i - $current_page) <= 2): ?>
                                <a href="?page_num=<?= $i ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&supplier_id=<?= $supplier_id ?>&floor_id=<?= $floor_filter ?>" class="btn-asb-action" style="background:<?= $i==$current_page?'#b71c1c':'#e9ecef'; ?>; color:<?= $i==$current_page?'#fff':'#495057'; ?>; padding:6px 12px; font-size:12px; box-shadow:none;"><?= $i ?></a>
                            <?php elseif (abs($i - $current_page) == 3): ?>
                                <span style="padding:0 6px; color:#adb5bd;">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- =========================================================
         PRINT-ONLY LANDSCAPE SIGNATURE & FOOTER BLOCKS
         ========================================================= -->
    <div class="print-signature-section">
        <div>
            <div class="print-sig-gap"></div>
            <div class="print-sig-box">Warehouse Auditor Verification</div>
        </div>
        <div>
            <div class="print-sig-gap"></div>
            <div class="print-sig-box">Operations Manager Sign-off</div>
        </div>
        <div>
            <div class="print-sig-gap"></div>
            <div class="print-sig-box">Finance &amp; Audit Approval</div>
        </div>
    </div>

    <div class="print-doc-footer">
        © <?= date('Y'); ?> ASB Fashion Central Inventory Ledger Matrix System (Landscape Format) • Designed &amp; Developed by Vexel IT by Kavizz
    </div>

    <!-- Screen Footer -->
    <div class="asb-footer no-print" style="text-align: center; margin-top: 50px; padding: 25px; color: #6c757d; border-top: 1px solid #e5e7eb; font-size: 13px;">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:6px; display:inline-block; color:#adb5bd;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>