<?php
// Ensure ROOT_PATH is defined (if accessed directly)
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}

// Include database and functions
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

$page_title = 'ASB Fashion | Purchase Order Operations Control';
$page = 'pos';

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_filter = isset($_GET['search']) ? $_GET['search'] : '';
$items_per_page = 10;
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$offset = ($current_page - 1) * $items_per_page;

$total_pos = countPOs($status_filter, $search_filter);
$total_pages = ceil($total_pos / $items_per_page);
$pos_list = getPOs($status_filter, $search_filter, $items_per_page, $offset);

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    body { background-color: #fbfbfb; font-family: 'Segoe UI', Arial, sans-serif; color: #333; }
    .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; }
    .asb-card { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 25px; }
    .asb-card-header { background: #fff; border-bottom: 2px solid #eaeaea; padding: 15px 20px; color: #b71c1c; font-weight: bold; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
    
    /* Stats Layout */
    .stat-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 25px; }
    .asb-stat-box { background: #ffffff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px; text-align: center; border-bottom: 3px solid #ccc; transition: transform 0.2s; }
    .asb-stat-box:hover { transform: translateY(-2px); }
    .asb-stat-box .stat-label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; color: #666; letter-spacing: 0.3px; }
    .asb-stat-box .stat-value { font-size: 24px; font-weight: 800; color: #111; margin-top: 4px; display: block; }
    
    /* Dynamic Theme Accents */
    .asb-stat-box.border-total { border-bottom-color: #333; }
    .asb-stat-box.border-pending { border-bottom-color: #f57c00; background: #fffbf5; }
    .asb-stat-box.border-received { border-bottom-color: #1976d2; background: #f5f9ff; }
    .asb-stat-box.border-completed { border-bottom-color: #388e3c; background: #f5fbf5; }
    .asb-stat-box.border-cancelled { border-bottom-color: #d32f2f; background: #fff5f5; }
    
    /* Table Core UI */
    .asb-table th { background: #f8f9fa !important; color: #444 !important; font-weight: 600 !important; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; padding: 12px 10px !important; border-bottom: 2px solid #d32f2f !important; }
    .asb-table td { padding: 12px 10px !important; vertical-align: middle !important; border-bottom: 1px solid #f0f0f0 !important; font-size: 13px; }
    
    /* Buttons Customization */
    .btn-asb { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 8px 16px; border-radius: 4px; font-size: 12px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .btn-asb:hover { background: #b71c1c; color: #fff; }
    .btn-asb-success { background: #2e7d32; }
    .btn-asb-success:hover { background: #1b5e20; }
    .btn-asb-secondary { background: #f5f5f5; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 8px 14px; border-radius: 4px; font-size: 12px; text-decoration: none; }
    .btn-asb-secondary:hover { background: #e0e0e0; }
    
    .btn-action-group { display: flex; gap: 4px; justify-content: flex-start; }
    .btn-action { width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; font-size: 12px; font-weight: bold; border: 1px solid transparent; cursor: pointer; transition: 0.2s; }
    .btn-action-view { background: #f5f5f5; color: #333; border-color: #ccc; }
    .btn-action-view:hover { background: #e0e0e0; }
    .btn-action-receive { background: #e3f2fd; color: #0d47a1; border-color: #bbdefb; }
    .btn-action-receive:hover { background: #2196f3; color: #fff; }
    .btn-action-print { background: #fff5f5; color: #b71c1c; border-color: #ffccd2; }
    .btn-action-print:hover { background: #d32f2f; color: #fff; }
    .btn-action-cancel { background: #fafafa; color: #757575; border-color: #e0e0e0; }
    .btn-action-cancel:hover { background: #d32f2f; color: #fff; border-color: #d32f2f; }

    /* Custom Badges */
    .asb-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; display: inline-block; }
    .badge-pending { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; }
    .badge-received { background: #e3f2fd; color: #0d47a1; border: 1px solid #bbdefb; }
    .badge-completed { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
    .badge-cancelled { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
    
    .asb-input { border: 1px solid #ccc; padding: 8px 12px; border-radius: 4px; width: 100%; font-size: 12px; box-sizing: border-box; background: #fff; }
    .asb-input:focus { border-color: #d32f2f; outline: none; box-shadow: 0 0 4px rgba(211,47,47,0.2); }
    .asb-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; }
    .asb-modal-content { background:white; padding:25px; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); max-height:90vh; overflow-y:auto; border-top: 4px solid #d32f2f; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    
    <!-- Management Control Title Bar -->
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom: 25px; gap: 15px;">
        <h2 class="asb-header-title">ASB Fashion <span style="font-weight:300; color:#555; font-size:16px;">| Purchase Orders Control Registry</span></h2>
        <a href="create_po.php" class="btn-asb btn-asb-success">➕ Create New PO</a>
    </div>

    <!-- Alert Notifications -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success" style="border-left:4px solid #2e7d32; background:#e8f5e9; color:#1b5e20; padding:12px; border-radius:4px; margin-bottom:20px; font-weight:600;">
            <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger" style="border-left:4px solid #d32f2f; background:#ffebee; color:#b71c1c; padding:12px; border-radius:4px; margin-bottom:20px; font-weight:600;">
            <?= $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Master Operational Summary Statistics -->
    <div class="stat-container">
        <?php $stats = getPOStats(); ?>
        <div class="asb-stat-box border-total">
            <span class="stat-label">Total Matrix</span>
            <span class="stat-value"><?= $stats['total']; ?></span>
        </div>
        <div class="asb-stat-box border-pending">
            <span class="stat-label" style="color: #e65100;">Pending Intake</span>
            <span class="stat-value" style="color: #e65100;"><?= $stats['pending']; ?></span>
        </div>
        <div class="asb-stat-box border-received">
            <span class="stat-label" style="color: #0d47a1;">Partially Received</span>
            <span class="stat-value" style="color: #0d47a1;"><?= $stats['received']; ?></span>
        </div>
        <div class="asb-stat-box border-completed">
            <span class="stat-label" style="color: #1b5e20;">Fully Completed</span>
            <span class="stat-value" style="color: #1b5e20;"><?= $stats['completed']; ?></span>
        </div>
        <div class="asb-stat-box border-cancelled">
            <span class="stat-label" style="color: #b71c1c;">Void / Cancelled</span>
            <span class="stat-value" style="color: #b71c1c;"><?= $stats['cancelled']; ?></span>
        </div>
    </div>

    <!-- Live Multicriteria Filtering Pipeline Panel -->
    <div class="asb-card">
        <div style="padding: 15px 20px; background: #fff;">
            <form method="GET" action="">
                <input type="hidden" name="page" value="pos">
                <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                    <div style="flex: 1; min-width: 180px;">
                        <label style="font-weight:600; margin-bottom:6px; display:block; font-size:11px; color:#555; text-transform:uppercase;">Workflow Status</label>
                        <select name="status" class="asb-input">
                            <option value="">-- View All Orders --</option>
                            <option value="Pending" <?= $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Received" <?= $status_filter == 'Received' ? 'selected' : ''; ?>>Received</option>
                            <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div style="flex: 2; min-width: 250px;">
                        <label style="font-weight:600; margin-bottom:6px; display:block; font-size:11px; color:#555; text-transform:uppercase;">Global Registry Search</label>
                        <input type="text" name="search" class="asb-input" placeholder="Search PO Code Reference or Vendor Supplier Identity Name..." value="<?= htmlspecialchars($search_filter); ?>">
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn-asb" style="padding: 9px 20px;">🔍 Apply Filters</button>
                        <a href="pos.php" class="btn-asb-secondary" style="padding: 8px 14px;">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Matrix View Grid Component -->
    <div class="asb-card">
        <div class="asb-card-header">
            <span>📋 Primary Procurement Ledger Overview</span>
            <span style="font-size: 11px; background: #b71c1c; color:#fff; padding: 2px 8px; border-radius: 10px; font-weight: normal;">Matches Found: <?= $total_pos; ?></span>
        </div>
        <div style="padding: 0; background: #fff;">
            <?php if (empty($pos_list)): ?>
                <div style="text-align:center; padding:50px; color:#7f8c8d;">
                    <div style="font-size:54px; margin-bottom: 10px;">📋</div>
                    <span style="font-weight:600; display:block; margin-bottom:10px;">No historical Purchase Orders matched your active pipeline filter parameters.</span>
                    <a href="create_po.php" class="btn-asb btn-asb-success" style="font-size:11px; padding:6px 12px;">Initialize First Entry</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table asb-table" style="width:100%; margin-bottom:0; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="width: 140px;">PO Code Ref</th>
                                <th>Supplier Vendor Matrix Identity</th>
                                <th style="width: 120px;">Order Date</th>
                                <th style="width: 130px;">Expected Delivery</th>
                                <th style="width: 130px; text-align:center;">Fulfillment Status</th>
                                <th style="width: 180px; text-align:left;">Action Workflow Suite</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pos_list as $po): 
                                $status = strtolower($po['status']);
                                $badgeClass = 'badge-pending';
                                if ($status === 'completed') $badgeClass = 'badge-completed';
                                elseif ($status === 'received') $badgeClass = 'badge-received';
                                elseif ($status === 'cancelled') $badgeClass = 'badge-cancelled';
                            ?>
                                <tr>
                                    <td><strong style="color:#111; letter-spacing:0.2px;"><?= $po['po_number']; ?></strong></td>
                                    <td><span style="font-weight:600; color:#444;"><?= htmlspecialchars($po['supplier_name']); ?></span></td>
                                    <td><span style="color:#555; font-weight:500;"><?= date('Y-m-d', strtotime($po['purchase_date'])); ?></span></td>
                                    <td><span style="color:#666;"><?= !empty($po['expected_delivery_date']) ? date('Y-m-d', strtotime($po['expected_delivery_date'])) : '<span style="color:#bbb; font-style:italic;">Not Specified</span>'; ?></span></td>
                                    <td style="text-align:center;"><span class="asb-badge <?= $badgeClass; ?>"><?= $po['status']; ?></span></td>
                                    <td>
                                        <div class="btn-action-group">
                                            <button class="btn-action btn-action-view" onclick="viewPO(<?= $po['po_id']; ?>)" title="Inspect Complete Struct Registry Details">👁️</button>
                                            <button class="btn-action btn-action-receive" onclick="receivePO(<?= $po['po_id']; ?>)" title="Commit Material Intake GRN Inbound Allocation">📥</button>
                                            <button class="btn-action btn-action-print" onclick="printPO(<?= $po['po_id']; ?>)" title="Allocation Report (Landscape)">🖨️</button>
                                            <button class="btn-action btn-action-print" onclick="printSupplierPO(<?= $po['po_id']; ?>)" title="Supplier PO (A4 Portrait)">📄</button>
                                            <?php if ($po['status'] != 'Cancelled' && $po['status'] != 'Completed'): ?>
                                                <button class="btn-action btn-action-cancel" onclick="cancelPO(<?= $po['po_id']; ?>)" title="Revoke Allocation &amp; Flag Terminated Status">✖</button>
                                            <?php endif; ?>
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

    <!-- Framework Pagination Bar Engine -->
    <?php if ($total_pages > 1): ?>
        <div style="margin-top:20px; display:flex; gap:4px; justify-content:center;">
            <?php if ($current_page > 1): ?>
                <a href="?page=pos&page_num=<?= $current_page-1; ?>&status=<?= $status_filter; ?>&search=<?= urlencode($search_filter); ?>" class="btn-asb-secondary" style="padding: 6px 12px;">← Prev</a>
            <?php endif; ?>
            
            <?php for ($i=1; $i<=$total_pages; $i++): ?>
                <a href="?page=pos&page_num=<?= $i; ?>&status=<?= $status_filter; ?>&search=<?= urlencode($search_filter); ?>" 
                   style="padding: 6px 12px; border: 1px solid #ccc; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; <?= $i == $current_page ? 'background:#d32f2f; color:#fff; border-color:#d32f2f;' : 'background:#fff; color:#333;'; ?>">
                    <?= $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="?page=pos&page_num=<?= $current_page+1; ?>&status=<?= $status_filter; ?>&search=<?= urlencode($search_filter); ?>" class="btn-asb-secondary" style="padding: 6px 12px;">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- High Density Struct Details View Modal -->
<div id="viewModal" class="asb-modal">
    <div class="asb-modal-content" style="max-width:850px; width:95%;">
        <h4 style="color:#b71c1c; font-weight:800; border-bottom:2px solid #eee; padding-bottom:10px; margin-top:0; margin-bottom:15px; text-transform:uppercase; letter-spacing:0.5px;">📋 Procurement Core Audit Breakdown</h4>
        <div id="viewContent"></div>
        <div style="margin-top:20px; text-align:right; border-top:1px solid #eee; padding-top:15px;">
            <button class="btn-asb" onclick="document.getElementById('viewModal').style.display='none'">Dismiss Details</button>
        </div>
    </div>
</div>

<!-- Material Batch Delivery Receipt Intake Modal -->
<div id="receiveModal" class="asb-modal">
    <div class="asb-modal-content" style="max-width:750px; width:95%;">
        <h4 style="color:#b71c1c; font-weight:800; border-bottom:2px solid #eee; padding-bottom:10px; margin-top:0; margin-bottom:15px; text-transform:uppercase; letter-spacing:0.5px;">📥 Commit Log Inbound Cargo Intake Run</h4>
        <form id="receiveForm">
            <div id="receiveContent"></div>
            <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end; border-top:1px solid #eee; padding-top:15px;">
                <button type="button" class="btn-asb-secondary" onclick="document.getElementById('receiveModal').style.display='none'">Cancel Inbound</button>
                <button type="submit" class="btn-asb btn-asb-success">Save Freight Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ---- View PO Details Script Integration ----
    function viewPO(poId) {
        fetch(`api.php?action=getPO&po_id=${poId}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                document.getElementById('viewContent').innerHTML = renderPODetails(data);
                document.getElementById('viewModal').style.display = 'flex';
            })
            .catch(err => alert('Internal API Core Matrix Communication Failure: ' + err));
    }

    function renderPODetails(po) {
        let html = `<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:20px; background:#f9f9f9; padding:15px; border-radius:6px; border:1px solid #e0e0e0;">
            <div><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">PO Code Reference</span><strong style="font-size:14px; color:#b71c1c;">${po.po_number}</strong></div>
            <div><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">Supplier Account Identity</span><strong style="color:#333;">${po.supplier_name}</strong></div>
            <div><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">Base Procurement Date</span><strong>${po.purchase_date}</strong></div>
            <div><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">Expected Delivery ETA</span><strong>${po.expected_delivery_date || 'N/A'}</strong></div>
            <div><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">Workflow Track Status</span><span style="color:#b71c1c; font-weight:bold; text-transform:uppercase;">${po.status}</span></div>
            <div><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">Attention Destination Target</span><strong>${po.attention || '-'}</strong></div>
            <div style="grid-column: span 2;"><span style="font-size:11px; text-transform:uppercase; font-weight:bold; color:#777; display:block;">Audit Administrative Remarks</span><span style="font-style:italic; color:#555;">${po.remarks || 'No internal tracking annotations committed.'}</span></div>
        </div>`;
        html += `<table class="table" style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="border-bottom:2px solid #b71c1c; background:#f5f5f5;">
                    <th style="padding:10px; text-align:left; font-weight:bold; text-transform:uppercase;">Item Specification Description</th>
                    <th style="padding:10px; text-align:right; font-weight:bold; text-transform:uppercase; width:100px;">Target Vol</th>
                    <th style="padding:10px; text-align:right; font-weight:bold; text-transform:uppercase; width:100px;">Received Vol</th>
                    <th style="padding:10px; text-align:right; font-weight:bold; text-transform:uppercase; width:110px;">Unit Cost Price</th>
                    <th style="padding:10px; text-align:right; font-weight:bold; text-transform:uppercase; width:110px;">Unit Retail Sell</th>
                </tr>
            </thead><tbody>`;
        po.items.forEach(item => {
            html += `<tr style="border-bottom:1px solid #eee;">
                <td style="padding:10px; font-weight:600; color:#222;">${item.item_name}</td>
                <td style="padding:10px; text-align:right; font-weight:bold; color:#555;">${parseFloat(item.quantity).toLocaleString()}</td>
                <td style="padding:10px; text-align:right; font-weight:bold; color:#2e7d32;">${parseFloat(item.received_qty).toLocaleString()}</td>
                <td style="padding:10px; text-align:right; font-weight:500; color:#444;">${parseFloat(item.cost_price).toFixed(2)}</td>
                <td style="padding:10px; text-align:right; font-weight:500; color:#b71c1c;">${parseFloat(item.selling_price).toFixed(2)}</td>
            </tr>`;
        });
        html += `</tbody></table>`;
        return html;
    }

    // ---- Inbound Goods Received Allocation Script Logic ----
    function receivePO(poId) {
        fetch(`api.php?action=getPO&po_id=${poId}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                let html = `<div style="background:#fff5f5; border:1px solid #ffccd2; padding:12px; border-radius:4px; margin-bottom:15px; font-size:12px;">
                    <strong>Active PO Reference Target:</strong> <span style="color:#b71c1c; font-weight:bold;">${data.po_number}</span> | <strong>Vendor Supplier:</strong> <strong>${data.supplier_name}</strong>
                </div>`;
                html += `<table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#f5f5f5; border-bottom:2px solid #b71c1c;">
                            <th style="padding:8px; text-align:left; text-transform:uppercase; font-weight:bold;">Item Detail Specs</th>
                            <th style="padding:8px; text-align:right; text-transform:uppercase; font-weight:bold; width:90px;">Ordered</th>
                            <th style="padding:8px; text-align:right; text-transform:uppercase; font-weight:bold; width:90px;">Accum Received</th>
                            <th style="padding:8px; text-align:right; text-transform:uppercase; font-weight:bold; width:120px;">Commit Quantity Now</th>
                        </tr>
                    </thead><tbody>`;
                data.items.forEach(item => {
                    let max = item.quantity - item.received_qty;
                    html += `<tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:8px; font-weight:600; color:#333;">${item.item_name}</td>
                        <td style="padding:8px; text-align:right; color:#666;">${item.quantity}</td>
                        <td style="padding:8px; text-align:right; font-weight:bold; color:#2e7d32;">${item.received_qty}</td>
                        <td style="padding:8px; text-align:right;">
                            <input type="number" name="received[${item.po_item_id}]" class="asb-input" value="${max}" min="0" max="${max}" style="width:100px; text-align:right; font-weight:bold; color:#b71c1c;">
                        </td>
                    </tr>`;
                });
                html += `</tbody></table>`;
                document.getElementById('receiveContent').innerHTML = html;
                document.getElementById('receiveModal').style.display = 'flex';
                document.getElementById('receiveForm').dataset.poId = poId;
            })
            .catch(err => alert('Internal Logistics API Core Initialization Failure: ' + err));
    }

    document.getElementById('receiveForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const poId = this.dataset.poId;
        const formData = new FormData(this);
        formData.append('action', 'receivePO');
        formData.append('po_id', poId);
        fetch('api.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Inbound stock batch freight run committed successfully to verification logs!');
                location.reload();
            } else {
                alert('Operational Data Entry Constraint: ' + data.message);
            }
        })
        .catch(err => alert('Internal System Save Matrix Integration Exception: ' + err));
    });

    // ---- Print Profile Form Generation Framework ----
    function printPO(poId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'print_po.php';
        form.target = '_blank';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'po_id';
        input.value = poId;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // ---- Print Supplier PO Report ----
    function printSupplierPO(poId) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'print_po_supplier.php';
        form.target = '_blank';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'po_id';
        input.value = poId;
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // ---- Cancel Operational Status Command Run ----
    function cancelPO(poId) {
        if (!confirm('CRITICAL ACTION: Are you absolutely certain you want to terminate and cancel this Purchase Order allocation? This structural change cannot be rolled back.')) return;
        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=cancelPO&po_id=${poId}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Target order state successfully updated to Void/Terminated.');
                location.reload();
            } else {
                alert('Data Protection Rejection: ' + data.message);
            }
        })
        .catch(err => alert('Central Processing Unit Execution Refusal: ' + err));
    }

    // Close modals on outside click
    document.querySelectorAll('#viewModal, #receiveModal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>