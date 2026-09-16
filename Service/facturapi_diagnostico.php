<?php
// Service/facturapi_diagnostico.php
//
// DIAGNÓSTICO DE UN SOLO USO para el error "api_key_invalid" al timbrar
// la factura de una suscripción.
//
// Revisa, paso por paso: si la variable de entorno del organization_id
// existe, si la llave maestra funciona, y si se puede obtener la llave
// de la organización (que es la única con la que se puede timbrar).
//
// BÓRRALO del servidor en cuanto lo uses.

session_start();

// 1) Cambia esto por un texto largo y único, y entra con ?token=ESE_TEXTO
//    OJO: la URL debe llevar "token=" antes del valor.
$TOKEN_REQUERIDO = '123';

if ($TOKEN_REQUERIDO === 'cambia-esto-por-algo-largo-y-unico' || ($_GET['token'] ?? '') !== $TOKEN_REQUERIDO) {
    http_response_code(403);
    exit('Acceso denegado. Edita $TOKEN_REQUERIDO y entra con ?token=TU_TOKEN');
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['usuario_rol'] ?? '') !== 'admin') {
    http_response_code(401);
    exit('No autorizado.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Facturapi\Facturapi;

header('Content-Type: text/plain; charset=utf-8');

function ok($m)   { echo "[OK]    $m\n"; }
function bad($m)  { echo "[FALLA] $m\n"; }
function info($m) { echo "        $m\n"; }

echo "DIAGNÓSTICO FACTURAPI - FACTURA DE SUSCRIPCIÓN\n";
echo str_repeat('=', 55) . "\n\n";

// ---------- PASO 1: llave maestra ----------
echo "PASO 1. Llave maestra (FACTURAPI_API_KEY)\n";
$api_key = env('FACTURAPI_API_KEY');
if (empty($api_key)) {
    bad('FACTURAPI_API_KEY no está definida en el .env. Todo lo demás fallará.');
    exit;
}
ok('Encontrada. Empieza con: ' . substr($api_key, 0, 8) . '...');
if (strpos($api_key, 'sk_user_') !== 0) {
    info('AVISO: no empieza con "sk_user_". Si es una llave de organización,');
    info('no podrá listar organizaciones (pero quizá sí timbrar directo).');
}
echo "\n";

// ---------- PASO 2: organization_id ----------
echo "PASO 2. ID de la organización de LibertyFin\n";
$posibles = [
    'FACTURAPI_LIBERTYFIN_ORGANIZATION_ID',
    'FACTURAPI_ORGANIZATION_ID',
    'FACTURAPI_ORG_ID',
];
$organization_id = null;
$nombre_var_usada = null;
foreach ($posibles as $nombre) {
    $v = env($nombre);
    if (!empty($v)) {
        $organization_id = $v;
        $nombre_var_usada = $nombre;
        break;
    }
}

if (empty($organization_id)) {
    bad('NINGUNA de estas variables existe en tu .env:');
    foreach ($posibles as $n) { info(' - ' . $n); }
    info('');
    info('ESTA ES LA CAUSA MÁS PROBABLE DEL ERROR api_key_invalid:');
    info('si el organization_id va vacío, no se puede obtener la llave de');
    info('la organización, y se termina timbrando con una llave inválida.');
    info('');
    info('SOLUCIÓN: agrega a tu .env la línea:');
    info('FACTURAPI_LIBERTYFIN_ORGANIZATION_ID=696ffda4c95bb2e1eee22e4c');
    exit;
}
ok("Encontrada en la variable: $nombre_var_usada");
info("Valor: $organization_id");
echo "\n";

// ---------- PASO 3: obtener la llave de la organización ----------
echo "PASO 3. Intercambio por la llave de la organización\n";
info('(Una sk_user_ NO puede timbrar: hay que cambiarla por la llave');
info(' de la organización. Esto es lo que ya hace facturar_venta.php.)');
echo "\n";

try {
    $maestra = new Facturapi($api_key);
} catch (Exception $e) {
    bad('No se pudo inicializar con la llave maestra: ' . $e->getMessage());
    exit;
}

$extraer = function ($obj) {
    if (is_string($obj)) return $obj;
    if (is_object($obj)) {
        foreach (['key', 'api_key', 'secret'] as $prop) {
            if (isset($obj->$prop)) return $obj->$prop;
        }
    }
    return null;
};

// Llave de PRUEBA
$test_key = null;
try {
    $test_key = $extraer($maestra->Organizations->getTestApiKey($organization_id));
    if ($test_key) {
        ok('Llave de PRUEBA obtenida: ' . substr($test_key, 0, 10) . '...');
    } else {
        bad('getTestApiKey respondió, pero sin una llave utilizable.');
    }
} catch (Exception $e) {
    bad('getTestApiKey falló: ' . $e->getMessage());
}

// Llave REAL (live)
$live_key = null;
try {
    $live_key = $extraer($maestra->Organizations->getLiveApiKey($organization_id));
    if ($live_key) {
        ok('Llave REAL (live) obtenida: ' . substr($live_key, 0, 10) . '...');
    } else {
        bad('getLiveApiKey respondió, pero sin una llave utilizable.');
    }
} catch (Exception $e) {
    bad('getLiveApiKey falló: ' . $e->getMessage());
    info('Suele significar que a esa organización le falta completar');
    info('datos fiscales o subir los certificados CSD en Facturapi.');
}
echo "\n";

// ---------- PASO 4: conclusión ----------
echo "CONCLUSIÓN\n";
echo str_repeat('-', 55) . "\n";
if ($test_key) {
    info('El intercambio de llaves SÍ funciona. Verifica que el código que');
    info('timbra la suscripción use la llave obtenida aquí, y NO la');
    info('FACTURAPI_API_KEY maestra directamente.');
    echo "\n";
    info('IMPORTANTE - prueba vs real:');
    info('  - Llave de PRUEBA -> la factura se crea, pero NO es un CFDI');
    info('    fiscalmente válido (es simulación).');
    info('  - Llave REAL (live) -> CFDI válido ante el SAT.');
    if (!$live_key) {
        info('');
        info('Tu organización todavía NO tiene llave real disponible, así que');
        info('por ahora solo podrás generar facturas de prueba.');
    }
} else {
    info('No se pudo obtener la llave de la organización. Revisa que el ID');
    info("($organization_id) sea correcto y que esa organización exista.");
}

echo "\n\nBORRA ESTE ARCHIVO DEL SERVIDOR CUANDO TERMINES.\n";
