<?php
// Configuración de la base de datos
$host = "4.206.5.198";
$user = "lunette";
$password = "SOLunette";
$dbname = "lunette_db";

try {
    // Conexión con nombre $conn
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
