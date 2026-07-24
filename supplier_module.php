<?php
// 1. DATABASE CONFIGURATION
$host = '127.0.0.1';
$db   = 'return_qc';
$user = 'root'; 
$pass = '';     
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 2. BACKEND ROUTER OPERATIONS (CRUD)
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'create') {
        $countQuery = $pdo->query("SELECT COUNT(*) as total FROM suppliers")->fetch();
        $nextIndex = sprintf("%02d", $countQuery['total'] + 1);
        $generatedStatus = "New " . $nextIndex;

        $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_name, system_id, contact_number, land_number, fax_number, email, address, status, contact_person, whatsapp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['supplier_name'], 
            $_POST['system_id'] ?? '',
            $_POST['contact_number'],
            $_POST['land_number'],
            $_POST['fax_number'] ?? '',
            $_POST['email'],
            $_POST['address'],
            $generatedStatus,
            $_POST['contact_person'],
            $_POST['whatsapp']
        ]);
        header("Location: supplier_module.php?msg=Supplier Added");
        exit;
    }
    
    if ($action === 'update') {
        $stmt = $pdo->prepare("UPDATE suppliers SET supplier_name=?, system_id=?, contact_number=?, land_number=?, fax_number=?, email=?, address=?, status=?, contact_person=?, whatsapp=? WHERE supplier_id=?");
        $stmt->execute([
            $_POST['supplier_name'],
            $_POST['system_id'] ?? '',
            $_POST['contact_number'],
            $_POST['land_number'],
            $_POST['fax_number'] ?? '',
            $_POST['email'],
            $_POST['address'],
            $_POST['status'],
            $_POST['contact_person'],
            $_POST['whatsapp'],
            $_POST['supplier_id']
        ]);
        header("Location: supplier_module.php?msg=Supplier Updated");
        exit;
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
    $stmt->execute([$_GET['id']]);
    header("Location: supplier_module.php?msg=Record Removed");
    exit;
}

