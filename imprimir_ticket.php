<?php


session_start();
date_default_timezone_set('America/Mexico_City');

// Verificar si hay una venta para imprimir
if (!isset($_SESSION['venta_realizada'])) {
    die("No hay venta para imprimir");
}

$venta = $_SESSION['venta_realizada'];

// Obtener los timbres disponibles de la venta
$timbres_disponibles = $venta['timbres_disponibles'] ?? 0;
$plan_empresa = $venta['plan_empresa'] ?? 'prueba';

// Determinar si mostrar QR de facturación basado en timbres disponibles
$mostrar_qr_facturacion = ($timbres_disponibles > 0 && !empty($venta['url_facturacion'] ?? $venta['facturapi_invoice_url'] ?? ''));

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'] ?? '';

// Inicializar variables
$empresa_nombre = 'Mi Empresa';
$empresa_direccion = '';
$empresa_telefono = '';
$empresa_rfc = '';
$empresa_logo = '';
$sucursal_nombre = 'Sucursal';
$sucursal_direccion = '';
$sucursal_telefono = '';
$usuario_nombre = 'Usuario';
$cliente_nombre = "Cliente General";

// Variables de descuento
$descuento_total = $venta['descuento'] ?? 0;
$subtotal_sin_descuento = $venta['subtotal'] ?? 0;
$subtotal_con_descuento = $subtotal_sin_descuento - $descuento_total;

// Variables para facturación (solo si hay timbres disponibles)
$url_facturacion = $venta['url_facturacion'] ?? $venta['facturapi_invoice_url'] ?? '';

if (!empty($dbname)) {
    try {
        $conn = new mysqli($servername, $username, $password, $dbname);

        if (!$conn->connect_error) {
            // Obtener información de la empresa desde sistema_config
            $sql_empresa = "SELECT nombre_empresa, direccion, telefono, rfc, logo FROM sistema_config LIMIT 1";
            $result_empresa = $conn->query($sql_empresa);
            if ($empresa = $result_empresa->fetch_assoc()) {
                $empresa_nombre = $empresa['nombre_empresa'] ?? 'Mi Empresa';
                $empresa_direccion = $empresa['direccion'] ?? '';
                $empresa_telefono = $empresa['telefono'] ?? '';
                $empresa_rfc = $empresa['rfc'] ?? '';
                $empresa_logo = $empresa['logo'] ?? '';
            }

            // Obtener información de la sucursal
            if (isset($_SESSION['sucursal_id'])) {
                $sucursal_id = $_SESSION['sucursal_id'];
                $sql_sucursal = "SELECT nombre, direccion, telefono FROM sucursales WHERE id = ?";
                $stmt_sucursal = $conn->prepare($sql_sucursal);
                $stmt_sucursal->bind_param("i", $sucursal_id);
                $stmt_sucursal->execute();
                $result_sucursal = $stmt_sucursal->get_result();
                if ($sucursal = $result_sucursal->fetch_assoc()) {
                    $sucursal_nombre = $sucursal['nombre'] ?? 'Sucursal';
                    $sucursal_direccion = $sucursal['direccion'] ?? '';
                    $sucursal_telefono = $sucursal['telefono'] ?? '';
                }
                $stmt_sucursal->close();
            }

            // Obtener información del usuario
            if (isset($_SESSION['usuario_id'])) {
                $usuario_id = $_SESSION['usuario_id'];
                $sql_usuario = "SELECT nombre FROM usuarios WHERE id = ?";
                $stmt_usuario = $conn->prepare($sql_usuario);
                $stmt_usuario->bind_param("i", $usuario_id);
                $stmt_usuario->execute();
                $result_usuario = $stmt_usuario->get_result();
                if ($usuario = $result_usuario->fetch_assoc()) {
                    $usuario_nombre = $usuario['nombre'] ?? 'Usuario';
                }
                $stmt_usuario->close();
            }

            // Obtener información del cliente si existe
            if ($venta['cliente_id']) {
                $sql_cliente = "SELECT nombre FROM clientes WHERE id = ?";
                $stmt_cliente = $conn->prepare($sql_cliente);
                $stmt_cliente->bind_param("i", $venta['cliente_id']);
                $stmt_cliente->execute();
                $result_cliente = $stmt_cliente->get_result();
                if ($cliente = $result_cliente->fetch_assoc()) {
                    $cliente_nombre = $cliente['nombre'];
                }
                $stmt_cliente->close();
            }

            // Si hay timbres disponibles y no tenemos URL en sesión, buscar en la BD
            if ($timbres_disponibles > 0 && empty($url_facturacion)) {
                $sql_url = "SELECT urlfacturacion FROM ventas WHERE id = ?";
                $stmt_url = $conn->prepare($sql_url);
                $stmt_url->bind_param("i", $venta['venta_id']);
                $stmt_url->execute();
                $result_url = $stmt_url->get_result();
                if ($url_data = $result_url->fetch_assoc()) {
                    $url_facturacion = $url_data['urlfacturacion'] ?? '';
                    $mostrar_qr_facturacion = ($timbres_disponibles > 0 && !empty($url_facturacion));
                }
                $stmt_url->close();
            }

            $conn->close();
        }
    } catch (Exception $e) {
        // Si hay error, mantener los valores por defecto
        error_log("Error al obtener datos para el ticket: " . $e->getMessage());
    }
}

