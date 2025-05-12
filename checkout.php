<?php
session_start();
require 'config/db.php';

// Habilitar el registro de errores
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'errors.log');

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=cart.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$cart_items = [];
$subtotal_seleccionados = 0;
$shipping_cost = 0;
$total = 0;
$type_cards = [];
$user_addresses = [];
$user_cards = [];
$current_step = isset($_GET['step']) ? intval($_GET['step']) : 1;

if (!isset($_GET['step'])) {
    header('Location: checkout.php?step=1');
    exit;
}

// Obtener el carrito del usuario
$stmt = $conn->prepare("SELECT sc.shopping_card_id, sc.total_amount FROM shopping_card sc WHERE sc.user_id = ?");
$stmt->execute([$user_id]);
$shopping_card = $stmt->fetch(PDO::FETCH_ASSOC);
error_log("Consulta carrito: " . $stmt->queryString); // Log de la consulta
error_log("Resultado carrito: " . json_encode($shopping_card)); // Log de los resultados

if (!$shopping_card) {
    $_SESSION['cart_message'] = ['type' => 'error', 'text' => 'No tienes un carrito de compras activo'];
    header('Location: cart.php');
    exit;
}

$shopping_card_id = $shopping_card['shopping_card_id'];

// Obtener los items seleccionados
$stmt = $conn->prepare("SELECT sci.*, p.name, p.price, p.description, (SELECT pi.image_url FROM product_image pi WHERE pi.product_id = p.product_id LIMIT 1) AS image_url FROM shopping_card_item sci JOIN product p ON sci.product_id = p.product_id WHERE sci.shopping_card_id = ? AND sci.is_select = 1");
$stmt->execute([$shopping_card_id]);
$cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Consulta items carrito: " . $stmt->queryString); // Log de la consulta
error_log("Resultado items carrito: " . json_encode($cart_items)); // Log de los resultados

// Calcular totales
foreach ($cart_items as $item) {
    $subtotal_seleccionados += $item['price'] * $item['quantity'];
}

$total = $subtotal_seleccionados + $shipping_cost;

// Obtener tipos de tarjeta
$stmt = $conn->query("SELECT * FROM type_card");
$type_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Consulta tipos de tarjeta: " . $stmt->queryString); // Log de la consulta
error_log("Resultado tipos de tarjeta: " . json_encode($type_cards)); // Log de los resultados

// Obtener direcciones y tarjetas del usuario
$stmt = $conn->prepare("SELECT * FROM address WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Consulta direcciones: " . $stmt->queryString); // Log de la consulta
error_log("Resultado direcciones: " . json_encode($user_addresses)); // Log de los resultados

$stmt = $conn->prepare("SELECT c.*, tc.name as type_card_name FROM card c JOIN type_card tc ON c.type_card_id = tc.type_card_id WHERE c.user_id = ?");
$stmt->execute([$user_id]);
$user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log("Consulta tarjetas: " . $stmt->queryString); // Log de la consulta
error_log("Resultado tarjetas: " . json_encode($user_cards)); // Log de los resultados

