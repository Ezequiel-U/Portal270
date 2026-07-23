<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'alumno') {
    header("Location: index.php");
    exit;
}

$alumno_id = $_SESSION['usuario_id'];
// Obtener información del alumno
$stmt = $pdo->prepare("SELECT a.*, g.grupo, g.semestre, c.nombre as carrera 
                       FROM alumnos a 
                       JOIN grupos g ON a.id_grupo = g.id_grupo 
                       JOIN carreras c ON g.id_carrera = c.id_carrera 
                       WHERE a.id_usuario = ?");
$stmt->execute([$alumno_id]);
$info_alumno = $stmt->fetch();

$id_alumno_db = $info_alumno['id_alumno'];
$id_grupo_db = $info_alumno['id_grupo'];

// Si se recibe un archivo (entrega de tarea)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_actividad'])) {
    $id_actividad = $_POST['id_actividad'];
    $comentario = $_POST['comentario'] ?? '';
    
    // Validar fecha_limite y permitir_tarde
    $stmtVal = $pdo->prepare("SELECT fecha_limite, permitir_tarde FROM actividades WHERE id_actividad = ?");
    $stmtVal->execute([$id_actividad]);
    $actVal = $stmtVal->fetch();
    
    if ($actVal) {
        $fecha_limite_ts = strtotime($actVal['fecha_limite']);
        $ahora_ts = time();
        if ($actVal['permitir_tarde'] == 0 && $ahora_ts > $fecha_limite_ts) {
            header("Location: alumno.php?msg=bloqueado");
            exit;
        }
    }
    
    // Procesar subida de archivo real
    $archivo_nombre_guardado = '';
    if(isset($_FILES['archivo']) && $_FILES['archivo']['error'] == 0) {
        $nombre_original = basename($_FILES['archivo']['name']);
        $archivo_nombre_guardado = $nombre_original;
        
        $ruta_destino = 'uploads/' . $archivo_nombre_guardado;
        $contador = 1;
        while(file_exists($ruta_destino)) {
            $info = pathinfo($nombre_original);
            $archivo_nombre_guardado = $info['filename'] . " ($contador)." . (isset($info['extension']) ? $info['extension'] : '');
            $ruta_destino = 'uploads/' . $archivo_nombre_guardado;
            $contador++;
        }
        
        move_uploaded_file($_FILES['archivo']['tmp_name'], $ruta_destino);
    }

    $pdo->beginTransaction();
    try {
        // Alumno que sube
        $stmt = $pdo->prepare("INSERT INTO entregas (id_actividad, id_alumno, archivo, comentario) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id_actividad, $id_alumno_db, $archivo_nombre_guardado, $comentario]);
        
        $stmt2 = $pdo->prepare("UPDATE actividad_alumno SET estatus = 'entregado', fecha_entrega = NOW() WHERE id_actividad = ? AND id_alumno = ?");
        $stmt2->execute([$id_actividad, $id_alumno_db]);

        // Compañeros de equipo si es colaborativa
        if(isset($_POST['teammates']) && is_array($_POST['teammates'])) {
            foreach($_POST['teammates'] as $teammate_id) {
                // Verificar que no haya entregado
                $stmtCheck = $pdo->prepare("SELECT estatus FROM actividad_alumno WHERE id_actividad = ? AND id_alumno = ?");
                $stmtCheck->execute([$id_actividad, $teammate_id]);
                $t_estatus = $stmtCheck->fetchColumn();
                if($t_estatus !== 'entregado' && $t_estatus !== 'revisado' && $t_estatus !== 'esperando_confirmacion') {
                    $comentario_equipo = "Invitación de equipo enviada por: " . $_SESSION['nombre'] . "\n" . $comentario;
                    // Se inserta la entrega como pendiente de confirmacion
                    $stmtInv = $pdo->prepare("INSERT INTO entregas (id_actividad, id_alumno, archivo, comentario, estado) VALUES (?, ?, ?, ?, 'pendiente_confirmacion')");
                    $stmtInv->execute([$id_actividad, $teammate_id, $archivo_nombre_guardado, $comentario_equipo]);
                    
                    // Se cambia el estatus a esperando_confirmacion
                    $stmt2Inv = $pdo->prepare("UPDATE actividad_alumno SET estatus = 'esperando_confirmacion', fecha_entrega = NOW() WHERE id_actividad = ? AND id_alumno = ?");
                    $stmt2Inv->execute([$id_actividad, $teammate_id]);
                }
            }
        }
        
        // Notificación al profesor
        $stmtAct = $pdo->prepare("SELECT creado_por, titulo FROM actividades WHERE id_actividad = ?");
        $stmtAct->execute([$id_actividad]);
        $actData = $stmtAct->fetch();
        if($actData) {
            $id_docente_usuario = $actData['creado_por'];
            $titulo_notif = "Nueva Entrega";
            $mensaje_notif = $_SESSION['nombre'] . " ha entregado la actividad: " . $actData['titulo'];
            
            $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (id_usuario, titulo, mensaje, tipo) VALUES (?, ?, ?, 'entrega')");
            $stmtNotif->execute([$id_docente_usuario, $titulo_notif, $mensaje_notif]);
        }
        $pdo->commit();
        header("Location: alumno.php?msg=entregado");
        exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}

// Confirmar Invitacion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['aceptar_equipo', 'rechazar_equipo'])) {
    $id_actividad = $_POST['id_actividad'];
    
    if($_POST['action'] === 'aceptar_equipo') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE entregas SET estado = 'entregado' WHERE id_actividad = ? AND id_alumno = ? AND estado = 'pendiente_confirmacion'")->execute([$id_actividad, $id_alumno_db]);
            $pdo->prepare("UPDATE actividad_alumno SET estatus = 'entregado' WHERE id_actividad = ? AND id_alumno = ?")->execute([$id_actividad, $id_alumno_db]);
            $pdo->commit();
            header("Location: alumno.php?msg=aceptado");
            exit;
        } catch(Exception $e) {
            $pdo->rollBack();
            die("Error: " . $e->getMessage());
        }
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM entregas WHERE id_actividad = ? AND id_alumno = ? AND estado = 'pendiente_confirmacion'")->execute([$id_actividad, $id_alumno_db]);
            $pdo->prepare("UPDATE actividad_alumno SET estatus = 'pendiente', fecha_entrega = NULL WHERE id_actividad = ? AND id_alumno = ?")->execute([$id_actividad, $id_alumno_db]);
            $pdo->commit();
            header("Location: alumno.php?msg=rechazado");
            exit;
        } catch(Exception $e) {
            $pdo->rollBack();
            die("Error: " . $e->getMessage());
        }
    }
}

