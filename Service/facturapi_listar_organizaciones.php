<?php
// Service/facturapi_listar_organizaciones.php
//
// HERRAMIENTA DE UN SOLO USO. Lista todas las Organizaciones que existen
// bajo tu cuenta maestra de Facturapi (la tuya propia + una por cada
// empresa cliente que ya tiene organización creada), para que identifiques
// cuál es la de LibertyFin mismo (normalmente la más antigua, o la que
// tenga el nombre de tu propio negocio).
//
// SEGURIDAD: esto expone el nombre/RFC de TODAS tus empresas clientes en
// una sola pantalla -- por eso pide sesión de admin Y un token que tú
// mismo defines abajo. Bórralo del servidor en cuanto obtengas el ID que
// necesitas.

session_start();

// Cambia esto por cualquier texto largo antes de subir el archivo, y entra
// con ?token=lo-que-hayas-puesto. Así nadie más puede abrir esta página
// aunque adivine la URL.
$TOKEN_REQUERIDO = 'eo8tvy9sytvmerhndyxltuvkeyrlitvuydlxrutivhtylerx';

if (($_GET['token'] ?? '') !== $TOKEN_REQUERIDO || $TOKEN_REQUERIDO === 'cambia-esto-por-algo-largo-y-unico') {
    http_response_code(403);
    exit('Acceso denegado. Edita $TOKEN_REQUERIDO en este archivo antes de usarlo.');
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    http_response_code(401);
    exit('No autorizado.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Facturapi\Facturapi;

header('Content-Type: text/html; charset=utf-8');

$api_key = env('FACTURAPI_API_KEY');
if (empty($api_key)) {
    exit('No se encontró FACTURAPI_API_KEY en las variables de entorno.');
}

try {
    $facturapi = new Facturapi($api_key);
    $resultado = $facturapi->Organizations->all(['limit' => 100]);
} catch (Exception $e) {
    exit('Error al consultar Facturapi: ' . htmlspecialchars($e->getMessage()));
}

$organizaciones = $resultado->data ?? $resultado ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Organizaciones de Facturapi</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; font-size: 14px; }
        th { background: #f2f2f2; }
        code { background: #eee; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h2>Organizaciones bajo tu cuenta de Facturapi</h2>
    <p>Busca la que corresponda a <strong>LibertyFin mismo</strong> (no una empresa cliente) y copia su <code>id</code>.</p>
    <table>
        <thead>
            <tr><th>ID</th><th>Nombre comercial</th><th>Razón social</th><th>Tipo de cuenta</th></tr>
        </thead>
        <tbody>
        <?php foreach ($organizaciones as $org): ?>
            <tr>
                <td><code><?php echo htmlspecialchars($org->id ?? ''); ?></code></td>
                <td><?php echo htmlspecialchars($org->name ?? ''); ?></td>
                <td><?php echo htmlspecialchars($org->legal->legal_name ?? ($org->legal_name ?? '')); ?></td>
                <td><?php echo htmlspecialchars($org->live_enabled ?? ($org->type ?? '—')); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin-top:2rem; color:#a00;"><strong>Borra este archivo del servidor en cuanto termines.</strong></p>
</body>
</html>
