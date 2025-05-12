<?php
session_start();
require 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    try {
        $stmt = $conn->prepare("SELECT * FROM user WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            header('Location: index.php');
            exit;
        } else {
            $error = "Correo o contraseña incorrectos";
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
    <title>Iniciar Sesión - Lunette</title>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden;
            background-color: var(--color4);
        }
        .split-container {
            display: flex;
            height: 100vh;
        }
        .form-side {
            width: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: var(--color4);
        }
        .image-side {
            width: 50%;
            background-image: url('assets/images/imagen-inicio.jpg');
            background-size: cover;
            background-position: center;
        }
        .login-form {
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
            font-size: 16px;
            background-color: var(--color4);
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
        <div class="form-side">
            <div class="login-form">
                <h2>Iniciar Sesión</h2>
                <?php if (isset($error)): ?>
                    <p style="color:red;"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>
                <form method="post">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" name="email" required class="input">
                    <label for="password">Contraseña</label>
                    <input type="password" name="password" required class="input">
                    <button type="submit" class="btn-cta">Iniciar Sesión</button>
                </form>
                <p style="margin-top:1rem;">¿No tienes cuenta? <a href="register.php">Crea una aquí</a></p>
            </div>
        </div>
        <div class="image-side"></div>
    </div>
</body>
</html>
