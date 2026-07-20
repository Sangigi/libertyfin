<?php
// importar_productos.php
session_start();
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Limpiar cualquier output buffer previo
ob_clean();

// Configurar header para JSON
header('Content-Type: application/json; charset=utf-8');

// Verificar si el usuario está logueado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
    exit();
}

// Configuración de la base de datos
$servername = "libertyfin.com.mx";
$username = "juanc141_alexis";
$password = "Alexis1997";
$dbname = $_SESSION['empresa_db'];

$response = ['success' => false, 'message' => '', 'importados' => 0, 'errores' => []];

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }

    // Obtener el plan de la empresa desde la sesión
    $empresa_plan = $_SESSION['empresa_plan'] ?? 'prueba';
    
    // Verificar límite de productos
    $limite_info = verificarLimiteProductos($conn, $empresa_plan);
    
    if ($limite_info['alcanzado']) {
        throw new Exception("Has alcanzado el límite de productos para tu plan ({$limite_info['limite']} productos).");
    }

    // Procesar archivo subido
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error al subir el archivo. Código: " . ($_FILES['archivo']['error'] ?? 'No file'));
    }

    $archivo_tmp = $_FILES['archivo']['tmp_name'];
    $extension = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));

    // Validar extensión
    $extensiones_permitidas = ['csv', 'xls', 'xlsx'];
    if (!in_array($extension, $extensiones_permitidas)) {
        throw new Exception("Formato no permitido. Use: CSV, XLS o XLSX");
    }

    // Leer datos del archivo
    $datos = [];
    
    if ($extension === 'csv') {
        // Leer CSV
        if (($handle = fopen($archivo_tmp, "r")) !== FALSE) {
            // Detectar delimitador (coma o punto y coma)
            $primera_linea = fgets($handle);
            rewind($handle);
            
            $delimitador = (strpos($primera_linea, ';') !== false) ? ';' : ',';
            
            $headers = fgetcsv($handle, 1000, $delimitador); // Leer primera fila como encabezados
            
            // Limpiar encabezados (quitar BOM y espacios)
            $headers = array_map(function($h) {
                // Quitar BOM UTF-8 si existe
                $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
                return trim($h);
            }, $headers);
            
            while (($data = fgetcsv($handle, 1000, $delimitador)) !== FALSE) {
                if (count($data) == count($headers)) {
                    $fila = array_combine($headers, $data);
                    // Limpiar valores
                    foreach ($fila as $key => $value) {
                        $fila[$key] = trim($value);
                    }
                    // Filtrar filas vacías (que no tengan código y nombre)
                    if (!empty($fila['codigo']) && !empty($fila['nombre'])) {
                        $datos[] = $fila;
                    }
                }
            }
            fclose($handle);
        }
    } else {
        // Leer Excel usando PhpSpreadsheet
        try {
            $spreadsheet = IOFactory::load($archivo_tmp);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            if (count($rows) > 0) {
                $headers = array_shift($rows); // Primera fila como encabezados
                // Limpiar encabezados
                $headers = array_map('trim', $headers);
                
                foreach ($rows as $row) {
                    if (count($row) == count($headers)) {
                        $fila = array_combine($headers, $row);
                        // Limpiar valores
                        foreach ($fila as $key => $value) {
                            $fila[$key] = trim($value);
                        }
                        // Filtrar filas vacías (que tengan código y nombre)
                        if (!empty($fila['codigo']) && !empty($fila['nombre'])) {
                            $datos[] = $fila;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception("Error al leer el archivo Excel: " . $e->getMessage());
        }
    }

    if (empty($datos)) {
        throw new Exception("El archivo está vacío o no tiene datos válidos");
    }

    // Validar encabezados requeridos
    $campos_requeridos = ['codigo', 'nombre', 'precio', 'costo'];
    $primer_fila = $datos[0];
    
    foreach ($campos_requeridos as $campo) {
        if (!array_key_exists($campo, $primer_fila)) {
            throw new Exception("El archivo debe contener la columna '$campo'");
        }
    }

    // Obtener mapeo de categorías y proveedores
    $categorias = obtenerCategorias($conn);
    $proveedores = obtenerProveedores($conn);
    $sucursales = obtenerSucursales($conn);
    
    // Si no hay sucursales, crear una por defecto
    if (empty($sucursales)) {
        $sucursal_id = crearSucursalDefault($conn);
        $sucursales = [['id' => $sucursal_id, 'nombre' => 'Matriz']];
    }

    $importados = 0;
    $errores = [];
    $stock_minimo_global = obtenerStockMinimoGlobal($conn);

    // Procesar cada fila
    foreach ($datos as $index => $fila) {
        $fila_num = $index + 2; // +2 porque la fila 1 son encabezados
        
        try {
            // Validar datos requeridos
            if (empty($fila['codigo'])) {
                $errores[] = "Fila $fila_num: El código es requerido";
                continue;
            }
            
            if (empty($fila['nombre'])) {
                $errores[] = "Fila $fila_num: El nombre es requerido";
                continue;
            }

            // Verificar si el código ya existe
            if (codigoExiste($conn, $fila['codigo'])) {
                $errores[] = "Fila $fila_num: El código '{$fila['codigo']}' ya existe";
                continue;
            }

            // Procesar valores numéricos (limpiar comas y espacios)
            $precio = floatval(str_replace(',', '', preg_replace('/[^0-9.,-]/', '', $fila['precio'] ?? '0')));
            $costo = floatval(str_replace(',', '', preg_replace('/[^0-9.,-]/', '', $fila['costo'] ?? '0')));
            $subprecio = isset($fila['subprecio']) ? floatval(str_replace(',', '', preg_replace('/[^0-9.,-]/', '', $fila['subprecio']))) : $precio;
            $descuento = isset($fila['descuento']) ? floatval(str_replace(',', '', preg_replace('/[^0-9.,-]/', '', $fila['descuento']))) : 0;
            $stock = isset($fila['stock']) ? intval(preg_replace('/[^0-9-]/', '', $fila['stock'])) : 0;

            // Validar precios
            if ($precio <= 0) {
                $errores[] = "Fila $fila_num: El precio debe ser mayor a 0";
                continue;
            }
            
            if ($costo <= 0) {
                $errores[] = "Fila $fila_num: El costo debe ser mayor a 0";
                continue;
            }

            // Obtener o crear categoría
            $categoria_id = null;
            if (!empty($fila['categoria'])) {
                $categoria_nombre = trim($fila['categoria']);
                $categoria_key = strtolower(trim($categoria_nombre));
                if (isset($categorias[$categoria_key])) {
                    $categoria_id = $categorias[$categoria_key];
                } else {
                    // Crear nueva categoría
                    $categoria_id = crearCategoria($conn, $categoria_nombre);
                    $categorias[$categoria_key] = $categoria_id;
                }
            }

            // Obtener o crear proveedor
            $proveedor_id = null;
            if (!empty($fila['proveedor'])) {
                $proveedor_nombre = trim($fila['proveedor']);
                $proveedor_key = strtolower(trim($proveedor_nombre));
                if (isset($proveedores[$proveedor_key])) {
                    $proveedor_id = $proveedores[$proveedor_key];
                } else {
                    // Crear nuevo proveedor
                    $proveedor_id = crearProveedor($conn, $proveedor_nombre);
                    $proveedores[$proveedor_key] = $proveedor_id;
                }
            }

            // Unidad de medida
            $unidad_medida = isset($fila['unidad_medida']) ? strtolower(trim($fila['unidad_medida'])) : 'pieza';
            $unidades_validas = ['pieza', 'kilo', 'litro'];
            if (!in_array($unidad_medida, $unidades_validas)) {
                $unidad_medida = 'pieza';
            }

            // Permitir fracciones
            $permite_fracciones = 0;
            if (isset($fila['permite_fracciones'])) {
                $valor = strtolower(trim($fila['permite_fracciones']));
                $permite_fracciones = ($valor === 'si' || $valor === 'sí' || $valor === '1' || $valor === 'true' || $valor === 'yes') ? 1 : 0;
            } elseif ($unidad_medida === 'kilo' || $unidad_medida === 'litro') {
                $permite_fracciones = 1; // Por defecto para kilos y litros
            }

            // Peso
            $peso_kg = isset($fila['peso_kg']) ? floatval(str_replace(',', '', $fila['peso_kg'])) : 1.0;
            if ($peso_kg <= 0) $peso_kg = 1.0;

            // Fecha de caducidad
            $fecha_caducidad = null;
            if (!empty($fila['fecha_caducidad'])) {
                $fecha_str = trim($fila['fecha_caducidad']);
                // Intentar diferentes formatos de fecha
                $fecha = DateTime::createFromFormat('d/m/Y', $fecha_str);
                if (!$fecha) {
                    $fecha = DateTime::createFromFormat('Y-m-d', $fecha_str);
                }
                if (!$fecha) {
                    $fecha = DateTime::createFromFormat('d-m-Y', $fecha_str);
                }
                if ($fecha) {
                    $fecha_caducidad = $fecha->format('Y-m-d');
                }
            }

            // Descripción y marca (pueden ser null)
            $descripcion = !empty($fila['descripcion']) ? $fila['descripcion'] : null;
            $marca = !empty($fila['marca']) ? $fila['marca'] : null;

            // Iniciar transacción
            $conn->begin_transaction();

            // Insertar producto - CORREGIDO: usar variables correctas
            $sql = "INSERT INTO productos (
                codigo, nombre, descripcion, marca, precio, subprecio, costo, descuento,
                stock, stock_minimo, categoria_id, proveedor_id, unidad_medida, peso_kg,
                permite_fracciones, fecha_caducidad, activo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Error en preparación: " . $conn->error);
            }

            // CORREGIDO: usar $fila['nombre'] en lugar de $nombre
            $stmt->bind_param(
                "ssssddddiiissdss",
                $fila['codigo'],
                $fila['nombre'],        // <- ESTA ERA LA VARIABLE FALTANTE
                $descripcion,
                $marca,
                $precio,
                $subprecio,
                $costo,
                $descuento,
                $stock,
                $stock_minimo_global,
                $categoria_id,
                $proveedor_id,
                $unidad_medida,
                $peso_kg,
                $permite_fracciones,
                $fecha_caducidad
            );

            if (!$stmt->execute()) {
                throw new Exception("Error en BD: " . $stmt->error);
            }

            $producto_id = $conn->insert_id;
            $stmt->close();

            // Asignar a todas las sucursales activas
            $primera_sucursal = true;
            foreach ($sucursales as $sucursal) {
                $sucursal_id = $sucursal['id'];
                $stock_sucursal = ($primera_sucursal) ? $stock : 0;
                
                $sql_sucursal = "INSERT INTO producto_sucursal (producto_id, sucursal_id, stock, stock_minimo) 
                                VALUES (?, ?, ?, ?)";
                $stmt_sucursal = $conn->prepare($sql_sucursal);
                $stmt_sucursal->bind_param("iiii", $producto_id, $sucursal_id, $stock_sucursal, $stock_minimo_global);
                
                if (!$stmt_sucursal->execute()) {
                    throw new Exception("Error al asignar sucursal: " . $stmt_sucursal->error);
                }
                $stmt_sucursal->close();
                
                $primera_sucursal = false;
            }

            // Commit de la transacción
            $conn->commit();
            $importados++;

        } catch (Exception $e) {
            // Rollback en caso de error
            $conn->rollback();
            $errores[] = "Fila $fila_num: " . $e->getMessage();
        }
    }

    $conn->close();

    $response['success'] = true;
    $response['message'] = "Se importaron $importados productos correctamente";
    $response['importados'] = $importados;
    $response['errores'] = $errores;

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// Funciones auxiliares
function verificarLimiteProductos($conn, $plan) {
    $sql_count = "SELECT COUNT(*) as total FROM productos WHERE activo = 1";
    $result = $conn->query($sql_count);
    $row = $result->fetch_assoc();
    $total_productos = $row['total'];

    $limites = [
        'prueba' => 100,
        'basico' => 100,
        'emprendedor' => 500,
        'premium' => PHP_INT_MAX
    ];

    $limite = isset($limites[$plan]) ? $limites[$plan] : 100;

    return [
        'total' => $total_productos,
        'limite' => $limite,
        'alcanzado' => $total_productos >= $limite
    ];
}

function obtenerCategorias($conn) {
    $categorias = [];
    $sql = "SELECT id, LOWER(TRIM(nombre)) as nombre FROM categorias WHERE activo = 1";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $categorias[$row['nombre']] = $row['id'];
    }
    return $categorias;
}

function obtenerProveedores($conn) {
    $proveedores = [];
    $sql = "SELECT id, LOWER(TRIM(nombre)) as nombre FROM proveedores WHERE activo = 1";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $proveedores[$row['nombre']] = $row['id'];
    }
    return $proveedores;
}

function obtenerSucursales($conn) {
    $sucursales = [];
    $sql = "SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY 
            CASE WHEN LOWER(TRIM(nombre)) = 'matriz' THEN 0 ELSE 1 END, nombre";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $sucursales[] = $row;
    }
    return $sucursales;
}

function obtenerStockMinimoGlobal($conn) {
    $sql = "SELECT stock_minimo_global FROM sistema_config LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['stock_minimo_global'] ?? 5;
    }
    return 5;
}

function crearSucursalDefault($conn) {
    $sql = "INSERT INTO sucursales (nombre, activo) VALUES ('Matriz', 1)";
    $conn->query($sql);
    return $conn->insert_id;
}

function crearCategoria($conn, $nombre) {
    $sql = "INSERT INTO categorias (nombre, activo) VALUES (?, 1)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function crearProveedor($conn, $nombre) {
    $sql = "INSERT INTO proveedores (nombre, activo) VALUES (?, 1)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function codigoExiste($conn, $codigo) {
    $sql = "SELECT COUNT(*) as total FROM productos WHERE codigo = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['total'] > 0;
}

// Asegurarse de que no haya output antes del JSON
if (ob_get_length()) ob_clean();

// Devolver respuesta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();
?>