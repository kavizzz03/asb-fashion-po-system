<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Allocate PO to Branches';
$page = 'po_ledger';

$conn = getConnection();      // po_system
$qcConn = getQcConnection();  // return_qc (for suppliers)

// ------------------------------------------------------------
// AJAX: get PO suggestions (for Select2)
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_po_suggestions') {
    header('Content-Type: application/json');
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sql = "SELECT po_id AS id, po_number AS text FROM po_header";
    if (!empty($search)) {
        $sql .= " WHERE po_number LIKE ?";
        $like = "%$search%";
        $stmt = $conn->prepare($sql . " ORDER BY po_number LIMIT 20");
        $stmt->bind_param("s", $like);
    } else {
        $stmt = $conn->prepare($sql . " ORDER BY po_number LIMIT 20");
    }
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['results' => $results, 'pagination' => ['more' => false]]);
    exit;
}

// ------------------------------------------------------------
// AJAX: get PO list with allocation status
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_po_list') {
    header('Content-Type: application/json');

    $search_po = isset($_GET['search_po']) ? trim($_GET['search_po']) : '';
    $search_supplier = isset($_GET['search_supplier']) ? trim($_GET['search_supplier']) : '';
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $alloc_status = isset($_GET['alloc_status']) ? $_GET['alloc_status'] : '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;

    $where = [];
    $params = [];
    $types = '';

    if (!empty($search_po)) {
        $where[] = "h.po_number LIKE ?";
        $params[] = "%$search_po%";
        $types .= 's';
    }
    if (!empty($search_supplier)) {
        $where[] = "s.supplier_name LIKE ?";
        $params[] = "%$search_supplier%";
        $types .= 's';
    }
    if (!empty($date_from)) {
        $where[] = "h.purchase_date >= ?";
        $params[] = $date_from;
        $types .= 's';
    }
    if (!empty($date_to)) {
        $where[] = "h.purchase_date <= ?";
        $params[] = $date_to;
        $types .= 's';
    }
    if (!empty($status)) {
        $where[] = "h.status = ?";
        $params[] = $status;
        $types .= 's';
    }

    // Base query with allocation summary
    $sql = "SELECT h.po_id, h.po_number, h.purchase_date, h.status, s.supplier_name,
                   (SELECT COUNT(*) FROM po_items WHERE po_id = h.po_id) AS total_items,
                   (SELECT COALESCE(SUM(quantity),0) FROM po_items WHERE po_id = h.po_id) AS total_qty,
                   (SELECT COALESCE(SUM(a.quantity),0) FROM po_item_allocations a JOIN po_items pi ON a.po_item_id = pi.po_item_id WHERE pi.po_id = h.po_id) AS allocated_qty
            FROM po_header h
            LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id";

    $where_clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
    $sql .= " $where_clause ORDER BY h.purchase_date DESC LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) AS total FROM po_header h LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id $where_clause";
    $count_stmt = $conn->prepare($count_sql);
    $where_params = array_slice($params, 0, -2);
    $where_types = substr($types, 0, -2);
    if (!empty($where_params)) {
        $count_stmt->bind_param($where_types, ...$where_params);
    }
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];

    // Add allocation status to each row
    foreach ($rows as &$row) {
        $row['allocated_qty'] = (int)$row['allocated_qty'];
        $row['total_qty'] = (int)$row['total_qty'];
        if ($row['total_qty'] == 0) {
            $row['alloc_status'] = 'No Items';
        } elseif ($row['allocated_qty'] >= $row['total_qty']) {
            $row['alloc_status'] = 'Fully Allocated';
        } elseif ($row['allocated_qty'] > 0) {
            $row['alloc_status'] = 'Partially Allocated';
        } else {
            $row['alloc_status'] = 'Not Allocated';
        }
    }

    // Apply allocation status filter after fetching (since it's computed)
    if (!empty($alloc_status)) {
        $rows = array_filter($rows, function($r) use ($alloc_status) {
            return $r['alloc_status'] == $alloc_status;
        });
        $rows = array_values($rows);
        $total = count($rows);
    }

    echo json_encode([
        'results' => $rows,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'more' => ($offset + $per_page) < $total
        ]
    ]);
    exit;
}

