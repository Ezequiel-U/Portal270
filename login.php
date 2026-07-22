<?php
session_start();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión | Portal 270</title>

    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

    <main class="login-layout">

        <section class="login-visual">

            <div class="visual-overlay"></div>

            <div class="visual-content">

                <div class="logo-placeholder">
                    Aquí irá el logo del CBTis 270
                </div>

                <div class="school-number">
                    <span>CBTis</span>
                    <strong>270</strong>
                    <small>Ciudad Juárez, Chihuahua</small>
                </div>

                <div class="platform-description">
                    <h1>
                        Sistema Inteligente de Actividades
                        <span>CBTis 270</span>
                    </h1>

                    <p>
                        Plataforma web para la gestión, seguimiento y motivación
                        de actividades académicas e institucionales.
                    </p>
                </div>

                <div class="features">

                    <article class="feature">
                        <div class="feature-icon">🔔</div>
                        <p>Notificaciones<br>en tiempo real</p>
                    </article>

                    <article class="feature">
                        <div class="feature-icon">🏆</div>
                        <p>Puntos y<br>recompensas</p>
                    </article>

                    <article class="feature">
                        <div class="feature-icon">📊</div>
                        <p>Seguimiento de<br>tu progreso</p>
                    </article>

                </div>

                <div class="motivation-card">
                    <div class="motivation-logo">
                        Logo
                    </div>

                    <p>
                        “Cada actividad te acerca<br>
                        más a tus metas.”<br>
                        <strong>¡Tú puedes, Toro!</strong>
                    </p>
                </div>

            </div>
        </section>

        <section class="login-section">

            <div class="login-card">

                <header class="login-header">
                    <h2>¡Bienvenido de nuevo!</h2>
                    <p>Ingresa para continuar</p>
                </header>

                <form action="#" method="POST">

                    <div class="form-group">
                        <label for="usuario">Correo electrónico o matrícula</label>

                        <div class="input-wrapper">
                            <span class="input-icon">👤</span>

                            <input
                                type="text"
                                id="usuario"
                                name="usuario"
                                placeholder="Correo electrónico / Matrícula"
                                autocomplete="username"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Contraseña</label>

                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Contraseña"
                                autocomplete="current-password"
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                aria-label="Mostrar contraseña"
                            >
                                👁
                            </button>
                        </div>
                    </div>

                    <div class="forgot-password">
                        <a href="#">¿Olvidaste tu contraseña?</a>
                    </div>

                    <fieldset class="role-selector">

                        <legend>Selecciona tu rol:</legend>

                        <div class="role-options">

                            <label class="role-card active">
                                <input
                                    type="radio"
                                    name="rol"
                                    value="alumno"
                                    checked
                                >

                                <span class="role-icon">🎓</span>
                                <span>Alumno</span>
                            </label>

                            <label class="role-card">
                                <input
                                    type="radio"
                                    name="rol"
                                    value="docente"
                                >

                                <span class="role-icon">🧑‍🏫</span>
                                <span>Docente</span>
                            </label>

                            <label class="role-card">
                                <input
                                    type="radio"
                                    name="rol"
                                    value="administrador"
                                >

                                <span class="role-icon">🛡</span>
                                <span>Administrador</span>
                            </label>

                        </div>
                    </fieldset>

                    <button type="submit" class="login-button">
                        <span>⇥</span>
                        Iniciar sesión
                    </button>

                    <div class="divider">
                        <span>o continúa con</span>
                    </div>

                    <button type="button" class="google-button">
                        <span class="google-icon">G</span>
                        Iniciar sesión con Google
                    </button>

                </form>

            </div>

            <footer class="login-footer">
                <span>🛡 CBTis 270</span>
                <span>© Todos los derechos reservados</span>
                <span>▱ Versión 1.0.0</span>
            </footer>

        </section>

    </main>

    <script src="assets/js/login.js"></script>
</body>
</html>