// Paso 1: Procesar la dirección y tarjeta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 1) {
    try {
        $expiration_date = null;
        if (isset($_POST['expiration_month']) && isset($_POST['expiration_year'])) {
            $year_full = '20' . $_POST['expiration_year'];
            $expiration_date = "$year_full-{$_POST['expiration_month']}-01";
        }

        // Verificar si todos los campos necesarios están presentes
        if (empty($_POST['street_address']) || empty($_POST['city']) || empty($_POST['postal_code']) || empty($_POST['country'])) {
            throw new Exception("Faltan datos obligatorios en la dirección");
        }

        if (empty($_POST['card_number']) || empty($_POST['cvv']) || empty($_POST['cardholders_name']) || empty($_POST['expiration_month']) || empty($_POST['expiration_year'])) {
            throw new Exception("Faltan datos obligatorios en la tarjeta");
        }

        // Inserción de la nueva dirección
        $is_default = (count($user_addresses) == 0) ? 1 : 0; // Si es la única dirección, marcarla como predeterminada
        $stmt = $conn->prepare("INSERT INTO address (street_address, city, postal_code, country, specification, is_default, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['street_address'],
            $_POST['city'],
            $_POST['postal_code'],
            $_POST['country'],
            $_POST['specification'] ?? '', // Especificación adicional
            $is_default,
            $user_id
        ]);
        $address_id = $conn->lastInsertId(); // Obtener el ID de la nueva dirección
        error_log("Inserción dirección: " . json_encode($_POST)); // Log de lo que se envió
        error_log("ID dirección insertada: " . $address_id); // Log del ID insertado

        // Inserción de la nueva tarjeta
        $stmt = $conn->prepare("INSERT INTO card (number, cvv, cardholders_name, expiration_date, user_id, type_card_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['card_number'],
            $_POST['cvv'],
            $_POST['cardholders_name'],
            $expiration_date,
            $user_id,
            $_POST['type_card_id'] // Tipo de tarjeta seleccionado
        ]);
        $card_id = $conn->lastInsertId(); // Obtener el ID de la nueva tarjeta
        error_log("Inserción tarjeta: " . json_encode($_POST)); // Log de lo que se envió
        error_log("ID tarjeta insertada: " . $card_id); // Log del ID insertado

        // Crear la orden
        $stmt = $conn->prepare("INSERT INTO orders (date, total_amount, user_id, address_id, card_id) VALUES (NOW(), ?, ?, ?, ?)");
        $stmt->execute([$total, $user_id, $address_id, $card_id]);
        $order_id = $conn->lastInsertId(); // Obtener el ID de la nueva orden
        error_log("Inserción orden: " . json_encode($_SESSION['checkout_data'])); // Log de los datos de la orden
        error_log("ID orden insertada: " . $order_id); // Log del ID insertado

        // Guardar los datos de checkout en la sesión
        $_SESSION['checkout_data'] = [
            'address_id' => $address_id,
            'card_id' => $card_id,
            'order_id' => $order_id
        ];

        // Redirigir al paso 2 (confirmación)
        header('Location: checkout.php?step=2');
        exit;

    } catch (Exception $e) {
        error_log("Error en el paso 1: " . $e->getMessage());
        echo "<script>console.log('Error en el paso 1: " . $e->getMessage() . "');</script>";
        $_SESSION['cart_message'] = ['type' => 'error', 'text' => $e->getMessage()];
        header('Location: checkout.php?step=1');
        exit;
    }
}
?>



