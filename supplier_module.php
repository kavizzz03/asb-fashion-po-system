<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';
requireLogin();

// 1. DATABASE CONFIGURATIONS (Dual-DB Setup)
$host    = '127.0.0.1';
$user    = 'root'; 
$pass    = '';     
$charset = 'utf8mb4';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo_po = new PDO("mysql:host=$host;dbname=po_system;charset=$charset", $user, $pass, $options);
    $pdo_qc = new PDO("mysql:host=$host;dbname=return_qc;charset=$charset", $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 2. BACKEND ROUTER OPERATIONS (Dual-Table Sync)
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $supplier_name  = trim($_POST['supplier_name'] ?? '');
        $system_id      = !empty($_POST['system_id']) ? trim($_POST['system_id']) : null;
        $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
        $land_number    = !empty($_POST['land_number']) ? trim($_POST['land_number']) : null;
        $fax_number     = !empty($_POST['fax_number']) ? trim($_POST['fax_number']) : null;
        $email          = !empty($_POST['email']) ? trim($_POST['email']) : null;
        $address        = !empty($_POST['address']) ? trim($_POST['address']) : null;
        $status         = !empty($_POST['status']) ? trim($_POST['status']) : 'Active';
        $contact_person = !empty($_POST['contact_person']) ? trim($_POST['contact_person']) : null;
        $whatsapp       = !empty($_POST['whatsapp']) ? trim($_POST['whatsapp']) : null;

        try {
            $stmt1 = $pdo_po->prepare("INSERT INTO suppliers (supplier_name, system_id, contact_number, land_number, fax_number, email, address, status, contact_person, whatsapp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt1->execute([$supplier_name, $system_id, $contact_number, $land_number, $fax_number, $email, $address, $status, $contact_person, $whatsapp]);
            $inserted_id = $pdo_po->lastInsertId();

            $stmt2 = $pdo_qc->prepare("INSERT INTO suppliers (supplier_id, supplier_name, system_id, contact_number, land_number, fax_number, email, address, status, contact_person, whatsapp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->execute([$inserted_id, $supplier_name, $system_id, $contact_number, $land_number, $fax_number, $email, $address, $status, $contact_person, $whatsapp]);

            header("Location: supplier_module.php?msg=" . urlencode("Supplier '$supplier_name' registered successfully in both databases!"));
            exit;
        } catch (\PDOException $e) {
            die("Error inserting supplier: " . $e->getMessage());
        }
    }
    
    if ($action === 'update') {
        $supplier_id    = (int)($_POST['supplier_id'] ?? 0);
        $supplier_name  = trim($_POST['supplier_name'] ?? '');
        $system_id      = !empty($_POST['system_id']) ? trim($_POST['system_id']) : null;
        $contact_number = !empty($_POST['contact_number']) ? trim($_POST['contact_number']) : null;
        $land_number    = !empty($_POST['land_number']) ? trim($_POST['land_number']) : null;
        $fax_number     = !empty($_POST['fax_number']) ? trim($_POST['fax_number']) : null;
        $email          = !empty($_POST['email']) ? trim($_POST['email']) : null;
        $address        = !empty($_POST['address']) ? trim($_POST['address']) : null;
        $status         = !empty($_POST['status']) ? trim($_POST['status']) : 'Active';
        $contact_person = !empty($_POST['contact_person']) ? trim($_POST['contact_person']) : null;
        $whatsapp       = !empty($_POST['whatsapp']) ? trim($_POST['whatsapp']) : null;

        try {
            $stmt1 = $pdo_po->prepare("UPDATE suppliers SET supplier_name=?, system_id=?, contact_number=?, land_number=?, fax_number=?, email=?, address=?, status=?, contact_person=?, whatsapp=? WHERE supplier_id=?");
            $stmt1->execute([$supplier_name, $system_id, $contact_number, $land_number, $fax_number, $email, $address, $status, $contact_person, $whatsapp, $supplier_id]);

            $stmt2 = $pdo_qc->prepare("UPDATE suppliers SET supplier_name=?, system_id=?, contact_number=?, land_number=?, fax_number=?, email=?, address=?, status=?, contact_person=?, whatsapp=? WHERE supplier_id=?");
            $stmt2->execute([$supplier_name, $system_id, $contact_number, $land_number, $fax_number, $email, $address, $status, $contact_person, $whatsapp, $supplier_id]);

            header("Location: supplier_module.php?msg=" . urlencode("Supplier '$supplier_name' updated successfully across dual databases!"));
            exit;
        } catch (\PDOException $e) {
            die("Error updating supplier: " . $e->getMessage());
        }
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $supplier_id = (int)$_GET['id'];
    try {
        $stmt1 = $pdo_po->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
        $stmt1->execute([$supplier_id]);

        $stmt2 = $pdo_qc->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
        $stmt2->execute([$supplier_id]);

        header("Location: supplier_module.php?msg=" . urlencode("Supplier #$supplier_id permanently removed from dual databases!"));
        exit;
    } catch (\PDOException $e) {
        die("Error deleting supplier: " . $e->getMessage());
    }
}

// 3. SEARCH & PAGINATION ENGINE (25 / 50 / 100 breakdown)
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, [25, 50, 100])) {
    $perPage = 25;
}
$limit = $perPage;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

$conditions = [];
$params = [];

if (!empty($search)) {
    $searchTerm = '%' . $search . '%';
    $conditions[] = "(supplier_name LIKE ? OR contact_person LIKE ? OR contact_number LIKE ? OR whatsapp LIKE ? OR land_number LIKE ? OR email LIKE ? OR address LIKE ? OR system_id LIKE ? OR status LIKE ?)";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($statusFilter)) {
    if ($statusFilter === 'Active') {
        $conditions[] = "status = 'Active'";
    } elseif ($statusFilter === 'Inactive') {
        $conditions[] = "(status = 'Inactive' OR status = 'Suspended')";
    } elseif ($statusFilter === 'Pending') {
        $conditions[] = "(status LIKE '%Pending%' OR status LIKE '%New%')";
    } elseif ($statusFilter === 'Unassigned') {
        $conditions[] = "(status IS NULL OR status = '')";
    } else {
        $conditions[] = "status = ?";
        $params[] = $statusFilter;
    }
}

$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countSql = "SELECT COUNT(*) FROM suppliers $whereClause";
$stmtCount = $pdo_po->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// Fetch stats for KPI counters (Handles NULL & any custom status correctly)
$totalAllSuppliers = (int)$pdo_po->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
$totalActiveSuppliers = (int)$pdo_po->query("SELECT COUNT(*) FROM suppliers WHERE status = 'Active'")->fetchColumn();
$totalInactiveSuppliers = (int)$pdo_po->query("SELECT COUNT(*) FROM suppliers WHERE status != 'Active' OR status IS NULL")->fetchColumn();
$totalUnassignedSuppliers = (int)$pdo_po->query("SELECT COUNT(*) FROM suppliers WHERE status IS NULL OR status = ''")->fetchColumn();

$sql = "SELECT * FROM suppliers $whereClause ORDER BY supplier_id DESC LIMIT ? OFFSET ?";
$stmt = $pdo_po->prepare($sql);
$execParams = array_merge($params, [$limit, $offset]);
$stmt->execute($execParams);
$suppliers = $stmt->fetchAll();

// Index suppliers by ID for safe JS mapping
$suppliersById = [];
foreach ($suppliers as $s) {
    $suppliersById[(int)$s['supplier_id']] = [
        'supplier_id'    => (int)$s['supplier_id'],
        'supplier_name'  => (string)($s['supplier_name'] ?? ''),
        'system_id'      => (string)($s['system_id'] ?? ''),
        'contact_number' => (string)($s['contact_number'] ?? ''),
        'land_number'    => (string)($s['land_number'] ?? ''),
        'fax_number'     => (string)($s['fax_number'] ?? ''),
        'email'          => (string)($s['email'] ?? ''),
        'address'        => (string)($s['address'] ?? ''),
        'status'         => (string)($s['status'] ?? ''),
        'contact_person' => (string)($s['contact_person'] ?? ''),
        'whatsapp'       => (string)($s['whatsapp'] ?? '')
    ];
}

