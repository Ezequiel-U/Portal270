<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'docente') {
    header("Location: index.php");
    exit;
}

$docente_id = $_SESSION['usuario_id'];

// Obtener info docente
$stmt = $pdo->prepare("SELECT id_docente FROM docentes WHERE id_usuario = ?");
$stmt->execute([$docente_id]);
$info_docente = $stmt->fetch();
$id_docente_db = $info_docente['id_docente'];

// Procesar calificación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calificar'])) {
    $ids_entregas = explode(',', $_POST['ids_entregas']);
    $ids_alumnos = explode(',', $_POST['ids_alumnos']);
    $id_actividad = $_POST['id_actividad'];
    $puntos = $_POST['puntos'];
    $retroalimentacion = $_POST['retroalimentacion'];
    
    $pdo->beginTransaction();
    try {
        $stmtCal = $pdo->prepare("INSERT INTO calificaciones (id_entrega, id_docente, puntos_obtenidos, retroalimentacion) VALUES (?, ?, ?, ?)");
        $stmtUpdEnt = $pdo->prepare("UPDATE entregas SET estado = 'revisado' WHERE id_entrega = ?");
        $stmtUpdAct = $pdo->prepare("UPDATE actividad_alumno SET estatus = 'revisado' WHERE id_actividad = ? AND id_alumno = ?");
        
        foreach($ids_entregas as $idx => $id_entrega) {
            $id_alumno = $ids_alumnos[$idx];
            $stmtCal->execute([$id_entrega, $id_docente_db, $puntos, $retroalimentacion]);
            $stmtUpdEnt->execute([$id_entrega]);
            $stmtUpdAct->execute([$id_actividad, $id_alumno]);
        }
        $pdo->commit();
        header("Location: revisar_entregas.php?msg=calificado");
        exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}


