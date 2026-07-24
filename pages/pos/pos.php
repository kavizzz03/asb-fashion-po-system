<?php
$page_title = 'Purchase Orders';
$page = 'pos';

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_filter = isset($_GET['search']) ? $_GET['search'] : '';
$items_per_page = 10;
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Functions should be defined in includes/functions.php
$total_pos = countPOs($status_filter, $search_filter);
$total_pages = ceil($total_pos / $items_per_page);
$pos_list = getPOs($status_filter, $search_filter, $items_per_page, $offset);

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>
<div class="page-header" style="display:flex; justify-content:space-between; flex-wrap:wrap;">
    <h2>📋 Purchase Orders</h2>
    <a href="?page=create_po" class="btn btn-success">➕ Create New PO</a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Stats Summary (optional) -->
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:15px; margin-bottom:20px;">
    <?php
    $stats = getPOStats(); // returns ['total','pending','received','completed','cancelled']
    ?>
    <div class="stat-box"><span class="stat-label">Total</span><span class="stat-value"><?php echo $stats['total']; ?></span></div>
    <div class="stat-box bg-pending"><span class="stat-label">Pending</span><span class="stat-value"><?php echo $stats['pending']; ?></span></div>
    <div class="stat-box bg-received"><span class="stat-label">Received</span><span class="stat-value"><?php echo $stats['received']; ?></span></div>
    <div class="stat-box bg-completed"><span class="stat-label">Completed</span><span class="stat-value"><?php echo $stats['completed']; ?></span></div>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-row" style="display:flex; flex-wrap:wrap; gap:10px; align-items:end;">
        <input type="hidden" name="page" value="pos">
        <div class="form-group" style="flex:1; min-width:150px;">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="">All</option>
                <option value="Pending" <?php echo $status_filter=='Pending'?'selected':''; ?>>Pending</option>
                <option value="Received" <?php echo $status_filter=='Received'?'selected':''; ?>>Received</option>
                <option value="Completed" <?php echo $status_filter=='Completed'?'selected':''; ?>>Completed</option>
                <option value="Cancelled" <?php echo $status_filter=='Cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="form-group" style="flex:2; min-width:200px;">
            <label>Search</label>
            <input type="text" name="search" class="form-control" placeholder="PO # or Supplier" value="<?php echo htmlspecialchars($search_filter); ?>">
        </div>
        <div class="form-group" style="flex:0;">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary" style="width:100%;">🔍 Filter</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h5>All POs <span style="font-size:14px; color:#7f8c8d; font-weight:normal;">(<?php echo $total_pos; ?> total)</span></h5>
    </div>
    <div class="card-body">
        <?php if (empty($pos_list)): ?>
            <div style="text-align:center; padding:40px; color:#7f8c8d;">
                <div style="font-size:48px;">📋</div>
                No POs found. <a href="?page=create_po">Create one</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th>PO #</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th>Expected</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pos_list as $po): ?>
                            <tr>
                                <td><strong><?php echo $po['po_number']; ?></strong></td>
                                <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                <td><?php echo $po['purchase_date']; ?></td>
                                <td><?php echo $po['expected_delivery_date'] ?? 'N/A'; ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($po['status']); ?>">
                                        <?php echo $po['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewPO(<?php echo $po['po_id']; ?>)">👁️</button>
                                    <button class="btn btn-sm btn-warning" onclick="receivePO(<?php echo $po['po_id']; ?>)">📥</button>
                                    <button class="btn btn-sm btn-secondary" onclick="printPO(<?php echo $po['po_id']; ?>)">🖨️</button>
                                    <?php if ($po['status'] != 'Cancelled' && $po['status'] != 'Completed'): ?>
                                        <button class="btn btn-sm btn-danger" onclick="cancelPO(<?php echo $po['po_id']; ?>)">✖</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="pagination" style="margin-top:15px; display:flex; gap:5px; justify-content:center;">
                    <?php if ($current_page > 1): ?>
                        <a href="?page=pos&page_num=<?php echo $current_page-1; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_filter); ?>">← Prev</a>
                    <?php else: ?>
                        <span class="disabled">← Prev</span>
                    <?php endif; ?>
                    <?php for ($i=1; $i<=$total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=pos&page_num=<?php echo $i; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_filter); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?page=pos&page_num=<?php echo $current_page+1; ?>&status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_filter); ?>">Next →</a>
                    <?php else: ?>
                        <span class="disabled">Next →</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modals for View/Receive (can be implemented with AJAX) -->
<div id="viewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:25px; border-radius:15px; max-width:900px; width:95%; max-height:90vh; overflow-y:auto;">
        <h4>PO Details</h4>
        <div id="viewContent"></div>
        <button class="btn btn-secondary" onclick="document.getElementById('viewModal').style.display='none'">Close</button>
    </div>
</div>