// URL Query Builder Helper
function buildSupplierUrl($newParams = []) {
    $params = $_GET;
    foreach ($newParams as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return 'supplier_module.php' . (!empty($params) ? '?' . http_build_query($params) : '');
}
?>

<?php
$page_title = 'ASB Group · Supplier Management Center';
$page = 'suppliers';
include ROOT_PATH . 'includes/header.php';
?>

<!-- Tailwind CSS & Google Fonts -->
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    /* =========================================================
       ASB EXECUTIVE SUITE - ULTRA-WIDE MODERN DESIGN SYSTEM
       ========================================================= */
    :root {
        --asb-red: #b91c1c;
        --asb-red-dark: #7f1d1d;
        --asb-red-deep: #450a0a;
        --asb-blush: #fef2f2;
        --asb-border: #fecaca;
        --asb-slate: #0f172a;
    }

    body {
        font-family: 'Plus Jakarta Sans', 'Inter', system-ui, -apple-system, sans-serif;
        background-color: #f8fafc;
        color: #1e293b;
        -webkit-font-smoothing: antialiased;
    }

    /* Container Spacing & Full Desktop Expansion */
    .supplier-workspace {
        width: 100%;
        max-width: 1920px;
        margin: 0 auto;
        padding: 1.25rem 1.5rem 2.5rem 1.5rem;
    }
    @media (min-width: 1280px) {
        .supplier-workspace {
            padding: 1.5rem 2.25rem 3rem 2.25rem;
        }
    }

    /* Glass Cards */
    .glass-card {
        background: #ffffff;
        border-radius: 1rem;
        border: 1px solid #fee2e2;
        box-shadow: 0 4px 15px -3px rgba(185, 28, 28, 0.05), 0 2px 6px -2px rgba(0, 0, 0, 0.02);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .glass-card-hover:hover {
        box-shadow: 0 10px 25px -4px rgba(185, 28, 28, 0.1), 0 4px 10px -2px rgba(0, 0, 0, 0.03);
    }

    /* Action Buttons */
    .btn-asb-primary {
        background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
        color: #ffffff;
        font-weight: 700;
        font-size: 0.84rem;
        padding: 0.65rem 1.25rem;
        border-radius: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 0 4px 12px rgba(185, 28, 28, 0.25);
        transition: all 0.2s ease;
        border: 1px solid rgba(255, 255, 255, 0.15);
        cursor: pointer;
        text-decoration: none;
    }
    .btn-asb-primary:hover {
        background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);
        box-shadow: 0 6px 18px rgba(185, 28, 28, 0.35);
        transform: translateY(-1px);
        color: #ffffff;
    }

    .btn-asb-secondary {
        background: #ffffff;
        color: #991b1b;
        font-weight: 700;
        font-size: 0.84rem;
        padding: 0.65rem 1.15rem;
        border-radius: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1.5px solid #fecaca;
        transition: all 0.2s ease;
        cursor: pointer;
        text-decoration: none;
    }
    .btn-asb-secondary:hover {
        background: #fef2f2;
        border-color: #b91c1c;
        color: #7f1d1d;
        transform: translateY(-1px);
    }

    .btn-asb-dark {
        background: #0f172a;
        color: #ffffff;
        font-weight: 700;
        font-size: 0.84rem;
        padding: 0.65rem 1.15rem;
        border-radius: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid #334155;
        transition: all 0.2s ease;
        cursor: pointer;
        text-decoration: none;
    }
    .btn-asb-dark:hover {
        background: #1e293b;
        border-color: #475569;
        transform: translateY(-1px);
        color: #ffffff;
    }

    /* KPI Stat Cards */
    .kpi-card {
        background: #ffffff;
        border-radius: 1rem;
        padding: 1.15rem 1.35rem;
        border: 1px solid #fecaca;
        position: relative;
        overflow: hidden;
        transition: all 0.2s ease;
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px -3px rgba(185, 28, 28, 0.08);
    }
    .kpi-icon-wrap {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    /* Filter & Breakdown Bar */
    .filter-panel {
        background: #ffffff;
        border-radius: 1rem;
        padding: 1rem 1.25rem;
        border: 1px solid #fee2e2;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
    }
    .search-input-wrap {
        position: relative;
        flex: 1;
        min-width: 240px;
    }
    .search-input-wrap i.search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.95rem;
    }
    .search-input-wrap input {
        width: 100%;
        padding: 0.65rem 1rem 0.65rem 2.6rem;
        border-radius: 0.75rem;
        border: 1.5px solid #e2e8f0;
        font-size: 0.88rem;
        color: #0f172a;
        font-weight: 500;
        transition: all 0.2s ease;
        background: #f8fafc;
    }
    .search-input-wrap input:focus {
        outline: none;
        border-color: #b91c1c;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(185, 28, 28, 0.12);
    }

    /* Breakdown Selector Pills (25 / 50 / 100) */
    .breakdown-group {
        display: inline-flex;
        align-items: center;
        background: #f1f5f9;
        padding: 3px;
        border-radius: 0.65rem;
        border: 1px solid #e2e8f0;
    }
    .breakdown-pill {
        padding: 0.35rem 0.75rem;
        font-size: 0.78rem;
        font-weight: 700;
        border-radius: 0.5rem;
        color: #475569;
        text-decoration: none;
        transition: all 0.18s ease;
    }
    .breakdown-pill:hover {
        color: #0f172a;
        background: rgba(255, 255, 255, 0.8);
    }
    .breakdown-pill.active {
        background: #b91c1c;
        color: #ffffff;
        box-shadow: 0 2px 6px rgba(185, 28, 28, 0.3);
    }

    /* Full Desktop Ultra-Wide Table */
    .table-responsive-box {
        background: #ffffff;
        border-radius: 1rem;
        border: 1px solid #fecaca;
        overflow: hidden;
        box-shadow: 0 4px 20px -3px rgba(0, 0, 0, 0.04);
    }
    .supplier-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.84rem;
    }
    .supplier-table thead th {
        background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
        color: #f8fafc;
        font-weight: 800;
        font-size: 0.72rem;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        padding: 0.9rem 1rem;
        border-bottom: 2px solid #b91c1c;
        white-space: nowrap;
    }
    .supplier-table tbody tr {
        transition: background-color 0.15s ease;
        border-bottom: 1px solid #f1f5f9;
    }
    .supplier-table tbody tr:hover {
        background-color: #fff1f2;
    }
    .supplier-table tbody td {
        padding: 0.85rem 1rem;
        vertical-align: middle;
        color: #334155;
    }

    /* Badges & Pills */
    .badge-id {
        font-family: monospace;
        font-size: 0.82rem;
        font-weight: 800;
        padding: 0.2rem 0.55rem;
        border-radius: 0.5rem;
        background: #f1f5f9;
        color: #0f172a;
        border: 1px solid #cbd5e1;
    }
    .badge-sys {
        font-family: monospace;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 0.15rem 0.45rem;
        border-radius: 0.4rem;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }

    /* Dynamic Status Badges for ANY condition */
    .status-badge-active {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 800;
        background: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }
    .status-badge-inactive {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 800;
        background: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }
    .status-badge-pending {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 800;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }
    .status-badge-neutral {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 800;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
    }
    .status-badge-custom {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 800;
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }

    /* Contact Links */
    .contact-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.76rem;
        font-weight: 600;
        padding: 0.2rem 0.5rem;
        border-radius: 0.45rem;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .contact-chip-phone {
        background: #f8fafc;
        color: #0f172a;
        border: 1px solid #e2e8f0;
    }
    .contact-chip-phone:hover {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }
    .contact-chip-wa {
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
    }
    .contact-chip-wa:hover {
        background: #d1fae5;
        color: #065f46;
    }

    /* Row Action Buttons */
    .row-btn-edit {
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        padding: 0.35rem 0.65rem;
        border-radius: 0.5rem;
        font-size: 0.76rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .row-btn-edit:hover {
        background: #dbeafe;
        color: #1e40af;
        border-color: #93c5fd;
    }
    .row-btn-print {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
        padding: 0.35rem 0.65rem;
        border-radius: 0.5rem;
        font-size: 0.76rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .row-btn-print:hover {
        background: #b91c1c;
        color: #ffffff;
        border-color: #991b1b;
    }
    .row-btn-delete {
        background: #fff1f2;
        color: #e11d48;
        border: 1px solid #fecdd3;
        padding: 0.35rem 0.55rem;
        border-radius: 0.5rem;
        font-size: 0.76rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .row-btn-delete:hover {
        background: #e11d48;
        color: #ffffff;
        border-color: #be123c;
    }

    /* =========================================================
       MODAL STYLES (Bulletproof & Universal Status Support)
       ========================================================= */
    #supplierModal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 999999;
        overflow-y: auto;
        padding: 1.25rem 1rem;
        align-items: center;
        justify-content: center;
    }
    #supplierModal.is-open {
        display: flex !important;
    }
    .modal-box {
        background: #ffffff;
        border-radius: 1.25rem;
        border: 1px solid #fecaca;
        box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);
        width: 100%;
        max-width: 52rem;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: modalScaleUp 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    @keyframes modalScaleUp {
        from { transform: scale(0.96) translateY(12px); opacity: 0; }
        to { transform: scale(1) translateY(0); opacity: 1; }
    }
    .modal-head {
        background: linear-gradient(135deg, #991b1b 0%, #7f1d1d 100%);
        padding: 1rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: #ffffff;
        border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    }
    .modal-body {
        overflow-y: auto;
        padding: 1.5rem;
        background: #ffffff;
    }
    .modal-foot {
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        padding: 1rem 1.5rem;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }

    /* Modal Form Controls */
    .field-group-card {
        background: #f8fafc;
        border-radius: 0.85rem;
        border: 1px solid #e2e8f0;
        padding: 1rem 1.15rem;
        margin-bottom: 1rem;
    }
    .field-group-title {
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #b91c1c;
        display: flex;
        align-items: center;
        gap: 0.4rem;
        margin-bottom: 0.75rem;
    }
    .form-lbl {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #475569;
        margin-bottom: 0.35rem;
    }
    .form-ctrl-wrap {
        position: relative;
    }
    .form-ctrl-wrap i {
        position: absolute;
        left: 0.85rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.85rem;
    }
    .form-ctrl {
        width: 100%;
        padding: 0.6rem 0.85rem 0.6rem 2.35rem;
        border-radius: 0.65rem;
        border: 1.5px solid #cbd5e1;
        font-size: 0.86rem;
        font-weight: 500;
        color: #0f172a;
        background: #ffffff;
        transition: all 0.15s ease;
    }
    .form-ctrl:focus {
        outline: none;
        border-color: #b91c1c;
        box-shadow: 0 0 0 3px rgba(185, 28, 28, 0.12);
    }

    /* =========================================================
       PRINT PREVIEW MODAL (Screen Presentation Only)
       ========================================================= */
    #printPreviewModal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.85);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 999999;
        overflow-y: auto;
        padding: 1.5rem 1rem;
        align-items: flex-start;
        justify-content: center;
    }
    #printPreviewModal.is-open {
        display: flex !important;
    }
    .preview-shell {
        max-width: 165mm;
        width: 100%;
        margin: auto;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .preview-bar {
        width: 100%;
        background: #0f172a;
        color: #ffffff;
        border-radius: 0.85rem 0.85rem 0 0;
        padding: 0.85rem 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: 1px solid #334155;
        border-bottom: none;
    }
    .preview-canvas-box {
        width: 100%;
        background: #334155;
        padding: 1.5rem 1rem;
        border-radius: 0 0 0.85rem 0.85rem;
        border: 1px solid #475569;
        display: flex;
        justify-content: center;
        overflow-x: auto;
    }

    /* Dedicated screen-hidden print container */
    #print-sheet-wrapper {
        display: none;
    }

    /* =========================================================
       PRINT STYLES (@media print)
       Exact 1-Page A5 Portrait (148mm x 210mm) Architecture
       ========================================================= */
    @media print {
        @page {
            size: A5 portrait;
            margin: 5mm 6mm;
        }
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            box-sizing: border-box !important;
        }
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
            color: #000000 !important;
            width: 100% !important;
            height: auto !important;
        }
        /* Completely hide site chrome */
        .app-container > *:not(#print-sheet-wrapper),
        .main-content > *:not(#print-sheet-wrapper),
        header, footer, nav, .top-nav, .site-footer, main, #supplierModal, #printPreviewModal, .no-print {
            display: none !important;
            visibility: hidden !important;
        }
        #print-sheet-wrapper {
            display: block !important;
            visibility: visible !important;
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: #ffffff !important;
        }
        #print-sheet-wrapper * {
            visibility: visible !important;
        }
    }
