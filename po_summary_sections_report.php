<?php
/**
 * ASB Fashion - PO Summary Sections Report
 * Department & Sub-Department Order Status Pivot Matrix Report
 * Matches official A4 Portrait Sectioned Format with Corporate Header & Footer
 * Designed & Developed by Vexel IT by Kavizz
 */

if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

startSession();
requireLogin();

date_default_timezone_set('Asia/Colombo');
$server_printed_time = date('d/m/Y h:i:s A');

$conn = getConnection();

// =====================================================
// FILTER VALUES
// =====================================================
$from_date     = isset($_GET['from_date']) && !empty($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date       = isset($_GET['to_date']) && !empty($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$status_filter = isset($_GET['status']) && !empty($_GET['status']) ? trim($_GET['status']) : 'all';
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

// Fetch departments for filter dropdown
$dept_list_res = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");
$departments_list = $dept_list_res ? $dept_list_res->fetch_all(MYSQLI_ASSOC) : [];

// Selected department name for headers/exports
$selected_dept_name = 'All Departments';
if ($department_id > 0) {
    foreach ($departments_list as $d) {
        if ((int)$d['department_id'] === $department_id) {
            $selected_dept_name = $d['department_name'];
            break;
        }
    }
}

// Status Display Label
$status_labels = [
    'all'                 => 'All (Excluding Cancelled)',
    'Pending'             => 'Pending Only',
    'Received'            => 'Received Only',
    'Completed'           => 'Completed Only',
    'Cancelled'           => 'Cancelled Only',
    'all_with_cancelled'  => 'All (Including Cancelled)'
];
$status_label = $status_labels[$status_filter] ?? 'All (Excluding Cancelled)';

// Determine Active Pivot Status Columns based on User Selection
// When "all" is selected, cancelled is excluded by requirement ("when all given duindt consider canecled recordes")
$status_columns = [];
if ($status_filter === 'all' || empty($status_filter)) {
    $status_columns = [
        'qty_completed' => 'Completed',
        'qty_pending'   => 'Pending',
        'qty_received'  => 'Received'
    ];
} elseif ($status_filter === 'all_with_cancelled') {
    $status_columns = [
        'qty_completed' => 'Completed',
        'qty_pending'   => 'Pending',
        'qty_received'  => 'Received',
        'qty_cancelled' => 'Cancelled'
    ];
} elseif ($status_filter === 'Pending') {
    $status_columns = ['qty_pending' => 'Pending'];
} elseif ($status_filter === 'Received') {
    $status_columns = ['qty_received' => 'Received'];
} elseif ($status_filter === 'Completed') {
    $status_columns = ['qty_completed' => 'Completed'];
} elseif ($status_filter === 'Cancelled') {
    $status_columns = ['qty_cancelled' => 'Cancelled'];
} else {
    $status_columns = [
        'qty_completed' => 'Completed',
        'qty_pending'   => 'Pending',
        'qty_received'  => 'Received'
    ];
}

// =====================================================
// FUNCTION - GET PIVOT REPORT DATA
// =====================================================
function getSectionsPivotData($conn, $from_date, $to_date, $status_filter = 'all', $dept_id = 0) {
    $where_clauses = [
        "ph.purchase_date >= ?",
        "ph.purchase_date <= ?"
    ];
    $types = "ss";
    $params = [$from_date, $to_date];

    // Status Filter Logic
    if ($status_filter === 'all' || empty($status_filter)) {
        // Exclude Cancelled when "all" is given as explicitly required
        $where_clauses[] = "LOWER(TRIM(ph.status)) != 'cancelled'";
    } elseif ($status_filter === 'Pending') {
        $where_clauses[] = "LOWER(TRIM(ph.status)) = 'pending'";
    } elseif ($status_filter === 'Received') {
        $where_clauses[] = "LOWER(TRIM(ph.status)) = 'received'";
    } elseif ($status_filter === 'Completed') {
        $where_clauses[] = "LOWER(TRIM(ph.status)) = 'completed'";
    } elseif ($status_filter === 'Cancelled') {
        $where_clauses[] = "LOWER(TRIM(ph.status)) = 'cancelled'";
    } elseif ($status_filter === 'all_with_cancelled') {
        // Includes all statuses without exclusion
    }

    if ($dept_id > 0) {
        $where_clauses[] = "(i.department_id = ? OR d.department_id = ?)";
        $types .= "ii";
        $params[] = $dept_id;
        $params[] = $dept_id;
    }

    $where_sql = implode(" AND ", $where_clauses);

    $sql = "
        SELECT
            COALESCE(d.department_name, 'UNASSIGNED') AS department_name,
            COALESCE(sd.sub_department_name, 'UNASSIGNED') AS sub_department_name,
            SUM(CASE WHEN LOWER(TRIM(ph.status)) = 'completed' THEN pi.quantity ELSE 0 END) AS qty_completed,
            SUM(CASE WHEN LOWER(TRIM(ph.status)) = 'pending' THEN pi.quantity ELSE 0 END) AS qty_pending,
            SUM(CASE WHEN LOWER(TRIM(ph.status)) = 'received' THEN pi.quantity ELSE 0 END) AS qty_received,
            SUM(CASE WHEN LOWER(TRIM(ph.status)) = 'cancelled' THEN pi.quantity ELSE 0 END) AS qty_cancelled,
            SUM(pi.quantity) AS grand_total_qty
        FROM po_header ph
        INNER JOIN po_items pi ON ph.po_id = pi.po_id
        INNER JOIN items i ON pi.item_id = i.item_id
        LEFT JOIN departments d ON i.department_id = d.department_id
        LEFT JOIN sub_departments sd ON i.sub_department_id = sd.sub_department_id
        WHERE $where_sql
        GROUP BY d.department_name, sd.sub_department_name
        ORDER BY d.department_name ASC, sd.sub_department_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("<div style='font-family:sans-serif; padding:20px; border:1px solid #cc0000; background:#fff5f5; border-radius:6px; margin:20px;'>
                <h3 style='color:#cc0000; margin-top:0;'>Database Query Preparation Error</h3>
                <p><strong>SQL Error:</strong> " . htmlspecialchars($conn->error) . "</p>
             </div>");
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// =====================================================
// EXCEL EXPORT (DOWNLOAD BEFORE ANY HTML OUTPUT)
// =====================================================
if ($from_date && $to_date && isset($_GET['export']) && $_GET['export'] === 'excel') {
    $excel_result = getSectionsPivotData($conn, $from_date, $to_date, $status_filter, $department_id);

    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=ASB_PO_Summary_Report_" . date('Ymd_His') . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");

    $excel_data = [];
    while ($r = $excel_result->fetch_assoc()) {
        $excel_data[] = $r;
    }

    $num_status_cols = count($status_columns);
    $total_excel_cols = 2 + $num_status_cols + 1;

    echo "<html xmlns:x='urn:schemas-microsoft-com:office:excel'>";
    echo "<head><meta charset='utf-8'></head><body>";
    echo "<table border='1' cellpadding='4' cellspacing='0' style='font-family:Arial, sans-serif; font-size:11px;'>";
    echo "<tr><th colspan='{$total_excel_cols}' style='background:#b71c1c; color:#ffffff; font-size:15px; font-weight:bold; text-align:center; height:36px;'>ASB FASHION - PO SUMMARY REPORT</th></tr>";
    echo "<tr><th colspan='2' style='background:#f1f5f9; text-align:left;'>From Date</th><td colspan='" . ($total_excel_cols - 2) . "'>" . date('d/m/Y', strtotime($from_date)) . "</td></tr>";
    echo "<tr><th colspan='2' style='background:#f1f5f9; text-align:left;'>To Date</th><td colspan='" . ($total_excel_cols - 2) . "'>" . date('d/m/Y', strtotime($to_date)) . "</td></tr>";
    echo "<tr><th colspan='2' style='background:#f1f5f9; text-align:left;'>Status Filter</th><td colspan='" . ($total_excel_cols - 2) . "'>" . htmlspecialchars($status_label) . "</td></tr>";
    if ($department_id > 0) {
        echo "<tr><th colspan='2' style='background:#f1f5f9; text-align:left;'>Department</th><td colspan='" . ($total_excel_cols - 2) . "'>" . htmlspecialchars($selected_dept_name) . "</td></tr>";
    }
    echo "<tr><th colspan='2' style='background:#f1f5f9; text-align:left;'>Printed / Server Time</th><td colspan='" . ($total_excel_cols - 2) . "'>" . $server_printed_time . " (Asia/Colombo)</td></tr>";
    echo "<tr><th colspan='2' style='background:#f1f5f9; text-align:left;'>Printed By</th><td colspan='" . ($total_excel_cols - 2) . "'>" . htmlspecialchars($_SESSION['username'] ?? 'User') . "</td></tr>";

    // Two-row header matching screenshot
    echo "<tr style='background:#cbd5e1; font-weight:bold;'>
            <th colspan='2' align='left'>Sum of Qty</th>
            <th colspan='{$num_status_cols}' align='left'>Status</th>
            <th align='right'>Grand Total</th>
          </tr>";

    echo "<tr style='background:#cbd5e1; font-weight:bold;'>
            <th align='left'>Department</th>
            <th align='left'>Sub Department</th>";
    foreach ($status_columns as $col_key => $col_label) {
        echo "<th align='right'>" . htmlspecialchars($col_label) . "</th>";
    }
    echo "<th align='right'>Grand Total</th></tr>";

    $curr_dept = '';
    $dept_col_totals = array_fill_keys(array_keys($status_columns), 0);
    $dept_total_qty = 0;

    $grand_col_totals = array_fill_keys(array_keys($status_columns), 0);
    $grand_total_qty = 0;

    foreach ($excel_data as $row) {
        $dept = $row['department_name'];
        if ($curr_dept !== '' && $curr_dept !== $dept) {
            echo "<tr style='font-weight:bold; background:#f8fafc;'>
                    <td colspan='2'>{$curr_dept} Total</td>";
            foreach ($status_columns as $col_key => $col_label) {
                $val = $dept_col_totals[$col_key];
                echo "<td align='right'>" . ($val > 0 ? number_format($val) : "") . "</td>";
            }
            echo "<td align='right'>" . number_format($dept_total_qty, 2) . "</td></tr>";

            $dept_col_totals = array_fill_keys(array_keys($status_columns), 0);
            $dept_total_qty = 0;
        }

        $is_first = ($curr_dept !== $dept);
        $curr_dept = $dept;

        $row_tot = (float)$row['grand_total_qty'];
        $dept_total_qty += $row_tot;
        $grand_total_qty += $row_tot;

        echo "<tr>";
        echo "<td>" . ($is_first ? htmlspecialchars($row['department_name']) : "") . "</td>";
        echo "<td>" . htmlspecialchars($row['sub_department_name']) . "</td>";
        foreach ($status_columns as $col_key => $col_label) {
            $val = (float)$row[$col_key];
            $dept_col_totals[$col_key] += $val;
            $grand_col_totals[$col_key] += $val;
            echo "<td align='right'>" . ($val > 0 ? number_format($val) : "") . "</td>";
        }
        echo "<td align='right'>" . number_format($row_tot, 2) . "</td>";
        echo "</tr>";
    }

    if ($curr_dept !== '') {
        echo "<tr style='font-weight:bold; background:#f8fafc;'>
                <td colspan='2'>{$curr_dept} Total</td>";
        foreach ($status_columns as $col_key => $col_label) {
            $val = $dept_col_totals[$col_key];
            echo "<td align='right'>" . ($val > 0 ? number_format($val) : "") . "</td>";
        }
        echo "<td align='right'>" . number_format($dept_total_qty, 2) . "</td></tr>";
    }

    echo "<tr style='font-weight:bold; background:#e2e8f0;'>
            <td colspan='2'>Grand Total</td>";
    foreach ($status_columns as $col_key => $col_label) {
        $val = $grand_col_totals[$col_key];
        echo "<td align='right'>" . ($val > 0 ? number_format($val) : "") . "</td>";
    }
    echo "<td align='right'>" . number_format($grand_total_qty, 2) . "</td></tr>";

    echo "<tr><td colspan='{$total_excel_cols}' style='text-align:center; padding:10px; font-size:10px; color:#555;'>Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></td></tr>";
    echo "</table></body></html>";
    exit;
}

// Fetch Data for Screen & Print
$result = getSectionsPivotData($conn, $from_date, $to_date, $status_filter, $department_id);

$dept_groups = [];
$total_subdepts_count = 0;

$grand_col_totals = array_fill_keys(array_keys($status_columns), 0);
$grand_total_qty = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $dept_name = $row['department_name'];
        if (!isset($dept_groups[$dept_name])) {
            $dept_groups[$dept_name] = [
                'department_name' => $dept_name,
                'items'           => [],
                'col_totals'      => array_fill_keys(array_keys($status_columns), 0),
                'total_qty'       => 0
            ];
        }

        $row_tot = (float)$row['grand_total_qty'];
        $dept_groups[$dept_name]['items'][] = $row;
        $dept_groups[$dept_name]['total_qty'] += $row_tot;
        $grand_total_qty += $row_tot;

        foreach ($status_columns as $col_key => $col_label) {
            $val = (float)$row[$col_key];
            $dept_groups[$dept_name]['col_totals'][$col_key] += $val;
            $grand_col_totals[$col_key] += $val;
        }

        $total_subdepts_count++;
    }
}

$total_depts_count = count($dept_groups);

$page_title = 'ASB Fashion | PO Summary Report';
$page = 'po_summary_sections';

// Standard System Header & Navigation
include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    /* =========================================================
       ASB RED & WHITE CONSISTENT THEME (SCREEN STYLES)
       ========================================================= */
    body {
        background-color: #f8fafc;
        font-family: Arial, Helvetica, 'Segoe UI', sans-serif;
        color: #1e293b;
    }

    .asb-header-title {
        color: #b71c1c;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-left: 5px solid #d32f2f;
        padding-left: 15px;
        margin: 0;
        font-size: 22px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .asb-header-title span {
        font-weight: 400;
        color: #64748b;
        font-size: 15px;
        text-transform: none;
        letter-spacing: 0;
    }

    .asb-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        margin-bottom: 24px;
        overflow: hidden;
    }

    /* Screen Action Buttons */
    .btn-asb {
        background: linear-gradient(135deg, #b71c1c 0%, #7f1d1d 100%);
        color: #ffffff;
        border: none;
        font-weight: 700;
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 12.5px;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        box-shadow: 0 2px 6px rgba(183, 28, 28, 0.2);
    }
    .btn-asb:hover {
        background: linear-gradient(135deg, #991b1b 0%, #6b1515 100%);
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(183, 28, 28, 0.3);
    }
    .btn-asb-secondary {
        background: #ffffff;
        color: #334155;
        border: 1px solid #cbd5e1;
        font-weight: 700;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 12.5px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-asb-secondary:hover {
        background: #f1f5f9;
        color: #0f172a;
        border-color: #94a3b8;
        transform: translateY(-1px);
    }
    .btn-asb-print {
        background: #1e293b;
        color: #ffffff;
        border: none;
        font-weight: 700;
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 12.5px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-asb-print:hover {
        background: #0f172a;
        color: #ffffff;
        transform: translateY(-1px);
    }
    .btn-asb-excel {
        background: #15803d;
        color: #ffffff;
        border: none;
        font-weight: 700;
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 12.5px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-asb-excel:hover {
        background: #166534;
        color: #ffffff;
        transform: translateY(-1px);
    }

    /* Filter Controls */
    .asb-input {
        border: 1px solid #cbd5e1;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 500;
        color: #1e293b;
        outline: none;
        background: #ffffff;
        transition: all 0.2s ease;
        box-sizing: border-box;
        width: 100%;
    }
    .asb-input:focus {
        border-color: #b71c1c;
        box-shadow: 0 0 0 3px rgba(183, 28, 28, 0.12);
    }

    /* Status Quick Pills */
    .status-pill-btn {
        display: inline-flex;
        align-items: center;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11.5px;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        transition: all 0.15s ease;
    }
    .status-pill-btn:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    .status-pill-btn.active {
        background: #b71c1c;
        color: #ffffff;
        border-color: #b71c1c;
    }

    /* =========================================================
       REPORT SHEET CONTAINER (SCREEN + PRINT)
       ========================================================= */
    .report-sheet-container {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 25px 30px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.04);
    }

    /* =========================================================
       ELEGANT CORPORATE PRINT HEADER
       ========================================================= */
    .print-document-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2.5px solid #b71c1c;
        padding-bottom: 12px;
        margin-bottom: 18px;
    }
    .print-brand-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .print-logo-badge {
        background: #b71c1c;
        color: #ffffff;
        font-weight: 900;
        font-size: 22px;
        width: 48px;
        height: 48px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        letter-spacing: 0.5px;
        box-shadow: 0 2px 6px rgba(183, 28, 28, 0.25);
    }
    .print-brand-title {
        font-size: 18px;
        font-weight: 900;
        color: #b71c1c;
        margin: 0;
        line-height: 1.1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .print-brand-sub {
        font-size: 11px;
        font-weight: 600;
        color: #475569;
        margin: 2px 0 0 0;
    }
    .print-doc-title {
        font-size: 11.5px;
        font-weight: 800;
        color: #0f172a;
        margin: 4px 0 0 0;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .print-meta-right {
        text-align: right;
        font-size: 10.5px;
        color: #334155;
        line-height: 1.5;
        border-left: 2px solid #e2e8f0;
        padding-left: 15px;
    }
    .print-meta-right .lbl {
        color: #64748b;
        font-weight: 600;
        margin-right: 4px;
    }
    .print-meta-right .val {
        color: #0f172a;
        font-weight: 700;
    }
    .print-meta-right .time-highlight {
        color: #b71c1c;
        font-weight: 800;
    }

    /* Top Left Metadata Box (Matches PDF) */
    .report-meta-box {
        border: 1.5px solid #000000;
        border-collapse: collapse;
        width: 340px;
        margin-bottom: 20px;
        font-size: 10.5px;
        background: #ffffff;
    }
    .report-meta-box th, .report-meta-box td {
        border: 1px solid #000000;
        padding: 3.5px 8px;
    }
    .report-meta-box th.box-header {
        text-align: center;
        font-weight: bold;
        font-size: 11.5px;
        padding: 5px;
        background: #ffffff;
        letter-spacing: 0.5px;
    }
    .report-meta-box th.label-col {
        width: 44%;
        text-align: left;
        font-weight: bold;
        background: #f8fafc;
    }
    .report-meta-box td.val-col {
        text-align: left;
        background: #ffffff;
        font-weight: 600;
    }

    /* Matrix Table */
    .matrix-table {
        width: 100%;
        border-collapse: collapse;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10px;
        color: #000000;
    }

    /* Table Headers */
    .matrix-table thead tr {
        background-color: #cbd5e1;
        color: #000000;
        font-weight: bold;
    }
    .matrix-table thead th {
        padding: 5px 6px;
        border: 1px solid #cbd5e1;
        font-size: 10.5px;
    }
    .matrix-table thead tr.header-row-1 th {
        border-bottom: 1px solid #94a3b8;
    }

    /* Data Rows */
    .matrix-table tbody tr td {
        padding: 2.5px 6px;
        vertical-align: middle;
        font-size: 9.5px;
        border: none;
    }
    .matrix-table tbody tr.data-row:hover td {
        background-color: #f1f5f9;
    }
    .matrix-table tbody tr.dept-total-row td {
        font-weight: bold;
        border-top: 1.5px solid #000000 !important;
        border-bottom: 1.5px solid #000000 !important;
        padding: 4px 6px;
        background-color: #ffffff;
    }

    /* Grand Total Row */
    .matrix-table tbody tr.grand-total-row td {
        font-weight: bold;
        border-top: 2px solid #000000 !important;
        border-bottom: 2.5px solid #000000 !important;
        padding: 5px 6px;
        font-size: 10px;
        background-color: #ffffff;
    }

    .align-left { text-align: left; }
    .align-right { text-align: right; }
    .align-center { text-align: center; }

    /* =========================================================
       PRINT SIGNATURE & VERIFICATION SECTION
       ========================================================= */
    .print-signature-section {
        display: none;
        grid-template-columns: repeat(3, 1fr);
        gap: 30px;
        margin-top: 35px;
        text-align: center;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .print-signature-section .sig-col {
        display: flex;
        flex-direction: column;
    }
    .print-signature-section .sig-space {
        height: 38px;
    }
    .print-signature-section .sig-line {
        border-top: 1px solid #000000;
        padding-top: 6px;
        font-size: 9pt;
        font-weight: bold;
        color: #000000;
    }
    .print-signature-section .sig-sub {
        font-size: 7.5pt;
        color: #555555;
        margin-top: 2px;
    }

    /* =========================================================
       CORPORATE PRINT FOOTER
       ========================================================= */
    .print-document-footer {
        display: none;
        border-top: 2px solid #b71c1c;
        padding-top: 8px;
        margin-top: 25px;
        font-size: 8pt;
        color: #475569;
        justify-content: space-between;
        align-items: center;
        page-break-inside: avoid;
        break-inside: avoid;
        font-family: Arial, Helvetica, sans-serif;
    }
    .print-document-footer .footer-left strong {
        color: #0f172a;
    }
    .print-document-footer .footer-center {
        font-weight: bold;
        color: #b71c1c;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 7.5pt;
    }
    .print-document-footer .footer-right strong {
        color: #b71c1c;
        font-weight: 800;
    }

    /* =========================================================
       PRINT STYLES (A4 PORTRAIT EXACT MATCH TO PDF)
       ========================================================= */
    @media print {
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 12mm 10mm;
        }

        body {
            background: #ffffff !important;
            color: #000000 !important;
            padding: 0 !important;
            margin: 0 !important;
            font-family: Arial, Helvetica, sans-serif !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .no-print,
        .top-nav,
        .page-header,
        .asb-footer {
            display: none !important;
        }

        .main-content {
            padding: 0 !important;
            width: 100% !important;
        }

        .report-sheet-container {
            border: none !important;
            padding: 0 !important;
            box-shadow: none !important;
            margin: 0 !important;
        }

        .print-document-header {
            display: flex !important;
            border-bottom: 2px solid #b71c1c !important;
            padding-bottom: 8px !important;
            margin-bottom: 12px !important;
        }

        .print-logo-badge {
            background: #b71c1c !important;
            color: #ffffff !important;
            width: 42px !important;
            height: 42px !important;
            font-size: 19px !important;
        }

        .print-brand-title {
            font-size: 16px !important;
            color: #b71c1c !important;
        }
        .print-brand-sub {
            font-size: 9.5pt !important;
        }
        .print-doc-title {
            font-size: 10pt !important;
        }

        .print-meta-right {
            font-size: 8pt !important;
            border-left: 2px solid #cbd5e1 !important;
            padding-left: 10px !important;
            line-height: 1.4 !important;
        }

        .report-meta-box {
            border: 1.5px solid #000000 !important;
            margin-bottom: 14px !important;
            font-size: 8.5pt !important;
            width: 320px !important;
        }
        .report-meta-box th, .report-meta-box td {
            border: 1px solid #000000 !important;
            padding: 2.5px 6px !important;
        }

        .matrix-table {
            font-size: 8.5pt !important;
            width: 100% !important;
        }
        .matrix-table thead {
            display: table-header-group !important;
        }
        .matrix-table thead tr {
            background-color: #cbd5e1 !important;
            color: #000000 !important;
        }
        .matrix-table thead th {
            padding: 3.5px 5px !important;
            font-size: 8.5pt !important;
            border: 1px solid #cbd5e1 !important;
        }
        .matrix-table tbody tr {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .matrix-table tbody tr td {
            padding: 2px 5px !important;
            font-size: 8pt !important;
        }
        .matrix-table tbody tr.dept-total-row td {
            border-top: 1.5px solid #000000 !important;
            border-bottom: 1.5px solid #000000 !important;
            padding: 3px 5px !important;
            font-size: 8.5pt !important;
        }
        .matrix-table tbody tr.grand-total-row td {
            border-top: 2px solid #000000 !important;
            border-bottom: 2.5px solid #000000 !important;
            padding: 3.5px 5px !important;
            font-size: 8.5pt !important;
        }

        .print-signature-section {
            display: grid !important;
        }

        .print-document-footer {
            display: flex !important;
        }
    }
</style>

<div class="container-fluid" style="padding: 10px 15px 40px 15px; max-width: 1600px; margin: 0 auto;">

    <!-- =========================================================
         SCREEN PAGE HEADER & ACTION TOOLBAR (WITH BACK TO DASHBOARD)
         ========================================================= -->
    <div class="page-header no-print" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
        <div>
            <h2 class="asb-header-title">
                <i class="fas fa-chart-pie"></i> ASB FASHION 
                <span>| PO Summary Report</span>
            </h2>
            <p style="font-size: 12px; color: #64748b; margin: 4px 0 0 20px;">
                Department &amp; Sub-Department Pivot Matrix Breakdown across Purchase Order Statuses.
            </p>
        </div>

        <div class="action-buttons" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <!-- BACK TO DASHBOARD BUTTON -->
            <a href="dashboard.php" class="btn-asb-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>

            <?php if ($total_subdepts_count > 0): ?>
                <!-- PRINT A4 BUTTON (EXACT PDF MATCH) -->
                <button type="button" onclick="prepareAndPrint()" class="btn-asb-print">
                    <i class="fas fa-print"></i> Print A4 Report
                </button>

                <!-- EXCEL EXPORT BUTTON -->
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=<?= urlencode($status_filter) ?>&department_id=<?= (int)$department_id ?>&export=excel" class="btn-asb-excel">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- =========================================================
         FILTER CARD (SCREEN ONLY)
         Allows selecting Date Range, Status (Pending, Received, Completed, Cancelled, All), and Department
         ========================================================= -->
    <div class="asb-card no-print" style="margin-bottom: 24px;">
        <div style="padding: 18px 22px; background: #ffffff;">
            <form method="GET" id="reportFilterForm" style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                
                <!-- From Date -->
                <div style="flex: 1 1 150px; min-width: 140px;">
                    <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                        <i class="fas fa-calendar-alt text-red-700"></i> From Date *
                    </label>
                    <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>" required class="asb-input">
                </div>

                <!-- To Date -->
                <div style="flex: 1 1 150px; min-width: 140px;">
                    <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                        <i class="fas fa-calendar-check text-red-700"></i> To Date *
                    </label>
                    <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>" required class="asb-input">
                </div>

                <!-- Status Filter (Pending, Received, Completed, Cancelled, All) -->
                <div style="flex: 2 1 240px; min-width: 220px;">
                    <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                        <i class="fas fa-tasks text-red-700"></i> Status Filter *
                    </label>
                    <select name="status" class="asb-input" onchange="document.getElementById('reportFilterForm').submit();">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses (Excluding Cancelled)</option>
                        <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending Only</option>
                        <option value="Received" <?= $status_filter === 'Received' ? 'selected' : '' ?>>Received Only</option>
                        <option value="Completed" <?= $status_filter === 'Completed' ? 'selected' : '' ?>>Completed Only</option>
                        <option value="Cancelled" <?= $status_filter === 'Cancelled' ? 'selected' : '' ?>>Cancelled Only</option>
                        <option value="all_with_cancelled" <?= $status_filter === 'all_with_cancelled' ? 'selected' : '' ?>>All (Including Cancelled)</option>
                    </select>
                </div>

                <!-- Department Filter (Optional) -->
                <div style="flex: 2 1 200px; min-width: 180px;">
                    <label style="display: block; font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 6px;">
                        <i class="fas fa-layer-group text-red-700"></i> Department Filter
                    </label>
                    <select name="department_id" class="asb-input">
                        <option value="0">-- All Departments --</option>
                        <?php foreach ($departments_list as $d): ?>
                            <option value="<?= (int)$d['department_id'] ?>" <?= $department_id === (int)$d['department_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn-asb" style="height: 38px;">
                        <i class="fas fa-filter"></i> Generate Report
                    </button>
                    <a href="po_summary_sections_report.php" class="btn-asb-secondary" style="height: 38px;" title="Reset Filters">
                        <i class="fas fa-sync-alt"></i>
                    </a>
                </div>
            </form>

            <!-- Quick Status Filter Pills for Instant Selection -->
            <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span style="font-size: 11px; font-weight: 700; color: #64748b; margin-right: 4px;">Quick Status Filter:</span>
                
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=all&department_id=<?= (int)$department_id ?>" 
                   class="status-pill-btn <?= $status_filter === 'all' ? 'active' : '' ?>">
                    All (Excl. Cancelled)
                </a>
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=Pending&department_id=<?= (int)$department_id ?>" 
                   class="status-pill-btn <?= $status_filter === 'Pending' ? 'active' : '' ?>">
                    Pending
                </a>
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=Received&department_id=<?= (int)$department_id ?>" 
                   class="status-pill-btn <?= $status_filter === 'Received' ? 'active' : '' ?>">
                    Received
                </a>
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=Completed&department_id=<?= (int)$department_id ?>" 
                   class="status-pill-btn <?= $status_filter === 'Completed' ? 'active' : '' ?>">
                    Completed
                </a>
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=Cancelled&department_id=<?= (int)$department_id ?>" 
                   class="status-pill-btn <?= $status_filter === 'Cancelled' ? 'active' : '' ?>">
                    Cancelled
                </a>
                <a href="?from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>&status=all_with_cancelled&department_id=<?= (int)$department_id ?>" 
                   class="status-pill-btn <?= $status_filter === 'all_with_cancelled' ? 'active' : '' ?>">
                    All (Incl. Cancelled)
                </a>
            </div>
        </div>
    </div>

    <?php if ($total_subdepts_count > 0): ?>

        <!-- =========================================================
             A4 REPORT SHEET CONTAINER
             Matches the exact visual layout of the PDF screenshot
             With Corporate Header & Footer and Server Time
             ========================================================= -->
        <div class="report-sheet-container">
            
            <!-- =========================================================
                 ELEGANT PRINTABLE CORPORATE HEADER
                 ========================================================= -->
            <div class="print-document-header">
                <div class="print-brand-left">
                    <div class="print-logo-badge">ASB</div>
                    <div>
                        <h1 class="print-brand-title">ASB FASHION</h1>
                        <p class="print-brand-sub">ASB Group Of Companies • Purchase Order Management System</p>
                        <p class="print-doc-title">PO SUMMARY REPORT — DEPARTMENT &amp; SUB-DEPARTMENT MATRIX</p>
                    </div>
                </div>

                <div class="print-meta-right">
                    <div>
                        <span class="lbl">Report Period:</span>
                        <span class="val"><?= date('d/m/Y', strtotime($from_date)) ?> to <?= date('d/m/Y', strtotime($to_date)) ?></span>
                    </div>
                    <div>
                        <span class="lbl">Printed Date &amp; Time:</span>
                        <span class="val time-highlight print-time-slot"><?= $server_printed_time ?></span>
                    </div>
                    <div>
                        <span class="lbl">Generated By:</span>
                        <span class="val"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                    </div>
                    <div>
                        <span class="lbl">Status Scope:</span>
                        <span class="val"><?= htmlspecialchars($status_label) ?></span>
                    </div>
                    <?php if ($department_id > 0): ?>
                    <div>
                        <span class="lbl">Department:</span>
                        <span class="val"><?= htmlspecialchars($selected_dept_name) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top-Left Box: PO SUMMARY REPORT with From/To Date and Status (Matches PDF) -->
            <table class="report-meta-box">
                <tr>
                    <th colspan="2" class="box-header">PO SUMMARY REPORT</th>
                </tr>
                <tr>
                    <th class="label-col">From Date</th>
                    <td class="val-col"><?= date('d/m/Y', strtotime($from_date)) ?></td>
                </tr>
                <tr>
                    <th class="label-col">To Date</th>
                    <td class="val-col"><?= date('d/m/Y', strtotime($to_date)) ?></td>
                </tr>
                <?php if ($status_filter !== 'all'): ?>
                <tr>
                    <th class="label-col">Status</th>
                    <td class="val-col" style="font-weight: bold;"><?= htmlspecialchars($status_label) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($department_id > 0): ?>
                <tr>
                    <th class="label-col">Department</th>
                    <td class="val-col" style="font-weight: bold;"><?= htmlspecialchars($selected_dept_name) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="no-print">
                    <th class="label-col">Server Time</th>
                    <td class="val-col print-time-slot"><?= $server_printed_time ?></td>
                </tr>
            </table>

            <!-- Matrix Table -->
            <?php 
                $num_status_cols = count($status_columns);
            ?>
            <table class="matrix-table">
                <thead>
                    <!-- Header Row 1: Sum of Qty | Status | (empty) -->
                    <tr class="header-row-1">
                        <th colspan="2" class="align-left">Sum of Qty</th>
                        <th colspan="<?= $num_status_cols ?>" class="align-left">Status</th>
                        <th class="align-right"></th>
                    </tr>
                    <!-- Header Row 2: Department | Sub Department | [Status Columns] | Grand Total -->
                    <tr>
                        <th class="align-left" style="width: 14%;">Department</th>
                        <th class="align-left" style="width: 28%;">Sub Department</th>
                        <?php foreach ($status_columns as $col_key => $col_label): ?>
                            <th class="align-right" style="width: <?= round(44 / ($num_status_cols + 1)) ?>%;">
                                <?= htmlspecialchars($col_label) ?>
                            </th>
                        <?php endforeach; ?>
                        <th class="align-right" style="width: 14%;">Grand Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($dept_groups as $dept_name => $group): 
                        $items       = $group['items'];
                        $dept_totals = $group['col_totals'];
                        $dept_tot    = $group['total_qty'];
                        $is_first    = true;

                        foreach ($items as $item): 
                            $row_tot = (float)$item['grand_total_qty'];
                    ?>
                        <tr class="data-row">
                            <td class="align-left" style="font-weight: <?= $is_first ? 'bold' : 'normal' ?>;">
                                <?= $is_first ? htmlspecialchars($dept_name) : '' ?>
                            </td>
                            <td class="align-left" style="text-transform: uppercase;">
                                <?= htmlspecialchars($item['sub_department_name']) ?>
                            </td>
                            <?php foreach ($status_columns as $col_key => $col_label): 
                                $val = (float)$item[$col_key];
                            ?>
                                <td class="align-right">
                                    <?= $val > 0 ? number_format($val) : '' ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="align-right">
                                <?= number_format($row_tot, 2) ?>
                            </td>
                        </tr>
                    <?php 
                            $is_first = false;
                        endforeach; 
                    ?>
                        <!-- Department Total Row -->
                        <tr class="dept-total-row">
                            <td colspan="2" class="align-left">
                                <?= htmlspecialchars($dept_name) ?> Total
                            </td>
                            <?php foreach ($status_columns as $col_key => $col_label): 
                                $sum = $dept_totals[$col_key];
                            ?>
                                <td class="align-right">
                                    <?= $sum > 0 ? number_format($sum) : '' ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="align-right">
                                <?= number_format($dept_tot, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Overall Grand Total Row -->
                    <tr class="grand-total-row">
                        <td colspan="2" class="align-left">Grand Total</td>
                        <?php foreach ($status_columns as $col_key => $col_label): 
                            $g_sum = $grand_col_totals[$col_key];
                        ?>
                            <td class="align-right">
                                <?= $g_sum > 0 ? number_format($g_sum) : '' ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="align-right">
                            <?= number_format($grand_total_qty, 2) ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- =========================================================
                 OFFICIAL SIGN-OFF & VERIFICATION SECTION (PRINT ONLY)
                 ========================================================= -->
            <div class="print-signature-section">
                <div class="sig-col">
                    <div class="sig-space"></div>
                    <div class="sig-line">Prepared By (Procurement Officer)</div>
                    <div class="sig-sub">Signature &amp; Date</div>
                </div>
                <div class="sig-col">
                    <div class="sig-space"></div>
                    <div class="sig-line">Checked &amp; Audited By (Operations)</div>
                    <div class="sig-sub">Signature &amp; Date</div>
                </div>
                <div class="sig-col">
                    <div class="sig-space"></div>
                    <div class="sig-line">Authorized Approval (Senior Management)</div>
                    <div class="sig-sub">Official Seal &amp; Date</div>
                </div>
            </div>

            <!-- =========================================================
                 ELEGANT PRINTABLE CORPORATE FOOTER
                 ========================================================= -->
            <div class="print-document-footer">
                <div class="footer-left">
                    <span><strong>Printed on:</strong> <span class="print-time-slot"><?= $server_printed_time ?></span> (Server Time)</span>
                    <span style="margin: 0 6px;">|</span>
                    <span><strong>User:</strong> <?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                </div>
                <div class="footer-center">
                    <span>ASB Fashion Central Audit Matrix</span>
                </div>
                <div class="footer-right">
                    <span>Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
                </div>
            </div>

        </div><!-- Close report-sheet-container -->

    <?php else: ?>

        <!-- Empty State Alert -->
        <div class="asb-card" style="padding: 40px 20px; text-align: center;">
            <div style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px;">
                <i class="fas fa-folder-open"></i>
            </div>
            <h3 style="font-size: 16px; font-weight: 700; color: #475569; margin: 0 0 6px 0;">No Records Found</h3>
            <p style="font-size: 12.5px; color: #94a3b8; max-width: 480px; margin: 0 auto 18px auto;">
                No purchase orders were logged for the selected date range (<?= date('d/m/Y', strtotime($from_date)) ?> to <?= date('d/m/Y', strtotime($to_date)) ?>) under status filter: <strong><?= htmlspecialchars($status_label) ?></strong>.
            </p>
            <a href="dashboard.php" class="btn-asb-secondary">
                <i class="fas fa-arrow-left"></i> Return to Dashboard
            </a>
        </div>

    <?php endif; ?>

    <!-- Screen Footer -->
    <div class="asb-footer no-print" style="text-align: center; margin-top: 40px; padding: 20px; color: #64748b; border-top: 1px solid #e2e8f0; font-size: 12.5px;">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> PO Management System • All Rights Reserved.<br>
        <span style="font-size: 11px; margin-top: 4px; display: inline-block; color: #94a3b8;">
            System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong> • Server Time: <?= $server_printed_time ?>
        </span>
    </div>

</div><!-- Close container-fluid -->

</div><!-- Close main-content from includes/header.php -->
</div><!-- Close app-container from includes/header.php -->

<script>
function prepareAndPrint() {
    // Refresh printed timestamp to current time before printing
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
