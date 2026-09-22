<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

// Require Admin Privilege
requireRole(['admin']);

$page_title = 'ASB Group · Role & Tab Permissions';
$page = 'role_management';

$conn = getConnection();
$message = '';
$error = '';

// ============================================================
// AJAX Handler: Save Role Permissions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_permissions') {
    header('Content-Type: application/json');
    $roleKey = trim($_POST['role_key'] ?? '');
    $assignedTabs = $_POST['tabs'] ?? [];

    if (empty($roleKey)) {
        echo json_encode(['success' => false, 'message' => 'Invalid role key.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Clear existing permissions for this role
        $delStmt = $conn->prepare("DELETE FROM po_role_tabs WHERE role = ?");
        $delStmt->bind_param("s", $roleKey);
        $delStmt->execute();
        $delStmt->close();

        // Insert new tab permissions
        if (!empty($assignedTabs)) {
            $insStmt = $conn->prepare("INSERT INTO po_role_tabs (role, tab_id) VALUES (?, ?)");
            foreach ($assignedTabs as $tabId) {
                $tId = (int)$tabId;
                $insStmt->bind_param("si", $roleKey, $tId);
                $insStmt->execute();
            }
            $insStmt->close();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Permissions updated successfully!']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX Handler: Create New Role
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_role') {
    header('Content-Type: application/json');
    $roleName = trim($_POST['role_name'] ?? '');
    $roleDesc = trim($_POST['role_description'] ?? '');
    $roleKey  = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $roleName)));

    if (empty($roleName) || empty($roleKey)) {
        echo json_encode(['success' => false, 'message' => 'Role name is required.']);
        exit;
    }

    try {
        $stmt = $conn->prepare("INSERT INTO po_roles (role_key, role_name, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $roleKey, $roleName, $roleDesc);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'New role created successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Role key already exists.']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error creating role: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch all defined roles
$rolesQuery = $conn->query("SELECT * FROM po_roles ORDER BY id ASC");
$roles = $rolesQuery ? $rolesQuery->fetch_all(MYSQLI_ASSOC) : [];

// Fetch all available tabs categorized by section
$tabsQuery = $conn->query("SELECT * FROM po_tabs ORDER BY section ASC, display_order ASC");
$allTabs = $tabsQuery ? $tabsQuery->fetch_all(MYSQLI_ASSOC) : [];

// Fetch current role-to-tab mappings
$mappingQuery = $conn->query("SELECT role, tab_id FROM po_role_tabs");
$roleTabMappings = [];
if ($mappingQuery) {
    while ($row = $mappingQuery->fetch_assoc()) {
        $roleTabMappings[$row['role']][] = (int)$row['tab_id'];
    }
}

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    :root {
        --primary-red: #8B0000;
        --accent-red: #B71C1C;
        --light-red: #FFEBEE;
        --text-dark: #0F172A;
        --text-muted: #64748B;
        --border-color: #E2E8F0;
    }

    .rbac-container { padding: 25px; }
    
    .rbac-card {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.04);
        border: 1px solid var(--border-color);
        margin-bottom: 30px;
        overflow: hidden;
    }

    .rbac-card-header {
        padding: 20px 25px;
        background: #ffffff;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .rbac-card-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 800;
        color: var(--text-dark);
    }

    /* Role Pills */
    .role-pills {
        display: flex;
        gap: 10px;
        padding: 20px 25px;
        border-bottom: 1px solid var(--border-color);
        background: #F8FAFC;
        overflow-x: auto;
    }

    .role-pill {
        padding: 10px 20px;
        border-radius: 12px;
        border: 2px solid var(--border-color);
        background: #ffffff;
        color: var(--text-dark);
        font-weight: 700;
        font-size: 13.5px;
        cursor: pointer;
        transition: all 0.25s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .role-pill.active {
        background: var(--accent-red);
        color: #ffffff;
        border-color: var(--accent-red);
        box-shadow: 0 4px 14px rgba(183, 28, 28, 0.25);
    }

    /* Tab Grid Setup */
    .tab-matrix-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 18px;
        padding: 25px;
    }

    .tab-item-card {
        background: #ffffff;
        border: 1.5px solid var(--border-color);
        border-radius: 16px;
        padding: 16px 18px;
        display: flex;
        align-items: flex-start;
        gap: 14px;
        transition: all 0.25s ease;
        cursor: pointer;
        user-select: none;
    }

    .tab-item-card:hover {
        border-color: var(--accent-red);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .tab-item-card.selected {
        border-color: var(--accent-red);
        background: var(--light-red);
    }

    .tab-item-card .icon-box {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: #F1F5F9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: var(--accent-red);
        flex-shrink: 0;
    }

    .tab-item-card.selected .icon-box {
        background: var(--accent-red);
        color: #ffffff;
    }

    .tab-info h5 {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 700;
        color: var(--text-dark);
    }

    .tab-info p {
        margin: 0;
        font-size: 12px;
        color: var(--text-muted);
        line-height: 1.35;
    }

    .custom-checkbox {
        width: 20px;
        height: 20px;
        border-radius: 6px;
        border: 2px solid var(--border-color);
        margin-left: auto;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.2s;
        flex-shrink: 0;
    }

    .tab-item-card.selected .custom-checkbox {
        background: var(--accent-red);
        border-color: var(--accent-red);
        color: #ffffff;
    }

    /* Modal Backdrop & Styles */
    .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 999;
    }

    .modal-box {
        background: #ffffff;
        width: 100%;
        max-width: 460px;
        border-radius: 20px;
        padding: 28px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
    }

    .btn-red {
        background: linear-gradient(135deg, var(--primary-red), var(--accent-red));
        color: #ffffff;
        border: none;
        padding: 11px 24px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: 0.3s;
    }

    .btn-red:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(139, 0, 0, 0.3);
    }
</style>

<div class="rbac-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 style="font-weight: 800; color: var(--text-dark); margin:0;">Role & Permission Manager</h2>
            <p style="color: var(--text-muted); margin:0; font-size: 13px;">Create system roles and dynamically assign accessible dashboard tabs.</p>
        </div>
        <button class="btn-red" id="openCreateModal"><i class="fas fa-plus-circle me-2"></i> Create New Role</button>
    </div>

    <div class="rbac-card">
        <!-- Role Selector Pills -->
        <div class="role-pills" id="rolePillContainer">
            <?php foreach ($roles as $index => $r): ?>
                <div class="role-pill <?php echo $index === 0 ? 'active' : ''; ?>" data-role="<?php echo htmlspecialchars($r['role_key']); ?>">
                    <i class="fas fa-user-shield"></i>
                    <span><?php echo htmlspecialchars($r['role_name']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="rbac-card-header">
            <h3>Assigned Dashboard Tabs (<span id="activeRoleTitle">Administrator</span>)</h3>
            <button class="btn-red" id="savePermissionsBtn"><i class="fas fa-save me-2"></i> Save Permissions</button>
        </div>

        <!-- Section: Main Modules -->
        <h5 style="padding: 20px 25px 0 25px; margin:0; color: var(--text-dark); font-weight:800;">
            <i class="fas fa-th-large me-2" style="color:var(--accent-red);"></i> Main Workspace Modules
        </h5>
        <div class="tab-matrix-grid" id="mainTabsGrid">
            <?php foreach ($allTabs as $tab): 
                if ($tab['section'] !== 'main') continue; ?>
                <div class="tab-item-card" data-tab-id="<?php echo $tab['id']; ?>">
                    <div class="icon-box"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                    <div class="tab-info">
                        <h5><?php echo htmlspecialchars($tab['title']); ?></h5>
                        <p><?php echo htmlspecialchars($tab['description']); ?></p>
                    </div>
                    <div class="custom-checkbox"><i class="fas fa-check" style="font-size: 10px;"></i></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Section: GRN Modules -->
        <h5 style="padding: 10px 25px 0 25px; margin:0; color: var(--text-dark); font-weight:800;">
            <i class="fas fa-boxes me-2" style="color:var(--accent-red);"></i> Goods Receipt Note (GRN) Modules
        </h5>
        <div class="tab-matrix-grid" id="grnTabsGrid">
            <?php foreach ($allTabs as $tab): 
                if ($tab['section'] !== 'grn') continue; ?>
                <div class="tab-item-card" data-tab-id="<?php echo $tab['id']; ?>">
                    <div class="icon-box"><i class="<?php echo htmlspecialchars($tab['icon']); ?>"></i></div>
                    <div class="tab-info">
                        <h5><?php echo htmlspecialchars($tab['title']); ?></h5>
                        <p><?php echo htmlspecialchars($tab['description']); ?></p>
                    </div>
                    <div class="custom-checkbox"><i class="fas fa-check" style="font-size: 10px;"></i></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal: Create Role -->
<div class="modal-backdrop" id="createRoleModal">
    <div class="modal-box">
        <h4 style="font-weight: 800; margin-bottom: 6px;">Create New System Role</h4>
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">Add a new access tier to your organization.</p>
        
        <form id="createRoleForm">
            <div class="mb-3">
                <label style="font-weight:700; font-size: 13px; display:block; margin-bottom: 6px;">Role Name</label>
                <input type="text" id="newRoleName" class="form-control" placeholder="e.g. Finance Auditor" required style="border-radius:10px; padding:10px 14px;">
            </div>
            <div class="mb-4">
                <label style="font-weight:700; font-size: 13px; display:block; margin-bottom: 6px;">Description</label>
                <textarea id="newRoleDesc" class="form-control" rows="3" placeholder="Describe access privileges..." style="border-radius:10px; padding:10px 14px;"></textarea>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" id="closeCreateModal" style="border-radius:10px; font-weight:700;">Cancel</button>
                <button type="submit" class="btn-red">Create Role</button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript Interactivity -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const mappings = <?php echo json_encode($roleTabMappings); ?>;
        let activeRole = 'admin';

        const rolePills = document.querySelectorAll('.role-pill');
        const tabCards = document.querySelectorAll('.tab-item-card');
        const activeRoleTitle = document.getElementById('activeRoleTitle');

        // Function to render active role's checkboxes
        function updateTabMatrix() {
            const assignedTabIds = mappings[activeRole] || [];
            tabCards.forEach(card => {
                const tabId = parseInt(card.dataset.tabId);
                if (assignedTabIds.includes(tabId)) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            });
        }

        // Switch active role
        rolePills.forEach(pill => {
            pill.addEventListener('click', function() {
                rolePills.forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                activeRole = this.dataset.role;
                activeRoleTitle.textContent = this.querySelector('span').textContent;
                updateTabMatrix();
            });
        });

        // Toggle card selections
        tabCards.forEach(card => {
            card.addEventListener('click', function() {
                this.classList.toggle('selected');
                const tabId = parseInt(this.dataset.tabId);
                
                if (!mappings[activeRole]) mappings[activeRole] = [];
                
                if (this.classList.contains('selected')) {
                    if (!mappings[activeRole].includes(tabId)) {
                        mappings[activeRole].push(tabId);
                    }
                } else {
                    mappings[activeRole] = mappings[activeRole].filter(id => id !== tabId);
                }
            });
        });

        // Save Permissions via AJAX
        document.getElementById('savePermissionsBtn').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';

            const formData = new FormData();
            formData.append('action', 'save_permissions');
            formData.append('role_key', activeRole);
            
            const currentTabs = mappings[activeRole] || [];
            currentTabs.forEach(id => formData.append('tabs[]', id));

            fetch('role_management.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save me-2"></i> Save Permissions';
                alert(data.message);
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save me-2"></i> Save Permissions';
                alert('An error occurred while saving.');
            });
        });

        // Modal Elements
        const modal = document.getElementById('createRoleModal');
        document.getElementById('openCreateModal').addEventListener('click', () => modal.style.display = 'flex');
        document.getElementById('closeCreateModal').addEventListener('click', () => modal.style.display = 'none');

        // Create Role via AJAX
        document.getElementById('createRoleForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const roleName = document.getElementById('newRoleName').value.trim();
            const roleDesc = document.getElementById('newRoleDesc').value.trim();

            const formData = new FormData();
            formData.append('action', 'create_role');
            formData.append('role_name', roleName);
            formData.append('role_description', roleDesc);

            fetch('role_management.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message);
                }
            });
        });

        // Initial Load
        updateTabMatrix();
    });
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>