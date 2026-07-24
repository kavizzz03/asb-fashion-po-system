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

    // Build base query with allocation summary
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
// AJAX: get PO summary report (branch-wise, company totals, remaining)
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_po_summary_report') {
    header('Content-Type: application/json');
    $po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
    if (!$po_id) {
        echo json_encode(['error' => 'Invalid PO ID']);
        exit;
    }

    // PO header
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

    // All branches with company info
    $branch_sql = "SELECT l.location_id, l.location_name, c.company_id, c.company_name
                   FROM store_locations l
                   LEFT JOIN companies c ON l.company_id = c.company_id
                   ORDER BY c.company_name, l.location_name";
    $branches = $conn->query($branch_sql)->fetch_all(MYSQLI_ASSOC);

    // Items with allocations per branch
    $items_sql = "SELECT pi.po_item_id, pi.item_id, pi.quantity AS po_qty,
                         i.item_code, i.item_name,
                         (SELECT COALESCE(SUM(quantity),0) FROM po_item_allocations WHERE po_item_id = pi.po_item_id) AS total_allocated
                  FROM po_items pi
                  JOIN items i ON pi.item_id = i.item_id
                  WHERE pi.po_id = ?
                  ORDER BY pi.po_item_id";
    $stmt = $conn->prepare($items_sql);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get allocation per item per branch
    $alloc_sql = "SELECT a.po_item_id, a.location_id, a.quantity
                  FROM po_item_allocations a
                  JOIN po_items pi ON a.po_item_id = pi.po_item_id
                  WHERE pi.po_id = ?";
    $stmt = $conn->prepare($alloc_sql);
    $stmt->bind_param("i", $po_id);
    $stmt->execute();
    $alloc_res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $alloc_map = [];
    foreach ($alloc_res as $row) {
        $alloc_map[$row['po_item_id']][$row['location_id']] = (int)$row['quantity'];
    }

    // Build response
    $response = [
        'header' => $header,
        'branches' => $branches,
        'items' => []
    ];

    foreach ($items as $item) {
        $po_item_id = $item['po_item_id'];
        $branch_qty = [];
        foreach ($branches as $b) {
            $branch_qty[$b['location_id']] = $alloc_map[$po_item_id][$b['location_id']] ?? 0;
        }
        $response['items'][] = [
            'item_code' => $item['item_code'],
            'item_name' => $item['item_name'],
            'po_qty' => (int)$item['po_qty'],
            'total_allocated' => (int)$item['total_allocated'],
            'remaining' => (int)$item['po_qty'] - (int)$item['total_allocated'],
            'branches' => $branch_qty
        ];
    }

    echo json_encode($response);
    exit;
}

