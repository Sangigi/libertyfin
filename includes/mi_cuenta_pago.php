<?php
/**
 * includes/mi_cuenta_pago.php
 *
 * Soporte para la sección "Mi cuenta" -> Datos fiscales, Datos para procesar
 * pagos reales (alta de comercio) y Documentos.
 *
 * Todo vive en la base de datos PROPIA de cada empresa (la misma a la que
 * apunta $_SESSION['empresa_db'] / getEmpresaDBConnection()), igual que
 * `sistema_config`: cada empresa ya tiene su propia base de datos, así que
 * no hace falta una tabla central con empresa_id — el aislamiento entre
 * negocios ya lo da la base de datos separada de cada uno.
 *
 * asegurar_tablas_pago() sigue el mismo patrón que ya usa configuracion.php
 * (SHOW COLUMNS / información de esquema + ALTER TABLE si falta una
 * columna), para que no haga falta correr ningún script SQL a mano: la
 * primera vez que un admin entra a "Mi cuenta" se crea/actualiza todo solo.
 */

// Tipos de documento exactos que se piden en la sección de Documentos.
define('MCP_TIPOS_DOCUMENTO', [
    'identificacion_frente'  => 'Identificación del dueño del negocio (frente)',
    'identificacion_reverso' => 'Identificación del dueño del negocio (reverso)',
    'estado_cuenta_bancario' => 'Portada del estado de cuenta bancario',
    'comprobante_domicilio'  => 'Comprobante de domicilio',
    'constancia_fiscal'      => 'Constancia de situación fiscal',
]);

define('MCP_UPLOADS_MIME_PERMITIDOS', [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'pdf'  => 'application/pdf',
]);

define('MCP_UPLOADS_MAX_BYTES', 10 * 1024 * 1024); // 10 MB

/**
 * Crea (si no existen) las tablas de datos de pago / documentos, y agrega a
 * `sistema_config` las columnas de datos fiscales que falten. Seguro de
 * llamar en cada carga de la página: todo usa IF NOT EXISTS o revisa antes
 * de alterar.
 */