// Anular entrega
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'anular_entrega') {
    $id_actividad = $_POST['id_actividad_anular'];
    
    // Buscar el archivo enviado para esta actividad
    $stmt = $pdo->prepare("SELECT archivo FROM entregas WHERE id_actividad = ? AND id_alumno = ?");
    $stmt->execute([$id_actividad, $id_alumno_db]);
    $archivo = $stmt->fetchColumn();
    
    if($archivo) {
        $pdo->beginTransaction();
        try {
            // Buscar a todos los compañeros que tienen este mismo archivo (equipo)
            $stmtTeam = $pdo->prepare("SELECT id_alumno FROM entregas WHERE id_actividad = ? AND archivo = ?");
            $stmtTeam->execute([$id_actividad, $archivo]);
            $team = $stmtTeam->fetchAll(PDO::FETCH_COLUMN);
            
            // Borrar de entregas
            $stmtDel = $pdo->prepare("DELETE FROM entregas WHERE id_actividad = ? AND archivo = ?");
            $stmtDel->execute([$id_actividad, $archivo]);
            
            // Regresar el estatus a pendiente
            if(!empty($team)) {
                $inQuery = implode(',', array_fill(0, count($team), '?'));
                $params = array_merge([$id_actividad], $team);
                $stmtUpd = $pdo->prepare("UPDATE actividad_alumno SET estatus = 'pendiente', fecha_entrega = NULL WHERE id_actividad = ? AND id_alumno IN ($inQuery)");
                $stmtUpd->execute($params);
            }
            
            $pdo->commit();
            
            // Borrar archivo fisico
            if(file_exists('uploads/' . $archivo)) {
                unlink('uploads/' . $archivo);
            }
            
            header("Location: alumno.php?msg=anulado");
            exit;
        } catch(Exception $e) {
            $pdo->rollBack();
            die("Error: " . $e->getMessage());
        }
    }
}

