<?php
session_start();
require_once '../config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'alumno') {
    http_response_code(403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_actividad'], $_POST['estatus'])) {
    $id_actividad = $_POST['id_actividad'];
    $estatus = $_POST['estatus'];
    $alumno_id = $_SESSION['usuario_id'];
    
    // Obtener id_alumno de la DB
    $stmt = $pdo->prepare("SELECT id_alumno FROM alumnos WHERE id_usuario = ?");
    $stmt->execute([$alumno_id]);
    $alumno = $stmt->fetch();
    
    if($alumno) {
        if ($estatus === 'visto') {
            $stmtUpdate = $pdo->prepare("UPDATE actividad_alumno 
                                         SET estatus = 'visto', visto = 1, fecha_visto = NOW() 
                                         WHERE id_actividad = ? AND id_alumno = ? AND estatus = 'no_visto'");
            $stmtUpdate->execute([$id_actividad, $alumno['id_alumno']]);
            echo json_encode(['success' => true]);
        }
    }
}
?>
