<?php
// api.php - All JSON endpoints for the PO system
require_once 'includes/config.php';
require_once 'includes/functions.php';

$conn = getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {

    // ==================== Supplier Search ====================
    case 'searchSuppliers':
        $search = $conn->real_escape_string($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page_num'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT supplier_id, supplier_name, contact_person, phone, email FROM suppliers WHERE status=1";
        if ($search) {
            $sql .= " AND (supplier_name LIKE '%$search%' OR contact_person LIKE '%$search%')";
        }
        $countSql = str_replace("SELECT supplier_id, supplier_name, contact_person, phone, email", "SELECT COUNT(*) as total", $sql);
        $totalRes = $conn->query($countSql);
        $total = $totalRes->fetch_assoc()['total'];
        
        $sql .= " ORDER BY supplier_name LIMIT $offset, $perPage";
        $res = $conn->query($sql);
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;
        
        echo json_encode([
            'data' => $data,
            'totalPages' => ceil($total / $perPage),
            'currentPage' => $page,
            'total' => $total
        ]);
        break;

    // ==================== Sub‑Departments ====================
    case 'getSubDepartments':
        $deptId = (int)($_GET['department_id'] ?? 0);
        $res = $conn->query("SELECT sub_department_id, sub_department_name FROM sub_departments WHERE department_id = $deptId ORDER BY sub_department_name");
        $out = [];
        while ($row = $res->fetch_assoc()) $out[] = $row;
        echo json_encode($out);
        break;

    // ==================== Search Items (for PO creation) ====================
    case 'searchItems':
        $supplierId = (int)($_GET['supplier_id'] ?? 0);
        $dept = (int)($_GET['department_id'] ?? 0);
        $sub = (int)($_GET['sub_department_id'] ?? 0);
        $cat = (int)($_GET['category_id'] ?? 0);
        $col = (int)($_GET['color_id'] ?? 0);
        $sz = (int)($_GET['size_id'] ?? 0);
        $search = $conn->real_escape_string($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page_num'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT i.item_id, i.item_name, i.item_code, i.system_code,
                       d.department_name, c.category_name, col.color_name, s.size_name,
                       COALESCE(si.cost_price, i.cost_price) as cost_price,
                       COALESCE(si.selling_price, i.selling_price) as selling_price
                FROM items i
                LEFT JOIN departments d ON i.department_id = d.department_id
                LEFT JOIN categories c ON i.category_id = c.category_id
                LEFT JOIN colors col ON i.color_id = col.color_id
                LEFT JOIN sizes s ON i.size_id = s.size_id
                INNER JOIN supplier_items si ON si.item_id = i.item_id AND si.supplier_id = $supplierId
                WHERE 1";
        if ($dept) $sql .= " AND i.department_id = $dept";
        if ($sub) $sql .= " AND i.sub_department_id = $sub";
        if ($cat) $sql .= " AND i.category_id = $cat";
        if ($col) $sql .= " AND i.color_id = $col";
        if ($sz)  $sql .= " AND i.size_id = $sz";
        if ($search) $sql .= " AND (i.item_name LIKE '%$search%' OR i.item_code LIKE '%$search%' OR i.system_code LIKE '%$search%')";

        $countSql = str_replace("SELECT i.item_id, i.item_name, i.item_code, i.system_code, ...", "SELECT COUNT(*) as total", $sql);
        $totalRes = $conn->query($countSql);
        $total = $totalRes->fetch_assoc()['total'];

        $sql .= " ORDER BY i.item_name LIMIT $offset, $perPage";
        $res = $conn->query($sql);
        $data = [];
        while ($row = $res->fetch_assoc()) $data[] = $row;

        echo json_encode([
            'data' => $data,
            'totalPages' => ceil($total / $perPage),
            'currentPage' => $page,
            'total' => $total
        ]);
        break;

    // ==================== Create New Item (with supplier link) ====================
    case 'createItem':
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $itemName = $conn->real_escape_string($_POST['item_name'] ?? '');
        $deptId = (int)($_POST['department_id'] ?? 0);
        $subDeptId = (int)($_POST['sub_department_id'] ?? 0);
        $catId = (int)($_POST['category_id'] ?? 0);
        $colorId = (int)($_POST['color_id'] ?? 0);
        $sizeId = (int)($_POST['size_id'] ?? 0);
        $cost = (float)($_POST['cost_price'] ?? 0);
        $sell = (float)($_POST['selling_price'] ?? 0);

        if (!$supplierId || !$itemName || !$deptId || $cost <= 0 || $sell <= 0) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            break;
        }

        // Generate system_code and item_code
        $sysCode = 'SYS-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $itemCode = 'ITM-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        // Ensure uniqueness (simple retry loop)
        $attempts = 0;
        while ($attempts < 5) {
            $check = $conn->query("SELECT item_id FROM items WHERE item_code = '$itemCode' OR system_code = '$sysCode'");
            if ($check->num_rows === 0) break;
            $sysCode = 'SYS-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $itemCode = 'ITM-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $attempts++;
        }

        $conn->begin_transaction();
        try {
            // Insert item
            $sql = "INSERT INTO items (system_code, item_code, item_name, department_id, sub_department_id, category_id, color_id, size_id, cost_price, selling_price)
                    VALUES ('$sysCode', '$itemCode', '$itemName', $deptId, " . ($subDeptId ?: 'NULL') . ", " . ($catId ?: 'NULL') . ", " . ($colorId ?: 'NULL') . ", " . ($sizeId ?: 'NULL') . ", $cost, $sell)";
            if (!$conn->query($sql)) throw new Exception($conn->error);
            $itemId = $conn->insert_id;

            // Link to supplier
            $sql2 = "INSERT INTO supplier_items (supplier_id, item_id, cost_price, selling_price) VALUES ($supplierId, $itemId, $cost, $sell)";
            if (!$conn->query($sql2)) throw new Exception($conn->error);

            $conn->commit();
            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'item_name' => $itemName,
                'cost_price' => $cost,
                'selling_price' => $sell
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ==================== Get PO details (for view & receive) ====================
    case 'getPO':
        $poId = (int)($_GET['po_id'] ?? 0);
        if (!$poId) { echo json_encode(['error' => 'PO ID required']); break; }

        // Header
        $po = $conn->query("SELECT h.*, s.supplier_name FROM po_header h LEFT JOIN suppliers s ON h.supplier_id = s.supplier_id WHERE h.po_id = $poId")->fetch_assoc();
        if (!$po) { echo json_encode(['error' => 'PO not found']); break; }

        // Items
        $items = [];
        $res = $conn->query("SELECT pi.*, i.item_name FROM po_items pi JOIN items i ON pi.item_id = i.item_id WHERE pi.po_id = $poId");
        while ($row = $res->fetch_assoc()) $items[] = $row;

        echo json_encode(array_merge($po, ['items' => $items]));
        break;

    // ==================== Receive PO (update received quantities) ====================
    case 'receivePO':
        $poId = (int)($_POST['po_id'] ?? 0);
        if (!$poId) { echo json_encode(['success' => false, 'message' => 'PO ID missing']); break; }

        $received = $_POST['received'] ?? [];
        if (empty($received)) { echo json_encode(['success' => false, 'message' => 'No quantities provided']); break; }

        $conn->begin_transaction();
        try {
            foreach ($received as $poItemId => $qty) {
                $qty = max(0, (int)$qty);
                $sql = "UPDATE po_items SET received_qty = received_qty + $qty WHERE po_item_id = $poItemId AND po_id = $poId";
                if (!$conn->query($sql)) throw new Exception($conn->error);
            }

            // Optionally update PO status to 'Received' if all items fully received
            $check = $conn->query("SELECT SUM(quantity - received_qty) as remaining FROM po_items WHERE po_id = $poId");
            $remaining = $check->fetch_assoc()['remaining'];
            if ($remaining == 0) {
                $conn->query("UPDATE po_header SET status = 'Received' WHERE po_id = $poId");
            }

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ==================== Cancel PO ====================
    case 'cancelPO':
        $poId = (int)($_POST['po_id'] ?? 0);
        if (!$poId) { echo json_encode(['success' => false, 'message' => 'PO ID missing']); break; }

        $conn->query("UPDATE po_header SET status = 'Cancelled' WHERE po_id = $poId");
        if ($conn->affected_rows > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'PO not found or already cancelled']);
        }
        break;

    // ==================== Default ====================
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>