<?php
// 1) Conexión a la base de datos
require_once __DIR__ . '/config/db.php';   // Aquí defines $conn

// 2) Consulta de todos los usuarios
try {
    $stmt  = $conn->query('SELECT * FROM user');  
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error al recuperar usuarios: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lista de Usuarios</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
        }
        h1 {
            color: #333;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th {
            background-color: #498fe0;
            color: white;
            padding: 10px;
            text-align: center;
        }
        td {
            padding: 10px;
            border: 1px solid #ccc;
            text-align: center;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tr:hover {
            background-color: #dbeeff;
        }
    </style>
</head>
<body>

<h1>Usuarios Registrados</h1>

<?php if (count($users) > 0): ?>
    <table>
        <thead>
            <tr>
                <?php foreach (array_keys($users[0]) as $column): ?>
                    <th><?= htmlspecialchars($column) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <?php foreach ($user as $value): ?>
                        <td><?= htmlspecialchars($value) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No se encontraron usuarios.</p>
<?php endif; ?>

</body>
</html>
