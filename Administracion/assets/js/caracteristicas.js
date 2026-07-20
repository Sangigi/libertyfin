// =============================================
// JAVASCRIPT ESPECÍFICO DE CARACTERÍSTICAS
// =============================================

(function() {
    'use strict';

    $(document).ready(function() {
        
        // =============================================
        // MOSTRAR/OCULTAR TIPOS DE UNIDAD
        // =============================================
        $('#unidad_medida').on('change', function() {
            if ($(this).is(':checked')) {
                $('#tiposUnidadContainer').slideDown();
            } else {
                $('#tiposUnidadContainer').slideUp();
            }
        });
        
        // =============================================
        // CARGAR CONFIGURACIÓN DE EMPRESA
        // =============================================
        $('#btnCargar').on('click', function() {
            const empresaId = $('#empresaSelect').val();
            const empresaNombre = $('#empresaSelect option:selected').text().replace(/\(Plan:.*\)/, '').trim();
            const $btn = $(this);
            
            // Mostrar loading
            $btn.prop('disabled', true);
            $btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Cargando...');
            
            // Cargar configuración de la empresa seleccionada
            $.ajax({
                url: 'obtener_caracteristicas.php',
                type: 'POST',
                data: { empresa_id: empresaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Actualizar campos del formulario
                        $('#precio_compra').prop('checked', response.data.precio_compra == 1);
                        $('#unidad_medida').prop('checked', response.data.unidad_medida == 1);
                        $('#proveedor').prop('checked', response.data.proveedor == 1);
                        $('#fecha_caducidad').prop('checked', response.data.fecha_caducidad == 1);
                        $('#categoria').prop('checked', response.data.categoria == 1);
                        
                        // Actualizar tipos de unidad
                        if (response.data.tipos_unidad && Array.isArray(response.data.tipos_unidad)) {
                            $('input[name="tipos_unidad[]"]').each(function() {
                                $(this).prop('checked', response.data.tipos_unidad.includes($(this).val()));
                            });
                        }
                        
                        // Actualizar empresa ID y nombre
                        $('#empresa_id').val(empresaId);
                        $('#empresaNombre').text(empresaNombre);
                        $('#empresaNombreHeader').text(empresaNombre);
                        
                        // Mostrar/ocultar configuración de tipos
                        if (response.data.unidad_medida == 1) {
                            $('#tiposUnidadContainer').show();
                        } else {
                            $('#tiposUnidadContainer').hide();
                        }
                        
                        mostrarMensaje('success', 'Configuración cargada correctamente');
                    } else {
                        mostrarMensaje('danger', 'Error al cargar: ' + (response.message || 'Error desconocido'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX:', error);
                    mostrarMensaje('danger', 'Error de conexión al cargar la configuración');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.html('<i class="fas fa-sync-alt me-2"></i>Cargar Configuración');
                }
            });
        });
        
        // =============================================
        // GUARDAR CONFIGURACIÓN
        // =============================================
        $('#caracteristicasForm').on('submit', function(e) {
            e.preventDefault();
            
            // Validar que al menos un tipo de unidad esté seleccionado si unidad_medida está activa
            if ($('#unidad_medida').is(':checked')) {
                const tiposSeleccionados = $('input[name="tipos_unidad[]"]:checked').length;
                if (tiposSeleccionados === 0) {
                    mostrarMensaje('warning', 'Debe seleccionar al menos un tipo de unidad');
                    return;
                }
            }
            
            const formData = $(this).serialize();
            const $btn = $('#btnGuardar');
            
            $btn.prop('disabled', true);
            $btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...');
            
            $.ajax({
                url: 'guardar_caracteristicas.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        mostrarMensaje('success', response.message || 'Configuración guardada correctamente');
                    } else {
                        mostrarMensaje('danger', 'Error: ' + (response.message || 'Error desconocido'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX:', error);
                    mostrarMensaje('danger', 'Error de conexión al guardar la configuración');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btn.html('<i class="fas fa-save me-2"></i>Guardar Configuración');
                }
            });
        });
        
        // =============================================
        // FUNCIÓN PARA MOSTRAR MENSAJES
        // =============================================
        function mostrarMensaje(tipo, mensaje) {
            const iconos = {
                success: 'check-circle',
                danger: 'exclamation-circle',
                warning: 'exclamation-triangle',
                info: 'info-circle'
            };
            
            const alertHtml = `
                <div class="alert alert-${tipo} alert-dismissible fade show" role="alert">
                    <i class="fas fa-${iconos[tipo] || 'info-circle'} me-2"></i>
                    ${mensaje}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            $('#mensajeContainer').html(alertHtml);
            
            // Auto cerrar después de 5 segundos
            setTimeout(() => {
                $('.alert').alert('close');
            }, 5000);
        }
        
        // =============================================
        // SCROLL TÁCTIL MEJORADO
        // =============================================
        function setupTouchScrolling() {
            const scrollables = document.querySelectorAll('.table-responsive, .card-body');
            
            scrollables.forEach(container => {
                let startX, startY, scrollLeft;
                let isScrolling = false;
                
                container.addEventListener('touchstart', function(e) {
                    if (window.innerWidth >= 768) return;
                    
                    startX = e.touches[0].pageX;
                    startY = e.touches[0].pageY;
                    scrollLeft = container.scrollLeft || 0;
                    isScrolling = false;
                    
                    container.classList.add('touch-active');
                }, { passive: true });
                
                container.addEventListener('touchmove', function(e) {
                    if (window.innerWidth >= 768) return;
                    if (!startX) return;
                    
                    const x = e.touches[0].pageX;
                    const y = e.touches[0].pageY;
                    
                    const walkX = startX - x;
                    const walkY = startY - y;
                    
                    if (Math.abs(walkX) > Math.abs(walkY) && container.scrollWidth > container.clientWidth) {
                        isScrolling = true;
                        container.classList.add('touch-scrolling');
                        container.scrollLeft = scrollLeft + walkX;
                    }
                }, { passive: true });
                
                container.addEventListener('touchend', function() {
                    if (isScrolling) {
                        setTimeout(() => {
                            container.classList.remove('touch-scrolling');
                            container.classList.remove('touch-active');
                        }, 300);
                    }
                    
                    startX = null;
                    startY = null;
                    isScrolling = false;
                }, { passive: true });
            });
        }
        
        // Inicializar scroll táctil
        setupTouchScrolling();

        // =============================================
        // DETECTAR CAMBIOS DE ORIENTACIÓN
        // =============================================
        let lastOrientation = window.orientation;
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                if (window.orientation !== lastOrientation) {
                    lastOrientation = window.orientation;
                    // Recargar tipos de unidad si es necesario
                    if ($('#unidad_medida').is(':checked')) {
                        $('#tiposUnidadContainer').show();
                    }
                }
            }, 100);
        });

        console.log('Características JS cargado correctamente');
    });

})();