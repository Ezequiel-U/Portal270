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

// Obtener los grupos asignados al docente para el selector
$stmtGrupos = $pdo->prepare("
    SELECT g.id_grupo, g.semestre, g.grupo, c.nombre as carrera
    FROM grupos g
    JOIN docente_grupo dg ON g.id_grupo = dg.id_grupo
    JOIN carreras c ON g.id_carrera = c.id_carrera
    WHERE dg.id_docente = ?
");
$stmtGrupos->execute([$id_docente_db]);
$grupos = $stmtGrupos->fetchAll();

// Obtener Notificaciones
$stmtNotif = $pdo->prepare("SELECT * FROM notificaciones WHERE id_usuario = ? ORDER BY fecha DESC LIMIT 10");
$stmtNotif->execute([$docente_id]);
$notificaciones = $stmtNotif->fetchAll();
$notif_no_leidas = array_filter($notificaciones, function($n) { return $n['leida'] == 0; });
$num_no_leidas = count($notif_no_leidas);

// Obtener Próximos Eventos Escolares (Docente)
$stmtEventosDocente = $pdo->prepare("
    SELECT * FROM calendario_eventos 
    WHERE id_docente = ?
    AND (fecha_inicio >= CURDATE() OR fecha_fin >= CURDATE())
    ORDER BY fecha_inicio ASC
    LIMIT 6
");
$stmtEventosDocente->execute([$id_docente_db]);
$proximos_eventos = $stmtEventosDocente->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario - Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?= time() ?>">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- Quill.js for Rich Text -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    
    <!-- FullCalendar -->
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>

    <style>
        #calendar {
            min-height: 400px;
            font-size: 14px;
        }
        .fc-event { cursor: pointer; }
        
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: #fff; padding: 25px; border-radius: 12px;
            width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto;
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="profile-section">
            <div class="profile-pic" style="background:var(--card-bg); margin:0 auto 10px;"></div>
            <div class="profile-name"><?= htmlspecialchars($_SESSION['nombre']) ?></div>
            <div class="profile-role">Docente | CBTis 270</div>
        </div>
        <ul class="nav-links">
            <li><a href="docente.php" class="nav-item">Asignar Tareas</a></li>
            <li><a href="gestionar_tareas.php" class="nav-item">Administrar Tareas</a></li>
            <li><a href="revisar_entregas.php" class="nav-item">Revisar Entregas</a></li>
            <li><a href="calendario_docente.php" class="nav-item active">Calendario</a></li>
        </ul>
        <div style="margin-top: auto; padding: 20px;">
            <a href="index.php" class="nav-item">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="header">
            <div class="breadcrumb">
                Inicio > <span>Calendario Escolar</span>
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

        <div id="container-calendario" style="margin-top: 20px;">
            <h2 style="margin-bottom: 20px; display:flex; align-items:center; gap:10px;"><i data-lucide="calendar-days"></i> Próximos Eventos</h2>
            
            <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 30px;">
                <?php foreach($proximos_eventos as $ev): ?>
                    <div class="card" style="border-left: 4px solid <?= $ev['color'] ? htmlspecialchars($ev['color']) : '#3b82f6' ?>; cursor:pointer; display: flex; justify-content: space-between; align-items: center; padding: 12px 20px;" onclick="calendar.getEventById('<?= $ev['id_evento'] ?>') ? calendar.getEventById('<?= $ev['id_evento'] ?>')._def.ui.display !== 'none' ? document.getElementById('modalVer').style.display='flex' : null : null;">
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
                        <p>No tienes eventos escolares próximos programados.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div id="calendar-wrapper" style="background:var(--card-bg); padding:20px; border-radius:12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); max-width: 900px; margin: 0 auto; border: 1px solid var(--border-color);">
                <div id="calendar"></div>
            </div>
        </div>

    </main>

    <!-- Modal para Crear/Editar Evento -->
    <div id="modalEvento" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle" style="margin-bottom: 20px; font-size: 20px;">Crear Evento</h3>
            <form id="formEvento">
                <input type="hidden" id="ev-id">
                
                <div class="form-group">
                    <label>Título del Evento</label>
                    <input type="text" id="ev-titulo" class="form-control" required placeholder="Ej: Junta de Padres, Asueto, etc.">
                </div>
                
                <div class="form-group" style="display: flex; gap: 10px;">
                    <div style="flex:1;">
                        <label>Inicio</label>
                        <input type="datetime-local" id="ev-inicio" class="form-control" required>
                    </div>
                    <div style="flex:1;">
                        <label>Fin</label>
                        <input type="datetime-local" id="ev-fin" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Dirigido a</label>
                    <select id="ev-grupo" class="form-control">
                        <option value="0">Toda la Escuela (Evento General)</option>
                        <?php foreach($grupos as $g): ?>
                            <option value="<?= $g['id_grupo'] ?>">Solo <?= $g['semestre'] ?>º <?= $g['grupo'] ?> <?= $g['carrera'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Nivel de Urgencia</label>
                    <select id="ev-urgencia" class="form-control">
                        <option value="baja">Baja (Informativo)</option>
                        <option value="media" selected>Media (Importante)</option>
                        <option value="alta">Alta (Urgente / Obligatorio)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Color del Evento</label>
                    <input type="color" id="ev-color" value="#3b82f6" style="width: 100%; height: 40px; border: none; cursor: pointer;">
                </div>
                
                <div class="form-group">
                    <label>Descripción / Instrucciones</label>
                    <div id="editor-container" style="height: 120px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 4px;"></div>
                </div>
                
                <div style="display: flex; justify-content: space-between; margin-top:20px;">
                    <button type="button" class="btn btn-outline" id="btnEliminar" style="display:none; border-color:#ef4444; color:#ef4444;">Eliminar Evento</button>
                    <div style="display: flex; gap: 15px; margin-left:auto;">
                        <button type="button" class="btn btn-outline" onclick="cerrarModal()">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal para Ver Evento (Solo lectura, antes de editar o para tareas) -->
    <div id="modalVer" class="modal">
        <div class="modal-content">
            <h3 id="ver-titulo" style="margin-bottom: 5px; font-size: 20px; color:var(--text-dark);">Título</h3>
            <div id="ver-fechas" style="font-size: 13px; color:var(--text-muted); margin-bottom: 5px;"></div>
            <div id="ver-urgencia" style="font-size: 14px; font-weight: 600; margin-bottom: 15px;"></div>
            
            <div id="ver-dirigido" style="margin-bottom:15px; font-size: 14px; font-weight: 500;"></div>
            
            <div style="background: var(--bg-hover); padding: 15px; border-radius: 8px; font-size:14px; margin-bottom: 20px;" id="ver-descripcion">
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 15px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modalVer').style.display='none'">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        var calendar;
        
        var quill = new Quill('#editor-container', {
            theme: 'snow',
            placeholder: 'Agrega detalles o indicaciones...',
            modules: { toolbar: [ ['bold', 'italic', 'underline'], [{ 'list': 'ordered'}, { 'list': 'bullet' }], ['link'] ] }
        });

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
                buttonText: {
                    today: 'Hoy',
                    month: 'Mes',
                    week: 'Semana',
                    day: 'Día'
                },
                events: 'api_calendario.php', // Fetch events from API
                selectable: true,
                select: function(info) {
                    abrirModalCrear(info.startStr, info.endStr);
                },
                // Use eventContent hook to add Lucide icons directly in FullCalendar events
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
                    // Prevenir redirección por defecto
                    info.jsEvent.preventDefault();
                    
                    var props = info.event.extendedProps;
                    
                    // Si es un evento, podemos editarlo o borrarlo
                    // Para simplificar, primero mostramos la info y damos la opcion
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
                    
                    if(props.dirigido) {
                        document.getElementById('ver-dirigido').innerHTML = '<i data-lucide="users" style="width:16px; margin-bottom:-3px;"></i> Dirigido a: ' + props.dirigido;
                    } else {
                        document.getElementById('ver-dirigido').innerHTML = '';
                    }
                    
                    document.getElementById('ver-descripcion').innerHTML = props.descripcion || 'Sin descripción adicional.';
                    
                    lucide.createIcons();
                    document.getElementById('modalVer').style.display = 'flex';
                    
                    // Configuramos el boton de "Editar" u "Eliminar" de forma global (solo si es evento, no tarea)
                    // (Omitido para simplificar vista vs edición directa).
                    
                    // En su lugar, si hacen clic y quieren editar, pueden presionar un boton.
                    // Vamos a agregarlo dinámicamente si es evento.
                    let footer = document.getElementById('modalVer').querySelector('div[style*="justify-content: flex-end"]');
                    let btnEditar = document.getElementById('btnEditarDinamico');
                    if(btnEditar) btnEditar.remove();
                    
                    if(props.tipo === 'evento') {
                        btnEditar = document.createElement('button');
                        btnEditar.id = 'btnEditarDinamico';
                        btnEditar.className = 'btn btn-primary';
                        btnEditar.innerText = 'Editar / Eliminar';
                        btnEditar.onclick = function() {
                            document.getElementById('modalVer').style.display = 'none';
                            abrirModalEditar(info.event);
                        };
                        footer.appendChild(btnEditar);
                    }
                }
            });
            calendar.render();
        });

        function formatDateTimeLocal(dateStr) {
            if(!dateStr) return '';
            let d = new Date(dateStr);
            let p = new Date(d.getTime() - (d.getTimezoneOffset() * 60000)).toISOString().slice(0, 16);
            return p;
        }

        function abrirModalCrear(startStr, endStr) {
            document.getElementById('modalTitle').innerText = 'Crear Evento';
            document.getElementById('ev-id').value = '';
            document.getElementById('ev-titulo').value = '';
            
            // Si viene de seleccionar multiples dias, endStr es exclusivo (00:00 del sig dia)
            let start = formatDateTimeLocal(startStr);
            let end = endStr ? formatDateTimeLocal(endStr) : start;
            
            document.getElementById('ev-inicio').value = start;
            document.getElementById('ev-fin').value = end;
            document.getElementById('ev-grupo').value = "0";
            document.getElementById('ev-urgencia').value = "media";
            document.getElementById('ev-color').value = "#3b82f6";
            quill.root.innerHTML = '';
            
            document.getElementById('btnEliminar').style.display = 'none';
            document.getElementById('modalEvento').style.display = 'flex';
        }

        function abrirModalEditar(eventObj) {
            document.getElementById('modalTitle').innerText = 'Editar Evento';
            document.getElementById('ev-id').value = eventObj.id;
            document.getElementById('ev-titulo').value = eventObj.title;
            
            document.getElementById('ev-inicio').value = formatDateTimeLocal(eventObj.start);
            document.getElementById('ev-fin').value = formatDateTimeLocal(eventObj.end || eventObj.start);
            
            document.getElementById('ev-grupo').value = eventObj.extendedProps.id_grupo || "0";
            document.getElementById('ev-urgencia').value = eventObj.extendedProps.urgencia || "media";
            document.getElementById('ev-color').value = eventObj.backgroundColor;
            quill.root.innerHTML = eventObj.extendedProps.descripcion || '';
            
            document.getElementById('btnEliminar').style.display = 'block';
            document.getElementById('btnEliminar').onclick = function() {
                if(confirm("¿Estás seguro de eliminar este evento?")) {
                    guardarEvento('eliminar');
                }
            };
            
            document.getElementById('modalEvento').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalEvento').style.display = 'none';
        }

        document.getElementById('formEvento').onsubmit = function(e) {
            e.preventDefault();
            guardarEvento('crear'); // crear o actualizar
        };

        function guardarEvento(accion) {
            let formData = new FormData();
            formData.append('accion', accion);
            if(accion === 'eliminar') {
                formData.append('id_evento', document.getElementById('ev-id').value);
            } else {
                // Al crear, borramos antes si estabamos editando (en API es mas facil hacer delete+insert, o crear un UPDATE)
                // Para simplificar, si tiene ID, primero llamamos eliminar y luego crear, o mandamos a la API.
                // Como nuestra API de 'crear' solo inserta, si estamos editando, tendriamos que borrar primero.
                let id_ev = document.getElementById('ev-id').value;
                if(id_ev) {
                    // Simulamos edición borrando y recreando
                    let fdDel = new FormData();
                    fdDel.append('accion', 'eliminar');
                    fdDel.append('id_evento', id_ev);
                    fetch('api_calendario.php', { method: 'POST', body: fdDel }).then(() => {
                        enviarCreacion();
                    });
                    return;
                }
                enviarCreacion();
            }
            
            function enviarCreacion() {
                let f = new FormData();
                f.append('accion', 'crear');
                f.append('titulo', document.getElementById('ev-titulo').value);
                f.append('fecha_inicio', document.getElementById('ev-inicio').value);
                f.append('fecha_fin', document.getElementById('ev-fin').value);
                f.append('color', document.getElementById('ev-color').value);
                f.append('id_grupo', document.getElementById('ev-grupo').value);
                f.append('urgencia', document.getElementById('ev-urgencia').value);
                f.append('descripcion', quill.root.innerHTML);
                
                fetch('api_calendario.php', { method: 'POST', body: f })
                .then(r => r.json())
                .then(data => {
                    window.location.reload();
                });
            }

            if(accion === 'eliminar') {
                fetch('api_calendario.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    window.location.reload();
                });
            }
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
        }
    </script>

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
