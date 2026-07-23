<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'docente') {
    header("Location: index.php");
    exit;
}

$docente_id = $_SESSION['usuario_id'];

// Obtener info docente
$stmt = $pdo->prepare("SELECT d.* FROM docentes d WHERE d.id_usuario = ?");
$stmt->execute([$docente_id]);
$info_docente = $stmt->fetch();
$id_docente_db = $info_docente['id_docente'];

// Procesar Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'eliminar') {
    $id_actividad = $_POST['id_actividad'];
    
    // Al borrar la actividad, las claves foráneas ON DELETE CASCADE (si están configuradas) borrarán entregas y calificaciones.
    // Si no están configuradas así, las borramos manualmente por seguridad.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM calificaciones WHERE id_entrega IN (SELECT id_entrega FROM entregas WHERE id_actividad = ?)")->execute([$id_actividad]);
        $pdo->prepare("DELETE FROM entregas WHERE id_actividad = ?")->execute([$id_actividad]);
        $pdo->prepare("DELETE FROM actividad_alumno WHERE id_actividad = ?")->execute([$id_actividad]);
        $pdo->prepare("DELETE FROM actividades WHERE id_actividad = ? AND id_docente = ?")->execute([$id_actividad, $id_docente_db]);
        $pdo->commit();
        header("Location: gestionar_tareas.php?msg=eliminado");
        exit;
    } catch(Exception $e) {
        $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}