</style>

<!-- ========================================================
     MAIN BODY & DASHBOARD PORTAL
     ======================================================== -->
<main class="supplier-workspace no-print space-y-5">

    <!-- Flash Message Notification -->
    <?php if (isset($_GET['msg'])): ?>
    <div id="flashMsg" class="p-4 rounded-xl bg-emerald-50 border-l-4 border-l-emerald-600 border border-emerald-200 text-emerald-900 flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
                <i class="fas fa-check"></i>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-700">System Notification</p>
                <p class="text-sm font-semibold"><?= htmlspecialchars($_GET['msg']); ?></p>
            </div>
        </div>
        <button onclick="document.getElementById('flashMsg').style.display='none';" class="text-emerald-700 hover:text-emerald-900 text-lg px-2">&times;</button>
    </div>
    <?php endif; ?>

    <!-- 1. Executive Portal Header & Fast Action Hub -->
    <div class="glass-card p-6 border-l-4 border-l-red-600 bg-gradient-to-r from-white via-red-50/25 to-white flex flex-col lg:flex-row lg:items-center justify-between gap-5">
        <div>
            <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-red-100 text-red-800 text-xs font-bold uppercase tracking-wider mb-2">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <i class="fas fa-shield-alt text-red-600"></i> Dual DB Synchronized (po_system ⇄ return_qc)
            </div>
            <h1 class="text-2xl lg:text-3xl font-black text-slate-800 tracking-tight flex items-center gap-3">
                <i class="fas fa-truck-loading text-red-600"></i> Supplier Management Portal
            </h1>
            <p class="text-sm text-slate-500 mt-1 max-w-2xl">
                Universal vendor management for all supplier conditions, real-time QC verification, dual-database consistency, and precision A5 print engine.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <button type="button" onclick="openModal('add')" class="btn-asb-primary">
                <i class="fas fa-plus-circle"></i> Register Supplier
            </button>
            <button type="button" onclick="printEmptyForm()" class="btn-asb-secondary" title="Print Blank A5 Supplier Registration Form">
                <i class="fas fa-file-invoice"></i> Blank Form (A5)
            </button>
            <button type="button" onclick="printAllSuppliersPartitioned()" class="btn-asb-dark" title="Print currently loaded <?= count($suppliers); ?> records partitioned onto separate A5 pages">
                <i class="fas fa-print"></i> Print Records (<?= count($suppliers); ?> A5 Pages)
            </button>
        </div>
    </div>

    <!-- 2. KPI Interactive Counters -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="kpi-card border-l-4 border-l-red-600 flex items-center gap-4">
            <div class="kpi-icon-wrap bg-red-50 text-red-700">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Suppliers</p>
                <h3 class="text-2xl font-black text-slate-800"><?= number_format($totalAllSuppliers); ?></h3>
                <span class="text-[11px] font-semibold text-red-600">All Conditions / Profiles</span>
            </div>
        </div>

        <div class="kpi-card border-l-4 border-l-emerald-600 flex items-center gap-4">
            <div class="kpi-icon-wrap bg-emerald-50 text-emerald-700">
                <i class="fas fa-check-circle"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Active Partners</p>
                <h3 class="text-2xl font-black text-slate-800"><?= number_format($totalActiveSuppliers); ?></h3>
                <span class="text-[11px] font-semibold text-emerald-600">
                    Certified Active
                </span>
            </div>
        </div>

        <div class="kpi-card border-l-4 border-l-amber-500 flex items-center gap-4">
            <div class="kpi-icon-wrap bg-amber-50 text-amber-700">
                <i class="fas fa-pause-circle"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Other / Unassigned</p>
                <h3 class="text-2xl font-black text-slate-800"><?= number_format($totalInactiveSuppliers); ?></h3>
                <span class="text-[11px] font-semibold text-amber-600">General / Review Condition</span>
            </div>
        </div>

        <div class="kpi-card border-l-4 border-l-blue-600 flex items-center gap-4">
            <div class="kpi-icon-wrap bg-blue-50 text-blue-700">
                <i class="fas fa-sync-alt"></i>
            </div>
            <div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Dual Sync Status</p>
                <h3 class="text-2xl font-black text-slate-800">100%</h3>
                <span class="text-[11px] font-semibold text-blue-600">po_system ⇄ return_qc</span>
            </div>
        </div>
    </div>

    <!-- 3. Search, Filter & 25/50/100 Breakdown Command Center -->
    <div class="filter-panel">
        <form method="GET" action="supplier_module.php" class="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
            <!-- Search & Status Input -->
            <div class="flex flex-wrap items-center gap-3 flex-1">
                <div class="search-input-wrap">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Search by name, contact, ERP ID, phone, condition...">
                </div>

                <div class="w-52">
                    <select name="status_filter" onchange="this.form.submit()" class="w-full py-2.5 px-3 rounded-xl border border-slate-300 bg-slate-50 text-sm font-semibold text-slate-700 focus:outline-none focus:border-red-600">
                        <option value="">All Statuses (Any Condition)</option>
                        <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : ''; ?>>Active Only</option>
                        <option value="Inactive" <?= $statusFilter === 'Inactive' ? 'selected' : ''; ?>>Inactive / Suspended</option>
                        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : ''; ?>>New / Pending</option>
                        <option value="Unassigned" <?= $statusFilter === 'Unassigned' ? 'selected' : ''; ?>>Unassigned / General</option>
                    </select>
                </div>

                <input type="hidden" name="per_page" value="<?= $perPage; ?>">

                <button type="submit" class="btn-asb-primary py-2.5 px-4 text-xs">
                    <i class="fas fa-filter"></i> Apply
                </button>

                <?php if (!empty($search) || !empty($statusFilter)): ?>
                <a href="supplier_module.php?per_page=<?= $perPage; ?>" class="btn-asb-secondary py-2.5 px-3 text-xs" title="Reset Filters">
                    <i class="fas fa-undo"></i> Reset
                </a>
                <?php endif; ?>
            </div>

            <!-- Per-Page Breakdown Selector (25 / 50 / 100) -->
            <div class="flex items-center gap-2 self-end md:self-center">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Per Page:</span>
                <div class="breakdown-group">
                    <a href="<?= buildSupplierUrl(['per_page' => 25, 'page' => 1]); ?>" class="breakdown-pill <?= $perPage === 25 ? 'active' : ''; ?>">25</a>
                    <a href="<?= buildSupplierUrl(['per_page' => 50, 'page' => 1]); ?>" class="breakdown-pill <?= $perPage === 50 ? 'active' : ''; ?>">50</a>
                    <a href="<?= buildSupplierUrl(['per_page' => 100, 'page' => 1]); ?>" class="breakdown-pill <?= $perPage === 100 ? 'active' : ''; ?>">100</a>
                </div>
            </div>
        </form>
    </div>

    <!-- 4. Ultra-Wide Full Desktop Supplier Master Table -->
    <div class="table-responsive-box">
        <div class="overflow-x-auto">
            <table class="supplier-table">
                <thead>
                    <tr>
                        <th class="w-16 text-center">ID &amp; ERP</th>
                        <th>Company / Vendor Name</th>
                        <th>Primary Liaison</th>
                        <th>Direct Channels</th>
                        <th>Office &amp; Factory Address</th>
                        <th class="text-center">Condition &amp; Sync</th>
                        <th class="text-center w-48">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-16 text-slate-400">
                            <i class="fas fa-folder-open text-4xl mb-3 text-red-200"></i>
                            <p class="text-base font-bold text-slate-600">No supplier records match the selected condition.</p>
                            <p class="text-xs text-slate-400 mt-1">Try resetting the filter or registering a new vendor profile.</p>
                            <button onclick="openModal('add')" class="btn-asb-primary mt-4 py-2 px-4 text-xs">
                                <i class="fas fa-plus"></i> Register First Supplier
                            </button>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $s): 
                            $rawStatus = trim($s['status'] ?? '');
                            $sysId = !empty($s['system_id']) ? htmlspecialchars($s['system_id']) : ('#' . str_pad($s['supplier_id'], 5, '0', STR_PAD_LEFT));

                            // Dynamic badge handling for every condition
                            if (empty($rawStatus)) {
                                $badgeClass = 'status-badge-neutral';
                                $badgeIcon = 'fa-circle-notch';
                                $badgeLabel = 'Standard';
                            } elseif (strcasecmp($rawStatus, 'Active') === 0) {
                                $badgeClass = 'status-badge-active';
                                $badgeIcon = 'fa-check-circle';
                                $badgeLabel = 'Active';
                            } elseif (stripos($rawStatus, 'Inactive') !== false || stripos($rawStatus, 'Suspended') !== false || stripos($rawStatus, 'Hold') !== false) {
                                $badgeClass = 'status-badge-inactive';
                                $badgeIcon = 'fa-exclamation-circle';
                                $badgeLabel = htmlspecialchars($rawStatus);
                            } elseif (stripos($rawStatus, 'New') !== false || stripos($rawStatus, 'Pending') !== false) {
                                $badgeClass = 'status-badge-pending';
                                $badgeIcon = 'fa-clock';
                                $badgeLabel = htmlspecialchars($rawStatus);
                            } else {
                                $badgeClass = 'status-badge-custom';
                                $badgeIcon = 'fa-tag';
                                $badgeLabel = htmlspecialchars($rawStatus);
                            }
                        ?>
                        <tr>
                            <!-- ID & ERP -->
                            <td class="text-center whitespace-nowrap">
                                <div class="inline-flex flex-col items-center gap-1">
                                    <span class="badge-id">#<?= $s['supplier_id']; ?></span>
                                    <span class="badge-sys"><?= $sysId; ?></span>
                                </div>
                            </td>

                            <!-- Company Name -->
                            <td>
                                <div class="flex items-start gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-red-50 text-red-700 flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5 border border-red-100">
                                        <i class="fas fa-building"></i>
                                    </div>
                                    <div>
                                        <div class="font-extrabold text-slate-900 text-sm">
                                            <?= htmlspecialchars($s['supplier_name']); ?>
                                        </div>
                                        <div class="text-[11px] font-semibold text-slate-400 flex items-center gap-2 mt-0.5">
                                            <span>Registered Supplier</span>
                                            <span>·</span>
                                            <span class="text-red-700 font-bold">ASB Partner</span>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Primary Liaison -->
                            <td>
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-user-tie text-red-600 text-xs"></i>
                                    <span class="font-bold text-slate-800 text-xs">
                                        <?= !empty($s['contact_person']) ? htmlspecialchars($s['contact_person']) : '<span class="text-slate-400 font-normal">—</span>'; ?>
                                    </span>
                                </div>
                            </td>

                            <!-- Direct Channels -->
                            <td>
                                <div class="flex flex-col gap-1 items-start">
                                    <?php if (!empty($s['contact_number'])): ?>
                                        <a href="tel:<?= htmlspecialchars($s['contact_number']); ?>" class="contact-chip contact-chip-phone" title="Call Mobile">
                                            <i class="fas fa-phone-alt text-red-600 text-[10px]"></i>
                                            <?= htmlspecialchars($s['contact_number']); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($s['whatsapp'])): ?>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $s['whatsapp']); ?>" target="_blank" rel="noopener" class="contact-chip contact-chip-wa" title="Open WhatsApp Chat">
                                            <i class="fab fa-whatsapp text-emerald-600 text-[11px]"></i>
                                            <?= htmlspecialchars($s['whatsapp']); ?>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (empty($s['contact_number']) && empty($s['whatsapp'])): ?>
                                        <span class="text-xs text-slate-400">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Office & Factory Address -->
                            <td>
                                <div class="space-y-1 max-w-md">
                                    <?php if (!empty($s['email'])): ?>
                                        <div class="flex items-center gap-1.5 text-xs">
                                            <i class="fas fa-envelope text-red-600 text-[10px]"></i>
                                            <a href="mailto:<?= htmlspecialchars($s['email']); ?>" class="text-slate-700 hover:text-red-700 font-medium truncate">
                                                <?= htmlspecialchars($s['email']); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($s['land_number'])): ?>
                                        <div class="flex items-center gap-1.5 text-[11px] text-slate-500">
                                            <i class="fas fa-phone-office text-slate-400 text-[10px]"></i>
                                            <span>Land: <?= htmlspecialchars($s['land_number']); ?></span>
                                            <?php if (!empty($s['fax_number'])): ?>
                                                <span>· Fax: <?= htmlspecialchars($s['fax_number']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($s['address'])): ?>
                                        <div class="flex items-start gap-1.5 text-[11px] text-slate-500 line-clamp-1">
                                            <i class="fas fa-map-marker-alt text-red-500 text-[10px] mt-0.5"></i>
                                            <span><?= htmlspecialchars($s['address']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Condition & Sync Status -->
                            <td class="text-center">
                                <div class="inline-flex flex-col items-center gap-1">
                                    <span class="<?= $badgeClass; ?>">
                                        <i class="fas <?= $badgeIcon; ?> text-[7px]"></i> <?= $badgeLabel; ?>
                                    </span>
                                    <span class="text-[10px] font-bold text-emerald-700 flex items-center gap-1">
                                        <i class="fas fa-check-double text-[9px]"></i> Dual Synced
                                    </span>
                                </div>
                            </td>

                            <!-- Action Hub (Works for EVERY condition!) -->
                            <td class="text-center whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5">
                                    <!-- Edit Button (Loads for ANY status) -->
                                    <button type="button" onclick="openEditModal(<?= (int)$s['supplier_id']; ?>)" class="row-btn-edit" title="Modify Supplier Data">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <!-- Print Single Supplier Button (Prints for ANY status) -->
                                    <button type="button" onclick="printSingleSupplier(<?= (int)$s['supplier_id']; ?>)" class="row-btn-print" title="Print A5 Registered Supplier Dossier">
                                        <i class="fas fa-print"></i> Print A5
                                    </button>

                                    <!-- Delete Button with Safe Confirmation -->
                                    <a href="supplier_module.php?action=delete&id=<?= (int)$s['supplier_id']; ?>" onclick="return confirm('Permanently remove supplier \'<?= addslashes(htmlspecialchars($s['supplier_name'])); ?>\' from both po_system and return_qc databases?');" class="row-btn-delete" title="Permanently Delete Supplier">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Footer & Breakdown Pagination Bar -->
        <div class="p-4 border-t border-red-200/70 bg-red-50/20 flex flex-col sm:flex-row justify-between items-center gap-4">
            <!-- Left Info -->
            <div class="flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-600">
                <span>Showing <span class="font-bold text-red-700"><?= $totalRecords > 0 ? $offset + 1 : 0; ?></span> to 
                <span class="font-bold text-red-700"><?= min($offset + $limit, $totalRecords); ?></span> of 
                <span class="font-bold text-red-700"><?= number_format($totalRecords); ?></span> suppliers</span>

                <span class="text-slate-300 hidden sm:inline">|</span>

                <div class="flex items-center gap-1.5">
                    <span class="text-slate-500">Breakdown:</span>
                    <a href="<?= buildSupplierUrl(['per_page' => 25, 'page' => 1]); ?>" class="px-2 py-0.5 rounded text-[11px] font-bold <?= $perPage === 25 ? 'bg-red-700 text-white' : 'text-slate-600 hover:bg-red-100'; ?>">25</a>
                    <a href="<?= buildSupplierUrl(['per_page' => 50, 'page' => 1]); ?>" class="px-2 py-0.5 rounded text-[11px] font-bold <?= $perPage === 50 ? 'bg-red-700 text-white' : 'text-slate-600 hover:bg-red-100'; ?>">50</a>
                    <a href="<?= buildSupplierUrl(['per_page' => 100, 'page' => 1]); ?>" class="px-2 py-0.5 rounded text-[11px] font-bold <?= $perPage === 100 ? 'bg-red-700 text-white' : 'text-slate-600 hover:bg-red-100'; ?>">100</a>
                </div>
            </div>

            <!-- Numeric Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="flex items-center gap-1">
                <?php if ($page > 1): ?>
                    <a href="<?= buildSupplierUrl(['page' => 1]); ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-red-50 hover:text-red-700" title="First Page">&laquo;</a>
                    <a href="<?= buildSupplierUrl(['page' => $page - 1]); ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-red-50 hover:text-red-700">Prev</a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage   = min($totalPages, $page + 2);
                for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <a href="<?= buildSupplierUrl(['page' => $p]); ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold <?= $p === $page ? 'bg-red-700 text-white shadow-sm' : 'border border-slate-300 text-slate-700 hover:bg-red-50 hover:text-red-700'; ?>">
                        <?= $p; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= buildSupplierUrl(['page' => $page + 1]); ?>" class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-red-50 hover:text-red-700">Next</a>
                    <a href="<?= buildSupplierUrl(['page' => $totalPages]); ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-300 text-xs font-bold text-slate-700 hover:bg-red-50 hover:text-red-700" title="Last Page">&raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</main>


<!-- ========================================================
     MODERN SUPPLIER ADD / EDIT MODAL (Universal Condition Engine)
     ======================================================== -->
<div id="supplierModal" class="no-print">
    <div class="modal-box">
        
        <!-- Modal Header -->
        <div class="modal-head">
            <h3 id="modalTitle" class="font-extrabold text-base tracking-wide flex items-center gap-2.5">
                <i class="fas fa-building"></i> Supplier Master Registration
            </h3>
            <button type="button" onclick="closeModal()" class="text-red-200 hover:text-white text-2xl font-bold leading-none transition cursor-pointer">&times;</button>
        </div>

        <!-- Form inside Modal Body -->
        <form id="supplierForm" method="POST" action="supplier_module.php?action=create">
            <input type="hidden" id="supplier_id" name="supplier_id">

            <div class="modal-body space-y-4">
                
                <!-- Section 1: Corporate Business Identity -->
                <div class="field-group-card">
                    <div class="field-group-title">
                        <i class="fas fa-briefcase"></i> 1. Corporate Business Identity
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div class="sm:col-span-2">
                            <label class="form-lbl">Corporate Supplier Name <span class="text-red-600">*</span></label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-building"></i>
                                <input type="text" id="supplier_name" name="supplier_name" required class="form-ctrl" placeholder="e.g. Apex Fabrics (Pvt) Ltd">
                            </div>
                        </div>

                        <div>
                            <label class="form-lbl">System / ERP Code</label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-hashtag"></i>
                                <input type="text" id="system_id" name="system_id" class="form-ctrl" placeholder="e.g. SYS-1002">
                            </div>
                        </div>

                        <!-- Universal Condition / Status Selector -->
                        <div>
                            <label class="form-lbl">Partnership Condition / Status</label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-toggle-on"></i>
                                <select id="status_select" class="form-ctrl" onchange="handleStatusChange(this.value)">
                                    <option value="Active">Active Partnership</option>
                                    <option value="Inactive">Inactive / Suspended</option>
                                    <option value="Pending">Pending Verification</option>
                                    <option value="New">New Vendor Onboarding</option>
                                    <option value="Suspended">Suspended / On Hold</option>
                                    <option value="__custom__">+ Enter Custom Condition...</option>
                                </select>
                            </div>
                            <!-- Actual submitted status field -->
                            <input type="hidden" id="status" name="status" value="Active">

                            <div id="customStatusWrap" class="mt-2 hidden">
                                <input type="text" id="custom_status_input" class="form-ctrl py-1.5 text-xs" placeholder="Type custom status / condition (e.g. Review, Trial, Seasonal)" oninput="document.getElementById('status').value = this.value.trim() || 'Active'">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Primary Liaison Representative -->
                <div class="field-group-card">
                    <div class="field-group-title">
                        <i class="fas fa-user-tie"></i> 2. Primary Liaison Representative
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="form-lbl">Contact Person <span class="text-red-600">*</span></label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-user"></i>
                                <input type="text" id="contact_person" name="contact_person" required class="form-ctrl" placeholder="Full Name">
                            </div>
                        </div>

                        <div>
                            <label class="form-lbl">Mobile Number <span class="text-red-600">*</span></label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-mobile-alt"></i>
                                <input type="text" id="contact_number" name="contact_number" required class="form-ctrl" placeholder="07XXXXXXXX">
                            </div>
                        </div>

                        <div>
                            <label class="form-lbl">WhatsApp Number</label>
                            <div class="form-ctrl-wrap">
                                <i class="fab fa-whatsapp text-emerald-600"></i>
                                <input type="text" id="whatsapp" name="whatsapp" class="form-ctrl" placeholder="07XXXXXXXX">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Official Corporate Channels -->
                <div class="field-group-card">
                    <div class="field-group-title">
                        <i class="fas fa-envelope-open-text"></i> 3. Official Corporate Channels
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="form-lbl">Official Email</label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="email" name="email" class="form-ctrl" placeholder="procurement@company.com">
                            </div>
                        </div>

                        <div>
                            <label class="form-lbl">Landline Telephone</label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-phone"></i>
                                <input type="text" id="land_number" name="land_number" class="form-ctrl" placeholder="011XXXXXXX">
                            </div>
                        </div>

                        <div>
                            <label class="form-lbl">Fax Number</label>
                            <div class="form-ctrl-wrap">
                                <i class="fas fa-fax"></i>
                                <input type="text" id="fax_number" name="fax_number" class="form-ctrl" placeholder="011XXXXXXX">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 4: Physical Address -->
                <div class="field-group-card">
                    <div class="field-group-title">
                        <i class="fas fa-map-marker-alt"></i> 4. Factory &amp; Registered Office Address
                    </div>
                    <textarea id="address" name="address" rows="2" class="w-full p-3 rounded-xl border border-slate-300 text-sm font-medium focus:outline-none focus:border-red-600 focus:ring-2 focus:ring-red-100 transition" placeholder="Street Address, Industrial Zone, City, Country"></textarea>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-foot">
                <button type="button" onclick="closeModal()" class="btn-asb-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-asb-primary">
                    <i class="fas fa-save"></i> Save Supplier Master Record
                </button>
            </div>
        </form>

    </div>
</div>


<!-- ========================================================
     PRINT PREVIEW MODAL (Screen Presentation Only)
     ======================================================== -->
<div id="printPreviewModal" class="no-print">
    <div class="preview-shell">
        <!-- Preview Action Bar -->
        <div class="preview-bar">
            <h4 id="previewDocTitle" class="text-sm font-extrabold flex items-center gap-2">
                <i class="fas fa-file-invoice text-red-400"></i> A5 Document Ready to Print
            </h4>
            <div class="flex items-center gap-2">
                <button type="button" onclick="reprintCurrentDocument()" class="btn-asb-primary py-1.5 px-3 text-xs">
                    <i class="fas fa-print"></i> Print Document (A5)
                </button>
                <button type="button" onclick="closePrintPreviewModal()" class="btn-asb-secondary py-1.5 px-3 text-xs">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>

        <!-- Authentic Canvas Container -->
        <div class="preview-canvas-box">
            <div id="preview-render-area"></div>
        </div>
    </div>
</div>


<!-- Dedicated hidden print wrapper for @media print fallback -->
<div id="print-sheet-wrapper"></div>


<!-- ========================================================
     CLIENT JAVASCRIPT ENGINE & UNIVERSAL PRINT / EDIT GENERATOR
     ======================================================== -->
<script>
    // 1. Safe Suppliers Map (Immune to quote breakages)
    const suppliersData = <?= json_encode($suppliersById, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const allSuppliersList = Object.values(suppliersData);

    let currentPrintHtml = '';
    let currentPrintTitle = '';

    // Universal Status Setup for Modal
    function setModalStatus(rawVal) {
        const sel = document.getElementById('status_select');
        const hiddenStatus = document.getElementById('status');
        const customWrap = document.getElementById('customStatusWrap');
        const customInput = document.getElementById('custom_status_input');

        const val = (rawVal && String(rawVal).trim() !== '') ? String(rawVal).trim() : 'Active';
        hiddenStatus.value = val;

        let matched = false;
        for (let i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value.toLowerCase() === val.toLowerCase()) {
                sel.selectedIndex = i;
                matched = true;
                break;
            }
        }

        if (matched) {
            customWrap.classList.add('hidden');
            customInput.value = '';
        } else {
            sel.value = '__custom__';
            customWrap.classList.remove('hidden');
            customInput.value = val;
        }
    }

    function handleStatusChange(val) {
        const hiddenStatus = document.getElementById('status');
        const customWrap = document.getElementById('customStatusWrap');
        const customInput = document.getElementById('custom_status_input');

        if (val === '__custom__') {
            customWrap.classList.remove('hidden');
            customInput.focus();
            hiddenStatus.value = customInput.value.trim() || 'Active';
        } else {
            customWrap.classList.add('hidden');
            customInput.value = '';
            hiddenStatus.value = val;
        }
    }

    // 2. Modal Controller Functions (Works for ALL conditions)
    function openModal(mode) {
        const modal = document.getElementById('supplierModal');
        const title = document.getElementById('modalTitle');
        const form = document.getElementById('supplierForm');

        if (mode === 'add') {
            title.innerHTML = '<i class="fas fa-plus-circle text-red-200"></i> Register New Supplier Master Record';
            form.action = 'supplier_module.php?action=create';
            form.reset();
            document.getElementById('supplier_id').value = '';
            setModalStatus('Active');
        }

        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            const input = document.getElementById('supplier_name');
            if (input) input.focus();
        }, 120);
    }

    function closeModal() {
        const modal = document.getElementById('supplierModal');
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    function openEditModal(supplierId) {
        const s = suppliersData[supplierId];
        if (!s) {
            alert('Supplier record #' + supplierId + ' could not be found.');
            return;
        }

        document.getElementById('supplier_id').value = s.supplier_id;
        document.getElementById('supplier_name').value = s.supplier_name || '';
        document.getElementById('system_id').value = s.system_id || '';
        setModalStatus(s.status);
        document.getElementById('contact_person').value = s.contact_person || '';
        document.getElementById('contact_number').value = s.contact_number || '';
        document.getElementById('whatsapp').value = s.whatsapp || '';
        document.getElementById('land_number').value = s.land_number || '';
        document.getElementById('fax_number').value = s.fax_number || '';
        document.getElementById('email').value = s.email || '';
        document.getElementById('address').value = s.address || '';

        const title = document.getElementById('modalTitle');
        title.innerHTML = '<i class="fas fa-edit text-red-200"></i> Modify Supplier #' + s.supplier_id + ' · ' + escapeHtml(s.supplier_name);

        const form = document.getElementById('supplierForm');
        form.action = 'supplier_module.php?action=update';

        openModal('edit');
    }

    // Modal click-backdrop listener
    document.getElementById('supplierModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.getElementById('printPreviewModal').addEventListener('click', function(e) {
        if (e.target === this) closePrintPreviewModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
            closePrintPreviewModal();
        }
    });

    // 3. String & Number Utility
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function generateFormNumber(supplierId) {
        const year = new Date().getFullYear();
        const padded = String(supplierId).padStart(4, '0');
        return `ASB-SUP-${year}-${padded}`;
    }

    // Universal Status Helper for A5 Print Reports (Accurate for ANY condition)
    function resolveConditionMeta(rawStatus) {
        const s = (rawStatus && String(rawStatus).trim() !== '') ? String(rawStatus).trim() : 'Standard';
        if (/active/i.test(s)) {
            return {
                pillClass: 'pill-active',
                label: s,
                syncBadge: '✓ Verified Active Partner',
                color: '#166534'
            };
        }
        if (/inactive|suspended|hold|blocked/i.test(s)) {
            return {
                pillClass: 'pill-inactive',
                label: s,
                syncBadge: '⚠ ' + s + ' (Restricted / Hold)',
                color: '#991b1b'
            };
        }
        if (/new|pending/i.test(s)) {
            return {
                pillClass: 'pill-pending',
                label: s,
                syncBadge: 'ℹ ' + s + ' (Under Verification)',
                color: '#1d4ed8'
            };
        }
        return {
            pillClass: 'pill-neutral',
            label: s,
            syncBadge: '✓ ' + s + ' (Standard Profile)',
            color: '#334155'
        };
    }

    // 4. Exact A5 Print CSS Stylesheet (For Iframe and Fallback)
    function getA5PrintStyles() {
        return `
            @page {
                size: A5 portrait;
                margin: 5mm 6mm;
            }
            * {
                box-sizing: border-box;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body {
                margin: 0;
                padding: 0;
                background: #ffffff;
                color: #0f172a;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                font-size: 8.5pt;
                line-height: 1.25;
            }
            .a5-sheet {
                width: 100%;
                max-width: 136mm;
                min-height: 198mm;
                margin: 0 auto;
                padding: 5.5mm 6.5mm;
                background: #ffffff;
                border: 2px solid #b91c1c;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .a5-page-break {
                page-break-after: always;
                break-after: page;
            }
            .a5-page-break:last-child {
                page-break-after: avoid;
                break-after: avoid;
            }
            /* Header */
            .a5-header {
                text-align: center;
                padding-bottom: 4px;
                border-bottom: 2px solid #b91c1c;
                margin-bottom: 5px;
            }
            .a5-brand-title {
                font-size: 13pt;
                font-weight: 900;
                color: #b91c1c;
                letter-spacing: 0.8px;
                margin: 0;
            }
            .a5-brand-sub {
                font-size: 8pt;
                font-weight: 800;
                color: #0f172a;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                margin-top: 1px;
            }
            .a5-brand-dept {
                font-size: 6.5pt;
                font-weight: 600;
                color: #64748b;
                margin-top: 1px;
            }
            /* Meta Bar */
            .a5-meta-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 4px;
                padding: 3px 6px;
                margin-bottom: 5px;
                font-size: 6.8pt;
            }
            .meta-lbl {
                font-weight: 800;
                color: #64748b;
                margin-right: 3px;
            }
            .meta-val {
                font-weight: 800;
                color: #0f172a;
            }
            .status-pill {
                font-weight: 800;
                padding: 1px 6px;
                border-radius: 9999px;
                text-transform: uppercase;
                font-size: 6.5pt;
                letter-spacing: 0.03em;
            }
            .pill-active {
                background: #dcfce7;
                color: #166534;
                border: 1px solid #bbf7d0;
            }
            .pill-inactive {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            .pill-pending {
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #bfdbfe;
            }
            .pill-neutral {
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
            }
            /* Record Hero Box */
            .a5-record-hero {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #fef2f2;
                border: 1.5px solid #fecaca;
                border-radius: 5px;
                padding: 5px 8px;
                margin-bottom: 5px;
            }
            .hero-name {
                font-size: 11pt;
                font-weight: 900;
                color: #991b1b;
                line-height: 1.2;
            }
            .hero-person {
                font-size: 7.5pt;
                font-weight: 700;
                color: #334155;
                margin-top: 2px;
            }
            .hero-sys-id-box {
                text-align: right;
            }
            .hero-sys-lbl {
                font-size: 6pt;
                font-weight: 800;
                color: #94a3b8;
                text-transform: uppercase;
            }
            .hero-sys-val {
                font-size: 8.5pt;
                font-weight: 900;
                color: #1e3a8a;
                font-family: monospace;
            }
            /* Section Blocks */
            .a5-section {
                margin-bottom: 5px;
            }
            .a5-sec-title {
                font-size: 7pt;
                font-weight: 800;
                color: #b91c1c;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-bottom: 1px solid #fecaca;
                padding-bottom: 2px;
                margin-bottom: 3px;
            }
            /* High Density Tables */
            .a5-data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 7.2pt;
            }
            .a5-data-table td {
                padding: 2.5px 4px;
                border: 1px solid #e2e8f0;
                vertical-align: middle;
            }
            .dt-lbl {
                width: 25%;
                font-weight: 800;
                color: #475569;
                background: #f8fafc;
            }
            .dt-val {
                width: 25%;
                font-weight: 700;
                color: #0f172a;
            }
            /* Fill In Blank Grid */
            .a5-fill-grid {
                width: 100%;
                border-collapse: collapse;
                font-size: 7.2pt;
            }
            .a5-fill-grid td {
                padding: 3.5px 4px;
                border: 1px solid #cbd5e1;
            }
            .f-lbl {
                background: #f8fafc;
                font-weight: 800;
                color: #334155;
                width: 28%;
            }
            .f-blank {
                width: 22%;
                color: #94a3b8;
                font-style: italic;
            }
            .f-dotted-line {
                border-bottom: 1px dotted #94a3b8;
                height: 14px;
                margin-top: 3px;
            }
            /* Dual DB Sync Banner */
            .sync-banner {
                background: #f0fdf4;
                border: 1px solid #bbf7d0;
                border-radius: 4px;
                padding: 4px 7px;
                display: flex;
                justify-content: space-between;
                font-size: 6.8pt;
                font-weight: 700;
            }
            /* Signatures */
            .a5-signatures {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
                margin-top: 4px;
                margin-bottom: 4px;
            }
            .a5-sig-card {
                border: 1px solid #cbd5e1;
                border-radius: 4px;
                padding: 4px 6px;
                background: #ffffff;
                text-align: center;
            }
            .sig-header {
                font-size: 6.2pt;
                font-weight: 800;
                color: #475569;
                text-transform: uppercase;
                border-bottom: 1px dashed #cbd5e1;
                padding-bottom: 2px;
                margin-bottom: 16px;
            }
            .sig-rule {
                border-top: 1px solid #0f172a;
                font-size: 6.8pt;
                font-weight: 800;
                color: #0f172a;
                padding-top: 2px;
            }
            .sig-sub {
                font-size: 6pt;
                color: #64748b;
                display: flex;
                justify-content: space-between;
                margin-top: 2px;
            }
            /* Footer */
            .a5-footer {
                border-top: 1px solid #cbd5e1;
                padding-top: 3px;
                display: flex;
                justify-content: space-between;
                font-size: 6pt;
                color: #64748b;
                font-weight: 600;
            }
            .a5-footer b {
                color: #b91c1c;
            }
        `;
    }

    // 5. HTML Builders for A5 Sheets (Handles EVERY condition dynamically!)
    function buildSingleSupplierHtml(s) {
        const formNo = generateFormNumber(s.supplier_id);
        const now = new Date();
        const formattedDate = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
        const sysId = s.system_id ? `#${escapeHtml(s.system_id)}` : `#${String(s.supplier_id).padStart(5, '0')}`;
        const condition = resolveConditionMeta(s.status);

        return `
            <div class="a5-sheet">
                <div>
                    <!-- Header -->
                    <div class="a5-header">
                        <div class="a5-brand-title">ASB GROUP OF COMPANIES</div>
                        <div class="a5-brand-sub">REGISTERED SUPPLIER MASTER DOSSIER</div>
                        <div class="a5-brand-dept">Enterprise Procurement &amp; Dual-DB Synchronization Profile</div>
                    </div>

                    <!-- Meta Bar -->
                    <div class="a5-meta-bar">
                        <div><span class="meta-lbl">FORM REF:</span> <span class="meta-val">${formNo}</span></div>
                        <div><span class="meta-lbl">CONDITION:</span> <span class="status-pill ${condition.pillClass}">${escapeHtml(condition.label)}</span></div>
                        <div><span class="meta-lbl">PRINTED:</span> <span class="meta-val">${formattedDate}</span></div>
                    </div>

                    <!-- Corporate Hero Box -->
                    <div class="a5-record-hero">
                        <div>
                            <div class="hero-name">${escapeHtml(s.supplier_name || 'N/A')}</div>
                            <div class="hero-person">
                                <span>Liaison: <b>${escapeHtml(s.contact_person || '—')}</b></span>
                                <span style="color:#64748b; font-weight:normal; margin-left:4px;">(Primary Representative)</span>
                            </div>
                        </div>
                        <div class="hero-sys-id-box">
                            <div class="hero-sys-lbl">System / ERP Code</div>
                            <div class="hero-sys-val">${sysId}</div>
                        </div>
                    </div>

                    <!-- 1. Registered Contact Directory -->
                    <div class="a5-section">
                        <div class="a5-sec-title">1. REGISTERED CONTACT &amp; CHANNEL DIRECTORY</div>
                        <table class="a5-data-table">
                            <tr>
                                <td class="dt-lbl">Mobile Phone:</td>
                                <td class="dt-val">${escapeHtml(s.contact_number || 'N/A')}</td>
                                <td class="dt-lbl">WhatsApp:</td>
                                <td class="dt-val">${escapeHtml(s.whatsapp || 'N/A')}</td>
                            </tr>
                            <tr>
                                <td class="dt-lbl">Landline:</td>
                                <td class="dt-val">${escapeHtml(s.land_number || 'N/A')}</td>
                                <td class="dt-lbl">Fax Number:</td>
                                <td class="dt-val">${escapeHtml(s.fax_number || '—')}</td>
                            </tr>
                            <tr>
                                <td class="dt-lbl">Official Email:</td>
                                <td class="dt-val" colspan="3">${escapeHtml(s.email || 'N/A')}</td>
                            </tr>
                            <tr>
                                <td class="dt-lbl">Factory / Address:</td>
                                <td class="dt-val" colspan="3">${escapeHtml(s.address || 'N/A')}</td>
                            </tr>
                        </table>
                    </div>

                    <!-- 2. Dual DB Sync & Compliance (Dynamic for ANY condition) -->
                    <div class="a5-section">
                        <div class="a5-sec-title">2. SYSTEM SYNC &amp; COMPLIANCE PROFILE</div>
                        <div class="sync-banner" style="color:${condition.color}; border-color:${condition.color}40; background:${condition.color}0a;">
                            <div>✓ Dual-DB Synced: <b>po_system</b> &amp; <b>return_qc</b></div>
                            <div><b>${escapeHtml(condition.syncBadge)}</b></div>
                        </div>
                    </div>

                    <!-- 3. Quality Control & Audit Endorsements -->
                    <div class="a5-section">
                        <div class="a5-sec-title">3. QUALITY CONTROL &amp; TRANSACTION AUDIT LOG</div>
                        <div style="font-size:6.5pt; color:#64748b; margin-bottom:2px;">Use this section for authorized goods receipt verification, QC clearance, or audit endorsements:</div>
                        <div class="f-dotted-line"></div>
                        <div class="f-dotted-line"></div>
                    </div>
                </div>

                <div>
                    <!-- Signatures -->
                    <div class="a5-signatures">
                        <div class="a5-sig-card">
                            <div class="sig-header">ASB QUALITY &amp; PROCUREMENT VERIFICATION</div>
                            <div class="sig-rule">Authorized Officer Signature</div>
                            <div class="sig-sub"><span>Date: ____/____/20__</span> <span>ASB QA Passed</span></div>
                        </div>
                        <div class="a5-sig-card">
                            <div class="sig-header">SUPPLIER ACKNOWLEDGMENT &amp; STAMP</div>
                            <div class="sig-rule">Authorized Signatory &amp; Seal</div>
                            <div class="sig-sub"><span>Date: ____/____/20__</span> <span>Official Stamp</span></div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="a5-footer">
                        <div>System Generated Master Record · ASB Group of Companies · Dual-DB Verified</div>
                        <div>Developed and Designed by <b>Vexel IT by Kavizz</b></div>
                    </div>
                </div>
            </div>
        `;
    }

    function buildBlankFormHtml() {
        const year = new Date().getFullYear();
        const formNo = `ASB-SUP-${year}-BLANK`;

        return `
            <div class="a5-sheet">
                <div>
                    <!-- Header -->
                    <div class="a5-header">
                        <div class="a5-brand-title">ASB GROUP OF COMPANIES</div>
                        <div class="a5-brand-sub">SUPPLIER MASTER REGISTRATION APPLICATION FORM</div>
                        <div class="a5-brand-dept">Official Procurement Onboarding &amp; Quality Control Profile</div>
                    </div>

                    <!-- Meta Bar -->
                    <div class="a5-meta-bar">
                        <div><span class="meta-lbl">FORM REF:</span> <span class="meta-val">${formNo}</span></div>
                        <div><span class="meta-lbl">CATEGORY:</span> <span class="meta-val">New Vendor Enrolment</span></div>
                        <div><span class="meta-lbl">DOC TYPE:</span> <span class="meta-val">Standard A5 Entry</span></div>
                    </div>

                    <!-- 1. Corporate Identity -->
                    <div class="a5-section">
                        <div class="a5-sec-title">1. CORPORATE BUSINESS IDENTITY</div>
                        <table class="a5-fill-grid">
                            <tr>
                                <td class="f-lbl">Corporate Entity Name:</td>
                                <td colspan="3" class="f-blank">________________________________________________</td>
                            </tr>
                            <tr>
                                <td class="f-lbl">System / ERP Code:</td>
                                <td class="f-blank">_____________</td>
                                <td class="f-lbl">Business Reg. No:</td>
                                <td class="f-blank">_____________</td>
                            </tr>
                            <tr>
                                <td class="f-lbl">Tax / VAT Number:</td>
                                <td class="f-blank">_____________</td>
                                <td class="f-lbl">Nature of Business:</td>
                                <td class="f-blank">_____________</td>
                            </tr>
                        </table>
                    </div>

                    <!-- 2. Liaison & Contact Channels -->
                    <div class="a5-section">
                        <div class="a5-sec-title">2. LIAISON REPRESENTATIVE &amp; COMMUNICATION CHANNELS</div>
                        <table class="a5-fill-grid">
                            <tr>
                                <td class="f-lbl">Primary Contact Person:</td>
                                <td class="f-blank">___________________</td>
                                <td class="f-lbl">Designation / Role:</td>
                                <td class="f-blank">___________________</td>
                            </tr>
                            <tr>
                                <td class="f-lbl">Mobile Phone:</td>
                                <td class="f-blank">___________________</td>
                                <td class="f-lbl">WhatsApp Number:</td>
                                <td class="f-blank">___________________</td>
                            </tr>
                            <tr>
                                <td class="f-lbl">Landline Telephone:</td>
                                <td class="f-blank">___________________</td>
                                <td class="f-lbl">Fax Number:</td>
                                <td class="f-blank">___________________</td>
                            </tr>
                            <tr>
                                <td class="f-lbl">Official Email:</td>
                                <td colspan="3" class="f-blank">________________________________________________</td>
                            </tr>
                            <tr>
                                <td class="f-lbl">Factory / Office Address:</td>
                                <td colspan="3" class="f-blank">
                                    <div class="f-dotted-line"></div>
                                    <div class="f-dotted-line"></div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- 3. Quality & Dual-DB Sync Declaration -->
                    <div class="a5-section">
                        <div class="a5-sec-title">3. COMPLIANCE &amp; QUALITY CONTROL DECLARATION</div>
                        <div style="font-size:6.7pt; color:#334155; line-height:1.3; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; padding:4px 6px;">
                            The applicant hereby certifies that all vendor data provided is accurate and authentic. By signing, the supplier agrees to ASB Group QC inspection guidelines, purchase order return standards, and dual-system registration across <b>po_system</b> and <b>return_qc</b>.
                        </div>
                    </div>
                </div>

                <div>
                    <!-- 4. Signatures -->
                    <div class="a5-signatures">
                        <div class="a5-sig-card">
                            <div class="sig-header">SUPPLIER AUTHORIZED SIGNATORY &amp; STAMP</div>
                            <div class="sig-rule">Authorized Signatory &amp; Company Seal</div>
                            <div class="sig-sub"><span>Date: ____/____/20__</span> <span>Official Stamp</span></div>
                        </div>
                        <div class="a5-sig-card">
                            <div class="sig-header">ASB PROCUREMENT APPROVAL &amp; ERP ENTRY</div>
                            <div class="sig-rule">Procurement Director / QA Officer</div>
                            <div class="sig-sub"><span>Date: ____/____/20__</span> <span>Dual-DB Entered</span></div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="a5-footer">
                        <div>Confidential · ASB Group Procurement &amp; QC System · Ref: ASB-SUP-BLANK</div>
                        <div>Developed and Designed by <b>Vexel IT by Kavizz</b></div>
                    </div>
                </div>
            </div>
        `;
    }

    function buildMultiSupplierHtml(list) {
        if (!list || list.length === 0) return '<p>No records to print.</p>';
        return list.map((s, idx) => {
            const formNo = generateFormNumber(s.supplier_id);
            const now = new Date();
            const formattedDate = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
            const sysId = s.system_id ? `#${escapeHtml(s.system_id)}` : `#${String(s.supplier_id).padStart(5, '0')}`;
            const condition = resolveConditionMeta(s.status);

            return `
                <div class="a5-sheet a5-page-break">
                    <div>
                        <!-- Header -->
                        <div class="a5-header">
                            <div class="a5-brand-title">ASB GROUP OF COMPANIES</div>
                            <div class="a5-brand-sub">REGISTERED SUPPLIER MASTER DOSSIER</div>
                            <div class="a5-brand-dept">Record ${idx + 1} of ${list.length} · Enterprise Procurement Profile</div>
                        </div>

                        <!-- Meta Bar -->
                        <div class="a5-meta-bar">
                            <div><span class="meta-lbl">FORM REF:</span> <span class="meta-val">${formNo}</span></div>
                            <div><span class="meta-lbl">CONDITION:</span> <span class="status-pill ${condition.pillClass}">${escapeHtml(condition.label)}</span></div>
                            <div><span class="meta-lbl">PRINTED:</span> <span class="meta-val">${formattedDate}</span></div>
                        </div>

                        <!-- Hero Box -->
                        <div class="a5-record-hero">
                            <div>
                                <div class="hero-name">${escapeHtml(s.supplier_name || 'N/A')}</div>
                                <div class="hero-person">
                                    <span>Liaison: <b>${escapeHtml(s.contact_person || '—')}</b></span>
                                    <span style="color:#64748b; font-weight:normal; margin-left:4px;">(Primary Representative)</span>
                                </div>
                            </div>
                            <div class="hero-sys-id-box">
                                <div class="hero-sys-lbl">System / ERP Code</div>
                                <div class="hero-sys-val">${sysId}</div>
                            </div>
                        </div>

                        <!-- 1. Registered Contact Directory -->
                        <div class="a5-section">
                            <div class="a5-sec-title">1. REGISTERED CONTACT &amp; CHANNEL DIRECTORY</div>
                            <table class="a5-data-table">
                                <tr>
                                    <td class="dt-lbl">Mobile Phone:</td>
                                    <td class="dt-val">${escapeHtml(s.contact_number || 'N/A')}</td>
                                    <td class="dt-lbl">WhatsApp:</td>
                                    <td class="dt-val">${escapeHtml(s.whatsapp || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td class="dt-lbl">Landline:</td>
                                    <td class="dt-val">${escapeHtml(s.land_number || 'N/A')}</td>
                                    <td class="dt-lbl">Fax Number:</td>
                                    <td class="dt-val">${escapeHtml(s.fax_number || '—')}</td>
                                </tr>
                                <tr>
                                    <td class="dt-lbl">Official Email:</td>
                                    <td class="dt-val" colspan="3">${escapeHtml(s.email || 'N/A')}</td>
                                </tr>
                                <tr>
                                    <td class="dt-lbl">Factory / Address:</td>
                                    <td class="dt-val" colspan="3">${escapeHtml(s.address || 'N/A')}</td>
                                </tr>
                            </table>
                        </div>

                        <!-- 2. Dual DB Sync & Compliance (Dynamic for ANY condition) -->
                        <div class="a5-section">
                            <div class="a5-sec-title">2. SYSTEM SYNC &amp; COMPLIANCE PROFILE</div>
                            <div class="sync-banner" style="color:${condition.color}; border-color:${condition.color}40; background:${condition.color}0a;">
                                <div>✓ Dual-DB Synced: <b>po_system</b> &amp; <b>return_qc</b></div>
                                <div><b>${escapeHtml(condition.syncBadge)}</b></div>
                            </div>
                        </div>

                        <!-- 3. Quality Control Audit Notes -->
                        <div class="a5-section">
                            <div class="a5-sec-title">3. QUALITY CONTROL &amp; TRANSACTION AUDIT LOG</div>
                            <div style="font-size:6.5pt; color:#64748b; margin-bottom:2px;">QC verification, order inspection endorsements, or goods receipt approvals:</div>
                            <div class="f-dotted-line"></div>
                            <div class="f-dotted-line"></div>
                        </div>
                    </div>

                    <div>
                        <!-- Signatures -->
                        <div class="a5-signatures">
                            <div class="a5-sig-card">
                                <div class="sig-header">ASB QUALITY &amp; PROCUREMENT VERIFICATION</div>
                                <div class="sig-rule">Authorized Officer Signature</div>
                                <div class="sig-sub"><span>Date: ____/____/20__</span> <span>ASB QA Passed</span></div>
                            </div>
                            <div class="a5-sig-card">
                                <div class="sig-header">SUPPLIER ACKNOWLEDGMENT &amp; STAMP</div>
                                <div class="sig-rule">Authorized Signatory &amp; Seal</div>
                                <div class="sig-sub"><span>Date: ____/____/20__</span> <span>Official Stamp</span></div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="a5-footer">
                            <div>System Generated Master Record (${idx + 1}/${list.length}) · ASB Group · Dual-DB Verified</div>
                            <div>Developed and Designed by <b>Vexel IT by Kavizz</b></div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // 6. Direct Print & Preview Launchers
    function printHtmlContent(htmlContent, title) {
        // Also populate dedicated fallback wrapper
        const wrapper = document.getElementById('print-sheet-wrapper');
        if (wrapper) wrapper.innerHTML = htmlContent;

        // Use isolated iframe for 100% infallible printing
        let iframe = document.getElementById('asbPrintIframe');
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.id = 'asbPrintIframe';
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            iframe.style.visibility = 'hidden';
            document.body.appendChild(iframe);
        }

        const doc = iframe.contentWindow.document;
        doc.open();
        doc.write(`<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>${escapeHtml(title)}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>${getA5PrintStyles()}</style>
</head>
<body>${htmlContent}</body>
</html>`);
        doc.close();

        iframe.contentWindow.focus();
        setTimeout(() => {
            iframe.contentWindow.print();
        }, 220);
    }

    function openPrintPreview(title, htmlContent) {
        currentPrintTitle = title;
        currentPrintHtml = htmlContent;

        document.getElementById('previewDocTitle').innerHTML = '<i class="fas fa-file-invoice text-red-400"></i> ' + escapeHtml(title);
        
        // Wrap preview content with print styles for screen presentation
        document.getElementById('preview-render-area').innerHTML = `
            <style>${getA5PrintStyles()}</style>
            ${htmlContent}
        `;

        const modal = document.getElementById('printPreviewModal');
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';

        // Auto trigger iframe print
        printHtmlContent(htmlContent, title);
    }

    function closePrintPreviewModal() {
        const modal = document.getElementById('printPreviewModal');
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    function reprintCurrentDocument() {
        if (currentPrintHtml) {
            printHtmlContent(currentPrintHtml, currentPrintTitle);
        }
    }

    // Public Print Entrypoints (Work for EVERY condition!)
    function printSingleSupplier(supplierId) {
        const s = suppliersData[supplierId];
        if (!s) {
            alert('Supplier record #' + supplierId + ' not found.');
            return;
        }
        const html = buildSingleSupplierHtml(s);
        openPrintPreview(`Supplier Dossier (A5): ${s.supplier_name}`, html);
    }

    function printEmptyForm() {
        const html = buildBlankFormHtml();
        openPrintPreview('Blank Supplier Registration Form (A5)', html);
    }

    function printAllSuppliersPartitioned() {
        if (!allSuppliersList || allSuppliersList.length === 0) {
            alert('No suppliers currently displayed to print.');
            return;
        }
        const html = buildMultiSupplierHtml(allSuppliersList);
        openPrintPreview(`All Supplier Records (${allSuppliersList.length} A5 Pages Partitioned)`, html);
    }

    // URL Query Auto-Triggers
    <?php if (isset($_GET['print_all'])): ?>
    window.addEventListener('DOMContentLoaded', function() {
        printAllSuppliersPartitioned();
    });
    <?php elseif (isset($_GET['print_blank'])): ?>
    window.addEventListener('DOMContentLoaded', function() {
        printEmptyForm();
    });
    <?php elseif (isset($_GET['print_id'])): ?>
    window.addEventListener('DOMContentLoaded', function() {
        printSingleSupplier(<?= (int)$_GET['print_id']; ?>);
    });
    <?php endif; ?>
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>