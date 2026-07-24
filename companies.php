<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
startSession();
requireLogin();

if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Manage Companies';
$page = 'companies';
include 'includes/header.php';
include 'includes/sidebar.php';

$conn = getConnection();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['company_name']);
        if (empty($name)) {
            $error = 'Company name is required.';
        } else {
            $stmt = $conn->prepare("INSERT INTO companies (company_name) VALUES (?)");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $message = 'Company added successfully.';
            } else {
                $error = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['company_id'];
        $name = trim($_POST['company_name']);
        if (empty($name)) {
            $error = 'Company name is required.';
        } else {
            $stmt = $conn->prepare("UPDATE companies SET company_name = ? WHERE company_id = ?");
            $stmt->bind_param("si", $name, $id);
            if ($stmt->execute()) {
                $message = 'Company updated successfully.';
            } else {
                $error = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['company_id'];
        // Check if any locations exist for this company
        $check = $conn->prepare("SELECT COUNT(*) FROM store_locations WHERE company_id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        $count = $result->fetch_row()[0];
        $check->close();
        if ($count > 0) {
            $error = 'Cannot delete this company because it has ' . $count . ' location(s) assigned.';
        } else {
            $stmt = $conn->prepare("DELETE FROM companies WHERE company_id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $message = 'Company deleted successfully.';
            } else {
                $error = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch all companies
$companies = [];
$result = $conn->query("SELECT company_id, company_name, created_at FROM companies ORDER BY company_name");
while ($row = $result->fetch_assoc()) {
    $companies[] = $row;
}
$result->free();
?>

<style>
    .action-btn { padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; }
    .btn-edit { background: #f39c12; color: white; }
    .btn-edit:hover { background: #d68910; }
    .btn-delete { background: #e74c3c; color: white; }
    .btn-delete:hover { background: #c0392b; }
    .btn-add { background: #27ae60; color: white; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .btn-add:hover { background: #1e8449; }
    .modal-overlay { position: fixed; inset:0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 2000; }
    .modal-overlay.active { display: flex; }
    .modal-panel { background: white; padding: 25px; border-radius: 16px; max-width: 450px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    .modal-panel h3 { margin-bottom: 20px; color: #1e293b; }
    .modal-panel .form-group { margin-bottom: 15px; }
    .modal-panel .form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 4px; }
    .modal-panel .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; }
    .modal-panel .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    .btn-primary { background: #b91c1c; color: white; padding: 8px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
    .btn-primary:hover { background: #7f1d1d; }
    .btn-secondary { background: #e2e8f0; color: #333; padding: 8px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
    .btn-secondary:hover { background: #cbd5e1; }
    .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; }
    .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
    .alert-danger { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    table thead { background: #f8fafc; }
    table th { padding: 12px 15px; text-align: left; font-weight: 600; color: #1e293b; border-bottom: 2px solid #e2e8f0; }
    table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
    table tbody tr:hover { background: #fef2f2; }
    @media (max-width: 768px) { table td, table th { padding: 8px 10px; } }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <div class="page-header">
        <h2><i class="fas fa-building"></i> Companies</h2>
        <button onclick="openAddModal()" class="btn-add"><i class="fas fa-plus"></i> Add Company</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company Name</th>
                        <th>Created At</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($companies)): ?>
                        <tr><td colspan="4" style="padding:30px; text-align:center; color:#94a3b8;">No companies found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($companies as $c): ?>
                            <tr>
                                <td><?php echo $c['company_id']; ?></td>
                                <td><?php echo htmlspecialchars($c['company_name']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($c['created_at'])); ?></td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <button onclick="openEditModal(<?php echo $c['company_id']; ?>, '<?php echo addslashes($c['company_name']); ?>')" class="action-btn btn-edit"><i class="fas fa-edit"></i> Edit</button>
                                    <button onclick="confirmDelete(<?php echo $c['company_id']; ?>, '<?php echo addslashes($c['company_name']); ?>')" class="action-btn btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="companyModal">
    <div class="modal-panel">
        <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Company</h3>
        <form method="POST" id="companyForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="company_id" id="formCompanyId" value="0">
            <div class="form-group">
                <label>Company Name</label>
                <input type="text" name="company_name" id="formCompanyName" required>
            </div>
            <div class="form-actions">
                <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('companyModal');
    function openAddModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Company';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formCompanyId').value = '0';
        document.getElementById('formCompanyName').value = '';
        modal.classList.add('active');
    }
    function openEditModal(id, name) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Company';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formCompanyId').value = id;
        document.getElementById('formCompanyName').value = name;
        modal.classList.add('active');
    }
    function confirmDelete(id, name) {
        if (confirm('Delete company "' + name + '" permanently? This will fail if locations are assigned.')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="company_id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
    function closeModal() {
        modal.classList.remove('active');
    }
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
</script>

<?php include 'includes/footer.php'; ?>