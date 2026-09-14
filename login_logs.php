<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
startSession();
requireLogin();

if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Login Logs';
$page = 'login_logs';
include 'includes/header.php';
include 'includes/sidebar.php';

$conn = getConnection();

// ---- Filters ----
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build a unique filter hash to store cursor stack per filter set
$filterHash = md5(serialize([$userId, $dateFrom, $dateTo, $search]));

// ---- Cursor Pagination ----
$limit = 50;

// Determine action: next or prev
$action = $_GET['action'] ?? '';

// Initialize session stack for this filter if not exists
if (!isset($_SESSION['log_cursors'])) {
    $_SESSION['log_cursors'] = [];
}
if (!isset($_SESSION['log_cursors'][$filterHash])) {
    $_SESSION['log_cursors'][$filterHash] = [];
}
$stack = &$_SESSION['log_cursors'][$filterHash];

$lastTime = null;
$lastId = null;
$direction = 'next'; // default

if ($action === 'next') {
    // Use the last record from the current page as cursor
    $lastTime = $_GET['last_time'] ?? null;
    $lastId = $_GET['last_id'] ?? null;
    if ($lastTime && $lastId) {
        // Push current cursor onto stack for 'prev' (we store the cursor that brought us here)
        // Actually, we want to store the cursor of the page we are leaving, so that we can come back.
        // We'll store the current page's first record as the "prev" cursor.
        // But we don't know the first record until we fetch. So we store the last record as cursor
        // to go forward, and for going back we need to store the first record.
        // Let's simplify: we store the cursor of the page we are currently on (the first record)
        // so we can go back to it.
        // Instead, we'll store the "previous" cursor in the stack: the cursor that was used to get this page.
        // When we click 'next', we store the current page's first record as the "prev" cursor.
        // But we don't have that yet. So we'll fetch the current page first, then store the first record.
        // For simplicity, we'll store the last record as the "previous" cursor? Not ideal.
        // Better: store the cursor of the page we are leaving (the one that brought us here).
        // We'll do this: when we navigate, we push the current cursor to stack, then move.
        // So on 'next', we push the current cursor (which is the last_time and last_id that were used to load this page)
        // onto the stack, then fetch the next page.
        // But we need to know the current cursor that was used to load this page. That is stored in $_GET['last_time'] and $_GET['last_id'].
        // So we push those to stack.
        if ($_GET['last_time'] && $_GET['last_id']) {
            $stack[] = ['last_time' => $_GET['last_time'], 'last_id' => $_GET['last_id']];
            // Keep stack size reasonable
            if (count($stack) > 100) array_shift($stack);
        }
        // Then we fetch the next page using the last_time and last_id from the current page's last record.
        // We'll get the last record from the current page, but we don't have it yet. So we need to fetch the current page first.
        // This becomes complicated. Let's restructure: we'll fetch the page based on the cursor.
        // On the first page, we have no cursor. We fetch from the beginning.
        // On subsequent pages, we pass the cursor (last_time, last_id) from the previous page.
        // For "prev", we pop the last cursor from the stack.
        // So we need to store the cursor that was used to fetch the current page.
        // We'll store it in the session when we render.
        // We'll use a simpler approach: store all previous cursors in an array, and use a pointer.
        // Or we can use a session variable to store the current page's cursor.
    }
}

// Since this logic gets complex, we'll implement a simpler cursor-based pagination:
// We'll use a "next" and "prev" that rely on the last_time and last_id from the current page.
// We'll store the current page's cursor in the session, so we can go back.

// For the first load, we have no cursor.
$cursor = null;
$stack = $_SESSION['log_cursors'][$filterHash] ?? [];

if ($action === 'next') {
    // Use the last record of the current page as cursor for next
    $lastTime = $_GET['last_time'] ?? null;
    $lastId = $_GET['last_id'] ?? null;
    if ($lastTime && $lastId) {
        // Store the current cursor (which is the one we used to load this page) into stack for prev
        $currentCursor = $_GET['current_time'] ?? null;
        $currentId = $_GET['current_id'] ?? null;
        if ($currentCursor && $currentId) {
            $stack[] = ['last_time' => $currentCursor, 'last_id' => $currentId];
            $_SESSION['log_cursors'][$filterHash] = $stack;
        }
        // Now fetch using the new cursor (lastTime, lastId)
        $cursor = ['last_time' => $lastTime, 'last_id' => $lastId];
    }
} elseif ($action === 'prev') {
    // Pop the last cursor from stack
    if (!empty($stack)) {
        $prev = array_pop($stack);
        $_SESSION['log_cursors'][$filterHash] = $stack;
        $cursor = ['last_time' => $prev['last_time'], 'last_id' => $prev['last_id']];
    }
}

// Now fetch logs using cursor or from start
if ($cursor) {
    $logs = getLoginLogsCursor($userId, $dateFrom, $dateTo, $search, $limit, $cursor['last_time'], $cursor['last_id']);
} else {
    // First page: fetch from beginning
    $logs = getLoginLogsCursor($userId, $dateFrom, $dateTo, $search, $limit);
}

