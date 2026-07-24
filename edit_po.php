<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Edit Purchase Order';
$page = 'po_ledger';

$conn = getConnection();          // main connection (po_system)
$qcConn = getQcConnection();      // connection to return_qc

// ------------------------------------------------------------
// Handle AJAX save request (requires manager password)
// ------------------------------------------------------------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_po') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // Verify manager password
    $input_password = isset($_POST['manager_password']) ? trim($_POST['manager_password']) : '';
    if (empty($input_password)) {
        $response['message'] = 'Manager password is required.';
        echo json_encode($response);
        exit;
    }

    $pwd_stmt = $conn->prepare("SELECT password FROM manager_credentials LIMIT 1");
    $pwd_stmt->execute();
    $result = $pwd_stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stored_password = $row['password'];
    } else {
        $response['message'] = 'Manager credentials not set up.';
        echo json_encode($response);
        exit;
    }
    $pwd_stmt->close();

    if ($input_password !== $stored_password) {
        $response['message'] = 'Invalid manager password.';
        echo json_encode($response);
        exit;
    }

    // Get PO ID
    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
    if ($po_id <= 0) {
        $response['message'] = 'Invalid PO ID.';
        echo json_encode($response);
        exit;
    }

    // Start transaction
    $conn->begin_transaction();
    try {
        // 1. Update PO header
        $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
        $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : '';
        $attention = isset($_POST['attention']) ? trim($_POST['attention']) : '';
        $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
        $expected_delivery_date = isset($_POST['expected_delivery_date']) && $_POST['expected_delivery_date'] !== '' ? $_POST['expected_delivery_date'] : null;
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'Pending';

        $update_header = $conn->prepare("UPDATE po_header SET supplier_id = ?, purchase_date = ?, attention = ?, remarks = ?, expected_delivery_date = ?, status = ? WHERE po_id = ?");
        $update_header->bind_param("isssssi", $supplier_id, $purchase_date, $attention, $remarks, $expected_delivery_date, $status, $po_id);
        $update_header->execute();
        $update_header->close();

        // 2. Delete existing po_items for this PO (we'll re-insert all)
        $del_items = $conn->prepare("DELETE FROM po_items WHERE po_id = ?");
        $del_items->bind_param("i", $po_id);
        $del_items->execute();
        $del_items->close();

        // 3. Process each submitted item
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $insert_po_item = $conn->prepare("INSERT INTO po_items (po_id, item_id, quantity, cost_price, selling_price, received_qty) VALUES (?, ?, ?, ?, ?, ?)");

            foreach ($_POST['items'] as $item) {
                $system_code   = trim($item['system_code'] ?? '');
                $item_code     = trim($item['item_code'] ?? '');
                $item_name     = trim($item['item_name'] ?? '');
                $department_id = isset($item['department_id']) ? (int)$item['department_id'] : null;
                $sub_department_id = isset($item['sub_department_id']) ? (int)$item['sub_department_id'] : null;
                $category_id   = isset($item['category_id']) ? (int)$item['category_id'] : null;
                $color_id      = isset($item['color_id']) ? (int)$item['color_id'] : null;
                $size_id       = isset($item['size_id']) ? (int)$item['size_id'] : null;
                // Combined cost & selling – use same for master and PO
                $cost   = (float)($item['cost'] ?? 0);
                $sell   = (float)($item['selling'] ?? 0);
                $qty    = (int)($item['quantity'] ?? 0);
                $recv_qty = (int)($item['received_qty'] ?? 0);

                // Skip empty rows (must have item_code and name)
                if ($item_code === '' || $item_name === '') continue;

                // Check if item_code already exists
                $find_item = $conn->prepare("SELECT item_id FROM items WHERE item_code = ?");
                $find_item->bind_param("s", $item_code);
                $find_item->execute();
                $res = $find_item->get_result();
                if ($row = $res->fetch_assoc()) {
                    $item_id = $row['item_id'];
                    // Update master item details with combined cost/sell
                    $update_item = $conn->prepare("UPDATE items SET system_code = ?, item_name = ?, department_id = ?, sub_department_id = ?, category_id = ?, color_id = ?, size_id = ?, cost_price = ?, selling_price = ? WHERE item_id = ?");
                    $update_item->bind_param("ssiiiiiddi", $system_code, $item_name, $department_id, $sub_department_id, $category_id, $color_id, $size_id, $cost, $sell, $item_id);
                    $update_item->execute();
                    $update_item->close();
                } else {
                    // Insert new master item
                    $insert_item = $conn->prepare("INSERT INTO items (system_code, item_code, item_name, department_id, sub_department_id, category_id, color_id, size_id, cost_price, selling_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_item->bind_param("sssiiiiidd", $system_code, $item_code, $item_name, $department_id, $sub_department_id, $category_id, $color_id, $size_id, $cost, $sell);
                    $insert_item->execute();
                    $item_id = $insert_item->insert_id;
                    $insert_item->close();
                }
                $find_item->close();

                // Insert into po_items using the same cost/sell
                $insert_po_item->bind_param("iiiddi", $po_id, $item_id, $qty, $cost, $sell, $recv_qty);
                $insert_po_item->execute();
            }
            $insert_po_item->close();
        }

        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'PO updated successfully.';
    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = 'Update failed: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}
// ------------------------------------------------------------

// ------------------------------------------------------------
// Get search parameters
// ------------------------------------------------------------
$search_po       = isset($_GET['search_po']) ? trim($_GET['search_po']) : '';
$search_supplier = isset($_GET['search_supplier']) ? trim($_GET['search_supplier']) : '';
$date_from       = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to         = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$selected_po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

// ------------------------------------------------------------
// Build search query for POs (cross-database join using main connection)
// ------------------------------------------------------------
$search_results = [];
$show_search_results = false;
if (!empty($search_po) || !empty($search_supplier) || !empty($date_from) || !empty($date_to)) {
    $where = ["1=1"];
    $params = [];
    $types = "";
    if (!empty($search_po)) {
        $where[] = "h.po_number LIKE ?";
        $params[] = "%" . $search_po . "%";
        $types .= "s";
    }
    if (!empty($search_supplier)) {
        $where[] = "s.supplier_name LIKE ?";
        $params[] = "%" . $search_supplier . "%";
        $types .= "s";
    }
    if (!empty($date_from)) {
        $where[] = "h.purchase_date >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    if (!empty($date_to)) {
        $where[] = "h.purchase_date <= ?";
        $params[] = $date_to;
        $types .= "s";
    }

    $sql = "SELECT h.po_id, h.po_number, h.purchase_date, h.status, s.supplier_name
            FROM po_header h
            LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY h.purchase_date DESC";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $show_search_results = true;
}

// ------------------------------------------------------------
// Load PO data if selected
// ------------------------------------------------------------
$po_data = null;
$po_items = [];
$suppliers = [];
$departments = [];
$sub_departments = [];
$categories = [];
$colors = [];
$sizes = [];

if ($selected_po_id > 0) {
    // Load PO header (cross-database join)
    $header_sql = "SELECT h.*, s.supplier_name FROM po_header h LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id WHERE h.po_id = ?";
    $stmt = $conn->prepare($header_sql);
    $stmt->bind_param("i", $selected_po_id);
    $stmt->execute();
    $po_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($po_data) {
        // Load items with master item details
        $item_sql = "SELECT pi.*, i.system_code, i.item_code, i.item_name, i.department_id, i.sub_department_id, i.category_id, i.color_id, i.size_id
                     FROM po_items pi
                     JOIN items i ON pi.item_id = i.item_id
                     WHERE pi.po_id = ?
                     ORDER BY pi.po_item_id";
        $stmt = $conn->prepare($item_sql);
        $stmt->bind_param("i", $selected_po_id);
        $stmt->execute();
        $po_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Load all master dropdown data (for Select2 static options)

// ---- Suppliers (from return_qc using dedicated connection) ----
$supplier_sql = "SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name";
$supplier_res = $qcConn->query($supplier_sql);
$suppliers = ($supplier_res) ? $supplier_res->fetch_all(MYSQLI_ASSOC) : [];

// ---- Departments, sub-departments, categories, colors, sizes (all in po_system) ----
$dept_sql = "SELECT department_id, department_name FROM departments ORDER BY department_name";
$dept_res = $conn->query($dept_sql);
$departments = ($dept_res) ? $dept_res->fetch_all(MYSQLI_ASSOC) : [];

$subdept_sql = "SELECT sub_department_id, department_id, sub_department_name FROM sub_departments ORDER BY sub_department_name";
$subdept_res = $conn->query($subdept_sql);
$sub_departments = ($subdept_res) ? $subdept_res->fetch_all(MYSQLI_ASSOC) : [];

$cat_sql = "SELECT category_id, category_name FROM categories ORDER BY category_name";
$cat_res = $conn->query($cat_sql);
$categories = ($cat_res) ? $cat_res->fetch_all(MYSQLI_ASSOC) : [];

$color_sql = "SELECT color_id, color_name FROM colors ORDER BY color_name";
$color_res = $conn->query($color_sql);
$colors = ($color_res) ? $color_res->fetch_all(MYSQLI_ASSOC) : [];

$size_sql = "SELECT size_id, size_name FROM sizes ORDER BY size_name";
$size_res = $conn->query($size_sql);
$sizes = ($size_res) ? $size_res->fetch_all(MYSQLI_ASSOC) : [];

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<style>
    /* -------------------- General Styles -------------------- */
    body { background-color: #fbfbfb; font-family: 'Segoe UI', Arial, sans-serif; color: #333; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; margin-bottom: 25px; }
    .asb-card { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 25px; }
    .asb-card-header { background: #fff; border-bottom: 2px solid #eaeaea; padding: 15px 20px; color: #b71c1c; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
    .asb-table th { background: #f8f9fa !important; color: #444 !important; font-weight: 600 !important; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; padding: 10px !important; border-bottom: 2px solid #d32f2f !important; }
    .asb-table td { padding: 10px !important; vertical-align: middle !important; border-bottom: 1px solid #f0f0f0 !important; font-size: 12px; }
    .btn-asb { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
    .btn-asb:hover { background: #b71c1c; color: #fff; }
    .btn-asb-secondary { background: #f5f5f5; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 6px 12px; border-radius: 4px; font-size: 12px; text-decoration: none; }
    .btn-asb-secondary:hover { background: #e0e0e0; }
    .btn-asb-sm { padding: 4px 8px; font-size: 10px; }
    .btn-danger-sm { background: #dc3545; color: white; border: none; padding: 4px 8px; font-size: 10px; border-radius: 4px; }
    .btn-danger-sm:hover { background: #c82333; color: white; }
    .btn-success-sm { background: #28a745; color: white; border: none; padding: 4px 8px; font-size: 10px; border-radius: 4px; }
    .btn-success-sm:hover { background: #218838; color: white; }
    .asb-badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    .badge-completed { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
    .badge-received { background: #e3f2fd; color: #0d47a1; border: 1px solid #bbdefb; }
    .badge-pending { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
    .badge-cancelled { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
    .asb-input { border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; width: 100%; font-size: 12px; box-sizing: border-box; }
    .asb-input:focus { border-color: #d32f2f; outline: none; box-shadow: 0 0 4px rgba(211,47,47,0.2); }
    .asb-footer { text-align: center; margin-top: 40px; padding: 15px; color: #777; border-top: 1px solid #eee; font-size: 12px; }
    .asb-footer strong { color: #b71c1c; }
    .modal-mask { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:30px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .modal-content h4 { margin-top:0; color:#b71c1c; }
    .modal-content .form-group { margin-bottom:15px; }
    .modal-content .form-group label { display:block; font-weight:600; font-size:12px; margin-bottom:5px; }
    .modal-content .form-group input { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; }
    .modal-content .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }
    .edit-form .form-row { display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
    .edit-form .form-group { flex: 1; min-width: 150px; }
    .edit-form .form-group label { display: block; font-weight: 600; font-size: 11px; color: #555; margin-bottom: 4px; }

    /* -------------------- Enhanced Item Table Styles -------------------- */
    .item-table {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
        font-size: 13px;
        background: #fff;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .item-table thead {
        position: sticky;
        top: 0;
        z-index: 10;
    }
    .item-table th {
        background: #f1f3f5 !important;
        color: #333 !important;
        font-weight: 700 !important;
        font-size: 10px !important;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        padding: 8px 6px !important;
        border-bottom: 2px solid #d32f2f !important;
        white-space: nowrap;
        text-align: left;
    }
    .item-table td {
        padding: 6px 4px !important;
        vertical-align: middle;
        border-bottom: 1px solid #e9ecef;
    }
    .item-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    .item-table tbody tr:nth-child(even) {
        background-color: #fcfcfc;
    }
    .item-table input,
    .item-table select {
        padding: 6px 8px !important;
        font-size: 12px !important;
        border: 1px solid #ced4da;
        border-radius: 4px;
        background: #fff;
        width: 100%;
        box-sizing: border-box;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .item-table input:focus,
    .item-table select:focus {
        border-color: #d32f2f;
        outline: 0;
        box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.15);
    }
    .item-table input::placeholder {
        color: #aaa;
        font-style: italic;
    }
    .item-table .action-col {
        text-align: center;
        width: 50px;
        min-width: 50px;
    }
    .item-table .remove-row {
        background: #dc3545;
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 28px;
        height: 28px;
        line-height: 28px;
        text-align: center;
        font-size: 12px;
        cursor: pointer;
        transition: background 0.2s;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .item-table .remove-row:hover {
        background: #c82333;
    }
    .item-table .add-row-btn {
        margin-top: 10px;
        background: #28a745;
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 12px;
        cursor: pointer;
        transition: background 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .item-table .add-row-btn:hover {
        background: #218838;
    }
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .item-table .col-system { min-width: 80px; }
    .item-table .col-code { min-width: 100px; }
    .item-table .col-name { min-width: 140px; }
    .item-table .col-dept { min-width: 100px; }
    .item-table .col-subdept { min-width: 100px; }
    .item-table .col-cat { min-width: 90px; }
    .item-table .col-color { min-width: 80px; }
    .item-table .col-size { min-width: 70px; }
    .item-table .col-cost { min-width: 80px; }
    .item-table .col-sell { min-width: 80px; }
    .item-table .col-qty { min-width: 70px; }
    .item-table .col-recv { min-width: 70px; }

    .select2-container .select2-selection--single { height: 32px; border-color: #ccc; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 32px; padding-left: 8px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">

    <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Purchase Order Editor</span></h2>

    <!-- ==================== SEARCH PANEL ==================== -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span><i class="fas fa-search"></i> Locate PO to Edit</span>
        </div>
        <div style="padding: 15px 20px;">
            <form method="GET" action="">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: flex-end;">
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">PO Number</label>
                        <input type="text" name="search_po" class="asb-input" placeholder="Ex: PO-2026-001" value="<?= htmlspecialchars($search_po); ?>">
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Supplier Name</label>
                        <input type="text" name="search_supplier" class="asb-input" placeholder="Search Vendor..." value="<?= htmlspecialchars($search_supplier); ?>">
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Date From</label>
                        <input type="date" name="date_from" class="asb-input" value="<?= htmlspecialchars($date_from); ?>">
                    </div>
                    <div>
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Date To</label>
                        <input type="date" name="date_to" class="asb-input" value="<?= htmlspecialchars($date_to); ?>">
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn-asb" style="padding: 9px 16px; flex: 1; justify-content: center;"><i class="fas fa-filter"></i> Search</button>
                        <a href="edit_po.php" class="btn-asb-secondary" style="padding: 8px 12px; display: inline-flex; align-items: center;">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== SEARCH RESULTS ==================== -->
    <?php if ($show_search_results): ?>
        <div class="asb-card">
            <div class="asb-card-header">
                <span>Search Results</span>
                <span style="font-size:11px; background:#b71c1c; color:#fff; padding:2px 8px; border-radius:10px;"><?= count($search_results); ?> found</span>
            </div>
            <div style="padding: 0;">
                <?php if (empty($search_results)): ?>
                    <div style="text-align:center; padding:20px; color:#888;">No POs match your criteria.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table asb-table">
                            <thead>
                                <tr>
                                    <th>PO Number</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th style="text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($search_results as $row): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($row['po_number']); ?></strong></td>
                                        <td><?= htmlspecialchars($row['supplier_name'] ?? 'Unknown'); ?></td>
                                        <td><?= date('Y-m-d', strtotime($row['purchase_date'])); ?></td>
                                        <td><span class="asb-badge <?= 'badge-' . strtolower($row['status']); ?>"><?= $row['status']; ?></span></td>
                                        <td style="text-align:center;">
                                            <a href="edit_po.php?po_id=<?= $row['po_id']; ?>" class="btn-asb btn-asb-sm"><i class="fas fa-edit"></i> Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ==================== EDIT FORM (only when PO selected) ==================== -->
    <?php if ($po_data): ?>
        <div class="asb-card">
            <div class="asb-card-header">
                <span><i class="fas fa-pen"></i> Editing PO: <?= htmlspecialchars($po_data['po_number']); ?></span>
                <span style="font-size:11px; background:#b71c1c; color:#fff; padding:2px 8px; border-radius:10px;">ID: <?= $po_data['po_id']; ?></span>
            </div>
            <div style="padding: 20px;">
                <form id="editPoForm" class="edit-form">
                    <input type="hidden" name="po_id" value="<?= $po_data['po_id']; ?>">

                    <!-- Header fields -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="supplier_id">Supplier *</label>
                            <select name="supplier_id" id="supplier_id" class="asb-input" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['supplier_id']; ?>" <?= ($sup['supplier_id'] == $po_data['supplier_id']) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($sup['supplier_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="purchase_date">Purchase Date *</label>
                            <input type="date" name="purchase_date" id="purchase_date" class="asb-input" value="<?= $po_data['purchase_date']; ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="expected_delivery_date">Expected Delivery</label>
                            <input type="date" name="expected_delivery_date" id="expected_delivery_date" class="asb-input" value="<?= $po_data['expected_delivery_date']; ?>">
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="asb-input">
                                <option value="Pending" <?= $po_data['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Received" <?= $po_data['status'] == 'Received' ? 'selected' : ''; ?>>Received</option>
                                <option value="Completed" <?= $po_data['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?= $po_data['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="attention">Attention</label>
                            <input type="text" name="attention" id="attention" class="asb-input" value="<?= htmlspecialchars($po_data['attention'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="remarks">Remarks</label>
                            <textarea name="remarks" id="remarks" class="asb-input" rows="2"><?= htmlspecialchars($po_data['remarks'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- ==================== ENHANCED ITEMS SECTION ==================== -->
                    <div style="margin-top: 30px;">
                        <h5 style="color:#b71c1c; font-weight:bold; margin-bottom:15px;">
                            <i class="fas fa-list"></i> Line Items
                            <span style="font-size:12px; font-weight:normal; color:#888; margin-left:10px;">
                                <i class="fas fa-info-circle" title="Cost & Selling apply to both the master item and this PO."></i>
                            </span>
                        </h5>
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="item-table" id="itemsTable">
                                <thead>
                                    <tr>
                                        <th class="col-system">System<br>Code</th>
                                        <th class="col-code">Item Code *</th>
                                        <th class="col-name">Item Name *</th>
                                        <th class="col-dept">Dept</th>
                                        <th class="col-subdept">Sub Dept</th>
                                        <th class="col-cat">Category</th>
                                        <th class="col-color">Color</th>
                                        <th class="col-size">Size</th>
                                        <th class="col-cost">Cost</th>
                                        <th class="col-sell">Selling</th>
                                        <th class="col-qty">Qty</th>
                                        <th class="col-recv">Recv</th>
                                        <th class="action-col">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody">
                                    <?php if (empty($po_items)): ?>
                                        <tr class="item-row">
                                            <td><input type="text" name="items[0][system_code]" placeholder="SysCode" title="System Code"></td>
                                            <td>
                                                <input type="text" name="items[0][item_code]" class="item_code_input" placeholder="Item Code" required title="Item Code">
                                                <select name="items[0][item_search]" class="item-search" style="width:100%; margin-top:4px;">
                                                    <option value="">-- Search & Auto‑fill --</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" name="items[0][item_name]" class="item_name_input" placeholder="Item Name" required title="Item Name">
                                                <select name="items[0][suggestion]" class="name-suggestion" style="width:100%; margin-top:4px;">
                                                    <option value="">-- Suggested Names --</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="items[0][department_id]" class="dept-select" style="width:100%;">
                                                    <option value="">--</option>
                                                    <?php foreach ($departments as $d): ?>
                                                        <option value="<?= $d['department_id']; ?>"><?= htmlspecialchars($d['department_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="items[0][sub_department_id]" class="subdept-select" style="width:100%;">
                                                    <option value="">--</option>
                                                    <?php foreach ($sub_departments as $sd): ?>
                                                        <option value="<?= $sd['sub_department_id']; ?>"><?= htmlspecialchars($sd['sub_department_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="items[0][category_id]" class="cat-select" style="width:100%;">
                                                    <option value="">--</option>
                                                    <?php foreach ($categories as $c): ?>
                                                        <option value="<?= $c['category_id']; ?>"><?= htmlspecialchars($c['category_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="items[0][color_id]" class="color-select" style="width:100%;">
                                                    <option value="">--</option>
                                                    <?php foreach ($colors as $col): ?>
                                                        <option value="<?= $col['color_id']; ?>"><?= htmlspecialchars($col['color_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="items[0][size_id]" class="size-select" style="width:100%;">
                                                    <option value="">--</option>
                                                    <?php foreach ($sizes as $sz): ?>
                                                        <option value="<?= $sz['size_id']; ?>"><?= htmlspecialchars($sz['size_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="number" step="0.01" name="items[0][cost]" placeholder="0.00" class="asb-input"></td>
                                            <td><input type="number" step="0.01" name="items[0][selling]" placeholder="0.00" class="asb-input"></td>
                                            <td><input type="number" name="items[0][quantity]" placeholder="Qty" min="0" class="asb-input"></td>
                                            <td><input type="number" name="items[0][received_qty]" placeholder="Recv" min="0" class="asb-input"></td>
                                            <td class="action-col">
                                                <button type="button" class="remove-row" title="Remove"><i class="fas fa-times"></i></button>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $rowIndex = 0; foreach ($po_items as $item): ?>
                                            <tr class="item-row">
                                                <td><input type="text" name="items[<?= $rowIndex; ?>][system_code]" value="<?= htmlspecialchars($item['system_code'] ?? ''); ?>" placeholder="SysCode" title="System Code"></td>
                                                <td>
                                                    <input type="text" name="items[<?= $rowIndex; ?>][item_code]" class="item_code_input" value="<?= htmlspecialchars($item['item_code']); ?>" required placeholder="Item Code" title="Item Code">
                                                    <select name="items[<?= $rowIndex; ?>][item_search]" class="item-search" style="width:100%; margin-top:4px;">
                                                        <option value="">-- Search & Auto‑fill --</option>
                                                        <?php if (!empty($item['item_code'])): ?>
                                                            <option value="<?= $item['item_id']; ?>" selected><?= htmlspecialchars($item['item_code'] . ' - ' . $item['item_name']); ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="text" name="items[<?= $rowIndex; ?>][item_name]" class="item_name_input" value="<?= htmlspecialchars($item['item_name']); ?>" required placeholder="Item Name" title="Item Name">
                                                    <select name="items[<?= $rowIndex; ?>][suggestion]" class="name-suggestion" style="width:100%; margin-top:4px;">
                                                        <option value="">-- Suggested Names --</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="items[<?= $rowIndex; ?>][department_id]" class="dept-select" style="width:100%;">
                                                        <option value="">--</option>
                                                        <?php foreach ($departments as $d): ?>
                                                            <option value="<?= $d['department_id']; ?>" <?= ($d['department_id'] == $item['department_id']) ? 'selected' : ''; ?>><?= htmlspecialchars($d['department_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="items[<?= $rowIndex; ?>][sub_department_id]" class="subdept-select" style="width:100%;">
                                                        <option value="">--</option>
                                                        <?php foreach ($sub_departments as $sd): ?>
                                                            <option value="<?= $sd['sub_department_id']; ?>" <?= ($sd['sub_department_id'] == $item['sub_department_id']) ? 'selected' : ''; ?>><?= htmlspecialchars($sd['sub_department_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="items[<?= $rowIndex; ?>][category_id]" class="cat-select" style="width:100%;">
                                                        <option value="">--</option>
                                                        <?php foreach ($categories as $c): ?>
                                                            <option value="<?= $c['category_id']; ?>" <?= ($c['category_id'] == $item['category_id']) ? 'selected' : ''; ?>><?= htmlspecialchars($c['category_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="items[<?= $rowIndex; ?>][color_id]" class="color-select" style="width:100%;">
                                                        <option value="">--</option>
                                                        <?php foreach ($colors as $col): ?>
                                                            <option value="<?= $col['color_id']; ?>" <?= ($col['color_id'] == $item['color_id']) ? 'selected' : ''; ?>><?= htmlspecialchars($col['color_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="items[<?= $rowIndex; ?>][size_id]" class="size-select" style="width:100%;">
                                                        <option value="">--</option>
                                                        <?php foreach ($sizes as $sz): ?>
                                                            <option value="<?= $sz['size_id']; ?>" <?= ($sz['size_id'] == $item['size_id']) ? 'selected' : ''; ?>><?= htmlspecialchars($sz['size_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td><input type="number" step="0.01" name="items[<?= $rowIndex; ?>][cost]" value="<?= number_format($item['cost_price'], 2); ?>" placeholder="0.00" class="asb-input"></td>
                                                <td><input type="number" step="0.01" name="items[<?= $rowIndex; ?>][selling]" value="<?= number_format($item['selling_price'], 2); ?>" placeholder="0.00" class="asb-input"></td>
                                                <td><input type="number" name="items[<?= $rowIndex; ?>][quantity]" value="<?= $item['quantity']; ?>" min="0" placeholder="Qty" class="asb-input"></td>
                                                <td><input type="number" name="items[<?= $rowIndex; ?>][received_qty]" value="<?= $item['received_qty']; ?>" min="0" placeholder="Recv" class="asb-input"></td>
                                                <td class="action-col">
                                                    <button type="button" class="remove-row" title="Remove"><i class="fas fa-times"></i></button>
                                                </td>
                                            </tr>
                                        <?php $rowIndex++; endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="button" id="addRowBtn" class="add-row-btn"><i class="fas fa-plus"></i> Add Item</button>
                    </div>
                    <!-- ==================== END ITEMS SECTION ==================== -->

                    <!-- Save button -->
                    <div style="margin-top: 25px; text-align: right;">
                        <a href="edit_po.php" class="btn-asb-secondary">Cancel</a>
                        <button type="button" id="savePoBtn" class="btn-asb" style="padding: 10px 24px;"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==================== PASSWORD MODAL ==================== -->
        <div id="passwordModal" class="modal-mask">
            <div class="modal-content">
                <h4><i class="fas fa-lock"></i> Manager Authorization</h4>
                <p style="font-size:12px; color:#666;">Enter the manager password to confirm these changes.</p>
                <div class="form-group">
                    <label for="modalPassword">Password</label>
                    <input type="password" id="modalPassword" class="asb-input" placeholder="Enter manager password">
                </div>
                <div id="modalError" style="color:#d32f2f; font-size:12px; margin-bottom:10px;"></div>
                <div class="modal-actions">
                    <button type="button" class="btn-asb-secondary" onclick="closeModal()">Cancel</button>
                    <button type="button" class="btn-asb" onclick="submitSave()">Confirm Save</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ==================== FOOTER ==================== -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:4px; display:inline-block; color:#aaa;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>
</div>

<!-- ==================== JAVASCRIPT ==================== -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
$(document).ready(function() {

    // ─── AJAX Select2 helpers ───
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
                    if (extraData) {
                        $.extend(data, extraData);
                    }
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

    // ─── Initialize Select2 for all dropdowns in a row ───
    function initRowSelects($row) {
        var $deptSelect = $row.find('.dept-select');
        var $itemSelect = $row.find('.item-search');
        var $suggestionSelect = $row.find('.name-suggestion');

        // Get current supplier ID (from header)
        function getSupplierId() {
            return $('#supplier_id').val() || '';
        }

        // Item search (from items table, filtered by supplier and dept)
        $itemSelect.select2({
            ajax: {
                url: window.location.href,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    var data = {
                        ajax_action: 'get_items',
                        search: params.term || '',
                        page: params.page || 1
                    };
                    var deptId = $deptSelect.val();
                    if (deptId) data.department_id = deptId;
                    var supId = getSupplierId();
                    if (supId) data.supplier_id = supId;
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
            placeholder: 'Search item...',
            minimumInputLength: 0,
            allowClear: true,
            templateResult: function(item) { return item.loading ? item.text : item.text; },
            templateSelection: function(item) { return item.text || 'Search item...'; }
        }).on('select2:select', function(e) {
            var data = e.params.data;
            var $row = $(this).closest('tr');
            $row.find('input[name*="[system_code]"]').val(data.system_code || '');
            $row.find('.item_code_input').val(data.item_code || '');
            $row.find('.item_name_input').val(data.item_name || '');
            setSelectValue($row.find('.dept-select'), data.department_id, data.department_name);
            setSelectValue($row.find('.subdept-select'), data.sub_department_id, data.sub_department_name);
            setSelectValue($row.find('.cat-select'), data.category_id, data.category_name);
            setSelectValue($row.find('.color-select'), data.color_id, data.color_name);
            setSelectValue($row.find('.size-select'), data.size_id, data.size_name);
            // Fill cost & selling from master values
            $row.find('input[name*="[cost]"]').val(data.master_cost_price || 0);
            $row.find('input[name*="[selling]"]').val(data.master_selling_price || 0);
        });

        // When supplier changes globally, reset item search for all rows
        $('#supplier_id').on('change', function() {
            $('.item-search').each(function() {
                $(this).val(null).trigger('change');
            });
        });

        // Name suggestion (from item_name_suggestions, filtered by department)
        function refreshSuggestions() {
            var deptId = $deptSelect.val() || '';
            $suggestionSelect.select2({
                ajax: {
                    url: window.location.href,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            ajax_action: 'get_name_suggestions',
                            search: params.term || '',
                            page: params.page || 1,
                            department_id: deptId
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
                placeholder: 'Search suggestions...',
                minimumInputLength: 0,
                allowClear: true,
                templateResult: function(item) { return item.loading ? item.text : item.text; },
                templateSelection: function(item) { return item.text || 'Suggestions...'; }
            }).on('select2:select', function(e) {
                var data = e.params.data;
                var $row = $(this).closest('tr');
                $row.find('.item_name_input').val(data.text);
            });
            $suggestionSelect.val(null).trigger('change');
        }

        // When department changes, refresh suggestions and item search
        $deptSelect.on('change', function() {
            refreshSuggestions();
            $itemSelect.val(null).trigger('change');
        });

        // Initial suggestion load
        refreshSuggestions();

        // Other selects (department etc.) – use static options but with Select2 for search
        $row.find('.dept-select').select2({
            placeholder: 'Search dept...',
            allowClear: true
        });
        $row.find('.subdept-select').select2({
            placeholder: 'Search sub dept...',
            allowClear: true
        });
        $row.find('.cat-select').select2({
            placeholder: 'Search category...',
            allowClear: true
        });
        $row.find('.color-select').select2({
            placeholder: 'Search color...',
            allowClear: true
        });
        $row.find('.size-select').select2({
            placeholder: 'Search size...',
            allowClear: true
        });
    }

    function setSelectValue($select, value, text) {
        if (value) {
            var option = new Option(text, value, true, true);
            $select.append(option).trigger('change');
            $select.val(value).trigger('change');
        } else {
            $select.val(null).trigger('change');
        }
    }

    // ─── Initialize existing rows ───
    $('#itemsBody tr.item-row').each(function() {
        initRowSelects($(this));
    });

    // ─── Add Row ───
    var rowIndex = <?= count($po_items) ?: 0; ?>;
    $('#addRowBtn').on('click', function() {
        var newIndex = rowIndex++;
        var newRowHtml = `
            <tr class="item-row">
                <td><input type="text" name="items[${newIndex}][system_code]" placeholder="SysCode" title="System Code"></td>
                <td>
                    <input type="text" name="items[${newIndex}][item_code]" class="item_code_input" placeholder="Item Code" required title="Item Code">
                    <select name="items[${newIndex}][item_search]" class="item-search" style="width:100%; margin-top:4px;">
                        <option value="">-- Search & Auto‑fill --</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="items[${newIndex}][item_name]" class="item_name_input" placeholder="Item Name" required title="Item Name">
                    <select name="items[${newIndex}][suggestion]" class="name-suggestion" style="width:100%; margin-top:4px;">
                        <option value="">-- Suggested Names --</option>
                    </select>
                </td>
                <td>
                    <select name="items[${newIndex}][department_id]" class="dept-select" style="width:100%;">
                        <option value="">--</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['department_id']; ?>"><?= htmlspecialchars($d['department_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="items[${newIndex}][sub_department_id]" class="subdept-select" style="width:100%;">
                        <option value="">--</option>
                        <?php foreach ($sub_departments as $sd): ?>
                            <option value="<?= $sd['sub_department_id']; ?>"><?= htmlspecialchars($sd['sub_department_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="items[${newIndex}][category_id]" class="cat-select" style="width:100%;">
                        <option value="">--</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['category_id']; ?>"><?= htmlspecialchars($c['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="items[${newIndex}][color_id]" class="color-select" style="width:100%;">
                        <option value="">--</option>
                        <?php foreach ($colors as $col): ?>
                            <option value="<?= $col['color_id']; ?>"><?= htmlspecialchars($col['color_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <select name="items[${newIndex}][size_id]" class="size-select" style="width:100%;">
                        <option value="">--</option>
                        <?php foreach ($sizes as $sz): ?>
                            <option value="<?= $sz['size_id']; ?>"><?= htmlspecialchars($sz['size_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" step="0.01" name="items[${newIndex}][cost]" placeholder="0.00" class="asb-input"></td>
                <td><input type="number" step="0.01" name="items[${newIndex}][selling]" placeholder="0.00" class="asb-input"></td>
                <td><input type="number" name="items[${newIndex}][quantity]" placeholder="Qty" min="0" class="asb-input"></td>
                <td><input type="number" name="items[${newIndex}][received_qty]" placeholder="Recv" min="0" class="asb-input"></td>
                <td class="action-col">
                    <button type="button" class="remove-row" title="Remove"><i class="fas fa-times"></i></button>
                </td>
            </tr>
        `;
        var $newRow = $(newRowHtml);
        $('#itemsBody').append($newRow);
        initRowSelects($newRow);
    });

    // ─── Remove Row ───
    $(document).on('click', '.remove-row', function() {
        var $row = $(this).closest('tr');
        if ($('#itemsBody tr').length > 1) {
            $row.remove();
        } else {
            alert('You must keep at least one item row.');
        }
    });

    // ─── Save button triggers modal ───
    $('#savePoBtn').on('click', function() {
        var supplier = $('#supplier_id').val();
        var date = $('#purchase_date').val();
        if (!supplier || !date) {
            alert('Please fill in Supplier and Purchase Date.');
            return;
        }
        var valid = false;
        $('#itemsBody tr').each(function() {
            var $row = $(this);
            var code = $row.find('.item_code_input').val().trim();
            var name = $row.find('.item_name_input').val().trim();
            if (code && name) valid = true;
        });
        if (!valid) {
            alert('Please add at least one item with a code and name.');
            return;
        }

        $('#passwordModal').css('display', 'flex');
        $('#modalPassword').val('');
        $('#modalError').text('');
    });

    // ─── Modal functions ───
    window.closeModal = function() {
        $('#passwordModal').css('display', 'none');
    };

    window.submitSave = function() {
        var password = $('#modalPassword').val().trim();
        if (!password) {
            $('#modalError').text('Please enter the manager password.');
            return;
        }

        var form = document.getElementById('editPoForm');
        var formData = new FormData(form);
        formData.append('ajax_action', 'save_po');
        formData.append('manager_password', password);

        // Rebuild items array from DOM
        var items = [];
        $('#itemsBody tr.item-row').each(function(idx) {
            var $row = $(this);
            var code = $row.find('.item_code_input').val().trim();
            var name = $row.find('.item_name_input').val().trim();
            if (!code || !name) return; // skip empty rows

            items.push({
                system_code: $row.find('input[name*="[system_code]"]').val() || '',
                item_code: code,
                item_name: name,
                department_id: $row.find('.dept-select').val() || '',
                sub_department_id: $row.find('.subdept-select').val() || '',
                category_id: $row.find('.cat-select').val() || '',
                color_id: $row.find('.color-select').val() || '',
                size_id: $row.find('.size-select').val() || '',
                cost: parseFloat($row.find('input[name*="[cost]"]').val()) || 0,
                selling: parseFloat($row.find('input[name*="[selling]"]').val()) || 0,
                quantity: parseInt($row.find('input[name*="[quantity]"]').val()) || 0,
                received_qty: parseInt($row.find('input[name*="[received_qty]"]').val()) || 0
            });
        });

        // Remove existing 'items' entries and append rebuilt
        formData.delete('items');
        items.forEach(function(item, idx) {
            formData.append(`items[${idx}][system_code]`, item.system_code);
            formData.append(`items[${idx}][item_code]`, item.item_code);
            formData.append(`items[${idx}][item_name]`, item.item_name);
            formData.append(`items[${idx}][department_id]`, item.department_id);
            formData.append(`items[${idx}][sub_department_id]`, item.sub_department_id);
            formData.append(`items[${idx}][category_id]`, item.category_id);
            formData.append(`items[${idx}][color_id]`, item.color_id);
            formData.append(`items[${idx}][size_id]`, item.size_id);
            formData.append(`items[${idx}][cost]`, item.cost);
            formData.append(`items[${idx}][selling]`, item.selling);
            formData.append(`items[${idx}][quantity]`, item.quantity);
            formData.append(`items[${idx}][received_qty]`, item.received_qty);
        });

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.href = 'edit_po.php?po_id=<?= $po_data['po_id']; ?>';
            } else {
                $('#modalError').text(data.message);
            }
        })
        .catch(error => {
            $('#modalError').text('An error occurred: ' + error);
        });
    };

    // Close modal on background click
    $('#passwordModal').on('click', function(e) {
        if (e.target === this) closeModal();
    });
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>