<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Checkout - Lunette</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        /* Estilos para la pasarela de pago */
        .checkout-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 2px 8px 0 rgba(189,101,113,0.1);
        }
        
        /* Pasos de checkout */
        .checkout-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .checkout-steps::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--color3);
            z-index: 1;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--color3);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 2;
        }
        
        .step.active {
            background: var(--color2);
        }
        
        .step.completed {
            background: var(--color5);
        }
        
        .step-label {
            text-align: center;
            font-size: 0.85rem;
            color: var(--color5);
            margin-top: 0.5rem;
            font-weight: 600;
        }
        
        /* Formularios */
        .form-section {
            margin-bottom: 2rem;
        }
        
        .form-section h3 {
            color: var(--color5);
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--color5);
        }
        
        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid var(--color3);
            border-radius: 0.5rem;
            font-size: 1rem;
        }
        
        .radio-group {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        
        .radio-group input {
            margin-right: 0.5rem;
        }
        
        .card-list, .address-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .card-item, .address-item {
            border: 1px solid var(--color3);
            padding: 1rem;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .card-item:hover, .address-item:hover,
        .card-item.selected, .address-item.selected {
            border-color: var(--color2);
            box-shadow: 0 2px 8px 0 rgba(189,101,113,0.2);
        }
        
        .card-item h4, .address-item h4 {
            color: var(--color5);
            margin-bottom: 0.5rem;
        }
        
        /* Botones */
        .button-group {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }
        
        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
            border: none;
        }
        
        .btn-back {
            background: #f0f0f0;
            color: var(--color5);
        }
        
        .btn-back:hover {
            background: #e0e0e0;
        }
        
        .btn-next, .btn-confirm {
            background: var(--color2);
            color: #fff;
        }
        
        .btn-next:hover, .btn-confirm:hover {
            background: var(--color5);
        }
        
        /* Resumen de compra */
        .checkout-summary {
            margin-top: 2rem;
            border-top: 1px solid var(--color3);
            padding-top: 1rem;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .summary-total {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--color5);
            border-top: 1px solid var(--color3);
            padding-top: 0.5rem;
            margin-top: 0.5rem;
        }
        
        /* Productos en el resumen */
        .product-list {
            margin-top: 1rem;
        }
        
        .product-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--color3);
        }
        
        .product-item img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 0.5rem;
        }
        
        .product-details {
            flex: 1;
        }
        
        .product-name {
            font-weight: 600;
            color: var(--color5);
        }
        
        .product-price {
            color: var(--color2);
        }
        
        .product-quantity {
            font-size: 0.9rem;
            color: #666;
        }
        
        /* Confirmación */
        .confirmation {
            text-align: center;
            padding: 2rem;
        }
        
        .confirmation-icon {
            font-size: 4rem;
            color: var(--color2);
            margin-bottom: 1rem;
        }
        
        .confirmation h2 {
            color: var(--color5);
            margin-bottom: 1rem;
        }
        
        .confirmation p {
            margin-bottom: 1.5rem;
        }
        
        .order-details {
            background: #f9f9f9;
            padding: 1rem;
            border-radius: 0.5rem;
            max-width: 400px;
            margin: 0 auto;
            text-align: left;
        }
        
        .order-details p {
            margin-bottom: 0.5rem;
        }
        
        .order-details strong {
            color: var(--color5);
        }

        /* Estilos para fecha de expiración */
        .expiration-inputs {
            display: flex;
            align-items: center;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="logo">Lunette</div>
    <ul class="nav-links nav-main">
        <li><a href="index.php">Inicio</a></li>
        <li><a href="index.php#categorias">Categorías</a></li>
        <li><a href="index.php#productos">Productos</a></li>
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

<section class="main-container">
    <div class="checkout-container">
        <!-- Pasos del checkout -->
        <div class="checkout-steps">
            <div>
                <div class="step <?= $current_step >= 1 ? 'active' : '' ?> <?= $current_step > 1 ? 'completed' : '' ?>">1</div>
                <div class="step-label">Información de Pago</div>
            </div>
            <div>
                <div class="step <?= $current_step >= 2 ? 'active' : '' ?> <?= $current_step > 2 ? 'completed' : '' ?>">2</div>
                <div class="step-label">Resumen de Compra</div>
            </div>
            <div>
                <div class="step <?= $current_step >= 3 ? 'active' : '' ?>">3</div>
                <div class="step-label">Confirmación</div>
            </div>
        </div>
        
        <?php if ($current_step == 1): ?>
            <!-- Paso 1: Información de Pago y Dirección -->
            <h2>Información de Pago y Envío</h2>
            
            <form action="checkout.php" method="POST" id="checkoutForm">
    <input type="hidden" name="step" value="1">
    
    <!-- Sección de Dirección -->
    <div class="form-section">
        <h3>Dirección de Envío</h3>
        
        <div class="radio-group">
            <label for="new_address_1">
                <input type="radio" id="new_address_1" name="new_address" value="false" onchange="toggleAddressForm(false)" <?= count($user_addresses) > 0 ? 'checked' : '' ?>>
                Usar una dirección guardada
            </label>
            <label for="new_address_2">
                <input type="radio" id="new_address_2" name="new_address" value="true" onchange="toggleAddressForm(true)" <?= count($user_addresses) == 0 ? 'checked' : '' ?>>
                Agregar nueva dirección
            </label>
        </div>
        
        <div id="saved-addresses" style="<?= count($user_addresses) == 0 ? 'display: none;' : '' ?>">
            <?php if (count($user_addresses) > 0): ?>
                <div class="address-list">
                    <?php foreach ($user_addresses as $index => $address): ?>
                        <div class="address-item <?= $index === 0 ? 'selected' : '' ?>" onclick="selectAddress(this, <?= $address['address_id'] ?>)">
                            <h4><?= $address['city'] ?>, <?= $address['country'] ?></h4>
                            <p><?= $address['street_address'] ?></p>
                            <p>CP: <?= $address['postal_code'] ?></p>
                            <?php if ($address['specification']): ?>
                                <p><small><?= $address['specification'] ?></small></p>
                            <?php endif; ?>
                            <?php if ($address['is_default']): ?>
                                <p><strong>Dirección predeterminada</strong></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="address_id" id="selected-address-id" value="<?= $user_addresses[0]['address_id'] ?>">
            <?php else: ?>
                <p>No tienes direcciones guardadas.</p>
            <?php endif; ?>
        </div>
        
        <div id="new-address-form" style="<?= count($user_addresses) > 0 ? 'display: none;' : '' ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="street_address">Dirección</label>
                    <input type="text" class="form-control" id="street_address" name="street_address" autocomplete="address-line1">
                </div>
                <div class="form-group">
                    <label for="city">Ciudad</label>
                    <input type="text" class="form-control" id="city" name="city" autocomplete="address-level2">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="postal_code">Código Postal</label>
                    <input type="text" class="form-control" id="postal_code" name="postal_code" autocomplete="postal-code">
                </div>
                <div class="form-group">
                    <label for="country">País</label>
                    <input type="text" class="form-control" id="country" name="country" autocomplete="country">
                </div>
            </div>
            <div class="form-group">
                <label for="specification">Especificaciones adicionales (opcional)</label>
                <textarea class="form-control" id="specification" name="specification" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="save_address" value="true" checked>
                    Guardar esta dirección para futuras compras
                </label>
            </div>
        </div>
    </div>
    
    <!-- Sección de Tarjeta -->
    <div class="form-section">
        <h3>Información de Pago</h3>
        
        <div class="radio-group">
            <label for="new_card_1">
                <input type="radio" id="new_card_1" name="new_card" value="false" onchange="toggleCardForm(false)" <?= count($user_cards) > 0 ? 'checked' : '' ?>>
                Usar una tarjeta guardada
            </label>
            <label for="new_card_2">
                <input type="radio" id="new_card_2" name="new_card" value="true" onchange="toggleCardForm(true)" <?= count($user_cards) == 0 ? 'checked' : '' ?>>
                Agregar nueva tarjeta
            </label>
        </div>
        
        <div id="saved-cards" style="<?= count($user_cards) == 0 ? 'display: none;' : '' ?>">
            <?php if (count($user_cards) > 0): ?>
                <div class="card-list">
                    <?php foreach ($user_cards as $index => $card): ?>
                        <div class="card-item <?= $index === 0 ? 'selected' : '' ?>" onclick="selectCard(this, <?= $card['card_id'] ?>)">
                            <h4><?= $card['type_card_name'] ?></h4>
                            <p>**** **** **** <?= substr($card['number'], -4) ?></p>
                            <p><?= $card['cardholders_name'] ?></p>
                            <p>Vence: <?= date('m/y', strtotime($card['expiration_date'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="card_id" id="selected-card-id" value="<?= $user_cards[0]['card_id'] ?>">
            <?php else: ?>
                <p>No tienes tarjetas guardadas.</p>
            <?php endif; ?>
        </div>
        
        <div id="new-card-form" style="<?= count($user_cards) > 0 ? 'display: none;' : '' ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="type_card_id">Tipo de Tarjeta</label>
                    <select class="form-control" id="type_card_id" name="type_card_id">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($type_cards as $type): ?>
                            <option value="<?= $type['type_card_id'] ?>"><?= $type['name'] ?> - <?= $type['description'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="card_number">Número de Tarjeta</label>
                    <input type="text" class="form-control" id="card_number" name="card_number" placeholder="1234 5678 9012 3456" autocomplete="cc-number">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cardholders_name">Nombre del Titular</label>
                    <input type="text" class="form-control" id="cardholders_name" name="cardholders_name" autocomplete="cc-name">
                </div>
                <div class="form-group">
                    <label for="expiration_date">Fecha de Vencimiento (MM/AA)</label>
                    <div class="expiration-inputs">
                        <select class="form-control" id="expiration_month" name="expiration_month" required style="width: 80px; margin-right: 10px;" autocomplete="cc-exp-month">
                            <option value="">Mes</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= sprintf('%02d', $i) ?>"><?= sprintf('%02d', $i) ?></option>
                            <?php endfor; ?>
                        </select>
                        <span>/</span>
                        <select class="form-control" id="expiration_year" name="expiration_year" required style="width: 100px; margin-left: 10px;" autocomplete="cc-exp-year">
                            <option value="">Año</option>
                            <?php 
                            $current_year = date('Y');
                            for ($i = 0; $i < 10; $i++): 
                                $year = $current_year + $i;
                            ?>
                                <option value="<?= substr($year, 2) ?>"><?= $year ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cvv">CVV</label>
                    <input type="text" class="form-control" id="cvv" name="cvv" placeholder="123" maxlength="4" style="width: 100px;" autocomplete="cc-csc">
                </div>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="save_card" value="true" checked>
                    Guardar esta tarjeta para futuras compras
                </label>
            </div>
        </div>
    </div>

    <div class="checkout-summary">
        <h3>Resumen de Compra</h3>
        <div class="summary-item">
            <span>Subtotal (<?= count($cart_items) ?> productos):</span>
            <span>S/ <?= number_format($subtotal_seleccionados, 2) ?></span>
        </div>
        <div class="summary-item">
            <span>Envío:</span>
            <span>S/ <?= number_format($shipping_cost, 2) ?></span>
        </div>
        <div class="summary-total">
            <span>Total:</span>
            <span>S/ <?= number_format($total, 2) ?></span>
        </div>
    </div>
    
    <div class="button-group">
        <a href="cart.php" class="btn btn-back">Volver al Carrito</a>
        <button type="submit" class="btn btn-next">Continuar</button>
    </div>
</form>

        <?php elseif ($current_step == 2): ?>
            <!-- Paso 2: Resumen de Compra -->
            <h2>Resumen de Compra</h2>
            
            <?php
            // Recuperar datos del checkout
            $checkout_data = $_SESSION['checkout_data'];
            
            // Obtener detalles de la dirección
            if ($checkout_data['new_address']) {
                $address_details = [
                    'street_address' => $checkout_data['street_address'],
                    'city' => $checkout_data['city'],
                    'postal_code' => $checkout_data['postal_code'],
                    'country' => $checkout_data['country'],
                    'specification' => $checkout_data['specification']
                ];
            } else {
                $address_id = $checkout_data['address_id'];
                foreach ($user_addresses as $addr) {
                    if ($addr['address_id'] == $address_id) {
                        $address_details = $addr;
                        break;
                    }
                }
            }
            
            // Obtener detalles de la tarjeta
            if ($checkout_data['new_card']) {
                $card_details = [
                    'type_name' => '',
                    'number' => $checkout_data['card_number'],
                    'cardholders_name' => $checkout_data['cardholders_name'],
                    'expiration_date' => $checkout_data['expiration_date']
                ];
                
                // Obtener nombre del tipo de tarjeta
                foreach ($type_cards as $type) {
                    if ($type['type_card_id'] == $checkout_data['type_card_id']) {
                        $card_details['type_name'] = $type['name'];
                        break;
                    }
                }
            } else {
                $card_id = $checkout_data['card_id'];
                foreach ($user_cards as $c) {
                    if ($c['card_id'] == $card_id) {
                        $card_details = [
                            'type_name' => $c['type_card_name'],
                            'number' => $c['number'],
                            'cardholders_name' => $c['cardholders_name'],
                            'expiration_date' => $c['expiration_date']
                        ];
                        break;
                    }
                }
            }
            ?>
            
            <div class="checkout-container">
                <div class="form-section">
                    <h3>Dirección de Envío</h3>
                    <div class="address-item selected">
                        <p><strong><?= $address_details['street_address'] ?></strong></p>
                        <p><?= $address_details['city'] ?>, <?= $address_details['country'] ?></p>
                        <p>CP: <?= $address_details['postal_code'] ?></p>
                        <?php if (!empty($address_details['specification'])): ?>
                            <p><small><?= $address_details['specification'] ?></small></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Método de Pago</h3>
                    <div class="card-item selected">
                        <h4><?= $card_details['type_name'] ?></h4>
                        <p>**** **** **** <?= substr($card_details['number'], -4) ?></p>
                        <p><?= $card_details['cardholders_name'] ?></p>
                        <p>Vence: <?= date('m/y', strtotime($card_details['expiration_date'])) ?></p>
                    </div>
                </div>
                
                <div class="form-section">
        <h3>Productos</h3>
                    <div class="product-list">
                        <?php foreach ($cart_items as $item): ?>
                            <div class="product-item">
                                <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                                <div class="product-details">
                                    <div class="product-name"><?= htmlspecialchars($item['name']) ?></div>
                                    <div class="product-price">S/ <?= number_format($item['price'], 2) ?></div>
                                    <div class="product-quantity">Cantidad: <?= $item['quantity'] ?></div>
                                </div>
                                <div class="product-total">
                                    S/ <?= number_format($item['price'] * $item['quantity'], 2) ?>
                                </div>
            </div>
        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="checkout-summary">
                    <div class="summary-item">
                        <span>Subtotal (<?= count($cart_items) ?> productos):</span>
                        <span>S/ <?= number_format($subtotal_seleccionados, 2) ?></span>
                    </div>
                    <div class="summary-item">
                        <span>Envío:</span>
                        <span>S/ <?= number_format($shipping_cost, 2) ?></span>
                    </div>
                    <div class="summary-total">
            <span>Total:</span>
            <span>S/ <?= number_format($total, 2) ?></span>
                    </div>
        </div>

                <form action="checkout.php" method="POST">
                    <input type="hidden" name="step" value="2">
                    <div class="button-group">
                        <a href="checkout.php?step=1" class="btn btn-back">Volver</a>
                        <button type="submit" class="btn btn-confirm">Confirmar Compra</button>
                    </div>
        </form>
            </div>
        <?php elseif ($current_step == 3): ?>
            <!-- Paso 3: Confirmación -->
            <?php
            // Obtener el ID de la orden de la sesión
            $order_id = isset($_SESSION['order_id']) ? $_SESSION['order_id'] : null;
            
            // Obtener los detalles de la orden
            $order_details = null;
            if ($order_id) {
                $stmt = $conn->prepare("
                    SELECT o.*, a.street_address, a.city, a.country 
                    FROM orders o
                    JOIN address a ON o.address_id = a.address_id
                    WHERE o.orders_id = ? AND o.user_id = ?
                ");
                $stmt->execute([$order_id, $user_id]);
                $order_details = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            ?>
            
            <div class="confirmation">
                <div class="confirmation-icon">✓</div>
                <h2>¡Compra Realizada con Éxito!</h2>
                <p>Gracias por tu compra. Pronto recibirás un correo electrónico con los detalles de tu pedido.</p>
                
                <?php if ($order_details): ?>
                    <div class="order-details">
                        <p><strong>Número de Pedido:</strong> <?= $order_id ?></p>
                        <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($order_details['date'])) ?></p>
                        <p><strong>Total:</strong> S/ <?= number_format($order_details['total_amount'], 2) ?></p>
                        <p><strong>Dirección de Envío:</strong> <?= $order_details['street_address'] ?>, <?= $order_details['city'] ?>, <?= $order_details['country'] ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="button-group" style="justify-content: center; margin-top: 2rem;">
                    <a href="index.php" class="btn btn-next">Volver a la Tienda</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
// Función para manejar la selección de dirección
function selectAddress(element, addressId) {
    // Eliminar la selección previa
    const items = document.querySelectorAll('.address-item');
    items.forEach(item => item.classList.remove('selected'));
    
    // Marcar como seleccionado
    element.classList.add('selected');
    
    // Actualizar el valor del input escondido
    document.getElementById('selected-address-id').value = addressId;
}

// Función para manejar la selección de tarjeta
function selectCard(element, cardId) {
    // Eliminar la selección previa
    const items = document.querySelectorAll('.card-item');
    items.forEach(item => item.classList.remove('selected'));
    
    // Marcar como seleccionado
    element.classList.add('selected');
    
    // Actualizar el valor del input escondido
    document.getElementById('selected-card-id').value = cardId;
}

// Función para mostrar/ocultar formulario de dirección
function toggleAddressForm(showNew) {
    const savedAddresses = document.getElementById('saved-addresses');
    const newAddressForm = document.getElementById('new-address-form');
    
    if (showNew) {
        savedAddresses.style.display = 'none';
        newAddressForm.style.display = 'block';
        // Hacer que los campos sean requeridos
        const inputs = newAddressForm.querySelectorAll('input[type="text"]');
        inputs.forEach(input => {
            if (input.id !== 'specification') {
                input.required = true;
            }
        });
    } else {
        savedAddresses.style.display = 'block';
        newAddressForm.style.display = 'none';
        // Eliminar required de los campos
        const inputs = newAddressForm.querySelectorAll('input[type="text"]');
        inputs.forEach(input => {
            input.required = false;
        });
    }
}

// Función para mostrar/ocultar formulario de tarjeta
function toggleCardForm(showNew) {
    const savedCards = document.getElementById('saved-cards');
    const newCardForm = document.getElementById('new-card-form');
    
    if (showNew) {
        savedCards.style.display = 'none';
        newCardForm.style.display = 'block';
        // Hacer que los campos sean requeridos
        const inputs = newCardForm.querySelectorAll('input[type="text"], select');
        inputs.forEach(input => {
            input.required = true;
        });
    } else {
        savedCards.style.display = 'block';
        newCardForm.style.display = 'none';
        // Eliminar required de los campos
        const inputs = newCardForm.querySelectorAll('input[type="text"], select');
        inputs.forEach(input => {
            input.required = false;
        });
    }
}

// Inicializar la primera dirección y tarjeta como seleccionadas
document.addEventListener('DOMContentLoaded', function() {
    const firstAddress = document.querySelector('.address-item');
    if (firstAddress) {
        firstAddress.classList.add('selected');
    }
    
    const firstCard = document.querySelector('.card-item');
    if (firstCard) {
        firstCard.classList.add('selected');
    }
});
</script>

</body>
</html>
