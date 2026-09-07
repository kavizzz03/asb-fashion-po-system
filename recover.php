<?php
// recover.php - Admin recovery tool
require_once 'config/database.php';
require_once 'includes/functions.php';

$conn = getConnection();
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action === 'restore_po' && isset($_GET['po_id'])) {
    $po_id = (int)$_GET['po_id'];
    // Restore from backup tables
    $conn->begin_transaction();
    try {
        // Delete existing po_items
        $del = $conn->prepare("DELETE FROM po_items WHERE po_id = ?");
        $del->bind_param("i", $po_id);
        $del->execute();
        $del->close();
        // Delete existing po_header
        $del = $conn->prepare("DELETE FROM po_header WHERE po_id = ?");
        $del->bind_param("i", $po_id);
        $del->execute();
        $del->close();

        // Restore po_header from the latest backup for this po_id
        $backup = $conn->prepare("SELECT * FROM po_header_backup WHERE po_id = ? ORDER BY backup_timestamp DESC LIMIT 1");
        $backup->bind_param("i", $po_id);
        $backup->execute();
        $row = $backup->get_result()->fetch_assoc();
        if ($row) {
            unset($row['backup_id'], $row['backup_timestamp']);
            $cols = implode(',', array_keys($row));
            $vals = implode(',', array_fill(0, count($row), '?'));
            $stmt = $conn->prepare("INSERT INTO po_header ($cols) VALUES ($vals)");
            $types = '';
            $params = [];
            foreach ($row as $val) {
                if (is_int($val)) $types .= 'i';
                elseif (is_float($val)) $types .= 'd';
                else $types .= 's';
                $params[] = $val;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
        $backup->close();

        // Restore po_items
        $backupItems = $conn->prepare("SELECT * FROM po_items_backup WHERE po_id = ? ORDER BY backup_timestamp DESC");
        $backupItems->bind_param("i", $po_id);
        $backupItems->execute();
        $res = $backupItems->get_result();
        while ($row = $res->fetch_assoc()) {
            unset($row['backup_id'], $row['backup_timestamp']);
            $cols = implode(',', array_keys($row));
            $vals = implode(',', array_fill(0, count($row), '?'));
            $stmt = $conn->prepare("INSERT INTO po_items ($cols) VALUES ($vals)");
            $types = '';
            $params = [];
            foreach ($row as $val) {
                if (is_int($val)) $types .= 'i';
                elseif (is_float($val)) $types .= 'd';
                else $types .= 's';
                $params[] = $val;
            }
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
        $backupItems->close();

        $conn->commit();
        $message = "PO #$po_id restored successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Restore failed: " . $e->getMessage();
    }
    header("Location: recover.php?msg=" . urlencode($message));
    exit;
}

// List recent backups
$backups = $conn->query("
    SELECT DISTINCT po_id, po_number, backup_timestamp 
    FROM po_header_backup 
    ORDER BY backup_timestamp DESC 
    LIMIT 50
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Recovery Tool</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h2 { color: #b71c1c; border-bottom: 2px solid #b71c1c; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f1f3f5; }
        .btn { background: #d32f2f; color: #fff; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 12px; border: none; cursor: pointer; }
        .btn:hover { background: #b71c1c; }
        .msg { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h2><i class="fas fa-history"></i> PO Recovery Tool</h2>
    <?php if (isset($_GET['msg'])): ?>
        <div class="msg"><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>
    <p>Restore a purchase order from its latest backup.</p>
    <table>
        <thead>
            <tr>
                <th>PO Number</th>
                <th>PO ID</th>
                <th>Backup Timestamp</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $backups->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['po_number']) ?></td>
                <td><?= $row['po_id'] ?></td>
                <td><?= $row['backup_timestamp'] ?></td>
                <td>
                    <a href="?action=restore_po&po_id=<?= $row['po_id'] ?>" 
                       onclick="return confirm('Restore PO <?= $row['po_number'] ?> from backup? This will overwrite current data.')" 
                       class="btn"><i class="fas fa-undo"></i> Restore</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <p style="margin-top:20px;"><a href="pos.php" class="btn" style="background:#555;">Back to POS</a></p>
</div>
</body>
</html>