<?php
// test_po_debug.php - Direct PO creation test
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

$conn = getConnection();
$qcConn = getQcConnection();

echo "<h2>PO Creation Debug Test</h2>";

// Check if we're submitting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>Testing PO Creation...</h3>";
    
    try {
        $supplier_id = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
        $purchase_date = isset($_POST['purchase_date']) ? $_POST['purchase_date'] : date('Y-m-d');
        $po_number = 'TEST-' . date('YmdHis');
        
        echo "Supplier ID: $supplier_id<br>";
        echo "Purchase Date: $purchase_date<br>";
        echo "PO Number: $po_number<br>";
        
        // Check supplier exists in po_system
        $check = $conn->query("SELECT supplier_id FROM suppliers WHERE supplier_id = $supplier_id");
        if ($check->num_rows == 0) {
            // Copy from return_qc
            $copy = $qcConn->query("SELECT supplier_name, contact_number, email, address FROM suppliers WHERE supplier_id = $supplier_id");
            if ($row = $copy->fetch_assoc()) {
                $insert = $conn->prepare("INSERT INTO suppliers (supplier_id, supplier_name, contact_number, email, address) VALUES (?, ?, ?, ?, ?)");
                $insert->bind_param("issss", $supplier_id, $row['supplier_name'], $row['contact_number'], $row['email'], $row['address']);
                $insert->execute();
                echo "Supplier copied to po_system<br>";
            }
        }
        
        // Check po_users
        $user_check = $conn->query("SELECT id FROM po_users LIMIT 1");
        if ($user_check->num_rows == 0) {
            $conn->query("INSERT INTO po_users (username, password, role) VALUES ('system', MD5('system123'), 'admin')");
            echo "Default user created<br>";
        }
        $user = $user_check->fetch_assoc();
        $added_by = $user['id'];
        echo "Using user ID: $added_by<br>";
        
        // DISABLE foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        echo "Foreign key checks disabled<br>";
        
        // Insert PO header
        $stmt = $conn->prepare("INSERT INTO po_header (po_number, supplier_id, purchase_date, status, added_by) VALUES (?, ?, ?, 'Pending', ?)");
        $stmt->bind_param("sisi", $po_number, $supplier_id, $purchase_date, $added_by);
        
        if ($stmt->execute()) {
            $po_id = $stmt->insert_id;
            echo "✅ PO Header inserted! PO ID: $po_id<br>";
            
            // Insert a test item
            // Check if items table has data
            $item_check = $conn->query("SELECT item_id FROM items LIMIT 1");
            if ($item_check->num_rows > 0) {
                $item = $item_check->fetch_assoc();
                $item_id = $item['item_id'];
                
                $item_stmt = $conn->prepare("INSERT INTO po_items (po_id, item_id, quantity, cost_price, selling_price) VALUES (?, ?, ?, ?, ?)");
                $qty = 5;
                $cost = 10.00;
                $sell = 15.00;
                $item_stmt->bind_param("iiidd", $po_id, $item_id, $qty, $cost, $sell);
                $item_stmt->execute();
                echo "✅ Test item inserted<br>";
            }
            
            // Status log
            $log_stmt = $conn->prepare("INSERT INTO po_status_log (po_id, status, remarks) VALUES (?, 'Ordered', 'Test PO')");
            $log_stmt->bind_param("i", $po_id);
            $log_stmt->execute();
            echo "✅ Status log inserted<br>";
            
            $conn->commit();
            echo "✅ Transaction committed<br>";
            echo "<h3 style='color:green'>✅ PO CREATED SUCCESSFULLY!</h3>";
            echo "PO Number: $po_number<br>";
            echo "PO ID: $po_id<br>";
            
        } else {
            echo "❌ PO Header insert failed: " . $stmt->error . "<br>";
        }
        
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        
    } catch (Exception $e) {
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        echo "❌ Exception: " . $e->getMessage() . "<br>";
        echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "<br>";
    }
    
    echo "<hr><a href='test_po_debug.php'>Run again</a>";
    exit;
}

// Show form
?>
<!DOCTYPE html>
<html>
<head>
    <title>PO Debug Test</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .form-group { margin: 15px 0; }
        label { display: inline-block; width: 150px; }
        input, select { padding: 8px; width: 200px; }
        button { padding: 10px 30px; background: #d32f2f; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <h1>PO Creation Debug Test</h1>
    
    <?php
    // Get suppliers from return_qc
    $suppliers = $qcConn->query("SELECT supplier_id, supplier_name FROM suppliers LIMIT 10");
    ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Supplier:</label>
            <select name="supplier_id" required>
                <option value="">-- Select --</option>
                <?php while($row = $suppliers->fetch_assoc()): ?>
                <option value="<?= $row['supplier_id'] ?>"><?= $row['supplier_name'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Purchase Date:</label>
            <input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <button type="submit">Create Test PO</button>
    </form>
    
    <hr>
    <h3>Check Database Tables:</h3>
    <?php
    $tables = ['po_header', 'po_items', 'po_status_log', 'items', 'suppliers', 'po_users'];
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        echo $table . ": " . ($result->num_rows > 0 ? "✅ EXISTS" : "❌ MISSING") . "<br>";
    }
    ?>
    
    <h3>Check po_users:</h3>
    <?php
    $users = $conn->query("SELECT * FROM po_users");
    if ($users->num_rows > 0) {
        while($row = $users->fetch_assoc()) {
            echo "ID: {$row['id']}, Username: {$row['username']}, Role: {$row['role']}<br>";
        }
    } else {
        echo "❌ No users found!<br>";
    }
    ?>
</body>
</html>