<?php
$page_title = 'Suppliers';
$page = 'suppliers';

// Get search and pagination parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$current_page = isset($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$limit = 10; // Items per page
$offset = ($current_page - 1) * $limit;

// Get suppliers with search and pagination
$suppliers = getSuppliers($search, $limit, $offset);
$total_suppliers = countSuppliers($search);
$total_pages = ceil($total_suppliers / $limit);

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<div class="page-header">
    <h2>🚚 Suppliers <span style="font-size:16px; color:#7f8c8d; font-weight:normal;">(<?php echo $total_suppliers; ?> total)</span></h2>
</div>

<!-- Search Bar -->
<div class="filter-bar">
    <form method="GET" class="filter-row">
        <input type="hidden" name="page" value="suppliers">
        <div class="form-group">
            <label>Search Suppliers</label>
            <input type="text" name="search" class="form-control" placeholder="Name, contact, or email..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-primary" style="width:100%;">🔍 Search</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($suppliers)): ?>
            <div style="text-align:center; padding:40px; color:#7f8c8d;">
                <div style="font-size:48px;">🚚</div>
                No suppliers found. 
                <?php if ($search): ?>
                    <a href="?page=suppliers">Clear search</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Phone</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($suppliers as $s): ?>
                            <tr>
                                <td><?php echo $s['supplier_id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($s['supplier_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['contact_number'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['contact_number'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?page=suppliers&page_num=<?php echo $current_page-1; ?>&search=<?php echo urlencode($search); ?>">← Prev</a>
                    <?php else: ?>
                        <span class="disabled">← Prev</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=suppliers&page_num=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="?page=suppliers&page_num=<?php echo $current_page+1; ?>&search=<?php echo urlencode($search); ?>">Next →</a>
                    <?php else: ?>
                        <span class="disabled">Next →</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include ROOT_PATH . 'includes/footer.php'; ?>