<?php
// ============================================================
// HELPER GLOBAL: Convertir slug de plan → nombre legible
// ============================================================
if (!function_exists('nombrePlanLegible')) {
    function nombrePlanLegible($slug) {
        $mapa = [
            'basico'      => 'Básico',
            'starter'     => 'Profesional',
            'emprendedor' => 'Empresarial',
            'premium'     => 'Empresarial Plus',
        ];
        $slug = strtolower(trim((string) $slug));
        return $mapa[$slug] ?? ucfirst($slug ?: 'N/A');
    }
}