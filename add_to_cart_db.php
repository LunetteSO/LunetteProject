<?php
session_start();
require 'config/db.php';

// Verificar si hay un producto_id en la solicitud
if (!isset($_GET['product_id']) && !isset($_POST['product_id'])) {
    header('Location: index.php');
    exit;
}

// Obtener el ID del producto de GET o POST
$product_id = isset($_GET['product_id']) ? $_GET['product_id'] : $_POST['product_id'];
$quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    // Si no está logueado, redirigir a la página de login
    header('Location: login.php?redirect=product.php?id=' . $product_id);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Verificar si el producto existe
    $stmt = $conn->prepare("SELECT * FROM product WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        // Si el producto no existe, redirigir a la página de productos
        header('Location: index.php');
        exit;
    }
    
    // Verificar si el usuario ya tiene un carrito
    $stmt = $conn->prepare("SELECT * FROM shopping_card WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $shopping_card = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Iniciar una transacción
    $conn->beginTransaction();
    
    if (!$shopping_card) {
        // Si no tiene carrito, crear uno nuevo
        $stmt = $conn->prepare("INSERT INTO shopping_card (user_id, total_amount) VALUES (?, 0)");
        $stmt->execute([$user_id]);
        $shopping_card_id = $conn->lastInsertId();
    } else {
        $shopping_card_id = $shopping_card['shopping_card_id'];
    }
    
    // Verificar si el producto ya está en el carrito
    $stmt = $conn->prepare("SELECT * FROM shopping_card_item WHERE shopping_card_id = ? AND product_id = ?");
    $stmt->execute([$shopping_card_id, $product_id]);
    $existing_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_item) {
        // Si el producto ya está en el carrito, actualizar la cantidad
        $new_quantity = $existing_item['quantity'] + $quantity;
        $stmt = $conn->prepare("UPDATE shopping_card_item SET quantity = ?, is_select = 1 WHERE shopping_card_item_id = ?");
        $stmt->execute([$new_quantity, $existing_item['shopping_card_item_id']]);
    } else {
        // Si el producto no está en el carrito, añadirlo
        $stmt = $conn->prepare("INSERT INTO shopping_card_item (shopping_card_id, product_id, quantity, is_select) VALUES (?, ?, ?, 1)");
        $stmt->execute([$shopping_card_id, $product_id, $quantity]);
    }
    
    // Actualizar el monto total del carrito
    $stmt = $conn->prepare("
        SELECT SUM(p.price * sci.quantity) as total 
        FROM shopping_card_item sci 
        JOIN product p ON sci.product_id = p.product_id 
        WHERE sci.shopping_card_id = ?
    ");
    $stmt->execute([$shopping_card_id]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $conn->prepare("UPDATE shopping_card SET total_amount = ? WHERE shopping_card_id = ?");
    $stmt->execute([$total, $shopping_card_id]);
    
    // Confirmar la transacción
    $conn->commit();
    
    // Redirigir al carrito
    header('Location: cart.php');
    exit;
    
} catch (PDOException $e) {
    // Si hay algún error, revertir la transacción
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Mostrar mensaje de error
    die("Error al añadir producto al carrito: " . $e->getMessage());
}
?> 