// ------------------------------------------------------------
// AJAX: get PO details for allocation
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_po_details') {
    header('Content-Type: application/json');
    $po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
    if (!$po_id) {
        echo json_encode(['error' => 'Invalid PO ID']);
        exit;
    }

    // Get PO header with supplier
    $sql = "SELECT h.*, s.supplier_name FROM po_header h
            LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id
            WHERE h.po_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $header = $stmt->get_result()->fetch_assoc();
    if (!$header) {
        echo json_encode(['error' => 'PO not found']);
        exit;
    }

    // Get items with current allocations
    $items_sql = "SELECT pi.po_item_id, pi.item_id, pi.quantity, pi.received_qty, pi.cost_price, pi.selling_price,
                         i.item_code, i.item_name, i.system_code,
                         (SELECT COALESCE(SUM(quantity),0) FROM po_item_allocations WHERE po_item_id = pi.po_item_id) AS allocated_qty
                  FROM po_items pi
                  JOIN items i ON pi.item_id = i.item_id
                  WHERE pi.po_id = ?
                  ORDER BY pi.po_item_id";
    $stmt = $conn->prepare($items_sql);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get all branches with company info
    $branch_sql = "SELECT l.location_id, l.location_name, c.company_id, c.company_name
                   FROM store_locations l
                   LEFT JOIN companies c ON l.company_id = c.company_id
                   ORDER BY c.company_name, l.location_name";
    $branches = $conn->query($branch_sql)->fetch_all(MYSQLI_ASSOC);

    // Get existing allocations for this PO
    $alloc_sql = "SELECT a.po_item_id, a.location_id, a.quantity
                  FROM po_item_allocations a
                  JOIN po_items pi ON a.po_item_id = pi.po_item_id
                  WHERE pi.po_id = ?";
    $stmt = $conn->prepare($alloc_sql);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $allocation_map = [];
    foreach ($existing as $row) {
        $allocation_map[$row['po_item_id']][$row['location_id']] = $row['quantity'];
    }

    echo json_encode([
        'header' => $header,
        'items' => $items,
        'branches' => $branches,
        'allocations' => $allocation_map
    ]);
    exit;
}

