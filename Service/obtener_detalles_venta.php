<?php
// obtener_detalles_venta.php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$venta_id = $_GET['venta_id'] ?? 0;
if (!$venta_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID de venta no proporcionado']);
    exit;
}

header('Content-Type: application/json');

try {
    $conn = getEmpresaDBConnection($_SESSION['empresa_db']);
    if (!$conn) {
        throw new Exception('No se pudo conectar a la base de datos.');
    }

    // === 1. Detalles de la venta ===
    $sql = "SELECT vd.*, p.nombre as producto_nombre
            FROM venta_detalles vd
            JOIN productos p ON vd.producto_id = p.id
            WHERE vd.venta_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$venta_id]);
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // === 2. Datos del cliente (con TODOS los campos fiscales) ===
    $sql_cliente = "SELECT 
                        c.nombre,
                        c.razon_social,
                        c.rfc,
                        c.email,
                        c.telefono,
                        c.direccion,
                        c.calle_numero,
                        c.numero_interior,
                        c.colonia,
                        c.delegacion_municipio,
                        c.ciudad,
                        c.estado_direccion,
                        c.pais,
                        c.codigo_postal,
                        c.regimen_fiscal,
                        c.uso_cfdi
                    FROM ventas v
                    LEFT JOIN clientes c ON v.cliente_id = c.id
                    WHERE v.id = ?
                    LIMIT 1";
    $stmt_cliente = $conn->prepare($sql_cliente);
    $stmt_cliente->execute([$venta_id]);
    $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

    // Solo devolvemos cliente si tiene RFC o email (si no, es Cliente General)
    $cliente_data = null;
    if ($cliente && (!empty($cliente['rfc']) || !empty($cliente['email']))) {
        // Preferimos razón social sobre nombre si existe
        $nombre_legal = !empty($cliente['razon_social'])
            ? $cliente['razon_social']
            : ($cliente['nombre'] ?? '');

        $cliente_data = [
            'nombre'              => $cliente['nombre']               ?? '',
            'razon_social'        => $cliente['razon_social']         ?? '',
            'nombre_legal'        => $nombre_legal,
            'rfc'                 => $cliente['rfc']                  ?? '',
            'email'               => $cliente['email']                ?? '',
            'telefono'            => $cliente['telefono']             ?? '',
            'direccion'           => $cliente['direccion']            ?? '',
            'calle_numero'        => $cliente['calle_numero']         ?? '',
            'numero_interior'     => $cliente['numero_interior']      ?? '',
            'colonia'             => $cliente['colonia']              ?? '',
            'delegacion_municipio'=> $cliente['delegacion_municipio'] ?? '',
            'ciudad'              => $cliente['ciudad']               ?? '',
            'estado_direccion'    => $cliente['estado_direccion']     ?? '',
            'pais'                => $cliente['pais']                 ?? 'México',
            'codigo_postal'       => $cliente['codigo_postal']        ?? '',
            'regimen_fiscal'      => $cliente['regimen_fiscal']       ?? '',
            'uso_cfdi'            => $cliente['uso_cfdi']             ?? '',
        ];
    }

    echo json_encode([
        'success'  => true,
        'detalles' => $detalles,
        'cliente'  => $cliente_data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}