// Get total count for display (but we only need it for the info, not for pagination)
$totalLogs = countLoginLogs($userId, $dateFrom, $dateTo, $search);
$totalPages = ceil($totalLogs / $limit); // approximate, not used

// Determine if there are more records
$hasMore = (count($logs) == $limit);

// Get the first and last record for cursor info
$firstRecord = !empty($logs) ? $logs[0] : null;
$lastRecord = !empty($logs) ? $logs[count($logs)-1] : null;

// Build query params for links
$queryParams = http_build_query(array_filter([
    'user_id' => $userId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => $search
]));

// Get all users for filter dropdown
$users = [];
$userResult = $conn->query("SELECT id, username FROM po_users ORDER BY username");
while ($row = $userResult->fetch_assoc()) {
    $users[] = $row;
}
$userResult->free();
?>

<style>
    .filter-bar { background: white; padding: 20px; border-radius: 16px; margin-bottom: 25px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
    .filter-bar .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; align-items: end; }
    .filter-bar .filter-row .form-group { margin-bottom: 0; }
    .filter-bar .filter-row label { font-size: 12px; font-weight: 600; color: #444; display: block; margin-bottom: 3px; }
    .filter-bar .filter-row input, .filter-bar .filter-row select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
    .filter-bar .filter-row .btn { padding: 8px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; }
    .btn-primary { background: #b91c1c; color: white; }
    .btn-primary:hover { background: #7f1d1d; }
    .btn-secondary { background: #e2e8f0; color: #333; }
    .btn-secondary:hover { background: #cbd5e1; }

    .table-wrapper { overflow-x: auto; }
    .table { width: 100%; border-collapse: collapse; }
    .table thead { background: #f8fafc; }
    .table th { padding: 12px 15px; text-align: left; font-weight: 600; color: #1e293b; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
    .table tbody tr:hover { background: #fef2f2; }
    .badge-role { display: inline-block; padding: 2px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-admin { background: #b91c1c; color: white; }
    .badge-user { background: #2c3e50; color: white; }

    .pagination { display: flex; justify-content: center; gap: 15px; margin-top: 20px; flex-wrap: wrap; align-items: center; }
    .pagination a, .pagination span { display: inline-block; padding: 6px 18px; border: 1px solid #e2e8f0; border-radius: 8px; color: #333; transition: 0.2s; text-decoration: none; }
    .pagination a:hover { background: #b91c1c; color: white; border-color: #b91c1c; }
    .pagination .disabled { opacity: 0.5; cursor: not-allowed; }
    .pagination .info { color: #64748b; font-size: 14px; }

    .user-agent-cell { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .alert-warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; color: #856404; }
    @media (max-width: 768px) {
        .filter-bar .filter-row { grid-template-columns: 1fr; }
    }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <div class="page-header">
        <h2><i class="fas fa-history"></i> Login Logs</h2>
        <span style="font-size:14px; color:#888;">Total: <?php echo number_format($totalLogs); ?> entries</span>
    </div>

    <?php if ($totalLogs > 100000): ?>
        <div class="alert-warning">
            <i class="fas fa-info-circle"></i> Large dataset detected. Using optimized cursor pagination for faster navigation.
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="form-group">
                    <label>User</label>
                    <select name="user_id">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $userId == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Username, IP, or User Agent" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group" style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="login_logs.php" class="btn btn-secondary"><i class="fas fa-times"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="card" style="background:white; border-radius:16px; box-shadow:0 2px 12px rgba(0,0,0,0.05); overflow:hidden;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Login Time</th>
                        <th>IP Address</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="padding:40px; text-align:center; color:#94a3b8;">No login records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log['id']; ?></td>
                                <td><?php echo htmlspecialchars($log['username']); ?></td>
                                <td><span class="badge-role <?php echo $log['role'] === 'admin' ? 'badge-admin' : 'badge-user'; ?>"><?php echo ucfirst($log['role']); ?></span></td>
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['login_time'])); ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                <td class="user-agent-cell" title="<?php echo htmlspecialchars($log['user_agent']); ?>"><?php echo htmlspecialchars($log['user_agent']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Cursor Pagination -->
    <?php if (!empty($logs)): ?>
        <div class="pagination">
            <?php
            // Previous link – only if stack is not empty
            $prevDisabled = empty($stack);
            ?>
            <?php if (!$prevDisabled): ?>
                <a href="?action=prev&<?php echo $queryParams; ?>" class="btn btn-secondary" style="padding:6px 18px;">← Previous</a>
            <?php else: ?>
                <span class="disabled">← Previous</span>
            <?php endif; ?>

            <span class="info">Showing <?php echo count($logs); ?> records</span>

            <?php if ($hasMore): ?>
                <a href="?action=next&last_time=<?php echo urlencode($lastRecord['login_time']); ?>&last_id=<?php echo $lastRecord['id']; ?>&current_time=<?php echo urlencode($firstRecord['login_time']); ?>&current_id=<?php echo $firstRecord['id']; ?>&<?php echo $queryParams; ?>" class="btn btn-primary" style="padding:6px 18px;">Next →</a>
            <?php else: ?>
                <span class="disabled">Next →</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>