<?php
session_start();
require 'config/db.php';

// Obtener detalles del producto por ID
if (isset($_GET['id'])) {
    $product_id = $_GET['id'];
    try {
        $stmt = $conn->prepare("SELECT p.*, 
            (SELECT pi.image_url FROM product_image pi WHERE pi.product_id = p.product_id LIMIT 1) AS image_url
            FROM product p WHERE p.product_id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error al recuperar el producto: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Producto - Lunette</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <script src="js/scripts.js" defer></script>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="logo">Lunette</div>
    <ul class="nav-links nav-main">
        <li><a href="index.php">Inicio</a></li>
        <li><a href="#categorias">Categorías</a></li>
        <li><a href="#productos">Productos</a></li>
        <li><a href="cart.php" class="cart-link">🛒 Carrito</a></li>
        
    </ul>
    <ul class="nav-links nav-user">
        <?php if (isset($_SESSION['user_id'])): ?>
            <li><a href="login.php">Cerrar Sesión</a></li>
        <?php else: ?>
            <li><a href="login.php">Iniciar Sesión</a></li>
            <li><a href="register.php">Crear Cuenta</a></li>
        <?php endif; ?>
    </ul>
</nav>

<!-- Product Details -->
<section class="main-container">
    <div class="product-details">
        <?php if ($product): ?>
            <div class="product-image">
            <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
            </div>
            <div class="product-info">
                <h2><?= htmlspecialchars($product['name']) ?></h2>
                <p><?= htmlspecialchars($product['description']) ?></p>
                <p class="product-price">S/ <?= number_format($product['price'], 2) ?></p>
                
                <!-- Formulario para seleccionar cantidad y añadir al carrito -->
                <form method="POST" action="add_to_cart.php">
                    <input type="hidden" name="product_id" value="<?= $product['product_id'] ?>">
                    
                    <!-- Selector de cantidad -->
                    <div class="quantity-control">
                        <button type="button" class="quantity-btn" onclick="decrementQuantity()">-</button>
                        <input type="number" name="quantity" id="quantity" value="1" min="1" class="quantity-input" onchange="updateQuantity()">
                        <button type="button" class="quantity-btn" onclick="incrementQuantity()">+</button>
                    </div>
                    <button type="submit" class="btn-carrito">Añadir al Carrito</button>
                </form>
            </div>
        <?php else: ?>
            <p>Producto no encontrado.</p>
        <?php endif; ?>
    </div>
</section>

<script>
    // Funciones para incrementar y decrementar la cantidad del producto
    function incrementQuantity() {
        var quantityInput = document.getElementById("quantity");
        quantityInput.value = parseInt(quantityInput.value) + 1;
    }

    function decrementQuantity() {
        var quantityInput = document.getElementById("quantity");
        if (parseInt(quantityInput.value) > 1) {
            quantityInput.value = parseInt(quantityInput.value) - 1;
        }
    }

    // Actualizar la cantidad del producto
    function updateQuantity() {
        var quantityInput = document.getElementById("quantity");
        if (parseInt(quantityInput.value) < 1) {
            quantityInput.value = 1;
        }
    }
</script>

</body>
</html>
