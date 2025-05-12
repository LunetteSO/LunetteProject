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

// Calcular totales
foreach ($cart_items as $item) {
    $subtotal_seleccionados += $item['price'] * $item['quantity'];
}

$total = $subtotal_seleccionados + $shipping_cost;

// Obtener tipos de tarjeta
$stmt = $conn->query("SELECT * FROM type_card");
$type_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener direcciones y tarjetas del usuario
$stmt = $conn->prepare("SELECT * FROM address WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT c.*, tc.name as type_card_name FROM card c JOIN type_card tc ON c.type_card_id = tc.type_card_id WHERE c.user_id = ?");
$stmt->execute([$user_id]);
$user_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mensaje de resultado
$message = null;

// Procesamiento del formulario para guardar en BD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = null;
    try {
        $conn->beginTransaction();
        $saved_data = [];
        
        // Procesar dirección
        if (isset($_POST['address_option']) && $_POST['address_option'] == 'new') {
            // Verificar que los campos obligatorios estén presentes
            if (empty($_POST['street']) || empty($_POST['city']) || empty($_POST['postal_code']) || empty($_POST['country'])) {
                throw new Exception("Todos los campos de dirección marcados con * son obligatorios");
            }
            
            // Nueva dirección
            $stmt = $conn->prepare("INSERT INTO address (street_address, city, postal_code, country, specification, is_default, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['street'],
                $_POST['city'],
                $_POST['postal_code'],
                $_POST['country'],
                $_POST['specification'] ?? '',
                0, // is_default = 0
                $user_id
            ]);
            $address_id = $conn->lastInsertId();
            $saved_data['address'] = [
                'id' => $address_id,
                'type' => 'nueva',
                'street' => $_POST['street'],
                'city' => $_POST['city']
            ];
        } else {
            // Usar dirección existente si hay disponible
            if (isset($_POST['selected-address-id']) && !empty($_POST['selected-address-id'])) {
                $address_id = $_POST['selected-address-id'];
                
                // Encontrar los detalles de la dirección seleccionada
                $selected_address = null;
                foreach ($user_addresses as $addr) {
                    if ($addr['address_id'] == $address_id) {
                        $selected_address = $addr;
                        break;
                    }
                }
                
                $saved_data['address'] = [
                    'id' => $address_id,
                    'type' => 'existente',
                    'street' => $selected_address ? $selected_address['street_address'] : 'Dirección seleccionada',
                    'city' => $selected_address ? $selected_address['city'] : ''
                ];
            } else {
                // No hay dirección seleccionada, verificar si hay direcciones disponibles
                if (count($user_addresses) > 0) {
                    $address_id = $user_addresses[0]['address_id'];
                    $saved_data['address'] = [
                        'id' => $address_id,
                        'type' => 'existente (predeterminada)',
                        'street' => $user_addresses[0]['street_address'],
                        'city' => $user_addresses[0]['city']
                    ];
                } else {
                    throw new Exception("No hay dirección disponible. Por favor, añade una dirección.");
                }
            }
        }
        
        // Procesar tarjeta
        if (isset($_POST['payment_option']) && $_POST['payment_option'] == 'new') {
            // Verificar que los campos obligatorios estén presentes
            if (empty($_POST['card_number']) || empty($_POST['cvv']) || empty($_POST['card_name']) || 
                empty($_POST['exp_month']) || empty($_POST['exp_year']) || empty($_POST['type_card_id'])) {
                throw new Exception("Todos los campos de tarjeta marcados con * son obligatorios");
            }
            
            $card_number = $_POST['card_number'];
            $cvv = $_POST['cvv'];
            $card_name = $_POST['card_name'];
            $exp_month = $_POST['exp_month'];
            $exp_year = $_POST['exp_year'];
            $type_card_id = $_POST['type_card_id'];
            $save_card = isset($_POST['save_card']) ? true : false;
            
            // Verificar si debemos guardar la tarjeta o solo usarla para esta compra
            if ($save_card) {
                // Guardar la tarjeta en la base de datos
                $expiration_date = "20{$exp_year}-{$exp_month}-01";
                
                $stmt = $conn->prepare("INSERT INTO card (number, cvv, cardholders_name, expiration_date, user_id, type_card_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $card_number,
                    $cvv,
                    $card_name,
                    $expiration_date,
                    $user_id,
                    $type_card_id
                ]);
                $card_id = $conn->lastInsertId();
                
                // Encontrar el nombre del tipo de tarjeta
                $card_type_name = "";
                foreach ($type_cards as $type) {
                    if ($type['type_card_id'] == $type_card_id) {
                        $card_type_name = $type['name'];
                        break;
                    }
                }
            } else {
                // No guardar la tarjeta, pero usarla para esta compra
                $card_id = null; // Será null en la orden
                
                // Buscar el nombre del tipo de tarjeta para mostrarlo en el resumen
                $card_type_name = "";
                foreach ($type_cards as $type) {
                    if ($type['type_card_id'] == $type_card_id) {
                        $card_type_name = $type['name'];
                        break;
                    }
                }
            }
            
            // Guardar información de la tarjeta para el resumen
            $saved_data['card'] = [
                'id' => $card_id,
                'type' => $save_card ? 'nueva' : 'temporal',
                'number' => '****' . substr($card_number, -4),
                'holder' => $card_name,
                'card_type' => $card_type_name,
                'temporary_data' => $save_card ? null : [
                    'card_number' => $card_number,
                    'cvv' => $cvv,
                    'exp_month' => $exp_month,
                    'exp_year' => $exp_year,
                    'type_card_id' => $type_card_id
                ]
            ];
        } else {
            // Usar tarjeta existente si hay disponible
            if (isset($_POST['selected-card-id']) && !empty($_POST['selected-card-id'])) {
                $card_id = $_POST['selected-card-id'];
                
                // Encontrar los detalles de la tarjeta seleccionada
                $selected_card = null;
                foreach ($user_cards as $card) {
                    if ($card['card_id'] == $card_id) {
                        $selected_card = $card;
                        break;
                    }
                }
                
                $saved_data['card'] = [
                    'id' => $card_id,
                    'type' => 'existente',
                    'number' => $selected_card ? ('****' . substr($selected_card['number'], -4)) : 'Tarjeta seleccionada',
                    'holder' => $selected_card ? $selected_card['cardholders_name'] : '',
                    'card_type' => $selected_card ? $selected_card['type_card_name'] : ''
                ];
            } else {
                // No hay tarjeta seleccionada, verificar si hay tarjetas disponibles
                if (count($user_cards) > 0) {
                    $card_id = $user_cards[0]['card_id'];
                    $saved_data['card'] = [
                        'id' => $card_id,
                        'type' => 'existente (predeterminada)',
                        'number' => '****' . substr($user_cards[0]['number'], -4),
                        'holder' => $user_cards[0]['cardholders_name'],
                        'card_type' => $user_cards[0]['type_card_name']
                    ];
                } else {
                    throw new Exception("No hay tarjeta disponible. Por favor, añade una tarjeta.");
                }
            }
        }
        
        // Guardar información en la sesión para usarla después
        $_SESSION['checkout_data'] = [
            'address_id' => $address_id,
            'card_id' => $card_id,
            'saved_data' => $saved_data
        ];
        
        // Justo antes de las inserciones
        error_log("Address ID: " . $_SESSION['checkout_data']['address_id']);
        error_log("Card ID: " . $_SESSION['checkout_data']['card_id']);
        error_log("Total: " . $total);
        error_log("Número de productos: " . count($cart_items));
        
        $conn->commit();
        
        // Mensaje de éxito
        $message = [
            'type' => 'success',
            'text' => "¡Datos guardados con éxito! Se ha guardado la información de dirección y tarjeta."
        ];
        
        // Redirigir al siguiente paso
        header('Location: checkout.php?step=2');
        exit;
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollBack();
        }
        
        // Mensaje de error
        $message = [
            'type' => 'error',
            'text' => "Error al guardar datos: " . $e->getMessage()
        ];
    }
}

