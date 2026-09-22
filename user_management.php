<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

startSession();
requireLogin();

// Require Admin Privileges
requireRole(['admin']);

$page_title = 'User Management';
$page = 'users';

$conn = getConnection();
$message = '';
$error = '';

// 1. Fetch all available dynamic roles from database
$rolesQuery = $conn->query("SELECT role_key, role_name FROM po_roles ORDER BY id ASC");
$availableRoles = [];
if ($rolesQuery) {
    while ($r = $rolesQuery->fetch_assoc()) {
        $availableRoles[$r['role_key']] = $r['role_name'];
    }
} else {
    // Fallback if po_roles table is not yet populated
    $availableRoles = ['admin' => 'Administrator', 'user' => 'Standard User', 'received' => 'GRN Officer'];
}

// 2. Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add' || $action === 'edit') {
        $username = trim($_POST['username'] ?? '');
        $role     = trim($_POST['role'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $userId   = (int)($_POST['user_id'] ?? 0);

        // Dynamic Role Validation
        if (empty($username) || !array_key_exists($role, $availableRoles)) {
            $error = 'Invalid username or role selected.';
        } else {
            if ($action === 'add') {
                if (empty($password)) {
                    $error = 'Password is required for new users.';
                } else {
                    // Check if username already exists
                    $checkStmt = $conn->prepare("SELECT id FROM po_users WHERE username = ?");
                    $checkStmt->bind_param("s", $username);
                    $checkStmt->execute();
                    if ($checkStmt->get_result()->num_rows > 0) {
                        $error = 'Username already exists.';
                    } else {
                        // Secure Bcrypt Hashing
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $conn->prepare("INSERT INTO po_users (username, password, role) VALUES (?, ?, ?)");
                        $stmt->bind_param("sss", $username, $hashedPassword, $role);
                        if ($stmt->execute()) {
                            $message = 'User added successfully with secure password encryption.';
                        } else {
                            $error = 'Database Error: ' . $stmt->error;
                        }
                        $stmt->close();
                    }
                    $checkStmt->close();
                }
            } elseif ($action === 'edit' && $userId > 0) {
                if (!empty($password)) {
                    // Update username, hashed password, and role
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("UPDATE po_users SET username = ?, password = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $username, $hashedPassword, $role, $userId);
                } else {
                    // Update username and role only
                    $stmt = $conn->prepare("UPDATE po_users SET username = ?, role = ? WHERE id = ?");
                    $stmt->bind_param("ssi", $username, $role, $userId);
                }
                
                if ($stmt->execute()) {
                    $message = 'User account updated successfully.';
                } else {
                    $error = 'Database Error: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0 && $userId !== (int)$_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM po_users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            if ($stmt->execute()) {
                $message = 'User deleted successfully.';
            } else {
                $error = 'Database Error: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = 'You cannot delete your own active administrator account.';
        }
    }
}

// 3. Fetch all system users
$users = [];
$result = $conn->query("SELECT id, username, role, created_at, last_login FROM po_users ORDER BY id ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $result->free();
}

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    :root {
        --primary-red: #8B0000;
        --accent-red: #B71C1C;
        --light-red-bg: #FFEBEE;
        --text-dark: #0F172A;
        --text-muted: #64748B;
        --border-color: #E2E8F0;
    }

    .user-container { padding: 25px; }
    
    .user-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #ffffff;
        padding: 20px 28px;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        margin-bottom: 25px;
        border: 1px solid var(--border-color);
        border-left: 6px solid var(--accent-red);
    }
    .user-header h2 { margin: 0; font-size: 22px; font-weight: 800; color: var(--text-dark); }
    .user-header p { margin: 2px 0 0 0; font-size: 13px; color: var(--text-muted); }

    .btn-red {
        background: linear-gradient(135deg, var(--primary-red), var(--accent-red));
        color: #ffffff;
        border: none;
        padding: 11px 24px;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 15px rgba(139, 0, 0, 0.2);
    }
    .btn-red:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 22px rgba(139, 0, 0, 0.3);
        color: #fff;
    }

    /* User Grid / Cards */
    .user-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 22px;
    }
    .user-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 22px;
        box-shadow: 0 4px 18px rgba(0,0,0,0.03);
        border: 1px solid var(--border-color);
        border-top: 5px solid var(--accent-red);
        transition: all 0.3s ease;
        position: relative;
    }
    .user-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 30px rgba(139,0,0,0.12);
    }
    .user-card .username-box {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 12px;
    }
    .user-card .username-box i {
        font-size: 36px;
        color: var(--accent-red);
    }
    .user-card .username {
        font-weight: 800;
        font-size: 17px;
        color: var(--text-dark);
    }
    .role-badge {
        display: inline-block;
        padding: 4px 14px;
        border-radius: 20px;
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #F1F5F9;
        color: var(--text-dark);
    }
    .role-badge.admin { background: var(--light-red-bg); color: var(--accent-red); }
    .role-badge.user { background: #E0F2FE; color: #0369A1; }
    .role-badge.received { background: #CCFBF1; color: #0F766E; }

    .user-card .meta {
        font-size: 12.5px;
        color: var(--text-muted);
        margin-top: 6px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .user-card .actions {
        display: flex;
        gap: 8px;
        margin-top: 18px;
        padding-top: 15px;
        border-top: 1px solid var(--border-color);
    }
    .btn-action {
        padding: 7px 14px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 700;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-edit { background: #FEF3C7; color: #D97706; }
    .btn-edit:hover { background: #FDE68A; }
    .btn-delete { background: #FEE2E2; color: #DC2626; }
    .btn-delete:hover { background: #FCA5A5; }

    /* Modal Overlay */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }
    .modal-overlay.active { display: flex; }
    .form-modal {
        background: #ffffff;
        padding: 30px;
        border-radius: 22px;
        max-width: 460px;
        width: 90%;
        box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    }
    .form-modal h3 { font-weight: 800; font-size: 20px; color: var(--text-dark); margin-bottom: 18px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-weight: 700; font-size: 13px; color: var(--text-dark); margin-bottom: 6px; }
    .form-group input, .form-group select {
        width: 100%;
        padding: 11px 14px;
        border: 1.5px solid var(--border-color);
        border-radius: 12px;
        font-size: 14px;
    }
    .form-group input:focus, .form-group select:focus {
        border-color: var(--accent-red);
        outline: none;
    }
    .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
</style>

<div class="user-container">
    <div class="user-header">
        <div>
            <h2><i class="fas fa-users-cog" style="color:var(--accent-red); margin-right:8px;"></i> User Management</h2>
            <p>Create, update user roles, and secure system access permissions.</p>
        </div>
        <button onclick="openAddModal()" class="btn-red"><i class="fas fa-user-plus"></i> Add New User</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="border-radius:12px; margin-bottom:20px;"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" style="border-radius:12px; margin-bottom:20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="user-grid">
        <?php foreach ($users as $user): ?>
            <div class="user-card">
                <div class="username-box">
                    <i class="fas fa-user-circle"></i>
                    <div>
                        <div class="username"><?php echo htmlspecialchars($user['username']); ?></div>
                        <span class="role-badge <?php echo htmlspecialchars($user['role']); ?>">
                            <?php echo htmlspecialchars($availableRoles[$user['role']] ?? ucfirst($user['role'])); ?>
                        </span>
                    </div>
                </div>
                <div class="meta"><i class="fas fa-calendar-alt"></i> Created: <?php echo date('M j, Y H:i', strtotime($user['created_at'])); ?></div>
                <div class="meta"><i class="fas fa-clock"></i> Last Login: <?php echo $user['last_login'] ? date('M j, Y H:i', strtotime($user['last_login'])) : 'Never'; ?></div>
                
                <div class="actions">
                    <button onclick="openEditModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>', '<?php echo $user['role']; ?>')" class="btn-action btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                        <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>')" class="btn-action btn-delete">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div class="modal-overlay" id="userModal">
    <div class="form-modal">
        <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
        <form method="POST" id="userForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="user_id" id="formUserId" value="0">
            
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="formUsername" required placeholder="Enter username">
            </div>
            
            <div class="form-group">
                <label>Assigned Role</label>
                <select name="role" id="formRole" required>
                    <?php foreach ($availableRoles as $key => $name): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($name); ?> (<?php echo htmlspecialchars($key); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Password <span id="passLabel" style="font-weight:normal; color:var(--text-muted);">(required for new user)</span></label>
                <input type="password" name="password" id="formPassword" placeholder="Enter password">
            </div>
            
            <div class="form-actions">
                <button type="button" onclick="closeModal()" class="btn btn-light" style="border-radius:10px; font-weight:700;">Cancel</button>
                <button type="submit" class="btn-red" style="padding: 10px 24px;">Save Account</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('userModal');

    function openAddModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus" style="color:var(--accent-red);"></i> Add New User';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formUserId').value = '0';
        document.getElementById('formUsername').value = '';
        document.getElementById('formPassword').value = '';
        document.getElementById('passLabel').innerHTML = '(required for new user)';
        document.getElementById('formPassword').required = true;
        modal.classList.add('active');
    }

    function openEditModal(id, username, role) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-edit" style="color:var(--accent-red);"></i> Edit User Account';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formUserId').value = id;
        document.getElementById('formUsername').value = username;
        document.getElementById('formRole').value = role;
        document.getElementById('formPassword').value = '';
        document.getElementById('passLabel').innerHTML = '(leave blank to retain current password)';
        document.getElementById('formPassword').required = false;
        modal.classList.add('active');
    }

    function confirmDelete(id, username) {
        if (confirm('Are you sure you want to permanently delete user "' + username + '"?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="' + id + '">';
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

<?php include ROOT_PATH . 'includes/footer.php'; ?>