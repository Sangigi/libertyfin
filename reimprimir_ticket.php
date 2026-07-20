<?php
// reimprimir_ticket.php
session_start();
date_default_timezone_set('America/Mexico_City');

// Activar visualización de errores temporalmente para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    die("Acceso no autorizado");
}

// Verificar si hay una venta para imprimir
$venta_id = isset($_GET['venta_id']) ? intval($_GET['venta_id']) : 0;
$es_whatsapp = isset($_GET['whatsapp']) && $_GET['whatsapp'] == 1;

if ($venta_id <= 0) {
    die("No se especificó venta para imprimir");
}

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
$descuento_total = 0;
$subtotal_sin_descuento = 0;
$subtotal_con_descuento = 0;
$productos = array();

// Variables para facturación
$facturacion_url = '';
$qr_url = '';
$qr_base64 = '';
$debug_info = '';

// Función para generar QR manualmente usando una librería simple
function generarQRSimple($texto, $size = 150) {
    // Usar API de QR Code Monkey (más confiable que Google Charts)
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($texto);
    
    // Alternativa: Usar QR code generator (también confiable)
    // return "https://quickchart.io/qr?text=" . urlencode($texto) . "&size={$size}";
}

// Función para crear un QR de respaldo en texto (si las APIs fallan)
function generarQRTexto($texto) {
    // Esta función genera una representación en texto del QR
    // No es un QR real, pero al menos muestra la URL
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='150' height='150' viewBox='0 0 150 150'%3E%3Crect width='150' height='150' fill='%23f0f0f0'/%3E%3Ctext x='10' y='40' font-family='Arial' font-size='10' fill='%23333'%3ECODIGO QR%3C/text%3E%3Ctext x='10' y='60' font-family='Arial' font-size='8' fill='%23333'%3E" . substr($texto, 0, 30) . "%3C/text%3E%3Ctext x='10' y='80' font-family='Arial' font-size='8' fill='%23333'%3E" . substr($texto, 30, 30) . "%3C/text%3E%3Ctext x='10' y='100' font-family='Arial' font-size='8' fill='%23333'%3E" . substr($texto, 60, 30) . "%3C/text%3E%3C/svg%3E";
}

