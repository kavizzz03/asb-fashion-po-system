<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';
$conn = getConnection();

switch ($action) {
    case 'store':
        storePO($conn);
        break;
    case 'update':
        updatePO($conn);
        break;
    case 'delete':
        deletePO($conn);
        break;
    case 'update-status':
        updateStatus($conn);
        break;
    case 'receive':
        receiveItems($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function storePO($conn) {
    try {
        $conn->begin_transaction();
        
        // Insert PO Header
        $poNumber = $_POST['po_number'];
        $supplierId = $_POST['supplier_id'];
        $purchaseDate = $_POST['purchase_date'];
        $attention = $_POST['attention'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        $expectedDelivery = $_POST['expected_delivery_date'] ?? null;
        
        $query = "INSERT INTO po_header (po_number, supplier_id, purchase_date, attention, remarks, 
                  expected_delivery_date, added_by, status) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sissssi", $poNumber, $supplierId, $purchaseDate, $attention, 
                         $remarks, $expectedDelivery, $_SESSION['user_id']);
        $stmt->execute();
        $poId = $conn->insert_id;
        
        // Insert PO Items
        foreach ($_POST['items'] as $itemData) {
            $itemId = $itemData['item_id'];
            $quantity = $itemData['quantity'];
            $costPrice = $itemData['cost_price'];
            $sellingPrice = $itemData['selling_price'];
            
            $query = "INSERT INTO po_items (po_id, item_id, quantity, cost_price, selling_price, received_qty) 
                      VALUES (?, ?, ?, ?, ?, 0)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("iiidd", $poId, $itemId, $quantity, $costPrice, $sellingPrice);
            $stmt->execute();
            $poItemId = $conn->insert_id;
            
            // Insert allocations
            if (isset($itemData['allocations'])) {
                foreach ($itemData['allocations'] as $locationId => $qty) {
                    if ($qty > 0) {
                        $query = "INSERT INTO po_item_allocations (po_item_id, location_id, quantity) 
                                  VALUES (?, ?, ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("iii", $poItemId, $locationId, $qty);
                        $stmt->execute();
                    }
                }
            }
        }
        
        // Create status log
        $query = "INSERT INTO po_status_log (po_id, status, remarks, updated_by) 
                  VALUES (?, 'Ordered', 'Purchase order created', ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $poId, $_SESSION['user_id']);
        $stmt->execute();
        
        $conn->commit();
        
        $_SESSION['success'] = 'PO created successfully!';
        header('Location: ../index.php?page=pos');
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
        header('Location: ../index.php?page=pos&action=create');
    }
}

function deletePO($conn) {
    $id = $_GET['id'] ?? 0;
    
    // Check if PO can be deleted
    $query = "SELECT status FROM po_header WHERE po_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $po = $result->fetch_assoc();
    
    if (!$po || !in_array($po['status'], ['Pending', 'Cancelled'])) {
        $_SESSION['error'] = 'Only Pending or Cancelled POs can be deleted!';
        header('Location: ../index.php?page=pos');
        exit();
    }
    
    try {
        $conn->begin_transaction();
        
        // Delete allocations
        $query = "DELETE pi FROM po_item_allocations pi 
                  JOIN po_items p ON p.po_item_id = pi.po_item_id 
                  WHERE p.po_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Delete PO items
        $query = "DELETE FROM po_items WHERE po_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Delete status logs
        $query = "DELETE FROM po_status_log WHERE po_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Delete PO header
        $query = "DELETE FROM po_header WHERE po_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        $conn->commit();
        $_SESSION['success'] = 'PO deleted successfully!';
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header('Location: ../index.php?page=pos');
}

function updateStatus($conn) {
    $id = $_POST['po_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    
    try {
        $conn->begin_transaction();
        
        $query = "UPDATE po_header SET status = ? WHERE po_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        
        $query = "INSERT INTO po_status_log (po_id, status, remarks, updated_by) 
                  VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", $id, $status, $remarks, $_SESSION['user_id']);
        $stmt->execute();
        
        $conn->commit();
        $_SESSION['success'] = 'Status updated successfully!';
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header('Location: ../index.php?page=pos&action=show&id=' . $id);
}

function receiveItems($conn) {
    $poId = $_POST['po_id'] ?? 0;
    
    try {
        $conn->begin_transaction();
        
        $allReceived = true;
        
        foreach ($_POST['items'] as $poItemId => $data) {
            $receivedQty = $data['received_qty'] ?? 0;
            
            $query = "UPDATE po_items SET received_qty = received_qty + ? WHERE po_item_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $receivedQty, $poItemId);
            $stmt->execute();
            
            // Check if item is fully received
            $query = "SELECT quantity, received_qty FROM po_items WHERE po_item_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $poItemId);
            $stmt->execute();
            $result = $stmt->get_result();
            $item = $result->fetch_assoc();
            
            if ($item['received_qty'] < $item['quantity']) {
                $allReceived = false;
            }
        }
        
        // Update PO status
        $status = $allReceived ? 'Received' : 'Received';
        $remarks = $allReceived ? 'All items received' : 'Partial items received';
        
        $query = "UPDATE po_header SET status = ? WHERE po_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $status, $poId);
        $stmt->execute();
        
        $query = "INSERT INTO po_status_log (po_id, status, remarks, updated_by) 
                  VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", $poId, $status, $remarks, $_SESSION['user_id']);
        $stmt->execute();
        
        $conn->commit();
        $_SESSION['success'] = 'Items received successfully!';
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = 'Error: ' . $e->getMessage();
    }
    
    header('Location: ../index.php?page=pos&action=show&id=' . $poId);
}
?>