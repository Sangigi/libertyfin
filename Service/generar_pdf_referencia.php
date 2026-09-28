<?php
// generar_pdf_referencia.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

use TCPDF;

// --- Parámetros ---
$folio      = $_GET['folio'] ?? null;
$referencia = $_GET['referencia'] ?? null;

if (!$folio && !$referencia) {
    die('Error: No se proporcionó folio ni referencia.');
}

// --- Conexión BD ---
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM pagos_referencias WHERE folio = :folio OR referencia = :referencia ORDER BY id DESC LIMIT 1");
$stmt->execute([':folio' => $folio, ':referencia' => $referencia]);
$pago = $stmt->fetch(PDO::FETCH_ASSOC);
$conn = null;

if (!$pago) {
    die('Error: Referencia no encontrada.');
}

// --- Datos ---
$nombreCliente   = strtoupper($pago['cliente_nombre'] ?: 'CLIENTE');
$emailCliente    = $pago['cliente_email'] ?: 'cliente@correo.com';
$monto           = number_format((float)$pago['monto'], 2);
$montoLetra      = convertirNumeroALetras($pago['monto']);
$referenciaPago  = $pago['referencia'];
$folioPago       = $pago['folio'];
$fechaEmision    = date('d/m/Y', strtotime($pago['created_at']));
$concepto        = $pago['descrpcion'];
$barcodeBase64   = $pago['barcode'];

// --- PDF ---
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('Libertyfin');
$pdf->SetAuthor('Club Pago');
$pdf->SetTitle('Formato de Pago - ' . $folioPago);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(false, 10);
$pdf->AddPage();

// Logo Club Pago
$logoClubPago = __DIR__ . '/images/logo_club_pago.png';
if (file_exists($logoClubPago)) {
    $pdf->Image($logoClubPago, 145, 8, 50, 0, 'PNG', '', '', true, 300);
}
$logoSmall = __DIR__ . '/images/logo_club_pago_small.png';
if (file_exists($logoSmall)) {
    $pdf->Image($logoSmall, 165, 22, 28, 0, 'PNG', '', '', true, 300);
}

// Línea superior
$pdf->SetDrawColor(0, 51, 153);
$pdf->SetLineWidth(0.8);
$pdf->Line(12, 30, 198, 30);