// Función para generar el HTML del ticket para WhatsApp
function generarTicketWhatsApp($venta_data, $productos, $empresa_nombre, $empresa_rfc, $empresa_direccion, $empresa_telefono, $sucursal_nombre, $sucursal_direccion, $sucursal_telefono, $usuario_nombre, $cliente_nombre, $descuento_total, $subtotal_sin_descuento, $total_descuento_productos) {
    ob_start();
    ?>
    <div style="font-family: 'Courier New', Courier, monospace; font-size: 12px; max-width: 400px; margin: 0 auto; padding: 20px; background: white; border: 1px solid #ccc;">
        <!-- Encabezado -->
        <div style="text-align: center; margin-bottom: 15px;">
            <h2 style="margin: 0; font-size: 18px;"><?php echo htmlspecialchars($empresa_nombre); ?></h2>
            <?php if (!empty($empresa_rfc)): ?>
                <p style="margin: 2px 0;">RFC: <?php echo htmlspecialchars($empresa_rfc); ?></p>
            <?php endif; ?>
            <?php if (!empty($empresa_direccion)): ?>
                <p style="margin: 2px 0;"><?php echo htmlspecialchars($empresa_direccion); ?></p>
            <?php endif; ?>
            <?php if (!empty($empresa_telefono)): ?>
                <p style="margin: 2px 0;">Tel: <?php echo htmlspecialchars($empresa_telefono); ?></p>
            <?php endif; ?>
        </div>
        
        <div style="border-top: 2px solid #000; margin: 10px 0;"></div>
        
        <!-- Sucursal -->
        <div style="text-align: center; margin: 10px 0;">
            <strong><?php echo htmlspecialchars($sucursal_nombre); ?></strong>
            <?php if (!empty($sucursal_direccion)): ?>
                <br><?php echo htmlspecialchars($sucursal_direccion); ?>
            <?php endif; ?>
            <?php if (!empty($sucursal_telefono)): ?>
                <br>Tel: <?php echo htmlspecialchars($sucursal_telefono); ?>
            <?php endif; ?>
        </div>
        
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>
        
        <!-- Fecha y venta -->
        <div style="text-align: center; margin: 10px 0;">
            <p><strong>Fecha:</strong> <?php echo date('d/m/Y H:i:s', strtotime($venta_data['fecha'])); ?></p>
            <p><strong>Venta:</strong> <?php echo htmlspecialchars($venta_data['codigo_venta']); ?></p>
        </div>
        
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>
        
        <!-- Cliente y vendedor -->
        <div style="margin: 10px 0;">
            <p><strong>Cliente:</strong> <?php echo htmlspecialchars($cliente_nombre); ?></p>
            <p><strong>Vendedor:</strong> <?php echo htmlspecialchars($usuario_nombre); ?></p>
        </div>
        
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>
        
        <!-- Productos -->
        <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
            <thead>
                <tr style="border-bottom: 1px solid #000;">
                    <th style="text-align: left; padding: 5px;">Cant</th>
                    <th style="text-align: left; padding: 5px;">Descripción</th>
                    <th style="text-align: right; padding: 5px;">Importe</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $producto): 
                    $tiene_descuento = isset($producto['descuento']) && $producto['descuento'] > 0;
                ?>
                <tr>
                    <td style="padding: 3px; vertical-align: top;">
                        <?php echo $producto['permite_fracciones'] ? number_format($producto['cantidad'], 3) : intval($producto['cantidad']); ?>
                    </td>
                    <td style="padding: 3px; vertical-align: top;">
                        <?php echo htmlspecialchars(substr($producto['nombre'], 0, 25)); ?>
                        <?php if ($tiene_descuento): ?>
                            <br><small style="color: #ff0000;">-<?php echo number_format(($producto['descuento'] / ($producto['precio'] * $producto['cantidad']) * 100), 1); ?>%</small>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 3px; text-align: right; vertical-align: top;">
                        $<?php echo number_format($producto['subtotal_con_descuento'] ?? $producto['subtotal'], 2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="border-top: 2px solid #000; margin: 10px 0;"></div>
        
        <!-- Totales -->
        <div style="margin: 10px 0;">
            <p><strong>Subtotal:</strong> $<?php echo number_format($subtotal_sin_descuento, 2); ?></p>
            <?php if ($descuento_total > 0): ?>
                <p style="color: #ff0000;"><strong>Descuento:</strong> -$<?php echo number_format($descuento_total, 2); ?></p>
            <?php endif; ?>
            <p style="font-size: 16px;"><strong>TOTAL:</strong> $<?php echo number_format($venta_data['total'], 2); ?></p>
        </div>
        
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>
        
        <!-- Método de pago -->
        <div style="margin: 10px 0;">
            <p><strong>Pago:</strong> <?php echo strtoupper($venta_data['metodo_pago']); ?></p>
            <?php if ($venta_data['metodo_pago'] === 'efectivo'): ?>
                <p><strong>Efectivo:</strong> $<?php echo number_format($venta_data['efectivo_recibido'], 2); ?></p>
                <p><strong>Cambio:</strong> $<?php echo number_format($venta_data['cambio'], 2); ?></p>
            <?php endif; ?>
        </div>
        
        <div style="border-top: 1px solid #000; margin: 10px 0;"></div>
        
        <!-- QR de facturación si existe -->
        <?php if (!empty($venta_data['urlfacturacion'])): ?>
        <div style="text-align: center; margin: 15px 0;">
            <p><strong>🌐 FACTURACIÓN ELECTRÓNICA</strong></p>
            <p style="font-size: 10px;">Link para facturar:</p>
            <p style="font-size: 9px; word-break: break-all; background: #f5f5f5; padding: 5px;">
                <?php echo htmlspecialchars($venta_data['urlfacturacion']); ?>
            </p>
        </div>
        <div style="border-top: 1px dashed #000; margin: 10px 0;"></div>
        <?php endif; ?>
        
        <!-- Pie -->
        <div style="text-align: center; margin: 15px 0;">
            <p><strong>¡Gracias por su compra!</strong></p>
            <p style="font-size: 10px;">Ticket comprobante - Conserve para aclaraciones</p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Estructura para los datos de la venta
$venta_data = array();

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

            // Obtener información de la venta
            $sql_venta = "SELECT v.*, c.nombre as cliente_nombre 
                         FROM ventas v 
                         LEFT JOIN clientes c ON v.cliente_id = c.id 
                         WHERE v.id = ?";
            $stmt_venta = $conn->prepare($sql_venta);
            $stmt_venta->bind_param("i", $venta_id);
            $stmt_venta->execute();
            $result_venta = $stmt_venta->get_result();
            
            if ($venta = $result_venta->fetch_assoc()) {
                $venta_data = $venta;
                $descuento_total = $venta['descuento'] ?? 0;
                $subtotal_sin_descuento = $venta['subtotal'] ?? 0;
                $subtotal_con_descuento = $subtotal_sin_descuento - $descuento_total;
                $cliente_nombre = $venta['cliente_nombre'] ?? "Cliente General";
                
                // Obtener URL de facturación
                $facturacion_url = $venta['urlfacturacion'] ?? '';
                
                // DEPURACIÓN: Guardar información de la URL
                $debug_info .= "URL facturación obtenida: " . ($facturacion_url ?: 'VACÍA') . "\n";
                
                // Generar QR si existe URL
                if (!empty($facturacion_url)) {
                    // Intentar con diferentes APIs de QR
                    $qr_size = 150;
                    
                    // Opción 1: QR Server (más confiable)
                    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size={$qr_size}x{$qr_size}&data=" . urlencode($facturacion_url);
                    $debug_info .= "QR URL (QR Server): " . $qr_url . "\n";
                    
                    // Opción 2: QuickChart.io (alternativa)
                    $qr_url_alt = "https://quickchart.io/qr?text=" . urlencode($facturacion_url) . "&size={$qr_size}";
                    $debug_info .= "QR URL (Alternativa): " . $qr_url_alt . "\n";
                    
                    // Opción 3: QR code como texto (respaldo)
                    $qr_base64 = generarQRTexto($facturacion_url);
                    
                    // Verificar si la URL es accesible (timeout corto)
                    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                    $headers = @get_headers($qr_url, 0, $ctx);
                    if ($headers && strpos($headers[0], '200') !== false) {
                        $debug_info .= "✅ QR Server responde correctamente\n";
                    } else {
                        $debug_info .= "⚠️ QR Server no responde, se usará alternativa\n";
                        // Si la primera API falla, probar con la segunda
                        $headers_alt = @get_headers($qr_url_alt, 0, $ctx);
                        if ($headers_alt && strpos($headers_alt[0], '200') !== false) {
                            $qr_url = $qr_url_alt;
                            $debug_info .= "✅ API alternativa responde correctamente\n";
                        } else {
                            $debug_info .= "❌ Ninguna API responde, se usará QR de respaldo\n";
                            $qr_url = $qr_base64;
                        }
                    }
                } else {
                    $debug_info .= "No se generó QR porque la URL está vacía\n";
                }
                
                // Obtener información del usuario
                $usuario_id = $venta['usuario_id'] ?? 0;
                if ($usuario_id) {
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
                
                // Obtener información de la sucursal
                $sucursal_id = $venta['sucursal_id'] ?? 0;
                if ($sucursal_id) {
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
                
                // Obtener los productos de la venta
                $sql_productos = "SELECT vd.*, p.nombre, p.codigo, p.permite_fracciones, p.precio as precio_regular
                                 FROM venta_detalles vd 
                                 LEFT JOIN productos p ON vd.producto_id = p.id 
                                 WHERE vd.venta_id = ?";
                $stmt_productos = $conn->prepare($sql_productos);
                $stmt_productos->bind_param("i", $venta_id);
                $stmt_productos->execute();
                $result_productos = $stmt_productos->get_result();
                
                while ($producto = $result_productos->fetch_assoc()) {
                    $descuento_producto = $producto['descuento'] ?? 0;
                    $subtotal_original = $producto['precio_unitario'] * $producto['cantidad'];
                    $subtotal_con_descuento_producto = $producto['subtotal'];
                    
                    $productos[] = array(
                        'nombre' => $producto['nombre'],
                        'cantidad' => $producto['cantidad'],
                        'precio' => $producto['precio_unitario'],
                        'subtotal' => $subtotal_original,
                        'subtotal_con_descuento' => $subtotal_con_descuento_producto,
                        'descuento' => $descuento_producto,
                        'permite_fracciones' => $producto['permite_fracciones'] ?? 0
                    );
                }
                $stmt_productos->close();
            }
            $stmt_venta->close();
            
            $conn->close();
        }
    } catch (Exception $e) {
        $debug_info .= "ERROR: " . $e->getMessage() . "\n";
        error_log("Error al obtener datos para el ticket: " . $e->getMessage());
    }
}

