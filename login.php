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
</head>
<body>
    <div class="login-container" style="max-width:400px;margin:100px auto;padding:2rem;background:#fff;border-radius:10px;">
        <h2>Iniciar Sesión</h2>
        <?php if (isset($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label for="email">Correo Electrónico</label>
            <input type="email" name="email" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <label for="password">Contraseña</label>
            <input type="password" name="password" required class="input" style="width:100%;padding:8px;margin-bottom:1rem;">
            <button type="submit" class="btn-cta" style="width:100%;">Iniciar Sesión</button>
        </form>
        <p style="margin-top:1rem;">¿No tienes cuenta? <a href="register.php">Crea una aquí</a></p>
    </div>
</body>
</html>
