<?php
$page_title = 'Dashboard';
$page = 'dashboard';

// Use helper functions from includes/functions.php
// These functions handle database connection internally
$total_suppliers = getTotalSuppliers();
$total_pos = getTotalPOs();
$pending_pos = getPendingPOs();
$total_items = getTotalItems();
$recent = getRecentPOs();

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>
<div class="welcome-banner">
    <h2>👋 Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
    <p>Manage your purchase orders, suppliers, and items efficiently.</p>
</div>
<div class="stats-row">
    <div class="stat-card primary">
        <span class="stat-icon">📄</span>
        <div class="stat-label">Total POs</div>
        <div class="stat-number"><?php echo $total_pos; ?></div>
    </div>
    <div class="stat-card warning">
        <span class="stat-icon">⏳</span>
        <div class="stat-label">Pending POs</div>
        <div class="stat-number"><?php echo $pending_pos; ?></div>
    </div>
    <div class="stat-card success">
        <span class="stat-icon">🚚</span>
        <div class="stat-label">Suppliers</div>
        <div class="stat-number"><?php echo $total_suppliers; ?></div>
    </div>
    <div class="stat-card info">
        <span class="stat-icon">📦</span>
        <div class="stat-label">Items</div>
        <div class="stat-number"><?php echo $total_items; ?></div>
    </div>
</div>
<div class="card">
    <div class="card-header"><h5>📋 Recent Purchase Orders</h5></div>
    <div class="card-body">
        <?php if (empty($recent)): ?>
            <div class="alert alert-info">No POs found. <a href="?page=pos&action=create">Create your first PO</a></div>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr><th>PO #</th><th>Supplier</th><th>Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $po): ?>
                            <tr>
                                <td><strong><?php echo $po['po_number']; ?></strong></td>
                                <td><?php echo $po['supplier_name']; ?></td>
                                <td><?php echo $po['purchase_date']; ?></td>
                                <td><span class="badge badge-<?php echo strtolower($po['status']); ?>"><?php echo $po['status']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include ROOT_PATH . 'includes/footer.php'; ?>