// Si no se encontró la venta, mostrar error
if (empty($venta_data)) {
    die("Venta no encontrada");
}

// === NUEVO: MANEJO DE WHATSAPP ===
if ($es_whatsapp) {
    // Calcular total de descuentos por productos
    $total_descuento_productos = 0;
    foreach ($productos as $producto) {
        $total_descuento_productos += $producto['descuento'] ?? 0;
    }
    
    // Crear mensaje para WhatsApp
    $mensaje = "📋 *TICKET DE VENTA* 📋\n\n";
    $mensaje .= "*Empresa:* " . $empresa_nombre . "\n";
    $mensaje .= "*Sucursal:* " . $sucursal_nombre . "\n";
    $mensaje .= "*Venta:* " . $venta_data['codigo_venta'] . "\n";
    $mensaje .= "*Fecha:* " . date('d/m/Y H:i', strtotime($venta_data['fecha'])) . "\n";
    $mensaje .= "*Cliente:* " . $cliente_nombre . "\n";
    $mensaje .= "*Vendedor:* " . $usuario_nombre . "\n";
    $mensaje .= "*Total:* $" . number_format($venta_data['total'], 2) . "\n";
    $mensaje .= "*Método de pago:* " . strtoupper($venta_data['metodo_pago']) . "\n";
    
    if ($venta_data['metodo_pago'] === 'efectivo') {
        $mensaje .= "*Efectivo:* $" . number_format($venta_data['efectivo_recibido'], 2) . "\n";
        $mensaje .= "*Cambio:* $" . number_format($venta_data['cambio'], 2) . "\n";
    }
    
    if ($descuento_total > 0) {
        $mensaje .= "*Descuento:* -$" . number_format($descuento_total, 2) . "\n";
    }
    
    $mensaje .= "\n*PRODUCTOS:*\n";
    
    foreach ($productos as $index => $producto) {
        $num = $index + 1;
        $cantidad = $producto['permite_fracciones'] ? number_format($producto['cantidad'], 3) : intval($producto['cantidad']);
        $nombre = substr($producto['nombre'], 0, 20);
        $precio = $producto['subtotal_con_descuento'] ?? $producto['subtotal'];
        $mensaje .= "{$num}. {$cantidad} x {$nombre} = $" . number_format($precio, 2) . "\n";
        
        if (($producto['descuento'] ?? 0) > 0) {
            $mensaje .= "   (Desc: -$" . number_format($producto['descuento'], 2) . ")\n";
        }
    }
    
    if (!empty($facturacion_url)) {
        $mensaje .= "\n🔗 *FACTURACIÓN:*\n";
        $mensaje .= $facturacion_url . "\n";
    }
    
    $mensaje .= "\n🖨️ *Ver ticket completo:*\n";
    $protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $dominio = $_SERVER['HTTP_HOST'];
    $ruta = dirname($_SERVER['SCRIPT_NAME']);
    $ticket_url = $protocolo . $dominio . $ruta . "/reimprimir_ticket.php?venta_id=" . $venta_id;
    $mensaje .= $ticket_url;
    
    // Redirigir a WhatsApp
    $whatsapp_url = "https://wa.me/?text=" . urlencode($mensaje);
    header("Location: " . $whatsapp_url);
    exit();
}
// === FIN MANEJO DE WHATSAPP ===