// Obtener tareas pendientes
$stmt = $pdo->prepare("
    SELECT act.*, aa.estatus, e.archivo as archivo_invitacion, e.comentario as comentario_invitacion 
    FROM actividades act
    JOIN actividad_alumno aa ON act.id_actividad = aa.id_actividad
    LEFT JOIN entregas e ON e.id_actividad = act.id_actividad AND e.id_alumno = aa.id_alumno AND e.estado = 'pendiente_confirmacion'
    WHERE aa.id_alumno = ? AND aa.estatus IN ('pendiente', 'esperando_confirmacion', 'no_visto', 'visto')
");
$stmt->execute([$id_alumno_db]);
$tareas_pendientes = $stmt->fetchAll();

// Obtener tareas entregadas
$stmtEnt = $pdo->prepare("
    SELECT act.*, aa.estatus, aa.fecha_entrega, e.archivo as archivo_entregado
    FROM actividades act
    JOIN actividad_alumno aa ON act.id_actividad = aa.id_actividad
    LEFT JOIN entregas e ON e.id_actividad = act.id_actividad AND e.id_alumno = ?
    WHERE aa.id_alumno = ? AND aa.estatus = 'entregado'
");
$stmtEnt->execute([$id_alumno_db, $id_alumno_db]);
$tareas_entregadas = $stmtEnt->fetchAll();

// Obtener calificaciones
$stmtCal = $pdo->prepare("
    SELECT act.*, aa.estatus, aa.fecha_entrega, e.archivo as archivo_entregado, c.puntos_obtenidos, c.retroalimentacion, c.fecha_revision
    FROM actividades act
    JOIN actividad_alumno aa ON act.id_actividad = aa.id_actividad
    JOIN entregas e ON e.id_actividad = act.id_actividad AND e.id_alumno = ?
    JOIN calificaciones c ON c.id_entrega = e.id_entrega
    WHERE aa.id_alumno = ? AND aa.estatus = 'revisado'
    ORDER BY c.fecha_revision DESC
");
$stmtCal->execute([$id_alumno_db, $id_alumno_db]);
$tareas_calificadas = $stmtCal->fetchAll();

// Obtener Próximos Eventos Escolares
$stmtEventosAlumno = $pdo->prepare("
    SELECT * FROM calendario_eventos 
    WHERE (id_grupo IS NULL OR id_grupo = ?)
    AND (fecha_inicio >= CURDATE() OR fecha_fin >= CURDATE())
    ORDER BY fecha_inicio ASC
    LIMIT 6
");
$stmtEventosAlumno->execute([$id_grupo_db]);
$proximos_eventos = $stmtEventosAlumno->fetchAll();



// Compañeros para equipo
$stmt = $pdo->prepare("SELECT a.id_alumno, u.nombre FROM alumnos a JOIN usuarios u ON a.id_usuario = u.id_usuario WHERE a.id_grupo = ? AND a.id_alumno != ?");
$stmt->execute([$id_grupo_db, $id_alumno_db]);
$companeros = $stmt->fetchAll();

// Obtener Notificaciones
$stmtNotif = $pdo->prepare("SELECT * FROM notificaciones WHERE id_usuario = ? ORDER BY fecha DESC LIMIT 10");
$stmtNotif->execute([$alumno_id]);
$notificaciones = $stmtNotif->fetchAll();
$notif_no_leidas = array_filter($notificaciones, function($n) { return $n['leida'] == 0; });
$num_no_leidas = count($notif_no_leidas);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumno - Portal CBTis 270</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?= time() ?>">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- FullCalendar -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

    <style>
        .ql-editor {
            padding: 0;
            font-family: inherit;
        }
        /* Custom Checkbox for Teammates */
        .teammate-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            background: var(--card-bg);
        }
        .teammate-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            border-radius: 6px;
            cursor: pointer;
        }
        .teammate-item:hover {
            background: var(--bg-hover);
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <div class="profile-pic" style="background:var(--card-bg); margin:0 auto 10px;"></div>
            <div class="profile-name"><?= htmlspecialchars($_SESSION['nombre']) ?></div>
            <div class="profile-role"><?= htmlspecialchars($info_alumno['semestre']) ?>º <?= htmlspecialchars($info_alumno['grupo']) ?> <?= htmlspecialchars($info_alumno['carrera']) ?></div>
        </div>
        <ul class="nav-links">
            <li><a href="#" class="nav-item" onclick="switchNav('pendientes', this)"><i data-lucide="home"></i> Inicio</a></li>
            <li><a href="#" class="nav-item active" onclick="switchNav('pendientes', this)"><i data-lucide="book-open"></i> Mis actividades</a></li>
            <li><a href="#" class="nav-item" onclick="switchNav('entregas', this)"><i data-lucide="check-circle"></i> Entregas</a></li>
            <li><a href="#" class="nav-item" onclick="switchNav('calendario', this)"><i data-lucide="calendar"></i> Calendario</a></li>
            <li><a href="#" class="nav-item" onclick="switchNav('calificaciones', this)"><i data-lucide="award"></i> Calificaciones</a></li>
        </ul>
        <div style="margin-top: auto; padding: 20px;">
            <a href="index.php" class="nav-item" style="color:#ef4444;"><i data-lucide="log-out"></i> Cerrar sesión</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="header">
            <div class="breadcrumb">
                Mis actividades > <span id="breadcrumb-current">Pendientes</span>
            </div>
            <div class="notification-container" onclick="toggleNotificaciones()">
                <div class="notification-bell">
                    <i data-lucide="bell"></i>
                    <?php if($num_no_leidas > 0): ?>
                        <span class="notification-badge" id="notif-badge"><?= $num_no_leidas ?></span>
                    <?php endif; ?>
                </div>
                <div class="notification-dropdown" id="notif-dropdown">
                    <div class="notif-header">Notificaciones</div>
                    <div class="notif-list">
                        <?php if(count($notificaciones) == 0): ?>
                            <div style="padding: 15px; text-align:center; color: var(--text-muted);">No tienes notificaciones</div>
                        <?php else: ?>
                            <?php foreach($notificaciones as $n): 
                                $titulo_lower = strtolower($n['titulo']);
                                $mensaje_lower = strtolower($n['mensaje']);
                                $icon = 'mail';
                                if (strpos($titulo_lower, 'calendario') !== false || strpos($titulo_lower, 'evento') !== false) {
                                    $icon = 'calendar';
                                } elseif (strpos($titulo_lower, 'equipo') !== false || strpos($mensaje_lower, 'equipo') !== false) {
                                    $icon = 'users';
                                } elseif (strpos($titulo_lower, 'calific') !== false) {
                                    $icon = 'award';
                                }
                            ?>
                                <div class="notif-item">
                                    <div class="notif-icon notif-icon-bw"><i data-lucide="<?= $icon ?>"></i></div>
                                    <div>
                                        <div class="notif-title"><?= htmlspecialchars($n['titulo']) ?></div>
                                        <div style="color: #333;"><?= htmlspecialchars($n['mensaje']) ?></div>
                                        <div class="notif-time"><?= htmlspecialchars($n['fecha']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'entregado'): ?>
            <div style="background: var(--success-bg); color: var(--success-text); padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="check-circle"></i> ¡Actividad entregada correctamente! Sigue así.
            </div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'anulado'): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="info"></i> Has anulado la entrega correctamente. Ahora puedes volver a subirla.
            </div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'aceptado'): ?>
            <div style="background: var(--success-bg); color: var(--success-text); padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="check-circle"></i> ¡Has aceptado la invitación y tu tarea fue entregada!
            </div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'rechazado'): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="info"></i> Has rechazado la invitación de equipo.
            </div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'bloqueado'): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="lock"></i> Entregas cerradas. El tiempo para esta actividad expiró.
            </div>
        <?php endif; ?>

        <!-- CONTENEDOR PENDIENTES -->
        <div id="container-pendientes">
            <h2 style="margin-bottom: 20px;">Actividades Pendientes</h2>
            <?php if(count($tareas_pendientes) == 0): ?>
                <div class="stat-card" style="text-align: center; cursor:pointer;" onclick="switchNav('entregas', document.querySelector('.nav-item:nth-child(3)'))">
                    <i data-lucide="check-circle-2" style="width:48px; height:48px; color:#22c55e; margin-bottom:10px;"></i>
                    <p>No tienes tareas pendientes. ¡Excelente trabajo!</p>
                </div>
            <?php else: ?>
                <?php foreach($tareas_pendientes as $tarea): ?>
                    <div class="task-item" onclick="verTarea(<?= htmlspecialchars(json_encode($tarea)) ?>)" style="cursor:pointer; background:var(--card-bg);">
                        <div style="display:flex; align-items:center; gap: 15px;">
                            <div style="background: rgba(79, 70, 229, 0.1); color:#2563eb; width:45px; height:45px; border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                <i data-lucide="file-text"></i>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 16px; color:var(--text-dark);"><?= htmlspecialchars($tarea['titulo']) ?></div>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 5px; display:flex; align-items:center; gap:15px;">
                                    <span style="display:flex; align-items:center; gap:5px;"><i data-lucide="book" style="width:14px;"></i> <?= htmlspecialchars($tarea['materia']) ?></span>
                                    <span style="display:flex; align-items:center; gap:5px;"><i data-lucide="calendar" style="width:14px;"></i> <?= htmlspecialchars($tarea['fecha_limite']) ?></span>
                                    <?php if($tarea['modalidad'] == 'equipo'): ?>
                                        <span style="display:flex; align-items:center; gap:5px; color:#8b5cf6;"><i data-lucide="users" style="width:14px;"></i> Equipo</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if($tarea['estatus'] == 'esperando_confirmacion'): ?>
                            <span class="badge" style="background:#ffedd5; color:#ea580c; border: 1px solid #fdba74;"><i data-lucide="mail-warning" style="width:12px; margin-right:4px;"></i> Invitación</span>
                        <?php else: ?>
                            <span class="badge pending">Pendiente</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- CONTENEDOR ENTREGAS -->
        <div id="container-entregas" style="display:none;">
            <h2 style="margin-bottom: 20px;">Tareas Entregadas</h2>
            <?php if(count($tareas_entregadas) == 0): ?>
                <div class="stat-card" style="text-align: center; cursor:pointer;" onclick="switchNav('pendientes', document.querySelector('.nav-item:nth-child(2)'))">
                    <i data-lucide="inbox" style="width:48px; height:48px; color:#94a3b8; margin-bottom:10px;"></i>
                    <p>Aún no has entregado ninguna tarea.</p>
                </div>
            <?php else: ?>
                <?php foreach($tareas_entregadas as $tarea): ?>
                    <div class="task-item" style="background:var(--bg-hover); border: 1px solid var(--border-color);">
                        <div style="display:flex; align-items:center; gap: 15px;">
                            <div style="background: #e2e8f0; color:var(--text-muted); width:45px; height:45px; border-radius:10px; display:flex; align-items:center; justify-content:center;">
                                <i data-lucide="check-circle"></i>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 16px; color:var(--text-dark);"><?= htmlspecialchars($tarea['titulo']) ?></div>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 5px; display:flex; align-items:center; gap:15px;">
                                    <span style="display:flex; align-items:center; gap:5px;"><i data-lucide="book" style="width:14px;"></i> <?= htmlspecialchars($tarea['materia']) ?></span>
                                    <span style="display:flex; align-items:center; gap:5px; color:#10b981;"><i data-lucide="clock" style="width:14px;"></i> Entregado el: <?= htmlspecialchars($tarea['fecha_entrega']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if($tarea['archivo_entregado']): ?>
                        <div style="display:flex; gap:10px;">
                            <a href="uploads/<?= htmlspecialchars($tarea['archivo_entregado']) ?>" target="_blank" class="btn btn-outline" style="font-size:12px; padding:6px 12px;"><i data-lucide="download" style="width:14px;"></i> Tu archivo</a>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('¿Estás seguro de anular el envío? Si enviaste en equipo, se anulará para todos.');">
                                <input type="hidden" name="action" value="anular_entrega">
                                <input type="hidden" name="id_actividad_anular" value="<?= $tarea['id_actividad'] ?>">
                                <button type="submit" class="btn" style="font-size:12px; padding:6px 12px; background:rgba(239, 68, 68, 0.15); color:#ef4444; border:1px solid #f87171;"><i data-lucide="x-circle" style="width:14px;"></i> Anular envío</button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span class="badge" style="background:#e2e8f0; color:#475569;">Sin archivo</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- CONTENEDOR CALIFICACIONES -->
        <div id="container-calificaciones" style="display:none;">
            <h2 style="margin-bottom: 20px;">Calificaciones</h2>
            <?php if(count($tareas_calificadas) == 0): ?>
                <div class="stat-card" style="text-align: center; cursor:pointer;" onclick="switchNav('pendientes', document.querySelector('.nav-item:nth-child(2)'))">
                    <i data-lucide="award" style="width:48px; height:48px; color:#94a3b8; margin-bottom:10px;"></i>
                    <p>Aún no tienes tareas calificadas.</p>
                </div>
            <?php else: ?>
                <?php foreach($tareas_calificadas as $tarea): ?>
                    <div class="card" style="margin-bottom: 15px; border-left: 4px solid #8b5cf6;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                            <div>
                                <div style="font-weight: 600; font-size: 18px; color:var(--text-dark); margin-bottom:5px;"><?= htmlspecialchars($tarea['titulo']) ?></div>
                                <div style="font-size: 13px; color: var(--text-muted); display:flex; align-items:center; gap:15px; margin-bottom: 15px;">
                                    <span style="display:flex; align-items:center; gap:5px;"><i data-lucide="book" style="width:14px;"></i> <?= htmlspecialchars($tarea['materia']) ?></span>
                                </div>
                                
                                <div style="background:var(--bg-hover); padding:15px; border-radius:8px; border:1px solid var(--border-color);">
                                    <div style="font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:5px; text-transform:uppercase;">Comentarios del Docente:</div>
                                    <div style="color:var(--text-dark); font-size:14px;"><?= nl2br(htmlspecialchars($tarea['retroalimentacion'] ?? 'Buen trabajo.')) ?></div>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:12px; color:var(--text-muted); margin-bottom:5px;">Calificación</div>
                                <div style="font-size:28px; font-weight:700; color:#8b5cf6;"><?= htmlspecialchars($tarea['puntos_obtenidos']) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- CONTENEDOR CALENDARIO -->
        <div id="container-calendario" style="display:none;">
            
            <h2 style="margin-bottom: 20px; display:flex; align-items:center; gap:10px;"><i data-lucide="calendar-days" style=""></i> Próximos Eventos</h2>
            
            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 30px;">
                <?php foreach($proximos_eventos as $ev): ?>
                    <div class="card" style="border-left: 4px solid <?= $ev['color'] ? htmlspecialchars($ev['color']) : '#3b82f6' ?>; cursor:pointer; display: flex; justify-content: space-between; align-items: center; padding: 12px 20px;" onclick="document.getElementById('modalVerEvento').style.display='flex'; document.getElementById('ver-titulo').innerText='<?= htmlspecialchars(addslashes($ev['titulo'])) ?>'; document.getElementById('ver-fechas').innerText='<?= date('d/m/Y h:i A', strtotime($ev['fecha_inicio'])) ?>'; document.getElementById('ver-descripcion').innerHTML='<?= htmlspecialchars(addslashes($ev['descripcion'] ?? 'Sin descripción')) ?>';">
                        <div style="flex: 1;">
                            <h4 style="margin-bottom: 2px; font-size: 16px; color: var(--text-dark);"><?= htmlspecialchars($ev['titulo']) ?></h4>
                            <div style="font-size: 13px; color: var(--text-muted); display:flex; align-items:center; gap:5px;">
                                <i data-lucide="calendar" style="width:14px;"></i> <?= date('d M Y, h:i A', strtotime($ev['fecha_inicio'])) ?>
                            </div>
                        </div>
                        <?php 
                            $uText = ''; $uColor = ''; $uIcon = '';
                            if(($ev['urgencia'] ?? 'media') === 'alta') { $uText = 'Urgencia Alta'; $uColor = '#ef4444'; $uIcon = 'alert-triangle'; }
                            elseif(($ev['urgencia'] ?? 'media') === 'baja') { $uText = 'Urgencia Baja'; $uColor = '#10b981'; $uIcon = 'info'; }
                            else { $uText = 'Urgencia Media'; $uColor = '#f59e0b'; $uIcon = 'alert-circle'; }
                        ?>
                        <div style="font-size: 13px; color: <?= $uColor ?>; font-weight:600; display:flex; align-items:center; gap:5px; background: <?= $uColor ?>15; padding: 4px 10px; border-radius: 20px;">
                            <i data-lucide="<?= $uIcon ?>" style="width:14px;"></i> <?= $uText ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if(count($proximos_eventos) == 0): ?>
                    <div class="card" style="grid-column: 1 / -1; text-align: center; color: var(--text-muted); padding: 40px;">
                        <i data-lucide="calendar-check" style="width:48px; height:48px; margin-bottom:10px;"></i>
                        <p>No hay eventos escolares próximos programados.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div style="background:var(--card-bg); padding:20px; border-radius:12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); max-width: 900px; margin: 0 auto; border: 1px solid var(--border-color);">
                <div id='calendar' style="min-height: 400px; font-size: 14px;"></div>
            </div>
        </div>

        <div id="detalles-tarea" style="display: none;">
            <div class="card" style="max-width: 900px; margin: 0 auto;">
                <div class="task-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom:20px; margin-bottom:0;">
                    <div class="icon-box"><i data-lucide="book-open"></i></div>
                    <div>
                        <div class="task-title" id="t-titulo">Título</div>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top:5px;" id="t-meta">Materia</div>
                    </div>
                </div>
                
                <div class="tabs" style="margin-top:20px;">
                    <div class="tab active" id="tab-instrucciones" onclick="switchTab('instrucciones')">Instrucciones y Recursos</div>
                    <div class="tab" id="tab-entrega" onclick="switchTab('entrega')">Mi Entrega</div>
                </div>

                <!-- Tab: Instrucciones -->
                <div id="content-instrucciones">
                    <div style="background: var(--bg-hover); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 20px;">
                        <div class="ql-editor" id="t-desc"></div>
                    </div>
                    
                    <div id="recursos-container" style="display:none; margin-bottom: 20px;">
                        <h4 style="font-size:14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;"><i data-lucide="paperclip" style="width:18px;"></i> Archivos Adjuntos</h4>
                        <div id="recursos-list" style="display:flex; flex-direction:column; gap:10px;"></div>
                    </div>
                    
                    <button type="button" class="btn btn-primary" onclick="switchTab('entrega')">Ir a Entregar <i data-lucide="arrow-right" style="width:18px; height:18px;"></i></button>
                    <button type="button" class="btn btn-outline" onclick="volver()">Regresar</button>
                </div>

                <!-- Tab: Entrega -->
                <div id="content-entrega" style="display: none;">
                    
                    <div id="content-entrega-normal">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="id_actividad" id="tarea-id" value="">
                        
                        <div id="msg-bloqueado" style="display:none; background:rgba(239, 68, 68, 0.15); color:#ef4444; padding:20px; border-radius:8px; border:1px solid #f87171; text-align:center; margin-bottom:20px;">
                            <div style="display:flex; justify-content:center; margin-bottom:10px;"><i data-lucide="lock" style="width:32px; height:32px;"></i></div>
                            <h4 style="margin-bottom:5px; font-size:16px;">Entregas Cerradas</h4>
                            <p style="font-size:14px;">El periodo de entrega para esta actividad ha finalizado y el docente no acepta entregas tardías.</p>
                        </div>

                        <div id="form-subida">
                        <div class="form-group">
                            <label style="font-weight:600; font-size:15px; margin-bottom:15px;"><i data-lucide="upload" style="color:#2563eb; width:20px; height:20px; margin-bottom:-4px;"></i> 1. Sube tu archivo</label>
                            
                            <div class="upload-area" id="dropZoneContainer" onclick="document.getElementById('fileInput').click()">
                                <div style="display:flex; justify-content:center; margin-bottom: 10px;"><i data-lucide="cloud-upload" style="width:40px; height:40px; color:var(--primary-color);"></i></div>
                                <div style="font-weight: 600; margin-bottom: 5px; color:#2563eb;">Arrastra y suelta tu archivo aquí</div>
                                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">o haz clic para buscar</div>
                                <input type="file" name="archivo" id="fileInput" required style="display:none" onchange="showFileName(this)">
                                <div style="font-size: 11px; color: #94a3b8; margin-top: 15px;">Formatos permitidos: PDF, DOCX, ZIP | Máximo: 50MB</div>
                            </div>

                            <div id="filePreviewContainer" style="display:none; align-items:center; justify-content:space-between; background: rgba(79, 70, 229, 0.1); border: 1px solid #c7d2fe; border-radius: 8px; padding: 12px 15px;">
                                <div style="display:flex; align-items:center; gap: 10px;">
                                    <i data-lucide="file-check-2" style="color:#2563eb; width:20px; height:20px;"></i>
                                    <span id="fileNameDisplay" style="font-weight: 600; color: var(--text-dark); font-size: 14px;"></span>
                                </div>
                                <button type="button" onclick="removeFile(event)" style="background:transparent; border:none; outline:none; color:#ef4444; cursor:pointer; display:flex; align-items:center; gap:5px; font-size:13px; font-weight:600; padding:5px; border-radius:4px;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                                    <i data-lucide="trash-2" style="width:16px; height:16px;"></i> Eliminar
                                </button>
                            </div>
                        </div>
                        
                        <div id="seccion-equipo" class="form-group" style="display:none; background:var(--bg-hover); padding:15px; border-radius:8px; border:1px solid var(--border-color);">
                            <label style="font-weight:600; font-size:15px; color:var(--text-dark); display:flex; align-items:center; gap:8px;">
                                <i data-lucide="users" style="color:#8b5cf6;"></i> Selecciona a tu equipo
                            </label>
                            <p style="font-size:12px; color:var(--text-muted); margin-bottom:10px;">Al marcar a tus compañeros, esta tarea se enviará para todos ellos automáticamente.</p>
                            <div class="teammate-list">
                                <?php foreach($companeros as $comp): ?>
                                <label class="teammate-item">
                                    <input type="checkbox" name="teammates[]" value="<?= $comp['id_alumno'] ?>">
                                    <span style="font-size:14px; font-weight:500; color:var(--text-dark);"><?= htmlspecialchars($comp['nombre']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                            <textarea name="comentario" class="form-control" rows="3" placeholder="Escribe un comentario para tu docente..."></textarea>
                        </div>

                        <div style="background: #eff6ff; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 10px; color: #1e40af; font-size: 14px;">
                            <i data-lucide="info" style="width:20px; flex-shrink:0;"></i> 
                            <div>Una vez entregada, ya no podrás modificar tu archivo. Asegúrate de revisar antes de enviar.</div>
                        </div>

                        <div style="display: flex; gap: 15px;">
                            <button type="button" class="btn btn-outline" onclick="volver()">Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i data-lucide="send" style="width:18px; height:18px;"></i> Entregar actividad</button>
                        </div>
                        </div> <!-- form-subida -->
                    </form>
                    </div>

                    <div id="content-entrega-confirmacion" style="display:none; text-align:center; padding: 40px 20px;">
                        <div style="display:flex; justify-content:center; margin-bottom:20px;">
                            <div style="background:#f3e8ff; padding:20px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                <i data-lucide="users" style="width:48px; height:48px; color:#8b5cf6;"></i>
                            </div>
                        </div>
                        <h3 style="margin-bottom:10px; font-size:22px; color:var(--text-dark);">¡Tienes una invitación de equipo!</h3>
                        <p style="color:var(--text-muted); margin-bottom:20px; font-size:15px;" id="inv-comentario"></p>
                        
                        <div style="background:var(--bg-hover); border:1px solid var(--border-color); border-radius:12px; padding:20px; margin-bottom:30px; display:inline-flex; align-items:center; gap:15px; max-width:100%;">
                            <i data-lucide="file-check-2" style="color:#2563eb; width:24px; height:24px;"></i>
                            <span id="inv-archivo" style="font-weight:600; color:var(--text-dark); font-size:16px;"></span>
                        </div>
                        
                        <div style="display:flex; justify-content:center; gap:20px;">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="rechazar_equipo">
                                <input type="hidden" name="id_actividad" class="tarea-id-conf">
                                <button type="submit" class="btn" style="background:rgba(239, 68, 68, 0.15); color:#ef4444; border:1px solid #f87171; padding: 12px 24px; font-size:15px;"><i data-lucide="x" style="width:18px;"></i> Rechazar</button>
                            </form>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="aceptar_equipo">
                                <input type="hidden" name="id_actividad" class="tarea-id-conf">
                                <button type="submit" class="btn btn-primary" style="padding: 12px 24px; font-size:15px;"><i data-lucide="check" style="width:18px;"></i> Aceptar y Entregar</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();

        function switchNav(view, element) {
            // Update active state in sidebar
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            if(element) element.classList.add('active');

            // Hide all views
            const containerPendientes = document.getElementById('container-pendientes');
            if (containerPendientes) containerPendientes.style.display = 'none';
            const containerCalificaciones = document.getElementById('container-calificaciones');
            if (containerCalificaciones) containerCalificaciones.style.display = 'none';
            const containerCalendario = document.getElementById('container-calendario');
            if (containerCalendario) containerCalendario.style.display = 'none';
            const detallesTarea = document.getElementById('detalles-tarea');
            if (detallesTarea) detallesTarea.style.display = 'none';

            // Show selected view
            if (view === 'entregas') {
                if (containerPendientes) containerPendientes.style.display = 'block';
                switchTaskTab('completado', document.getElementById('btn-tab-completado') || document.querySelector('.task-tab:nth-child(3)'));
            } else {
                const selectedContainer = document.getElementById('container-' + view);
                if (selectedContainer) selectedContainer.style.display = 'block';
            }

            // Update Breadcrumb
            const titles = {
                'pendientes': 'Pendientes',
                'entregas': 'Historial de Entregas',
                'calificaciones': 'Calificaciones Obtenidas',
                'calendario': 'Calendario Escolar'
            };
            document.getElementById('breadcrumb-current').innerText = titles[view];
            
            if (view === 'calendario' && calendar) {
                calendar.render();
            }
        }

        function switchTab(tab) {
            document.getElementById('tab-instrucciones').classList.remove('active');
            document.getElementById('tab-entrega').classList.remove('active');
            
            document.getElementById('content-instrucciones').style.display = 'none';
            document.getElementById('content-entrega').style.display = 'none';
            
            document.getElementById('tab-' + tab).classList.add('active');
            document.getElementById('content-' + tab).style.display = 'block';
        }

                function switchTaskTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
            document.getElementById('tab-' + tabId).style.display = 'block';
            
            document.querySelectorAll('.task-tab').forEach(el => {
                el.classList.remove('active');
                el.style.color = 'var(--text-muted)';
                el.style.borderBottomColor = 'transparent';
            });
            
            btn.classList.add('active');
            btn.style.color = 'var(--primary-color)';
            btn.style.borderBottomColor = 'var(--primary-color)';
        }

        function verTarea(tareaData) {
            document.getElementById('tarea-id').value = tareaData.id_actividad;
            document.querySelectorAll('.tarea-id-conf').forEach(el => el.value = tareaData.id_actividad);
            document.getElementById('t-titulo').innerText = tareaData.titulo;
            document.getElementById('t-meta').innerText = 'Materia: ' + tareaData.materia + ' | Vence: ' + tareaData.fecha_limite;
            
            if(tareaData.estatus === 'esperando_confirmacion') {
                document.getElementById('content-entrega-normal').style.display = 'none';
                document.getElementById('content-entrega-confirmacion').style.display = 'block';
                document.getElementById('inv-archivo').innerText = tareaData.archivo_invitacion;
                document.getElementById('inv-comentario').innerText = tareaData.comentario_invitacion;
            } else {
                document.getElementById('content-entrega-normal').style.display = 'block';
                document.getElementById('content-entrega-confirmacion').style.display = 'none';
                
                // Evaluar entregas tardías
                var now = new Date();
                var limite = new Date(tareaData.fecha_limite);
                if (tareaData.permitir_tarde == 0 && now > limite) {
                    document.getElementById('form-subida').style.display = 'none';
                    document.getElementById('msg-bloqueado').style.display = 'block';
                } else {
                    document.getElementById('form-subida').style.display = 'block';
                    document.getElementById('msg-bloqueado').style.display = 'none';
                }
            }
            
            // Set description (Quill HTML)
            document.getElementById('t-desc').innerHTML = tareaData.descripcion || 'Sin descripción adicional.';
            
            // Handle Resources
            const recursosContainer = document.getElementById('recursos-container');
            const recursosList = document.getElementById('recursos-list');
            recursosList.innerHTML = '';
            
            if(tareaData.archivo_recurso) {
                try {
                    let archivos = JSON.parse(tareaData.archivo_recurso);
                    if(archivos && archivos.length > 0) {
                        recursosContainer.style.display = 'block';
                        archivos.forEach(archivo => {
                            recursosList.innerHTML += `
                                <div style="display:flex; align-items:center; justify-content:space-between; background:var(--card-bg); border:1px solid var(--border-color); padding:10px 15px; border-radius:8px;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <i data-lucide="file" style="color:var(--text-muted); width:18px;"></i>
                                        <span style="font-size:14px; font-weight:500;">${archivo}</span>
                                    </div>
                                    <a href="uploads/recursos/${archivo}" target="_blank" class="btn btn-outline" style="padding:6px 12px; font-size:12px; display:flex; align-items:center; gap:5px;">
                                        <i data-lucide="download" style="width:14px;"></i> Descargar
                                    </a>
                                </div>
                            `;
                        });
                        lucide.createIcons();
                    } else {
                        recursosContainer.style.display = 'none';
                    }
                } catch(e) {
                    // Fallback for single old file format
                    recursosContainer.style.display = 'block';
                    recursosList.innerHTML = `
                        <div style="display:flex; align-items:center; justify-content:space-between; background:var(--card-bg); border:1px solid var(--border-color); padding:10px 15px; border-radius:8px;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <i data-lucide="file" style="width:18px;"></i>
                                <span style="font-size:14px; font-weight:500;">${tareaData.archivo_recurso}</span>
                            </div>
                            <a href="uploads/recursos/${tareaData.archivo_recurso}" target="_blank" class="btn btn-outline" style="padding:6px 12px; font-size:12px; display:flex; align-items:center; gap:5px;">
                                <i data-lucide="download" style="width:14px;"></i> Descargar
                            </a>
                        </div>
                    `;
                    lucide.createIcons();
                }
            } else {
                recursosContainer.style.display = 'none';
            }

            // Handle Team section
            if(tareaData.modalidad === 'equipo') {
                document.getElementById('seccion-equipo').style.display = 'block';
            } else {
                document.getElementById('seccion-equipo').style.display = 'none';
                // uncheck all teammates
                document.querySelectorAll('input[name="teammates[]"]').forEach(cb => cb.checked = false);
            }

            document.getElementById('container-pendientes').style.display = 'none';
            document.getElementById('detalles-tarea').style.display = 'block';
            
            // Default to instructions tab
            switchTab('instrucciones');
            
            // Mark as viewed via simple fetch
            if(tareaData.estatus === 'no_visto') {
                fetch('api/update_estatus.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'id_actividad=' + tareaData.id_actividad + '&estatus=visto'
                });
            }
        }

        function volver() {
            document.getElementById('detalles-tarea').style.display = 'none';
            document.getElementById('container-pendientes').style.display = 'block';
        }

        function showFileName(input) {
            var display = document.getElementById('fileNameDisplay');
            var container = document.getElementById('filePreviewContainer');
            var dropZone = document.getElementById('dropZoneContainer');
            
            if(input.files && input.files[0]) {
                display.innerText = input.files[0].name;
                container.style.display = 'flex';
                dropZone.style.display = 'none';
            } else {
                removeFile();
            }
        }

        function removeFile(event) {
            if(event) event.stopPropagation();
            var input = document.getElementById('fileInput');
            input.value = '';
            document.getElementById('filePreviewContainer').style.display = 'none';
            document.getElementById('dropZoneContainer').style.display = 'flex'; // It's usually block, but we use flex for inner centering
            document.getElementById('dropZoneContainer').style.flexDirection = 'column';
        }

        function toggleNotificaciones() {
            var dropdown = document.getElementById('notif-dropdown');
            dropdown.classList.toggle('show');
            var badge = document.getElementById('notif-badge');
            if (dropdown.classList.contains('show') && badge) {
                fetch('api_notificaciones.php', { method: 'POST' }).then(r => { if(r.ok) badge.style.display='none'; });
            }
        }
        
        window.onclick = function(e) {
            if (!e.target.closest('.notification-container')) {
                document.querySelectorAll(".notification-dropdown").forEach(el => el.classList.remove('show'));
            }
            if (e.target.id === 'modalVerEvento') {
                document.getElementById('modalVerEvento').style.display = 'none';
            }
        }
        
        var calendar;
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'es',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', day: 'Día' },
                events: 'api_calendario.php',
                eventContent: function(arg) {
                    let iconHtml = '';
                    if(arg.event.extendedProps.tipo === 'tarea') {
                        iconHtml = '<i data-lucide="book-open" style="width:14px; height:14px; margin-right:4px; margin-left:2px; vertical-align:middle;"></i>';
                    } else {
                        iconHtml = '<i data-lucide="calendar" style="width:14px; height:14px; margin-right:4px; margin-left:2px; vertical-align:middle;"></i>';
                    }
                    
                    setTimeout(() => lucide.createIcons(), 10);
                    return { 
                        html: `<div style="display:flex; align-items:center; overflow:hidden; white-space:nowrap; padding:2px; color:#fff; height: 22px; box-sizing: border-box;">
                                ${iconHtml} <span style="text-overflow:ellipsis; overflow:hidden; font-weight:500; font-size:12.5px;">${arg.event.title}</span>
                               </div>`
                    };
                },
                eventClick: function(info) {
                    info.jsEvent.preventDefault();
                    var props = info.event.extendedProps;
                    
                    if (props.tipo === 'tarea') {
                        // Navegar a pendientes
                        switchNav('pendientes', document.querySelector('.nav-item[onclick*="pendientes"]'));
                        
                        // Fake data for verTarea
                        let tareaData = {
                            id_actividad: info.event.id.replace('tarea_', ''),
                            titulo: info.event.title.replace('📝 ', ''),
                            materia: 'Ver en tus pendientes', 
                            fecha_limite: info.event.startStr,
                            estatus: props.estatus || 'no_visto',
                            archivo_invitacion: '',
                            comentario_invitacion: ''
                        };
                        verTarea(tareaData);
                        
                        // Set the description
                        document.getElementById('t-desc').innerHTML = props.descripcion || 'Sin descripción adicional.';
                        return; // Detener aquí para no abrir el modal general
                    }
                    
                    document.getElementById('ver-titulo').innerText = info.event.title;
                    let fInicio = info.event.start ? info.event.start.toLocaleString() : '';
                    let fFin = info.event.end ? ' - ' + info.event.end.toLocaleString() : '';
                    document.getElementById('ver-fechas').innerText = fInicio + fFin;
                    
                    let uText = ''; let uColor = ''; let uIcon = '';
                    if(props.urgencia === 'alta') { uText = 'Urgencia Alta'; uColor = '#ef4444'; uIcon = 'alert-triangle'; }
                    else if(props.urgencia === 'baja') { uText = 'Urgencia Baja'; uColor = '#10b981'; uIcon = 'info'; }
                    else { uText = 'Urgencia Media'; uColor = '#f59e0b'; uIcon = 'alert-circle'; }
                    document.getElementById('ver-urgencia').innerHTML = `<span style="color:${uColor}; display:flex; align-items:center; gap:5px;"><i data-lucide="${uIcon}" style="width:16px;"></i> ${uText}</span>`;
                    lucide.createIcons();
                    
                    document.getElementById('ver-descripcion').innerHTML = props.descripcion || 'Sin descripción adicional.';
                    
                    document.getElementById('modalVerEvento').style.display = 'flex';
                }
            });
        });
    </script>
    
    <!-- Modal Ver Evento Alumno -->
    <div id="modalVerEvento" class="modal" style="display:none; align-items:center; justify-content:center;">
        <div class="modal-content" style="max-width: 500px; width: 90%; padding: 0; border-radius: 16px; overflow: hidden; border: none; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1); animation: modalFadeIn 0.3s ease;">
            <!-- Header -->
            <div style="background: linear-gradient(135deg, #1e293b, #0f172a); padding: 25px; color: white;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div style="flex: 1; padding-right: 15px;">
                        <h3 style="margin: 0; font-size: 20px; font-weight: 700; display:flex; align-items:center; gap:10px; line-height: 1.3;">
                            <i data-lucide="calendar-check" style="width:22px; height:22px; color:#60a5fa; flex-shrink:0;"></i> 
                            <span id="ver-titulo">Título del Evento</span>
                        </h3>
                        <div style="font-size: 13.5px; color: #94a3b8; margin-top: 10px; display:flex; align-items:center; gap:6px;">
                            <i data-lucide="clock" style="width:14px; height:14px;"></i> 
                            <span id="ver-fechas">...</span>
                        </div>
                    </div>
                    <button onclick="document.getElementById('modalVerEvento').style.display='none'" style="background: rgba(255,255,255,0.1); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display:flex; justify-content:center; align-items:center; transition: all 0.2s; flex-shrink:0;" onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i data-lucide="x" style="width:16px;"></i>
                    </button>
                </div>
            </div>
            
            <!-- Body -->
            <div style="padding: 25px; background: var(--bg-card, #fff);">
                <div style="margin-bottom: 5px;">
                    <h4 style="font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 12px; display:flex; align-items:center; gap:6px;">
                        <i data-lucide="info" style="width:14px; height:14px;"></i> Información Adicional
                    </h4>
                    <div id="ver-descripcion" style="font-size: 14.5px; color: var(--text-color, #334155); line-height: 1.6; background: var(--bg-hover, #f8fafc); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color, #e2e8f0);">
                        <!-- descripción -->
                    </div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; margin-top: 25px;">
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('modalVerEvento').style.display='none'" style="padding: 10px 24px; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);">Entendido</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute("data-theme") || "dark";
            const newTheme = currentTheme === "dark" ? "light" : "dark";
            document.documentElement.setAttribute("data-theme", newTheme);
            
            let oldIcon = document.getElementById("theme-icon");
            if(oldIcon) {
                let newIcon = document.createElement("i");
                newIcon.id = "theme-icon";
                newIcon.setAttribute("data-lucide", newTheme === "light" ? "moon" : "sun");
                oldIcon.parentNode.replaceChild(newIcon, oldIcon);
            }
            
            const text = document.getElementById("theme-text");
            if (newTheme === "light") {
                if(text) text.innerText = "Modo Oscuro";
            } else {
                if(text) text.innerText = "Modo Claro";
            }
            if(window.lucide) lucide.createIcons();
            
            localStorage.setItem("theme", newTheme);
        }
        
        document.addEventListener("DOMContentLoaded", () => {
            const savedTheme = localStorage.getItem("theme");
            if (savedTheme) {
                document.documentElement.setAttribute("data-theme", savedTheme);
                let oldIcon = document.getElementById("theme-icon");
                if(oldIcon) {
                    let newIcon = document.createElement("i");
                    newIcon.id = "theme-icon";
                    newIcon.setAttribute("data-lucide", savedTheme === "light" ? "moon" : "sun");
                    oldIcon.parentNode.replaceChild(newIcon, oldIcon);
                }
                const text = document.getElementById("theme-text");
                if (savedTheme === "light") {
                    if(text) text.innerText = "Modo Oscuro";
                } else {
                    if(text) text.innerText = "Modo Claro";
                }
                if(window.lucide) lucide.createIcons();
            }
        });
    </script>

</body>
</html>
