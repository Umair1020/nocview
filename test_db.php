<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once $_SERVER['DOCUMENT_ROOT'] . 'db_connect.php';
$db = Database::getInstance();
$mysqli = $db->getConnection();
echo $mysqli->ping() ? "Database connected" : "Database connection failed";
?>