<div id="receiveModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:white; padding:25px; border-radius:15px; max-width:700px; width:95%; max-height:90vh; overflow-y:auto;">
        <h4>Receive PO Items</h4>
        <form id="receiveForm">
            <div id="receiveContent"></div>
            <div style="margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('receiveModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-success">Save Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
    // ---- View PO ----
    function viewPO(poId) {
        fetch(`?page=api&action=getPO&po_id=${poId}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                document.getElementById('viewContent').innerHTML = renderPODetails(data);
                document.getElementById('viewModal').style.display = 'flex';
            })
            .catch(err => alert('Error: ' + err));
    }

    function renderPODetails(po) {
        let html = `<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <div><strong>PO #:</strong> ${po.po_number}</div>
            <div><strong>Supplier:</strong> ${po.supplier_name}</div>
            <div><strong>Date:</strong> ${po.purchase_date}</div>
            <div><strong>Expected:</strong> ${po.expected_delivery_date || 'N/A'}</div>
            <div><strong>Status:</strong> ${po.status}</div>
            <div><strong>Attention:</strong> ${po.attention || '-'}</div>
            <div style="grid-column: span 2;"><strong>Remarks:</strong> ${po.remarks || '-'}</div>
        </div>`;
        html += `<table class="table" style="width:100%; border-collapse:collapse; margin-top:15px;">
            <thead><tr><th>Item</th><th>Ordered</th><th>Received</th><th>Cost</th><th>Sell</th></tr></thead><tbody>`;
        po.items.forEach(item => {
            html += `<tr><td>${item.item_name}</td><td>${item.qty}</td><td>${item.received_qty}</td><td>${item.cost_price}</td><td>${item.selling_price}</td></tr>`;
        });
        html += `</tbody></table>`;
        return html;
    }

    // ---- Receive PO ----
    function receivePO(poId) {
        fetch(`?page=api&action=getPO&po_id=${poId}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                let html = `<p><strong>PO:</strong> ${data.po_number} | <strong>Supplier:</strong> ${data.supplier_name}</p>`;
                html += `<table class="table"><thead><tr><th>Item</th><th>Ordered</th><th>Received so far</th><th>Receive Now</th></tr></thead><tbody>`;
                data.items.forEach((item, idx) => {
                    let max = item.qty - item.received_qty;
                    html += `<tr>
                        <td>${item.item_name}</td>
                        <td>${item.qty}</td>
                        <td>${item.received_qty}</td>
                        <td><input type="number" name="received[${item.po_item_id}]" class="form-control" value="${max}" min="0" max="${max}" style="width:80px;"></td>
                    </tr>`;
                });
                html += `</tbody></table>`;
                document.getElementById('receiveContent').innerHTML = html;
                document.getElementById('receiveModal').style.display = 'flex';
                document.getElementById('receiveForm').dataset.poId = poId;
            })
            .catch(err => alert('Error: ' + err));
    }

    document.getElementById('receiveForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const poId = this.dataset.poId;
        const formData = new FormData(this);
        formData.append('action', 'receivePO');
        formData.append('po_id', poId);
        fetch('?page=api', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Receipt saved!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => alert('Error: ' + err));
    });

    // ---- Print PO ----
    function printPO(poId) {
        // Open print_po.php with PO id via POST or GET
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=print_po';
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

    // ---- Cancel PO ----
    function cancelPO(poId) {
        if (!confirm('Cancel this PO? This cannot be undone.')) return;
        fetch(`?page=api&action=cancelPO&po_id=${poId}`, { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('PO cancelled.');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => alert('Error: ' + err));
    }

    // Close modals on outside click
    document.querySelectorAll('#viewModal, #receiveModal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });
</script>

<style>
    .stat-box { background:#f8f9fa; padding:10px; border-radius:8px; text-align:center; }
    .stat-box .stat-label { display:block; font-size:12px; color:#7f8c8d; }
    .stat-box .stat-value { font-size:24px; font-weight:bold; }
    .bg-pending { background:#fff3cd; }
    .bg-received { background:#cce5ff; }
    .bg-completed { background:#d4edda; }
    .badge { padding:3px 8px; border-radius:12px; font-size:12px; }
    .badge-pending { background:#ffc107; color:#212529; }
    .badge-received { background:#17a2b8; color:white; }
    .badge-completed { background:#28a745; color:white; }
    .badge-cancelled { background:#dc3545; color:white; }
    .btn-sm { padding:2px 8px; font-size:12px; }
    .table-responsive { overflow-x:auto; }
    table th, table td { padding:8px 12px; border-bottom:1px solid #dee2e6; text-align:left; }
    .pagination a, .pagination span { display:inline-block; padding:6px 12px; border:1px solid #ddd; margin:0 2px; text-decoration:none; }
    .pagination .active { background:#007bff; color:white; border-color:#007bff; }
    .pagination .disabled { color:#6c757d; pointer-events:none; }
    .filter-row .form-group { margin-bottom:0; }
    @media (max-width:768px) { .filter-row .form-group { flex:1 1 100%; } }
</style>

<?php include ROOT_PATH . 'includes/footer.php'; ?>