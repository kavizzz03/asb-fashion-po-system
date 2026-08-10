<?php
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config/database.php';
require_once ROOT_PATH . 'includes/functions.php';

header('Content-Type: application/json');

$po_id = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
if ($po_id <= 0) {
    echo json_encode([]);
    exit;
}

$conn = getConnection();

// For 1M+ records, ensure indexes on po_id and received_date
$stmt = $conn->prepare("
    SELECT grn_id, grn_number, received_date, delivery_note_no, remarks, vehicle_no, delivered_by, total_box_count
    FROM grn_header
    WHERE po_id = ?
    ORDER BY received_date DESC, grn_id DESC
");
$stmt->bind_param("i", $po_id);
$stmt->execute();
$result = $stmt->get_result();
$grns = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($grns);
?>