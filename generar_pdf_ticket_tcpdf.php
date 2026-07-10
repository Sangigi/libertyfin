<?php
// generar_pdf_ticket_tcpdf.php
session_start();
// Configuración de error reporting para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Obtener ID de venta
$venta_id = isset($_GET['venta_id']) ? intval($_GET['venta_id']) : 0;
if ($venta_id <= 0) {
    die("ID de venta no válido");
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'] ?? '';

if (empty($dbname)) {
    die("Base de datos no especificada");
}

// Conexión a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener información de la empresa
$sql_empresa = "SELECT nombre_empresa, direccion, telefono, rfc, logo FROM sistema_config LIMIT 1";
$result_empresa = $conn->query($sql_empresa);
$empresa = $result_empresa->fetch_assoc();
$empresa_nombre = $empresa['nombre_empresa'] ?? 'Mi Empresa';
$empresa_direccion = $empresa['direccion'] ?? '';
$empresa_telefono = $empresa['telefono'] ?? '';
$empresa_rfc = $empresa['rfc'] ?? '';

// Obtener datos de la venta
$sql_venta = "SELECT v.*, c.nombre as cliente_nombre, u.nombre as usuario_nombre, s.nombre as sucursal_nombre 
              FROM ventas v 
              LEFT JOIN clientes c ON v.cliente_id = c.id 
              LEFT JOIN usuarios u ON v.usuario_id = u.id
              LEFT JOIN sucursales s ON v.sucursal_id = s.id
              WHERE v.id = ?";
$stmt = $conn->prepare($sql_venta);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();

if (!$venta) {
    die("Venta no encontrada");
}

// Obtener productos de la venta
$sql_productos = "SELECT vd.*, p.nombre as producto_nombre, p.permite_fracciones 
                  FROM venta_detalles vd 
                  LEFT JOIN productos p ON vd.producto_id = p.id 
                  WHERE vd.venta_id = ?";
$stmt = $conn->prepare($sql_productos);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();

// ============================================
// CONFIGURACIÓN DE TCPDF
// ============================================

// Verificar si TCPDF está instalado
$tcpdf_paths = [
    'tcpdf/tcpdf.php',
    '../tcpdf/tcpdf.php',
    '../../tcpdf/tcpdf.php',
    'vendor/tecnickcom/tcpdf/tcpdf.php',
    '../vendor/tecnickcom/tcpdf/tcpdf.php'
];

$tcpdf_found = false;
foreach ($tcpdf_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $tcpdf_found = true;
        break;
    }
}

if (!$tcpdf_found) {
    // Si no hay TCPDF, mostrar el ticket en HTML directamente
    header('Content-Type: text/html; charset=utf-8');
    echo generarTicketHTML($venta, $productos, $empresa_nombre, $empresa_rfc, $empresa_direccion, $empresa_telefono);
    exit();
}

// Crear clase personalizada de TCPDF
class MYPDF extends TCPDF {
    public function Header() {
        // Sin header para ticket
    }
    
    public function Footer() {
        // Sin footer para ticket
    }
}

// Crear PDF en tamaño ticket (58mm de ancho)
$pdf = new MYPDF('P', 'mm', array(58, 'auto'), true, 'UTF-8', false);
$pdf->SetAutoPageBreak(TRUE, 5);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 9);
$pdf->SetMargins(3, 3, 3);

// Generar HTML del ticket
$html = generarTicketHTML($venta, $productos, $empresa_nombre, $empresa_rfc, $empresa_direccion, $empresa_telefono);

// Escribir HTML en el PDF
$pdf->writeHTML($html, true, false, true, false, '');

// Salida del PDF
$pdf->Output('ticket_' . $venta['codigo_venta'] . '.pdf', 'I');