// Función para acortar URLs de Facturapi
function acortarUrlFacturapi($url) {
    if (empty($url)) return '';
    
    // Extraer solo el dominio y parte importante
    if (preg_match('/https?:\/\/([^\/]+)\/(.+)/', $url, $matches)) {
        $dominio = $matches[1];
        $path = $matches[2];
        
        // Si es Facturapi, mostrar solo el dominio y el ID
        if (strpos($dominio, 'facturapi.io') !== false || strpos($dominio, 'facturapi.com') !== false) {
            // Extraer ID del recibo si está en la URL
            if (preg_match('/\/(rec|recibo|receipt)\/([a-zA-Z0-9]+)/', $url, $id_match)) {
                return "facturapi.io/rec/" . substr($id_match[2], 0, 8) . "...";
            }
        }
        
        // Para otros dominios, mostrar dominio y parte del path
        $path_short = (strlen($path) > 15) ? substr($path, 0, 15) . "..." : $path;
        return $dominio . "/" . $path_short;
    }
    
    // Si no se puede parsear, devolver truncado
    return (strlen($url) > 30) ? substr($url, 0, 30) . "..." : $url;
}

// Generar QR solo si hay timbres disponibles y hay URL
if ($mostrar_qr_facturacion) {
    // Generar QR usando API QR Server
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($url_facturacion);
    // Crear URL acortada para mostrar
    $facturacion_short_url = acortarUrlFacturapi($url_facturacion);
} else {
    // Si no hay timbres, no mostrar QR
    $mostrar_qr_facturacion = false;
}

// Limpiar la venta de la sesión después de obtener los datos
unset($_SESSION['venta_realizada']);

