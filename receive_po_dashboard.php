<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Item Receiving Dashboard';
$page = 'receiving';

// Initialize the main PO database connection
$conn = getConnection(); 

// Filter Handlers
$search_filter = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter   = isset($_GET['date_filter']) ? $_GET['date_filter'] : '';
$items_per_page = 10;
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Processing incoming batch shipment submissions (GRN Entry Saved)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_grn'])) {
    $po_id = (int)$_POST['po_id'];
    $delivery_note = $conn->real_escape_string($_POST['delivery_note_no']);
    $remarks = $conn->real_escape_string($_POST['remarks']);
    
    // Capture logistics tracking details from the form
    $vehicle_no = $conn->real_escape_string($_POST['vehicle_no']);
    $delivered_by = $conn->real_escape_string($_POST['delivered_by']);
    $total_box_count = (int)$_POST['total_box_count'];
    
    $quantities = $_POST['receive_qty']; // Array indexed by po_item_id
    
    try {
        $conn->begin_transaction();
        
        // Generate Unique GRN Identifier
        $grn_no = "GRN-" . date('Ymd') . "-" . rand(1000, 9999);
        
        // Insert GRN Records
        $stmt = $conn->prepare("INSERT INTO grn_header (grn_number, po_id, delivery_note_no, remarks, vehicle_no, delivered_by, total_box_count) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sissssi", $grn_no, $po_id, $delivery_note, $remarks, $vehicle_no, $delivered_by, $total_box_count);
        $stmt->execute();
        $grn_id = $conn->insert_id;
        $stmt->close();
        
        $all_items_completed = true;

        // Loop items to calculate balances
        foreach ($quantities as $po_item_id => $qty_received) {
            $po_item_id = (int)$po_item_id;
            $qty_received = (int)$qty_received;
            if ($qty_received <= 0) continue;

            // Fetch current item state
            $st = $conn->prepare("SELECT item_id, quantity, received_qty FROM po_items WHERE po_item_id = ?");
            $st->bind_param("i", $po_item_id);
            $st->execute();
            $res = $st->get_result();
            $rowItem = $res->fetch_assoc();
            $st->close();

            $new_total_received = $rowItem['received_qty'] + $qty_received;
            
            // Safety Validation against over-receiving thresholds
            if ($new_total_received > $rowItem['quantity']) {
                throw new Exception("Error: Quantity entered exceeds outstanding balance limit.");
            }

            // Write batch row records
            $inst = $conn->prepare("INSERT INTO grn_items (grn_id, po_item_id, item_id, qty_received) VALUES (?, ?, ?, ?)");
            $inst->bind_param("iiii", $grn_id, $po_item_id, $rowItem['item_id'], $qty_received);
            $inst->execute();
            $inst->close();

            // Synchronize rolling status aggregates
            $upd = $conn->prepare("UPDATE po_items SET received_qty = ? WHERE po_item_id = ?");
            $upd->bind_param("ii", $new_total_received, $po_item_id);
            $upd->execute();
            $upd->close();

            if ($new_total_received < $rowItem['quantity']) {
                $all_items_completed = false;
            }
        }

        // Determine master structural PO Status transitions
        $finalStatus = $all_items_completed ? 'Completed' : 'Received';
        $updHeader = $conn->prepare("UPDATE po_header SET status = ? WHERE po_id = ?");
        $updHeader->bind_param("si", $finalStatus, $po_id);
        $updHeader->execute();
        $updHeader->close();
        
        $conn->commit();
        
        // Red-themed alert with manual print actions
        $_SESSION['print_grn_id'] = $grn_id;
        $_SESSION['success'] = "
            <div style='font-size: 16px; margin-bottom: 12px;'><strong>📦 Goods Received Note ($grn_no) Added Successfully!</strong></div>
            <div style='margin-bottom: 15px;'>PO workflow context updated to status: <span style='background:#d32f2f; color:#fff; padding:2px 8px; border-radius:3px; font-weight:bold; font-size:11px;'>$finalStatus</span></div>
            <div style='display:flex; gap:10px; flex-wrap:wrap;'>
                <a href='print_grn.php?grn_id=$grn_id&type=supplier' target='_blank' style='background:#d32f2f; color:#fff; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:bold; font-size:13px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); transition: 0.2s;'>🖨️ Print Supplier Copy</a>
                <a href='print_grn.php?grn_id=$grn_id&type=internal' target='_blank' style='background:#333; color:#fff; text-decoration:none; padding:10px 16px; border-radius:6px; font-weight:bold; font-size:13px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); transition: 0.2s;'>📋 Print Gate Pass Copy</a>
            </div>
        ";
            
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }
    header("Location: receive_po_dashboard.php");
    exit;
}

