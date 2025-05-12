<?php
session_start();
require 'config/db.php';

// Habilitar el registro de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Configurar encabezados para respuesta JSON
header('Content-Type: application/json');

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Usuario no autenticado']);
    exit;
}

// Verificar si hay datos en la solicitud
if (!isset($_POST['action']) || !isset($_POST['product_id']) || !isset($_POST['shopping_card_id'])) {
    echo json_encode(['success' => false, 'message' => 'Faltan parámetros requeridos']);
    exit;
}

$user_id = $_SESSION['user_id'];
$product_id = $_POST['product_id'];
$shopping_card_id = $_POST['shopping_card_id'];
$action = $_POST['action'];

try {
    // Verificar que el carrito pertenece al usuario
    $stmt = $conn->prepare("SELECT * FROM shopping_card WHERE shopping_card_id = ? AND user_id = ?");
    $stmt->execute([$shopping_card_id, $user_id]);
    $shopping_card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shopping_card) {
        echo json_encode(['success' => false, 'message' => 'El carrito no pertenece al usuario']);
        exit;
    }
    
    // Iniciar una transacción
    $conn->beginTransaction();
    
    if ($action === 'update_quantity') {
        if (!isset($_POST['quantity'])) {
            echo json_encode(['success' => false, 'message' => 'Falta el parámetro quantity']);
            exit;
        }
        
        $quantity = max(1, intval($_POST['quantity']));
        $stmt = $conn->prepare("UPDATE shopping_card_item SET quantity = ? WHERE shopping_card_id = ? AND product_id = ?");
        $stmt->execute([$quantity, $shopping_card_id, $product_id]);
    } 
    else if ($action === 'toggle_select') {
        if (!isset($_POST['is_select'])) {
            echo json_encode(['success' => false, 'message' => 'Falta el parámetro is_select']);
            exit;
        }
        
        $is_select = intval($_POST['is_select']);
        $stmt = $conn->prepare("UPDATE shopping_card_item SET is_select = ? WHERE shopping_card_id = ? AND product_id = ?");
        $stmt->execute([$is_select, $shopping_card_id, $product_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        exit;
    }
    
    // Actualizar el monto total del carrito (todos los productos)
    $stmt = $conn->prepare("
        SELECT SUM(p.price * sci.quantity) as total 
        FROM shopping_card_item sci 
        JOIN product p ON sci.product_id = p.product_id 
        WHERE sci.shopping_card_id = ?
    ");
    $stmt->execute([$shopping_card_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = $result['total'] ?? 0;
    
    // Actualizar el total en la base de datos
    $stmt = $conn->prepare("UPDATE shopping_card SET total_amount = ? WHERE shopping_card_id = ?");
    $stmt->execute([$total, $shopping_card_id]);
    
    // Calcular el subtotal de productos seleccionados
    $stmt = $conn->prepare("
        SELECT SUM(p.price * sci.quantity) as subtotal_seleccionados 
        FROM shopping_card_item sci 
        JOIN product p ON sci.product_id = p.product_id 
        WHERE sci.shopping_card_id = ? AND sci.is_select = 1
    ");
    $stmt->execute([$shopping_card_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $subtotal_seleccionados = $result['subtotal_seleccionados'] ?? 0;
    
    // Confirmar la transacción
    $conn->commit();
    
    // Retornar éxito y los valores actualizados
    echo json_encode([
        'success' => true, 
        'total' => floatval($total), 
        'subtotal_seleccionados' => floatval($subtotal_seleccionados)
    ]);
    
} catch (PDOException $e) {
    // Si hay algún error, revertir la transacción
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Retornar error
    echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
}
?> 