<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_usuario = $_SESSION['usuario_id'];
    $stmt = $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE id_usuario = ?");
    if($stmt->execute([$id_usuario])) {
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(500);
    }
}
?>
