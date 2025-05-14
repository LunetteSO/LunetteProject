<?php
session_start();
require 'config/db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function logToConsole($message) {
    echo "<script>console.log('PHP Debug: " . str_replace("'", "\'", $message) . "');</script>";
}

$usuario_logueado = isset($_SESSION['user_id']);
$cart_empty = true;
$cart_items = [];
$total = 0;
$subtotal_seleccionados = 0;
$shopping_card_id = 0;

$cart_message = isset($_SESSION['cart_message']) ? $_SESSION['cart_message'] : null;
if (isset($_SESSION['cart_message'])) {
    unset($_SESSION['cart_message']);
}

if ($usuario_logueado) {
    try {
        $user_id = $_SESSION['user_id'];
    
        $stmt = $conn->prepare("
            SELECT sc.shopping_card_id, sc.total_amount
            FROM shopping_card sc
            WHERE sc.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $shopping_card = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($shopping_card) {
            $shopping_card_id = $shopping_card['shopping_card_id'];
            logToConsole("Carrito encontrado: " . json_encode($shopping_card));

            $stmt = $conn->prepare("
                SELECT sci.*, p.name, p.price, p.description,
                (SELECT pi.image_url FROM product_image pi WHERE pi.product_id = p.product_id LIMIT 1) AS image_url
                FROM shopping_card_item sci
                JOIN product p ON sci.product_id = p.product_id
                WHERE sci.shopping_card_id = ?
            ");
            $stmt->execute([$shopping_card_id]);
            $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            logToConsole("Items del carrito: " . json_encode($cart_items));
            
            if (count($cart_items) > 0) {
                $cart_empty = false;
                $total = $shopping_card['total_amount'];
                
                foreach ($cart_items as $item) {
                    if ($item['is_select'] == 1) {
                        $subtotal_seleccionados += $item['price'] * $item['quantity'];
                    }
                }
            }
        } else {
            logToConsole("No se encontró carrito para el usuario ID: " . $user_id);
        }
    } catch (PDOException $e) {
        $errorMsg = "Error al obtener el carrito: " . $e->getMessage();
        logToConsole($errorMsg);
    }
} else {
    logToConsole("Usuario no logueado");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carrito de Compras - Lunette</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        .cart-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 2rem;
        }
 
        .item-select {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid var(--color2);
            margin-right: 10px;
            position: relative;
            cursor: pointer;
            display: inline-block;
        }
        
        .item-select::after {
            content: '';
            position: absolute;
            top: 4px;
            left: 4px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--color2);
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        
        .item-select.selected::after {
            opacity: 1;
        }
        
        .cart-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            animation: fadeIn 0.5s, fadeOut 0.5s 5s forwards;
        }
        
        .cart-message.success {
            background-color: rgba(240, 165, 180, 0.2);
            color: var(--color5);
            border: 1px solid var(--color1);
        }
        
        .cart-message.error {
            background-color: rgba(255, 99, 71, 0.2);
            color: #d32f2f;
            border: 1px solid #f44336;
        }
        
        .cart-summary {
            background: #fff;
            color: var(--color5);
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 2px 8px 0 rgba(189,101,113,0.1);
            position: sticky;
            top: 2rem;
            height: fit-content;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 0.8rem 0;
        }
        
        .summary-row.total {
            border-top: 1px solid var(--color3);
            padding-top: 1rem;
            margin-top: 1rem;
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        @media (max-width: 900px) {
            .cart-container {
                grid-template-columns: 1fr;
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    </style>
    <script src="js/scripts.js" defer></script>
</head>
<body>


<nav class="navbar">
    <div class="logo">Lunette</div>
    <ul class="nav-links nav-main">
        <li><a href="index.php">Inicio</a></li>
        <li><a href="index.php#categorias">Categorías</a></li>
        <li><a href="index.php#productos">Productos</a></li>
        <li><a href="cart.php" class="cart-link">🛒 Carrito</a></li>
    </ul>
    <ul class="nav-links nav-user">
        <?php if ($usuario_logueado): ?>
            <li><a href="logout.php">Cerrar Sesión</a></li>
        <?php else: ?>
            <li><a href="login.php">Iniciar Sesión</a></li>
            <li><a href="register.php">Crear Cuenta</a></li>
        <?php endif; ?>
    </ul>
</nav>


<section class="main-container">
    <h1 class="cart-title">Tu Carrito de Compras</h1>
    
    <?php if ($cart_message): ?>
        <div class="cart-message <?= $cart_message['type'] ?>">
            <?= $cart_message['text'] ?>
        </div>
    <?php endif; ?>

    <?php if ($cart_empty): ?>
        <p>Tu carrito está vacío. ¡Añade algunos productos!</p>
        <a href="index.php" class="continue-shopping">Continuar Comprando</a>
    <?php else: ?>
        <div class="cart-container">
            <div class="cart-items">
                <?php foreach ($cart_items as $item): ?>
                    <div class="cart-item">
                        <div class="item-select <?= $item['is_select'] ? 'selected' : '' ?>" 
                             onclick="toggleSelect(<?= $item['product_id'] ?>, <?= $shopping_card_id ?>)"></div>
                        <img class="item-image" src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                        <div class="item-details">
                            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="item-price">S/ <?= number_format($item['price'], 2) ?></div>
                            <div class="quantity-control">
                                <button type="button" class="quantity-btn" 
                                        onclick="decrementQuantity(<?= $item['product_id'] ?>, <?= $shopping_card_id ?>)">-</button>
                                <input type="number" name="quantity" value="<?= $item['quantity'] ?>" min="1" class="quantity-input" 
                                       id="quantity-<?= $item['product_id'] ?>" onchange="updateQuantity(<?= $item['product_id'] ?>, <?= $shopping_card_id ?>)">
                                <button type="button" class="quantity-btn" 
                                        onclick="incrementQuantity(<?= $item['product_id'] ?>, <?= $shopping_card_id ?>)">+</button>
                            </div>
                            <button type="button" class="remove-btn" 
                                    onclick="removeItem(<?= $item['product_id'] ?>, <?= $shopping_card_id ?>)">Eliminar</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="cart-summary">
                <h2 class="summary-title">Resumen de la Compra</h2>
                <div class="summary-row">
                    <span>Subtotal (<?= count($cart_items) ?> productos):</span>
                    <span>S/ <?= number_format($total, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Artículos seleccionados:</span>
                    <span id="subtotal-seleccionados">S/ <?= number_format($subtotal_seleccionados, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>Envío:</span>
                    <span>Gratis</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="total-compra">S/ <?= number_format($subtotal_seleccionados, 2) ?></span>
                </div>
                <button class="checkout-btn" onclick="window.location.href='checkout.php'">Realizar Compra</button>
            </div>
        </div>
    <?php endif; ?>
</section>

<script>
function incrementQuantity(productId, shoppingCardId) {
    const input = document.getElementById(`quantity-${productId}`);
    input.value = parseInt(input.value) + 1;
    updateQuantity(productId, shoppingCardId);
}

function decrementQuantity(productId, shoppingCardId) {
    const input = document.getElementById(`quantity-${productId}`);
    if (parseInt(input.value) > 1) {
        input.value = parseInt(input.value) - 1;
        updateQuantity(productId, shoppingCardId);
    }
}

function updateQuantity(productId, shoppingCardId) {
    const quantity = document.getElementById(`quantity-${productId}`).value;
    
    const formData = new FormData();
    formData.append('product_id', productId);
    formData.append('shopping_card_id', shoppingCardId);
    formData.append('quantity', quantity);
    formData.append('action', 'update_quantity');
    
    fetch('update_cart_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.summary-row')[0].querySelector('span:last-child').textContent = `S/ ${data.total.toFixed(2)}`;
            document.getElementById('subtotal-seleccionados').textContent = `S/ ${data.subtotal_seleccionados.toFixed(2)}`;
            document.getElementById('total-compra').textContent = `S/ ${data.subtotal_seleccionados.toFixed(2)}`;
        } else {
            console.error('Error al actualizar cantidad:', data.message);
        }
    })
    .catch(error => {
        console.error('Error en la petición:', error);
    });
}

function toggleSelect(productId, shoppingCardId) {
    const selector = event.target;
    const isCurrentlySelected = selector.classList.contains('selected');
    const newState = isCurrentlySelected ? 0 : 1;
    
    const formData = new FormData();
    formData.append('product_id', productId);
    formData.append('shopping_card_id', shoppingCardId);
    formData.append('is_select', newState);
    formData.append('action', 'toggle_select');
    
    fetch('update_cart_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (newState === 1) {
                selector.classList.add('selected');
            } else {
                selector.classList.remove('selected');
            }
            
            document.getElementById('subtotal-seleccionados').textContent = `S/ ${data.subtotal_seleccionados.toFixed(2)}`;
            document.getElementById('total-compra').textContent = `S/ ${data.subtotal_seleccionados.toFixed(2)}`;
        } else {
            console.error('Error al cambiar selección:', data.message);
        }
    })
    .catch(error => {
        console.error('Error en la petición:', error);
    });
}

function removeItem(productId, shoppingCardId) {
    if (confirm('¿Estás seguro de que deseas eliminar este producto del carrito?')) {
        window.location.href = `update_cart.php?action=remove&product_id=${productId}&shopping_card_id=${shoppingCardId}`;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const message = document.querySelector('.cart-message');
    if (message) {
        setTimeout(function() {
            message.style.display = 'none';
        }, 5000);
    }
});
</script>

</body>
</html>
