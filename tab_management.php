<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');

require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

startSession();
requireLogin();
requireRole(['admin']);

$page_title = 'Tab Management';
$page = 'tabs';

$conn = getConnection();
$message = '';
$error = '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $title       = trim($_POST['title'] ?? '');
        $tabKey      = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $title)));
        $description = trim($_POST['description'] ?? '');
        $url         = trim($_POST['url'] ?? '');
        $icon        = trim($_POST['icon'] ?? 'fas fa-link');
        $borderColor = trim($_POST['border_color'] ?? '#b91c1c');
        $section     = trim($_POST['section'] ?? 'main');
        $order       = (int)($_POST['display_order'] ?? 0);
        $tabId       = (int)($_POST['tab_id'] ?? 0);

        if (empty($title) || empty($url)) {
            $error = 'Title and URL are required.';
        } else {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO po_tabs (tab_key, title, description, url, icon, border_color, section, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssi", $tabKey, $title, $description, $url, $icon, $borderColor, $section, $order);
                if ($stmt->execute()) {
                    $newTabId = $stmt->insert_id;
                    // Automatically assign to admin
                    $conn->query("INSERT INTO po_role_tabs (role, tab_id) VALUES ('admin', $newTabId)");
                    $message = 'New tab added successfully and mapped to Admin!';
                } else {
                    $error = 'Database Error: ' . $stmt->error;
                }
                $stmt->close();
            } elseif ($action === 'edit' && $tabId > 0) {
                $stmt = $conn->prepare("UPDATE po_tabs SET title = ?, description = ?, url = ?, icon = ?, border_color = ?, section = ?, display_order = ? WHERE id = ?");
                $stmt->bind_param("ssssssii", $title, $description, $url, $icon, $borderColor, $section, $order, $tabId);
                if ($stmt->execute()) {
                    $message = 'Tab updated successfully.';
                } else {
                    $error = 'Database Error: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete') {
        $tabId = (int)($_POST['tab_id'] ?? 0);
        if ($tabId > 0) {
            $stmt = $conn->prepare("DELETE FROM po_tabs WHERE id = ?");
            $stmt->bind_param("i", $tabId);
            if ($stmt->execute()) {
                $message = 'Tab deleted successfully.';
            } else {
                $error = 'Database Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Fetch all tabs
$tabsResult = $conn->query("SELECT * FROM po_tabs ORDER BY section ASC, display_order ASC");
$tabs = $tabsResult ? $tabsResult->fetch_all(MYSQLI_ASSOC) : [];

include ROOT_PATH . 'includes/header.php';
include ROOT_PATH . 'includes/sidebar.php';
?>

<style>
    :root {
        --primary-red: #8B0000;
        --accent-red: #B71C1C;
        --border-color: #E2E8F0;
        --text-dark: #0F172A;
        --text-muted: #64748B;
    }
    .tab-container { padding: 25px; }
    .tab-header {
        display: flex; justify-content: space-between; align-items: center;
        background: #fff; padding: 20px 28px; border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid var(--border-color);
        border-left: 6px solid var(--accent-red); margin-bottom: 25px;
    }
    .btn-red {
        background: linear-gradient(135deg, var(--primary-red), var(--accent-red));
        color: #fff; border: none; padding: 10px 20px; border-radius: 12px;
        font-weight: 700; cursor: pointer; transition: all 0.3s ease;
    }
    .btn-red:hover { transform: translateY(-2px); color: #fff; }
    .tab-table {
        background: #fff; border-radius: 20px; border: 1px solid var(--border-color);
        overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.03);
    }
    .modal-overlay {
        position: fixed; inset: 0; background: rgba(15,23,42,0.6);
        backdrop-filter: blur(4px); display: none; align-items: center;
        justify-content: center; z-index: 2000;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: #fff; padding: 30px; border-radius: 22px; width: 90%; max-width: 500px;
    }
</style>

<div class="tab-container">
    <div class="tab-header">
        <div>
            <h2 style="font-weight:800; margin:0;"><i class="fas fa-columns" style="color:var(--accent-red);"></i> Tab Manager</h2>
            <p style="margin:2px 0 0 0; color:var(--text-muted); font-size:13px;">Create and organize workspace navigation cards.</p>
        </div>
        <button onclick="openAddModal()" class="btn-red"><i class="fas fa-plus-circle me-1"></i> Add New Tab</button>
    </div>

    <?php if ($message): ?><div class="alert alert-success" style="border-radius:12px;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" style="border-radius:12px;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="tab-table p-3">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Order</th>
                    <th>Title & Key</th>
                    <th>URL</th>
                    <th>Icon</th>
                    <th>Section</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tabs as $t): ?>
                    <tr>
                        <td><strong>#<?php echo $t['display_order']; ?></strong></td>
                        <td>
                            <div style="font-weight:700; color:var(--text-dark);"><?php echo htmlspecialchars($t['title']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($t['tab_key']); ?></small>
                        </td>
                        <td><code><?php echo htmlspecialchars($t['url']); ?></code></td>
                        <td><i class="<?php echo htmlspecialchars($t['icon']); ?> fs-5" style="color:<?php echo htmlspecialchars($t['border_color']); ?>;"></i></td>
                        <td><span class="badge bg-secondary"><?php echo strtoupper($t['section']); ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-warning me-1" onclick='openEditModal(<?php echo json_encode($t); ?>)'><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $t['id']; ?>, '<?php echo addslashes($t['title']); ?>')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="tabModal">
    <div class="modal-box">
        <h4 id="modalTitle" style="font-weight:800; margin-bottom:15px;">Add New Tab</h4>
        <form method="POST">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="tab_id" id="formTabId" value="0">
            <div class="mb-2">
                <label class="form-label font-weight-bold">Tab Title</label>
                <input type="text" name="title" id="formTitle" class="form-control" required placeholder="e.g. Sales Analytics">
            </div>
            <div class="mb-2">
                <label class="form-label font-weight-bold">Description</label>
                <input type="text" name="description" id="formDesc" class="form-control" placeholder="Short description">
            </div>
            <div class="row">
                <div class="col-6 mb-2">
                    <label class="form-label font-weight-bold">File URL</label>
                    <input type="text" name="url" id="formUrl" class="form-control" required placeholder="analytics.php">
                </div>
                <div class="col-6 mb-2">
                    <label class="form-label font-weight-bold">FontAwesome Icon</label>
                    <input type="text" name="icon" id="formIcon" class="form-control" placeholder="fas fa-chart-line">
                </div>
            </div>
            <div class="row">
                <div class="col-4 mb-2">
                    <label class="form-label font-weight-bold">Color</label>
                    <input type="color" name="border_color" id="formColor" class="form-control form-control-color w-100" value="#b91c1c">
                </div>
                <div class="col-4 mb-2">
                    <label class="form-label font-weight-bold">Section</label>
                    <select name="section" id="formSection" class="form-select">
                        <option value="main">Main</option>
                        <option value="grn">GRN</option>
                    </select>
                </div>
                <div class="col-4 mb-3">
                    <label class="form-label font-weight-bold">Order</label>
                    <input type="number" name="display_order" id="formOrder" class="form-control" value="0">
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-red">Save Tab</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('tabModal');
    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Add New Tab';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formTabId').value = '0';
        document.getElementById('formTitle').value = '';
        document.getElementById('formDesc').value = '';
        document.getElementById('formUrl').value = '';
        document.getElementById('formIcon').value = 'fas fa-link';
        document.getElementById('formColor').value = '#b91c1c';
        document.getElementById('formSection').value = 'main';
        document.getElementById('formOrder').value = '0';
        modal.classList.add('active');
    }
    function openEditModal(data) {
        document.getElementById('modalTitle').textContent = 'Edit Tab';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('formTabId').value = data.id;
        document.getElementById('formTitle').value = data.title;
        document.getElementById('formDesc').value = data.description;
        document.getElementById('formUrl').value = data.url;
        document.getElementById('formIcon').value = data.icon;
        document.getElementById('formColor').value = data.border_color;
        document.getElementById('formSection').value = data.section;
        document.getElementById('formOrder').value = data.display_order;
        modal.classList.add('active');
    }
    function confirmDelete(id, title) {
        if (confirm('Delete tab "' + title + '"?')) {
            const f = document.createElement('form');
            f.method = 'POST';
            f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="tab_id" value="' + id + '">';
            document.body.appendChild(f);
            f.submit();
        }
    }
    function closeModal() { modal.classList.remove('active'); }
</script>

<?php include ROOT_PATH . 'includes/footer.php'; ?>