<?php
// generar_pdf_referencia.php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

// --- Parámetros ---
$folio      = $_GET['folio'] ?? null;
$referencia = $_GET['referencia'] ?? null;

if (!$folio && !$referencia) {
    die('Error: No se proporcionó folio ni referencia.');
}

// --- Conexión BD ---
$conn = getDBConnection();
$stmt = $conn->prepare("
    SELECT * FROM referencias_pago
    WHERE folio_cct = :folio OR reference_cct = :referencia
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([
    ':folio'      => $folio,
    ':referencia' => $referencia,
]);
$pago = $stmt->fetch(PDO::FETCH_ASSOC);
$conn = null;

if (!$pago) {
    die('Error: Referencia no encontrada.');
}

// --- Datos ---
$nombreCliente   = strtoupper($pago['customer_name'] ?: 'CLIENTE');
$emailCliente    = $pago['customer_email'] ?: 'cliente@correo.com';
$monto           = number_format((float)$pago['monto'], 2);
$montoLetra      = convertirNumeroALetras($pago['monto']);
$referenciaPago  = $pago['reference_cct'];
$folioPago       = $pago['folio_cct'] ?: $pago['reference_cct'];
$fechaEmision    = date('d/m/Y', strtotime($pago['fecha_creacion']));
$concepto        = $pago['descripcion'];

// Código de barras
$barcodeUrl    = $pago['barcode_url'] ?? '';
$barcodeBase64 = '';
if (!empty($pago['json_respuesta'])) {
    $json = json_decode($pago['json_respuesta'], true);
    $barcodeBase64 = $json['barcode'] ?? ($json['barcodeBase64'] ?? '');
}

// --- PDF ---
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('LibertyFin');
$pdf->SetAuthor('Club Pago');
$pdf->SetTitle('Formato de Pago - ' . $folioPago);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(false, 10);
$pdf->AddPage();

// =========================================================
// ENCABEZADO
// =========================================================

// Logo Club Pago (si existe)
$logoClubPago = __DIR__ . '/images/logo_club_pago.png';
if (file_exists($logoClubPago)) {
    $pdf->Image($logoClubPago, 150, 8, 45, 0, 'PNG', '', '', true, 300);
}

// Línea superior
$pdf->SetDrawColor(0, 51, 153);
$pdf->SetLineWidth(0.8);
$pdf->Line(12, 25, 198, 25);

// Emisor (izquierda)
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetXY(12, 30);
$pdf->Cell(70, 6, 'Emisor: LibertyFin', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(12);
$pdf->Cell(70, 5, 'PRUEBAS', 0, 1, 'L');

// Título (derecha)
$pdf->SetFont('helvetica', 'B', 18);
$pdf->SetXY(85, 28);
$pdf->Cell(110, 8, 'Formato de Pago', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetX(85);
$pdf->Cell(110, 5, 'Información del cliente', 0, 1, 'C');

// --- Datos del cliente ---
$pdf->SetXY(85, 45);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(28, 5, 'Nombre:', 0, 0, 'R');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(82, 5, $nombreCliente, 0, 1, 'L');

$pdf->SetX(85);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(28, 5, 'Correo Elect.:', 0, 0, 'R');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(82, 5, $emailCliente, 0, 1, 'L');

// =========================================================
// CAJA CONCEPTO / FECHA
// =========================================================
$pdf->SetFillColor(245, 245, 245);
$pdf->Rect(12, 62, 186, 12, 'F');
$pdf->SetXY(15, 64);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(25, 5, 'Concepto:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(60, 5, $concepto, 0, 0, 'L');
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(35, 5, 'Fecha Emisión:', 0, 0, 'R');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(50, 5, $fechaEmision, 0, 1, 'L');

// =========================================================
// REFERENCIA + CÓDIGO DE BARRAS
// =========================================================
$pdf->SetXY(12, 80);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(120, 5, 'Escanea esta Referencia para pagar en CADENA', 0, 1, 'L');

$pdf->SetFillColor(240, 240, 240);
$pdf->Rect(12, 86, 120, 24, 'F');

// Intentar con URL primero
$barcodeMostrado = false;
if (!empty($barcodeUrl)) {
    $imgBin = @file_get_contents($barcodeUrl);
    if ($imgBin) {
        $tmpFile = sys_get_temp_dir() . '/bc_' . $folioPago . '.png';
        file_put_contents($tmpFile, $imgBin);
        $pdf->Image($tmpFile, 14, 88, 116, 20, 'PNG');
        @unlink($tmpFile);
        $barcodeMostrado = true;
    }
}

// Intentar con base64
if (!$barcodeMostrado && !empty($barcodeBase64) && strpos($barcodeBase64, 'data:image') === 0) {
    $imgData = explode(',', $barcodeBase64);
    $imgBin  = base64_decode($imgData[1]);
    $tmpFile = sys_get_temp_dir() . '/bc_' . $folioPago . '.png';
    file_put_contents($tmpFile, $imgBin);
    $pdf->Image($tmpFile, 14, 88, 116, 20, 'PNG');
    @unlink($tmpFile);
    $barcodeMostrado = true;
}

// Fallback: generar con TCPDF
if (!$barcodeMostrado) {
    $style = [
        'position' => '', 'align' => 'C', 'stretch' => false,
        'fitwidth' => true, 'cellfitalign' => '', 'border' => false,
        'hpadding' => 'auto', 'vpadding' => 'auto',
        'fgcolor' => [0,0,0], 'bgcolor' => false,
        'text' => true, 'font' => 'helvetica', 'fontsize' => 8,
        'stretchtext' => 4,
    ];
    $pdf->write1DBarcode($referenciaPago, 'C128', 14, 87, 116, 20, 0.4, $style, 'N');
}

// =========================================================
// TOTAL A PAGAR (derecha)
// =========================================================
$pdf->SetXY(135, 80);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(60, 6, 'Total a pagar', 0, 1, 'R');

$pdf->SetXY(135, 88);
$pdf->SetFont('helvetica', 'B', 22);
$pdf->Cell(60, 12, '$' . $monto, 0, 1, 'R');

$pdf->SetXY(135, 102);
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(60, 5, '(MXN) MONEDA NACIONAL', 0, 1, 'R');

$pdf->SetXY(135, 107);
$pdf->SetFont('helvetica', '', 7);
$pdf->MultiCell(60, 4, 'SON: ' . $montoLetra, 0, 'R', false, 1, 135, 107);

// =========================================================
// INSTRUCCIONES PARA EL USUARIO (PARTE 1 - ANTES DE LOGOS)
// =========================================================
$pdf->SetXY(12, 118);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Instrucciones para realizar tu pago en cadenas', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(186, 4.5,
    "• Imprime este formato y acude con él a cualquier sucursal de",
    0, 'L', false, 1, 12, 125);

// =========================================================
// LOGOS DE TIENDAS  (2 filas x 6 logos = 12)
// =========================================================
$logosTiendas = [
    // --- Fila 1 ---
    ['img' => 'logo-walmart.png',             'nombre' => 'Walmart'],
    ['img' => 'logo-bodega-aurrera.png',      'nombre' => 'Bodega Aurrera'],
    ['img' => 'logo-sams-club.jpg',           'nombre' => "Sam's Club"],
    ['img' => 'logo-soriana.png',             'nombre' => 'Soriana'],
    ['img' => 'logo-city-club.png',           'nombre' => 'City Club'],
    ['img' => 'logo-suburbia.png',            'nombre' => 'Suburbia'],
    // --- Fila 2 ---
    ['img' => 'logo-seven.png',               'nombre' => '7-Eleven'],
    ['img' => 'logo-extra.png',               'nombre' => 'extra'],
    ['img' => 'logo-circle_k.png',            'nombre' => 'Circle K'],
    ['img' => 'farmacias_ahorro.jpg',         'nombre' => 'Farmacias del Ahorro'],
    ['img' => 'logo-farmacias-benavides.png', 'nombre' => 'Farmacias Benavides'],
    ['img' => 'logo-abarrotes-mty.png',       'nombre' => 'Abarrotes Monterrey'],
];

$logosPorFila = 6;
$marginX      = 12;
$anchoTotal   = 186;                              // 210 - 12 - 12
$anchoCelda   = $anchoTotal / $logosPorFila;      // ≈ 31 mm
$altoCelda    = 8;                                // mm
$gapY         = 3;
$yInicial     = 134;                              // <-- AJUSTADO: Antes estaba en 148

// NOTA: Se eliminó el fondo verde para que los logos se vean sobre el fondo blanco/transparente del PDF.

foreach ($logosTiendas as $i => $lt) {
    $fila = intval($i / $logosPorFila);
    $col  = $i % $logosPorFila;

    $x = $marginX + ($col * $anchoCelda);
    $y = $yInicial + ($fila * ($altoCelda + $gapY));

    $ruta = __DIR__ . '/images/' . $lt['img'];

    if (file_exists($ruta)) {
        $pdf->Image(
            $ruta,
            $x + 1,                    // padding izq
            $y,
            $anchoCelda - 2,           // padding der
            $altoCelda,
            '',                        // tipo (autodetecta)
            '',                        // link
            '',                        // align
            true,                      // resize manteniendo aspecto
            300,                       // dpi
            '',                        // palign
            false,                     // ismask
            false,                     // imgmask
            0,                         // border
            'CM'                       // fitbox: Center + Middle
        );
    } else {
        // Fallback: recuadro con el nombre
        $pdf->SetXY($x + 1, $y);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->Cell($anchoCelda - 2, $altoCelda, $lt['nombre'], 1, 0, 'C');
    }
}

// =========================================================
// INSTRUCCIONES PARA EL USUARIO (PARTE 2 - DESPUÉS DE LOGOS)
// =========================================================
$pdf->SetXY(12, 160); // <-- AJUSTADO: Antes estaba en 170
$pdf->SetFont('helvetica', '', 8.5);
$pdf->MultiCell(186, 4.5,
    "• Solicita hacer tu pago por CLUBPAGO y entrega este formato al cajero.\n" .
    "• La Cadena donde decidas hacer tu pago, podrá cobrar una comisión por recibir tu pago y tener un límite de monto máximo, si tu pago es mayor a este, deberás hacer 2 pagos.\n" .
    "• Realiza tu pago en efectivo. La Cadena te entregará un ticket como comprobante de tu pago, consérvalo para cualquier aclaración.",
    0, 'L', false, 1, 12, 160);

// =========================================================
// INSTRUCCIONES PARA EL CAJERO (Ajustadas hacia arriba)
// =========================================================
$pdf->SetXY(12, 178); // <-- AJUSTADO: Antes estaba en 185
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
    0, 'L', false, 1, 12, 185); // <-- AJUSTADO: Antes estaba en 192

// =========================================================
// PIE DE PÁGINA (Ajustado hacia arriba)
// =========================================================
$pdf->SetXY(12, 215); // <-- AJUSTADO: Antes estaba en 220
$pdf->SetFont('helvetica', '', 8);
$pdf->Cell(0, 4, 'Si tienes dudas sobre tu pago, comunícate con CLUB PAGO al 871-133-9375 o al correo: soporte@clubpago.mx', 0, 1, 'L');
$pdf->SetX(12);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(0, 4, 'Soporte: 871-133-9375 | ventas@grupoideas.com.mx', 0, 1, 'L');

// Logo Paga y Gana
$logoPaga = __DIR__ . '/images/logo_paga_y_gana.png';
if (file_exists($logoPaga)) {
    $pdf->Image($logoPaga, 150, 215, 45, 0, 'PNG', '', '', true, 300); // <-- AJUSTADO: Antes estaba en 220
}

// Código de barras inferior (referencia pequeña)
$styleFooter = [
    'position' => '', 'align' => 'C', 'stretch' => false,
    'fitwidth' => true, 'border' => false,
    'hpadding' => 'auto', 'vpadding' => 'auto',
    'fgcolor' => [0,0,0], 'bgcolor' => false,
    'text' => false, 'font' => 'helvetica', 'fontsize' => 6,
];
$pdf->write1DBarcode($referenciaPago, 'C128', 12, 240, 80, 10, 0.3, $styleFooter, 'N'); // <-- AJUSTADO: Antes estaba en 245

// Barra azul inferior
$pdf->SetFillColor(0, 51, 153);
$pdf->Rect(0, 285, 210, 12, 'F');
$pdf->SetXY(0, 288);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(0, 6, 'Soporte ClubPago: 871-133-9375 | ventas@grupoideas.com.mx', 0, 1, 'C');

// Salida
$pdf->Output('Formato_Pago_' . $folioPago . '.pdf', 'I');
exit;

// =========================================================
// Auxiliares
// =========================================================
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