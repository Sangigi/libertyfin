<?php
session_start();
require_once('vendor/autoload.php');

use TCPDF;

// Verificar si hay una venta para generar PDF
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

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión");
    }
    
    // Obtener datos de la venta
    $sql_venta = "SELECT v.*, c.nombre as cliente_nombre, u.nombre as usuario_nombre 
                  FROM ventas v 
                  LEFT JOIN clientes c ON v.cliente_id = c.id 
                  LEFT JOIN usuarios u ON v.usuario_id = u.id 
                  WHERE v.id = ?";
    $stmt = $conn->prepare($sql_venta);
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $result_venta = $stmt->get_result();
    $venta = $result_venta->fetch_assoc();
    $stmt->close();
    
    if (!$venta) {
        throw new Exception("Venta no encontrada");
    }
    
    // Obtener productos de la venta
    $sql_productos = "SELECT vd.*, p.nombre, p.codigo, p.permite_fracciones, p.unidad_medida 
                      FROM venta_detalles vd 
                      JOIN productos p ON vd.producto_id = p.id 
                      WHERE vd.venta_id = ?";
    $stmt = $conn->prepare($sql_productos);
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $result_productos = $stmt->get_result();
    $productos = $result_productos->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Obtener información de la empresa
    $sql_empresa = "SELECT nombre_empresa, direccion, telefono, rfc, logo FROM sistema_config LIMIT 1";
    $result_empresa = $conn->query($sql_empresa);
    $empresa = $result_empresa->fetch_assoc();
    
    $conn->close();
    
    // Crear PDF - TAMAÑO TICKET 80mm
    $pdf = new TCPDF('P', 'mm', array(80, 200), true, 'UTF-8', false);
    
    // Configuración del PDF
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor($empresa['nombre_empresa'] ?? 'Sistema');
    $pdf->SetTitle('Ticket de Venta');
    $pdf->SetSubject('Ticket');
    
    // Eliminar cabeceras y pies de página
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Configurar márgenes
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(true, 5);
    
    // Agregar página
    $pdf->AddPage();
    
    // Configurar fuente
    $pdf->SetFont('courier', '', 9);
    
    // ========== CONTENIDO DEL TICKET ==========
    
    // Logo o nombre de la empresa
    $pdf->SetFont('courier', 'B', 12);
    $pdf->Cell(0, 6, $empresa['nombre_empresa'] ?? 'MI EMPRESA', 0, 1, 'C');
    
    $pdf->SetFont('courier', '', 8);
    if (!empty($empresa['direccion'])) {
        $pdf->Cell(0, 4, $empresa['direccion'], 0, 1, 'C');
    }
    if (!empty($empresa['telefono'])) {
        $pdf->Cell(0, 4, 'Tel: ' . $empresa['telefono'], 0, 1, 'C');
    }
    if (!empty($empresa['rfc'])) {
        $pdf->Cell(0, 4, 'RFC: ' . $empresa['rfc'], 0, 1, 'C');
    }
    
    // Línea separadora
    $pdf->Cell(0, 4, str_repeat('-', 48), 0, 1, 'C');
    
    // Folio y fecha
    $pdf->SetFont('courier', 'B', 9);
    $pdf->Cell(0, 5, 'TICKET DE VENTA', 0, 1, 'C');
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 4, 'Folio: ' . $venta['codigo_venta'], 0, 1, 'L');
    $pdf->Cell(0, 4, 'Fecha: ' . date('d/m/Y H:i', strtotime($venta['fecha_venta'] ?? 'now')), 0, 1, 'L');
    $pdf->Cell(0, 4, 'Cajero: ' . ($venta['usuario_nombre'] ?? 'Sistema'), 0, 1, 'L');
    $pdf->Cell(0, 4, 'Cliente: ' . ($venta['cliente_nombre'] ?? 'Público General'), 0, 1, 'L');
    
    // Línea separadora
    $pdf->Cell(0, 4, str_repeat('-', 48), 0, 1, 'C');
    
    // Encabezados de productos
    $pdf->SetFont('courier', 'B', 8);
    $pdf->Cell(10, 5, 'Cant', 0, 0, 'L');
    $pdf->Cell(30, 5, 'Producto', 0, 0, 'L');
    $pdf->Cell(15, 5, 'P.Unit', 0, 0, 'R');
    $pdf->Cell(15, 5, 'Total', 0, 1, 'R');
    
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 2, str_repeat('-', 48), 0, 1, 'C');
    
    // Productos
    $subtotal = 0;
    $descuento_total = 0;
    
    foreach ($productos as $producto) {
        // Formatear cantidad según el tipo de producto
        if ($producto['permite_fracciones'] == 1) {
            $cantidad = number_format($producto['cantidad'], 3);
            $unidad = $producto['unidad_medida'] ?? '';
        } else {
            $cantidad = intval($producto['cantidad']);
            $unidad = 'pz';
        }
        
        // Truncar nombre si es muy largo
        $nombre = $producto['nombre'];
        if (strlen($nombre) > 18) {
            $nombre = substr($nombre, 0, 16) . '..';
        }
        
        $precio = number_format($producto['precio_unitario'], 2);
        $total_producto = number_format($producto['total'], 2);
        
        $pdf->Cell(10, 4, $cantidad . ' ' . $unidad, 0, 0, 'L');
        $pdf->Cell(30, 4, $nombre, 0, 0, 'L');
        $pdf->Cell(15, 4, '$' . $precio, 0, 0, 'R');
        $pdf->Cell(15, 4, '$' . $total_producto, 0, 1, 'R');
        
        // Mostrar descuento si aplica
        if ($producto['descuento'] > 0) {
            $pdf->SetFont('courier', '', 7);
            $pdf->Cell(45, 3, '  Descuento: -$' . number_format($producto['descuento'], 2), 0, 1, 'L');
            $pdf->SetFont('courier', '', 8);
        }
        
        $subtotal += $producto['subtotal'];
        $descuento_total += $producto['descuento'];
    }
    
    // Línea separadora
    $pdf->Cell(0, 4, str_repeat('-', 48), 0, 1, 'C');
    
    // Totales
    $pdf->SetFont('courier', 'B', 9);
    $pdf->Cell(35, 6, 'SUBTOTAL:', 0, 0, 'R');
    $pdf->Cell(20, 6, '$' . number_format($subtotal, 2), 0, 1, 'R');
    
    if ($descuento_total > 0) {
        $pdf->Cell(35, 6, 'DESCUENTO:', 0, 0, 'R');
        $pdf->Cell(20, 6, '-$' . number_format($descuento_total, 2), 0, 1, 'R');
    }
    
    $pdf->Cell(35, 6, 'TOTAL:', 0, 0, 'R');
    $pdf->SetFont('courier', 'B', 11);
    $pdf->Cell(20, 6, '$' . number_format($venta['total'], 2), 0, 1, 'R');
    
    // Método de pago
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 4, '', 0, 1);
    $metodo_pago = '';
    switch($venta['metodo_pago']) {
        case 'efectivo': $metodo_pago = 'EFECTIVO'; break;
        case 'tarjeta': $metodo_pago = 'TARJETA'; break;
        case 'transferencia': $metodo_pago = 'TRANSFERENCIA'; break;
        default: $metodo_pago = strtoupper($venta['metodo_pago']);
    }
    $pdf->Cell(0, 4, 'Metodo de pago: ' . $metodo_pago, 0, 1, 'L');
    
    if ($venta['metodo_pago'] == 'efectivo' && $venta['efectivo_recibido'] > 0) {
        $pdf->Cell(0, 4, 'Efectivo: $' . number_format($venta['efectivo_recibido'], 2), 0, 1, 'L');
        $pdf->Cell(0, 4, 'Cambio: $' . number_format($venta['cambio'], 2), 0, 1, 'L');
    }
    
    // Línea separadora
    $pdf->Cell(0, 4, str_repeat('-', 48), 0, 1, 'C');
    
    // Mensaje de facturación si aplica
    if (!empty($venta['facturapi_receipt_id']) && !empty($venta['urlfacturacion'])) {
        $pdf->SetFont('courier', 'B', 8);
        $pdf->Cell(0, 4, 'FACTURACION DISPONIBLE:', 0, 1, 'C');
        $pdf->SetFont('courier', '', 7);
        $pdf->Cell(0, 3, $venta['urlfacturacion'], 0, 1, 'C');
        $pdf->Cell(0, 3, 'Folio CFDI: ' . $venta['facturapi_receipt_id'], 0, 1, 'C');
    }
    
    // Mensaje de agradecimiento
    $pdf->Cell(0, 4, str_repeat('-', 48), 0, 1, 'C');
    $pdf->SetFont('courier', 'B', 9);
    $pdf->Cell(0, 6, '¡GRACIAS POR SU COMPRA!', 0, 1, 'C');
    $pdf->SetFont('courier', '', 8);
    $pdf->Cell(0, 4, 'Vuelva pronto', 0, 1, 'C');
    
    // Salida del PDF
    $pdf->Output('ticket_' . $venta_id . '.pdf', 'I');
    
} catch (Exception $e) {
    error_log("Error generando PDF: " . $e->getMessage());
    die("Error al generar el PDF: " . $e->getMessage());
}
?>