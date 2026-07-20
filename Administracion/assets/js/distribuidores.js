// =============================================
// JAVASCRIPT ESPECÍFICO DE DISTRIBUIDORES
// =============================================

(function() {
    'use strict';

    let currentPage = 1;
    let isLoading = false;
    let searchTimeout = null;
    let abortController = null;
    let lastFilters = { busqueda: '', estado_verificacion: '', estado_activo: '' };

    // Cargar distribuidores
    function cargarDistribuidores(page = 1, force = false) {
        if (isLoading) return;
        
        const filters = {
            pagina: page,
            busqueda: $('#busqueda').val().trim(),
            estado_verificacion: $('#estado_verificacion').val(),
            estado_activo: $('#estado_activo').val()
        };
        
        const filtersChanged = JSON.stringify(filters) !== JSON.stringify(lastFilters);
        if (!force && !filtersChanged && page === currentPage) return;
        
        if (abortController) abortController.abort();
        abortController = new AbortController();
        isLoading = true;
        
        $('#tablaContent').html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary"></div>
                <p class="mt-2">Cargando distribuidores...</p>
            </div>
        `);
        
        $.ajax({
            url: 'ajax_distribuidores.php',
            method: 'GET',
            data: filters,
            dataType: 'json',
            signal: abortController.signal,
            timeout: 10000,
            success: function(response) {
                if (response.success) {
                    $('#tablaContent').html(response.html);
                    
                    if (response.estadisticas && filtersChanged) {
                        $('#statTotal, #totalDistribuidores').text(response.estadisticas.total || 0);
                        $('#statAprobados, #statActivos').text(response.estadisticas.aprobados || 0);
                        $('#statPendientes').text(response.estadisticas.pendientes || 0);
                        $('#statEnRevision').text(response.estadisticas.en_revision || 0);
                        $('#statRechazados').text(response.estadisticas.rechazados || 0);
                    }
                    
                    currentPage = page;
                    lastFilters = {...filters};
                } else {
                    $('#tablaContent').html(`
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-triangle fa-3x text-warning"></i>
                            <p>${response.mensaje || 'Error al cargar'}</p>
                        </div>
                    `);
                }
            },
            error: function(xhr) {
                if (xhr.statusText !== 'abort') {
                    $('#tablaContent').html(`
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-circle fa-3x text-danger"></i>
                            <p>Error de conexión. Reintentando...</p>
                        </div>
                    `);
                    setTimeout(() => cargarDistribuidores(page, true), 3000);
                }
            },
            complete: function() {
                isLoading = false;
                abortController = null;
                $('.input-group').removeClass('searching');
            }
        });
    }

    // Búsqueda con debounce
    function buscarConDebounce() {
        $('.input-group').addClass('searching');
        if (searchTimeout) clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            cargarDistribuidores(1);
        }, 300);
    }

    // Exportar Excel
    window.exportarExcel = function() {
        const params = new URLSearchParams({
            busqueda: $('#busqueda').val(),
            estado_verificacion: $('#estado_verificacion').val(),
            estado_activo: $('#estado_activo').val()
        });
        window.location.href = 'exportar_distribuidores.php?' + params.toString();
    };

    // Limpiar filtros
    function limpiarFiltros() {
        $('#busqueda').val('');
        $('#estado_verificacion').val('');
        $('#estado_activo').val('');
        cargarDistribuidores(1);
    }

    // Ver detalles
    window.verDetalle = function(id) {
        const modal = new bootstrap.Modal(document.getElementById('modalDetalle'));
        $('#detalleDistribuidor').html(`
            <div class="text-center py-4">
                <div class="spinner-border text-primary"></div>
                <p>Cargando...</p>
            </div>
        `);
        modal.show();
        
        $.ajax({
            url: 'ajax_detalle_distribuidor.php',
            method: 'GET',
            data: { id: id },
            success: function(response) {
                $('#detalleDistribuidor').html(response);
            },
            error: function() {
                $('#detalleDistribuidor').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Error al cargar los detalles
                    </div>
                `);
            }
        });
    };

    // Confirmar acciones
    window.confirmarAccion = function(id, accion) {
        $('#confirmarId').val(id);
        $('#confirmarAccion').val(accion);
        $('#mensajeConfirmacion').text(accion === 'desactivar' ? '¿Desactivar este distribuidor?' : '¿Activar este distribuidor?');
        $('#btnConfirmar').removeClass('btn-primary btn-danger').addClass(accion === 'desactivar' ? 'btn-danger' : 'btn-success');
        new bootstrap.Modal(document.getElementById('modalConfirmar')).show();
    };

    // Visor de archivos
    window.abrirArchivoModal = function(ruta, tipo, nombre, titulo) {
        const modal = new bootstrap.Modal(document.getElementById('modalArchivo'));
        const url = ruta.startsWith('http') ? ruta : (ruta.startsWith('/') ? ruta : '/' + ruta);
        
        $('#modalArchivoTitulo').text(titulo);
        $('#descargarArchivo').attr('href', url);
        $('#infoArchivo').text(nombre);
        
        $('#archivoCargando').removeClass('d-none');
        $('#visorImagen, #visorPDF, #visorError').addClass('d-none');
        
        modal.show();
        
        setTimeout(() => {
            if (tipo === 'imagen') {
                const img = new Image();
                img.onload = () => {
                    $('#imagenVisor').attr('src', url);
                    $('#archivoCargando').addClass('d-none');
                    $('#visorImagen').removeClass('d-none');
                };
                img.onerror = () => {
                    $('#archivoCargando, #visorImagen').addClass('d-none');
                    $('#visorError').removeClass('d-none');
                };
                img.src = url;
            } else if (tipo === 'pdf') {
                $('#pdfVisor').attr('src', url + '#view=fitH');
                $('#archivoCargando').addClass('d-none');
                $('#visorPDF').removeClass('d-none');
            } else {
                $('#archivoCargando').addClass('d-none');
                $('#visorError').removeClass('d-none');
            }
        }, 100);
    };

    // Cambiar página
    function cambiarPagina(page) {
        if (page !== currentPage) {
            cargarDistribuidores(page);
            if (window.innerWidth < 768) {
                $('html, body').animate({
                    scrollTop: $('#tablaContainer').offset().top - 70
                }, 300);
            }
        }
    }

    // =============================================
    // EVENTOS
    // =============================================

    $(document).ready(function() {
        cargarDistribuidores(1);
        
        $('#busqueda').on('input', buscarConDebounce);
        $('#estado_verificacion, #estado_activo').on('change', () => cargarDistribuidores(1));
        $('#btnLimpiarFiltros').on('click', limpiarFiltros);
        
        $(document).on('click', '.ver-archivo', function(e) {
            e.preventDefault();
            window.abrirArchivoModal(
                $(this).data('archivo'),
                $(this).data('tipo'),
                $(this).data('nombre'),
                $(this).data('titulo')
            );
        });
        
        $(document).on('click', '.page-link', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page) cambiarPagina(page);
        });
        
        // Limpiar modales al cerrar
        $('#modalArchivo').on('hidden.bs.modal', function() {
            $('#imagenVisor').attr('src', '');
            $('#pdfVisor').attr('src', '');
            $('#archivoCargando, #visorImagen, #visorPDF, #visorError').addClass('d-none');
            $('#archivoCargando').removeClass('d-none');
        });
        
        $('#modalDetalle').on('hidden.bs.modal', function() {
            $('#detalleDistribuidor').html('');
        });
    });

    // Detectar cambios de orientación
    let lastOrientation = window.orientation;
    window.addEventListener('orientationchange', function() {
        setTimeout(() => {
            if (window.orientation !== lastOrientation) {
                lastOrientation = window.orientation;
                cargarDistribuidores(currentPage, true);
            }
        }, 100);
    });

    // Mejorar experiencia táctil
    if ('ontouchstart' in window) {
        $(document).on('touchstart', '.distribuidor-card .btn', function(e) {
            e.preventDefault();
            $(this).trigger('click');
        });
    }

    console.log('Distribuidores JS cargado correctamente');

})();