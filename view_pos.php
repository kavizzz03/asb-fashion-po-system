<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Comprehensive PO Audit Ledger';
$page = 'po_ledger';

$conn = getConnection();

// ------------------------------------------------------------
// NEW: Handle AJAX requests for status update and deletion
// ------------------------------------------------------------
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // Get the password from POST
    $input_password = isset($_POST['manager_password']) ? trim($_POST['manager_password']) : '';
    if (empty($input_password)) {
        $response['message'] = 'Manager password is required.';
        echo json_encode($response);
        exit;
    }

    // Fetch the stored password (plain text)
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

    // Verify password (plain text comparison)
    if ($input_password !== $stored_password) {
        $response['message'] = 'Invalid manager password.';
        echo json_encode($response);
        exit;
    }

    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
    if ($po_id <= 0) {
        $response['message'] = 'Invalid PO ID.';
        echo json_encode($response);
        exit;
    }

    $action = $_POST['ajax_action'];

    if ($action === 'update_status') {
        $new_status = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
        $allowed_statuses = ['pending', 'received', 'completed', 'cancelled'];
        if (!in_array($new_status, $allowed_statuses)) {
            $response['message'] = 'Invalid status value.';
            echo json_encode($response);
            exit;
        }

        $update_stmt = $conn->prepare("UPDATE po_header SET status = ? WHERE po_id = ?");
        $update_stmt->bind_param("si", $new_status, $po_id);
        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Status updated successfully.';
        } else {
            $response['message'] = 'Database error: ' . $update_stmt->error;
        }
        $update_stmt->close();
        echo json_encode($response);
        exit;
    }

    if ($action === 'delete_po') {
        // Start transaction to delete PO and its items (cascade)
        $conn->begin_transaction();
        try {
            // Delete PO items first (if foreign keys are not set to cascade)
            $del_items = $conn->prepare("DELETE FROM po_items WHERE po_id = ?");
            $del_items->bind_param("i", $po_id);
            $del_items->execute();
            $del_items->close();

            // Delete the PO header
            $del_header = $conn->prepare("DELETE FROM po_header WHERE po_id = ?");
            $del_header->bind_param("i", $po_id);
            $del_header->execute();
            $del_header->close();

            $conn->commit();
            $response['success'] = true;
            $response['message'] = 'PO and associated items deleted.';
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Deletion failed: ' . $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }

    // If action not recognised
    $response['message'] = 'Unknown action.';
    echo json_encode($response);
    exit;
}
// ------------------------------------------------------------

// Existing search & filter logic (unchanged)
$search_po       = isset($_GET['search_po']) ? trim($_GET['search_po']) : '';
$search_supplier = isset($_GET['search_supplier']) ? trim($_GET['search_supplier']) : '';
$search_driver   = isset($_GET['search_driver']) ? trim($_GET['search_driver']) : '';
$date_from       = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to         = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$items_per_page = 15;
$current_page   = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$offset         = ($current_page - 1) * $items_per_page;

$where_clauses = ["1=1"];
$types = "";
$params = [];

if (!empty($search_po)) {
    $where_clauses[] = "h.po_number LIKE ?";
    $params[] = "%" . $search_po . "%";
    $types .= "s";
}
if (!empty($search_supplier)) {
    $where_clauses[] = "s.supplier_name LIKE ?";
    $params[] = "%" . $search_supplier . "%";
    $types .= "s";
}
if (!empty($search_driver)) {
    $where_clauses[] = "h.po_id IN (SELECT DISTINCT gh.po_id FROM grn_header gh WHERE gh.delivered_by LIKE ?)";
    $params[] = "%" . $search_driver . "%";
    $types .= "s";
}
if (!empty($date_from)) {
    $where_clauses[] = "h.purchase_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if (!empty($date_to)) {
    $where_clauses[] = "h.purchase_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_str = implode(" AND ", $where_clauses);

$count_sql = "SELECT COUNT(DISTINCT h.po_id) FROM po_header h LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id WHERE $where_str";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_pos = $count_stmt->get_result()->fetch_row()[0];
$count_stmt->close();

$total_pages = ceil($total_pos / $items_per_page);

$main_sql = "SELECT h.*, s.supplier_name,
                    COUNT(pi.po_item_id) as unique_items_count,
                    SUM(pi.quantity) as total_ordered_qty,
                    SUM(pi.received_qty) as total_received_qty,
                    (SELECT GROUP_CONCAT(DISTINCT gh.delivered_by SEPARATOR ', ') FROM grn_header gh WHERE gh.po_id = h.po_id) as tracking_drivers
             FROM po_header h
             LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id
             LEFT JOIN po_items pi ON h.po_id = pi.po_id
             WHERE $where_str
             GROUP BY h.po_id
             ORDER BY h.purchase_date DESC
             LIMIT ? OFFSET ?";

$types_final = $types . "ii";
$params_final = array_merge($params, [$items_per_page, $offset]);

$query_stmt = $conn->prepare($main_sql);
$query_stmt->bind_param($types_final, ...$params_final);
$query_stmt->execute();
$pos_ledger = $query_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$query_stmt->close();

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<!-- NEW: Include Font Awesome (or any icon library) if not already present -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
    /* … your existing styles … */
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
    .btn-warning-sm { background: #ffc107; color: #212529; border: none; padding: 4px 8px; font-size: 10px; border-radius: 4px; }
    .btn-warning-sm:hover { background: #e0a800; }
    .asb-badge { padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    .badge-completed { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
    .badge-received { background: #e3f2fd; color: #0d47a1; border: 1px solid #bbdefb; }
    .badge-pending { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
    .badge-cancelled { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
    .asb-input { border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; width: 100%; font-size: 12px; box-sizing: border-box; }
    .asb-input:focus { border-color: #d32f2f; outline: none; box-shadow: 0 0 4px rgba(211,47,47,0.2); }
    .asb-footer { text-align: center; margin-top: 40px; padding: 15px; color: #777; border-top: 1px solid #eee; font-size: 12px; }
    .asb-footer strong { color: #b71c1c; }

    /* NEW: Password modal styles */
    .modal-mask { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center; }
    .modal-content { background:#fff; padding:30px; border-radius:8px; max-width:400px; width:90%; box-shadow:0 5px 15px rgba(0,0,0,0.3); }
    .modal-content h4 { margin-top:0; color:#b71c1c; }
    .modal-content .form-group { margin-bottom:15px; }
    .modal-content .form-group label { display:block; font-weight:600; font-size:12px; margin-bottom:5px; }
    .modal-content .form-group input, .modal-content .form-group select { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; }
    .modal-content .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }
</style>

<!-- NEW: Password Modal (hidden by default) -->
<div id="passwordModal" class="modal-mask">
    <div class="modal-content">
        <h4><i class="fas fa-lock"></i> Manager Authorization Required</h4>
        <p style="font-size:12px; color:#666;">Please enter the manager password to perform this action.</p>
        <div class="form-group">
            <label for="modalPassword">Password</label>
            <input type="password" id="modalPassword" class="asb-input" placeholder="Enter manager password">
        </div>
        <!-- Hidden fields to pass action and PO ID -->
        <input type="hidden" id="modalAction" value="">
        <input type="hidden" id="modalPoId" value="">
        <!-- For status update: also store the new status -->
        <input type="hidden" id="modalNewStatus" value="">
        <div class="modal-actions">
            <button type="button" class="btn-asb-secondary" onclick="closePasswordModal()">Cancel</button>
            <button type="button" class="btn-asb" onclick="submitModalAction()">Confirm</button>
        </div>
        <div id="modalError" style="color:#d32f2f; font-size:12px; margin-top:10px;"></div>
    </div>
</div>

<div class="container-fluid" style="padding: 20px 25px;">
    
    <div class="page-header">
        <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Master Purchase Order Audit Registry</span></h2>
    </div>

    <!-- Search Control Dashboard Panel (unchanged) -->
    <div class="asb-card">
        <div style="padding: 15px 20px; background: #fff;">
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
                        <label style="font-weight:600; margin-bottom:5px; display:block; font-size:11px; color:#555;">Delivery Driver Name</label>
                        <input type="text" name="search_driver" class="asb-input" placeholder="Search Logistics Personnel..." value="<?= htmlspecialchars($search_driver); ?>">
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
                        <button type="submit" class="btn-asb" style="padding: 9px 16px; flex: 1; justify-content: center;">🔍 Filter</button>
                        <a href="view_pos.php" class="btn-asb-secondary" style="padding: 8px 12px; display: inline-flex; align-items: center;">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Presentation Hub Grid -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span>📋 Procurement Records Ledger Overview</span>
            <span style="font-size: 11px; background: #b71c1c; color:#fff; padding: 2px 8px; border-radius: 10px; font-weight: normal;">Matches Found: <?= $total_pos; ?></span>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($pos_ledger)): ?>
                <div style="text-align:center; padding:40px; color:#888;">No historical Purchase Orders matched your active filter selections.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table asb-table" style="width:100%; margin-bottom:0; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="width: 120px;">PO Reference</th>
                                <th>Supplier Name</th>
                                <th style="width: 100px;">Order Date</th>
                                <th style="width: 80px; text-align:center;">Line Items</th>
                                <th style="text-align:right; width: 100px;">Target Vol</th>
                                <th style="text-align:right; width: 100px;">Received Vol</th>
                                <th>Logistics Driver Associations</th>
                                <th style="width: 100px; text-align:center;">Fulfillment</th>
                                <!-- NEW: wider Actions column -->
                                <th style="text-align:center; width: 160px; padding-right:10px !important;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pos_ledger as $po): 
                                $status = strtolower($po['status']);
                                $badgeClass = 'badge-pending';
                                if ($status === 'completed') $badgeClass = 'badge-completed';
                                elseif ($status === 'received') $badgeClass = 'badge-received';
                                elseif ($status === 'cancelled') $badgeClass = 'badge-cancelled';
                                
                                $drivers_list = !empty($po['tracking_drivers']) ? htmlspecialchars($po['tracking_drivers']) : '<span style="color:#bbb; font-style:italic;">No logistics log</span>';
                            ?>
                                <tr>
                                    <td><strong style="color:#111;"><?= $po['po_number']; ?></strong></td>
                                    <td><span style="font-weight:600; color:#444;"><?= htmlspecialchars($po['supplier_name'] ?? 'Unknown Link'); ?></span></td>
                                    <td><?= date('Y-m-d', strtotime($po['purchase_date'])); ?></td>
                                    <td style="text-align:center; font-weight:600; color:#0d47a1;"><?= $po['unique_items_count']; ?></td>
                                    <td style="text-align:right; font-weight:500; color:#555;"><?= number_format($po['total_ordered_qty'] ?? 0); ?></td>
                                    <td style="text-align:right; font-weight:700; color:#2e7d32;"><?= number_format($po['total_received_qty'] ?? 0); ?></td>
                                    <td style="font-size:11px; color:#555; max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= strip_tags($drivers_list); ?>">
                                        <?= $drivers_list; ?>
                                    </td>
                                    <td style="text-align:center;"><span class="asb-badge <?= $badgeClass; ?>"><?= $po['status']; ?></span></td>
                                    <td style="text-align:center; padding-right:10px !important;">
                                        <!-- Print button (existing) -->
                                        <a href="print_po_report.php?po_id=<?= $po['po_id']; ?>" target="_blank" class="btn-asb btn-asb-sm" title="Print Full Cumulative History Statement">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        <!-- NEW: Edit Status button -->
                                        <button type="button" class="btn-warning-sm" onclick="openStatusModal(<?= $po['po_id']; ?>, '<?= $po['status']; ?>')" title="Edit Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <!-- NEW: Delete button -->
                                        <button type="button" class="btn-danger-sm" onclick="openDeleteModal(<?= $po['po_id']; ?>)" title="Delete PO">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination Execution Bar (unchanged) -->
    <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:5px; margin-top:15px;">
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <a href="view_pos.php?page_num=<?= $p; ?>&search_po=<?= urlencode($search_po); ?>&search_supplier=<?= urlencode($search_supplier); ?>&search_driver=<?= urlencode($search_driver); ?>&date_from=<?= urlencode($date_from); ?>&date_to=<?= urlencode($date_to); ?>" 
                   style="padding: 6px 12px; border: 1px solid #ccc; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; <?= $p === $current_page ? 'background:#d32f2f; color:#fff; border-color:#d32f2f;' : 'background:#fff; color:#333;'; ?>">
                    <?= $p; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <!-- System Branding Realization Footer -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASB Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:4px; display:inline-block; color:#aaa;">System Designed &amp; Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>

</div>

<!-- NEW: JavaScript for modal and AJAX -->
<script>
// Open modal for status update
function openStatusModal(poId, currentStatus) {
    document.getElementById('modalAction').value = 'update_status';
    document.getElementById('modalPoId').value = poId;
    // We'll create a dynamic dropdown inside the modal (or we can show a fixed select)
    // Instead of a hidden field, we'll add a select inside the modal content.
    // Let's dynamically add a status dropdown to the modal.
    const modalContent = document.querySelector('#passwordModal .modal-content');
    // Remove any previous extra fields (except the ones we keep)
    const existingExtra = document.getElementById('statusSelectWrapper');
    if (existingExtra) existingExtra.remove();

    const wrapper = document.createElement('div');
    wrapper.id = 'statusSelectWrapper';
    wrapper.className = 'form-group';
    wrapper.innerHTML = `
        <label for="statusSelect">New Status</label>
        <select id="statusSelect" class="asb-input">
            <option value="pending" ${currentStatus === 'pending' ? 'selected' : ''}>Pending</option>
            <option value="received" ${currentStatus === 'received' ? 'selected' : ''}>Received</option>
            <option value="completed" ${currentStatus === 'completed' ? 'selected' : ''}>Completed</option>
            <option value="cancelled" ${currentStatus === 'cancelled' ? 'selected' : ''}>Cancelled</option>
        </select>
    `;
    // Insert before the modal actions
    const actionsDiv = document.querySelector('#passwordModal .modal-actions');
    actionsDiv.parentNode.insertBefore(wrapper, actionsDiv);

    // Clear any previous error
    document.getElementById('modalError').innerText = '';
    document.getElementById('modalPassword').value = '';
    document.getElementById('passwordModal').style.display = 'flex';
}

// Open modal for delete
function openDeleteModal(poId) {
    document.getElementById('modalAction').value = 'delete_po';
    document.getElementById('modalPoId').value = poId;
    // Remove any extra status select if present
    const extra = document.getElementById('statusSelectWrapper');
    if (extra) extra.remove();
    document.getElementById('modalError').innerText = '';
    document.getElementById('modalPassword').value = '';
    document.getElementById('passwordModal').style.display = 'flex';
}

function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
}

function submitModalAction() {
    const password = document.getElementById('modalPassword').value.trim();
    const action = document.getElementById('modalAction').value;
    const poId = document.getElementById('modalPoId').value;
    let newStatus = '';
    if (action === 'update_status') {
        const statusSelect = document.getElementById('statusSelect');
        if (statusSelect) newStatus = statusSelect.value;
        else {
            document.getElementById('modalError').innerText = 'Status selection missing.';
            return;
        }
    }

    if (!password) {
        document.getElementById('modalError').innerText = 'Please enter the manager password.';
        return;
    }

    // Prepare data for AJAX
    const formData = new FormData();
    formData.append('ajax_action', action);
    formData.append('po_id', poId);
    formData.append('manager_password', password);
    if (action === 'update_status') {
        formData.append('new_status', newStatus);
    }

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload(); // Refresh the page to show updated data
        } else {
            document.getElementById('modalError').innerText = data.message;
        }
    })
    .catch(error => {
        document.getElementById('modalError').innerText = 'An error occurred: ' + error;
    });
}

// Close modal if user clicks outside the content
document.getElementById('passwordModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePasswordModal();
    }
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>