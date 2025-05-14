function addToCart(productId) {
    fetch('check_login.php')
        .then(res => res.json())
        .then(data => {
            if (!data.logged_in) {
                window.location.href = 'login.php';
            } else {
            }
        });
}


function addToCart(productId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'add_to_cart.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'product_id';
    input.value = productId;
    
    form.appendChild(input);
    
    document.body.appendChild(form);
    form.submit();
}

function cerrarModalCarrito() {
    document.getElementById('modalCarrito').style.display = 'none';
}

function irAPagar() {
    window.location.href = 'cart.php';
}