// ------------------------------------------------------------
// AJAX: get company-wise summary for filtered POs
// ------------------------------------------------------------
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_company_summary') {
    header('Content-Type: application/json');

    $search_po = isset($_GET['search_po']) ? trim($_GET['search_po']) : '';
    $search_supplier = isset($_GET['search_supplier']) ? trim($_GET['search_supplier']) : '';
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $alloc_status = isset($_GET['alloc_status']) ? $_GET['alloc_status'] : '';

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

    $where_clause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT
                c.company_id,
                c.company_name,
                COALESCE(SUM(a.quantity), 0) AS total_qty,
                COALESCE(SUM(a.quantity * pi.cost_price), 0) AS total_cost,
                COALESCE(SUM(a.quantity * pi.selling_price), 0) AS total_selling
            FROM po_item_allocations a
            JOIN po_items pi ON a.po_item_id = pi.po_item_id
            JOIN po_header h ON pi.po_id = h.po_id
            LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id
            JOIN store_locations l ON a.location_id = l.location_id
            LEFT JOIN companies c ON l.company_id = c.company_id
            $where_clause
            GROUP BY c.company_id, c.company_name
            ORDER BY c.company_name";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Handle alloc_status filter
    if (!empty($alloc_status)) {
        $po_sql = "SELECT h.po_id,
                   (SELECT COALESCE(SUM(quantity),0) FROM po_items WHERE po_id = h.po_id) AS total_qty,
                   (SELECT COALESCE(SUM(a.quantity),0) FROM po_item_allocations a JOIN po_items pi ON a.po_item_id = pi.po_item_id WHERE pi.po_id = h.po_id) AS allocated_qty
            FROM po_header h
            LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id
            $where_clause";
        $po_stmt = $conn->prepare($po_sql);
        if (!empty($params)) {
            $po_stmt->bind_param($types, ...$params);
        }
        $po_stmt->execute();
        $po_rows = $po_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $matching_po_ids = [];
        foreach ($po_rows as $row) {
            $total_qty = (int)$row['total_qty'];
            $allocated_qty = (int)$row['allocated_qty'];
            if ($total_qty == 0) {
                $status_text = 'No Items';
            } elseif ($allocated_qty >= $total_qty) {
                $status_text = 'Fully Allocated';
            } elseif ($allocated_qty > 0) {
                $status_text = 'Partially Allocated';
            } else {
                $status_text = 'Not Allocated';
            }
            if ($status_text == $alloc_status) {
                $matching_po_ids[] = $row['po_id'];
            }
        }

        if (empty($matching_po_ids)) {
            echo json_encode(['results' => []]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($matching_po_ids), '?'));
        $sql = "SELECT
                    c.company_id,
                    c.company_name,
                    COALESCE(SUM(a.quantity), 0) AS total_qty,
                    COALESCE(SUM(a.quantity * pi.cost_price), 0) AS total_cost,
                    COALESCE(SUM(a.quantity * pi.selling_price), 0) AS total_selling
                FROM po_item_allocations a
                JOIN po_items pi ON a.po_item_id = pi.po_item_id
                JOIN po_header h ON pi.po_id = h.po_id
                JOIN store_locations l ON a.location_id = l.location_id
                LEFT JOIN companies c ON l.company_id = c.company_id
                WHERE h.po_id IN ($placeholders)
                GROUP BY c.company_id, c.company_name
                ORDER BY c.company_name";

        $stmt = $conn->prepare($sql);
        $types_in = str_repeat('i', count($matching_po_ids));
        $stmt->bind_param($types_in, ...$matching_po_ids);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    echo json_encode(['results' => $summary]);
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

    .allocation-table th { background: #f1f3f5; font-size: 10px; text-transform: uppercase; border-bottom: 2px solid #d32f2f; padding: 6px; }
    .allocation-table td { padding: 4px; vertical-align: middle; }
    .allocation-table input[type="number"] { width: 60px; padding: 4px; border: 1px solid #ccc; border-radius: 4px; text-align: center; }
    .allocation-table .company-header { background: #e9ecef; font-weight: bold; }
    .available-qty { font-size: 11px; color: #666; }

    .po-table th { background: #f8f9fa; border-bottom: 2px solid #d32f2f; font-size: 10px; text-transform: uppercase; padding: 8px; }
    .po-table td { padding: 8px; vertical-align: middle; font-size: 12px; border-bottom: 1px solid #e9ecef; }
    .po-table .status-badge { min-width: 100px; display: inline-block; text-align: center; }

    .select2-container--default .select2-selection--single { height: 34px; border-color: #ccc; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 34px; padding-left: 12px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 34px; }

    .summary-table th { background: #f1f3f5; border-bottom: 2px solid #d32f2f; font-size: 10px; text-transform: uppercase; padding: 8px; }
    .summary-table td { padding: 8px; font-size: 12px; border-bottom: 1px solid #e9ecef; }
    .summary-table .grand-total { background: #fde8e4; font-weight: bold; }
    .summary-card { margin-top: 20px; display: none; }
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
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" id="applyFilters" class="btn-asb"><i class="fas fa-filter"></i> Apply</button>
                        <button type="button" id="resetFilters" class="btn-asb-secondary">Reset</button>
                        <button type="button" id="showSummaryBtn" class="btn-asb" style="background: #2c3e50;"><i class="fas fa-chart-bar"></i> Company Summary</button>
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
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:center;">Actions</th>
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

    <!-- ==================== COMPANY SUMMARY CARD ==================== -->
    <div class="asb-card summary-card" id="summaryCard">
        <div class="asb-card-header">
            <span><i class="fas fa-building"></i> Company-wise Allocation Summary</span>
            <span style="font-size:11px; color:#888;">Total allocated quantities per company</span>
        </div>
        <div style="padding: 0;">
            <div class="table-responsive">
                <table class="table summary-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th style="text-align:right;">Total Qty Allocated</th>
                            <th style="text-align:right;">Total Cost (LKR)</th>
                            <th style="text-align:right;">Total Selling (LKR)</th>
                            <th style="text-align:right;">Profit (LKR)</th>
                        </tr>
                    </thead>
                    <tbody id="summaryBody">
                        <tr><td colspan="5" style="text-align:center; padding:20px; color:#888;">No data. Apply filters and click "Company Summary".</td></tr>
                    </tbody>
                    <tfoot id="summaryFooter" style="display:none;">
                        <tr class="grand-total">
                            <td><strong>GRAND TOTAL</strong></td>
                            <td style="text-align:right;" id="grandQty">0</td>
                            <td style="text-align:right;" id="grandCost">0.00</td>
                            <td style="text-align:right;" id="grandSell">0.00</td>
                            <td style="text-align:right;" id="grandProfit">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ==================== ALLOCATION MODAL ==================== -->
    <div id="allocationModal" class="modal-mask">
        <div class="modal-content">
            <h4 id="modalTitle"><i class="fas fa-tasks"></i> Allocate Items</h4>
            <div id="modalBody">
                <div style="text-align:center; padding:20px;">Loading...</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-asb-secondary" onclick="closeModal()">Close</button>
                <button type="button" id="saveAllocBtn" class="btn-asb"><i class="fas fa-save"></i> Save Allocations</button>
            </div>
            <div id="modalError" style="color:#d32f2f; font-size:12px; margin-top:10px;"></div>
        </div>
    </div>

    <!-- ==================== SUMMARY REPORT MODAL ==================== -->
    <div id="reportModal" class="modal-mask">
        <div class="modal-content" style="max-width:98%; width:98%;">
            <h4 id="reportTitle"><i class="fas fa-file-alt"></i> PO Allocation Summary</h4>
            <div id="reportBody">
                <div style="text-align:center; padding:20px;">Loading...</div>
            </div>
            <div class="modal-actions">
                <button type="button" id="printReportBtn" class="btn-asb-secondary"><i class="fas fa-print"></i> Print Report</button>
                <button type="button" class="btn-asb-secondary" onclick="closeReportModal()">Close</button>
            </div>
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

    $('#po_search').select2(ajaxSelect2('get_po_suggestions', 'Search PO number...'));
    $('#supplier_search').select2(ajaxSelect2('get_suppliers', 'Search supplier...'));

    // ---- State ----
    var currentPage = 1;
    var totalPages = 1;
    var totalRecords = 0;
    var isLoading = false;
    var currentPoId = 0;

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
                $('#summaryCard').hide();
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
            html += '<td style="text-align:center;">';
            html += '<button class="btn-asb btn-asb-sm allocate-btn" data-po-id="' + row.po_id + '" ' + disabled + ' style="margin-right:4px;"><i class="fas fa-tasks"></i> Alloc</button>';
            html += '<button class="btn-asb-secondary btn-asb-sm report-btn" data-po-id="' + row.po_id + '" style="background:#2c3e50; color:#fff;"><i class="fas fa-print"></i> Report</button>';
            html += '</td>';
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

    // ---- Apply / Reset Filters ----
    $('#applyFilters').on('click', function() { currentPage = 1; loadPOList(1); });
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

    // ---- Company Summary ----
    $('#showSummaryBtn').on('click', function() {
        var data = {
            ajax_action: 'get_company_summary',
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
                renderSummary(res.results);
                $('#summaryCard').show();
                $('html, body').animate({ scrollTop: $('#summaryCard').offset().top - 20 }, 500);
            },
            error: function() { alert('Failed to load summary.'); }
        });
    });

    function renderSummary(rows) {
        if (!rows || rows.length === 0) {
            $('#summaryBody').html('<tr><td colspan="5" style="text-align:center; padding:20px; color:#888;">No allocation data for the selected filters.</td></tr>');
            $('#summaryFooter').hide();
            return;
        }

        var html = '';
        var grandQty = 0, grandCost = 0, grandSell = 0;
        rows.forEach(function(row) {
            var qty = parseFloat(row.total_qty) || 0;
            var cost = parseFloat(row.total_cost) || 0;
            var sell = parseFloat(row.total_selling) || 0;
            var profit = sell - cost;
            grandQty += qty;
            grandCost += cost;
            grandSell += sell;
            html += '<tr>';
            html += '<td><strong>' + (row.company_name || 'Unassigned') + '</strong></td>';
            html += '<td style="text-align:right;">' + qty.toLocaleString() + '</td>';
            html += '<td style="text-align:right;">' + cost.toFixed(2) + '</td>';
            html += '<td style="text-align:right;">' + sell.toFixed(2) + '</td>';
            html += '<td style="text-align:right; ' + (profit >= 0 ? 'color:green;' : 'color:red;') + '">' + profit.toFixed(2) + '</td>';
            html += '</tr>';
        });

        $('#summaryBody').html(html);
        $('#grandQty').text(grandQty.toLocaleString());
        $('#grandCost').text(grandCost.toFixed(2));
        $('#grandSell').text(grandSell.toFixed(2));
        var grandProfit = grandSell - grandCost;
        $('#grandProfit').text(grandProfit.toFixed(2)).css('color', grandProfit >= 0 ? 'green' : 'red');
        $('#summaryFooter').show();
    }

    // ---- Pagination ----
    $('#prevPage').on('click', function() { if (currentPage > 1) loadPOList(currentPage - 1); });
    $('#nextPage').on('click', function() { if (currentPage < totalPages) loadPOList(currentPage + 1); });

    // ---- Initial Load ----
    loadPOList(1);

    // ---- Allocate Button ----
    $(document).on('click', '.allocate-btn', function() {
        var poId = $(this).data('po-id');
        if (!poId) return;
        openAllocationModal(poId);
    });

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
                $('#allocationModal').data('po_id', poId);
            },
            error: function() {
                $('#modalBody').html('<div style="color:red;">Failed to load PO details.</div>');
            }
        });
    }

    function renderAllocationGrid(data) {
        var items = data.items;
        var branches = data.branches;
        var existing = data.allocations || {};

        if (!items || items.length === 0) {
            $('#modalBody').html('<div style="text-align:center; padding:20px; color:#888;">No items in this PO.</div>');
            return;
        }

        var companyMap = {};
        branches.forEach(function(b) {
            var cid = b.company_id || 0;
            if (!companyMap[cid]) companyMap[cid] = { company_name: b.company_name || 'Unassigned', branches: [] };
            companyMap[cid].branches.push(b);
        });

        var html = '<div class="table-responsive"><table class="allocation-table">';
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

        items.forEach(function(item) {
            var poItemId = item.po_item_id;
            var qty = parseInt(item.quantity) || 0;
            var allocated = parseInt(item.allocated_qty) || 0;
            var available = qty - allocated;

            html += '<tr>';
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

        // Real-time validation
        $('.alloc-input').on('input', function() {
            var poItemId = $(this).data('poitem');
            var totalAlloc = 0;
            $('.alloc-input[data-poitem="' + poItemId + '"]').each(function() {
                totalAlloc += parseInt($(this).val()) || 0;
            });
            var poQty = parseInt($(this).closest('tr').find('.po-qty').text()) || 0;
            var available = poQty - totalAlloc;
            var $availCell = $('.available-qty[data-poitem="' + poItemId + '"]');
            $availCell.text(available);
            if (available < 0) $availCell.css('color', 'red');
            else $availCell.css('color', '');
            $('.alloc-input[data-poitem="' + poItemId + '"]').each(function() {
                var currentVal = parseInt($(this).val()) || 0;
                var newMax = Math.max(0, available + currentVal);
                $(this).attr('max', newMax);
                if (parseInt($(this).val()) > newMax) $(this).val(newMax);
            });
        });
    }

    // ---- Close Allocation Modal ----
    window.closeModal = function() {
        $('#allocationModal').css('display', 'none');
    };
    $('#allocationModal').on('click', function(e) {
        if (e.target === this) closeModal();
    });

    // ---- Save Allocations ----
    $('#saveAllocBtn').on('click', function() {
        var hasError = false;
        $('.available-qty').each(function() {
            if (parseInt($(this).text()) < 0) hasError = true;
        });
        if (hasError) {
            $('#modalError').text('Some items are over‑allocated. Please correct the quantities.');
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

    window.closePasswordModal = function() {
        $('#passwordModal').css('display', 'none');
    };
    $('#passwordModal').on('click', function(e) {
        if (e.target === this) closePasswordModal();
    });

    // ---- Summary Report Button ----
    $(document).on('click', '.report-btn', function() {
        var poId = $(this).data('po-id');
        if (!poId) return;
        currentPoId = poId;
        openReportModal(poId);
    });

    // ---- Print Report Button ----
    $('#printReportBtn').on('click', function() {
        if (currentPoId) {
            // Open the dedicated print page in a new tab
            window.open('print_po_allocation.php?po_id=' + currentPoId, '_blank');
        } else {
            alert('No report loaded.');
        }
    });

    function openReportModal(poId) {
        $('#reportModal').css('display', 'flex');
        $('#reportBody').html('<div style="text-align:center; padding:20px;">Loading report...</div>');
        $('#reportTitle').html('<i class="fas fa-file-alt"></i> Loading...');

        $.ajax({
            url: window.location.href,
            data: { ajax_action: 'get_po_summary_report', po_id: poId },
            dataType: 'json',
            success: function(data) {
                if (data.error) {
                    $('#reportBody').html('<div style="color:red;">' + data.error + '</div>');
                    return;
                }
                renderReport(data);
                $('#reportTitle').html('<i class="fas fa-file-alt"></i> PO: ' + data.header.po_number + ' | Supplier: ' + data.header.supplier_name);
                currentPoId = poId;
            },
            error: function() {
                $('#reportBody').html('<div style="color:red;">Failed to load report.</div>');
            }
        });
    }

    function renderReport(data) {
        var header = data.header;
        var branches = data.branches;
        var items = data.items;

        if (!items || items.length === 0) {
            $('#reportBody').html('<div style="text-align:center; padding:20px; color:#888;">No items in this PO.</div>');
            return;
        }

        // Group branches by company
        var companyMap = {};
        branches.forEach(function(b) {
            var cid = b.company_id || 0;
            if (!companyMap[cid]) companyMap[cid] = { company_name: b.company_name || 'Unassigned', branches: [] };
            companyMap[cid].branches.push(b);
        });
        var companyIds = Object.keys(companyMap);

        var html = '<div id="printableArea">';
        // PO Header
        html += '<div style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:2px solid #c0392b; padding-bottom:8px;">';
        html += '<div><h2 style="margin:0; color:#c0392b;">ASB Group Of Companies</h2><small>Purchase Order Allocation Report</small></div>';
        html += '<div style="text-align:right;"><strong>PO:</strong> ' + header.po_number + '<br><strong>Supplier:</strong> ' + header.supplier_name + '<br><strong>Date:</strong> ' + header.purchase_date + '</div>';
        html += '</div>';

        html += '<div style="display:flex; justify-content:space-between; margin-bottom:15px; background:#fdf2f0; padding:8px 12px; border-left:4px solid #c0392b;">';
        html += '<div><strong>Expected Delivery:</strong> ' + (header.expected_delivery_date || 'N/A') + '</div>';
        html += '<div><strong>Attention:</strong> ' + (header.attention || '-') + '</div>';
        html += '<div><strong>Remarks:</strong> ' + (header.remarks || '-') + '</div>';
        html += '</div>';

        // Table
        html += '<div class="table-responsive"><table style="width:100%; border-collapse:collapse; font-size:9px; border:1px solid #ddd;">';
        html += '<thead><tr>';
        html += '<th style="padding:4px; border:1px solid #ddd;">#</th>';
        html += '<th style="padding:4px; border:1px solid #ddd; text-align:left;">Item</th>';
        html += '<th style="padding:4px; border:1px solid #ddd;">PO Qty</th>';
        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            var colSpan = comp.branches.length;
            html += '<th colspan="' + colSpan + '" style="padding:4px; border:1px solid #ddd; text-align:center; background:#e9ecef;">' + comp.company_name + ' Branches</th>';
        });
        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            html += '<th style="padding:4px; border:1px solid #ddd; text-align:center; background:#d5dbe3;">' + comp.company_name + ' Total</th>';
        });
        html += '<th style="padding:4px; border:1px solid #ddd;">Total Alloc</th>';
        html += '<th style="padding:4px; border:1px solid #ddd;">Remaining</th>';
        html += '</tr>';
        html += '<tr>';
        html += '<th style="padding:4px; border:1px solid #ddd;"></th>';
        html += '<th style="padding:4px; border:1px solid #ddd;"></th>';
        html += '<th style="padding:4px; border:1px solid #ddd;"></th>';
        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            comp.branches.forEach(function(b) {
                html += '<th style="padding:4px; border:1px solid #ddd; text-align:center; font-weight:normal; font-size:8px;">' + b.location_name + '</th>';
            });
        });
        companyIds.forEach(function(cid) {
            html += '<th style="padding:4px; border:1px solid #ddd; text-align:center; font-weight:normal; font-size:8px;">Total</th>';
        });
        html += '<th style="padding:4px; border:1px solid #ddd;"></th>';
        html += '<th style="padding:4px; border:1px solid #ddd;"></th>';
        html += '</tr></thead><tbody>';

        var grandTotalPO = 0, grandTotalAlloc = 0, grandRemaining = 0;
        var branchTotals = {};
        branches.forEach(function(b) { branchTotals[b.location_id] = 0; });
        var companyTotals = {};
        companyIds.forEach(function(cid) { companyTotals[cid] = 0; });

        items.forEach(function(item, idx) {
            var poQty = item.po_qty;
            var totalAlloc = item.total_allocated;
            var remaining = item.remaining;
            grandTotalPO += poQty;
            grandTotalAlloc += totalAlloc;
            grandRemaining += remaining;

            // Company sums for this item
            var itemCompanyTotals = {};
            companyIds.forEach(function(cid) { itemCompanyTotals[cid] = 0; });
            companyIds.forEach(function(cid) {
                var comp = companyMap[cid];
                comp.branches.forEach(function(b) {
                    var locId = b.location_id;
                    var qty = item.branches[locId] || 0;
                    itemCompanyTotals[cid] += qty;
                });
            });

            html += '<tr>';
            html += '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + (idx+1) + '</td>';
            html += '<td style="padding:4px; border:1px solid #ddd; text-align:left;"><strong>' + item.item_code + '</strong><br><small>' + item.item_name + '</small></td>';
            html += '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + poQty + '</td>';

            companyIds.forEach(function(cid) {
                var comp = companyMap[cid];
                comp.branches.forEach(function(b) {
                    var locId = b.location_id;
                    var qty = item.branches[locId] || 0;
                    branchTotals[locId] += qty;
                    companyTotals[cid] += qty;
                    html += '<td style="padding:4px; border:1px solid #ddd; text-align:center;">' + qty + '</td>';
                });
            });

            companyIds.forEach(function(cid) {
                html += '<td style="padding:4px; border:1px solid #ddd; text-align:center; font-weight:bold; background:#f5f7fa;">' + itemCompanyTotals[cid] + '</td>';
            });

            html += '<td style="padding:4px; border:1px solid #ddd; text-align:center; font-weight:bold;">' + totalAlloc + '</td>';
            html += '<td style="padding:4px; border:1px solid #ddd; text-align:center; ' + (remaining > 0 ? 'color:#e67e22;' : 'color:green;') + '">' + remaining + '</td>';
            html += '</tr>';
        });

        // Totals row
        html += '<tr style="background:#fde8e4; font-weight:bold;">';
        html += '<td colspan="3" style="padding:6px; border:1px solid #ddd; text-align:right;">TOTALS</td>';
        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            comp.branches.forEach(function(b) {
                var locId = b.location_id;
                html += '<td style="padding:6px; border:1px solid #ddd; text-align:center;">' + branchTotals[locId] + '</td>';
            });
        });
        companyIds.forEach(function(cid) {
            html += '<td style="padding:6px; border:1px solid #ddd; text-align:center; background:#f5f7fa;">' + companyTotals[cid] + '</td>';
        });
        html += '<td style="padding:6px; border:1px solid #ddd; text-align:center;">' + grandTotalAlloc + '</td>';
        html += '<td style="padding:6px; border:1px solid #ddd; text-align:center;">' + grandRemaining + '</td>';
        html += '</tr>';
        html += '</tbody></table></div>';

        // Summary footer
        html += '<div style="margin-top:15px; display:flex; flex-wrap:wrap; justify-content:flex-end; gap:15px; background:#fdf2f0; padding:10px 16px; border-left:4px solid #c0392b;">';
        companyIds.forEach(function(cid) {
            var comp = companyMap[cid];
            html += '<div><strong>' + comp.company_name + ' Total:</strong> ' + companyTotals[cid] + '</div>';
        });
        html += '<div><strong>Total PO Qty:</strong> ' + grandTotalPO + '</div>';
        html += '<div><strong>Total Allocated:</strong> ' + grandTotalAlloc + '</div>';
        html += '<div><strong>Total Remaining:</strong> ' + grandRemaining + '</div>';
        html += '</div>';

        html += '<div style="margin-top:15px; border-top:2px solid #c0392b; padding-top:8px; display:flex; justify-content:space-between; font-size:8px; color:#666;">';
        html += '<div>Developed by <strong>Vexel IT by Kavizz</strong></div>';
        html += '<div>Generated: ' + new Date().toLocaleString() + '</div>';
        html += '</div>';

        html += '</div>'; // printableArea
        $('#reportBody').html(html);
    }

    // ---- Close Report Modal ----
    window.closeReportModal = function() {
        $('#reportModal').css('display', 'none');
    };
    $('#reportModal').on('click', function(e) {
        if (e.target === this) closeReportModal();
    });

});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>