// ============================================
// FUNCIÓN PARA GENERAR HTML DEL TICKET
// ============================================
function generarTicketHTML($venta, $productos, $empresa_nombre, $empresa_rfc, $empresa_direccion, $empresa_telefono) {
    $subtotal_sin_descuento = $venta['subtotal'] ?? 0;
    $descuento_total = $venta['descuento'] ?? 0;
    
    $html = '
    <style>
        body { 
            font-family: helvetica, sans-serif; 
            font-size: 9pt; 
            line-height: 1.3;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .bold { font-weight: bold; }
        .line-simple { border-bottom: 1px solid #000000; margin: 4px 0; }
        .line-dashed { border-bottom: 1px dashed #000000; margin: 4px 0; }
        .line-double { border-bottom: 2px solid #000000; margin: 5px 0; }
        .producto-item { margin: 3px 0; }
        .total-text { font-size: 11pt; font-weight: bold; }
        .descuento-text { color: #ff0000; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        .col-cant { width: 20%; }
        .col-desc { width: 50%; }
        .col-importe { width: 30%; text-align: right; }
        .espacio { margin-top: 5px; }
        .qr-code { text-align: center; margin: 5px 0; }
        .qr-img { width: 40mm; height: 40mm; }
    </style>
    ';
    
    // Encabezado empresa
    $html .= '<div class="text-center bold">';
    $html .= htmlspecialchars($empresa_nombre) . '<br>';
    if (!empty($empresa_rfc)) $html .= 'RFC: ' . htmlspecialchars($empresa_rfc) . '<br>';
    if (!empty($empresa_direccion)) $html .= htmlspecialchars(substr($empresa_direccion, 0, 35)) . '<br>';
    if (!empty($empresa_telefono)) $html .= 'Tel: ' . htmlspecialchars($empresa_telefono) . '<br>';
    $html .= '</div>';
    $html .= '<div class="line-simple"></div>';
    
    // Sucursal
    if (!empty($venta['sucursal_nombre'])) {
        $html .= '<div class="text-center bold">' . htmlspecialchars($venta['sucursal_nombre']) . '</div>';
        $html .= '<div class="line-dashed"></div>';
    }
    
    // Fecha y número de venta
    $html .= '<div class="text-center">';
    $html .= date('d/m/Y H:i:s', strtotime($venta['fecha'])) . '<br>';
    $html .= '<span class="bold">VENTA: ' . htmlspecialchars($venta['codigo_venta']) . '</span>';
    $html .= '</div>';
    $html .= '<div class="line-dashed"></div>';
    
    // Cliente y vendedor
    $html .= '<div>';
    $html .= '<span class="bold">CLIENTE:</span> ' . htmlspecialchars(substr($venta['cliente_nombre'] ?? 'Cliente General', 0, 25)) . '<br>';
    $html .= '<span class="bold">VENDEDOR:</span> ' . htmlspecialchars(substr($venta['usuario_nombre'] ?? 'Usuario', 0, 20));
    $html .= '</div>';
    $html .= '<div class="line-dashed"></div>';
    
    // Tabla de productos
    $html .= '<table>';
    $html .= '<tr>';
    $html .= '<td class="col-cant bold">CANT</td>';
    $html .= '<td class="col-desc bold">DESCRIPCION</td>';
    $html .= '<td class="col-importe bold">IMPORTE</td>';
    $html .= '</tr>';
    
    foreach ($productos as $producto) {
        $cantidad = ($producto['permite_fracciones'] ?? 0) ? number_format($producto['cantidad'], 3) : intval($producto['cantidad']);
        $nombre = htmlspecialchars(substr($producto['producto_nombre'], 0, 20));
        $importe = number_format($producto['subtotal'], 2);
        
        $html .= '<tr>';
        $html .= '<td class="col-cant">' . $cantidad . '</td>';
        $html .= '<td class="col-desc">' . $nombre . '</td>';
        $html .= '<td class="col-importe">$' . $importe . '</td>';
        $html .= '</tr>';
        
        // Mostrar descuento por producto si existe
        if (isset($producto['descuento']) && $producto['descuento'] > 0) {
            $html .= '<tr>';
            $html .= '<td colspan="2" class="descuento-text" style="padding-left: 25%;">↓ Descuento: -$' . number_format($producto['descuento'], 2) . '</td>';
            $html .= '<td class="col-importe"></td>';
            $html .= '</tr>';
        }
    }
    
    $html .= '</table>';
    $html .= '<div class="line-simple"></div>';
    
    // Totales
    $html .= '<table>';
    $html .= '<tr>';
    $html .= '<td class="text-left bold">Subtotal:</td>';
    $html .= '<td class="text-right">$' . number_format($subtotal_sin_descuento, 2) . '</td>';
    $html .= '</tr>';
    
    if ($descuento_total > 0) {
        $html .= '<tr class="descuento-text">';
        $html .= '<td class="text-left bold">Descuento:</td>';
        $html .= '<td class="text-right">-$' . number_format($descuento_total, 2) . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '<tr>';
    $html .= '<td class="text-left total-text bold">TOTAL:</td>';
    $html .= '<td class="text-right total-text">$' . number_format($venta['total'], 2) . '</td>';
    $html .= '</tr>';
    $html .= '</table>';
    
    $html .= '<div class="line-dashed"></div>';
    
    // Método de pago
    $html .= '<div>';
    $html .= '<span class="bold">PAGO:</span> ' . strtoupper($venta['metodo_pago']);
    if ($venta['metodo_pago'] === 'efectivo') {
        $html .= '<br><span class="bold">EFECTIVO:</span> $' . number_format($venta['efectivo_recibido'], 2);
        $html .= '<br><span class="bold">CAMBIO:</span> $' . number_format($venta['cambio'], 2);
    }
    $html .= '</div>';
    
    $html .= '<div class="line-simple"></div>';
    
    // QR de facturación si existe
    if (!empty($venta['urlfacturacion'])) {
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=" . urlencode($venta['urlfacturacion']);
        $html .= '<div class="qr-code">';
        $html .= '<span class="bold">🌐 FACTURACIÓN ELECTRÓNICA</span><br>';
        $html .= '<img src="' . $qr_url . '" class="qr-img" alt="QR para facturar"><br>';
        $html .= '<span style="font-size: 7pt;">Escanee para facturar</span>';
        $html .= '</div>';
        $html .= '<div class="line-simple"></div>';
    }
    
    // Pie del ticket
    $html .= '<div class="text-center">';
    $html .= '<span class="bold">¡Gracias por su compra!</span><br>';
    $html .= 'Vuelva pronto<br>';
    $html .= '<div class="espacio"></div>';
    $html .= '* Ticket comprobante *<br>';
    $html .= '* Conserve para aclaraciones *';
    if ($descuento_total > 0) {
        $html .= '<br><span class="descuento-text">* Descuento aplicado: $' . number_format($descuento_total, 2) . ' *</span>';
    }
    $html .= '</div>';
    
    return $html;
}
?>