// Emisor
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY(12, 34);
$pdf->Cell(60, 6, 'Emisor: CLUB PAGO', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(12);
$pdf->Cell(60, 5, 'PRUEBAS', 0, 1, 'L');

// Título
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetXY(80, 34);
$pdf->Cell(110, 8, 'Formato de Pago', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(80);
$pdf->Cell(110, 5, 'Información del cliente', 0, 1, 'C');

// Datos cliente
$pdf->SetX(80);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(25, 5, 'Nombre:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(85, 5, $nombreCliente, 0, 1, 'L');

$pdf->SetX(80);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(25, 5, 'Correo Elect.:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(85, 5, $emailCliente, 0, 1, 'L');

// Caja concepto/fecha
$pdf->SetFillColor(245, 245, 245);
$pdf->Rect(12, 55, 186, 12, 'F');
$pdf->SetXY(15, 57);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(25, 5, 'Concepto:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(60, 5, $concepto, 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(35, 5, 'Fecha Emisión:', 0, 0, 'R');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 5, $fechaEmision, 0, 1, 'L');

// Referencia + código de barras
$pdf->SetXY(12, 72);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(120, 5, 'Escanea esta Referencia para pagar en CADENA', 0, 1, 'L');

$pdf->SetFillColor(240, 240, 240);
$pdf->Rect(12, 78, 120, 22, 'F');

if (!empty($barcodeBase64) && strpos($barcodeBase64, 'data:image') === 0) {
    $imgData = explode(',', $barcodeBase64);
    $imgBin  = base64_decode($imgData[1]);
    $tmpFile = sys_get_temp_dir() . '/bc_' . $folioPago . '.png';
    file_put_contents($tmpFile, $imgBin);
    $pdf->Image($tmpFile, 14, 80, 116, 18, 'PNG');
    @unlink($tmpFile);
} else {
    $style = [
        'position' => '', 'align' => 'C', 'stretch' => false,
        'fitwidth' => true, 'cellfitalign' => '', 'border' => false,
        'hpadding' => 'auto', 'vpadding' => 'auto',
        'fgcolor' => [0,0,0], 'bgcolor' => false,
        'text' => true, 'font' => 'helvetica', 'fontsize' => 8,
        'stretchtext' => 4,
    ];
    $pdf->write1DBarcode($referenciaPago, 'C128', 14, 79, 116, 18, 0.4, $style, 'N');
}

// Total
$pdf->SetXY(135, 72);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(60, 6, 'Total a pagar', 0, 1, 'R');
$pdf->SetXY(135, 80);
$pdf->SetFont('helvetica', 'B', 22);
$pdf->Cell(60, 12, '$' . $monto, 0, 1, 'R');
$pdf->SetXY(135, 94);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(60, 5, '(MXN) MONEDA NACIONAL', 0, 1, 'R');
$pdf->SetXY(135, 99);
$pdf->SetFont('helvetica', '', 7);
$pdf->Cell(60, 5, 'SON: ' . $montoLetra, 0, 1, 'R');

// Instrucciones usuario
$pdf->SetXY(12, 108);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Instrucciones para realizar tu pago en cadenas', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(186, 4.5,
    "• Imprime este formato y acude con él a la caducar sucursal de tu elección.\n" .
    "• Solicita hacer tu pago por CLUBPAGO y entrega este formato al cajero.\n" .
    "• La cadena donde decidas hacer tu pago podrá cobrar una comisión por recibir tu pago y tener un límite de monto máximo, si tu pago es mayor a este, deberás hacer 2 pagos.\n" .
    "• Realiza tu pago en efectivo. La Cadena te entregará un ticket como comprobante de tu pago, consérvalo para cualquier aclaración.",
    0, 'L', false, 1, 12, 115);

// Logos tiendas
$logosTiendas = [
    ['img' => 'logo_walmart.png',   'x' => 14,  'nombre' => 'Walmart'],
    ['img' => 'logo_soriana.png',   'x' => 40,  'nombre' => 'Soriana'],
    ['img' => 'logo_farmacias.png', 'x' => 66,  'nombre' => 'Farmacias'],
    ['img' => 'logo_7eleven.png',   'x' => 92,  'nombre' => '7-Eleven'],
    ['img' => 'logo_oxxo.png',      'x' => 118, 'nombre' => 'OXXO'],
    ['img' => 'logo_circle_k.png',  'x' => 144, 'nombre' => 'Circle K'],
    ['img' => 'logo_monterrey.png', 'x' => 170, 'nombre' => 'Monterrey'],
];
foreach ($logosTiendas as $lt) {
    $ruta = __DIR__ . '/images/' . $lt['img'];
    if (file_exists($ruta)) {
        $pdf->Image($ruta, $lt['x'], 140, 22, 8, '', '', '', true, 300);
    } else {
        $pdf->SetXY($lt['x'], 140);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell(22, 8, $lt['nombre'], 1, 0, 'C');
    }
}

// Instrucciones cajero
$pdf->SetXY(12, 155);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Instrucciones para el cajero', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(186, 4.5,
    "1) Entre al menú de Pago de Servicios.\n" .
    "2) Buscar y seleccionar CLUB PAGO.\n" .
    "3) Escanear el código de barras o teclear el número de referencia.\n" .
    "4) Capturar el monto a pagar.\n" .
    "5) Recibir del cliente el monto y comisión por pago.\n" .
    "6) Confirmar pago y entregar ticket al cliente.",
    0, 'L', false, 1, 12, 162);

// Pie de página
$pdf->SetXY(12, 195);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, 'Si lo prefieres, puedes hacer tu pago por:', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(0, 5, 'SPEI   Banco: STP', 0, 1, 'L');
$pdf->SetX(12);
$pdf->Cell(0, 5, 'CLABE: 646180227773853858', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 4, 'Si tienes dudas sobre tu pago, comunícate con CLUB PAGO al 871-133-9375 o al correo: soporte@clubpago.mx', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(0, 4, 'Soporte: 871-133-9375 | ventas@grupoideas.com.mx', 0, 1, 'L');

// Logo Paga y Gana
$logoPaga = __DIR__ . '/images/logo_paga_y_gana.png';
if (file_exists($logoPaga)) {
    $pdf->Image($logoPaga, 150, 195, 45, 0, 'PNG', '', '', true, 300);
}

// Barra azul inferior
$pdf->SetFillColor(0, 51, 153);
$pdf->Rect(0, 285, 210, 12, 'F');
$pdf->SetXY(0, 288);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(0, 6, 'Soporte ClubPago: 871-133-9375 | ventas@grupoideas.com.mx', 0, 1, 'C');

// Salida (I = mostrar en navegador, D = descargar)
$pdf->Output('Formato_Pago_' . $folioPago . '.pdf', 'I');
exit;

// Auxiliar: número a letras
function convertirNumeroALetras($numero) {
    $numero   = (float)$numero;
    $entero   = floor($numero);
    $centavos = round(($numero - $entero) * 100);

    $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
                 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS',
                 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE'];
    $decenas  = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA',
                 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
    $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
                 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    if ($entero == 0)          $letras = 'CERO';
    elseif ($entero == 100)    $letras = 'CIEN';
    elseif ($entero < 21)      $letras = $unidades[$entero];
    elseif ($entero < 100) {
        $dec = floor($entero / 10);
        $uni = $entero % 10;
        $letras = $decenas[$dec] . ($uni ? ' Y ' . $unidades[$uni] : '');
    } elseif ($entero < 1000) {
        $cen   = floor($entero / 100);
        $resto = $entero % 100;
        $letras = $centenas[$cen] . ($resto ? ' ' . convertirNumeroALetras($resto) : '');
    } elseif ($entero < 1000000) {
        $mil   = floor($entero / 1000);
        $resto = $entero % 1000;
        $letras = ($mil == 1 ? 'MIL' : convertirNumeroALetras($mil) . ' MIL') .
                  ($resto ? ' ' . convertirNumeroALetras($resto) : '');
    } else {
        $letras = (string)$entero;
    }

    return $letras . ' PESOS ' . str_pad($centavos, 2, '0', STR_PAD_LEFT) . '/100 M.N.';
}