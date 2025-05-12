// Verificar login antes de añadir al carrito
function addToCart(productId) {
    fetch('check_login.php')
        .then(res => res.json())
        .then(data => {
            if (!data.logged_in) {
                window.location.href = 'login.php';
            } else {
                // Lógica para añadir al carrito (AJAX)
                // Mostrar modal de carrito
            }
        });
}

// Lógica para mostrar/ocultar modal, modificar cantidades, eliminar productos, seleccionar para pagar, etc.

// Función para añadir productos al carrito
function addToCart(productId) {
    // Crear un formulario dinámicamente
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'add_to_cart.php';
    
    // Crear input para product_id
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'product_id';
    input.value = productId;
    
    // Añadir input al formulario
    form.appendChild(input);
    
    // Añadir el formulario al documento y enviarlo
    document.body.appendChild(form);
    form.submit();
}

// Funciones del modal carrito (si se necesitan)
function cerrarModalCarrito() {
    document.getElementById('modalCarrito').style.display = 'none';
}

function irAPagar() {
    window.location.href = 'cart.php';
}