// Procesar Editar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar') {
    $id_actividad = $_POST['id_actividad'];
    $titulo = $_POST['titulo'];
    $descripcion = $_POST['descripcion']; // Contiene HTML
    $fecha_limite = $_POST['fecha_limite'];
    $permitir_tarde = isset($_POST['permitir_tarde']) ? 1 : 0;
    
    $archivos_subidos = [];
    if(isset($_FILES['recurso']) && !empty($_FILES['recurso']['name'][0])) {
        $countFiles = count($_FILES['recurso']['name']);
        for($i = 0; $i < $countFiles; $i++) {
            if($_FILES['recurso']['error'][$i] == 0) {
                $nombre_original = basename($_FILES['recurso']['name'][$i]);
                $archivo_nombre_guardado = $nombre_original;
                $ruta_destino = 'uploads/recursos/' . $archivo_nombre_guardado;
                $contador = 1;
                while(file_exists($ruta_destino)) {
                    $info = pathinfo($nombre_original);
                    $archivo_nombre_guardado = $info['filename'] . " ($contador)." . (isset($info['extension']) ? $info['extension'] : '');
                    $ruta_destino = 'uploads/recursos/' . $archivo_nombre_guardado;
                    $contador++;
                }
                move_uploaded_file($_FILES['recurso']['tmp_name'][$i], $ruta_destino);
                $archivos_subidos[] = $archivo_nombre_guardado;
            }
        }
    }
    
    if(!empty($archivos_subidos)) {
        $archivo_recurso = json_encode($archivos_subidos, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("UPDATE actividades SET titulo = ?, descripcion = ?, fecha_limite = ?, permitir_tarde = ?, archivo_recurso = ? WHERE id_actividad = ? AND id_docente = ?");
        $stmt->execute([$titulo, $descripcion, $fecha_limite, $permitir_tarde, $archivo_recurso, $id_actividad, $id_docente_db]);
    } else {
        $stmt = $pdo->prepare("UPDATE actividades SET titulo = ?, descripcion = ?, fecha_limite = ?, permitir_tarde = ? WHERE id_actividad = ? AND id_docente = ?");
        $stmt->execute([$titulo, $descripcion, $fecha_limite, $permitir_tarde, $id_actividad, $id_docente_db]);
    }
    
    // Notificar a los alumnos sobre la actualización
    $stmtAlumnos = $pdo->prepare("
        SELECT al.id_usuario FROM actividad_alumno aa 
        JOIN alumnos al ON aa.id_alumno = al.id_alumno 
        WHERE aa.id_actividad = ?
    ");
    $stmtAlumnos->execute([$id_actividad]);
    $alumnos_asignados = $stmtAlumnos->fetchAll();
    
    $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (id_usuario, titulo, mensaje, tipo) VALUES (?, 'Actividad Modificada', ?, 'tarea')");
    $msg = "El profesor ha actualizado las instrucciones o fecha de la actividad: " . $titulo;
    foreach($alumnos_asignados as $al) {
        $stmtNotif->execute([$al['id_usuario'], $msg]);
    }
    
    header("Location: gestionar_tareas.php?msg=editado");
    exit;
}

// Obtener todas las actividades
$stmt = $pdo->prepare("
    SELECT a.*, g.semestre, g.grupo, c.nombre as carrera
    FROM actividades a
    JOIN grupos g ON a.id_grupo = g.id_grupo
    JOIN carreras c ON g.id_carrera = c.id_carrera
    WHERE a.id_docente = ?
    ORDER BY a.id_actividad DESC
");
$stmt->execute([$id_docente_db]);
$actividades = $stmt->fetchAll();

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
    <title>Administrar Tareas - Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <style>
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: #fff; padding: 25px; border-radius: 12px;
            width: 100%; max-width: 500px;
        }
    </style>
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
            <li><a href="gestionar_tareas.php" class="nav-item active">Administrar Tareas</a></li>
            <li><a href="revisar_entregas.php" class="nav-item">Revisar Entregas</a></li>
            <li><a href="calendario_docente.php" class="nav-item">Calendario</a></li>
        </ul>
        <div style="margin-top: auto; padding: 20px;">
            <a href="index.php" class="nav-item">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="header">
            <div class="breadcrumb">
                Mis actividades > <span>Administrar Tareas</span>
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

        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'eliminado'): ?>
            <div style="background: #fee2e2; color: #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="info"></i> La actividad y todas sus entregas han sido eliminadas.
            </div>
        <?php elseif(isset($_GET['msg']) && $_GET['msg'] == 'editado'): ?>
            <div style="background: var(--success-bg); color: var(--success-text); padding: 15px; border-radius: 8px; margin-bottom: 20px; display:flex; align-items:center; gap:10px;">
                <i data-lucide="check-circle"></i> La actividad se ha actualizado correctamente.
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-bottom: 20px;">Tareas Creadas</h2>
            
            <?php if(count($actividades) == 0): ?>
                <div style="text-align:center; padding: 40px; color:#64748b;">
                    <i data-lucide="folder-open" style="width:48px; height:48px; margin-bottom:10px;"></i>
                    <p>Aún no has creado ninguna actividad.</p>
                </div>
            <?php else: ?>
                <?php foreach($actividades as $act): ?>
                    <div class="task-item" style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-weight: 600; font-size: 16px; color:#0f172a;"><?= htmlspecialchars($act['titulo']) ?></div>
                            <div style="font-size: 13px; color: #64748b; margin-top: 5px; display: flex; gap: 15px;">
                                <span style="display:flex; align-items:center;"><i data-lucide="book" style="width:14px; margin-right:4px;"></i> <?= htmlspecialchars($act['materia']) ?></span>
                                <span style="display:flex; align-items:center;"><i data-lucide="users" style="width:14px; margin-right:4px;"></i> <?= htmlspecialchars($act['semestre']) ?>º <?= htmlspecialchars($act['grupo']) ?> <?= htmlspecialchars($act['carrera']) ?></span>
                            </div>
                            <div style="font-size: 13px; color: #64748b; margin-top: 2px; display: flex; align-items: center; gap: 8px;">
                                <span>Fecha límite: <strong style="<?= strtotime($act['fecha_limite']) < time() ? 'color:#ef4444;' : 'color:#10b981;' ?>"><?= htmlspecialchars($act['fecha_limite']) ?></strong></span>
                                <?php if(strtotime($act['fecha_limite']) < time()): ?>
                                    <span style="background:#fee2e2; color:#ef4444; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:600;"><i data-lucide="clock-3" style="width:12px; display:inline-block; margin-bottom:-2px;"></i> Vencida</span>
                                <?php else: ?>
                                    <span style="background:#dcfce7; color:#22c55e; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:600;"><i data-lucide="clock" style="width:12px; display:inline-block; margin-bottom:-2px;"></i> Activa</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 5px; display:flex; align-items:center; gap:5px;">
                                <?php if($act['permitir_tarde']): ?>
                                    <span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:4px;">Acepta retardo</span>
                                <?php else: ?>
                                    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:4px;">No acepta retardo</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px;">
                            <button class="btn btn-outline" style="padding: 8px 12px;" onclick='abrirModalEditar(<?= json_encode($act) ?>)'>
                                <i data-lucide="edit-2" style="width:16px;"></i> Editar
                            </button>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('¿Estás seguro de ELIMINAR esta actividad por completo? Se perderán todas las entregas y calificaciones.');">
                                <input type="hidden" name="action" value="eliminar">
                                <input type="hidden" name="id_actividad" value="<?= $act['id_actividad'] ?>">
                                <button type="submit" class="btn" style="background:#fee2e2; color:#ef4444; border:1px solid #f87171; padding: 8px 12px;">
                                    <i data-lucide="trash-2" style="width:16px;"></i> Eliminar
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Editar -->
    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <h3 style="margin-bottom: 20px; font-size: 20px;">Editar Tarea</h3>
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id_actividad" id="edit-id">
                <input type="hidden" name="descripcion" id="hiddenDescripcionEdit">
                
                <div class="form-group">
                    <label>Título de la actividad</label>
                    <input type="text" name="titulo" id="edit-titulo" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Instrucciones</label>
                    <div id="editor-container" style="height: 150px; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px;"></div>
                </div>
                
                <div class="form-group">
                    <label>Reemplazar Archivos Adjuntos (Opcional)</label>
                    <p style="font-size:12px; color:#64748b; margin-top:0;">Si subes nuevos archivos, reemplazarán por completo a los anteriores.</p>
                    <input type="file" name="recurso[]" class="form-control" multiple>
                </div>
                
                <div class="form-group">
                    <label>Fecha Límite</label>
                    <input type="datetime-local" name="fecha_limite" id="edit-fecha" class="form-control" required>
                </div>
                
                <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="permitir_tarde" id="edit-tarde">
                    <label for="edit-tarde" style="font-weight:500; font-size:14px;">Permitir entregas fuera de tiempo</label>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 15px; margin-top:20px;">
                    <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Escribe las instrucciones aquí...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link']
                ]
            }
        });
        
        document.getElementById('editForm').onsubmit = function() {
            document.getElementById('hiddenDescripcionEdit').value = quill.root.innerHTML;
        };
        
        function abrirModalEditar(act) {
            document.getElementById('edit-id').value = act.id_actividad;
            document.getElementById('edit-titulo').value = act.titulo;
            document.getElementById('edit-fecha').value = act.fecha_limite;
            document.getElementById('edit-tarde').checked = (act.permitir_tarde == 1);
            
            quill.root.innerHTML = act.descripcion || '';
            
            document.getElementById('modalEditar').style.display = 'flex';
        }
        
        function cerrarModal() {
            document.getElementById('modalEditar').style.display = 'none';
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
        
        lucide.createIcons();
    </script>
</body>
</html>