// Headers para mejor compatibilidad
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket de Venta - <?php echo htmlspecialchars($venta['codigo_venta']); ?></title>
    <style>
        /* RESET COMPLETO - TODO EN NEGRITA */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-weight: bold !important;
        }

        /* ESTILOS BASE CON MEJOR ESPACIADO */
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.3;
            width: 58mm;
            margin: 0 auto;
            padding: 2mm;
            background: white;
            color: black;
            -webkit-font-smoothing: none;
            -moz-osx-font-smoothing: none;
            font-weight: bold;
        }

        /* MEDIA PRINT - CONFIGURACIÓN PARA IMPRESORAS TÉRMICAS */
        @media print {
            @page {
                size: 58mm auto;
                margin: 0;
                padding: 0;
            }

            body {
                width: 58mm;
                margin: 0 auto;
                padding: 2mm 3mm;
                font-size: 11px;
                background: white;
                font-weight: bold;
                line-height: 1.3;
            }

            .no-print {
                display: none !important;
            }

            /* FORZAR TODO A NEGRO PURO Y NEGRITA */
            * {
                color: #000000 !important;
                background: transparent !important;
                font-weight: bold !important;
            }

            .texto-descuento {
                color: #000000 !important;
            }
        }

        /* CONTENEDOR PRINCIPAL */
        .ticket {
            width: 52mm;
            margin: 0 auto;
            word-wrap: break-word;
            background: white;
            font-weight: bold;
        }

        /* LOGO STYLES */
        .logo-container {
            text-align: center;
            margin: 2px 0;
            padding: 1px 0;
        }

        .logo-img {
            max-width: 40mm;
            max-height: 20mm;
            height: auto;
            display: inline-block;
            margin: 0 auto;
        }

        /* ALINEACIONES */
        .texto-centro {
            text-align: center;
            width: 100%;
            font-weight: bold;
            margin: 3px 0;
        }

        .texto-derecha {
            text-align: right;
            font-weight: bold;
            margin: 2px 0;
        }

        .texto-izquierda {
            text-align: left;
            font-weight: bold;
            margin: 2px 0;
        }

        /* TIPOGRAFÍAS - TODAS EN NEGRITA */
        .negrita {
            font-weight: bold;
            font-size: 11px;
            margin: 3px 0;
        }

        .super-negrita {
            font-weight: bold;
            font-size: 12px;
            margin: 4px 0;
        }

        /* LÍNEAS CON MÁS ESPACIO */
        .linea-divisoria {
            border-bottom: 1px solid #000;
            margin: 5px 0;
            display: block;
            height: 1px;
        }

        .linea-punteada {
            border-bottom: 1px dashed #000;
            margin: 4px 0;
            display: block;
            height: 1px;
        }

        .doble-linea {
            border-bottom: 2px solid #000;
            margin: 6px 0;
            display: block;
            height: 2px;
        }

        /* ESPACIOS MEJORADOS */
        .espacio {
            height: 6px;
            display: block;
            clear: both;
        }

        .espacio-pequeno {
            height: 3px;
            display: block;
            clear: both;
        }

        .espacio-minimo {
            height: 1px;
            display: block;
            clear: both;
        }

        /* CONTROLES (SOLO PREVIEW) */
        .controls {
            text-align: center;
            margin: 15px 0;
            padding: 10px;
            font-weight: bold;
            background: #f5f5f5;
            border-radius: 5px;
        }

        .btn {
            padding: 8px 15px;
            margin: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-family: Arial, sans-serif;
            font-weight: bold;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        /* TABLAS CON MEJOR ESPACIADO */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 4px 0;
            table-layout: fixed;
            font-weight: bold;
        }

        td {
            padding: 2px 1px;
            vertical-align: top;
            font-size: 10px;
            line-height: 1.2;
            font-weight: bold;
        }

        th {
            font-weight: bold;
            padding: 3px 1px;
        }

        /* INFORMACIÓN EMPRESA */
        .info-empresa {
            font-size: 10px;
            line-height: 1.2;
            margin: 3px 0;
            font-weight: bold;
            padding: 1px 0;
        }

        /* PRODUCTOS CON MÁS ESPACIO */
        .producto-nombre {
            font-size: 10px;
            line-height: 1.2;
            word-break: break-word;
            font-weight: bold;
            padding: 1px 0;
        }

        .encabezado-productos td {
            border-bottom: 1px solid #000;
            font-size: 10px;
            font-weight: bold;
            padding: 3px 1px;
        }

        .total-final {
            border-top: 1px solid #000;
            font-weight: bold;
            padding: 4px 0;
        }

        /* COLUMNAS OPTIMIZADAS CON MÁS ESPACIO */
        .col-cantidad {
            width: 18%;
            font-size: 10px;
            font-weight: bold;
            padding-right: 2px;
        }

        .col-descripcion {
            width: 47%;
            font-size: 10px;
            font-weight: bold;
            padding: 0 2px;
        }

        .col-precio {
            width: 35%;
            text-align: right;
            font-size: 10px;
            font-weight: bold;
            padding-left: 2px;
        }

        /* PIE DE TICKET */
        .pie-ticket {
            font-size: 9px;
            line-height: 1.3;
            font-weight: bold;
            margin: 6px 0;
            padding: 3px 0;
        }

        .codigo-venta {
            font-size: 11px;
            font-weight: bold;
            margin: 4px 0;
            padding: 2px 0;
        }

        .fecha-hora {
            font-size: 10px;
            font-weight: bold;
            margin: 3px 0;
            padding: 2px 0;
        }

        /* CLASES ESPECIALES PARA TEXTO EXTRA NEGRITO */
        .texto-extra-negrita {
            font-weight: 900 !important;
        }

        strong,
        b {
            font-weight: 900 !important;
        }

        /* MÁRGENES PARA MEJOR SEPARACIÓN */
        .seccion {
            margin: 5px 0;
            padding: 3px 0;
        }

        .item-producto {
            margin: 2px 0;
            padding: 1px 0;
        }

        /* MENSAJE DE ESTADO */
        .estado-impresion {
            font-size: 10px;
            color: #666;
            margin-top: 10px;
            font-weight: bold;
        }

        /* MENSAJE DE IMPRESIÓN AUTOMÁTICA */
        .auto-print-message {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: #007bff;
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 14px;
            z-index: 10000;
            font-weight: bold;
        }

        /* LOADING SPINNER */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007bff;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 10px auto;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* ESTILO PARA DESCUENTO (TEXTO ROJO EN PANTALLA, NEGRO EN IMPRESIÓN) */
        .texto-descuento {
            color: #ff0000 !important;
            font-weight: bold;
        }

        /* DESCUENTO POR PRODUCTO */
        .descuento-producto {
            font-size: 8px !important;
            line-height: 1.1 !important;
            color: #ff0000 !important;
            font-weight: bold !important;
            display: block;
            margin-top: 1px;
        }

        @media print {
            .descuento-producto {
                color: #000000 !important;
            }
        }

        /* PRECIO ORIGINAL TACHADO */
        .precio-original {
            text-decoration: line-through;
            font-size: 9px !important;
            color: #666 !important;
            display: inline;
            margin-right: 3px;
        }

        @media print {
            .precio-original {
                color: #000000 !important;
            }
        }

        /* PRECIO CON DESCUENTO */
        .precio-con-descuento {
            font-size: 10px !important;
            color: #ff0000 !important;
            font-weight: bold !important;
            display: inline;
        }

        @media print {
            .precio-con-descuento {
                color: #000000 !important;
            }
        }

        /* CONTENEDOR DE PRECIOS */
        .contenedor-precios {
            font-size: 9px !important;
            line-height: 1.2 !important;
            margin-top: 1px;
        }

        /* NUEVOS ESTILOS PARA EL CÓDIGO QR */
        .qr-container {
            text-align: center;
            margin: 5px auto;
            padding: 3px;
        }

        .qr-code {
            width: 35mm;
            height: 35mm;
            display: block;
            margin: 0 auto;
            image-rendering: pixelated;
            image-rendering: -moz-crisp-edges;
            image-rendering: crisp-edges;
            border: 1px solid #000;
        }

        .qr-text {
            font-size: 8px;
            line-height: 1.1;
            margin: 2px 0;
            font-weight: bold;
            text-align: center;
        }

        .qr-link {
            font-size: 7px;
            word-break: break-all;
            line-height: 1.0;
            margin: 1px 0;
            font-weight: bold;
        }

        @media print {
            .qr-text, .qr-link {
                color: #000000 !important;
            }
            
            .qr-code {
                border: 1px solid #000 !important;
            }
        }

        .facturacion-nota {
            font-size: 8px;
            line-height: 1.1;
            margin: 3px 0;
            text-align: center;
            font-weight: bold;
        }
        
        /* Estilos para cuando no carga el QR */
        .qr-error {
            font-size: 7px;
            color: #ff0000;
            font-weight: bold;
            text-align: center;
            margin: 5px 0;
            padding: 2px;
            border: 1px dashed #ff0000;
        }
        
        @media print {
            .qr-error {
                color: #000000 !important;
                border-color: #000000 !important;
            }
        }

        /* Estilo para cuando se muestra QR */
        .qr-section-premium {
            margin: 6px 0;
            padding: 3px 0;
        }
        
        /* Mensaje para cuando no hay timbres disponibles */
        .mensaje-no-timbres {
            font-size: 8px;
            color: #666;
            text-align: center;
            margin: 5px 0;
            padding: 3px;
            border: 1px dashed #ccc;
            font-weight: bold;
        }

        /* Mensaje informativo sobre timbres */
        .info-timbres {
            font-size: 7px;
            color: #666;
            text-align: center;
            margin: 2px 0;
        }

        @media print {
            .mensaje-no-timbres,
            .info-timbres {
                color: #000000 !important;
                border-color: #000000 !important;
            }
        }
    </style>