// Build Cross-Database SQL Clauses (using return_qc.suppliers)
$where_clauses = ["h.status NOT IN ('Cancelled', 'Completed')"];
$types = "";
$params = [];

if (!empty($search_filter)) {
    $where_clauses[] = "(h.po_number LIKE ? OR s.supplier_name LIKE ?)";
    $search_param = "%" . $search_filter . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}
if (!empty($date_filter)) {
    $where_clauses[] = "h.purchase_date = ?";
    $params[] = $date_filter;
    $types .= "s";
}
$where_str = implode(" AND ", $where_clauses);

// Dynamic Total Counters
$count_sql = "SELECT COUNT(DISTINCT h.po_id) FROM po_header h LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id WHERE $where_str";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_res = $count_stmt->get_result();
$total_pos = $count_res->fetch_row()[0];
$count_stmt->close();

$total_pages = ceil($total_pos / $items_per_page);

// Main Query execution set - Left Join grn_header to get the latest GRN ID for existing print actions
$main_sql = "SELECT h.*, s.supplier_name, 
                    (SELECT gh.grn_id FROM grn_header gh WHERE gh.po_id = h.po_id ORDER BY gh.grn_id DESC LIMIT 1) as latest_grn_id
             FROM po_header h 
             LEFT JOIN return_qc.suppliers s ON h.supplier_id = s.supplier_id 
             WHERE $where_str ORDER BY h.purchase_date DESC LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $items_per_page;
$params[] = $offset;

