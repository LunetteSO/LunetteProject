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
    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
        }
        .split-container {
            display: flex;
            height: 100vh;
        }
        .form-side {
            
            background-color: var(--color4);
            width: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .image-side {
            width: 50%;
            background-image: url('assets/images/imagen-inicio.jpg');
            background-size: cover;
            background-position: center;
        }
        .register-form {
            width: 80%;
            max-width: 400px;
            padding: 2rem;
        }
        .input {
            width: 100%;
            padding: 12px;
            margin-bottom: 1rem;
            border: 1px solid var(--color2);
            border-radius: 5px;
            
            background-color: var(--color4);
            font-size: 16px;
        }
        .btn-cta {
            width: 100%;
            padding: 12px;
            background-color: var(--color2);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .btn-cta:hover {
            background-color: var(--color5);
        }
    </style>
</head>
<body>
    <div class="split-container">
        <div class="image-side"></div>
        <div class="form-side">
            <div class="register-form">
                <h2>Crear Cuenta</h2>
                <?php if (isset($error)): ?>
                    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>
                <form method="post">
                    <label for="nombre">Nombre</label>
                    <input type="text" name="nombre" required class="input">
                    <label for="apellido">Apellido</label>
                    <input type="text" name="apellido" required class="input">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" name="email" required class="input">
                    <label for="telefono">Número Telefónico</label>
                    <input type="text" name="telefono" required class="input">
                    <label for="password">Contraseña</label>
                    <input type="password" name="password" required class="input">
                    <button type="submit" class="btn-cta">Crear Cuenta</button>
                </form>
                <p style="margin-top:1rem;">¿Ya tienes cuenta? <a href="login.php">Inicia sesión aquí</a></p>
            </div>
        </div>
    </div>
</body>
</html>
