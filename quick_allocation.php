<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Quick Qty Allocation';
$page = 'quick_allocation';

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
// AJAX: get suppliers for Select2
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_suppliers') {
    header('Content-Type: application/json');
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sql = "SELECT supplier_id AS id, supplier_name AS text FROM return_qc.suppliers";
    if (!empty($search)) {
        $sql .= " WHERE supplier_name LIKE ?";
        $like = "%$search%";
        $stmt = $qcConn->prepare($sql . " ORDER BY supplier_name LIMIT 20");
        $stmt->bind_param("s", $like);
    } else {
        $stmt = $qcConn->prepare($sql . " ORDER BY supplier_name LIMIT 20");
    }
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['results' => $results, 'pagination' => ['more' => false]]);
    exit;
}

// ------------------------------------------------------------
// AJAX: get PO list with allocation status (paged)
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
    $per_page = 15;
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
    $sql .= " $where_clause ORDER BY h.purchase_date DESC, h.po_id DESC LIMIT ? OFFSET ?";
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
// AJAX: get PO details with branches and items
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_po_details') {
    header('Content-Type: application/json');
    $po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
    if (!$po_id) {
        echo json_encode(['error' => 'Invalid PO ID']);
        exit;
    }

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

    $branch_sql = "SELECT l.location_id, l.location_name, c.company_id, c.company_name
                   FROM store_locations l
                   LEFT JOIN companies c ON l.company_id = c.company_id
                   ORDER BY c.company_name, l.location_name";
    $branches = $conn->query($branch_sql)->fetch_all(MYSQLI_ASSOC);

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
        $del = $conn->prepare("DELETE FROM po_item_allocations WHERE po_item_id IN (SELECT po_item_id FROM po_items WHERE po_id = ?)");
        $del->bind_param("i", $po_id);
        $del->execute();
        $del->close();

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
    /* General styles */
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

    .allocation-grid { font-size: 12px; width: 100%; border-collapse: collapse; }
    .allocation-grid th { background: #f1f3f5; font-size: 10px; text-transform: uppercase; border-bottom: 2px solid #d32f2f; padding: 6px; text-align:center; }
    .allocation-grid td { padding: 4px; vertical-align: middle; border-bottom: 1px solid #e9ecef; text-align:center; }
    .allocation-grid input[type="number"] { width: 70px; padding: 4px; border: 1px solid #ccc; border-radius: 4px; text-align: center; }
    .allocation-grid .item-label { text-align:left; font-weight:600; }
    .allocation-grid .available-qty { font-weight: bold; color: #1b5e20; }
    .allocation-grid .available-qty.over { color: #b71c1c; }

    .select2-container--default .select2-selection--single { height: 34px; border-color: #ccc; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 34px; padding-left: 12px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }

    .modal-mask { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:25px; border-radius:8px; max-width:95%; width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .modal-content h4 { margin-top:0; color:#b71c1c; }
    .modal-content .form-group { margin-bottom:15px; }
    .modal-content .form-group label { display:block; font-weight:600; font-size:12px; margin-bottom:5px; }
    .modal-content .form-group input { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; }
    .modal-content .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

    /* PO List table */
    .po-table { font-size: 12px; width: 100%; border-collapse: collapse; }
    .po-table th { background: #f8f9fa; border-bottom: 2px solid #d32f2f; font-size: 10px; text-transform: uppercase; padding: 8px; }
    .po-table td { padding: 8px; vertical-align: middle; border-bottom: 1px solid #e9ecef; }
    .po-table .status-badge { min-width: 100px; display: inline-block; text-align: center; }
    .po-table .btn-load { background: #1976d2; color: #fff; border: none; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: 600; transition: 0.2s; }
    .po-table .btn-load:hover { background: #0d47a1; }
    .po-table .btn-load:disabled { opacity: 0.5; cursor: not-allowed; }

    .pagination-controls { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Quick Qty Allocation</span></h2>

    <!-- ==================== PO SELECTOR (Quick Jump) ==================== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-search"></i> Quick PO Search</span>
        </div>
        <div style="padding: 15px 20px;">
            <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 2; min-width: 250px;">
                    <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">PO Number</label>
                    <select id="po_selector" class="asb-input select2-ajax" style="width:100%;">
                        <option value="">-- Search PO --</option>
                    </select>
                </div>
                <div>
                    <button type="button" id="loadPOBtn" class="btn-asb"><i class="fas fa-arrow-right"></i> Load PO</button>
                </div>
            </div>
            <div id="poInfo" style="margin-top: 10px; font-size:13px; color:#555;"></div>
        </div>
    </div>

    <!-- ==================== FILTERS & PO LIST ==================== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-list"></i> Purchase Orders</span>
            <span id="poCount" style="font-size:11px; color:#888;"></span>
        </div>
        <div style="padding: 15px 20px;">
            <!-- Filter form -->
            <form id="filterForm" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 15px;">
                <div>
                    <label style="font-weight:600; margin-bottom:4px; display:block; font-size:11px; color:#555;">PO Number</label>
                    <select id="filter_po_search" class="asb-input select2-ajax" style="width:100%;">
                        <option value="">-- Search --</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:4px; display:block; font-size:11px; color:#555;">Supplier</label>
                    <select id="filter_supplier_search" class="asb-input select2-ajax" style="width:100%;">
                        <option value="">-- Search --</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:4px; display:block; font-size:11px; color:#555;">Date From</label>
                    <input type="date" id="filter_date_from" class="asb-input">
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:4px; display:block; font-size:11px; color:#555;">Date To</label>
                    <input type="date" id="filter_date_to" class="asb-input">
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:4px; display:block; font-size:11px; color:#555;">PO Status</label>
                    <select id="filter_status" class="asb-input">
                        <option value="">-- All --</option>
                        <option value="Pending">Pending</option>
                        <option value="Received">Received</option>
                        <option value="Completed">Completed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:4px; display:block; font-size:11px; color:#555;">Allocation Status</label>
                    <select id="filter_alloc_status" class="asb-input">
                        <option value="">-- All --</option>
                        <option value="Fully Allocated">Fully Allocated</option>
                        <option value="Partially Allocated">Partially Allocated</option>
                        <option value="Not Allocated">Not Allocated</option>
                        <option value="No Items">No Items</option>
                    </select>
                </div>
                <div style="display: flex; gap: 8px; align-items: flex-end;">
                    <button type="button" id="applyFilters" class="btn-asb"><i class="fas fa-filter"></i> Apply</button>
                    <button type="button" id="resetFilters" class="btn-asb-secondary">Reset</button>
                </div>
            </form>

            <!-- PO Table -->
            <div class="table-responsive">
                <table class="po-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th style="text-align:center;">Items</th>
                            <th style="text-align:center;">Qty</th>
                            <th style="text-align:center;">Alloc</th>
                            <th style="text-align:center;">Alloc Status</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="poListBody">
                        <tr><td colspan="8" style="text-align:center; padding:20px; color:#888;">Loading POs...</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="pagination-controls">
                <span id="paginationInfo" style="font-size:12px; color:#888;"></span>
                <div>
                    <button id="prevPage" class="btn-asb-secondary btn-asb-sm" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                    <button id="nextPage" class="btn-asb-secondary btn-asb-sm" disabled>Next <i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== ALLOCATION GRID ==================== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-tasks"></i> Allocation Grid</span>
            <span>
                <button type="button" id="quickAllocateBtn" class="btn-asb" style="background:#1976d2;"><i class="fas fa-bolt"></i> Quick Allocate</button>
                <button type="button" id="saveAllocBtn" class="btn-asb"><i class="fas fa-save"></i> Save Allocations</button>
            </span>
        </div>
        <div style="padding: 0;">
            <div class="table-responsive" id="allocationContainer">
                <p style="padding:20px; text-align:center; color:#888;">Select a PO from the list or use the quick search.</p>
            </div>
        </div>
    </div>

    <!-- ==================== MODALS ==================== -->
    <!-- Password Modal -->
    <div id="passwordModal" class="modal-mask">
        <div class="modal-content" style="max-width:400px;">
            <h4><i class="fas fa-lock"></i> Manager Authorization</h4>
            <p style="font-size:12px; color:#666;">Enter manager password to save allocations.</p>
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

    <!-- Manual Allocation Modal -->
    <div id="manualAllocModal" class="modal-mask">
        <div class="modal-content" style="max-width:500px;">
            <h4><i class="fas fa-edit"></i> Manual Allocation</h4>
            <p style="font-size:12px; color:#666;" id="manualTotalMsg">Enter quantities for each company.</p>
            <div id="manualAllocFields">
                <div class="form-group"><label>Glamour Gate</label><input type="number" id="manual_gg" class="asb-input" min="0" value="0"></div>
                <div class="form-group"><label>ASB Glamour</label><input type="number" id="manual_ag" class="asb-input" min="0" value="0"></div>
                <div class="form-group"><label>ASB Fashion</label><input type="number" id="manual_af" class="asb-input" min="0" value="0"></div>
            </div>
            <div id="manualError" style="color:#d32f2f; font-size:12px; margin-bottom:10px;"></div>
            <div class="modal-actions">
                <button type="button" class="btn-asb-secondary" id="manualCancelBtn">Cancel</button>
                <button type="button" class="btn-asb" id="manualConfirmBtn">Confirm</button>
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

    // =========================================================
    // 1. SELECT2 INITIALIZATION
    // =========================================================
    function initSelect2(selector, action, placeholder) {
        $(selector).select2({
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        ajax_action: action,
                        search: params.term || '',
                        page: params.page || 1
                    };
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
            allowClear: true
        });
    }

    initSelect2('#po_selector', 'get_po_suggestions', 'Search PO number...');
    initSelect2('#filter_po_search', 'get_po_suggestions', 'Search PO...');
    initSelect2('#filter_supplier_search', 'get_suppliers', 'Search supplier...');

    // =========================================================
    // 2. KEYBOARD SHORTCUTS
    // =========================================================

    // Auto-load PO when selected from dropdown (more intuitive than pressing Enter)
    $('#po_selector').on('select2:select', function(e) {
        var poId = e.params.data.id;
        if (poId) {
            loadPODetails(poId);
        }
    });

    // In filter form, pressing Enter applies filters
    $('#filterForm input, #filterForm select').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#applyFilters').click();
        }
    });

    // Manual allocation modal: Enter confirms
    $('#manual_gg, #manual_ag, #manual_af').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#manualConfirmBtn').click();
        }
    });

    // Password modal: Enter submits save
    $('#modalPassword').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            window.submitAllocations();
        }
    });

    // Allocation grid: Enter moves to next input field (fast data entry)
    $('#allocationContainer').on('keypress', '.alloc-input', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            var $inputs = $('.alloc-input');
            var idx = $inputs.index(this);
            if (idx < $inputs.length - 1) {
                $inputs.eq(idx + 1).focus();
            } else {
                // Optionally focus the save button or the first field of next row
                // For now, just stay
            }
        }
    });

    // =========================================================
    // 3. STATE
    // =========================================================
    var currentPO = null;
    var itemsData = [];
    var branchesData = [];
    var existingAlloc = {};
    var companyBranchMap = {};
    var allocationMap = {
        300: [60, 96, 144],
        360: [72, 120, 168],
        480: [72, 144, 264],
        500: [72, 144, 284],
        240: [48, 96, 96],
        600: [96, 180, 324]
    };
    var COMPANY_ORDER = ['Glamour Gate', 'ASB Glamour', 'ASB Fashion'];

    // PO List pagination
    var currentPage = 1;
    var totalPages = 1;
    var totalRecords = 0;
    var isLoading = false;

    // =========================================================
    // 4. LOAD PO LIST
    // =========================================================
    function loadPOList(page) {
        if (isLoading) return;
        isLoading = true;

        var data = {
            ajax_action: 'get_po_list',
            page: page || 1,
            search_po: $('#filter_po_search').val() || '',
            search_supplier: $('#filter_supplier_search').val() || '',
            date_from: $('#filter_date_from').val() || '',
            date_to: $('#filter_date_to').val() || '',
            status: $('#filter_status').val() || '',
            alloc_status: $('#filter_alloc_status').val() || ''
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
            var disabled = (row.total_items == 0) ? 'disabled' : '';
            html += '<tr>';
            html += '<td><strong>' + row.po_number + '</strong></td>';
            html += '<td>' + (row.supplier_name || 'Unknown') + '</td>';
            html += '<td>' + row.purchase_date + '</td>';
            html += '<td style="text-align:center;">' + row.total_items + '</td>';
            html += '<td style="text-align:center;">' + row.total_qty + '</td>';
            html += '<td style="text-align:center;">' + row.allocated_qty + '</td>';
            html += '<td style="text-align:center;">' + statusBadge + '</td>';
            html += '<td style="text-align:center;"><button class="btn-load load-po-btn" data-po-id="' + row.po_id + '" ' + disabled + '><i class="fas fa-arrow-right"></i> Load</button></td>';
            html += '</tr>';
        });
        $('#poListBody').html(html);
    }

    function updatePagination(pagination) {
        totalRecords = pagination.total || 0;
        currentPage = pagination.page || 1;
        var perPage = pagination.per_page || 15;
        totalPages = Math.ceil(totalRecords / perPage);
        $('#poCount').text('Total: ' + totalRecords + ' POs');
        $('#paginationInfo').text('Page ' + currentPage + ' of ' + (totalPages || 1));
        $('#prevPage').prop('disabled', currentPage <= 1);
        $('#nextPage').prop('disabled', currentPage >= totalPages);
    }

    // Filter events
    $('#applyFilters').on('click', function() {
        currentPage = 1;
        loadPOList(1);
    });
    $('#resetFilters').on('click', function() {
        $('#filter_po_search').val(null).trigger('change');
        $('#filter_supplier_search').val(null).trigger('change');
        $('#filter_date_from').val('');
        $('#filter_date_to').val('');
        $('#filter_status').val('');
        $('#filter_alloc_status').val('');
        currentPage = 1;
        loadPOList(1);
    });
    $('#prevPage').on('click', function() {
        if (currentPage > 1) loadPOList(currentPage - 1);
    });
    $('#nextPage').on('click', function() {
        if (currentPage < totalPages) loadPOList(currentPage + 1);
    });

    // =========================================================
    // 5. LOAD A PO (from quick search or list)
    // =========================================================
    function loadPODetails(poId) {
        // Show loading in allocation container
        $('#allocationContainer').html('<p style="padding:20px; text-align:center; color:#888;">Loading PO details...</p>');

        $.ajax({
            url: window.location.href,
            data: { ajax_action: 'get_po_details', po_id: poId },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    alert(data.error);
                    $('#allocationContainer').html('<p style="padding:20px; text-align:center; color:red;">' + data.error + '</p>');
                    return;
                }
                currentPO = data.header;
                itemsData = data.items;
                branchesData = data.branches;
                existingAlloc = data.allocations || {};

                // Build company branch map (one branch per company)
                var compBranches = {};
                branchesData.forEach(function(b) {
                    var cid = b.company_id;
                    if (!compBranches[cid]) compBranches[cid] = [];
                    compBranches[cid].push(b);
                });
                companyBranchMap = {};
                var companyNames = {};
                branchesData.forEach(function(b) {
                    companyNames[b.company_id] = b.company_name;
                });
                for (var cid in compBranches) {
                    var branches = compBranches[cid];
                    branches.sort(function(a, b) { return a.location_id - b.location_id; });
                    var chosen = branches[0];
                    var compName = companyNames[cid];
                    if (compName) {
                        companyBranchMap[compName] = chosen.location_id;
                    }
                }

                // Update quick search info
                $('#poInfo').html('<strong>PO:</strong> ' + currentPO.po_number + ' &nbsp;|&nbsp; <strong>Supplier:</strong> ' + currentPO.supplier_name +
                    ' &nbsp;|&nbsp; <strong>Date:</strong> ' + currentPO.purchase_date);

                // Render allocation grid
                renderAllocationGrid();
            },
            error: function() {
                $('#allocationContainer').html('<p style="padding:20px; text-align:center; color:red;">Failed to load PO details.</p>');
            }
        });
    }

    // Quick search load (button)
    $('#loadPOBtn').on('click', function() {
        var poId = $('#po_selector').val();
        if (!poId) {
            alert('Please select a PO.');
            return;
        }
        loadPODetails(poId);
    });

    // Load from list
    $(document).on('click', '.load-po-btn', function() {
        var poId = $(this).data('po-id');
        if (poId) loadPODetails(poId);
    });

    // =========================================================
    // 6. RENDER ALLOCATION GRID
    // =========================================================
    function renderAllocationGrid() {
        if (!itemsData || itemsData.length === 0) {
            $('#allocationContainer').html('<p style="padding:20px; text-align:center; color:#888;">No items in this PO.</p>');
            return;
        }

        var html = '<table class="allocation-grid">';
        html += '<thead><tr>';
        html += '<th style="min-width:120px;">Item</th>';
        html += '<th style="min-width:60px;">PO Qty</th>';
        html += '<th style="min-width:60px;">Allocated</th>';
        html += '<th style="min-width:60px;">Available</th>';
        COMPANY_ORDER.forEach(function(comp) {
            html += '<th style="min-width:80px;">' + comp + '</th>';
        });
        html += '</tr></thead><tbody>';

        itemsData.forEach(function(item) {
            var poItemId = item.po_item_id;
            var qty = parseInt(item.quantity) || 0;
            var allocated = parseInt(item.allocated_qty) || 0;
            var available = qty - allocated;

            html += '<tr data-poitem="' + poItemId + '">';
            html += '<td class="item-label"><strong>' + item.item_code + '</strong><br><small>' + item.item_name + '</small></td>';
            html += '<td class="po-qty">' + qty + '</td>';
            html += '<td class="current-alloc">' + allocated + '</td>';
            html += '<td class="available-qty" data-poitem="' + poItemId + '">' + available + '</td>';

            COMPANY_ORDER.forEach(function(comp) {
                var locId = companyBranchMap[comp];
                var existingQty = (existingAlloc[poItemId] && existingAlloc[poItemId][locId]) ? parseInt(existingAlloc[poItemId][locId]) : 0;
                html += '<td>';
                html += '<input type="number" name="alloc[' + poItemId + '][' + locId + ']" class="alloc-input" ';
                html += 'value="' + existingQty + '" min="0" max="' + available + '" ';
                html += 'data-poitem="' + poItemId + '" data-loc="' + locId + '" style="width:70px;">';
                html += '</td>';
            });

            html += '</tr>';
        });

        html += '</tbody></table>';
        $('#allocationContainer').html(html);

        $('.alloc-input').on('input', function() {
            updateAvailable();
        });
        updateAvailable();
    }

    function updateAvailable() {
        var totals = {};
        $('.alloc-input').each(function() {
            var poItemId = $(this).data('poitem');
            var val = parseInt($(this).val()) || 0;
            if (!totals[poItemId]) totals[poItemId] = 0;
            totals[poItemId] += val;
        });

        $('.available-qty').each(function() {
            var poItemId = $(this).data('poitem');
            var poQty = parseInt($(this).closest('tr').find('.po-qty').text()) || 0;
            var totalAlloc = totals[poItemId] || 0;
            var available = poQty - totalAlloc;
            $(this).text(available);
            $('.alloc-input[data-poitem="' + poItemId + '"]').each(function() {
                var currentVal = parseInt($(this).val()) || 0;
                var newMax = Math.max(0, available + currentVal);
                $(this).attr('max', newMax);
                if (parseInt($(this).val()) > newMax) {
                    $(this).val(newMax);
                }
            });
            if (available < 0) {
                $(this).addClass('over');
            } else {
                $(this).removeClass('over');
            }
        });
    }

    // =========================================================
    // 7. QUICK ALLOCATE
    // =========================================================
    $('#quickAllocateBtn').on('click', function() {
        if (!itemsData || itemsData.length === 0) {
            alert('Load a PO first.');
            return;
        }
        // Find items not in map
        var manualQueue = [];
        itemsData.forEach(function(item) {
            var totalQty = parseInt(item.quantity) || 0;
            if (!allocationMap[totalQty]) {
                manualQueue.push(item);
            } else {
                // Fill directly
                fillItemAllocation(item.po_item_id, allocationMap[totalQty]);
            }
        });

        if (manualQueue.length === 0) {
            updateAvailable();
            $('#modalError').text('Quick allocation applied for all items.');
            return;
        }

        // Process manual items one by one
        var index = 0;
        function processNext() {
            if (index >= manualQueue.length) {
                updateAvailable();
                $('#modalError').text('Quick allocation complete. Review and save.');
                return;
            }
            var item = manualQueue[index];
            var totalQty = parseInt(item.quantity) || 0;
            showManualModal(totalQty, function(result) {
                if (result) {
                    fillItemAllocation(item.po_item_id, result);
                    index++;
                    processNext();
                } else {
                    $('#modalError').text('Quick allocation cancelled by user.');
                }
            });
        }
        processNext();
    });

    function fillItemAllocation(poItemId, allocArray) {
        COMPANY_ORDER.forEach(function(comp, idx) {
            var locId = companyBranchMap[comp];
            if (!locId) return;
            var qty = allocArray[idx] || 0;
            var input = $('input[name="alloc[' + poItemId + '][' + locId + ']"]');
            if (input.length) {
                input.val(qty).trigger('input');
            }
        });
    }

    // =========================================================
    // 8. MANUAL ALLOCATION MODAL
    // =========================================================
    var manualResolve = null;

    function showManualModal(totalQty, callback) {
        $('#manualTotalMsg').text('Enter quantities for each company. Total must equal ' + totalQty + '.');
        $('#manual_gg, #manual_ag, #manual_af').val(0);
        $('#manualError').text('');
        $('#manualAllocModal').css('display', 'flex');
        manualResolve = callback;

        $('#manualConfirmBtn').off('click').on('click', function() {
            var gg = parseInt($('#manual_gg').val()) || 0;
            var ag = parseInt($('#manual_ag').val()) || 0;
            var af = parseInt($('#manual_af').val()) || 0;
            var sum = gg + ag + af;
            if (sum !== totalQty) {
                $('#manualError').text('Total must equal ' + totalQty + '. Current sum: ' + sum);
                return;
            }
            $('#manualAllocModal').hide();
            if (manualResolve) manualResolve([gg, ag, af]);
        });

        $('#manualCancelBtn').off('click').on('click', function() {
            $('#manualAllocModal').hide();
            if (manualResolve) manualResolve(null);
        });

        $('#manualAllocModal').off('click').on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
                if (manualResolve) manualResolve(null);
            }
        });
    }

    // =========================================================
    // 9. SAVE ALLOCATIONS
    // =========================================================
    $('#saveAllocBtn').on('click', function() {
        var hasError = false;
        $('.available-qty').each(function() {
            if (parseInt($(this).text()) < 0) hasError = true;
        });
        if (hasError) {
            alert('Some items are over‑allocated. Correct the quantities.');
            return;
        }
        $('#passwordModal').css('display', 'flex');
        $('#modalPassword').val('');
        $('#passwordModalError').text('');
    });

    window.submitAllocations = function() {
        var password = $('#modalPassword').val().trim();
        if (!password) {
            $('#passwordModalError').text('Please enter the manager password.');
            return;
        }

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

        var poId = currentPO ? currentPO.po_id : 0;
        if (!poId) {
            alert('No PO loaded.');
            return;
        }

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
                    loadPODetails(poId); // refresh grid
                    loadPOList(currentPage); // refresh list
                } else {
                    $('#passwordModalError').text(res.message);
                }
            },
            error: function() {
                $('#passwordModalError').text('An error occurred.');
            }
        });
    };

    window.closePasswordModal = function() {
        $('#passwordModal').hide();
    };
    $('#passwordModal').on('click', function(e) {
        if (e.target === this) closePasswordModal();
    });

    // =========================================================
    // 10. INITIAL LOAD
    // =========================================================
    loadPOList(1);

    // Auto-load if PO id in URL
    var urlParams = new URLSearchParams(window.location.search);
    var poParam = urlParams.get('po_id');
    if (poParam) {
        $('#po_selector').val(poParam).trigger('change');
        loadPODetails(poParam);
    }
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>