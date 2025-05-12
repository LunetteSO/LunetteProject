<?php
require 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'];
    $apellido = $_POST['apellido'];
    $email = $_POST['email'];
    $telefono = $_POST['telefono'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    try {
        // Verificar si el usuario ya existe
        $stmt = $conn->prepare("SELECT * FROM user WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            $error = "El usuario ya existe. Inicia sesión.";
        } else {
            $stmt = $conn->prepare("INSERT INTO user (name, last_name, email, phone_number, password) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $apellido, $email, $telefono, $password]);
            header('Location: login.php');
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear Cuenta - Lunette</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="register-container" style="max-width:500px;margin:100px auto;padding:2rem;background:#fff;border-radius:10px;">
        <h2>Crear Cuenta</h2>
        <?php if (isset($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label for="nombre">Nombre</label>
            <input type="text" name="nombre" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <label for="apellido">Apellido</label>
            <input type="text" name="apellido" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <label for="email">Correo Electrónico</label>
            <input type="email" name="email" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <label for="telefono">Número Telefónico</label>
            <input type="text" name="telefono" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <label for="password">Contraseña</label>
            <input type="password" name="password" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <button type="submit" class="btn-cta" style="width:100%;">Crear Cuenta</button>
        </form>
        <p style="margin-top:1rem;">¿Ya tienes cuenta? <a href="login.php">Inicia sesión aquí</a></p>
    </div>
</body>
</html>
