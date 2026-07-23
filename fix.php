<?php
require_once 'config.php';
$pdo->exec("SET NAMES utf8mb4");
$pdo->exec("UPDATE usuarios SET nombre = 'Carlos Ramírez' WHERE id_usuario = 1");
echo "Nombre de usuario actualizado con éxito a Carlos Ramírez.";
?>
