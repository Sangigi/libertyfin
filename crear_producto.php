<?php
// Archivo: crear_producto.php

require 'vendor/autoload.php';
use Facturapi\Facturapi;

// Configuración
$apiKey = 'sk_test_3NGWy62UprCyUHgvXmJmmqwt3xmvHeALdjyotVP8U1'; // Reemplaza con tu SecretTestKey o SecretLiveKey
$facturapi = new Facturapi($apiKey);

// Datos del producto
$productData = [
    'description' => 'Nescafe Black 40gr',
    'product_key' => '43211508', // Código de laptop del catálogo SAT
    'unit_key' => 'H87', // Elemento/pieza
    'unit_name' => 'Pieza',
    'price' => 45,
    'tax_included' => true,
    'taxability' => '02', // Sí objeto de impuesto
    'sku' => '7506475102346',
    'taxes' => [
        [
            'type' => 'IVA',
            'rate' => 0.16, // 16%
            'withholding' => false,
            'factor' => 'Tasa'
        ]
    ]
];

try {
    // Crear el producto
    $product = $facturapi->Products->create($productData);
    
    echo "✅ Producto creado exitosamente!\n";
    echo "ID del producto: " . $product->id . "\n";
    echo "Descripción: " . $product->description . "\n";
    echo "SKU: " . $product->sku . "\n";
    echo "Precio: $" . number_format($product->price, 2) . "\n";
    
} catch (Exception $e) {
    echo "❌ Error al crear el producto: " . $e->getMessage() . "\n";
}

// Ejemplo con producto sin impuestos (exento)
function crearProductoExento($facturapi) {
    $productDataExento = [
        'description' => 'Libro de Matemáticas',
        'product_key' => '90101501', // Código de libro del catálogo SAT
        'unit_key' => 'H87',
        'unit_name' => 'Libro',
        'price' => 350.00,
        'tax_included' => true,
        'taxability' => '01', // No objeto de impuesto
        'sku' => 'LIB-MAT-001',
        'taxes' => [] // Arreglo vacío para producto exento
    ];
    
    try {
        $product = $facturapi->Products->create($productDataExento);
        echo "\n✅ Producto exento creado: " . $product->description . "\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Ejemplo con producto por kilogramo
function crearProductoPorKilo($facturapi) {
    $productDataKilo = [
        'description' => 'Manzana Golden Delicious',
        'product_key' => '10101500', // Código de frutas del catálogo SAT
        'unit_key' => 'KGM', // Kilogramo
        'unit_name' => 'Kilogramo',
        'price' => 85.50,
        'tax_included' => true,
        'taxability' => '02',
        'sku' => 'FRUT-MANZ-001'
        // taxes se omite, se usará IVA 16% por defecto
    ];
    
    try {
        $product = $facturapi->Products->create($productDataKilo);
        echo "\n✅ Producto por kilo creado: " . $product->description . "\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Función para buscar claves SAT (si necesitas referencia)
function buscarClaveSAT($facturapi, $termino) {
    try {
        $resultados = $facturapi->Catalogs->searchProducts($termino);
        echo "\n🔍 Resultados de búsqueda para '$termino':\n";
        foreach ($resultados as $item) {
            echo "- " . $item->description . " (Clave: " . $item->key . ")\n";
        }
    } catch (Exception $e) {
        echo "❌ Error en búsqueda: " . $e->getMessage() . "\n";
    }
}

// Ejecutar funciones adicionales (descomenta si las necesitas)
// crearProductoExento($facturapi);
// crearProductoPorKilo($facturapi);
// buscarClaveSAT($facturapi, "laptop");