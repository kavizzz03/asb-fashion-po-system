<?php
require_once 'config/database.php';

echo "<h2>PO Management System Installation</h2>";

// Check if database exists
$conn = getConnection();
$dbName = DB_NAME;

$result = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$dbName'");

if ($result->num_rows == 0) {
    echo "<p>Creating database '$dbName'...</p>";
    $conn->query("CREATE DATABASE $dbName");
    echo "<p style='color:green'>✓ Database created successfully!</p>";
} else {
    echo "<p style='color:orange'>⚠ Database '$dbName' already exists.</p>";
}

$conn->select_db($dbName);

// Check if tables exist
$tables = ['users', 'suppliers', 'departments', 'sub_departments', 'categories', 
           'colors', 'sizes', 'store_locations', 'items', 'po_header', 
           'po_items', 'po_item_allocations', 'po_status_log'];

$allTablesExist = true;
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows == 0) {
        $allTablesExist = false;
        break;
    }
}

if (!$allTablesExist) {
    echo "<p>Creating tables and importing sample data...</p>";
    
    // Read SQL file
    $sqlFile = 'database.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        
        // Execute SQL
        if ($conn->multi_query($sql)) {
            do {
                // Process results
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->next_result());
        }
        
        echo "<p style='color:green'>✓ Tables created and sample data imported!</p>";
    } else {
        echo "<p style='color:red'>✗ database.sql file not found!</p>";
    }
} else {
    echo "<p style='color:green'>✓ All tables already exist.</p>";
}

echo "<p><a href='login.php' class='btn btn-primary'>Go to Login</a></p>";
?>