<?php
session_start();
require 'config/db.php';

// Obtener categorías
try {
    $stmt = $conn->query("SELECT * FROM category");
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error al recuperar categorías: ' . $e->getMessage());
}

// Obtener productos (3 más baratos primero)
try {
    $stmt = $conn->query("SELECT p.*, 
        (SELECT pi.image_url FROM product_image pi WHERE pi.product_id = p.product_id LIMIT 1) AS image_url
        FROM product p
        ORDER BY p.price ASC LIMIT 3");
    $productos_baratos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error al recuperar productos baratos: ' . $e->getMessage());
}

try {
    $stmt = $conn->query("SELECT p.*, 
        (SELECT pi.image_url FROM product_image pi WHERE pi.product_id = p.product_id LIMIT 1) AS image_url
        FROM product p");
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error al recuperar productos: ' . $e->getMessage());
}

$usuario_logueado = isset($_SESSION['user_id']);

// Contar ítems en el carrito
$item_count = 0;
if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $item_count += $item['quantity'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lunette - Ecommerce</title>
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
        <li>
            <a href="cart.php" class="cart-link" title="Carrito">
                🛒 Carrito
                <?php if ($item_count > 0): ?>
                <span class="cart-count"><?= $item_count ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>
    <ul class="nav-links nav-user">
        <?php if ($usuario_logueado): ?>
            <li><a href="login.php">Cerrar Sesión</a></li>
        <?php else: ?>
            <li><a href="login.php">Iniciar Sesión</a></li>
            <li><a href="register.php">Crear Cuenta</a></li>
        <?php endif; ?>
       
    </ul>
</nav>

<div class="main-container">
<!-- Hero Section -->
<section class="hero">
    <div class="hero-img-container">
        <img src="assets/images/imagen-hero.png" alt="Joyería Lunette" class="hero-img">
        <div class="hero-overlay">
            <h1>Lunette</h1>
            <p class="hero-desc">Cada joya, una historia que contar</p>
            <blockquote class="hero-quote">
                "La magia de tus momentos más especiales."
            </blockquote>
        </div>
    </div>
</section>

<!-- Categorías -->
<div class="categories" id="categorias" style="margin-top: 80px;">
    <h2 style="text-align:center; color: var(--color2); font-size: 1.1rem; margin-bottom: 0.7rem;">Categorías</h2>
    <div class="category-list">
        <div class="categoria-bloque categoria-activa" onclick="filtrarPorTexto('todos')">
            <span>Todos</span>
        </div>
        <div class="categoria-bloque" onclick="filtrarPorTexto('anillos')">
            <span>Anillos</span>
        </div>
        <div class="categoria-bloque" onclick="filtrarPorTexto('pulseras')">
            <span>Pulseras</span>
        </div>
        <div class="categoria-bloque" onclick="filtrarPorTexto('aretes')">
            <span>Aretes</span>
        </div>
        <div class="categoria-bloque" onclick="filtrarPorTexto('collar')">
            <span>Collares</span>
        </div>
    </div>
</div>

<script>
// Función para filtrar productos por texto en el nombre o descripción
function filtrarPorTexto(categoria, elemento) {
    // Actualizar categorías activas
    const categorias = document.querySelectorAll('.categoria-bloque');
    categorias.forEach(cat => {
        cat.classList.remove('categoria-activa');
    });
    
    // Activar la categoría seleccionada
    if (elemento) {
        elemento.classList.add('categoria-activa');
    } else {
        event.currentTarget.classList.add('categoria-activa');
    }
    
    // Actualizar título
    const titulos = document.querySelectorAll('.products h2');
    if (titulos.length > 0) {
        // Actualizar solo el segundo título (Todos los productos)
        if (titulos.length > 1) {
            titulos[1].textContent = categoria === 'todos' ? 'Todos los Productos' : 
                document.querySelector('.categoria-activa span').textContent + ' - Productos';
        }
    }
    
    // Filtrar productos
    const productos = document.querySelectorAll('.producto');
    let categoriaBusqueda = categoria.toLowerCase();
    
    productos.forEach(producto => {
        // Buscar en el nombre y descripción del producto
        const nombreProducto = producto.querySelector('h3').innerText.toLowerCase();
        const descripcionProducto = producto.getAttribute('data-description') || '';
        
        if (categoria === 'todos') {
            producto.style.display = '';
        } else if (
            nombreProducto.includes(categoriaBusqueda) || 
            descripcionProducto.toLowerCase().includes(categoriaBusqueda)
        ) {
            producto.style.display = '';
        } else {
            producto.style.display = 'none';
        }
    });
}

// Inicializar cuando la página carga
document.addEventListener('DOMContentLoaded', function() {
    const todosBtn = document.querySelector('.categoria-bloque.categoria-activa');
    filtrarPorTexto('todos', todosBtn);
});
</script>

<!-- Productos más baratos -->
<div class="products" id="productos">
    <h2 style="text-align:center; color: var(--color2); font-size: 1.1rem; margin-bottom: 0.7rem;">Productos Más Baratos</h2>
    <div class="productos-grid">
        <?php foreach ($productos_baratos as $product): ?>
            <div class="producto categoria-<?= strtolower(preg_replace('/\s+/', '-', $product['name'])) ?>" 
                 data-description="<?= htmlspecialchars($product['description'] ?? '') ?>">
                <a href="add_to_cart_db.php?product_id=<?= $product['product_id'] ?>" class="btn-mas" title="Añadir al carrito">+</a>
                <a href="product.php?id=<?= $product['product_id'] ?>">
                    <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                    <h3><?= htmlspecialchars($product['name']) ?></h3>
                    <p>S/ <?= htmlspecialchars($product['price']) ?></p>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Todos los productos -->
<div class="products">
    <h2 style="text-align:center; color: var(--color2); font-size: 1.1rem; margin-bottom: 0.7rem;">Todos los Productos</h2>
    <div class="productos-grid">
        <?php foreach ($productos as $product): ?>
            <div class="producto categoria-<?= strtolower(preg_replace('/\s+/', '-', $product['name'])) ?>"
                 data-description="<?= htmlspecialchars($product['description'] ?? '') ?>">
                <a href="add_to_cart_db.php?product_id=<?= $product['product_id'] ?>" class="btn-mas" title="Añadir al carrito">+</a>
                <a href="product.php?id=<?= $product['product_id'] ?>">
                    <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                    <h3><?= htmlspecialchars($product['name']) ?></h3>
                    <p>S/ <?= htmlspecialchars($product['price']) ?></p>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal del carrito (estructura básica, funcionalidad en JS) -->
<div id="modalCarrito" class="modal-carrito" style="display:none;">
    <div class="modal-content">
        <span class="close" onclick="cerrarModalCarrito()">&times;</span>
        <h2>Tu Carrito</h2>
        <div id="carritoProductos"></div>
        <button onclick="irAPagar()" class="btn-carrito">Pagar</button>
    </div>
</div>
</div>

<!-- Footer -->
<footer class="footer">
    <div class="footer-col">
        <h4>Lunette</h4>
        <div class="footer-contact">
            Brindar joyería personalizada con un sello de elegancia, fantasía y exclusividad.
        </div>
    </div>
    <div class="footer-col">
        <h4>Categorías</h4>
        <ul>
            <li><a href="#categorias">Collares</a></li>
            <li><a href="#categorias">Pulseras</a></li>
            <li><a href="#categorias">Aretes</a></li>
        </ul>
    </div>
    <div class="footer-col">
        <h4>Legal</h4>
        <ul>
            <li><a href="#">Libro de Reclamaciones</a></li>
        </ul>
    </div>
    <div class="footer-col">
        <h4>Contáctanos</h4>
        <div class="footer-contact">
            +51 123 456 678<br>
            lunette@gmail.com
        </div>
    </div>
</footer>

</body>
</html>
