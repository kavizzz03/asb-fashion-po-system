<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

$page_title = 'ASB Fashion | Suggestion & Department Manager';
$page = 'suggestions';

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

    // ---- FETCH ALL DATA ----
    if ($action === 'fetch_all') {
        try {
            $departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
            $subDepartments = $pdo->query("SELECT sub_department_id, department_id, sub_department_name FROM sub_departments ORDER BY sub_department_name")->fetchAll();
            $suggestions = $pdo->query("
                SELECT s.suggestion_id, s.department_id, s.sub_department_id, s.suggested_name,
                       d.department_name, sd.sub_department_name
                FROM item_name_suggestions s
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN sub_departments sd ON s.sub_department_id = sd.sub_department_id
                ORDER BY s.suggestion_id DESC
                LIMIT 100
            ")->fetchAll();
            echo json_encode([
                'success' => true,
                'departments' => $departments,
                'sub_departments' => $subDepartments,
                'suggestions' => $suggestions
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---- ADD SUGGESTION ----
    if ($action === 'add_suggestion') {
        $department_id = intval($_POST['department_id'] ?? 0);
        $sub_department_id = !empty($_POST['sub_department_id']) ? intval($_POST['sub_department_id']) : null;
        $suggested_name = strtoupper(trim($_POST['suggested_name'] ?? ''));

        if ($department_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a department.']);
            exit;
        }
        if (empty($suggested_name)) {
            echo json_encode(['success' => false, 'message' => 'Suggested name is required.']);
            exit;
        }
        if (!preg_match('/^[A-Z\s]+$/', $suggested_name)) {
            echo json_encode(['success' => false, 'message' => 'Only uppercase letters (A-Z) and spaces are allowed.']);
            exit;
        }
        if (strlen($suggested_name) > 255) {
            echo json_encode(['success' => false, 'message' => 'Suggested name exceeds 255 characters.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = ?");
            $stmt->execute([$department_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Selected department does not exist.']);
                exit;
            }

            if ($sub_department_id !== null) {
                $stmt = $pdo->prepare("SELECT sub_department_id FROM sub_departments WHERE sub_department_id = ?");
                $stmt->execute([$sub_department_id]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Selected sub-department does not exist.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO item_name_suggestions (department_id, sub_department_id, suggested_name) VALUES (?, ?, ?)");
            $stmt->execute([$department_id, $sub_department_id, $suggested_name]);
            $newId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                SELECT s.suggestion_id, s.department_id, s.sub_department_id, s.suggested_name,
                       d.department_name, sd.sub_department_name
                FROM item_name_suggestions s
                LEFT JOIN departments d ON s.department_id = d.department_id
                LEFT JOIN sub_departments sd ON s.sub_department_id = sd.sub_department_id
                WHERE s.suggestion_id = ?
            ");
            $stmt->execute([$newId]);
            $row = $stmt->fetch();

            echo json_encode(['success' => true, 'message' => 'Suggestion added successfully!', 'data' => $row]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- ADD DEPARTMENT ----
    if ($action === 'add_department') {
        $department_name = strtoupper(trim($_POST['department_name'] ?? ''));
        if (empty($department_name)) {
            echo json_encode(['success' => false, 'message' => 'Department name is required.']);
            exit;
        }
        if (strlen($department_name) > 100) {
            echo json_encode(['success' => false, 'message' => 'Department name exceeds 100 characters.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT department_id FROM departments WHERE department_name = ?");
            $stmt->execute([$department_name]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Department already exists.']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO departments (department_name) VALUES (?)");
            $stmt->execute([$department_name]);
            $newId = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Department added successfully!',
                'data' => ['department_id' => $newId, 'department_name' => $department_name]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- ADD SUB-DEPARTMENT ----
    if ($action === 'add_sub_department') {
        $sub_department_name = strtoupper(trim($_POST['sub_department_name'] ?? ''));

        if (empty($sub_department_name)) {
            echo json_encode(['success' => false, 'message' => 'Sub-department name is required.']);
            exit;
        }
        if (strlen($sub_department_name) > 100) {
            echo json_encode(['success' => false, 'message' => 'Sub-department name exceeds 100 characters.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT sub_department_id FROM sub_departments WHERE sub_department_name = ?");
            $stmt->execute([$sub_department_name]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Sub-department already exists.']);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO sub_departments (department_id, sub_department_name) VALUES (0, ?)");
            $stmt->execute([$sub_department_name]);
            $newId = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Sub-department added successfully!',
                'data' => [
                    'sub_department_id' => $newId,
                    'department_id' => 0,
                    'sub_department_name' => $sub_department_name
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---- DELETE SUGGESTION ----
    if ($action === 'delete_suggestion') {
        $id = intval($_POST['suggestion_id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM item_name_suggestions WHERE suggestion_id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Suggestion deleted successfully!']);
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
        
        .grid-3 {
            display: grid;
            grid-template-columns: 1.1fr 1fr 1fr;
            gap: 24px;
        }
        @media (max-width: 1100px) {
            .grid-3 { grid-template-columns: 1fr; }
        }

        .asb-card { background: #ffffff; border: 1px solid #eaeaea; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); overflow: hidden; margin-bottom: 30px; display: flex; flex-direction: column; }
        .asb-card-header { background: #fff; border-bottom: 2px solid #f5f5f5; padding: 18px 24px; color: #b71c1c; font-weight: bold; display: flex; align-items: center; justify-content: space-between; }
        .asb-card-header h2 { font-size: 1.1rem; font-weight: 700; color: #b71c1c; display: flex; align-items: center; gap: 8px; margin: 0; }
        .asb-card-header h2 i { color: #d32f2f; }

        .card-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }

        .table-schema {
            font-size: 0.75rem;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #eaeaea;
            color: #555;
        }
        .schema-row { display: flex; flex-wrap: wrap; padding: 3px 0; border-bottom: 1px dashed #eee; align-items: baseline; }
        .schema-row:last-child { border-bottom: none; }
        .schema-col { width: 130px; min-width: 130px; font-weight: 600; color: #333; }
        .schema-type { color: #666; font-family: monospace; font-size: 0.7rem; flex: 1; }

        .badge-index { background: #ffebee; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: bold; color: #c62828; text-transform: uppercase; }

        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #555; margin-bottom: 6px; }
        .required:after { content: " *"; color: #d32f2f; }

        .asb-input, select { border: 1px solid #dcdcdc; padding: 10px 14px; border-radius: 6px; width: 100%; transition: all 0.2s; font-size: 13px; background: #fff; color: #333; }
        .asb-input:focus, select:focus { border-color: #d32f2f; box-shadow: 0 0 0 3px rgba(211,47,47,0.1); outline: none; }
        .asb-input.error, select.error { border-color: #d32f2f; background: #fff8f8; }

        .error-msg { color: #d32f2f; font-size: 11px; margin-top: 5px; display: flex; align-items: center; gap: 4px; font-weight: 600; }

        .btn-asb-action { background: #d32f2f; color: #fff; border: none; font-weight: bold; padding: 10px 16px; border-radius: 6px; box-shadow: 0 2px 4px rgba(211,47,47,0.2); transition: all 0.2s ease; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; font-size: 13px; }
        .btn-asb-action:hover { background: #b71c1c; box-shadow: 0 4px 8px rgba(211,47,47,0.3); transform: translateY(-1px); color: #fff; }

        .btn-outline-action { background: #fff; color: #333; border: 1px solid #ccc; font-weight: bold; padding: 10px 16px; border-radius: 6px; transition: all 0.2s ease; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; font-size: 13px; }
        .btn-outline-action:hover { background: #f5f5f5; border-color: #999; color: #111; }

        .divider { display: flex; align-items: center; color: #888; font-size: 11px; font-weight: 600; text-transform: uppercase; margin: 15px 0; }
        .divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: #eee; }
        .divider span { padding: 0 10px; }

        .list-scroll { max-height: 250px; overflow-y: auto; border-radius: 8px; border: 1px solid #eaeaea; background: #fcfcfc; }
        .list-item { padding: 10px 14px; border-bottom: 1px solid #f5f5f5; display: flex; align-items: center; justify-content: space-between; font-size: 13px; background: white; transition: 0.1s; }
        .list-item:last-child { border-bottom: none; }
        .list-item:hover { background: #fafafa; }
        .list-item .name { font-weight: 600; color: #222; }

        .id-badge { background: #f1f1f1; border-radius: 20px; padding: 2px 8px; font-size: 10px; color: #555; margin-left: 6px; font-weight: bold; }
        .suggestion-badge { background: #e8f5e9; color: #1b5e20; font-size: 10px; border-radius: 20px; padding: 2px 8px; margin-left: 6px; font-weight: bold; }

        .action-icon { color: #888; cursor: pointer; padding: 6px; border-radius: 4px; background: transparent; border: none; font-size: 12px; transition: 0.1s; }
        .action-icon:hover { background: #ffebee; color: #d32f2f; }

        .empty-state { padding: 20px; text-align: center; color: #999; font-size: 13px; font-style: italic; }

        .schema-warning { background: #fff9c4; border-left: 4px solid #fbc02d; padding: 8px 12px; border-radius: 6px; font-size: 11px; color: #795548; margin-bottom: 12px; display: flex; gap: 6px; align-items: center; font-weight: 500; }
        .schema-warning i { color: #f57f17; }

        .toast { position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 8px; color: white; font-weight: 600; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; opacity: 0; transform: translateY(-20px); transition: 0.3s ease; pointer-events: none; }
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
        <h2 class="asb-header-title">ASb Fashion <span style="font-weight:300; color:#555; font-size:18px;">| Suggestion & Department Manager Matrix</span></h2>
    </div>

    <div class="grid-3">
        <!-- COLUMN 1: Suggestions -->
        <div class="asb-card">
            <div class="asb-card-header">
                <h2><i class="fas fa-lightbulb"></i> Item Suggestions</h2>
                <span class="badge-index">Auto Increment</span>
            </div>
            <div class="card-body">
                <div class="table-schema">
                    <div class="schema-row"><span class="schema-col">suggestion_id</span><span class="schema-type">int(10) AI · PRIMARY</span></div>
                    <div class="schema-row"><span class="schema-col">department_id</span><span class="schema-type">bigint(20) · INDEX</span></div>
                    <div class="schema-row"><span class="schema-col">sub_department_id</span><span class="schema-type">bigint(20) NULL</span></div>
                    <div class="schema-row"><span class="schema-col">suggested_name</span><span class="schema-type">varchar(255) · UPPERCASE</span></div>
                </div>

                <div class="schema-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Verify schema mappings before finalizing batches.</span>
                </div>

                <form id="suggestionForm">
                    <div class="form-group">
                        <label class="required">Department</label>
                        <select id="suggestionDeptSelect" name="department_id" required>
                            <option value="">— select department —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sub-department (Independent)</label>
                        <select id="suggestionSubDeptSelect" name="sub_department_id">
                            <option value="">— none —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Suggested item name</label>
                        <input type="text" class="asb-input uppercase-input" id="suggestedNameInput" name="suggested_name" placeholder="e.g. OVERSIZED TEE" maxlength="255" autocomplete="off">
                        <div class="error-msg" id="nameError" style="display: none;"><i class="fas fa-circle-exclamation"></i> Only uppercase letters (A-Z) and spaces allowed</div>
                    </div>
                    <button type="submit" class="btn-asb-action"><i class="fas fa-plus-circle"></i> Add Suggestion</button>
                </form>

                <div class="divider"><span>Recent Suggestions</span></div>
                <div class="list-scroll" id="suggestionList">
                    <div class="empty-state">Loading...</div>
                </div>
            </div>
        </div>

        <!-- COLUMN 2: Departments -->
        <div class="asb-card">
            <div class="asb-card-header">
                <h2><i class="fas fa-building"></i> Departments</h2>
                <span class="badge-index" id="deptCount">0 rows</span>
            </div>
            <div class="card-body">
                <div class="table-schema">
                    <div class="schema-row"><span class="schema-col">department_id</span><span class="schema-type">bigint(20) AI · PRIMARY</span></div>
                    <div class="schema-row"><span class="schema-col">department_name</span><span class="schema-type">varchar(100) · INDEX</span></div>
                </div>

                <form id="departmentForm">
                    <div class="form-group">
                        <label class="required">New department name</label>
                        <input type="text" class="asb-input uppercase-input" id="deptNameInput" name="department_name" placeholder="e.g. PRODUCTION" maxlength="100">
                    </div>
                    <button type="submit" class="btn-outline-action"><i class="fas fa-plus"></i> Add Department</button>
                </form>

                <div class="divider"><span>Existing Departments</span></div>
                <div class="list-scroll" id="departmentList">
                    <div class="empty-state">Loading...</div>
                </div>
            </div>
        </div>

        <!-- COLUMN 3: Sub-departments (INDEPENDENT) -->
        <div class="asb-card">
            <div class="asb-card-header">
                <h2><i class="fas fa-sitemap"></i> Sub-Departments</h2>
                <span class="badge-index" id="subDeptCount">0 rows</span>
            </div>
            <div class="card-body">
                <div class="table-schema">
                    <div class="schema-row"><span class="schema-col">sub_department_id</span><span class="schema-type">bigint(20) AI · PRIMARY</span></div>
                    <div class="schema-row"><span class="schema-col">department_id</span><span class="schema-type">bigint(20) · (independent)</span></div>
                    <div class="schema-row"><span class="schema-col">sub_department_name</span><span class="schema-type">varchar(100) · INDEX</span></div>
                </div>

                <div class="schema-warning" style="background:#e3f2fd; border-left-color:#1976d2;">
                    <i class="fas fa-info-circle" style="color:#1976d2;"></i>
                    <span>This sub-department pool operates independently.</span>
                </div>

                <form id="subDepartmentForm">
                    <div class="form-group">
                        <label class="required">Sub-department name</label>
                        <input type="text" class="asb-input uppercase-input" id="subDeptNameInput" name="sub_department_name" placeholder="e.g. COTTON WEAR" maxlength="100">
                    </div>
                    <button type="submit" class="btn-outline-action"><i class="fas fa-plus"></i> Add Sub-Department</button>
                </form>

                <div class="divider"><span>Sub-Departments List</span></div>
                <div class="list-scroll" id="subDepartmentList">
                    <div class="empty-state">Loading...</div>
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

    const suggestionForm = document.getElementById('suggestionForm');
    const suggestionDeptSelect = document.getElementById('suggestionDeptSelect');
    const suggestionSubDeptSelect = document.getElementById('suggestionSubDeptSelect');
    const suggestedNameInput = document.getElementById('suggestedNameInput');
    const nameError = document.getElementById('nameError');
    const suggestionListEl = document.getElementById('suggestionList');

    const departmentForm = document.getElementById('departmentForm');
    const deptNameInput = document.getElementById('deptNameInput');
    const departmentListEl = document.getElementById('departmentList');
    const deptCountEl = document.getElementById('deptCount');

    const subDepartmentForm = document.getElementById('subDepartmentForm');
    const subDeptNameInput = document.getElementById('subDeptNameInput');
    const subDepartmentListEl = document.getElementById('subDepartmentList');
    const subDeptCountEl = document.getElementById('subDeptCount');

    const toastEl = document.getElementById('toast');

    let departments = [];
    let subDepartments = [];
    let suggestions = [];

    function showToast(message, type = 'success') {
        toastEl.textContent = message;
        toastEl.className = 'toast ' + type + ' show';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => toastEl.classList.remove('show'), 3200);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function isValidUppercaseName(name) {
        return /^[A-Z\s]+$/.test(name);
    }

    function renderDepartments() {
        let options = '<option value="">— select department —</option>';
        departments.forEach(d => {
            options += `<option value="${d.department_id}">${escapeHtml(d.department_name)} (ID: ${d.department_id})</option>`;
        });
        suggestionDeptSelect.innerHTML = options;

        if (departments.length === 0) {
            departmentListEl.innerHTML = '<div class="empty-state">No departments yet</div>';
        } else {
            let html = '';
            departments.forEach(d => {
                html += `<div class="list-item">
                    <div><span class="name">${escapeHtml(d.department_name)}</span><span class="id-badge">#${d.department_id}</span></div>
                </div>`;
            });
            departmentListEl.innerHTML = html;
        }
        deptCountEl.textContent = departments.length + ' rows';
    }

    function renderSubDepartments() {
        let options = '<option value="">— none —</option>';
        subDepartments.forEach(sd => {
            options += `<option value="${sd.sub_department_id}">${escapeHtml(sd.sub_department_name)} (ID: ${sd.sub_department_id})</option>`;
        });
        suggestionSubDeptSelect.innerHTML = options;

        if (subDepartments.length === 0) {
            subDepartmentListEl.innerHTML = '<div class="empty-state">No sub-departments yet</div>';
        } else {
            let html = '';
            subDepartments.forEach(sd => {
                html += `<div class="list-item">
                    <div>
                        <span class="name">${escapeHtml(sd.sub_department_name)}</span>
                        <span class="id-badge">#${sd.sub_department_id}</span>
                    </div>
                </div>`;
            });
            subDepartmentListEl.innerHTML = html;
        }
        subDeptCountEl.textContent = subDepartments.length + ' rows';
    }

    function renderSuggestions() {
        if (suggestions.length === 0) {
            suggestionListEl.innerHTML = '<div class="empty-state">No suggestions yet</div>';
            return;
        }
        let html = '';
        suggestions.forEach(s => {
            const deptName = s.department_name || '?';
            const subName = s.sub_department_name || '';
            const subDisplay = subName ? ` · ${escapeHtml(subName)}` : '';
            html += `<div class="list-item">
                <div>
                    <span class="name">${escapeHtml(s.suggested_name)}</span>
                    <span class="id-badge">#${s.suggestion_id}</span>
                    <span class="suggestion-badge"><i class="fas fa-tag"></i> ${escapeHtml(deptName)}${subDisplay}</span>
                </div>
                <button class="action-icon" title="Delete" onclick="deleteSuggestion(${s.suggestion_id})">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>`;
        });
        suggestionListEl.innerHTML = html;
    }

    function loadAllData() {
        fetch('?action=fetch_all')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    departments = data.departments || [];
                    subDepartments = data.sub_departments || [];
                    suggestions = data.suggestions || [];
                    renderDepartments();
                    renderSubDepartments();
                    renderSuggestions();
                } else {
                    showToast('Failed to load data: ' + (data.message || 'unknown'), 'error');
                }
            })
            .catch(err => showToast('Network error: ' + err.message, 'error'));
    }

    suggestedNameInput.addEventListener('input', function() {
        let val = this.value;
        const upper = val.toUpperCase();
        if (val !== upper) this.value = upper;
        if (upper.length > 0 && !isValidUppercaseName(upper)) {
            nameError.style.display = 'flex';
            this.classList.add('error');
        } else {
            nameError.style.display = 'none';
            this.classList.remove('error');
        }
    });

    suggestionForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const deptId = suggestionDeptSelect.value;
        const subDeptId = suggestionSubDeptSelect.value || '';
        let name = suggestedNameInput.value.trim().toUpperCase();

        nameError.style.display = 'none';
        suggestedNameInput.classList.remove('error');

        if (!deptId) { showToast('Please select a department.', 'error'); return; }
        if (!name) { showToast('Please enter a suggested name.', 'error'); return; }
        if (!isValidUppercaseName(name)) {
            nameError.style.display = 'flex';
            suggestedNameInput.classList.add('error');
            showToast('Only uppercase letters (A-Z) and spaces allowed.', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('action', 'add_suggestion');
        fd.append('department_id', deptId);
        fd.append('sub_department_id', subDeptId);
        fd.append('suggested_name', name);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    suggestions.unshift(data.data);
                    renderSuggestions();
                    suggestionForm.reset();
                    suggestionDeptSelect.value = '';
                    suggestionSubDeptSelect.value = '';
                    suggestedNameInput.value = '';
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(err => showToast('Request failed: ' + err.message, 'error'));
    });

    departmentForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const name = deptNameInput.value.trim().toUpperCase();
        if (!name) { showToast('Department name is required.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'add_department');
        fd.append('department_name', name);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    departments.push(data.data);
                    departments.sort((a,b) => a.department_name.localeCompare(b.department_name));
                    renderDepartments();
                    deptNameInput.value = '';
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(err => showToast('Request failed: ' + err.message, 'error'));
    });

    subDepartmentForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const name = subDeptNameInput.value.trim().toUpperCase();
        if (!name) { showToast('Sub-department name is required.', 'error'); return; }

        const fd = new FormData();
        fd.append('action', 'add_sub_department');
        fd.append('sub_department_name', name);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    subDepartments.push(data.data);
                    subDepartments.sort((a,b) => a.sub_department_name.localeCompare(b.sub_department_name));
                    renderSubDepartments();
                    subDeptNameInput.value = '';
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(err => showToast('Request failed: ' + err.message, 'error'));
    });

    window.deleteSuggestion = function(id) {
        if (!confirm('Delete this suggestion?')) return;
        const fd = new FormData();
        fd.append('action', 'delete_suggestion');
        fd.append('suggestion_id', id);

        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    suggestions = suggestions.filter(s => s.suggestion_id != id);
                    renderSuggestions();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(err => showToast('Request failed: ' + err.message, 'error'));
    };

    loadAllData();
})();
</script>

<?php if (file_exists(ROOT_PATH . 'includes/footer.php')) include ROOT_PATH . 'includes/footer.php'; ?>
</body>
</html>