// Comprobar si se debe crear la orden
$redirect_to_step3 = false;
if (isset($_GET['action']) && $_GET['action'] == 'create_order') {
    try {
        // Obtener datos del checkout
        $checkout_data = $_SESSION['checkout_data'];
        $address_id = $checkout_data['address_id'];
        $card_id = $checkout_data['card_id'];
        
        // Verificar si es una tarjeta temporal (no guardada)
        $temporary_card_data = null;
        if ($card_id === null && isset($checkout_data['saved_data']['card']['temporary_data'])) {
            $temporary_card_data = $checkout_data['saved_data']['card']['temporary_data'];
        }
        
        // Insertar la orden en la base de datos
        $stmt = $conn->prepare("INSERT INTO orders (user_id, address_id, card_id, total_amount, date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $address_id, $card_id, $total]);
        $order_id = $conn->lastInsertId();
        $_SESSION['order_id'] = $order_id;
        
        // Si hay datos temporales de tarjeta, podríamos guardarlos en otra tabla o simplemente
        // usarlos para procesar el pago en este punto
        if ($temporary_card_data) {
            // Aquí se podrían usar los datos temporales para procesar el pago
            // pero no se guarda la tarjeta en la base de datos
            error_log("Usando tarjeta temporal (no guardada en BD): " . json_encode($temporary_card_data));
        }
        
        // Mover los items del carrito a la orden completada
        foreach ($cart_items as $item) {
            $stmt = $conn->prepare("INSERT INTO order_items (orders_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
            
            // Eliminar el ítem del carrito
            $stmt = $conn->prepare("DELETE FROM shopping_card_item WHERE shopping_card_item_id = ?");
            $stmt->execute([$item['shopping_card_item_id']]);
        }
        
        // Almacenar el ID de la orden en la sesión
        $_SESSION['order_id'] = $order_id;
        
        // En lugar de redireccionar con header, usaremos JavaScript
        $redirect_to_step3 = true;
        
    } catch (PDOException $e) {
        error_log("ERROR EN INSERCIÓN: " . $e->getMessage() . " - Código: " . $e->getCode());
        // Almacenar el error en la sesión
        $_SESSION['checkout_error'] = $e->getMessage();
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
        .AdressSection{
        padding: 2em 1em;
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
            width: 80%;
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
            text-decoration: none;
            display: inline-block;
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
        
        /* Mensajes */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Expiration inputs */
        .expiration-inputs {
            display: flex;
            align-items: center;
        }
        
        /* Estilos para validación */
        .highlight-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }
        
        .highlight-section {
            border: 2px solid #dc3545 !important;
            padding: 15px !important;
            border-radius: 8px !important;
            background-color: rgba(220, 53, 69, 0.05) !important;
        }
        
        /* Para el contenedor de mensajes de error */
        #form-error-container {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            color: #721c24;
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
        
        .product-total {
            font-weight: bold;
            color: var(--color5);
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
        
        /* Estilos para el resumen de compra */
        .order-summary {
            background-color: #fff;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px 0 rgba(189,101,113,0.1);
        }
        
        .order-summary h2 {
            color: var(--color5);
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--color3);
            padding-bottom: 0.8rem;
        }
        
        .summary-detail {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            font-size: 1.1rem;
        }
        
        .summary-detail:last-of-type {
            margin-bottom: 1.5rem;
        }
        
        .summary-total {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--color5);
            border-top: 1px solid var(--color3);
            padding-top: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .btn-back-cart {
            background: var(--color3);
            color: var(--color5);
            padding: 0.8rem 1.5rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            border: none;
            text-decoration: none;
            display: inline-block;
            margin-top: 1rem;
        }
        
        .btn-back-cart:hover {
            background: #e0e0e0;
            color: var(--color2);
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
        
        <!-- Mensajes -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $message['type'] ?>">
                <?= $message['text'] ?>
            </div>
        <?php endif; ?>
        
        <?php if ($current_step == 1): ?>
            <!-- Paso 1: Información de Pago y Dirección -->
            <h2>Información de Pago y Envío</h2>
            
            <form action="checkout.php?step=1" method="POST" id="checkoutForm">
                <!-- Formulario para la dirección de envío -->
                <div class="form-section">
                    <h3>Dirección de Envío</h3>
                    
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="address_option" value="existing" <?= count($user_addresses) > 0 ? 'checked' : '' ?> onchange="toggleAddressForm(false)">
                            Usar dirección guardada
                        </label>
                        <label>
                            <input type="radio" name="address_option" value="new" <?= count($user_addresses) == 0 ? 'checked' : '' ?> onchange="toggleAddressForm(true)">
                            Agregar nueva dirección
                        </label>
                    </div>
                    
                    <div id="saved-addresses" style="<?= count($user_addresses) == 0 ? 'display: none;' : '' ?>">
                        <?php if (count($user_addresses) > 0): ?>
                            <div class="address-list">
                                <?php foreach ($user_addresses as $index => $address): ?>
                                    <div class="address-item <?= $index === 0 ? 'selected' : '' ?>" onclick="selectAddress(this, <?= $address['address_id'] ?>)">
                                        <p><strong><?= htmlspecialchars($address['street_address']) ?></strong></p>
                                        <p><?= htmlspecialchars($address['city']) ?>, <?= htmlspecialchars($address['country']) ?></p>
                                        <p>CP: <?= htmlspecialchars($address['postal_code']) ?></p>
                                        <?php if (!empty($address['specification'])): ?>
                                            <p><small><?= htmlspecialchars($address['specification']) ?></small></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p>No tienes direcciones guardadas.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div id="new-address-form" style="<?= count($user_addresses) > 0 ? 'display: none;' : '' ?>">
                    <div class="AdressSection">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="street">Calle y número *</label>
                                <input type="text" id="street" name="street" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="city">Ciudad *</label>
                                <input type="text" id="city" name="city" class="form-control">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="country">País *</label>
                                <input type="text" id="country" name="country" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="postal_code">Código Postal *</label>
                                <input type="text" id="postal_code" name="postal_code" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="specification">Especificaciones adicionales (Apt, Piso, etc.)</label>
                            <input type="text" id="specification" name="specification" class="form-control">
                        </div>
                        </div>
                    </div>
                    
                    <input type="hidden" id="selected-address-id" name="selected-address-id" value="<?= (count($user_addresses) > 0) ? $user_addresses[0]['address_id'] : '0' ?>">
                </div>
                
                <!-- Formulario para la información de pago -->
                <div class="form-section">
                    <h3>Información de Pago</h3>
                    
                    <div class="radio-group payment-options">
                        <label>
                            <input type="radio" name="payment_option" value="existing" <?= count($user_cards) > 0 ? 'checked' : '' ?> onchange="toggleCardForm(false)">
                            Usar una tarjeta guardada
                        </label>
                        <label>
                            <input type="radio" name="payment_option" value="new" <?= count($user_cards) == 0 ? 'checked' : '' ?> onchange="toggleCardForm(true)">
                            Agregar nueva tarjeta
                        </label>
                    </div>
                    
                    <div id="saved-cards" style="<?= count($user_cards) == 0 ? 'display: none;' : '' ?>">
                        <?php if (count($user_cards) > 0): ?>
                            <div class="card-list">
                                <?php foreach ($user_cards as $index => $card): ?>
                                    <div class="card-item <?= $index === 0 ? 'selected' : '' ?>" onclick="selectCard(this, <?= $card['card_id'] ?>)">
                                        <h4><?= htmlspecialchars($card['type_card_name']) ?></h4>
                                        <p>**** **** **** <?= substr(htmlspecialchars($card['number']), -4) ?></p>
                                        <p><?= htmlspecialchars($card['cardholders_name']) ?></p>
                                        <p>Vence: <?= date('m/y', strtotime($card['expiration_date'])) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p>No tienes tarjetas guardadas.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div id="new-card-form" style="<?= count($user_cards) > 0 ? 'display: none;' : '' ?>">

                    <div class="AdressSection">
                        <div class="form-row">

                            <div class="form-group">
                                <label for="type_card_id">Tipo de Tarjeta</label>
                                <select id="type_card_id" name="type_card_id" class="form-control">
                                    <option value="" disabled selected>Seleccionar...</option>
                                    <?php foreach ($type_cards as $type): ?>
                                        <option value="<?= $type['type_card_id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="card_number">Número de Tarjeta</label>
                                <input type="text" id="card_number" name="card_number" class="form-control" placeholder="1234 5678 9012 3456">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="card_name">Nombre del Titular</label>
                                <input type="text" id="card_name" name="card_name" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="expiration">Fecha de Vencimiento (MM/AA)</label>
                                <div class="expiration-inputs">
                                    <select id="exp_month" name="exp_month" class="form-control month-select">
                                        <option value="" disabled selected>Mes</option>
                                        <?php for ($i = 1; $i <= 12; $i++): ?>
                                            <option value="<?= sprintf('%02d', $i) ?>"><?= sprintf('%02d', $i) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="expiration-separator">/</span>
                                    <select id="exp_year" name="exp_year" class="form-control year-select">
                                        <option value="" disabled selected>Año</option>
                                        <?php $year = date('Y'); for ($i = 0; $i <= 10; $i++): ?>
                                            <option value="<?= substr($year + $i, -2) ?>"><?= $year + $i ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group cvv-group">
                                <label for="cvv">CVV</label>
                                <input type="text" id="cvv" name="cvv" class="form-control" placeholder="123">
                            </div>
                        </div>
                       </div> 
                        <!-- Checkbox para guardar la tarjeta -->
                        <div class="form-group save-card-option">
                            <label class="checkbox-container">
                                <input type="checkbox" id="save_card" name="save_card" checked>
                                <span class="checkmark"></span>
                                Guardar esta tarjeta para futuras compras
                            </label>
                        </div>
                    </div>
                    
                    <input type="hidden" id="selected-card-id" name="selected-card-id" value="<?= (count($user_cards) > 0) ? $user_cards[0]['card_id'] : '0' ?>">
                </div>

                <div class="button-group">
                    <a href="cart.php" class="btn btn-back">Volver al Carrito</a>
                    <button type="submit" class="btn btn-next">Guardar y Continuar</button>
                </div>
            </form>

        <?php elseif ($current_step == 2): ?>
            <!-- Paso 2: Resumen de Compra -->
            <?php
            // Comprobar si se debe crear la orden
            $redirect_to_step3 = false;
            if (isset($_GET['action']) && $_GET['action'] == 'create_order') {
                try {
                    // Obtener datos del checkout
                    $checkout_data = $_SESSION['checkout_data'];
                    $address_id = $checkout_data['address_id'];
                    $card_id = $checkout_data['card_id'];
                    
                    // Verificar si es una tarjeta temporal (no guardada)
                    $temporary_card_data = null;
                    if ($card_id === null && isset($checkout_data['saved_data']['card']['temporary_data'])) {
                        $temporary_card_data = $checkout_data['saved_data']['card']['temporary_data'];
                    }
                    
                    // Insertar la orden en la base de datos
                    $stmt = $conn->prepare("INSERT INTO orders (user_id, address_id, card_id, total_amount, date) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->execute([$user_id, $address_id, $card_id, $total]);
                    $order_id = $conn->lastInsertId();
                    $_SESSION['order_id'] = $order_id;
                    
                    // Si hay datos temporales de tarjeta, podríamos guardarlos en otra tabla o simplemente
                    // usarlos para procesar el pago en este punto
                    if ($temporary_card_data) {
                        // Aquí se podrían usar los datos temporales para procesar el pago
                        // pero no se guarda la tarjeta en la base de datos
                        error_log("Usando tarjeta temporal (no guardada en BD): " . json_encode($temporary_card_data));
                    }
                    
                    // Mover los items del carrito a la orden completada
                    foreach ($cart_items as $item) {
                        $stmt = $conn->prepare("INSERT INTO order_items (orders_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
                        
                        // Eliminar el ítem del carrito
                        $stmt = $conn->prepare("DELETE FROM shopping_card_item WHERE shopping_card_item_id = ?");
                        $stmt->execute([$item['shopping_card_item_id']]);
                    }
                    
                    // Almacenar el ID de la orden en la sesión
                    $_SESSION['order_id'] = $order_id;
                    
                    // En lugar de redireccionar con header, usaremos JavaScript
                    $redirect_to_step3 = true;
                    
                } catch (PDOException $e) {
                    error_log("ERROR EN INSERCIÓN: " . $e->getMessage() . " - Código: " . $e->getCode());
                    // Almacenar el error en la sesión
                    $_SESSION['checkout_error'] = $e->getMessage();
                }
            }
            
            // Si debemos redireccionar, agregamos el script de JavaScript
            if ($redirect_to_step3): ?>
                <script>
                    window.location.href = 'checkout.php?step=3';
                </script>
            <?php
                // No hay necesidad de exit() aquí porque el JavaScript se encargará de la redirección
            endif;
            
            // Recuperar datos del checkout
            $checkout_data = $_SESSION['checkout_data'];
            $saved_data = $checkout_data['saved_data'];
            
            // Mostrar error si existe
            if (isset($_SESSION['checkout_error'])) {
                echo '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin: 15px 0; border: 1px solid #f5c6cb; border-radius: 5px;">';
                echo '<strong>ERROR AL CREAR LA ORDEN:</strong><br>';
                echo $_SESSION['checkout_error'];
                echo '</div>';
                unset($_SESSION['checkout_error']);
            }
            
            // Obtener detalles de la dirección y tarjeta
            $address_id = $checkout_data['address_id'];
            $selected_address = null;
            foreach ($user_addresses as $addr) {
                if ($addr['address_id'] == $address_id) {
                    $selected_address = $addr;
                    break;
                }
            }
            
            $card_id = $checkout_data['card_id'];
            $selected_card = null;
            foreach ($user_cards as $card) {
                if ($card['card_id'] == $card_id) {
                    $selected_card = $card;
                    break;
                }
            }
            ?>
            
            <div class="form-section">
                <h3>Dirección de Envío</h3>
                <div class="address-item selected">
                    <?php if (isset($saved_data['address'])): ?>
                        <p><strong><?= $saved_data['address']['street'] ?></strong></p>
                        <p><?= $saved_data['address']['city'] ?>, <?= $selected_address ? $selected_address['country'] : '' ?></p>
                        <p>CP: <?= $selected_address ? $selected_address['postal_code'] : '' ?></p>
                        <?php if ($selected_address && !empty($selected_address['specification'])): ?>
                            <p><small><?= $selected_address['specification'] ?></small></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-section">
                <h3>Método de Pago</h3>
                <div class="card-item selected">
                    <?php if (isset($saved_data['card'])): ?>
                        <?php if (!empty($saved_data['card']['card_type'])): ?>
                            <h4><?= $saved_data['card']['card_type'] ?></h4>
                        <?php endif; ?>
                        <p><?= $saved_data['card']['number'] ?></p>
                        <p><?= $saved_data['card']['holder'] ?></p>
                        <?php if ($selected_card): ?>
                            <p>Vence: <?= date('m/y', strtotime($selected_card['expiration_date'])) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
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
            
            <div class="order-summary">
                <h2>Resumen de Compra</h2>
                <div class="summary-detail">
                    <span>Subtotal (<?= count($cart_items) ?> productos):</span>
                    <span>S/ <?= number_format($subtotal_seleccionados, 2) ?></span>
                </div>
                <div class="summary-detail">
                    <span>Envío:</span>
                    <span>S/ <?= number_format($shipping_cost, 2) ?></span>
                </div>
                <div class="summary-total">
                    <span>Total:</span>
                    <span>S/ <?= number_format($total, 2) ?></span>
                </div>
            </div>

            <!-- Botón de confirmación -->
            <div class="button-group">
                <a href="checkout.php?step=1" class="btn btn-back">Volver</a>
                <a href="checkout.php?step=2&action=create_order" class="btn btn-confirm" style="display: inline-block; text-decoration: none;">
                    Confirmar Compra
                </a>
            </div>

        <?php elseif ($current_step == 3): ?>
            <!-- Paso 3: Confirmación -->
            <?php
            // Obtener detalles de la orden
            $order_id = isset($_SESSION['order_id']) ? $_SESSION['order_id'] : null;
            $order = null;
            
            if ($order_id) {
                $stmt = $conn->prepare("
                    SELECT o.*, a.street_address, a.city, a.country 
                    FROM orders o
                    JOIN address a ON o.address_id = a.address_id
                    WHERE o.orders_id = ? AND o.user_id = ?
                ");
                $stmt->execute([$order_id, $user_id]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            ?>

            <div class="confirmation">
                <div class="confirmation-icon">✓</div>
                <h2>¡Compra Realizada con Éxito!</h2>
                <p>Gracias por tu compra. Pronto recibirás un correo electrónico con los detalles de tu pedido.</p>
                
                <?php if ($order): ?>
                    <div class="order-details">
                        <p><strong>Número de Pedido:</strong> <?= $order_id ?></p>
                        <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($order['date'])) ?></p>
                        <p><strong>Total:</strong> S/ <?= number_format($order['total_amount'], 2) ?></p>
                        <p><strong>Dirección de Envío:</strong> <?= $order['street_address'] ?>, <?= $order['city'] ?>, <?= $order['country'] ?></p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-error">
                        No se encontraron detalles de la orden.
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
    } else {
        savedAddresses.style.display = 'block';
        newAddressForm.style.display = 'none';
    }
}

// Función para mostrar/ocultar formulario de tarjeta
function toggleCardForm(showNew) {
    const savedCards = document.getElementById('saved-cards');
    const newCardForm = document.getElementById('new-card-form');
    
    if (showNew) {
        savedCards.style.display = 'none';
        newCardForm.style.display = 'block';
    } else {
        savedCards.style.display = 'block';
        newCardForm.style.display = 'none';
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

// Función para validar el formulario antes de enviar
document.addEventListener('DOMContentLoaded', function() {
    const checkoutForm = document.getElementById('checkoutForm');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function(e) {
            // Evitar envío automático
            e.preventDefault();
            
            // Verificar si el formulario es válido
            if (validateForm()) {
                // Si es válido, enviar el formulario
                this.submit();
            }
        });
    }
});

// Función para validar todos los campos
function validateForm() {
    let isValid = true;
    let errorMessage = '';
    
    // Determinar si estamos usando dirección nueva o existente
    const addressOption = document.querySelector('input[name="address_option"]:checked').value;
    
    if (addressOption === 'new') {
        // Validar nueva dirección
        const street = document.getElementById('street').value.trim();
        const city = document.getElementById('city').value.trim();
        const country = document.getElementById('country').value.trim();
        const postalCode = document.getElementById('postal_code').value.trim();
        
        if (!street || !city || !country || !postalCode) {
            isValid = false;
            errorMessage += "Por favor completa todos los campos obligatorios de dirección.\n";
            
            // Resaltar campos vacíos
            highlightEmptyField('street');
            highlightEmptyField('city');
            highlightEmptyField('country');
            highlightEmptyField('postal_code');
        }
    } else {
        // Validar dirección existente seleccionada
        const selectedAddressId = document.getElementById('selected-address-id').value;
        if (!selectedAddressId || selectedAddressId === '0') {
            isValid = false;
            errorMessage += "Por favor selecciona una dirección de envío.\n";
            
            // Resaltar sección
            document.getElementById('saved-addresses').classList.add('highlight-section');
        }
    }
    
    // Determinar si estamos usando tarjeta nueva o existente
    const paymentOption = document.querySelector('input[name="payment_option"]:checked').value;
    
    if (paymentOption === 'new') {
        // Validar nueva tarjeta
        const cardName = document.getElementById('card_name').value.trim();
        const cardNumber = document.getElementById('card_number').value.trim();
        const cvv = document.getElementById('cvv').value.trim();
        const expMonth = document.getElementById('exp_month').value;
        const expYear = document.getElementById('exp_year').value;
        const typeCardId = document.getElementById('type_card_id').value;
        
        if (!cardName || !cardNumber || !cvv || !expMonth || !expYear || !typeCardId) {
            isValid = false;
            errorMessage += "Por favor completa todos los campos obligatorios de tarjeta.\n";
            
            // Resaltar campos vacíos
            highlightEmptyField('card_name');
            highlightEmptyField('card_number');
            highlightEmptyField('cvv');
            
            if (!expMonth || !expYear) {
                document.querySelector('.expiration-inputs').classList.add('highlight-error');
            }
            
            highlightEmptyField('type_card_id');
        }
        
        // Validar formato de número de tarjeta (solo números, 16 dígitos)
        if (cardNumber && (!/^\d+$/.test(cardNumber.replace(/\s/g, '')) || cardNumber.replace(/\s/g, '').length !== 16)) {
            isValid = false;
            errorMessage += "El número de tarjeta debe tener 16 dígitos numéricos.\n";
            document.getElementById('card_number').classList.add('highlight-error');
        }
        
        // Validar CVV (3-4 dígitos)
        if (cvv && (!/^\d+$/.test(cvv) || cvv.length < 3 || cvv.length > 4)) {
            isValid = false;
            errorMessage += "El CVV debe tener 3 o 4 dígitos numéricos.\n";
            document.getElementById('cvv').classList.add('highlight-error');
        }
    } else {
        // Validar tarjeta existente seleccionada
        const selectedCardId = document.getElementById('selected-card-id').value;
        if (!selectedCardId || selectedCardId === '0') {
            isValid = false;
            errorMessage += "Por favor selecciona una tarjeta de pago.\n";
            
            // Resaltar sección
            document.getElementById('saved-cards').classList.add('highlight-section');
        }
    }
    
    // Mostrar mensaje de error si hay problemas
    if (!isValid) {
        showFormError(errorMessage);
    }
    
    return isValid;
}

// Resaltar campo vacío
function highlightEmptyField(fieldId) {
    const field = document.getElementById(fieldId);
    if (field) {
        if (!field.value.trim()) {
            field.classList.add('highlight-error');
        } else {
            field.classList.remove('highlight-error');
        }
    }
}

// Mostrar mensaje de error
function showFormError(message) {
    // Crear o actualizar el contenedor de mensajes de error
    let errorContainer = document.getElementById('form-error-container');
    
    if (!errorContainer) {
        errorContainer = document.createElement('div');
        errorContainer.id = 'form-error-container';
        errorContainer.className = 'alert alert-error';
        
        // Insertar al inicio del formulario
        const form = document.getElementById('checkoutForm');
        form.insertBefore(errorContainer, form.firstChild);
    }
    
    // Actualizar el mensaje
    errorContainer.innerHTML = '<strong>Error:</strong> ' + message.replace(/\n/g, '<br>');
    
    // Desplazarse hacia el mensaje de error
    errorContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Quitar resaltado de error al enfocar el campo
document.addEventListener('DOMContentLoaded', function() {
    const formInputs = document.querySelectorAll('.form-control');
    formInputs.forEach(input => {
        input.addEventListener('focus', function() {
            this.classList.remove('highlight-error');
        });
    });
    
    // Quitar resaltado de sección al hacer clic en ella
    const addressList = document.getElementById('saved-addresses');
    if (addressList) {
        addressList.addEventListener('click', function() {
            this.classList.remove('highlight-section');
        });
    }
    
    const cardList = document.getElementById('saved-cards');
    if (cardList) {
        cardList.addEventListener('click', function() {
            this.classList.remove('highlight-section');
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    console.log('Formulario de confirmación inicializado');
    
    var form = document.getElementById('confirmForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            console.log('Formulario enviado');
            // No prevenir el comportamiento por defecto
            // Podríamos agregar aquí: e.preventDefault(); para detener el envío normal
        });
    } else {
        console.error('Formulario no encontrado');
    }
});
</script>

</body>
</html>