$query_stmt = $conn->prepare($main_sql);
$query_stmt->bind_param($types, ...$params);
$query_stmt->execute();
$pos_list = $query_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$query_stmt->close();

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<!-- Custom CSS Inject Elements for Premium Red & White UX -->
<style>
    body { background-color: #fcfcfc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; margin-bottom: 25px; }
    .asb-card { background: #ffffff; border: 1px solid #eaeaea; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 30px; }
    .asb-card-header { background: #fff; border-bottom: 2px solid #f5f5f5; padding: 18px 24px; color: #b71c1c; font-weight: bold; }
    .asb-table th { background: #f8f9fa !important; color: #444 !important; font-weight: 600 !important; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; padding: 14px !important; border-bottom: 2px solid #eaeaea !important; }
    .asb-table td { padding: 14px !important; vertical-align: middle !important; border-bottom: 1px solid #f5f5f5 !important; }
    .btn-asb-action { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 8px 16px; border-radius: 6px; box-shadow: 0 2px 4px rgba(211,47,47,0.2); transition: all 0.2s ease; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .btn-asb-action:hover { background: #b71c1c; box-shadow: 0 4px 8px rgba(211,47,47,0.3); transform: translateY(-1px); color: #fff; }
    .asb-badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
    .asb-badge-pending { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
    .asb-badge-received { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
    .asb-input { border: 1px solid #dcdcdc; padding: 10px 14px; border-radius: 6px; width: 100%; transition: all 0.2s; }
    .asb-input:focus { border-color: #d32f2f; box-shadow: 0 0 0 3px rgba(211,47,47,0.1); outline: none; }
    .asb-footer { text-align: center; margin-top: 50px; padding: 20px; color: #777; border-top: 1px solid #eee; font-size: 13px; }
    .asb-footer strong { color: #d32f2f; }
</style>

<div class="container-fluid" style="padding: 20px 30px;">
    
    <div class="page-header">
        <h2 class="asb-header-title">ASb Fashion <span style="font-weight:300; color:#555; font-size:18px;">| Goods Receiving Control</span></h2>
    </div>

    <!-- Alert Notifications -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success" style="padding:20px; background-color: #e8f5e9; border: 1px solid #c8e6c9; color: #1b5e20; border-radius:10px; margin-bottom:25px; position:relative;">
            <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger" style="padding:20px; background-color: #ffebee; border: 1px solid #ffcdd2; color: #b71c1c; border-radius:10px; margin-bottom:25px;">
            ⚠️ <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Filter Control Board -->
    <div class="asb-card" style="margin-bottom: 25px;">
        <div style="padding: 20px; background: #fff;">
            <form method="GET" style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                <div style="flex:2; min-width:250px;">
                    <label style="font-weight:600; margin-bottom:8px; display:block; font-size:13px; color:#555;">Search Parameters</label>
                    <input type="text" name="search" class="asb-input" placeholder="Search PO Number or Supplier Name..." value="<?= htmlspecialchars($search_filter); ?>">
                </div>
                <div style="flex:1; min-width:180px;">
                    <label style="font-weight:600; margin-bottom:8px; display:block; font-size:13px; color:#555;">Purchase Date</label>
                    <input type="date" name="date_filter" class="asb-input" value="<?= htmlspecialchars($date_filter); ?>">
                </div>
                <div>
                    <button type="submit" class="btn-asb-action" style="padding: 11px 24px;">🔍 Filter Records</button>
                    <a href="receive_po_dashboard.php" style="color: #666; margin-left: 10px; text-decoration: none; font-size: 13px; font-weight: 600;">Reset Filters</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Grid Workspace Table Area -->
    <div class="asb-card">
        <div class="asb-card-header">📋 Active Production Purchase Orders Pending Delivery</div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($pos_list)): ?>
                <div style="text-align:center; padding:50px; color:#999; font-size:14px;">No active purchase orders found requiring stock receiving.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table asb-table" style="width:100%; margin-bottom:0; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th>PO Number Reference</th>
                                <th>Supplier Identity Context</th>
                                <th>Order Initiation Date</th>
                                <th>Current Status</th>
                                <th style="text-align:right; padding-right:24px !important;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pos_list as $po): 
                                $badgeClass = (strtolower($po['status']) == 'received') ? 'asb-badge-received' : 'asb-badge-pending';
                                $hasGrn = !empty($po['latest_grn_id']);
                            ?>
                                <tr>
                                    <td><strong style="color:#222; font-size:14px;"><?= $po['po_number']; ?></strong></td>
                                    <td style="color:#555;"><?= htmlspecialchars($po['supplier_name'] ?? 'Unknown Vendor Link'); ?></td>
                                    <td style="color:#666; font-size:13px;"><?= date('M d, Y', strtotime($po['purchase_date'])); ?></td>
                                    <td><span class="asb-badge <?= $badgeClass; ?>"><?= $po['status']; ?></span></td>
                                    <td style="text-align:right; padding-right:24px !important;">
                                        <div style="display: inline-flex; gap: 6px; align-items: center;">
                                            <?php if ($hasGrn): ?>
                                                <!-- Fixed Print Supplier Copy with true latest_grn_id -->
                                                <a href="print_grn.php?grn_id=<?= $po['latest_grn_id']; ?>&type=supplier" target="_blank" class="btn-asb-action" style="background: #fff; color: #d32f2f; border: 1px solid #d32f2f; padding: 6px 12px; font-size: 12px; box-shadow: none;" title="Print Supplier Copy">
                                                    🖨️ Supplier
                                                </a>
                                                <!-- Fixed Print Internal Gate Pass with true latest_grn_id -->
                                                <a href="print_grn.php?grn_id=<?= $po['latest_grn_id']; ?>&type=internal" target="_blank" class="btn-asb-action" style="background: #fff; color: #333; border: 1px solid #ccc; padding: 6px 12px; font-size: 12px; box-shadow: none;" title="Print Gate Pass Copy">
                                                    📋 Gate Pass
                                                </a>
                                            <?php else: ?>
                                                <button class="btn-asb-action" style="background: #f5f5f5; color: #aaa; border: 1px solid #ddd; padding: 6px 12px; font-size: 12px; box-shadow: none; cursor: not-allowed;" disabled title="No receipts linked yet">
                                                    🚫 No Items
                                                </button>
                                            <?php endif; ?>
                                            
                                            <!-- Log Entry Form Action Button -->
                                            <button class="btn-asb-action" style="padding: 7px 14px; font-size: 13px;" onclick="openGrnModal(<?= $po['po_id']; ?>, '<?= $po['po_number']; ?>', '<?= htmlspecialchars($po['supplier_name'] ?? 'Unknown'); ?>')">
                                                📥 Receive Delivery
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer System Branding Realization -->
    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASb Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:5px; display:inline-block; color:#aaa;">System Designed & Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>

</div>

<!-- Interactive Modal Dialog Box for Receiving Operations -->
<div id="grnModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(3px);">
    <div style="background:white; padding:30px; border-radius:14px; max-width:900px; width:95%; max-height:92vh; overflow-y:auto; box-shadow: 0 10px 30px rgba(0,0,0,0.25); border-top: 6px solid #d32f2f;">
        <h4 style="color:#b71c1c; font-weight:bold; margin-top:0; margin-bottom:20px; font-size:20px; display:flex; align-items:center; gap:8px;">📥 Log New Shipment Intake Batch</h4>
        
        <form method="POST" action="">
            <input type="hidden" name="process_grn" value="1">
            <input type="hidden" name="po_id" id="modal_po_id">
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:25px; background:#f9f9f9; padding:15px; border-radius:8px; border: 1px solid #eee;">
                <div><span style="color:#777; font-size:12px; display:block;">PO Reference Number</span> <strong id="modal_po_span" style="font-size:15px; color:#111;"></strong></div>
                <div><span style="color:#777; font-size:12px; display:block;">Supplier Account Context</span> <strong id="modal_supplier_span" style="font-size:15px; color:#111;"></strong></div>
            </div>

            <!-- Meta Parameters Grid Split Row 1 -->
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:15px; margin-bottom:20px;">
                <div>
                    <label style="font-weight:600; margin-bottom:6px; display:block; font-size:12px; color:#444;">Delivery Note / Invoice No *</label>
                    <input type="text" name="delivery_note_no" class="asb-input" required placeholder="Ex: DN-55122">
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:6px; display:block; font-size:12px; color:#444;">Total Box / Carton Count *</label>
                    <input type="number" name="total_box_count" class="asb-input" required min="1" placeholder="Qty of boxes">
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:6px; display:block; font-size:12px; color:#444;">Vehicle Registration Number *</label>
                    <input type="text" name="vehicle_no" class="asb-input" required placeholder="Ex: WP-CB-1234">
                </div>
            </div>

            <!-- Meta Parameters Grid Split Row 2 -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:25px;">
                <div>
                    <label style="font-weight:600; margin-bottom:6px; display:block; font-size:12px; color:#444;">Delivered By (Driver Name) *</label>
                    <input type="text" name="delivered_by" class="asb-input" required placeholder="Full Name of Deliverer">
                </div>
                <div>
                    <label style="font-weight:600; margin-bottom:6px; display:block; font-size:12px; color:#444;">Internal Receiving Remarks</label>
                    <input type="text" name="remarks" class="asb-input" placeholder="Condition comments, damaged boxes notes...">
                </div>
            </div>

            <h5 style="color:#444; font-weight:bold; font-size:14px; text-transform:uppercase; margin-bottom:12px; border-bottom:1px solid #ddd; padding-bottom:8px;">Line Item Breakdown Comparisons</h5>
            <div class="table-responsive" style="border: 1px solid #eee; border-radius:8px; overflow:hidden;">
                <table style="width:100%; border-collapse:collapse; margin-bottom:0;" id="grn_items_table" class="table asb-table">
                    <thead>
                        <tr>
                            <th>Product Structural Specification</th>
                            <th>Target Qty</th>
                            <th>Arrived Prior</th>
                            <th>Remaining Balance</th>
                            <th style="width:130px;">Receive Now</th>
                        </tr>
                    </thead>
                    <tbody id="grn_items_tbody">
                        <!-- Loaded dynamically via JavaScript Fetch -->
                    </tbody>
                </table>
            </div>

            <div style="margin-top:25px; display:flex; gap:12px; justify-content:flex-end; border-top: 1px solid #eee; padding-top:20px;">
                <button type="button" class="btn-asb-action" style="background:#fff; color:#555; border:1px solid #ccc; box-shadow:none;" onclick="closeGrnModal()">Dismiss Window</button>
                <button type="submit" class="btn-asb-action">✅ Save & Commit Inventory Batch</button>
            </div>
        </form>
    </div>
</div>

<script>
function openGrnModal(poId, poNumber, supplierName) {
    document.getElementById('modal_po_id').value = poId;
    document.getElementById('modal_po_span').innerText = poNumber;
    document.getElementById('modal_supplier_span').innerText = supplierName;
    
    const tbody = document.getElementById('grn_items_tbody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:30px; color:#888;">Calculating remaining line allocations...</td></tr>';
    document.getElementById('grnModal').style.display = 'flex';

    fetch(`api.php?action=getPO&po_id=${poId}`)
        .then(res => res.json())
        .then(data => {
            tbody.innerHTML = '';
            data.items.forEach(item => {
                let remaining = item.quantity - item.received_qty;
                if (remaining < 0) remaining = 0;

                let row = `<tr>
                    <td><strong style="color:#b71c1c;">[${item.item_code || 'N/A'}]</strong> <span style="color:#333;">${item.item_name}</span></td>
                    <td style="font-weight:600;">${item.quantity}</td>
                    <td style="color: #0288d1; font-weight:600;">${item.received_qty}</td>
                    <td style="font-weight:700; color: ${remaining > 0 ? '#d32f2f' : '#2e7d32'};">${remaining}</td>
                    <td>
                        <input type="number" name="receive_qty[${item.po_item_id}]" 
                               class="asb-input" value="${remaining}" 
                               min="0" max="${remaining}" 
                               style="width:110px; padding:6px 10px; text-align:center; font-weight:bold; color:#b71c1c;" ${remaining === 0 ? 'disabled' : ''}>
                    </td>
                </tr>`;
                tbody.innerHTML += row;
            });
        });
}

function closeGrnModal() {
    document.getElementById('grnModal').style.display = 'none';
}

// Automatic Dual Print Routine context execution block
window.addEventListener('DOMContentLoaded', (event) => {
    <?php if (isset($_SESSION['print_grn_id'])): ?>
        const targetGrnId = <?= (int)$_SESSION['print_grn_id']; ?>;
        
        // Open printing documents safely in split tab instances
        window.open(`print_grn.php?grn_id=${targetGrnId}&type=supplier`, '_blank');
        window.open(`print_grn.php?grn_id=${targetGrnId}&type=internal`, '_blank');
        
        <?php unset($_SESSION['print_grn_id']); ?>
    <?php endif; ?>
});
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>