<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    
    // Autenticación rápida (solo para pruebas)
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE correo = ? AND activo = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['usuario_id'] = $user['id_usuario'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol'] = $user['rol'];
        
        if ($user['rol'] === 'alumno') {
            header("Location: alumno.php");
        } else if ($user['rol'] === 'docente') {
            header("Location: docente.php");
        }
        exit;
    } else {
        $error = "Usuario no encontrado.";
    }
}

// Obtener todos los usuarios para el dropdown de pruebas
$stmtUsers = $pdo->query("SELECT * FROM usuarios WHERE activo = 1 ORDER BY rol DESC, nombre ASC");
$allUsers = $stmtUsers->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal CBTis 270</title>
    <link rel="stylesheet" href="css/styles.css">
    <style>
        body { justify-content: center; align-items: center; background: #051543; }
        .login-card { background: white; padding: 40px; border-radius: 16px; width: 100%; max-width: 400px; text-align: center; }
        .login-card img { width: 100px; margin-bottom: 20px; }
        .login-title { font-size: 24px; font-weight: bold; margin-bottom: 30px; color: #1e293b; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2 class="login-title">Portal CBTis 270</h2>
        <?php if(isset($error)): ?>
            <div style="color: red; margin-bottom: 15px;"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group" style="text-align: left;">
                <label>Correo electrónico (Prueba):</label>
                <select name="email" class="form-control" required>
                    <option value="">Selecciona un usuario...</option>
                    <?php foreach($allUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['correo']) ?>">
                            <?= ucfirst(htmlspecialchars($u['rol'])) ?>: <?= htmlspecialchars($u['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%;">Iniciar Sesión</button>
        </form>
    </div>
</body>
</html>
