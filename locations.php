<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
startSession();
requireLogin();

if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Manage Store Locations';
$page = 'locations';
include 'includes/header.php';
include 'includes/sidebar.php';

$conn = getConnection();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['location_name']);
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
        if (empty($name) || $companyId <= 0) {
            $error = 'Location name and Company are required.';
        } else {
            $stmt = $conn->prepare("INSERT INTO store_locations (location_name, company_id) VALUES (?, ?)");
            $stmt->bind_param("si", $name, $companyId);
            if ($stmt->execute()) {
                $message = 'Location added successfully.';
            } else {
                $error = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'edit') {
        $id = (int)$_POST['location_id'];
        $name = trim($_POST['location_name']);
        $companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
        if (empty($name) || $companyId <= 0) {
            $error = 'Location name and Company are required.';
        } else {
            $stmt = $conn->prepare("UPDATE store_locations SET location_name = ?, company_id = ? WHERE location_id = ?");
            $stmt->bind_param("sii", $name, $companyId, $id);
            if ($stmt->execute()) {
                $message = 'Location updated successfully.';
            } else {
                $error = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['location_id'];
        $stmt = $conn->prepare("DELETE FROM store_locations WHERE location_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = 'Location deleted successfully.';
        } else {
            $error = 'Error: ' . $stmt->error;
        }
        $stmt->close();
    }
}

// Fetch all locations with company names
$locations = [];
$query = "SELECT l.location_id, l.location_name, l.created_at, c.company_id, c.company_name 
          FROM store_locations l 
          LEFT JOIN companies c ON l.company_id = c.company_id 
          ORDER BY l.location_name";
$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}
$result->free();

// Get all companies for dropdown
$companies = [];
$compResult = $conn->query("SELECT company_id, company_name FROM companies ORDER BY company_name");
while ($row = $compResult->fetch_assoc()) {
    $companies[] = $row;
}
$compResult->free();
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
    .modal-panel .form-group input, .modal-panel .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; }
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
        <h2><i class="fas fa-store"></i> Store Locations</h2>
        <button onclick="openAddModal()" class="btn-add"><i class="fas fa-plus"></i> Add Location</button>
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
                        <th>Location Name</th>
                        <th>Company</th>
                        <th>Created At</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($locations)): ?>
                        <tr><td colspan="5" style="padding:30px; text-align:center; color:#94a3b8;">No locations found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($locations as $loc): ?>
                            <tr>
                                <td><?php echo $loc['location_id']; ?></td>
                                <td><?php echo htmlspecialchars($loc['location_name']); ?></td>
                                <td><?php echo $loc['company_name'] ? htmlspecialchars($loc['company_name']) : '<span style="color:#999;">(none)</span>'; ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($loc['created_at'])); ?></td>
                                <td style="text-align:center; white-space:nowrap;">
                                    <button onclick="openEditModal(<?php echo $loc['location_id']; ?>, '<?php echo addslashes($loc['location_name']); ?>', <?php echo $loc['company_id'] ?? 0; ?>)" class="action-btn btn-edit"><i class="fas fa-edit"></i> Edit</button>
                                    <button onclick="confirmDelete(<?php echo $loc['location_id']; ?>, '<?php echo addslashes($loc['location_name']); ?>')" class="action-btn btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
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
<div class="modal-overlay" id="locationModal">
    <div class="modal-panel">
        <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Location</h3>
        <form method="POST" id="locationForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="location_id" id="formLocationId" value="0">
            <div class="form-group">
                <label>Location Name</label>
                <input type="text" name="location_name" id="formLocationName" required>
            </div>
            <div class="form-group">
                <label>Company</label>
                <select name="company_id" id="formCompanyId" required>
                    <option value="">Select Company</option>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo $comp['company_id']; ?>"><?php echo htmlspecialchars($comp['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" onclick="closeModal()" class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('locationModal');
    function openAddModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Location';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formLocationId').value = '0';
        document.getElementById('formLocationName').value = '';
        document.getElementById('formCompanyId').value = '';
        modal.classList.add('active');
    }
    function openEditModal(id, name, companyId) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Location';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formLocationId').value = id;
        document.getElementById('formLocationName').value = name;
        document.getElementById('formCompanyId').value = companyId || '';
        modal.classList.add('active');
    }
    function confirmDelete(id, name) {
        if (confirm('Delete location "' + name + '" permanently?')) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="location_id" value="' + id + '">';
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