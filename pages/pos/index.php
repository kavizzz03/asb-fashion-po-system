<?php
$page_title = 'Purchase Orders';
$page = 'pos';

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_filter = isset($_GET['search']) ? $_GET['search'] : '';
$items_per_page = 10;
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Use functions without passing $conn
$total_pos = countPOs($status_filter, $search_filter);
$total_pages = ceil($total_pos / $items_per_page);
$pos_list = getPOs($status_filter, $search_filter, $items_per_page, $offset);

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>
<div class="page-header">
    <h2>📋 Purchase Orders</h2>
    <a href="?page=pos&action=create" class="btn btn-success">➕ Create New PO</a>
</div>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<div class="filter-bar">
    <form method="GET" class="filter-row">
        <input type="hidden" name="page" value="pos">
        <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="">All</option>
                <option value="Pending" <?php echo $status_filter=='Pending'?'selected':''; ?>>Pending</option>
                <option value="Received" <?php echo $status_filter=='Received'?'selected':''; ?>>Received</option>
                <option value="Completed" <?php echo $status_filter=='Completed'?'selected':''; ?>>Completed</option>
                <option value="Cancelled" <?php echo $status_filter=='Cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="form-group">
            <label>Search</label>
            <input type="text" name="search" class="form-control" placeholder="PO # or Supplier" value="<?php echo htmlspecialchars($search_filter); ?>">
        </div>
        <div class="form-group">
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
                No POs found. <a href="?page=pos&action=create">Create one</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead><tr><th>PO #</th><th>Supplier</th><th>Date</th><th>Expected</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($pos_list as $po): ?>
                            <tr>
                                <td><strong><?php echo $po['po_number']; ?></strong></td>
                                <td><?php echo $po['supplier_name']; ?></td>
                                <td><?php echo $po['purchase_date']; ?></td>
                                <td><?php echo $po['expected_delivery_date'] ?? 'N/A'; ?></td>
                                <td><span class="badge badge-<?php echo strtolower($po['status']); ?>"><?php echo $po['status']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
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
<?php include ROOT_PATH . 'includes/footer.php'; ?>