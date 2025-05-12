<?php
session_start();
require 'config/db.php';

// Habilitar el registro de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Función para registrar errores en consola
function logToConsole($message) {
    echo "<script>console.log('PHP Debug: " . str_replace("'", "\'", $message) . "');</script>";
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    logToConsole("Error: Usuario no logueado");
    header('Location: login.php');
    exit;
}

// Verificar parámetros necesarios
if (!isset($_GET['action']) || !isset($_GET['product_id']) || !isset($_GET['shopping_card_id'])) {
    logToConsole("Error: Faltan datos en la solicitud");
    header('Location: cart.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$product_id = $_GET['product_id'];
$shopping_card_id = $_GET['shopping_card_id'];
$action = $_GET['action'];

logToConsole("Actualizando carrito: Acción=$action, ProductoID=$product_id, UsuarioID=$user_id, CarritoID=$shopping_card_id");

try {
    // Verificar que el carrito pertenezca al usuario
    $stmt = $conn->prepare("SELECT * FROM shopping_card WHERE shopping_card_id = ? AND user_id = ?");
    $stmt->execute([$shopping_card_id, $user_id]);
    $cart = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cart) {
        logToConsole("Error: El carrito no pertenece al usuario");
        $_SESSION['cart_message'] = [
            'type' => 'error',
            'text' => 'No tienes acceso a este carrito'
        ];
        header('Location: cart.php');
        exit;
    }
    
    // Obtener información del producto para el mensaje
    $stmt = $conn->prepare("SELECT name FROM product WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    $product_name = $product ? $product['name'] : 'Producto';
    
    if ($action === 'remove') {
        // Eliminar el producto del carrito
        $stmt = $conn->prepare("DELETE FROM shopping_card_item WHERE shopping_card_id = ? AND product_id = ?");
        $stmt->execute([$shopping_card_id, $product_id]);
        logToConsole("Producto ID: $product_id eliminado del carrito");
        
        // Actualizar el total del carrito
        $stmt = $conn->prepare("
            SELECT SUM(p.price * sci.quantity) as total
            FROM shopping_card_item sci
            JOIN product p ON sci.product_id = p.product_id
            WHERE sci.shopping_card_id = ?
        ");
        $stmt->execute([$shopping_card_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = $result['total'] ?? 0;
        
        logToConsole("Total del carrito actualizado: $total");
        
        $stmt = $conn->prepare("UPDATE shopping_card SET total_amount = ? WHERE shopping_card_id = ?");
        $stmt->execute([$total, $shopping_card_id]);
        
        // Mensaje de éxito
        $_SESSION['cart_message'] = [
            'type' => 'success',
            'text' => $product_name . ' ha sido eliminado de tu carrito'
        ];
    }
} catch (PDOException $e) {
    // Si hay algún error, revertir la transacción
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Registrar y mostrar mensaje de error
    $errorMsg = "Error al actualizar el carrito: " . $e->getMessage();
    logToConsole($errorMsg);
    
    // Guardar mensaje de error en la sesión
    $_SESSION['cart_message'] = [
        'type' => 'error',
        'text' => $errorMsg
    ];
}

// Redirigir de vuelta al carrito
header('Location: cart.php');
exit;
?> 