// Headers para mejor compatibilidad
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reimprimir Ticket - <?php echo htmlspecialchars($venta_data['codigo_venta']); ?></title>
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

            * {
                color: #000000 !important;
                background: transparent !important;
                font-weight: bold !important;
            }

            .texto-descuento {
                color: #000000 !important;
            }
            
            .qr-section-premium {
                page-break-inside: avoid;
            }
        }

        /* ESTILOS PARA DEPURACIÓN */
        .debug-info {
            display: block;
            margin: 20px 0;
            padding: 10px;
            background: #f8f9fa;
            border: 2px solid #dc3545;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            color: #333;
            font-weight: normal;
            width: 100%;
            max-width: 300px;
        }
        
        .debug-info * {
            font-weight: normal !important;
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

        /* ESTILO PARA DESCUENTO */
        .texto-descuento {
            color: #ff0000 !important;
            font-weight: bold;
        }

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

        .contenedor-precios {
            font-size: 9px !important;
            line-height: 1.2 !important;
            margin-top: 1px;
        }
        
        /* ESTILOS PARA LA SECCIÓN QR */
        .qr-section-premium {
            text-align: center;
            margin: 8px 0 5px 0;
            padding: 5px 2px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        
        .qr-text {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 4px;
            letter-spacing: 0.5px;
        }
        
        .qr-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .qr-code {
            width: 40mm;
            height: 40mm;
            max-width: 40mm;
            max-height: 40mm;
            margin: 3px auto;
            border: 1px solid #000;
            padding: 2px;
            background: white;
            object-fit: contain;
        }
        
        .facturacion-nota {
            font-size: 8px;
            font-weight: bold;
            margin: 2px 0;
            color: #333;
        }
        
        .qr-link {
            font-size: 6px;
            font-weight: bold;
            word-break: break-all;
            max-width: 45mm;
            margin: 2px auto;
            color: #0066cc;
            text-decoration: none;
            font-family: 'Courier New', monospace;
        }
        
        @media print {
            .qr-link {
                color: #000000 !important;
            }
            
            .facturacion-nota {
                color: #000000 !important;
            }
        }
    </style>
</head>

<body>
    <!-- Mensaje de impresión automática -->
    <div class="auto-print-message no-print">
        🖨️ Reimprimiendo ticket...
        <div class="spinner"></div>
    </div>

    <div class="ticket">
        <!-- Logo de la empresa -->
        <?php if (!empty($empresa_logo)): ?>
            <div class="logo-container seccion">
                <?php
                $logo_path = '';
                if (file_exists($empresa_logo)) {
                    $logo_path = $empresa_logo;
                } elseif (file_exists('../' . $empresa_logo)) {
                    $logo_path = '../' . $empresa_logo;
                } elseif (file_exists('../../' . $empresa_logo)) {
                    $logo_path = '../../' . $empresa_logo;
                }

                if (!empty($logo_path) && file_exists($logo_path)):
                    $extension = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
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
            <?php echo date('d/m/Y H:i:s', strtotime($venta_data['fecha'])); ?>
        </div>
        <div class="texto-centro negrita codigo-venta">
            VENTA: <?php echo htmlspecialchars($venta_data['codigo_venta']); ?>
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

        <!-- Lista de productos -->
        <?php
        $total_descuento_productos = 0;
        foreach ($productos as $producto):
            $tiene_descuento = isset($producto['descuento']) && $producto['descuento'] > 0;
            $descuento_producto = $producto['descuento'] ?? 0;
            $total_descuento_productos += $descuento_producto;
            $subtotal_original = $producto['subtotal'];
            $subtotal_con_descuento = isset($producto['subtotal_con_descuento']) ? $producto['subtotal_con_descuento'] : $subtotal_original;
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
                            <div class="descuento-producto">
                                -<?php echo number_format($porcentaje_descuento, 1); ?>% ( -$<?php echo number_format($descuento_producto, 2); ?> )
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="col-precio" style="vertical-align: top;">
                        <div class="contenedor-precios">
                            <?php if ($tiene_descuento): ?>
                                <div class="precio-original">
                                    $<?php echo number_format($subtotal_original, 2); ?>
                                </div>
                                <div class="precio-con-descuento">
                                    $<?php echo number_format($subtotal_con_descuento, 2); ?>
                                </div>
                            <?php else: ?>
                                $<?php echo number_format($subtotal_original, 2); ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>
        <?php endforeach; ?>

        <div class="doble-linea"></div>

        <!-- Totales -->
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
            <tr class="total-final negrita">
                <td class="texto-izquierda">TOTAL:</td>
                <td class="texto-derecha">$<?php echo number_format($venta_data['total'], 2); ?></td>
            </tr>
        </table>

        <div class="linea-punteada"></div>

        <!-- Información de pago -->
        <div class="info-empresa negrita seccion">
            PAGO: <?php echo strtoupper($venta_data['metodo_pago']); ?>
        </div>

        <?php if ($venta_data['metodo_pago'] === 'efectivo'): ?>
            <table class="seccion">
                <tr>
                    <td class="texto-izquierda">EFECTIVO:</td>
                    <td class="texto-derecha">$<?php echo number_format($venta_data['efectivo_recibido'], 2); ?></td>
                </tr>
                <tr class="negrita">
                    <td class="texto-izquierda">CAMBIO:</td>
                    <td class="texto-derecha">$<?php echo number_format($venta_data['cambio'], 2); ?></td>
                </tr>
            </table>
        <?php endif; ?>

        <div class="linea-divisoria"></div>
        
        <!-- SECCIÓN QR PARA FACTURACIÓN -->
        <?php if (!empty($facturacion_url) && !empty($qr_url)): ?>
        <div class="qr-section-premium">
            <div class="qr-text negrita">
                🌐 FACTURACIÓN ELECTRÓNICA
            </div>
            <div class="qr-container">
                <img src="<?php echo $qr_url; ?>" alt="Código QR para facturación" class="qr-code" 
                     onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'150\' height=\'150\' viewBox=\'0 0 150 150\'%3E%3Crect width=\'150\' height=\'150\' fill=\'%23ffffff\'/%3E%3Ctext x=\'10\' y=\'40\' font-family=\'Arial\' font-size=\'10\' fill=\'%23000000\'%3EQR no disponible%3C/text%3E%3Ctext x=\'10\' y=\'60\' font-family=\'Arial\' font-size=\'8\' fill=\'%23000000\'%3EURL directa:%3C/text%3E%3Ctext x=\'10\' y=\'80\' font-family=\'Arial\' font-size=\'6\' fill=\'%23000000\'%3E' + this.getAttribute('data-url').substring(0, 30) + '%3C/text%3E%3C/svg%3E';"
                     data-url="<?php echo htmlspecialchars($facturacion_url); ?>">
                
                <div class="facturacion-nota">
                    Escanee para factura electrónica
                </div>
                <div class="qr-link">
                    <?php echo htmlspecialchars($facturacion_url); ?>
                </div>
            </div>
        </div>
        <div class="linea-divisoria"></div>
        <?php endif; ?>

        <!-- Pie del ticket -->
        <div class="texto-centro pie-ticket seccion">
            <div class="espacio-minimo"></div>
            <strong>¡Gracias por su compra!</strong><br>
            <div class="espacio-minimo"></div>
            Vuelva pronto<br>
            <div class="espacio-minimo"></div>
            * Ticket comprobante *<br>
            * Conserve para aclaraciones *<br>
            <div class="espacio-minimo"></div>
            <?php if ($descuento_total > 0): ?>
                <div class="texto-descuento" style="font-size: 8px;">
                    * Descuento aplicado: $<?php echo number_format($descuento_total, 2); ?> *
                </div>
                <div class="espacio-minimo"></div>
            <?php endif; ?>
            <?php if ($total_descuento_productos > 0): ?>
                <div style="font-size: 8px;">
                    * Descuento por productos: $<?php echo number_format($total_descuento_productos, 2); ?> *
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
        let impresionCompletada = false;
        let intentosImpresion = 0;
        const MAX_INTENTOS = 3;

        function imprimirAutomaticamente() {
            if (impresionCompletada || intentosImpresion >= MAX_INTENTOS) {
                return;
            }

            intentosImpresion++;
           

            try {
                window.print();

                setTimeout(() => {
                    if (!impresionCompletada) {
                        impresionCompletada = true;
                       
                        document.getElementById('estadoImpresion').innerHTML =
                            '✅ Impresión completada - Cerrando ventana...';
                        setTimeout(cerrarVentana, 1000);
                    }
                }, 1000);

            } catch (error) {
               
                document.getElementById('estadoImpresion').innerHTML =
                    '❌ Error en impresión: ' + error.message;
            }
        }

        function reimprimir() {
            impresionCompletada = false;
            intentosImpresion = 0;
            document.getElementById('estadoImpresion').innerHTML =
                '🖨️ Reimprimiendo...';
            setTimeout(imprimirAutomaticamente, 500);
        }

        function cerrarVentana() {
           
            window.close();

            setTimeout(() => {
                if (!window.closed) {
                    document.getElementById('estadoImpresion').innerHTML =
                        '⚠️ La ventana no se cerró automáticamente. Puede cerrarla manualmente.';
                }
            }, 2000);
        }

        function iniciarImpresionAutomatica() {
          
            setTimeout(imprimirAutomaticamente, 800);
            setTimeout(imprimirAutomaticamente, 1200);
            setTimeout(imprimirAutomaticamente, 2000);
        }

        window.addEventListener('load', iniciarImpresionAutomatica);
        document.addEventListener('DOMContentLoaded', iniciarImpresionAutomatica);
        window.addEventListener('focus', iniciarImpresionAutomatica);

        window.addEventListener('afterprint', function() {
          
            impresionCompletada = true;
            document.getElementById('estadoImpresion').innerHTML =
                '✅ Impresión completada - Cerrando ventana...';
            setTimeout(cerrarVentana, 1500);
        });

        setTimeout(function() {
            if (!impresionCompletada && !window.closed) {
               
                document.getElementById('estadoImpresion').innerHTML =
                    '⏰ Cierre automático - Si la impresión falló, use el botón Reimprimir';
                cerrarVentana();
            }
        }, 10000);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarVentana();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                reimprimir();
            }
            if (e.key === 'F5') {
                e.preventDefault();
                reimprimir();
            }
        });

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