// ------------------------------------------------------------
// AJAX: save allocations
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_allocations') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // Verify manager password
    $password = isset($_POST['manager_password']) ? trim($_POST['manager_password']) : '';
    $pwd_stmt = $conn->prepare("SELECT password FROM manager_credentials LIMIT 1");
    $pwd_stmt->execute();
    $stored = $pwd_stmt->get_result()->fetch_assoc()['password'] ?? '';
    $pwd_stmt->close();
    if ($password !== $stored) {
        $response['message'] = 'Invalid manager password.';
        echo json_encode($response);
        exit;
    }

    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
    if (!$po_id) {
        $response['message'] = 'PO ID required.';
        echo json_encode($response);
        exit;
    }

    $allocations = isset($_POST['allocations']) ? json_decode($_POST['allocations'], true) : [];
    if (!is_array($allocations) || empty($allocations)) {
        $response['message'] = 'No allocations provided.';
        echo json_encode($response);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Delete existing allocations for this PO
        $del = $conn->prepare("DELETE FROM po_item_allocations WHERE po_item_id IN (SELECT po_item_id FROM po_items WHERE po_id = ?)");
        $del->bind_param("i", $po_id);
        $del->execute();
        $del->close();

        // Insert new allocations
        $insert = $conn->prepare("INSERT INTO po_item_allocations (po_item_id, location_id, quantity) VALUES (?, ?, ?)");
        foreach ($allocations as $po_item_id => $branches) {
            foreach ($branches as $location_id => $qty) {
                $qty = (int)$qty;
                if ($qty > 0) {
                    $insert->bind_param("iii", $po_item_id, $location_id, $qty);
                    $insert->execute();
                }
            }
        }
        $insert->close();

        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Allocations saved successfully.';
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// HTML
// ------------------------------------------------------------
include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
    /* General ASB styles */
    body { background-color: #fbfbfb; font-family: 'Segoe UI', Arial, sans-serif; color: #333; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; margin-bottom: 25px; }
    .asb-card { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 25px; }
    .asb-card-header { background: #fff; border-bottom: 2px solid #eaeaea; padding: 15px 20px; color: #b71c1c; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
    .btn-asb { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .btn-asb:hover { background: #b71c1c; color: #fff; }
    .btn-asb-secondary { background: #f5f5f5; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; text-decoration: none; }
    .btn-asb-secondary:hover { background: #e0e0e0; }
    .btn-asb-sm { padding: 4px 8px; font-size: 10px; }
    .asb-input { border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; width: 100%; font-size: 12px; box-sizing: border-box; }
    .asb-input:focus { border-color: #d32f2f; outline: none; box-shadow: 0 0 4px rgba(211,47,47,0.2); }
    .asb-badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    .badge-allocated { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
    .badge-partial { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
    .badge-notallocated { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
    .badge-noitems { background: #f5f5f5; color: #666; border: 1px solid #e0e0e0; }
    .asb-footer { text-align: center; margin-top: 40px; padding: 15px; color: #777; border-top: 1px solid #eee; font-size: 12px; }
    .asb-footer strong { color: #b71c1c; }

    .modal-mask { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:25px; border-radius:8px; max-width:95%; width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .modal-content h4 { margin-top:0; color:#b71c1c; }
    .modal-content .form-group { margin-bottom:15px; }
    .modal-content .form-group label { display:block; font-weight:600; font-size:12px; margin-bottom:5px; }
    .modal-content .form-group input { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; }
    .modal-content .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

    .allocation-table { font-size:12px; width:100%; border-collapse:collapse; }
    .allocation-table th { background: #f1f3f5; font-size: 10px; text-transform: uppercase; border-bottom: 2px solid #d32f2f; padding: 6px; text-align:center; }
    .allocation-table td { padding: 4px; vertical-align: middle; border-bottom: 1px solid #e9ecef; text-align:center; }
    .allocation-table input[type="number"] { width: 60px; padding: 4px; border: 1px solid #ccc; border-radius: 4px; text-align: center; }
    .allocation-table .company-header { background: #e9ecef; font-weight: bold; }
    .allocation-table .item-label { text-align:left; font-weight:600; }
    .available-qty { font-size: 12px; font-weight: bold; color: #1b5e20; }
    .available-qty.over-allocated { color: #b71c1c; }

    .po-table th { background: #f8f9fa; border-bottom: 2px solid #d32f2f; font-size: 10px; text-transform: uppercase; padding: 8px; }
    .po-table td { padding: 8px; vertical-align: middle; font-size: 12px; border-bottom: 1px solid #e9ecef; }
    .po-table .status-badge { min-width: 100px; display: inline-block; text-align: center; }

    .select2-container--default .select2-selection--single { height: 34px; border-color: #ccc; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 34px; padding-left: 12px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }

    /* Print styles */
    @media print {
        body * { visibility: hidden; }
        #printArea, #printArea * { visibility: visible; }
        #printArea { position: fixed; left: 0; top: 0; width: 100%; padding: 20px; background: white; }
        #printArea .no-print, .no-print { display: none !important; }
        .allocation-table th { background: #f1f3f5 !important; -webkit-print-color-adjust: exact; }
        .allocation-table .company-header { background: #e9ecef !important; -webkit-print-color-adjust: exact; }
        .modal-mask { display: none !important; }
    }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Allocate PO to Branches</span></h2>

    <!-- ==================== FILTER PANEL ==================== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-sliders-h"></i> Search & Filter POs</span>
        </div>
        <div style="padding: 15px 20px;">
            <form id="filterForm">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: flex-end;">
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">PO Number</label>
                        <select id="po_search" class="asb-input select2-ajax" style="width:100%;">
                            <option value="">-- Search PO --</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Supplier</label>
                        <select id="supplier_search" class="asb-input select2-ajax" style="width:100%;">
                            <option value="">-- Search Supplier --</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Date From</label>
                        <input type="date" name="date_from" id="date_from" class="asb-input">
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Date To</label>
                        <input type="date" name="date_to" id="date_to" class="asb-input">
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">PO Status</label>
                        <select name="status" id="status" class="asb-input">
                            <option value="">-- All --</option>
                            <option value="Pending">Pending</option>
                            <option value="Received">Received</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Allocation Status</label>
                        <select name="alloc_status" id="alloc_status" class="asb-input">
                            <option value="">-- All --</option>
                            <option value="Fully Allocated">Fully Allocated</option>
                            <option value="Partially Allocated">Partially Allocated</option>
                            <option value="Not Allocated">Not Allocated</option>
                            <option value="No Items">No Items</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" id="applyFilters" class="btn-asb"><i class="fas fa-filter"></i> Apply</button>
                        <button type="button" id="resetFilters" class="btn-asb-secondary">Reset</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== PO LIST ==================== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span>Purchase Orders</span>
            <span id="poCount" style="font-size:11px; color:#888;"></span>
        </div>
        <div style="padding: 0;">
            <div class="table-responsive">
                <table class="table po-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th style="text-align:center;">Items</th>
                            <th style="text-align:center;">Total Qty</th>
                            <th style="text-align:center;">Allocated Qty</th>
                            <th style="text-align:center;">Allocation Status</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="poListBody">
                        <tr><td colspan="8" style="text-align:center; padding:20px; color:#888;">Loading POs...</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="padding: 10px 20px; display: flex; justify-content: space-between; align-items: center;">
                <span id="paginationInfo" style="font-size:12px; color:#888;"></span>
                <div>
                    <button id="prevPage" class="btn-asb-secondary btn-asb-sm" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                    <button id="nextPage" class="btn-asb-secondary btn-asb-sm" disabled>Next <i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== ALLOCATION MODAL ==================== -->
    <div id="allocationModal" class="modal-mask">
        <div class="modal-content">
            <h4 id="modalTitle"><i class="fas fa-tasks"></i> Allocate Items</h4>
            <div id="printArea">
                <div id="modalBody">
                    <!-- Allocation grid loaded via AJAX -->
                    <div style="text-align:center; padding:20px;">Loading...</div>
                </div>
            </div>
            <div class="modal-actions no-print">
                <button type="button" class="btn-asb-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="btn-asb-secondary" onclick="closeModal()">Close</button>
                <button type="button" id="saveAllocBtn" class="btn-asb"><i class="fas fa-save"></i> Save Allocations</button>
            </div>
            <div id="modalError" style="color:#d32f2f; font-size:12px; margin-top:10px;"></div>
        </div>
    </div>

    <!-- ==================== PASSWORD MODAL (for save) ==================== -->
    <div id="passwordModal" class="modal-mask">
        <div class="modal-content" style="max-width:400px;">
            <h4><i class="fas fa-lock"></i> Manager Authorization</h4>
            <p style="font-size:12px; color:#666;">Enter manager password to confirm allocations.</p>
            <div class="form-group">
                <label for="modalPassword">Password</label>
                <input type="password" id="modalPassword" class="asb-input" placeholder="Enter manager password">
            </div>
            <div id="passwordModalError" style="color:#d32f2f; font-size:12px; margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn-asb-secondary" onclick="closePasswordModal()">Cancel</button>
                <button type="button" class="btn-asb" onclick="submitAllocations()">Confirm Save</button>
            </div>
        </div>
    </div>

    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:4px; display:inline-block; color:#aaa;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>
</div>

<script>
$(document).ready(function() {

    // ---- Select2 AJAX ----
    function ajaxSelect2(url, placeholder, extraData) {
        return {
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    var data = {
                        ajax_action: url,
                        search: params.term || '',
                        page: params.page || 1
                    };
                    if (extraData) $.extend(data, extraData);
                    return data;
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data.results,
                        pagination: { more: data.pagination.more }
                    };
                },
                cache: true
            },
            placeholder: placeholder,
            minimumInputLength: 0,
            allowClear: true,
            templateResult: function(item) { return item.loading ? item.text : item.text; },
            templateSelection: function(item) { return item.text || placeholder; }
        };
    }

    // Initialize select2
    $('#po_search').select2(ajaxSelect2('get_po_suggestions', 'Search PO number...'));
    $('#supplier_search').select2(ajaxSelect2('get_suppliers', 'Search supplier...'));

    // ---- State ----
    var currentPage = 1;
    var totalPages = 1;
    var totalRecords = 0;
    var isLoading = false;

    // ---- Load PO List ----
    function loadPOList(page) {
        if (isLoading) return;
        isLoading = true;

        var data = {
            ajax_action: 'get_po_list',
            page: page || 1,
            search_po: $('#po_search').val() || '',
            search_supplier: $('#supplier_search').val() || '',
            date_from: $('#date_from').val() || '',
            date_to: $('#date_to').val() || '',
            status: $('#status').val() || '',
            alloc_status: $('#alloc_status').val() || ''
        };

        $.ajax({
            url: window.location.href,
            data: data,
            dataType: 'json',
            success: function(res) {
                renderPOList(res.results);
                updatePagination(res.pagination);
                isLoading = false;
            },
            error: function() {
                $('#poListBody').html('<tr><td colspan="8" style="text-align:center; color:red;">Error loading POs.</td></tr>');
                isLoading = false;
            }
        });
    }

    function renderPOList(rows) {
        if (!rows || rows.length === 0) {
            $('#poListBody').html('<tr><td colspan="8" style="text-align:center; padding:20px; color:#888;">No POs found.</td></tr>');
            return;
        }

        var html = '';
        rows.forEach(function(row) {
            var statusBadge = '';
            switch (row.alloc_status) {
                case 'Fully Allocated': statusBadge = '<span class="asb-badge badge-allocated">Fully Allocated</span>'; break;
                case 'Partially Allocated': statusBadge = '<span class="asb-badge badge-partial">Partially Allocated</span>'; break;
                case 'Not Allocated': statusBadge = '<span class="asb-badge badge-notallocated">Not Allocated</span>'; break;
                default: statusBadge = '<span class="asb-badge badge-noitems">No Items</span>';
            }
            html += '<tr>';
            html += '<td><strong>' + row.po_number + '</strong></td>';
            html += '<td>' + (row.supplier_name || 'Unknown') + '</td>';
            html += '<td>' + row.purchase_date + '</td>';
            html += '<td style="text-align:center;">' + row.total_items + '</td>';
            html += '<td style="text-align:center;">' + row.total_qty + '</td>';
            html += '<td style="text-align:center;">' + row.allocated_qty + '</td>';
            html += '<td style="text-align:center;">' + statusBadge + '</td>';
            var disabled = (row.total_items == 0) ? 'disabled' : '';
            html += '<td style="text-align:center;"><button class="btn-asb btn-asb-sm allocate-btn" data-po-id="' + row.po_id + '" ' + disabled + '><i class="fas fa-tasks"></i> Allocate</button></td>';
            html += '</tr>';
        });
        $('#poListBody').html(html);
    }

    function updatePagination(pagination) {
        totalRecords = pagination.total || 0;
        currentPage = pagination.page || 1;
        var perPage = pagination.per_page || 20;
        totalPages = Math.ceil(totalRecords / perPage);
        $('#poCount').text('Total: ' + totalRecords + ' POs');
        $('#paginationInfo').text('Page ' + currentPage + ' of ' + totalPages);
        $('#prevPage').prop('disabled', currentPage <= 1);
        $('#nextPage').prop('disabled', currentPage >= totalPages);
    }

    // ---- Event: Apply Filters ----
    $('#applyFilters').on('click', function() {
        currentPage = 1;
        loadPOList(1);
    });

    // ---- Event: Reset Filters ----
    $('#resetFilters').on('click', function() {
        $('#po_search').val(null).trigger('change');
        $('#supplier_search').val(null).trigger('change');
        $('#date_from').val('');
        $('#date_to').val('');
        $('#status').val('');
        $('#alloc_status').val('');
        currentPage = 1;
        loadPOList(1);
    });

    // ---- Pagination ----
    $('#prevPage').on('click', function() {
        if (currentPage > 1) {
            loadPOList(currentPage - 1);
        }
    });
    $('#nextPage').on('click', function() {
        if (currentPage < totalPages) {
            loadPOList(currentPage + 1);
        }
    });

    // ---- Initial Load ----
    loadPOList(1);

    // ---- Allocate Button: Open Modal ----
    $(document).on('click', '.allocate-btn', function() {
        var poId = $(this).data('po-id');
        if (!poId) return;
        openAllocationModal(poId);
    });

    // ---- Open Allocation Modal ----
    function openAllocationModal(poId) {
        $('#allocationModal').css('display', 'flex');
        $('#modalBody').html('<div style="text-align:center; padding:20px;">Loading PO details...</div>');
        $('#modalError').text('');
        $('#modalTitle').html('<i class="fas fa-tasks"></i> Loading...');

        $.ajax({
            url: window.location.href,
            data: { ajax_action: 'get_po_details', po_id: poId },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#modalBody').html('<div style="color:red;">' + data.error + '</div>');
                    return;
                }
                renderAllocationGrid(data);
                $('#modalTitle').html('<i class="fas fa-tasks"></i> PO: ' + data.header.po_number + ' | Supplier: ' + data.header.supplier_name);
                // Store po_id for save
                $('#allocationModal').data('po_id', poId);
            },
            error: function() {
                $('#modalBody').html('<div style="color:red;">Failed to load PO details.</div>');
            }
        });
    }

    // ---- Render Allocation Grid ----
    function renderAllocationGrid(data) {
        var items = data.items;
        var branches = data.branches;
        var existing = data.allocations || {};

        if (!items || items.length === 0) {
            $('#modalBody').html('<div style="text-align:center; padding:20px; color:#888;">No items in this PO.</div>');
            return;
        }

        // Group branches by company
        var companyMap = {};
        branches.forEach(function(b) {
            var cid = b.company_id || 0;
            if (!companyMap[cid]) companyMap[cid] = { company_name: b.company_name || 'Unassigned', branches: [] };
            companyMap[cid].branches.push(b);
        });

        var html = '<div class="table-responsive"><table class="allocation-table" id="allocationGrid">';
        // Header row: item columns + branch columns grouped by company
        html += '<thead><tr><th style="min-width:120px;">Item</th><th style="min-width:60px;">PO Qty</th><th style="min-width:60px;">Allocated</th><th style="min-width:60px;">Available</th>';

        var companyIds = Object.keys(companyMap);
        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            var colSpan = comp.branches.length;
            html += '<th colspan="' + colSpan + '" style="text-align:center; background:#e9ecef;">' + comp.company_name + '</th>';
        });
        html += '</tr><tr><th></th><th></th><th></th><th></th>';

        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            comp.branches.forEach(function(b) {
                html += '<th style="text-align:center; font-weight:normal; font-size:10px;">' + b.location_name + '</th>';
            });
        });
        html += '</tr></thead><tbody>';

        // For each item
        items.forEach(function(item) {
            var poItemId = item.po_item_id;
            var qty = parseInt(item.quantity) || 0;
            var allocated = parseInt(item.allocated_qty) || 0;
            var available = qty - allocated;

            html += '<tr data-poitem="' + poItemId + '">';
            html += '<td class="item-label"><strong>' + item.item_code + '</strong><br><small>' + item.item_name + '</small></td>';
            html += '<td class="po-qty">' + qty + '</td>';
            html += '<td class="current-alloc">' + allocated + '</td>';
            html += '<td class="available-qty" data-poitem="' + poItemId + '">' + available + '</td>';

            companyIds.forEach(function(cid) {
                var comp = companyMap[cid];
                comp.branches.forEach(function(b) {
                    var locId = b.location_id;
                    var existingQty = (existing[poItemId] && existing[poItemId][locId]) ? parseInt(existing[poItemId][locId]) : 0;

                    html += '<td>';
                    html += '<input type="number" name="alloc[' + poItemId + '][' + locId + ']" class="alloc-input" ';
                    html += 'value="' + existingQty + '" min="0" max="' + available + '" ';
                    html += 'data-poitem="' + poItemId + '" data-loc="' + locId + '" style="width:60px;">';
                    html += '</td>';
                });
            });

            html += '</tr>';
        });

        html += '</tbody></table></div>';
        $('#modalBody').html(html);

        // ---- Real-time available update ----
        updateAvailable(); // initial

        $('.alloc-input').on('input', function() {
            updateAvailable();
        });

        function updateAvailable() {
            // For each po_item_id, sum all inputs and update available cell
            var items = {};
            $('.alloc-input').each(function() {
                var poItemId = $(this).data('poitem');
                var val = parseInt($(this).val()) || 0;
                if (!items[poItemId]) items[poItemId] = 0;
                items[poItemId] += val;
            });

            // Update available cell for each item
            $('.available-qty').each(function() {
                var poItemId = $(this).data('poitem');
                var poQty = parseInt($(this).closest('tr').find('.po-qty').text()) || 0;
                var totalAlloc = items[poItemId] || 0;
                var available = poQty - totalAlloc;
                $(this).text(available);
                // Update max attribute on all inputs for this item
                $('.alloc-input[data-poitem="' + poItemId + '"]').each(function() {
                    var currentVal = parseInt($(this).val()) || 0;
                    var newMax = Math.max(0, available + currentVal); // because we can reduce existing
                    $(this).attr('max', newMax);
                    // If current value exceeds max, reduce it
                    if (parseInt($(this).val()) > newMax) {
                        $(this).val(newMax);
                    }
                });
                // Color coding for available
                if (available < 0) {
                    $(this).addClass('over-allocated');
                } else {
                    $(this).removeClass('over-allocated');
                }
            });
        }
    }

    // ---- Close Modal ----
    window.closeModal = function() {
        $('#allocationModal').css('display', 'none');
    };
    $('#allocationModal').on('click', function(e) {
        if (e.target === this) closeModal();
    });

    // ---- Save Allocations: Open Password Modal ----
    $('#saveAllocBtn').on('click', function() {
        // Validate that no available is negative
        var hasError = false;
        $('.available-qty').each(function() {
            if (parseInt($(this).text()) < 0) hasError = true;
        });
        if (hasError) {
            $('#modalError').text('Some items are over‑allocated. Please correct the quantities.');
            return;
        }

        // Open password modal
        $('#passwordModal').css('display', 'flex');
        $('#modalPassword').val('');
        $('#passwordModalError').text('');
    });

    // ---- Submit Allocations ----
    window.submitAllocations = function() {
        var password = $('#modalPassword').val().trim();
        if (!password) {
            $('#passwordModalError').text('Please enter the manager password.');
            return;
        }

        // Gather allocation data
        var allocData = {};
        $('.alloc-input').each(function() {
            var poItemId = $(this).data('poitem');
            var locId = $(this).data('loc');
            var val = parseInt($(this).val()) || 0;
            if (val > 0) {
                if (!allocData[poItemId]) allocData[poItemId] = {};
                allocData[poItemId][locId] = val;
            }
        });

        var poId = $('#allocationModal').data('po_id');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                ajax_action: 'save_allocations',
                manager_password: password,
                po_id: poId,
                allocations: JSON.stringify(allocData)
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    alert(res.message);
                    closePasswordModal();
                    closeModal();
                    // Refresh PO list
                    loadPOList(currentPage);
                } else {
                    $('#passwordModalError').text(res.message);
                }
            },
            error: function() {
                $('#passwordModalError').text('An error occurred.');
            }
        });
    };

    // ---- Close Password Modal ----
    window.closePasswordModal = function() {
        $('#passwordModal').css('display', 'none');
    };
    $('#passwordModal').on('click', function(e) {
        if (e.target === this) closePasswordModal();
    });

});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>