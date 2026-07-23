<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "No autorizado"]);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];

if ($metodo === 'GET') {
    $start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
    $end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d', strtotime('+1 month'));

    $eventos_finales = [];

    // Tareas pendientes o fechas limite (SOLO para alumnos)
    if ($rol === 'alumno') {
        $stmt = $pdo->prepare("SELECT id_alumno, id_grupo FROM alumnos WHERE id_usuario = ?");
        $stmt->execute([$usuario_id]);
        $al = $stmt->fetch();
        if ($al) {
            $id_alumno = $al['id_alumno'];
            $id_grupo = $al['id_grupo'];

            // 1. Tareas (Agendadas a la fecha_limite)
            $stmtTareas = $pdo->prepare("
                SELECT act.*, aa.estatus 
                FROM actividades act
                JOIN actividad_alumno aa ON act.id_actividad = aa.id_actividad
                WHERE aa.id_alumno = ? AND act.fecha_limite BETWEEN ? AND ?
            ");
            $stmtTareas->execute([$id_alumno, date('Y-m-d H:i:s', strtotime($start)), date('Y-m-d H:i:s', strtotime($end))]);
            $tareas = $stmtTareas->fetchAll();
            
            foreach($tareas as $t) {
                // Default red for pending/late tasks
                $color = '#ef4444'; 
                if($t['estatus'] == 'entregado' || $t['estatus'] == 'revisado') {
                    $color = '#10b981'; // Green if already submitted
                }
                
                $eventos_finales[] = [
                    'id' => 'tarea_' . $t['id_actividad'],
                    'title' => $t['titulo'],
                    'start' => $t['fecha_limite'],
                    'allDay' => false,
                    'display' => 'block',
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'extendedProps' => [
                        'tipo' => 'tarea',
                        'descripcion' => $t['descripcion'],
                        'estatus' => $t['estatus']
                    ]
                ];
            }

            // 2. Eventos Escolares
            $stmtEventos = $pdo->prepare("
                SELECT * FROM calendario_eventos 
                WHERE (id_grupo IS NULL OR id_grupo = ?)
                AND fecha_inicio >= ? AND fecha_fin <= ?
            ");
            $stmtEventos->execute([$id_grupo, date('Y-m-d H:i:s', strtotime($start)), date('Y-m-d H:i:s', strtotime($end))]);
            $eventos = $stmtEventos->fetchAll();
            
            foreach($eventos as $e) {
                $eventos_finales[] = [
                    'id' => 'evento_' . $e['id_evento'],
                    'title' => $e['titulo'],
                    'start' => $e['fecha_inicio'],
                    'end' => $e['fecha_fin'],
                    'backgroundColor' => $e['color'] ? $e['color'] : '#3b82f6',
                    'borderColor' => $e['color'] ? $e['color'] : '#3b82f6',
                    'display' => 'block',
                    'extendedProps' => [
                        'tipo' => 'evento',
                        'descripcion' => $e['descripcion'],
                        'urgencia' => $e['urgencia'] ?? 'media'
                    ]
                ];
            }
        }
    } 
    // Eventos para docentes
    else if ($rol === 'docente') {
        $stmt = $pdo->prepare("SELECT id_docente FROM docentes WHERE id_usuario = ?");
        $stmt->execute([$usuario_id]);
        $doc = $stmt->fetch();
        if ($doc) {
            $id_docente = $doc['id_docente'];
            
            $stmtEventos = $pdo->prepare("
                SELECT e.*, g.semestre, g.grupo, c.nombre as carrera 
                FROM calendario_eventos e
                LEFT JOIN grupos g ON e.id_grupo = g.id_grupo
                LEFT JOIN carreras c ON g.id_carrera = c.id_carrera
                WHERE e.id_docente = ?
                AND e.fecha_inicio >= ? AND e.fecha_fin <= ?
            ");
            $stmtEventos->execute([$id_docente, date('Y-m-d H:i:s', strtotime($start)), date('Y-m-d H:i:s', strtotime($end))]);
            $eventos = $stmtEventos->fetchAll();
            
            foreach($eventos as $e) {
                $dirigido = "Toda la Escuela (General)";
                if ($e['id_grupo']) {
                    $dirigido = $e['semestre'] . "º " . $e['grupo'] . " " . $e['carrera'];
                }
                $eventos_finales[] = [
                    'id' => 'evento_' . $e['id_evento'],
                    'title' => $e['titulo'],
                    'start' => $e['fecha_inicio'],
                    'end' => $e['fecha_fin'],
                    'backgroundColor' => $e['color'] ? $e['color'] : '#3b82f6',
                    'borderColor' => $e['color'] ? $e['color'] : '#3b82f6',
                    'display' => 'block',
                    'extendedProps' => [
                        'tipo' => 'evento',
                        'descripcion' => $e['descripcion'],
                        'dirigido' => $dirigido,
                        'id_grupo' => $e['id_grupo'],
                        'urgencia' => $e['urgencia'] ?? 'media'
                    ]
                ];
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode($eventos_finales);
    exit;
}

if ($metodo === 'POST' && $rol === 'docente') {
    $accion = $_POST['accion'] ?? '';
    $stmtDoc = $pdo->prepare("SELECT id_docente FROM docentes WHERE id_usuario = ?");
    $stmtDoc->execute([$usuario_id]);
    $doc = $stmtDoc->fetch();
    $id_docente = $doc['id_docente'];

    if ($accion === 'crear') {
        $titulo = $_POST['titulo'];
        $descripcion = $_POST['descripcion'];
        $fecha_inicio = $_POST['fecha_inicio'];
        $fecha_fin = $_POST['fecha_fin'];
        $color = $_POST['color'];
        $id_grupo = (!empty($_POST['id_grupo']) && $_POST['id_grupo'] != '0') ? $_POST['id_grupo'] : NULL;
        $urgencia = $_POST['urgencia'] ?? 'media';
        
        $stmt = $pdo->prepare("INSERT INTO calendario_eventos (id_docente, titulo, descripcion, fecha_inicio, fecha_fin, color, id_grupo, urgencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id_docente, $titulo, $descripcion, $fecha_inicio, $fecha_fin, $color, $id_grupo, $urgencia]);
        
        // --- Notificar Alumnos ---
        $notif_titulo = "Nuevo Evento Escolar";
        $notif_mensaje = "Se ha agendado el evento: " . $titulo;
        
        if ($id_grupo) {
            $stmtAlumnos = $pdo->prepare("SELECT id_usuario FROM alumnos WHERE id_grupo = ?");
            $stmtAlumnos->execute([$id_grupo]);
        } else {
            $stmtAlumnos = $pdo->query("SELECT id_usuario FROM alumnos");
        }
        
        $alumnos_noti = $stmtAlumnos->fetchAll();
        $stmtInsertNotif = $pdo->prepare("INSERT INTO notificaciones (id_usuario, titulo, mensaje) VALUES (?, ?, ?)");
        foreach($alumnos_noti as $al_n) {
            $stmtInsertNotif->execute([$al_n['id_usuario'], $notif_titulo, $notif_mensaje]);
        }
        
        echo json_encode(["status" => "success"]);
    } 
    else if ($accion === 'eliminar') {
        $id_evento = str_replace('evento_', '', $_POST['id_evento']);
        $stmt = $pdo->prepare("DELETE FROM calendario_eventos WHERE id_evento = ? AND id_docente = ?");
        $stmt->execute([$id_evento, $id_docente]);
        echo json_encode(["status" => "success"]);
    }
    exit;
}
?>
