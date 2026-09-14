<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PO', 'po_system');          // main PO database
define('DB_QC', 'return_qc');          // database containing suppliers

function getConnection() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
        $conn->select_db(DB_PO);
        $conn->set_charset("utf8mb4");
    }
    return $conn;
}

function getQcConnection() {
    static $qcConn = null;
    if ($qcConn === null) {
        $qcConn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($qcConn->connect_error) die("QC Connection failed: " . $qcConn->connect_error);
        $qcConn->select_db(DB_QC);
        $qcConn->set_charset("utf8mb4");
    }
    return $qcConn;
}
?>