// 3. SEARCH & PAGINATION ENGINE
$limit = 25; 
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Search term
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause for search across multiple columns
$whereClause = '';
$params = [];
if (!empty($search)) {
    $searchTerm = '%' . $search . '%';
    // Search in relevant columns – includes contact_person and whatsapp
    $whereClause = "WHERE (supplier_name LIKE ? OR contact_person LIKE ? OR contact_number LIKE ? OR whatsapp LIKE ? OR land_number LIKE ? OR email LIKE ? OR status LIKE ? OR system_id LIKE ?)";
    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

// Count total records with search filter
$countSql = "SELECT COUNT(*) FROM suppliers $whereClause";
$stmtCount = $pdo->prepare($countSql);
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Fetch data with search and pagination
$sql = "SELECT * FROM suppliers $whereClause ORDER BY supplier_id DESC LIMIT ? OFFSET ?";
$stmt = $pdo->prepare($sql);
// Merge search params with limit and offset
$execParams = array_merge($params, [$limit, $offset]);
$stmt->execute($execParams);
$suppliers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASB Group · Supplier Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800;14..32,900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #fef2f2; }
        .card { background: white; border-radius: 1.5rem; box-shadow: 0 8px 30px rgba(0,0,0,0.04), 0 2px 8px rgba(0,0,0,0.02); border: 1px solid #fecaca; }
        .btn-primary { background: #b91c1c; color: white; transition: all 0.15s; }
        .btn-primary:hover { background: #7f1d1d; transform: translateY(-1px); box-shadow: 0 8px 16px -6px rgba(185,28,28,0.3); }
        .btn-outline { background: transparent; border: 1px solid #b91c1c; color: #b91c1c; transition: all 0.15s; }
        .btn-outline:hover { background: #fef2f2; border-color: #7f1d1d; color: #7f1d1d; }
        .badge-status { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 600; padding: 0.2rem 0.7rem; border-radius: 9999px; font-size: 0.7rem; letter-spacing: 0.02em; }
        .table-row-hover:hover { background: #fef2f2; transition: 0.1s; }
        .pagination-btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.4rem 0.9rem; border-radius: 0.5rem; border: 1px solid #fecaca; background: white; font-weight: 500; font-size: 0.8rem; color: #1e293b; transition: 0.1s; }
        .pagination-btn:hover { background: #fef2f2; border-color: #b91c1c; }
        .pagination-btn.active { background: #b91c1c; border-color: #b91c1c; color: white; }

        /* MODAL – refined */
        .modal-overlay { background: rgba(185, 28, 28, 0.3); backdrop-filter: blur(6px); }
        .modal-panel {
            transform: scale(0.95) translateY(15px);
            opacity: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .modal-panel.show {
            transform: scale(1) translateY(0);
            opacity: 1;
        }
        .modal-panel .modal-body {
            overflow-y: auto;
            flex: 1 1 auto;
            padding: 1.5rem 1.5rem 0.5rem 1.5rem;
        }
        .modal-panel .modal-footer {
            padding: 1rem 1.5rem 1.5rem 1.5rem;
            border-top: 1px solid #fecaca;
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .input-group-icon { position: relative; }
        .input-group-icon .icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #b91c1c; font-size: 0.9rem; pointer-events: none; }
        .input-group-icon input, .input-group-icon textarea { padding-left: 2.6rem; }
        .input-group-icon textarea .icon { top: 0.9rem; transform: none; }
        .form-input { border: 1px solid #fecaca; border-radius: 0.75rem; padding: 0.7rem 1rem; width: 100%; font-size: 0.9rem; transition: 0.15s; background: #fafbfc; }
        .form-input:focus { border-color: #b91c1c; background: white; box-shadow: 0 0 0 3px rgba(185,28,28,0.1); outline: none; }
        .form-label { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #b91c1c; margin-bottom: 0.25rem; }
        .form-label .required { color: #b91c1c; margin-left: 2px; }

        .btn-save {
            background: linear-gradient(135deg, #b91c1c, #7f1d1d);
            color: white;
            border: none;
            padding: 0.65rem 2rem;
            border-radius: 0.75rem;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 12px rgba(185,28,28,0.3);
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(185,28,28,0.4);
            background: linear-gradient(135deg, #991b1b, #6b1d1d);
        }
        .btn-cancel {
            background: transparent;
            border: 1.5px solid #b91c1c;
            color: #b91c1c;
            padding: 0.65rem 2rem;
            border-radius: 0.75rem;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-cancel:hover {
            background: #fef2f2;
            border-color: #7f1d1d;
            color: #7f1d1d;
            transform: translateY(-2px);
        }

        /* Search bar styling */
        .search-wrapper {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-wrapper input {
            flex: 1;
            min-width: 200px;
        }
        .search-wrapper .btn-clear {
            background: transparent;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 0.45rem 1rem;
            border-radius: 0.75rem;
            font-weight: 600;
            transition: 0.15s;
        }
        .search-wrapper .btn-clear:hover {
            background: #fef2f2;
            border-color: #b91c1c;
        }

        /* PRINT STYLES */
        @media print {
            html, body { background: #fff !important; margin: 0; padding: 0; }
            body > *:not(#print-container) { display: none !important; }
            #print-container { display: block !important; width: 148mm; margin: 0 auto; }
            .a5-sheet { 
                width: 148mm; 
                height: 210mm; 
                padding: 0; 
                box-sizing: border-box; 
                background: #ffffff; 
                page-break-after: avoid;
                display: flex;
                flex-direction: column;
                border: 2px solid #b91c1c;
            }
            .print-header {
                background: #b91c1c;
                color: white;
                padding: 10mm 8mm 6mm 8mm;
                text-align: center;
                border-bottom: 4px solid #7f1d1d;
                flex-shrink: 0;
            }
            .print-header h1 {
                font-family: 'Inter', Arial, sans-serif;
                font-size: 22px;
                font-weight: 900;
                letter-spacing: 1px;
                margin: 0;
                text-transform: uppercase;
            }
            .print-header p {
                font-family: 'Inter', Arial, sans-serif;
                font-size: 12px;
                font-weight: 600;
                letter-spacing: 2px;
                margin: 4px 0 0 0;
                opacity: 0.9;
            }
            .print-body {
                padding: 6mm 8mm 4mm 8mm;
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            .print-meta {
                display: flex;
                justify-content: space-between;
                font-size: 11px;
                font-weight: 600;
                font-family: 'Inter', Arial, sans-serif;
                background: #fef2f2;
                padding: 4px 10px;
                border-radius: 4px;
                border-left: 4px solid #b91c1c;
                margin-bottom: 4mm;
                flex-shrink: 0;
            }
            .print-meta span { color: #1e293b; }
            .print-meta .label { color: #64748b; font-weight: 500; }
            .print-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11.5px;
                font-family: 'Inter', Arial, sans-serif;
                margin-bottom: 4mm;
                flex-shrink: 0;
            }
            .print-table td {
                padding: 4px 0;
                border-bottom: none;
            }
            .print-table .field-label {
                font-weight: 700;
                width: 35%;
                color: #1e293b;
                letter-spacing: 0.3px;
                vertical-align: top;
                padding-top: 6px;
            }
            .print-table .field-value {
                padding-left: 8px;
                vertical-align: top;
                padding-top: 2px;
            }
            .dotted-line {
                border-bottom: 1px dotted #b91c1c;
                height: 18px;
                margin-bottom: 4px;
                line-height: 18px;
                font-weight: 500;
                color: #0f172a;
                padding-left: 2px;
            }
            .dotted-line:last-child { margin-bottom: 0; }
            .print-handwritten {
                border: 2px dashed #b91c1c;
                border-radius: 6px;
                padding: 8px 12px;
                background: #fef2f2;
                margin-bottom: 4mm;
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
            }
            .print-handwritten p {
                margin: 0 0 4px 0;
                font-size: 9px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #b91c1c;
            }
            .print-handwritten .dotted-line {
                border-bottom: 1px dotted #b91c1c;
                height: 20px;
                margin-bottom: 4px;
            }
            .print-handwritten .dotted-line:last-child { margin-bottom: 0; }
            .print-signatures {
                display: flex;
                justify-content: space-between;
                font-size: 11px;
                font-family: 'Inter', Arial, sans-serif;
                margin-top: auto;
                padding-top: 4mm;
                border-top: 2px solid #b91c1c;
                flex-shrink: 0;
            }
            .print-signatures .sig-box {
                width: 45%;
                text-align: center;
            }
            .print-signatures .sig-box .title {
                font-weight: 700;
                color: #1e293b;
            }
            .print-signatures .sig-box .sub {
                font-weight: 800;
                font-size: 10px;
                text-transform: uppercase;
                color: #b91c1c;
                display: block;
                margin-top: 2px;
            }
            .print-footer {
                font-size: 8px;
                color: #64748b;
                text-align: center;
                border-top: 1px solid #b91c1c;
                padding-top: 2mm;
                margin-top: 2mm;
                letter-spacing: 0.5px;
                flex-shrink: 0;
                font-weight: 500;
            }
            .print-footer .credit {
                color: #b91c1c;
                font-weight: 700;
            }
            @page { size: A5 portrait; margin: 0; }
        }
    </style>
</head>
<body>

    <!-- HEADER – Red theme -->
    <header class="bg-gradient-to-r from-red-700 to-red-800 border-b border-red-900/40 sticky top-0 z-40 no-print shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between py-3 gap-3">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl bg-white text-red-700 flex items-center justify-center font-black text-lg shadow-sm">ASB</div>
                    <div>
                        <h1 class="text-lg font-extrabold tracking-tight text-white leading-none">Supplier Control</h1>
                        <p class="text-[11px] font-medium text-red-100">Enterprise Quality · Return QC</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="openModal('add')" class="btn-primary text-sm font-semibold px-4 py-2 rounded-xl flex items-center gap-2 bg-white text-red-700 hover:bg-red-50 border border-red-200">
                        <i class="fas fa-plus-circle"></i> Register
                    </button>
                    <button onclick="printEmptyForm()" class="btn-outline text-sm font-semibold px-4 py-2 rounded-xl flex items-center gap-2 text-white border-white/40 hover:bg-white/10">
                        <i class="fas fa-print"></i> Blank Form
                    </button>
                    <!-- *** NEW DASHBOARD BUTTON *** -->
                    <a href="dashboard.php" class="btn-outline text-sm font-semibold px-4 py-2 rounded-xl flex items-center gap-2 text-white border-white/40 hover:bg-white/10 transition">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- MAIN (screen) -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 no-print">
        <!-- STATS -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
            <div class="card p-5 flex items-center gap-4 border-l-4 border-l-red-600">
                <div class="h-12 w-12 rounded-xl bg-red-50 text-red-700 flex items-center justify-center text-xl"><i class="fas fa-building"></i></div>
                <div><p class="text-xs font-semibold text-red-600 uppercase tracking-wider">Total Suppliers</p><p class="text-2xl font-black text-slate-800"><?= number_format($totalRecords); ?></p></div>
            </div>
            <div class="card p-5 flex items-center gap-4 border-l-4 border-l-red-600">
                <div class="h-12 w-12 rounded-xl bg-red-50 text-red-700 flex items-center justify-center text-xl"><i class="fas fa-database"></i></div>
                <div><p class="text-xs font-semibold text-red-600 uppercase tracking-wider">Engine</p><p class="text-2xl font-black text-slate-800">MySQL · PDO</p></div>
            </div>
            <div class="card p-5 flex items-center gap-4 border-l-4 border-l-red-600">
                <div class="h-12 w-12 rounded-xl bg-red-50 text-red-700 flex items-center justify-center text-xl"><i class="fas fa-layer-group"></i></div>
                <div><p class="text-xs font-semibold text-red-600 uppercase tracking-wider">Page</p><p class="text-2xl font-black text-slate-800"><?= $page; ?> / <?= max(1, $totalPages); ?></p></div>
            </div>
            <div class="card p-5 flex items-center gap-4 border-l-4 border-l-red-600 bg-gradient-to-br from-red-50 to-white">
                <div class="h-12 w-12 rounded-xl bg-red-600 text-white flex items-center justify-center text-xl"><i class="fas fa-rocket"></i></div>
                <div><p class="text-xs font-semibold text-red-600 uppercase tracking-wider">Capacity</p><p class="text-2xl font-black text-red-700">10k+</p></div>
            </div>
        </div>

        <!-- MESSAGE -->
        <?php if(isset($_GET['msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-800 p-4 rounded-xl font-medium text-sm shadow-sm flex justify-between items-center mb-6">
                <span><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($_GET['msg']); ?></span>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
        <?php endif; ?>

        <!-- SEARCH BAR & TABLE CARD -->
        <div class="card overflow-hidden border-red-200">
            <!-- Search Bar -->
            <div class="p-4 border-b border-red-200/70 bg-red-50/30">
                <form method="GET" action="" class="search-wrapper">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-red-400"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Search by name, contact person, phone, email, status..." class="form-input pl-10" id="searchInput">
                    </div>
                    <button type="submit" class="btn-primary text-sm font-semibold px-4 py-2 rounded-xl flex items-center gap-2 bg-red-600 text-white hover:bg-red-700">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if (!empty($search)): ?>
                        <a href="supplier_module.php" class="btn-clear text-sm font-semibold px-4 py-2 rounded-xl flex items-center gap-2">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-red-50/80 border-b border-red-200/70 text-red-700 text-[11px] font-bold uppercase tracking-wider">
                            <th class="p-4 w-20 text-center">ID</th>
                            <th class="p-4">Supplier</th>
                            <th class="p-4">Contact Person</th>
                            <th class="p-4">Contact Details</th>
                            <th class="p-4">Status</th>
                            <th class="p-4 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-red-100 text-sm">
                        <?php if (empty($suppliers)): ?>
                            <tr><td colspan="6" class="p-12 text-center text-slate-400 font-medium">No suppliers found <?= !empty($search) ? 'matching your search' : 'in the database.'; ?></td></tr>
                        <?php else: ?>
                            <?php foreach($suppliers as $s): ?>
                            <tr class="table-row-hover">
                                <td class="p-4 text-center font-mono text-xs font-bold text-red-600">#<?= sprintf("%05d", $s['supplier_id']); ?></td>
                                <td class="p-4">
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($s['supplier_name']); ?></div>
                                    <div class="text-xs text-slate-400 truncate max-w-[200px]"><?= htmlspecialchars($s['address'] ?: 'No address'); ?></div>
                                </td>
                                <td class="p-4 font-semibold text-slate-700"><?= htmlspecialchars($s['contact_person'] ?: '—'); ?></td>
                                <td class="p-4 text-xs space-y-0.5 text-slate-600">
                                    <div><i class="fas fa-phone-alt w-4 text-red-400 text-[10px]"></i> <?= htmlspecialchars($s['contact_number']); ?></div>
                                    <?php if($s['whatsapp']): ?><div><i class="fab fa-whatsapp w-4 text-green-500 text-[10px]"></i> <?= htmlspecialchars($s['whatsapp']); ?></div><?php endif; ?>
                                    <?php if($s['email']): ?><div><i class="fas fa-envelope w-4 text-red-400 text-[10px]"></i> <?= htmlspecialchars($s['email']); ?></div><?php endif; ?>
                                    <?php if($s['land_number']): ?><div><i class="fas fa-phone w-4 text-red-400 text-[10px]"></i> <?= htmlspecialchars($s['land_number']); ?></div><?php endif; ?>
                                </td>
                                <td class="p-4"><span class="badge-status"><?= htmlspecialchars($s['status']); ?></span></td>
                                <td class="p-4 text-center space-x-1 whitespace-nowrap">
                                    <button onclick='populateAndEdit(<?= json_encode($s); ?>)' class="btn-outline text-xs px-3 py-1.5 rounded-lg font-semibold flex items-center gap-1 inline-flex"><i class="fas fa-pen"></i> Edit</button>
                                    <button onclick='printSingleSupplier(<?= json_encode($s); ?>)' class="btn-primary text-xs px-3 py-1.5 rounded-lg font-semibold flex items-center gap-1 inline-flex"><i class="fas fa-print"></i> Print</button>
                                    <a href="supplier_module.php?action=delete&id=<?= $s['supplier_id']; ?>" onclick="return confirm('Delete this supplier permanently?')" class="text-red-600 hover:text-red-800 text-xs font-semibold px-2 py-1.5 rounded-lg border border-red-200 hover:bg-red-50 inline-flex items-center gap-1"><i class="fas fa-trash-alt"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION (retains search) -->
            <?php if($totalPages > 1): ?>
            <div class="bg-red-50/80 px-4 py-3 border-t border-red-200/70 flex flex-wrap items-center justify-between text-xs text-slate-500 font-medium">
                <span>Showing <?= $offset + 1; ?> – <?= min($totalRecords, $offset + $limit); ?> of <?= $totalRecords; ?></span>
                <div class="flex items-center gap-1">
                    <?php 
                        $searchParam = !empty($search) ? '&search=' . urlencode($search) : '';
                    ?>
                    <?php if($page > 1): ?>
                        <a href="?page=1<?= $searchParam; ?>" class="pagination-btn"><i class="fas fa-angle-double-left"></i></a>
                        <a href="?page=<?= $page - 1; ?><?= $searchParam; ?>" class="pagination-btn"><i class="fas fa-angle-left"></i></a>
                    <?php endif; ?>
                    <span class="pagination-btn active"><?= $page; ?></span>
                    <?php if($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1; ?><?= $searchParam; ?>" class="pagination-btn"><i class="fas fa-angle-right"></i></a>
                        <a href="?page=<?= $totalPages; ?><?= $searchParam; ?>" class="pagination-btn"><i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- SCREEN FOOTER -->
    <footer class="bg-white border-t border-red-200 no-print mt-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-col sm:flex-row justify-between items-center text-xs text-slate-500">
            <span class="text-red-600 font-semibold">ASB Group of Companies</span>
            <span>Developed and Designed By <strong class="text-red-700">Vexel IT by Kavizz</strong></span>
        </div>
    </footer>

    <!-- MODAL – unchanged -->
    <div id="supplierModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay">
        <div id="modalPanel" class="modal-panel bg-white rounded-2xl shadow-2xl w-full max-w-lg border border-red-200/80 overflow-hidden">
            <div class="bg-gradient-to-r from-red-700 to-red-800 px-6 py-4 flex justify-between items-center flex-shrink-0">
                <h3 id="modalTitle" class="text-white font-bold text-base tracking-wider flex items-center gap-2">
                    <i class="fas fa-pen-alt"></i>Supplier Entry
                </h3>
                <button onclick="closeModal()" class="text-red-200 hover:text-white text-2xl leading-none transition">&times;</button>
            </div>
            <div class="modal-body">
                <form id="supplierForm" method="POST" class="space-y-4">
                    <input type="hidden" id="supplier_id" name="supplier_id">
                    <input type="hidden" id="status" name="status">
                    <input type="hidden" id="system_id" name="system_id" value="">
                    <input type="hidden" id="fax_number" name="fax_number" value="">

                    <div>
                        <label class="form-label"><i class="fas fa-building mr-1"></i> Corporate Name <span class="required">*</span></label>
                        <div class="input-group-icon">
                            <i class="fas fa-building icon"></i>
                            <input type="text" id="supplier_name" name="supplier_name" required class="form-input" placeholder="e.g. Apex Fabrics Ltd">
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><i class="fas fa-user mr-1"></i> Contact Person <span class="required">*</span></label>
                        <div class="input-group-icon">
                            <i class="fas fa-user icon"></i>
                            <input type="text" id="contact_person" name="contact_person" required class="form-input" placeholder="e.g. John Doe">
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><i class="fas fa-phone-alt mr-1"></i> Mobile Number <span class="required">*</span></label>
                        <div class="input-group-icon">
                            <i class="fas fa-mobile-alt icon"></i>
                            <input type="text" id="contact_number" name="contact_number" required class="form-input" placeholder="947XXXXXXXX">
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><i class="fab fa-whatsapp mr-1"></i> WhatsApp Number</label>
                        <div class="input-group-icon">
                            <i class="fab fa-whatsapp icon"></i>
                            <input type="text" id="whatsapp" name="whatsapp" class="form-input" placeholder="947XXXXXXXX">
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><i class="fas fa-phone mr-1"></i> Landline</label>
                        <div class="input-group-icon">
                            <i class="fas fa-phone icon"></i>
                            <input type="text" id="land_number" name="land_number" class="form-input" placeholder="9411XXXXXX">
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><i class="fas fa-envelope mr-1"></i> Email</label>
                        <div class="input-group-icon">
                            <i class="fas fa-envelope icon"></i>
                            <input type="email" id="email" name="email" class="form-input" placeholder="info@company.com">
                        </div>
                    </div>

                    <div>
                        <label class="form-label"><i class="fas fa-map-pin mr-1"></i> Address</label>
                        <div class="input-group-icon">
                            <i class="fas fa-map-pin icon" style="top: 0.9rem; transform: none;"></i>
                            <textarea id="address" name="address" rows="2" class="form-input" placeholder="Street, City, Country"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal()" class="btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" onclick="document.getElementById('supplierForm').submit();" class="btn-save">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>
    </div>

    <!-- PRINT CONTAINER – unchanged -->
    <div id="print-container" class="hidden">
        <div class="a5-sheet">
            <div class="print-header">
                <h1>ASB GROUP OF COMPANIES</h1>
                <p>Supplier Registration Form · Quality Control</p>
            </div>
            <div class="print-body">
                <div class="print-meta">
                    <span><span class="label">Form No:</span> <span class="p-form-no">ASB-SUP-2026-0001</span></span>
                    <span><span class="label">Status:</span> <span class="p-status">New 01</span></span>
                </div>

                <table class="print-table">
                    <tr>
                        <td class="field-label">Supplier Name</td>
                        <td class="field-value">
                            <div class="dotted-line p-name-line1"></div>
                            <div class="dotted-line p-name-line2"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">Contact Person</td>
                        <td class="field-value">
                            <div class="dotted-line p-person-line1"></div>
                            <div class="dotted-line p-person-line2"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">Mobile</td>
                        <td class="field-value">
                            <div class="dotted-line p-mob-line1"></div>
                            <div class="dotted-line p-mob-line2"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">WhatsApp</td>
                        <td class="field-value">
                            <div class="dotted-line p-whatsapp-line1"></div>
                            <div class="dotted-line p-whatsapp-line2"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">Landline</td>
                        <td class="field-value">
                            <div class="dotted-line p-land-line1"></div>
                            <div class="dotted-line p-land-line2"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">Email</td>
                        <td class="field-value">
                            <div class="dotted-line p-email-line1"></div>
                            <div class="dotted-line p-email-line2"></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="field-label">Address</td>
                        <td class="field-value">
                            <div class="dotted-line p-address-line1"></div>
                            <div class="dotted-line p-address-line2"></div>
                        </td>
                    </tr>
                </table>

                <div class="print-handwritten">
                    <p><i class="fas fa-pen" style="margin-right: 4px;"></i>Handwritten validation logs (to be completed by supplier)</p>
                    <div class="dotted-line"></div>
                    <div class="dotted-line"></div>
                    <div style="flex:1; min-height:8px;"></div>
                </div>

                <div class="print-signatures">
                    <div class="sig-box">
                        <span class="title">Authorized Signature</span>
                        <span class="sub">ASB Group Verification</span>
                    </div>
                    <div class="sig-box">
                        <span class="title">Supplier Acknowledgment</span>
                        <span class="sub">Signature &amp; Company Stamp</span>
                    </div>
                </div>

                <div class="print-footer">
                    <span>Developed and Designed By <span class="credit">Vexel IT by Kavizz</span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        const modal = document.getElementById('supplierModal');
        const panel = document.getElementById('modalPanel');
        const form = document.getElementById('supplierForm');
        const title = document.getElementById('modalTitle');

        function openModal(mode) {
            modal.classList.remove('hidden');
            void panel.offsetHeight;
            panel.classList.add('show');
            if (mode === 'add') {
                title.innerHTML = '<i class="fas fa-plus-circle"></i> Register New Supplier';
                form.action = 'supplier_module.php?action=create';
                form.reset();
                document.getElementById('supplier_id').value = '';
            }
        }

        function closeModal() {
            panel.classList.remove('show');
            setTimeout(() => { modal.classList.add('hidden'); }, 250);
        }

        function populateAndEdit(supplier) {
            openModal('edit');
            title.innerHTML = '<i class="fas fa-edit"></i> Modify Supplier';
            form.action = 'supplier_module.php?action=update';
            document.getElementById('supplier_id').value = supplier.supplier_id;
            document.getElementById('supplier_name').value = supplier.supplier_name;
            document.getElementById('contact_person').value = supplier.contact_person || '';
            document.getElementById('contact_number').value = supplier.contact_number;
            document.getElementById('whatsapp').value = supplier.whatsapp || '';
            document.getElementById('land_number').value = supplier.land_number || '';
            document.getElementById('email').value = supplier.email || '';
            document.getElementById('address').value = supplier.address || '';
            document.getElementById('status').value = supplier.status;
            document.getElementById('system_id').value = supplier.system_id || '';
            document.getElementById('fax_number').value = supplier.fax_number || '';
        }

        function generateFormNumber(supplierId) {
            const year = new Date().getFullYear();
            const padded = String(supplierId).padStart(4, '0');
            return `ASB-SUP-${year}-${padded}`;
        }

        function populatePrintFields(data) {
            document.querySelectorAll('.p-form-no').forEach(el => el.innerText = data.formNo);
            document.querySelectorAll('.p-status').forEach(el => el.innerText = data.status);
            document.querySelectorAll('.p-name-line1').forEach(el => el.innerText = data.name);
            document.querySelectorAll('.p-person-line1').forEach(el => el.innerText = data.person);
            document.querySelectorAll('.p-mob-line1').forEach(el => el.innerText = data.mob);
            document.querySelectorAll('.p-whatsapp-line1').forEach(el => el.innerText = data.whatsapp);
            document.querySelectorAll('.p-land-line1').forEach(el => el.innerText = data.land);
            document.querySelectorAll('.p-email-line1').forEach(el => el.innerText = data.email);
            document.querySelectorAll('.p-address-line1').forEach(el => el.innerText = data.address);
        }

        function printSingleSupplier(supplier) {
            const formNo = generateFormNumber(supplier.supplier_id);
            populatePrintFields({
                formNo: formNo,
                status: supplier.status,
                name: supplier.supplier_name,
                person: supplier.contact_person || '',
                mob: supplier.contact_number,
                whatsapp: supplier.whatsapp || '',
                land: supplier.land_number || '',
                email: supplier.email || '',
                address: supplier.address || ''
            });
            setTimeout(() => window.print(), 100);
        }

        function printEmptyForm() {
            const year = new Date().getFullYear();
            const formNo = `ASB-SUP-${year}-0000`;
            populatePrintFields({
                formNo: formNo,
                status: "MANUAL ENTRY",
                name: "",
                person: "",
                mob: "",
                whatsapp: "",
                land: "",
                email: "",
                address: ""
            });
            setTimeout(() => window.print(), 100);
        }

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });

        // Optional: Auto-submit search on typing (if you want instant search, uncomment below)
        // document.getElementById('searchInput').addEventListener('input', function() {
        //     this.form.submit();
        // });
    </script>
</body>
</html>