</head>

<body>
    <!-- Mensaje de impresión automática -->
    <div class="auto-print-message no-print">
        🖨️ Imprimiendo ticket automáticamente...
        <div class="spinner"></div>
    </div>

    <div class="ticket">
        <!-- Logo de la empresa -->
        <?php if (!empty($empresa_logo)): ?>
            <div class="logo-container seccion">
                <?php
                // Determinar la ruta del logo
                $logo_path = '';
                if (file_exists($empresa_logo)) {
                    $logo_path = $empresa_logo;
                } elseif (file_exists('../' . $empresa_logo)) {
                    $logo_path = '../' . $empresa_logo;
                } elseif (file_exists('../../' . $empresa_logo)) {
                    $logo_path = '../../' . $empresa_logo;
                }

                if (!empty($logo_path) && file_exists($logo_path)):
                    // Obtener la extensión del archivo
                    $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
                    // Leer el archivo y convertirlo a base64
                    $logo_data = base64_encode(file_get_contents($logo_path));
                    $logo_src = 'data:image/' . $extension . ';base64,' . $logo_data;
                ?>
                    <img src="<?php echo $logo_src; ?>" alt="Logo" class="logo-img">
                    <div class="espacio-minimo"></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Encabezado con información de la empresa -->
        <div class="texto-centro super-negrita">
            <?php echo htmlspecialchars($empresa_nombre); ?>
        </div>

        <div class="espacio-minimo"></div>

        <?php if (!empty($empresa_rfc)): ?>
            <div class="texto-centro info-empresa">
                <strong>RFC:</strong> <?php echo htmlspecialchars($empresa_rfc); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($empresa_direccion)): ?>
            <div class="texto-centro info-empresa">
                <?php echo htmlspecialchars(substr($empresa_direccion, 0, 35)); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($empresa_telefono)): ?>
            <div class="texto-centro info-empresa">
                <strong>TEL:</strong> <?php echo htmlspecialchars($empresa_telefono); ?>
            </div>
        <?php endif; ?>

        <div class="linea-divisoria"></div>

        <!-- Información de la sucursal -->
        <div class="texto-centro negrita seccion">
            <?php echo htmlspecialchars($sucursal_nombre); ?>
        </div>

        <?php if (!empty($sucursal_direccion)): ?>
            <div class="texto-centro info-empresa">
                <?php echo htmlspecialchars(substr($sucursal_direccion, 0, 35)); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($sucursal_telefono)): ?>
            <div class="texto-centro info-empresa">
                <strong>SUC. TEL:</strong> <?php echo htmlspecialchars($sucursal_telefono); ?>
            </div>
        <?php endif; ?>

        <div class="linea-divisoria"></div>

        <!-- Información de fecha y venta -->
        <div class="texto-centro fecha-hora seccion">
            <?php echo date('d/m/Y H:i:s', strtotime($venta['fecha'])); ?>
        </div>
        <div class="texto-centro negrita codigo-venta">
            VENTA: <?php echo htmlspecialchars($venta['codigo_venta']); ?>
        </div>

        <div class="linea-punteada"></div>

        <!-- Información de cliente y vendedor -->
        <div class="info-empresa seccion">
            <strong>CLIENTE:</strong> <?php echo htmlspecialchars(substr($cliente_nombre, 0, 22)); ?>
        </div>
        <div class="info-empresa">
            <strong>VENDEDOR:</strong> <?php echo htmlspecialchars(substr($usuario_nombre, 0, 18)); ?>
        </div>

        <div class="linea-punteada"></div>

        <!-- Encabezado de productos -->
        <table>
            <tr class="encabezado-productos">
                <td class="col-cantidad negrita">CANT</td>
                <td class="col-descripcion negrita">DESCRIPCION</td>
                <td class="col-precio negrita">IMPORTE</td>
            </tr>
        </table>

        <!-- Lista de productos CON DESCUENTOS POR PRODUCTO -->
        <?php
        $total_descuento_productos = 0;
        foreach ($venta['productos'] as $producto):
            // Calcular si tiene descuento
            $tiene_descuento = isset($producto['descuento']) && $producto['descuento'] > 0;
            $descuento_producto = $producto['descuento'] ?? 0;
            $total_descuento_productos += $descuento_producto;

            // Determinar el precio a mostrar
            $precio_unitario = $producto['precio'];
            $subtotal_original = $producto['subtotal'];
            $subtotal_con_descuento = isset($producto['subtotal_con_descuento']) ? $producto['subtotal_con_descuento'] : $subtotal_original;

            // Calcular porcentaje de descuento si existe
            $porcentaje_descuento = 0;
            if ($tiene_descuento && $subtotal_original > 0) {
                $porcentaje_descuento = ($descuento_producto / $subtotal_original) * 100;
            }
        ?>
            <table class="item-producto">
                <tr>
                    <td class="col-cantidad" style="vertical-align: top;">
                        <?php
                        if ($producto['permite_fracciones'] == 0) {
                            echo intval($producto['cantidad']);
                        } else {
                            echo number_format($producto['cantidad'], 3);
                        }
                        ?>
                    </td>
                    <td class="col-descripcion producto-nombre">
                        <?php echo htmlspecialchars(substr($producto['nombre'], 0, 20)); ?>

                        <?php if ($tiene_descuento): ?>
                            <!-- Mostrar información de descuento por producto -->
                            <div class="descuento-producto">
                                -<?php echo number_format($porcentaje_descuento, 1); ?>% ( -$<?php echo number_format($descuento_producto, 2); ?> )
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="col-precio" style="vertical-align: top;">
                        <div class="contenedor-precios">
                            <?php if ($tiene_descuento): ?>
                                <!-- Mostrar precio original tachado y precio con descuento -->
                                <div class="precio-original">
                                    $<?php echo number_format($subtotal_original, 2); ?>
                                </div>
                                <div class="precio-con-descuento">
                                    $<?php echo number_format($subtotal_con_descuento, 2); ?>
                                </div>
                            <?php else: ?>
                                <!-- Mostrar precio normal -->
                                $<?php echo number_format($subtotal_original, 2); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>

        <div class="doble-linea"></div>

        <!-- Totales CON DESCUENTO DETALLADO -->
        <table class="seccion">
            <tr>
                <td class="texto-izquierda">Subtotal:</td>
                <td class="texto-derecha">$<?php echo number_format($subtotal_sin_descuento, 2); ?></td>
            </tr>

            <?php if ($descuento_total > 0): ?>
                <tr class="texto-descuento negrita">
                    <td class="texto-izquierda">Descuento:</td>
                    <td class="texto-derecha">-$<?php echo number_format($descuento_total, 2); ?></td>
                </tr>
            <?php endif; ?>

            <!-- TOTAL FINAL -->
            <tr class="total-final negrita">
                <td class="texto-izquierda">TOTAL:</td>
                <td class="texto-derecha">$<?php echo number_format($venta['total'], 2); ?></td>
            </tr>
        </table>

        <div class="linea-punteada"></div>

        <!-- Información de pago -->
        <div class="info-empresa negrita seccion">
            PAGO: <?php echo strtoupper($venta['metodo_pago']); ?>
        </div>

        <?php if ($venta['metodo_pago'] === 'efectivo'): ?>
            <table class="seccion">
                <tr>
                    <td class="texto-izquierda">EFECTIVO:</td>
                    <td class="texto-derecha">$<?php echo number_format($venta['efectivo_recibido'], 2); ?></td>
                </tr>
                <tr class="negrita">
                    <td class="texto-izquierda">CAMBIO:</td>
                    <td class="texto-derecha">$<?php echo number_format($venta['cambio'], 2); ?></td>
                </tr>
            </table>
        <?php endif; ?>

        <div class="linea-divisoria"></div>

        <!-- SECCIÓN DE FACTURACIÓN SOLO SI HAY TIMBRES DISPONIBLES -->
        <?php if ($mostrar_qr_facturacion): ?>
        <div class="qr-section-premium">
            <div class="qr-text negrita">
                🌐 FACTURACIÓN ELECTRÓNICA
            </div>
            <div class="qr-container">
                <img src="<?php echo $qr_url; ?>" alt="Código QR para facturación" class="qr-code">
                <div class="facturacion-nota">
                    Escanee para factura electrónica
                </div>
                <div class="qr-link">
                    <?php echo htmlspecialchars($facturacion_short_url); ?>
                </div>
            </div>
            <div class="texto-centro info-empresa" style="font-size: 7px;">
                Recibo electrónico disponible en línea
            </div>
            <div class="info-timbres">
                Timbres restantes: <?php echo $timbres_disponibles; ?>
            </div>
        </div>
        <?php elseif ($timbres_disponibles <= 0 && !empty($plan_empresa)): ?>
        <div class="mensaje-no-timbres">
            ⚠️ Facturación electrónica no disponible<br>
            <small>Sin timbres fiscales disponibles</small>
        </div>
        <?php else: ?>
        <div class="mensaje-no-timbres">
            * Facturación disponible con timbres *
        </div>
        <?php endif; ?>

        <div class="linea-divisoria"></div>

        <!-- Pie del ticket -->
        <div class="texto-centro pie-ticket seccion">
            <div class="espacio-minimo"></div>
            <strong>¡Gracias por su compra!</strong><br>
            <div class="espacio-minimo"></div>
            Vuelva pronto<br>
            <div class="espacio-minimo"></div>
            * Ticket comprobante *<br>
            * Conserve para aclaraciones *<br>
            <?php if ($timbres_disponibles > 0): ?>
            <div class="espacio-minimo"></div>
            * Facturación electrónica disponible *
            <?php endif; ?>
            <div class="espacio-minimo"></div>
            <?php if ($descuento_total > 0): ?>
                <div class="texto-descuento" style="font-size: 8px;">
                    * Descuento aplicado: $<?php echo number_format($descuento_total, 2); ?> *
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Controles solo para previsualización -->
    <div class="controls no-print">
        <button class="btn btn-primary" onclick="reimprimir()">
            🖨️ Reimprimir
        </button>
        <button class="btn btn-secondary" onclick="cerrarVentana()">
            ❌ Cerrar
        </button>
        <div class="estado-impresion" id="estadoImpresion">
            Impresión automática en curso...
        </div>
    </div>

    <script>
        // Variable para controlar impresión
        let impresionCompletada = false;
        let intentosImpresion = 0;
        const MAX_INTENTOS = 3;

        // Función para imprimir automáticamente SIN diálogo
        function imprimirAutomaticamente() {
            if (impresionCompletada || intentosImpresion >= MAX_INTENTOS) {
                return;
            }

            intentosImpresion++;
            console.log(`Intento de impresión ${intentosImpresion}/${MAX_INTENTOS}`);

            try {
                // Método 1: window.print() estándar
                window.print();

                // Marcar como completado después de un tiempo
                setTimeout(() => {
                    if (!impresionCompletada) {
                        impresionCompletada = true;
                        console.log('Impresión marcada como completada');
                        document.getElementById('estadoImpresion').innerHTML =
                            '✅ Impresión completada - Cerrando ventana...';

                        // Cerrar ventana después de imprimir
                        setTimeout(cerrarVentana, 1000);
                    }
                }, 1000);

            } catch (error) {
                console.error('Error en impresión:', error);
                document.getElementById('estadoImpresion').innerHTML =
                    '❌ Error en impresión: ' + error.message;
            }
        }

        // Función para reimprimir manualmente
        function reimprimir() {
            impresionCompletada = false;
            intentosImpresion = 0;
            document.getElementById('estadoImpresion').innerHTML =
                '🖨️ Reimprimiendo...';
            setTimeout(imprimirAutomaticamente, 500);
        }

        // Función para cerrar la ventana
        function cerrarVentana() {
            console.log('Cerrando ventana de ticket...');
            window.close();

            // Fallback para navegadores que bloquean window.close()
            setTimeout(() => {
                if (!window.closed) {
                    document.getElementById('estadoImpresion').innerHTML =
                        '⚠️ La ventana no se cerró automáticamente. Puede cerrarla manualmente.';
                }
            }, 2000);
        }

        // Múltiples estrategias para iniciar impresión automática
        function iniciarImpresionAutomatica() {
            console.log('Iniciando proceso de impresión automática...');

            // Estrategia 1: Después de que la página cargue completamente
            setTimeout(imprimirAutomaticamente, 800);

            // Estrategia 2: Cuando la ventana gane foco
            setTimeout(imprimirAutomaticamente, 1200);

            // Estrategia 3: Como último recurso
            setTimeout(imprimirAutomaticamente, 2000);
        }

        // Eventos para iniciar impresión automática
        window.addEventListener('load', iniciarImpresionAutomatica);
        document.addEventListener('DOMContentLoaded', iniciarImpresionAutomatica);
        window.addEventListener('focus', iniciarImpresionAutomatica);

        // Detectar cuando la impresión se completa
        window.addEventListener('afterprint', function() {
            console.log('Evento afterprint detectado');
            impresionCompletada = true;
            document.getElementById('estadoImpresion').innerHTML =
                '✅ Impresión completada - Cerrando ventana...';
            setTimeout(cerrarVentana, 1500);
        });

        // Cierre automático de respaldo después de 10 segundos
        setTimeout(function() {
            if (!impresionCompletada && !window.closed) {
                console.log('Cierre automático por timeout');
                document.getElementById('estadoImpresion').innerHTML =
                    '⏰ Cierre automático - Si la impresión falló, use el botón Reimprimir';
                cerrarVentana();
            }
        }, 10000);

        // Atajos de teclado
        document.addEventListener('keydown', function(e) {
            // ESC para cerrar
            if (e.key === 'Escape') {
                cerrarVentana();
            }
            // Ctrl+P o Cmd+P para reimprimir
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                reimprimir();
            }
            // F5 para reimprimir
            if (e.key === 'F5') {
                e.preventDefault();
                reimprimir();
            }
        });

        // Prevenir que el usuario cierre accidentalmente
        window.addEventListener('beforeunload', function(e) {
            if (!impresionCompletada && intentosImpresion < MAX_INTENTOS) {
                e.preventDefault();
                e.returnValue = '¿Está seguro de que desea cerrar sin imprimir el ticket?';
                return e.returnValue;
            }
        });
    </script>
</body>

</html>