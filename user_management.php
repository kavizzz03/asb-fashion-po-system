<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
startSession();
requireLogin();

if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'User Management';
$page = 'users';
include 'includes/header.php';
include 'includes/sidebar.php';

$conn = getConnection();
$message = '';

// Handle CRUD (plain text passwords)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        if ($action === 'add' || $action === 'edit') {
            $username = trim($_POST['username']);
            $role = trim($_POST['role']);
            $password = isset($_POST['password']) ? trim($_POST['password']) : '';
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

            if (empty($username) || !in_array($role, ['admin', 'user'])) {
                $message = 'Invalid input.';
            } else {
                if ($action === 'add') {
                    if (empty($password)) {
                        $message = 'Password is required for new users.';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO po_users (username, password, role) VALUES (?, ?, ?)");
                        $stmt->bind_param("sss", $username, $password, $role);
                        if ($stmt->execute()) {
                            $message = 'User added successfully.';
                        } else {
                            $message = 'Error: ' . $stmt->error;
                        }
                        $stmt->close();
                    }
                } elseif ($action === 'edit' && $userId > 0) {
                    if (!empty($password)) {
                        $stmt = $conn->prepare("UPDATE po_users SET username=?, password=?, role=? WHERE id=?");
                        $stmt->bind_param("sssi", $username, $password, $role, $userId);
                    } else {
                        $stmt = $conn->prepare("UPDATE po_users SET username=?, role=? WHERE id=?");
                        $stmt->bind_param("ssi", $username, $role, $userId);
                    }
                    if ($stmt->execute()) {
                        $message = 'User updated successfully.';
                    } else {
                        $message = 'Error: ' . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        } elseif ($action === 'delete') {
            $userId = (int)$_POST['user_id'];
            if ($userId > 0 && $userId != $_SESSION['user_id']) {
                $stmt = $conn->prepare("DELETE FROM po_users WHERE id = ?");
                $stmt->bind_param("i", $userId);
                if ($stmt->execute()) {
                    $message = 'User deleted successfully.';
                } else {
                    $message = 'Error: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Cannot delete yourself.';
            }
        }
    }
}

// Fetch all users
$users = [];
$result = $conn->query("SELECT id, username, role, created_at, last_login FROM po_users ORDER BY id ASC");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$result->free();
?>

<style>
    .user-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-top: 20px; }
    .user-card { background: white; border-radius: 16px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); border-left: 5px solid #b71c1c; transition: 0.3s; }
    .user-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
    .user-card .username { font-weight: 700; font-size: 18px; color: #1a1a1a; }
    .user-card .role { display: inline-block; background: #b71c1c; color: white; padding: 2px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .user-card .role.user { background: #2c3e50; }
    .user-card .meta { font-size: 13px; color: #888; margin: 8px 0; }
    .user-card .actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
    .user-card .actions .btn { padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: 0.2s; }
    .btn-edit { background: #f39c12; color: white; }
    .btn-edit:hover { background: #d68910; }
    .btn-delete { background: #e74c3c; color: white; }
    .btn-delete:hover { background: #c0392b; }
    .btn-add { background: #27ae60; color: white; }
    .btn-add:hover { background: #1e8449; }
    .form-modal { background: white; padding: 25px; border-radius: 16px; max-width: 450px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    .form-modal .form-group { margin-bottom: 15px; }
    .form-modal .form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 4px; }
    .form-modal .form-group input, .form-modal .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
    .form-modal .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    .modal-overlay { position: fixed; inset:0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 2000; }
    .modal-overlay.active { display: flex; }
    .alert { padding: 12px 18px; border-radius: 10px; margin-bottom: 20px; }
    .alert-info { background: #d1ecf1; border-left: 4px solid #17a2b8; color: #0c5460; }
</style>

<div class="container-fluid" style="padding: 20px 25px;">
    <div class="page-header">
        <h2><i class="fas fa-users-cog"></i> User Management</h2>
        <button onclick="openAddModal()" class="btn btn-success btn-add" style="padding:10px 20px;"><i class="fas fa-plus"></i> Add New User</button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="user-grid">
        <?php foreach ($users as $user): ?>
            <div class="user-card">
                <div class="username"><i class="fas fa-user-circle" style="color:#b71c1c;"></i> <?php echo htmlspecialchars($user['username']); ?></div>
                <div><span class="role <?php echo $user['role'] === 'admin' ? '' : 'user'; ?>"><?php echo ucfirst($user['role']); ?></span></div>
                <div class="meta">Created: <?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></div>
                <div class="meta">Last Login: <?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?></div>
                <div class="actions">
                    <button onclick="openEditModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>', '<?php echo $user['role']; ?>')" class="btn btn-edit"><i class="fas fa-edit"></i> Edit</button>
                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                        <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>')" class="btn btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="userModal">
    <div class="form-modal">
        <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
        <form method="POST" id="userForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="user_id" id="formUserId" value="0">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="formUsername" required>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="formRole">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Password <span id="passLabel">(required for new user)</span></label>
                <input type="password" name="password" id="formPassword" placeholder="Leave blank to keep current password">
            </div>
            <div class="form-actions">
                <button type="button" onclick="closeModal()" class="btn btn-secondary" style="background:#ccc; color:#333; padding:8px 20px; border:none; border-radius:8px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#b71c1c; color:#fff; padding:8px 20px; border:none; border-radius:8px;">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('userModal');
    function openAddModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New User';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formUserId').value = '0';
        document.getElementById('formUsername').value = '';
        document.getElementById('formRole').value = 'user';
        document.getElementById('formPassword').value = '';
        document.getElementById('passLabel').innerHTML = '(required for new user)';
        document.getElementById('formPassword').required = true;
        modal.classList.add('active');
    }
    function openEditModal(id, username, role) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formUserId').value = id;
        document.getElementById('formUsername').value = username;
        document.getElementById('formRole').value = role;
        document.getElementById('formPassword').value = '';
        document.getElementById('passLabel').innerHTML = '(leave blank to keep current)';
        document.getElementById('formPassword').required = false;
        modal.classList.add('active');
    }
    function confirmDelete(id, username) {
        if (confirm('Delete user "' + username + '" permanently?')) {
            var form = document.createElement('form');
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

<?php include 'includes/footer.php'; ?>