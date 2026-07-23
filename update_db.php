<?php
require 'config.php';
$pdo->exec("ALTER TABLE actividades MODIFY archivo_recurso TEXT NULL");
echo "OK";
