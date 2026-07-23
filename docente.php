<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'docente') {
    header("Location: index.php");
    exit;
}

$docente_id = $_SESSION['usuario_id'];

// Obtener info docente y sus grupos
$stmt = $pdo->prepare("SELECT d.* FROM docentes d WHERE d.id_usuario = ?");
$stmt->execute([$docente_id]);
$info_docente = $stmt->fetch();
$id_docente_db = $info_docente['id_docente'];

$stmt = $pdo->prepare("SELECT g.id_grupo, g.semestre, g.grupo, c.nombre as carrera
                       FROM docente_grupo dg
                       JOIN grupos g ON dg.id_grupo = g.id_grupo
                       JOIN carreras c ON g.id_carrera = c.id_carrera
                       WHERE dg.id_docente = ?");
$stmt->execute([$id_docente_db]);
$grupos = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_tarea'])) {
    $titulo = $_POST['titulo'];
    $descripcion = $_POST['descripcion']; // Contiene HTML
    $id_grupo = $_POST['id_grupo'];
    $fecha_limite = $_POST['fecha_limite'];
    $materia = $_POST['materia'];
    $modalidad = $_POST['tipo_actividad'];
    
    $permitir_tarde = isset($_POST['permitir_tarde']) ? 1 : 0;
    $notificar_alumnos = isset($_POST['notificar_alumnos']) ? 1 : 0;
    
    $archivos_subidos = [];
    if(isset($_FILES['recurso']) && !empty($_FILES['recurso']['name'][0])) {
        $countFiles = count($_FILES['recurso']['name']);
        for($i = 0; $i < $countFiles; $i++) {
            if($_FILES['recurso']['error'][$i] == 0) {
                $nombre_original = basename($_FILES['recurso']['name'][$i]);
                // Para evitar colisiones y mantener el nombre
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
    $archivo_recurso = !empty($archivos_subidos) ? json_encode($archivos_subidos, JSON_UNESCAPED_UNICODE) : null;
    
    // 1. Crear actividad
    $stmt = $pdo->prepare("INSERT INTO actividades (titulo, descripcion, tipo, modalidad, materia, id_grupo, id_docente, creado_por, fecha_limite, archivo_recurso, permitir_tarde, notificar_alumnos) 
                           VALUES (?, ?, 'normal', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$titulo, $descripcion, $modalidad, $materia, $id_grupo, $id_docente_db, $docente_id, $fecha_limite, $archivo_recurso, $permitir_tarde, $notificar_alumnos]);
    $id_actividad = $pdo->lastInsertId();
    
    // 2. Asignar a todos los alumnos del grupo en actividad_alumno (estatus 'no_visto')
    $stmtAlumnos = $pdo->prepare("SELECT id_alumno, id_usuario FROM alumnos WHERE id_grupo = ?");
    $stmtAlumnos->execute([$id_grupo]);
    $alumnos_grupo = $stmtAlumnos->fetchAll();
    
    $stmtInsertAA = $pdo->prepare("INSERT INTO actividad_alumno (id_actividad, id_alumno, estatus) VALUES (?, ?, 'no_visto')");
    $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (id_usuario, titulo, mensaje, tipo) VALUES (?, 'Nueva Tarea Asignada', ?, 'tarea')");
    
    foreach($alumnos_grupo as $al) {
        $stmtInsertAA->execute([$id_actividad, $al['id_alumno']]);
        if($notificar_alumnos) {
            $msg = "El profesor " . $_SESSION['nombre'] . " ha asignado la actividad: " . $titulo;
            $stmtNotif->execute([$al['id_usuario'], $msg]);
        }
    }
    
    header("Location: docente.php?msg=creada");
    exit;
}

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
    <title>Asignar Actividad - Docente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css?v=<?= time() ?>">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- Quill.js for Rich Text Editor -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

    <style>
        .layout-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            align-items: start;
        }
        
        .section-title {
            font-size: 16px; font-weight: 700; color: var(--text-dark);
            margin: 25px 0 15px 0;
        }

        /* Radio Cards (Tipo de Actividad) */
        .radio-cards {
            display: grid; grid-template-columns: 1fr 1fr; gap: 15px;
        }
        .radio-card {
            border: 2px solid #e2e8f0; border-radius: 8px; padding: 15px;
            cursor: pointer; transition: all 0.2s; position: relative;
        }
        .radio-card:hover { border-color: #cbd5e1; }
        .radio-card.active { border-color: #2563eb; background-color: var(--bg-hover); }
        .radio-card input[type="radio"] { display: none; }
        .radio-card .icon { font-size: 24px; color: #2563eb; margin-bottom: 10px; }
        .radio-card .title { font-weight: 600; font-size: 14px; color: var(--text-dark); margin-bottom: 5px; }
        .radio-card .desc { font-size: 12px; color: var(--text-muted); line-height: 1.4; }
        .radio-card.active::after {
            content: '✓'; position: absolute; top: 15px; right: 15px;
            background: #2563eb; color: #fff; width: 20px; height: 20px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: bold;
        }

        /* Info Alert */
        .info-alert {
            background-color: var(--bg-hover); color: var(--primary-color); padding: 12px 15px;
            border-radius: 8px; font-size: 13px; display: flex; gap: 10px; align-items: center;
            margin-top: 15px;
        }

        /* Drag and Drop Zone */
        .drop-zone {
            border: 2px dashed #94a3b8; border-radius: 8px; padding: 30px;
            text-align: center; background: var(--bg-hover); cursor: pointer;
            transition: background 0.2s;
        }
        .drop-zone:hover, .drop-zone.dragover { background: var(--bg-hover); border-color: #2563eb; }
        .drop-zone .icon { font-size: 30px; color: #2563eb; margin-bottom: 10px; }

        /* Right Panel Cards */
        .side-card {
            background: var(--bg-hover); border: 1px solid var(--border-color); border-radius: 12px;
            padding: 20px; margin-bottom: 20px;
        }
        .side-card h3 {
            font-size: 14px; font-weight: 700; color: var(--primary-color);
            margin-bottom: 15px; display: flex; align-items: center; gap: 8px;
        }
        
        .tip-item {
            display: flex; gap: 12px; margin-bottom: 15px; align-items: center;
        }
        .tip-icon {
            background: var(--bg-hover); width: 40px; height: 40px;
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            color: #2563eb; flex-shrink: 0;
        }
        .tip-text { font-size: 12px; color: #475569; line-height: 1.4; font-weight: 500;}

        /* Preview and Summary Cards */
        .preview-box {
            background: var(--card-bg); border-radius: 8px; padding: 20px; border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .preview-header { display: flex; gap: 15px; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
        .preview-icon { background: #2563eb; color: #fff; border-radius: 10px; width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; }
        .preview-title { font-weight: 700; font-size: 15px; color: var(--text-dark); line-height: 1.3; }
        
        .meta-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 15px; }
        .meta-row { display: flex; align-items: flex-start; }
        .meta-label { display: flex; align-items: center; gap: 8px; width: 100px; color: var(--text-muted); font-size: 13px; font-weight: 500; }
        .meta-label i { width: 16px; height: 16px; color: #2563eb; }
        .meta-value { font-size: 13px; color: var(--text-dark); font-weight: 600; flex: 1; }
        
        .checkbox-group { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 15px; }
        .checkbox-group input { margin-top: 3px; }
        .checkbox-label { font-size: 14px; font-weight: 600; color: var(--text-dark); }
        .checkbox-desc { font-size: 12px; color: var(--text-muted); }
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
            <li><a href="docente.php" class="nav-item active">Asignar Tareas</a></li>
            <li><a href="gestionar_tareas.php" class="nav-item">Administrar Tareas</a></li>
            <li><a href="revisar_entregas.php" class="nav-item">Revisar Entregas</a></li>
            <li><a href="calendario_docente.php" class="nav-item">Calendario</a></li>
        </ul>
        <div style="margin-top: auto; padding: 20px;">
            
        <button class="theme-toggle-btn" onclick="toggleTheme()" style="display:flex; align-items:center; gap:10px; padding:12px 20px; margin:20px 0; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:var(--sidebar-text); border-radius:8px; cursor:pointer; width:100%;">
            <i data-lucide="moon" id="theme-icon"></i>
            <span id="theme-text">Modo Claro</span>
        </button>
<a href="index.php" class="nav-item">Cerrar sesión</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="header">
            <div class="breadcrumb">
                Mis actividades > <span>Asignar nueva actividad</span>
            </div>
            
            <div style="display:flex; gap: 20px; align-items:center;">
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
                                <?php foreach($notificaciones as $n): ?>
                                    <div class="notif-item">
                                        <div class="notif-icon"><i data-lucide="mail"></i></div>
                                        <div>
                                            <div class="notif-title"><?= htmlspecialchars($n['titulo']) ?></div>
                                            <div style="color: var(--text-dark);"><?= htmlspecialchars($n['mensaje']) ?></div>
                                            <div class="notif-time"><?= htmlspecialchars($n['fecha']) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if(isset($_GET['msg']) && $_GET['msg'] == 'creada'): ?>
            <div style="background: var(--success-bg); color: var(--success-text); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                ¡Actividad asignada y notificada correctamente!
            </div>
        <?php endif; ?>

        <div class="layout-grid">
            
            <!-- Columna Izquierda: Formulario Principal -->
            <div class="card" style="padding: 30px;">
                <div style="display:flex; align-items:center; gap: 15px; margin-bottom: 30px;">
                    <div style="background: #2563eb; color: #fff; width: 48px; height: 48px; border-radius: 12px; display:flex; align-items:center; justify-content:center;">
                        <i data-lucide="pen-line"></i>
                    </div>
                    <div>
                        <h1 style="margin: 0; font-size: 24px; color: var(--text-dark);">Asignar nueva actividad</h1>
                        <p style="margin: 5px 0 0 0; color: var(--text-muted); font-size: 14px;">Crea y asigna una actividad a tu grupo</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="tareaForm">
                    <input type="hidden" name="crear_tarea" value="1">
                    <input type="hidden" name="descripcion" id="hiddenDescripcion">

                    <div class="section-title">1. Información de la actividad</div>
                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group" style="margin:0;">
                            <label>Título de la actividad <span style="color:red">*</span></label>
                            <input type="text" name="titulo" id="inputTitulo" class="form-control" required placeholder="Ej: Investigación de Lenguajes">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Materia <span style="color:red">*</span></label>
                            <select name="materia" id="inputMateria" class="form-control" required>
                                <option value="">Selecciona</option>
                                <option value="Programación">Programación</option>
                                <option value="Matemáticas">Matemáticas</option>
                                <option value="Física">Física</option>
                                <option value="Inglés">Inglés</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Descripción <span style="color:red">*</span></label>
                        <div id="editor-container" style="height: 150px; background: var(--card-bg);"></div>
                    </div>

                    <div class="section-title">2. Asignación</div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group" style="margin:0;">
                            <label>Grupo <span style="color:red">*</span></label>
                            <select name="id_grupo" id="inputGrupo" class="form-control" required>
                                <option value="">Selecciona</option>
                                <?php foreach($grupos as $g): ?>
                                    <option value="<?= $g['id_grupo'] ?>">
                                        <?= htmlspecialchars($g['semestre']) ?>º <?= htmlspecialchars($g['grupo']) ?> <?= htmlspecialchars($g['carrera']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Fecha límite <span style="color:red">*</span></label>
                            <input type="datetime-local" name="fecha_limite" id="inputFecha" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tipo de actividad <span style="color:red">*</span></label>
                        <div class="radio-cards">
                            <label class="radio-card active" id="cardIndividual" onclick="selectTipo('individual')">
                                <input type="radio" name="tipo_actividad" value="individual" checked>
                                <div class="icon"><i data-lucide="user"></i></div>
                                <div class="title">Individual</div>
                                <div class="desc">Cada alumno realiza su actividad de forma individual.</div>
                            </label>
                            <label class="radio-card" id="cardEquipo" onclick="selectTipo('equipo')">
                                <input type="radio" name="tipo_actividad" value="equipo">
                                <div class="icon"><i data-lucide="users"></i></div>
                                <div class="title">Colaborativa (en equipo)</div>
                                <div class="desc">Los alumnos trabajan en equipo para realizar la actividad.</div>
                            </label>
                        </div>
                        <div class="info-alert">
                            <i data-lucide="info" style="width:16px; height:16px;"></i> Al seleccionar el tipo de actividad, se ajustarán los permisos de entrega para tus alumnos.
                        </div>
                    </div>

                    <div class="section-title">3. Archivos y recursos (opcional)</div>
                    <div class="form-group">
                        <label>Adjuntar archivos</label>
                        <div class="drop-zone" id="dropZoneContainer" onclick="document.getElementById('fileInput').click()">
                            <div class="icon"><i data-lucide="cloud-upload" style="width:32px; height:32px;"></i></div>
                            <div style="color: #2563eb; font-weight: 600;">Arrastra y suelta archivos aquí</div>
                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">o haz clic para seleccionar</div>
                            <div style="font-size: 11px; color: #94a3b8; margin-top: 10px;">Formatos: PDF, DOCX, PPTX, ZIP (Máx 50MB)</div>
                            <input type="file" name="recurso[]" id="fileInput" style="display:none" multiple onchange="showFileName(this)">
                        </div>
                        
                        <div id="filePreviewContainer" style="display:none; flex-direction:column; gap:10px; margin-top: 10px;">
                            <!-- File list will be injected here via JS -->
                        </div>
                    </div>

                    <div class="section-title">4. Configuración adicional (opcional)</div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="permitir_tarde" id="chk1">
                        <div>
                            <label for="chk1" class="checkbox-label">Permitir entregas fuera de tiempo</label>
                            <div class="checkbox-desc">Los alumnos podrán entregar después de la fecha límite.</div>
                        </div>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="notificar_alumnos" id="chk2" checked>
                        <div>
                            <label for="chk2" class="checkbox-label">Enviar notificación a los alumnos</label>
                            <div class="checkbox-desc">Los alumnos recibirán una notificación de la nueva actividad.</div>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                        <button type="button" class="btn btn-outline" style="padding: 12px 24px;">Cancelar</button>
                        <button type="submit" class="btn btn-primary" style="padding: 12px 30px; font-size: 16px; font-weight: 600; display:flex; align-items:center; gap:8px;">
                            <i data-lucide="send" style="width:18px; height:18px;"></i> Asignar actividad
                        </button>
                    </div>
                </form>
            </div>

            <!-- Columna Derecha: Paneles Informativos -->
            <div>
                <div class="side-card">
                    <h3><i data-lucide="lightbulb" style="color:#2563eb;"></i> Consejos para tu actividad</h3>
                    <div class="tip-item">
                        <div class="tip-icon"><i data-lucide="badge-check"></i></div>
                        <div class="tip-text">Define instrucciones claras y específicas.</div>
                    </div>
                    <div class="tip-item">
                        <div class="tip-icon"><i data-lucide="calendar"></i></div>
                        <div class="tip-text">Establece una fecha límite razonable para tus alumnos.</div>
                    </div>
                    <div class="tip-item">
                        <div class="tip-icon"><i data-lucide="clipboard-list"></i></div>
                        <div class="tip-text">Elige el tipo de actividad que mejor se adapte a tu objetivo.</div>
                    </div>
                    <div class="tip-item">
                        <div class="tip-icon"><i data-lucide="badge-check"></i></div>
                        <div class="tip-text">Incluye recursos de apoyo siempre que sea posible.</div>
                    </div>
                </div>

                <div class="side-card" style="background: var(--bg-hover); border-color: #e0e7ff;">
                    <h3><i data-lucide="eye" style="color:#2563eb;"></i> Vista previa para los alumnos</h3>
                    <div class="preview-box">
                        <div class="preview-header">
                            <div class="preview-icon"><i data-lucide="code"></i></div>
                            <div class="preview-title" id="prevTitulo">Título de la actividad</div>
                        </div>
                        <div class="meta-list">
                            <div class="meta-row">
                                <div class="meta-label"><i data-lucide="book"></i> Materia:</div>
                                <div class="meta-value" id="prevMateria">-</div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label"><i data-lucide="users"></i> Grupo:</div>
                                <div class="meta-value" id="prevGrupo">-</div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label"><i data-lucide="calendar"></i> Entrega:</div>
                                <div class="meta-value" id="prevFecha">-</div>
                            </div>
                            <div class="meta-row">
                                <div class="meta-label"><i data-lucide="user"></i> Tipo:</div>
                                <div class="meta-value" id="prevTipo">Individual</div>
                            </div>
                        </div>
                        <div style="font-size: 13px; color: #475569; border-top: 1px solid #f1f5f9; padding-top: 15px; margin-top: 15px; line-height: 1.5;" id="prevDesc">
                            La descripción aparecerá aquí...
                        </div>
                    </div>
                </div>

                <div class="side-card">
                    <h3><i data-lucide="bar-chart-2" style="color:#2563eb;"></i> Resumen de la actividad</h3>
                    <div class="meta-list" style="margin-top: 15px;">
                        <div class="meta-row">
                            <div class="meta-label"><i data-lucide="users"></i> Grupo:</div>
                            <div class="meta-value" id="resGrupo">-</div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-label"><i data-lucide="book"></i> Materia:</div>
                            <div class="meta-value" id="resMateria">-</div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-label"><i data-lucide="calendar"></i> Fecha:</div>
                            <div class="meta-value" id="resFecha">-</div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-label"><i data-lucide="user"></i> Tipo:</div>
                            <div class="meta-value" id="resTipo">Individual</div>
                        </div>
                        <div class="meta-row">
                            <div class="meta-label"><i data-lucide="paperclip"></i> Archivos:</div>
                            <div class="meta-value" id="resArchivos">0</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

        // Init Quill Editor
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

        // Submit form handler to get Quill HTML
        document.getElementById('tareaForm').onsubmit = function() {
            document.getElementById('hiddenDescripcion').value = quill.root.innerHTML;
        };

        // Radio card selection
        function selectTipo(tipo) {
            document.getElementById('cardIndividual').classList.remove('active');
            document.getElementById('cardEquipo').classList.remove('active');
            if(tipo === 'individual') {
                document.getElementById('cardIndividual').classList.add('active');
                document.getElementById('prevTipo').innerText = 'Individual';
                document.getElementById('resTipo').innerText = 'Individual';
            } else {
                document.getElementById('cardEquipo').classList.add('active');
                document.getElementById('prevTipo').innerText = 'Colaborativa';
                document.getElementById('resTipo').innerText = 'Colaborativa';
            }
        }

        let uploadedFiles = new DataTransfer();

        // File upload display
        function showFileName(input) {
            if(input.files && input.files.length > 0) {
                for(let i=0; i < input.files.length; i++) {
                    uploadedFiles.items.add(input.files[i]);
                }
                renderFileList();
            }
        }

        function renderFileList() {
            var container = document.getElementById('filePreviewContainer');
            var input = document.getElementById('fileInput');
            
            input.files = uploadedFiles.files; // Sync
            container.innerHTML = '';
            
            if(uploadedFiles.files.length > 0) {
                container.style.display = 'flex';
                document.getElementById('dropZoneContainer').style.display = 'none';
                
                for(let i=0; i < uploadedFiles.files.length; i++) {
                    let file = uploadedFiles.files[i];
                    let fileRow = document.createElement('div');
                    fileRow.style.cssText = 'display:flex; align-items:center; justify-content:space-between; background: var(--bg-hover); border: 1px solid #c7d2fe; border-radius: 8px; padding: 12px 15px; width: 100%; box-sizing: border-box;';
                    
                    fileRow.innerHTML = `
                        <div style="display:flex; align-items:center; gap: 10px; overflow:hidden;">
                            <i data-lucide="file-text" style="color:#2563eb; width:20px; height:20px; flex-shrink:0;"></i>
                            <span style="font-weight: 600; color: var(--text-dark); font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${file.name}</span>
                        </div>
                        <button type="button" onclick="removeFile(${i}, event)" style="background:transparent; border:none; color:#ef4444; cursor:pointer; display:flex; align-items:center; gap:5px; font-size:13px; font-weight:600; padding:5px; border-radius:4px; flex-shrink:0;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                            <i data-lucide="trash-2" style="width:16px; height:16px;"></i>
                        </button>
                    `;
                    container.appendChild(fileRow);
                }
                
                // Add "Add another file" button
                let addMoreBtn = document.createElement('button');
                addMoreBtn.type = 'button';
                addMoreBtn.onclick = function(e){ e.preventDefault(); document.getElementById('fileInput').click(); };
                addMoreBtn.style.cssText = 'margin-top:5px; background:transparent; border:1px dashed #2563eb; color:#2563eb; width:100%; padding:10px; border-radius:8px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;';
                addMoreBtn.innerHTML = '<i data-lucide="plus-circle" style="width:18px; height:18px;"></i> Añadir otro archivo';
                container.appendChild(addMoreBtn);
                
                document.getElementById('resArchivos').innerText = uploadedFiles.files.length;
                lucide.createIcons();
            } else {
                container.style.display = 'none';
                document.getElementById('dropZoneContainer').style.display = 'block';
                document.getElementById('resArchivos').innerText = '0';
            }
        }

        // Remove selected file
        function removeFile(index, event) {
            if(event) event.stopPropagation();
            let newDT = new DataTransfer();
            for(let i=0; i < uploadedFiles.files.length; i++) {
                if(i !== index) newDT.items.add(uploadedFiles.files[i]);
            }
            uploadedFiles = newDT;
            renderFileList();
        }

        // Live Preview Updaters
        document.getElementById('inputTitulo').addEventListener('input', function(e) {
            document.getElementById('prevTitulo').innerText = e.target.value || 'Título de la actividad';
        });
        document.getElementById('inputMateria').addEventListener('change', function(e) {
            var val = e.target.options[e.target.selectedIndex].text;
            document.getElementById('prevMateria').innerText = val !== 'Selecciona' ? val : '-';
            document.getElementById('resMateria').innerText = val !== 'Selecciona' ? val : '-';
        });
        document.getElementById('inputGrupo').addEventListener('change', function(e) {
            var val = e.target.options[e.target.selectedIndex].text;
            document.getElementById('prevGrupo').innerText = val !== 'Selecciona' ? val : '-';
            document.getElementById('resGrupo').innerText = val !== 'Selecciona' ? val : '-';
        });
        document.getElementById('inputFecha').addEventListener('change', function(e) {
            document.getElementById('prevFecha').innerText = e.target.value || '-';
            document.getElementById('resFecha').innerText = e.target.value || '-';
        });
        quill.on('text-change', function() {
            var text = quill.getText();
            document.getElementById('prevDesc').innerText = text.trim() ? text.substring(0, 100) + '...' : 'La descripción aparecerá aquí...';
        });

        // Notifications
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
