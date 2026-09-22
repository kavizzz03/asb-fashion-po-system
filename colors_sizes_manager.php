<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

$page_title = 'ASB Fashion | Colors & Sizes Manager';
$page = 'colors_sizes';

// ============================================================
// Database configuration
// ============================================================
$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'po_system';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ============================================================
// Handle AJAX / POST requests
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    // ==========================================================
    // COLORS CRUD
    // ==========================================================

    // ---- FETCH COLORS (with pagination + search) ----
    if ($action === 'fetch_colors') {
        try {
            $search = trim($_GET['search'] ?? '');
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = max(1, min(500, intval($_GET['limit'] ?? 50))); 
            $offset = ($page - 1) * $limit;

            $where = '';
            $params = [];
            if ($search !== '') {
                $where = "WHERE color_name LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM colors $where");
            $stmt->execute($params);
            $total = (int)$stmt->fetch()['total'];

            $stmt = $pdo->prepare("SELECT color_id, color_name, created_at, updated_at FROM colors $where ORDER BY color_name ASC LIMIT :limit OFFSET :offset");
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data' => $rows,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit)
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---- ADD COLOR ----
    if ($action === 'add_color') {
        $color_name = trim($_POST['color_name'] ?? '');
        if (empty($color_name)) {
            echo json_encode(['success' => false, 'message' => 'Color name is required.']);
            exit;
        }
        if (strlen($color_name) > 50) {
            echo json_encode(['success' => false, 'message' => 'Color name exceeds 50 characters.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT color_id FROM colors WHERE color_name = ?");
            $stmt->execute([$color_name]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Color already exists.']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO colors (color_name) VALUES (?)");
            $stmt->execute([$color_name]);
            $newId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT color_id, color_name, created_at, updated_at FROM colors WHERE color_id = ?");
            $stmt->execute([$newId]);
            $row = $stmt->fetch();

            echo json_encode(['success' => true, 'message' => 'Color added successfully!', 'data' => $row]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- UPDATE COLOR ----
    if ($action === 'update_color') {
        $color_id = intval($_POST['color_id'] ?? 0);
        $color_name = trim($_POST['color_name'] ?? '');

        if ($color_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid color ID.']);
            exit;
        }
        if (empty($color_name)) {
            echo json_encode(['success' => false, 'message' => 'Color name is required.']);
            exit;
        }
        if (strlen($color_name) > 50) {
            echo json_encode(['success' => false, 'message' => 'Color name exceeds 50 characters.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT color_id FROM colors WHERE color_name = ? AND color_id != ?");
            $stmt->execute([$color_name, $color_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Another color with this name already exists.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT color_id FROM colors WHERE color_id = ?");
            $stmt->execute([$color_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Color not found.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE colors SET color_name = ? WHERE color_id = ?");
            $stmt->execute([$color_name, $color_id]);

            $stmt = $pdo->prepare("SELECT color_id, color_name, created_at, updated_at FROM colors WHERE color_id = ?");
            $stmt->execute([$color_id]);
            $row = $stmt->fetch();

            echo json_encode(['success' => true, 'message' => 'Color updated successfully!', 'data' => $row]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- DELETE COLOR ----
    if ($action === 'delete_color') {
        $color_id = intval($_POST['color_id'] ?? 0);
        if ($color_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid color ID.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM colors WHERE color_id = ?");
            $stmt->execute([$color_id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Color not found.']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Color deleted successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ==========================================================
    // SIZES CRUD
    // ==========================================================

    // ---- FETCH SIZES (with pagination + search) ----
    if ($action === 'fetch_sizes') {
        try {
            $search = trim($_GET['search'] ?? '');
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = max(1, min(500, intval($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $where = '';
            $params = [];
            if ($search !== '') {
                $where = "WHERE size_name LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM sizes $where");
            $stmt->execute($params);
            $total = (int)$stmt->fetch()['total'];

            $stmt = $pdo->prepare("SELECT size_id, size_name, created_at, updated_at FROM sizes $where ORDER BY size_name ASC LIMIT :limit OFFSET :offset");
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data' => $rows,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit)
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---- ADD SIZE ----
    if ($action === 'add_size') {
        $size_name = trim($_POST['size_name'] ?? '');
        if (empty($size_name)) {
            echo json_encode(['success' => false, 'message' => 'Size name is required.']);
            exit;
        }
        if (strlen($size_name) > 20) {
            echo json_encode(['success' => false, 'message' => 'Size name exceeds 20 characters.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT size_id FROM sizes WHERE size_name = ?");
            $stmt->execute([$size_name]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Size already exists.']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO sizes (size_name) VALUES (?)");
            $stmt->execute([$size_name]);
            $newId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("SELECT size_id, size_name, created_at, updated_at FROM sizes WHERE size_id = ?");
            $stmt->execute([$newId]);
            $row = $stmt->fetch();

            echo json_encode(['success' => true, 'message' => 'Size added successfully!', 'data' => $row]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- UPDATE SIZE ----
    if ($action === 'update_size') {
        $size_id = intval($_POST['size_id'] ?? 0);
        $size_name = trim($_POST['size_name'] ?? '');

        if ($size_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid size ID.']);
            exit;
        }
        if (empty($size_name)) {
            echo json_encode(['success' => false, 'message' => 'Size name is required.']);
            exit;
        }
        if (strlen($size_name) > 20) {
            echo json_encode(['success' => false, 'message' => 'Size name exceeds 20 characters.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT size_id FROM sizes WHERE size_name = ? AND size_id != ?");
            $stmt->execute([$size_name, $size_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Another size with this name already exists.']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT size_id FROM sizes WHERE size_id = ?");
            $stmt->execute([$size_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Size not found.']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE sizes SET size_name = ? WHERE size_id = ?");
            $stmt->execute([$size_name, $size_id]);

            $stmt = $pdo->prepare("SELECT size_id, size_name, created_at, updated_at FROM sizes WHERE size_id = ?");
            $stmt->execute([$size_id]);
            $row = $stmt->fetch();

            echo json_encode(['success' => true, 'message' => 'Size updated successfully!', 'data' => $row]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- DELETE SIZE ----
    if ($action === 'delete_size') {
        $size_id = intval($_POST['size_id'] ?? 0);
        if ($size_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid size ID.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM sizes WHERE size_id = ?");
            $stmt->execute([$size_id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'Size not found.']);
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Size deleted successfully!']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Include Layout Views if available
if (file_exists(ROOT_PATH . 'includes/header.php')) include ROOT_PATH . 'includes/header.php';
if (file_exists(ROOT_PATH . 'includes/sidebar.php')) include ROOT_PATH . 'includes/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background-color: #fcfcfc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; }
        .asb-header-title { color: #b71c1c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-left: 5px solid #d32f2f; padding-left: 15px; margin-bottom: 25px; }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        @media (max-width: 1000px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        .asb-card { background: #ffffff; border: 1px solid #eaeaea; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 30px; display: flex; flex-direction: column; }
        .asb-card-header { background: #fff; border-bottom: 2px solid #f5f5f5; padding: 18px 24px; color: #b71c1c; font-weight: bold; display: flex; align-items: center; justify-content: space-between; }
        .asb-card-header h2 { font-size: 1.1rem; font-weight: 700; color: #b71c1c; display: flex; align-items: center; gap: 8px; margin: 0; }
        .asb-card-header h2 i { color: #d32f2f; }

        .badge-index { background: #ffebee; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: bold; color: #c62828; text-transform: uppercase; }
        .card-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }

        /* Add form row */
        .add-form { display: flex; gap: 10px; margin-bottom: 15px; }
        .asb-input { border: 1px solid #dcdcdc; padding: 10px 14px; border-radius: 6px; width: 100%; transition: all 0.2s; font-size: 13px; color: #333; background: #fff; }
        .asb-input:focus { border-color: #d32f2f; box-shadow: 0 0 0 3px rgba(211,47,47,0.1); outline: none; }

        .btn-asb-action { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 10px 16px; border-radius: 6px; box-shadow: 0 2px 4px rgba(211,47,47,0.2); transition: all 0.2s ease; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; white-space: nowrap; }
        .btn-asb-action:hover { background: #b71c1c; box-shadow: 0 4px 8px rgba(211,47,47,0.3); transform: translateY(-1px); color: #fff; }
        
        .btn-outline-action { background: #fff; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 10px 16px; border-radius: 6px; transition: all 0.2s ease; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; }
        .btn-outline-action:hover { background: #f5f5f5; border-color: #999; color: #111; }
        .btn-sm { padding: 5px 10px; font-size: 11px; border-radius: 4px; }
        .btn-icon { padding: 10px 14px; border-radius: 6px; }

        /* Search bar */
        .search-bar { position: relative; margin-bottom: 15px; }
        .search-bar input { width: 100%; font-family: inherit; font-size: 13px; padding: 10px 14px 10px 38px; border-radius: 6px; border: 1px solid #dcdcdc; background: #fafafa; outline: none; color: #333; transition: 0.2s; }
        .search-bar input:focus { border-color: #d32f2f; box-shadow: 0 0 0 3px rgba(211,47,47,0.1); background: white; }
        .search-bar i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #888; font-size: 13px; pointer-events: none; }
        .search-bar .clear-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: #eee; border: none; color: #555; width: 20px; height: 20px; border-radius: 50%; cursor: pointer; font-size: 10px; display: none; align-items: center; justify-content: center; }
        .search-bar .clear-btn:hover { background: #ddd; }
        .search-bar.has-value .clear-btn { display: flex; }

        /* Table */
        .table-wrap { flex: 1; overflow: auto; border-radius: 8px; border: 1px solid #eaeaea; background: white; min-height: 350px; max-height: 450px; position: relative; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead { position: sticky; top: 0; background: #f8f9fa; z-index: 2; box-shadow: 0 1px 0 #eaeaea; }
        thead th { padding: 12px 14px; text-align: left; font-weight: 600; color: #444; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #eaeaea; white-space: nowrap; }
        tbody td { padding: 12px 14px; border-bottom: 1px solid #f5f5f5; color: #333; vertical-align: middle; }
        tbody tr:hover { background: #fafafa; }
        tbody tr:last-child td { border-bottom: none; }

        .id-cell { font-family: monospace; font-size: 12px; color: #777; width: 60px; }
        .name-cell { font-weight: 600; color: #222; }
        .color-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; border: 1px solid #ccc; vertical-align: middle; }
        .actions-cell { width: 90px; text-align: right; white-space: nowrap; }

        .action-icon { color: #777; cursor: pointer; padding: 6px; border-radius: 4px; background: transparent; border: none; font-size: 12px; transition: 0.1s; }
        .action-icon.edit:hover { background: #e3f2fd; color: #1976d2; }
        .action-icon.delete:hover { background: #ffebee; color: #d32f2f; }

        /* Edit inline */
        .edit-inline { display: flex; gap: 5px; align-items: center; }
        .edit-inline input { padding: 6px 10px; font-size: 13px; border-radius: 4px; border: 1px solid #d32f2f; outline: none; flex: 1; min-width: 0; }

        /* Pagination */
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 12px 4px 0; font-size: 12px; color: #666; flex-wrap: wrap; gap: 8px; }
        .pagination-controls { display: flex; align-items: center; gap: 4px; }
        .page-btn { background: white; border: 1px solid #ccc; color: #333; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; transition: 0.12s; }
        .page-btn:hover:not(:disabled) { background: #f5f5f5; border-color: #999; }
        .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .page-input { width: 45px; padding: 5px; font-size: 12px; border-radius: 4px; border: 1px solid #ccc; text-align: center; outline: none; color: #333; }
        .per-page-select { padding: 5px 8px; font-size: 12px; border-radius: 4px; border: 1px solid #ccc; background: white; color: #333; outline: none; cursor: pointer; }

        /* Loading overlay */
        .loading-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.7); display: none; align-items: center; justify-content: center; z-index: 3; backdrop-filter: blur(2px); }
        .loading-overlay.active { display: flex; }
        .spinner { width: 28px; height: 28px; border: 3px solid #eee; border-top-color: #d32f2f; border-radius: 50%; animation: spin 0.7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .empty-state { padding: 3rem 1rem; text-align: center; color: #999; font-size: 13px; font-style: italic; }
        .empty-state i { font-size: 2rem; color: #ddd; display: block; margin-bottom: 10px; }

        /* Toast */
        .toast { position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 8px; color: white; font-weight: 600; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; opacity: 0; transform: translateY(-20px); transition: 0.3s ease; pointer-events: none; max-width: 380px; }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.success { background: #2e7d32; }
        .toast.error { background: #c62828; }

        .asb-footer { text-align: center; margin-top: 50px; padding: 20px; color: #777; border-top: 1px solid #eee; font-size: 13px; }
        .asb-footer strong { color: #d32f2f; }
        .uppercase-input { text-transform: uppercase; }
    </style>
</head>
<body>

<div class="container-fluid" style="padding: 20px 30px;">
    
    <div class="page-header">
        <h2 class="asb-header-title">ASb Fashion <span style="font-weight:300; color:#555; font-size:18px;">| Colors & Sizes Matrix Manager</span></h2>
    </div>

    <div class="grid-2">
        <!-- COLUMN 1: COLORS -->
        <div class="asb-card">
            <div class="asb-card-header">
                <h2><i class="fas fa-palette"></i> Colors Inventory</h2>
                <span class="badge-index" id="colorTotalBadge">0 total</span>
            </div>
            <div class="card-body">
                <!-- Add form -->
                <form id="colorForm" class="add-form">
                    <input type="text" class="asb-input uppercase-input" id="colorNameInput" placeholder="Add new color (e.g. RED)" maxlength="50" autocomplete="off">
                    <button type="submit" class="btn-asb-action btn-icon" title="Add color">
                        <i class="fas fa-plus"></i>
                    </button>
                </form>

                <!-- Search -->
                <div class="search-bar" id="colorSearchBar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="colorSearchInput" placeholder="Search colors..." autocomplete="off">
                    <button type="button" class="clear-btn" id="colorClearBtn" title="Clear"><i class="fas fa-times"></i></button>
                </div>

                <!-- Table -->
                <div class="table-wrap" id="colorTableWrap">
                    <div class="loading-overlay" id="colorLoading"><div class="spinner"></div></div>
                    <table>
                        <thead>
                            <tr>
                                <th class="id-cell">ID</th>
                                <th>Color Name</th>
                                <th class="actions-cell">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="colorTbody">
                            <tr><td colspan="3"><div class="empty-state"><i class="fas fa-inbox"></i>Loading...</div></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info" id="colorPageInfo">—</div>
                    <div class="pagination-controls">
                        <select class="per-page-select" id="colorPerPage">
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                        </select>
                        <button class="page-btn" id="colorFirst" title="First"><i class="fas fa-angles-left"></i></button>
                        <button class="page-btn" id="colorPrev" title="Prev"><i class="fas fa-angle-left"></i></button>
                        <input type="number" class="page-input" id="colorPageInput" min="1" value="1">
                        <button class="page-btn" id="colorNext" title="Next"><i class="fas fa-angle-right"></i></button>
                        <button class="page-btn" id="colorLast" title="Last"><i class="fas fa-angles-right"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: SIZES -->
        <div class="asb-card">
            <div class="asb-card-header">
                <h2><i class="fas fa-ruler-combined"></i> Sizes Inventory</h2>
                <span class="badge-index" id="sizeTotalBadge">0 total</span>
            </div>
            <div class="card-body">
                <!-- Add form -->
                <form id="sizeForm" class="add-form">
                    <input type="text" class="asb-input uppercase-input" id="sizeNameInput" placeholder="Add new size (e.g. XL)" maxlength="20" autocomplete="off">
                    <button type="submit" class="btn-asb-action btn-icon" title="Add size">
                        <i class="fas fa-plus"></i>
                    </button>
                </form>

                <!-- Search -->
                <div class="search-bar" id="sizeSearchBar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="sizeSearchInput" placeholder="Search sizes..." autocomplete="off">
                    <button type="button" class="clear-btn" id="sizeClearBtn" title="Clear"><i class="fas fa-times"></i></button>
                </div>

                <!-- Table -->
                <div class="table-wrap" id="sizeTableWrap">
                    <div class="loading-overlay" id="sizeLoading"><div class="spinner"></div></div>
                    <table>
                        <thead>
                            <tr>
                                <th class="id-cell">ID</th>
                                <th>Size Name</th>
                                <th class="actions-cell">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="sizeTbody">
                            <tr><td colspan="3"><div class="empty-state"><i class="fas fa-inbox"></i>Loading...</div></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <div class="pagination-info" id="sizePageInfo">—</div>
                    <div class="pagination-controls">
                        <select class="per-page-select" id="sizePerPage">
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                        </select>
                        <button class="page-btn" id="sizeFirst" title="First"><i class="fas fa-angles-left"></i></button>
                        <button class="page-btn" id="sizePrev" title="Prev"><i class="fas fa-angle-left"></i></button>
                        <input type="number" class="page-input" id="sizePageInput" min="1" value="1">
                        <button class="page-btn" id="sizeNext" title="Next"><i class="fas fa-angle-right"></i></button>
                        <button class="page-btn" id="sizeLast" title="Last"><i class="fas fa-angles-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="asb-footer">
        © <?= date('Y'); ?> <strong>ASb Fashion</strong> Inventory Ledger Matrix System. All Rights Reserved.<br>
        <span style="font-size:11px; margin-top:5px; display:inline-block; color:#aaa;">System Designed & Developed by <strong>Vexel IT by Kavizz</strong></span>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
(function() {
    'use strict';

    const colorState = { page: 1, limit: 50, search: '', total: 0, totalPages: 1, items: [] };
    const sizeState  = { page: 1, limit: 50, search: '', total: 0, totalPages: 1, items: [] };

    const toastEl = document.getElementById('toast');

    // Colors DOM
    const colorForm = document.getElementById('colorForm');
    const colorNameInput = document.getElementById('colorNameInput');
    const colorSearchInput = document.getElementById('colorSearchInput');
    const colorSearchBar = document.getElementById('colorSearchBar');
    const colorClearBtn = document.getElementById('colorClearBtn');
    const colorTbody = document.getElementById('colorTbody');
    const colorLoading = document.getElementById('colorLoading');
    const colorPageInfo = document.getElementById('colorPageInfo');
    const colorTotalBadge = document.getElementById('colorTotalBadge');
    const colorPerPage = document.getElementById('colorPerPage');
    const colorFirst = document.getElementById('colorFirst');
    const colorPrev = document.getElementById('colorPrev');
    const colorNext = document.getElementById('colorNext');
    const colorLast = document.getElementById('colorLast');
    const colorPageInput = document.getElementById('colorPageInput');

    // Sizes DOM
    const sizeForm = document.getElementById('sizeForm');
    const sizeNameInput = document.getElementById('sizeNameInput');
    const sizeSearchInput = document.getElementById('sizeSearchInput');
    const sizeSearchBar = document.getElementById('sizeSearchBar');
    const sizeClearBtn = document.getElementById('sizeClearBtn');
    const sizeTbody = document.getElementById('sizeTbody');
    const sizeLoading = document.getElementById('sizeLoading');
    const sizePageInfo = document.getElementById('sizePageInfo');
    const sizeTotalBadge = document.getElementById('sizeTotalBadge');
    const sizePerPage = document.getElementById('sizePerPage');
    const sizeFirst = document.getElementById('sizeFirst');
    const sizePrev = document.getElementById('sizePrev');
    const sizeNext = document.getElementById('sizeNext');
    const sizeLast = document.getElementById('sizeLast');
    const sizePageInput = document.getElementById('sizePageInput');

    function showToast(message, type = 'success') {
        toastEl.textContent = message;
        toastEl.className = 'toast ' + type + ' show';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => toastEl.classList.remove('show'), 3000);
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    function getColorDot(colorName) {
        const key = String(colorName).toLowerCase().replace(/\s+/g, '');
        const map = {
            'red': '#e74c3c', 'blue': '#3498db', 'green': '#2ecc71', 'yellow': '#f1c40f',
            'black': '#2c3e50', 'white': '#ecf0f1', 'orange': '#e67e22', 'purple': '#9b59b6',
            'pink': '#fd79a8', 'brown': '#a0522d', 'gray': '#7f8c8d', 'grey': '#7f8c8d',
            'cyan': '#1abc9c', 'magenta': '#e84393', 'lime': '#a3e635', 'navy': '#1e3a8a',
            'teal': '#0d9488', 'maroon': '#7f1d1d', 'olive': '#657a00', 'silver': '#c0c0c0',
            'gold': '#fbbf24', 'beige': '#f5f5dc', 'ivory': '#fffff0', 'coral': '#ff7f50',
            'salmon': '#fa8072', 'violet': '#8b5cf6', 'indigo': '#4f46e5'
        };
        return map[key] || '#ccc';
    }

    function debounce(fn, delay) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function loadColors() {
        colorLoading.classList.add('active');
        const params = new URLSearchParams({ action: 'fetch_colors', search: colorState.search, page: colorState.page, limit: colorState.limit });

        fetch('?' + params.toString())
            .then(res => res.json())
            .then(data => {
                colorLoading.classList.remove('active');
                if (!data.success) { showToast('Failed to load colors: ' + data.message, 'error'); return; }
                colorState.items = data.data || [];
                colorState.total = data.total || 0;
                colorState.totalPages = data.total_pages || 1;
                renderColors();
            })
            .catch(err => { colorLoading.classList.remove('active'); showToast('Network error: ' + err.message, 'error'); });
    }

    function renderColors() {
        colorTotalBadge.textContent = colorState.total.toLocaleString() + ' total';
        if (colorState.items.length === 0) {
            colorTbody.innerHTML = `<tr><td colspan="3"><div class="empty-state"><i class="fas fa-inbox"></i>${colorState.search ? 'No colors match your search' : 'No colors yet'}</div></td></tr>`;
        } else {
            let html = '';
            colorState.items.forEach(c => {
                const dot = getColorDot(c.color_name);
                html += `<tr data-id="${c.color_id}">
                    <td class="id-cell">#${c.color_id}</td>
                    <td class="name-cell"><span class="color-dot" style="background:${dot};"></span>${escapeHtml(c.color_name)}</td>
                    <td class="actions-cell">
                        <button class="action-icon edit" title="Edit" onclick="editColor(${c.color_id})"><i class="fas fa-pen"></i></button>
                        <button class="action-icon delete" title="Delete" onclick="deleteColor(${c.color_id})"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`;
            });
            colorTbody.innerHTML = html;
        }
        updateColorPagination();
    }

    function updateColorPagination() {
        const start = colorState.total === 0 ? 0 : (colorState.page - 1) * colorState.limit + 1;
        const end = Math.min(colorState.page * colorState.limit, colorState.total);
        colorPageInfo.textContent = `${start.toLocaleString()}–${end.toLocaleString()} of ${colorState.total.toLocaleString()}`;
        colorPageInput.value = colorState.page;
        colorPageInput.max = colorState.totalPages;
        colorFirst.disabled = colorState.page <= 1;
        colorPrev.disabled = colorState.page <= 1;
        colorNext.disabled = colorState.page >= colorState.totalPages;
        colorLast.disabled = colorState.page >= colorState.totalPages;
    }

    function loadSizes() {
        sizeLoading.classList.add('active');
        const params = new URLSearchParams({ action: 'fetch_sizes', search: sizeState.search, page: sizeState.page, limit: sizeState.limit });

        fetch('?' + params.toString())
            .then(res => res.json())
            .then(data => {
                sizeLoading.classList.remove('active');
                if (!data.success) { showToast('Failed to load sizes: ' + data.message, 'error'); return; }
                sizeState.items = data.data || [];
                sizeState.total = data.total || 0;
                sizeState.totalPages = data.total_pages || 1;
                renderSizes();
            })
            .catch(err => { sizeLoading.classList.remove('active'); showToast('Network error: ' + err.message, 'error'); });
    }

    function renderSizes() {
        sizeTotalBadge.textContent = sizeState.total.toLocaleString() + ' total';
        if (sizeState.items.length === 0) {
            sizeTbody.innerHTML = `<tr><td colspan="3"><div class="empty-state"><i class="fas fa-inbox"></i>${sizeState.search ? 'No sizes match your search' : 'No sizes yet'}</div></td></tr>`;
        } else {
            let html = '';
            sizeState.items.forEach(s => {
                html += `<tr data-id="${s.size_id}">
                    <td class="id-cell">#${s.size_id}</td>
                    <td class="name-cell"><i class="fas fa-ruler" style="color:#888; margin-right:8px;"></i>${escapeHtml(s.size_name)}</td>
                    <td class="actions-cell">
                        <button class="action-icon edit" title="Edit" onclick="editSize(${s.size_id})"><i class="fas fa-pen"></i></button>
                        <button class="action-icon delete" title="Delete" onclick="deleteSize(${s.size_id})"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>`;
            });
            sizeTbody.innerHTML = html;
        }
        updateSizePagination();
    }

    function updateSizePagination() {
        const start = sizeState.total === 0 ? 0 : (sizeState.page - 1) * sizeState.limit + 1;
        const end = Math.min(sizeState.page * sizeState.limit, sizeState.total);
        sizePageInfo.textContent = `${start.toLocaleString()}–${end.toLocaleString()} of ${sizeState.total.toLocaleString()}`;
        sizePageInput.value = sizeState.page;
        sizePageInput.max = sizeState.totalPages;
        sizeFirst.disabled = sizeState.page <= 1;
        sizePrev.disabled = sizeState.page <= 1;
        sizeNext.disabled = sizeState.page >= sizeState.totalPages;
        sizeLast.disabled = sizeState.page >= sizeState.totalPages;
    }

    colorForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const name = colorNameInput.value.trim().toUpperCase();
        if (!name) { showToast('Color name is required.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'add_color');
        fd.append('color_name', name);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    colorNameInput.value = '';
                    colorState.page = 1;
                    loadColors();
                } else { showToast(data.message, 'error'); }
            })
            .catch(err => showToast('Request failed: ' + err.message, 'error'));
    });

    window.editColor = function(id) {
        const item = colorState.items.find(c => c.color_id == id);
        if (!item) return;
        const row = colorTbody.querySelector(`tr[data-id="${id}"]`);
        if (!row) return;

        row.innerHTML = `
            <td class="id-cell">#${item.color_id}</td>
            <td colspan="2">
                <div class="edit-inline">
                    <input type="text" class="uppercase-input" id="editColorInput_${id}" value="${escapeHtml(item.color_name)}" maxlength="50">
                    <button class="btn-asb-action btn-sm" onclick="saveColor(${id})"><i class="fas fa-check"></i></button>
                    <button class="btn-outline-action btn-sm" onclick="loadColors()"><i class="fas fa-times"></i></button>
                </div>
            </td>
        `;
        const input = document.getElementById('editColorInput_' + id);
        input.focus(); input.select();
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); saveColor(id); }
            if (e.key === 'Escape') loadColors();
        });
    };

    window.saveColor = function(id) {
        const input = document.getElementById('editColorInput_' + id);
        if (!input) return;
        const newName = input.value.trim().toUpperCase();
        if (!newName) { showToast('Color name is required.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'update_color');
        fd.append('color_id', id);
        fd.append('color_name', newName);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) { showToast(data.message, 'success'); loadColors(); }
                else { showToast(data.message, 'error'); }
            });
    };

    window.deleteColor = function(id) {
        const item = colorState.items.find(c => c.color_id == id);
        if (!item) return;
        if (!confirm(`Delete color "${item.color_name}"?`)) return;

        const fd = new FormData();
        fd.append('action', 'delete_color');
        fd.append('color_id', id);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    if (colorState.items.length === 1 && colorState.page > 1) colorState.page--;
                    loadColors();
                } else { showToast(data.message, 'error'); }
            });
    };

    sizeForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const name = sizeNameInput.value.trim().toUpperCase();
        if (!name) { showToast('Size name is required.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'add_size');
        fd.append('size_name', name);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    sizeNameInput.value = '';
                    sizeState.page = 1;
                    loadSizes();
                } else { showToast(data.message, 'error'); }
            });
    });

    window.editSize = function(id) {
        const item = sizeState.items.find(s => s.size_id == id);
        if (!item) return;
        const row = sizeTbody.querySelector(`tr[data-id="${id}"]`);
        if (!row) return;

        row.innerHTML = `
            <td class="id-cell">#${item.size_id}</td>
            <td colspan="2">
                <div class="edit-inline">
                    <input type="text" class="uppercase-input" id="editSizeInput_${id}" value="${escapeHtml(item.size_name)}" maxlength="20">
                    <button class="btn-asb-action btn-sm" onclick="saveSize(${id})"><i class="fas fa-check"></i></button>
                    <button class="btn-outline-action btn-sm" onclick="loadSizes()"><i class="fas fa-times"></i></button>
                </div>
            </td>
        `;
        const input = document.getElementById('editSizeInput_' + id);
        input.focus(); input.select();
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); saveSize(id); }
            if (e.key === 'Escape') loadSizes();
        });
    };

    window.saveSize = function(id) {
        const input = document.getElementById('editSizeInput_' + id);
        if (!input) return;
        const newName = input.value.trim().toUpperCase();
        if (!newName) { showToast('Size name is required.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'update_size');
        fd.append('size_id', id);
        fd.append('size_name', newName);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) { showToast(data.message, 'success'); loadSizes(); }
                else { showToast(data.message, 'error'); }
            });
    };

    window.deleteSize = function(id) {
        const item = sizeState.items.find(s => s.size_id == id);
        if (!item) return;
        if (!confirm(`Delete size "${item.size_name}"?`)) return;

        const fd = new FormData();
        fd.append('action', 'delete_size');
        fd.append('size_id', id);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    if (sizeState.items.length === 1 && sizeState.page > 1) sizeState.page--;
                    loadSizes();
                } else { showToast(data.message, 'error'); }
            });
    };

    colorSearchInput.addEventListener('input', debounce(function() {
        colorState.search = colorSearchInput.value.trim();
        colorState.page = 1;
        colorSearchBar.classList.toggle('has-value', colorState.search.length > 0);
        loadColors();
    }, 350));

    colorClearBtn.addEventListener('click', function() {
        colorSearchInput.value = '';
        colorSearchBar.classList.remove('has-value');
        colorState.search = '';
        colorState.page = 1;
        loadColors();
    });

    sizeSearchInput.addEventListener('input', debounce(function() {
        sizeState.search = sizeSearchInput.value.trim();
        sizeState.page = 1;
        sizeSearchBar.classList.toggle('has-value', sizeState.search.length > 0);
        loadSizes();
    }, 350));

    sizeClearBtn.addEventListener('click', function() {
        sizeSearchInput.value = '';
        sizeSearchBar.classList.remove('has-value');
        sizeState.search = '';
        sizeState.page = 1;
        loadSizes();
    });

    colorPerPage.addEventListener('change', function() { colorState.limit = parseInt(this.value); colorState.page = 1; loadColors(); });
    colorFirst.addEventListener('click', () => { colorState.page = 1; loadColors(); });
    colorPrev.addEventListener('click', () => { if (colorState.page > 1) { colorState.page--; loadColors(); } });
    colorNext.addEventListener('click', () => { if (colorState.page < colorState.totalPages) { colorState.page++; loadColors(); } });
    colorLast.addEventListener('click', () => { colorState.page = colorState.totalPages; loadColors(); });

    sizePerPage.addEventListener('change', function() { sizeState.limit = parseInt(this.value); sizeState.page = 1; loadSizes(); });
    sizeFirst.addEventListener('click', () => { sizeState.page = 1; loadSizes(); });
    sizePrev.addEventListener('click', () => { if (sizeState.page > 1) { sizeState.page--; loadSizes(); } });
    sizeNext.addEventListener('click', () => { if (sizeState.page < sizeState.totalPages) { sizeState.page++; loadSizes(); } });
    sizeLast.addEventListener('click', () => { sizeState.page = sizeState.totalPages; loadSizes(); });

    loadColors();
    loadSizes();
})();
</script>

<?php if (file_exists(ROOT_PATH . 'includes/footer.php')) include ROOT_PATH . 'includes/footer.php'; ?>
</body>
</html>