function asegurar_tablas_pago(PDO $conn): void
{
    // ---- Columnas fiscales nuevas en sistema_config ----
    $columnas_actuales = [];
    $res = $conn->query("SHOW COLUMNS FROM sistema_config");
    if ($res) {
        foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columnas_actuales[] = $col['Field'];
        }
    }

    $columnas_nuevas = [
        'tipo_persona'         => "ALTER TABLE sistema_config ADD COLUMN tipo_persona VARCHAR(10) DEFAULT NULL COMMENT 'fisica o moral'",
        'razon_social'         => "ALTER TABLE sistema_config ADD COLUMN razon_social VARCHAR(200) DEFAULT NULL",
        'regimen_fiscal'       => "ALTER TABLE sistema_config ADD COLUMN regimen_fiscal VARCHAR(10) DEFAULT NULL",
        'cp_fiscal'            => "ALTER TABLE sistema_config ADD COLUMN cp_fiscal VARCHAR(5) DEFAULT NULL",
        'documentacion_estado' => "ALTER TABLE sistema_config ADD COLUMN documentacion_estado VARCHAR(20) NOT NULL DEFAULT 'sin_enviar'",
    ];

    foreach ($columnas_nuevas as $nombre => $sql) {
        if (!in_array($nombre, $columnas_actuales, true)) {
            try {
                $conn->exec($sql);
            } catch (PDOException $e) {
                error_log('mi_cuenta_pago: no se pudo agregar columna ' . $nombre . ': ' . $e->getMessage());
            }
        }
    }

    // ---- Tabla de datos bancarios / alta de comercio (una sola fila) ----
    $conn->exec("
        CREATE TABLE IF NOT EXISTS datos_pago_comercio (
            id                       INT PRIMARY KEY AUTO_INCREMENT,
            titular_nombre           VARCHAR(200) DEFAULT NULL COMMENT 'Como aparece en el estado de cuenta',
            nombre_comercio          VARCHAR(200) DEFAULT NULL COMMENT 'Nombre comercial / de sucursal',
            titular_correo           VARCHAR(160) DEFAULT NULL,
            giro                     VARCHAR(200) DEFAULT NULL,
            calle_numero             VARCHAR(200) DEFAULT NULL,
            numero_interior          VARCHAR(50)  DEFAULT NULL,
            colonia                  VARCHAR(150) DEFAULT NULL,
            delegacion_municipio     VARCHAR(150) DEFAULT NULL,
            ciudad                   VARCHAR(100) DEFAULT NULL,
            estado_direccion         VARCHAR(100) DEFAULT NULL,
            pais                     VARCHAR(100) DEFAULT 'México',
            telefono_oficina         VARCHAR(20)  DEFAULT NULL,
            telefono_celular         VARCHAR(20)  DEFAULT NULL,
            nombre_vendedor          VARCHAR(150) DEFAULT NULL,
            rep_legal_nombre         VARCHAR(200) DEFAULT NULL,
            rep_legal_escritura      VARCHAR(200) DEFAULT NULL,
            rep_legal_notaria_numero VARCHAR(50)  DEFAULT NULL,
            rep_legal_notario_nombre VARCHAR(200) DEFAULT NULL,
            rep_legal_ciudad         VARCHAR(100) DEFAULT NULL,
            empresa_escritura        VARCHAR(200) DEFAULT NULL COMMENT 'Solo persona moral',
            empresa_folio_rpc        VARCHAR(100) DEFAULT NULL,
            empresa_ciudad           VARCHAR(100) DEFAULT NULL,
            empresa_notario_nombre   VARCHAR(200) DEFAULT NULL,
            empresa_notaria_numero   VARCHAR(50)  DEFAULT NULL,
            id_tipo                  VARCHAR(50)  DEFAULT NULL,
            id_numero                VARCHAR(100) DEFAULT NULL,
            id_fecha_expedicion      DATE DEFAULT NULL,
            id_vigencia              DATE DEFAULT NULL,
            banco                    VARCHAR(100) DEFAULT NULL,
            plaza                    VARCHAR(100) DEFAULT NULL,
            sucursal_bancaria        VARCHAR(100) DEFAULT NULL,
            cuenta_cheques           VARCHAR(30)  DEFAULT NULL,
            cuenta_clabe             VARCHAR(18)  DEFAULT NULL COMMENT 'Dato sensible',
            clausulado_aceptado_en   DATETIME DEFAULT NULL,
            actualizado_en           DATETIME DEFAULT NULL,
            actualizado_por          INT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ---- Tabla de documentos subidos (una fila por tipo de documento) ----
    $conn->exec("
        CREATE TABLE IF NOT EXISTS documentos_comercio (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            tipo            VARCHAR(40) NOT NULL,
            ruta_archivo    VARCHAR(500) NOT NULL,
            nombre_original VARCHAR(255) DEFAULT NULL,
            mime_real       VARCHAR(100) DEFAULT NULL,
            tamano_bytes    INT DEFAULT NULL,
            estado          VARCHAR(20) NOT NULL DEFAULT 'pendiente' COMMENT 'pendiente | aprobado | rechazado',
            motivo_rechazo  VARCHAR(300) DEFAULT NULL,
            subido_por      INT DEFAULT NULL,
            subido_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revisado_por    INT DEFAULT NULL,
            revisado_en     DATETIME DEFAULT NULL,
            UNIQUE KEY uq_documentos_comercio_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Carpeta privada (fuera de acceso web directo) donde se guardan los
 * documentos de ESTA empresa. Se identifica con empresa_db porque ya es un
 * valor único por empresa y seguro para usarse como nombre de carpeta.
 */
function mcp_directorio_privado(string $empresa_db): string
{
    $baseDir = __DIR__ . '/../uploads_privados/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $empresa_db);
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0755, true);
    }
    // El .htaccess raíz de uploads_privados/ ya deniega todo, pero cada
    // subcarpeta nueva no siempre lo hereda según la configuración de
    // Apache — se copia explícito por si acaso (igual que hace EduPago).
    $htaccessOrigen = __DIR__ . '/../uploads_privados/.htaccess';
    $htaccessDestino = $baseDir . '/.htaccess';
    if (is_file($htaccessOrigen) && !is_file($htaccessDestino)) {
        @copy($htaccessOrigen, $htaccessDestino);
    }
    return $baseDir;
}

/**
 * Guarda un archivo subido ($_FILES[...]) de forma privada y segura:
 * valida tamaño, extensión Y el MIME real leído de los bytes (no el que
 * manda el navegador, que cualquiera puede falsificar), y lo guarda con un
 * nombre aleatorio para no exponer el nombre original ni pisar archivos.
 *
 * @return array{ok:bool, ruta_relativa:?string, mime_real:?string, tamano_bytes:?int, error:?string}
 */
function mcp_guardar_documento(array $file, string $empresa_db): array
{
    $vacio = ['ok' => false, 'ruta_relativa' => null, 'mime_real' => null, 'tamano_bytes' => null, 'error' => null];

    $codigo = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($codigo !== UPLOAD_ERR_OK) {
        $vacio['error'] = match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo es demasiado grande.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió incompleto. Intenta de nuevo.',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
            default => 'No se pudo guardar el archivo en el servidor. Intenta de nuevo.',
        };
        return $vacio;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        $vacio['error'] = 'Subida inválida.';
        return $vacio;
    }
    if ($file['size'] <= 0) {
        $vacio['error'] = 'El archivo está vacío.';
        return $vacio;
    }
    if ($file['size'] > MCP_UPLOADS_MAX_BYTES) {
        $mb = round(MCP_UPLOADS_MAX_BYTES / 1024 / 1024, 1);
        $vacio['error'] = "El archivo supera el límite de {$mb} MB.";
        return $vacio;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset(MCP_UPLOADS_MIME_PERMITIDOS[$ext])) {
        $vacio['error'] = 'Tipo de archivo no permitido. Usa JPG, PNG o PDF.';
        return $vacio;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReal = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);
    if (!$mimeReal || $mimeReal !== MCP_UPLOADS_MIME_PERMITIDOS[$ext]) {
        $vacio['error'] = 'El archivo no parece ser del tipo esperado.';
        return $vacio;
    }

    $baseDir = mcp_directorio_privado($empresa_db);
    $nombre  = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $rutaAbs = $baseDir . '/' . $nombre;

    if (!move_uploaded_file($file['tmp_name'], $rutaAbs)) {
        $vacio['error'] = 'No se pudo guardar el archivo.';
        return $vacio;
    }
    @chmod($rutaAbs, 0644);

    return [
        'ok' => true,
        'ruta_relativa' => $nombre, // dentro de uploads_privados/{empresa_db}/
        'mime_real' => $mimeReal,
        'tamano_bytes' => (int)$file['size'],
        'error' => null,
    ];
}