// Obtener entregas pendientes de revisión para este docente
// Join con actividades para saber cuáles son suyas, y con alumnos/usuarios para ver quién lo envió
$stmt = $pdo->prepare("
    SELECT MAX(e.id_entrega) as id_entrega, e.archivo, MAX(e.comentario) as comentario, MAX(e.fecha_entrega) as fecha_entrega, 
           a.id_actividad, a.titulo, a.materia,
           GROUP_CONCAT(al.id_alumno) as ids_alumnos,
           GROUP_CONCAT(e.id_entrega) as ids_entregas,
           GROUP_CONCAT(u.nombre SEPARATOR ', ') as nombres_equipo,
           MAX(g.grupo) as grupo, MAX(g.semestre) as semestre, MAX(c.nombre) as carrera
    FROM entregas e
    JOIN actividades a ON e.id_actividad = a.id_actividad
    JOIN alumnos al ON e.id_alumno = al.id_alumno
    JOIN usuarios u ON al.id_usuario = u.id_usuario
    JOIN grupos g ON al.id_grupo = g.id_grupo
    JOIN carreras c ON g.id_carrera = c.id_carrera
    WHERE a.id_docente = ? AND e.estado = 'entregado'
    GROUP BY a.id_actividad, e.archivo
    ORDER BY MAX(e.fecha_entrega) DESC
");
$stmt->execute([$id_docente_db]);
$entregas_pendientes = $stmt->fetchAll();

// Obtener Notificaciones
$stmtNotif = $pdo->prepare("SELECT * FROM notificaciones WHERE id_usuario = ? ORDER BY fecha DESC LIMIT 10");
$stmtNotif->execute([$docente_id]);
$notificaciones = $stmtNotif->fetchAll();
$notif_no_leidas = array_filter($notificaciones, function($n) { return $n['leida'] == 0; });
$num_no_leidas = count($notif_no_leidas);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Docente - Revisar Entregas</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        function mostrarPanelCalificacion(id_entrega) {
            // Ocultar todos los paneles
            document.querySelectorAll('.panel-calificacion').forEach(el => el.style.display = 'none');
            // Mostrar el seleccionado
            document.getElementById('panel-' + id_entrega).style.display = 'block';
        }
        function ocultarPanel(id_entrega) {
            document.getElementById('panel-' + id_entrega).style.display = 'none';
        }
        function toggleNotificaciones() {
            var dropdown = document.getElementById('notif-dropdown');
            dropdown.classList.toggle('show');
            
            // Si se abre el menú y hay notificaciones no leídas, marcarlas como leídas
            var badge = document.getElementById('notif-badge');
            if (dropdown.classList.contains('show') && badge) {
                fetch('api_notificaciones.php', { method: 'POST' })
                .then(response => {
                    if(response.ok) {
                        badge.style.display = 'none'; // Ocultar el globito rojo
                    }
                });
            }
        }
        // Cerrar dropdown al hacer clic fuera
        window.onclick = function(event) {
            if (!event.target.closest('.notification-container')) {
                var dropdowns = document.getElementsByClassName("notification-dropdown");
                for (var i = 0; i < dropdowns.length; i++) {
                    var openDropdown = dropdowns[i];
                    if (openDropdown.classList.contains('show')) {
                        openDropdown.classList.remove('show');
                    }
                }
            }
        }
    </script>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <div class="profile-pic" style="background:#fff; margin:0 auto 10px;"></div>
            <div class="profile-name"><?= htmlspecialchars($_SESSION['nombre']) ?></div>
            <div class="profile-role">Docente | CBTis 270</div>
        </div>
        <ul class="nav-links">
            <li><a href="docente.php" class="nav-item">Asignar Tareas</a></li>
            <li><a href="gestionar_tareas.php" class="nav-item">Administrar Tareas</a></li>
            <li><a href="revisar_entregas.php" class="nav-item active">Revisar Entregas</a></li>
            <li><a href="calendario_docente.php" class="nav-item">Calendario</a></li>
        </ul>
        <div style="margin-top: auto; padding: 20px;">
            <a href="index.php" class="nav-item">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="header">
            <div class="breadcrumb">
                Revisar Entregas > <span>Pendientes</span>
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
                            <div style="padding: 15px; text-align:center; color: #64748b;">No tienes notificaciones</div>
                        <?php else: ?>
                            <?php foreach($notificaciones as $n): ?>
                                <div class="notif-item">
                                    <div class="notif-icon"><i data-lucide="mail"></i></div>
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
        
        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'calificado'): ?>
            <div style="background: var(--success-bg); color: var(--success-text); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                ¡Calificación guardada correctamente! La tarea ha sido marcada como revisada.
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Entregas de Alumnos</h2>
            
            <?php if(count($entregas_pendientes) == 0): ?>
                <p>No tienes entregas pendientes por revisar. ¡Todo al día!</p>
            <?php else: ?>
                <?php foreach($entregas_pendientes as $entrega): ?>
                    <div class="task-item" style="display: flex; flex-direction: column; align-items: stretch;">
                        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                            <div>
                                <div style="font-weight: 600; font-size: 16px;"><?= htmlspecialchars($entrega['titulo']) ?></div>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 5px;">
                                    Entregado por: <strong style="color: var(--text-dark);"><?= htmlspecialchars($entrega['nombres_equipo']) ?></strong> 
                                    <br>(<?= htmlspecialchars($entrega['semestre']) ?>º <?= htmlspecialchars($entrega['grupo']) ?> <?= htmlspecialchars($entrega['carrera']) ?>)
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                                    Entregado el: <?= htmlspecialchars($entrega['fecha_entrega']) ?>
                                </div>
                            </div>
                            <button class="btn btn-outline" onclick="mostrarPanelCalificacion(<?= $entrega['id_entrega'] ?>)">Revisar</button>
                        </div>
                        
                        <!-- Panel de calificación oculto por defecto -->
                        <div id="panel-<?= $entrega['id_entrega'] ?>" class="panel-calificacion" style="display: none; margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                            
                            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 style="margin-bottom: 10px;">Archivo Entregado:</h4>
                                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                                    <a href="visor.php?archivo=<?= urlencode($entrega['archivo']) ?>" target="_blank" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px;">
                                        Vista previa
                                    </a>
                                    <a href="uploads/<?= htmlspecialchars($entrega['archivo']) ?>" download class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 8px;">
                                        Descargar archivo
                                    </a>
                                </div>
                                
                                <?php if(!empty($entrega['comentario'])): ?>
                                    <div style="margin-top: 15px; font-size: 14px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                                        <strong>Comentario del alumno:</strong><br>
                                        <p style="color: var(--text-muted); font-style: italic; margin-top: 5px;">"<?= htmlspecialchars($entrega['comentario']) ?>"</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form method="POST">
                                <input type="hidden" name="calificar" value="1">
                                <input type="hidden" name="ids_entregas" value="<?= $entrega['ids_entregas'] ?>">
                                <input type="hidden" name="ids_alumnos" value="<?= $entrega['ids_alumnos'] ?>">
                                <input type="hidden" name="id_actividad" value="<?= $entrega['id_actividad'] ?>">
                                
                                <div class="form-group">
                                    <label>Calificación (Puntos de 0 a 100)</label>
                                    <input type="number" name="puntos" class="form-control" min="0" max="100" required placeholder="Ej: 100">
                                </div>

                                <div class="form-group">
                                    <label>Retroalimentación para el alumno</label>
                                    <textarea name="retroalimentacion" class="form-control" rows="3" required placeholder="Excelente trabajo, sigue así..."></textarea>
                                </div>

                                <div style="display: flex; gap: 15px;">
                                    <button type="button" class="btn btn-outline" onclick="ocultarPanel(<?= $entrega['id_entrega'] ?>)">Cancelar</button>
                                    <button type="submit" class="btn btn-primary">Guardar Calificación</button>
                                </div>
                            </form>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>
