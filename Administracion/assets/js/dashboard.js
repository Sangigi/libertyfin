// =============================================
// JAVASCRIPT ESPECÍFICO DEL DASHBOARD
// =============================================

(function() {
    'use strict';

    // Variables globales
    let filtroPlan = '';
    let filtroBusqueda = '';
    let paginaActual = 1;
    let cargando = false;
    let timerBusqueda = null; // Timer para el filtro de búsqueda

    // =============================================
    // FUNCIÓN PARA APLICAR FILTROS AUTOMÁTICOS
    // =============================================
    
    function aplicarFiltrosAutomaticos() {
        var filtroPlanSelect = document.getElementById('filtroPlan');
        var filtroBusquedaInput = document.getElementById('filtroBusqueda');
        
        // Obtener los valores actuales
        var plan = filtroPlanSelect ? filtroPlanSelect.value : '';
        var busqueda = filtroBusquedaInput ? filtroBusquedaInput.value.trim() : '';
        
        // Guardar en variables globales
        filtroPlan = plan;
        filtroBusqueda = busqueda;
        paginaActual = 1;
        
        // Cargar la tabla con los filtros
        cargarTabla(plan, busqueda, 1);
    }

    // =============================================
    // FUNCIÓN PARA ABRIR MODAL DIRECTAMENTE
    // =============================================
    
    window.abrirModalDirecto = function(empresaId) {
        if (!empresaId) {
            return;
        }
        
        // Obtener el elemento modal
        var modalElement = document.getElementById('modalDetalle');
        if (!modalElement) {
            return;
        }
        
        var modalBody = document.getElementById('detalleEmpresa');
        if (!modalBody) {
            return;
        }
        
        // Mostrar loading
        modalBody.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-3 text-muted">Cargando información de la empresa...</p>
            </div>
        `;
        
        // Obtener o crear instancia del modal
        var bsModal = bootstrap.Modal.getInstance(modalElement);
        if (!bsModal) {
            bsModal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        }
        
        // Mostrar el modal
        try {
            bsModal.show();
        } catch (error) {
            bsModal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            bsModal.show();
        }
        
        // Cargar los datos vía AJAX
        $.ajax({
            url: 'Service/ajax_detalle_empresa.php',
            method: 'GET',
            data: { id: empresaId },
            timeout: 10000,
            success: function(response) {
                modalBody.innerHTML = response;
                
                // Resaltar la fila seleccionada
                $('.fila-clickeable').removeClass('seleccionada');
                $('.fila-clickeable[data-id="' + empresaId + '"]').addClass('seleccionada');
            },
            error: function(xhr, status, error) {
                modalBody.innerHTML = `
                    <div class="alert alert-danger m-3">
                        <h6><i class="fas fa-exclamation-circle me-2"></i>Error al cargar los detalles</h6>
                        <p class="mb-0">No se pudieron cargar los detalles de la empresa.</p>
                        <button class="btn btn-sm btn-outline-danger mt-2" onclick="location.reload()">
                            <i class="fas fa-sync me-1"></i>Recargar página
                        </button>
                    </div>
                `;
            }
        });
    };

    // =============================================
    // FUNCIÓN PARA ABRIR MODAL DE EDICIÓN
    // =============================================

    window.abrirModalEditar = function(empresaId) {
        if (!empresaId) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'ID de empresa no válido'
            });
            return;
        }
        
        var modalElement = document.getElementById('modalEditar');
        if (!modalElement) {
            return;
        }
        
        var contenido = document.getElementById('contenidoEditar');
        if (!contenido) {
            return;
        }
        
        // Mostrar loading
        contenido.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-warning" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-3 text-muted">Cargando formulario de edición...</p>
            </div>
        `;
        
        // Obtener o crear instancia del modal
        var bsModal = bootstrap.Modal.getInstance(modalElement);
        if (!bsModal) {
            bsModal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        }
        
        // Mostrar el modal
        try {
            bsModal.show();
        } catch (error) {
            bsModal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            bsModal.show();
        }
        
        // Cargar el formulario de edición
        $.ajax({
            url: 'Service/ajax_editar_empresa.php',
            method: 'GET',
            data: { id: empresaId },
            timeout: 10000,
            success: function(response) {
                contenido.innerHTML = response;
            },
            error: function(xhr, status, error) {
                contenido.innerHTML = `
                    <div class="alert alert-danger m-3">
                        <h6><i class="fas fa-exclamation-circle me-2"></i>Error al cargar el formulario</h6>
                        <p class="mb-0">No se pudo cargar el formulario de edición.</p>
                        <button class="btn btn-sm btn-outline-danger mt-2" onclick="location.reload()">
                            <i class="fas fa-sync me-1"></i>Recargar página
                        </button>
                    </div>
                `;
            }
        });
    };

    // =============================================
    // FUNCIÓN PARA CERRAR MODAL DE EDICIÓN
    // =============================================

    window.cerrarModalEditar = function() {
        var modalElement = document.getElementById('modalEditar');
        if (modalElement) {
            var bsModal = bootstrap.Modal.getInstance(modalElement);
            if (bsModal) {
                bsModal.hide();
            }
        }
    };

    // =============================================
    // FUNCIÓN PARA CARGAR TABLA VÍA AJAX
    // =============================================
    
    function cargarTabla(plan, busqueda, pagina) {
        if (cargando) return;
        cargando = true;
        
        var tablaContainer = document.getElementById('tablaContainer');
        if (!tablaContainer) {
            cargando = false;
            return;
        }
        
        // Guardar filtros en variables globales
        filtroPlan = typeof plan === 'string' ? plan : '';
        filtroBusqueda = typeof busqueda === 'string' ? busqueda : '';
        paginaActual = pagina || 1;
        
        // Mostrar indicador de carga
        tablaContainer.innerHTML = `
            <div class="table-loading">
                <div class="loading-overlay">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
                <div style="min-height: 300px;"></div>
            </div>
        `;
        
        // Construir URL con parámetros
        let url = 'Service/cargar_tabla_dashboard.php?';
        let params = [];
        
        if (filtroPlan && filtroPlan !== 'todos' && filtroPlan !== '') {
            params.push('plan=' + encodeURIComponent(filtroPlan));
        }
        if (filtroBusqueda && filtroBusqueda !== '') {
            params.push('busqueda=' + encodeURIComponent(filtroBusqueda));
        }
        params.push('pagina=' + (paginaActual || 1));
        
        url += params.join('&');
        
        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            timeout: 15000,
            success: function(response) {
                if (response.success) {
                    tablaContainer.innerHTML = response.html;
                    
                    // Actualizar contador de registros
                    var totalRegistros = document.getElementById('totalRegistros');
                    if (totalRegistros && response.total !== undefined) {
                        totalRegistros.textContent = response.total + ' registros';
                    }
                    
                    // Actualizar estadísticas si no hay filtros
                    if ((!filtroPlan || filtroPlan === 'todos' || filtroPlan === '') && (!filtroBusqueda || filtroBusqueda === '')) {
                        actualizarEstadisticas();
                    }
                } else {
                    mostrarError('Error al cargar datos: ' + (response.error || 'Error desconocido'));
                }
                cargando = false;
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX tabla:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                let mensajeError = 'Error al cargar los datos. ';
                if (status === 'timeout') {
                    mensajeError += 'La solicitud ha tardado demasiado tiempo.';
                } else if (status === 'error') {
                    mensajeError += 'Verifique la conexión al servidor.';
                } else if (xhr.responseText) {
                    try {
                        var jsonResp = JSON.parse(xhr.responseText);
                        if (jsonResp.error) {
                            mensajeError += jsonResp.error;
                        } else {
                            mensajeError += 'Error interno del servidor.';
                        }
                    } catch (e) {
                        mensajeError += 'Error interno del servidor.';
                    }
                } else {
                    mensajeError += 'Intente nuevamente.';
                }
                
                mostrarError(mensajeError);
                cargando = false;
            }
        });
    }
    
    // =============================================
    // FUNCIÓN PARA MOSTRAR ERRORES
    // =============================================
    
    function mostrarError(mensaje) {
        var tablaContainer = document.getElementById('tablaContainer');
        if (!tablaContainer) return;
        
        tablaContainer.innerHTML = `
            <div class="card">
                <div class="card-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${mensaje}
                        <br><small class="text-muted">Intente recargar la página si el problema persiste.</small>
                    </div>
                    <button class="btn btn-primary" onclick="location.reload()">
                        <i class="fas fa-sync me-1"></i>Recargar página
                    </button>
                </div>
            </div>
        `;
    }
    
    // =============================================
    // FUNCIÓN PARA ACTUALIZAR ESTADÍSTICAS
    // =============================================
    
    function actualizarEstadisticas() {
        $.ajax({
            url: 'Service/ajax_estadisticas.php',
            method: 'GET',
            dataType: 'json',
            timeout: 5000,
            success: function(response) {
                if (response.success && response.data) {
                    const stats = response.data;
                    
                    var statTotal = document.getElementById('statTotal');
                    var statAprobadas = document.getElementById('statAprobadas');
                    var statDesactivadas = document.getElementById('statDesactivadas');
                    var statPrueba = document.getElementById('statPrueba');
                    var statBasico = document.getElementById('statBasico');
                    var statStarter = document.getElementById('statStarter');
                    var statEmprendedor = document.getElementById('statEmprendedor');
                    var statPremium = document.getElementById('statPremium');
                    var statTotalPlanes = document.getElementById('statTotalPlanes');
                    var totalEmpresasHeader = document.getElementById('totalEmpresasHeader');
                    var infoTotalEmpresas = document.getElementById('infoTotalEmpresas');
                    
                    if (statTotal) statTotal.textContent = stats.total_empresas || 0;
                    if (statAprobadas) statAprobadas.textContent = stats.aprobadas || 0;
                    if (statDesactivadas) statDesactivadas.textContent = stats.desactivadas || 0;
                    if (statPrueba) statPrueba.textContent = stats.plan_prueba || 0;
                    if (statBasico) statBasico.textContent = stats.plan_basico || 0;
                    if (statStarter) statStarter.textContent = stats.plan_starter || 0;
                    if (statEmprendedor) statEmprendedor.textContent = stats.plan_emprendedor || 0;
                    if (statPremium) statPremium.textContent = stats.plan_premium || 0;
                    
                    const totalPlanes = (stats.plan_prueba || 0) + (stats.plan_basico || 0) + 
                                       (stats.plan_starter || 0) + (stats.plan_emprendedor || 0) + 
                                       (stats.plan_premium || 0);
                    if (statTotalPlanes) statTotalPlanes.textContent = totalPlanes;
                    
                    if (totalEmpresasHeader) totalEmpresasHeader.textContent = stats.total_empresas || 0;
                    if (infoTotalEmpresas) infoTotalEmpresas.textContent = stats.total_empresas + ' empresas';
                }
            },
            error: function(xhr, status, error) {
                // Silencioso
            }
        });
    }

    // =============================================
    // FUNCIONALIDAD PARA EL VISOR DE ARCHIVOS
    // =============================================

    window.abrirArchivoModal = function(rutaArchivo, tipoArchivo, nombreArchivo, titulo) {
        var modalElement = document.getElementById('modalArchivo');
        if (!modalElement) {
            window.open(rutaArchivo, '_blank');
            return;
        }
        
        var modalTitulo = document.getElementById('modalArchivoTitulo');
        var cargando = document.getElementById('archivoCargando');
        var visorImagen = document.getElementById('visorImagen');
        var imagenVisor = document.getElementById('imagenVisor');
        var visorPDF = document.getElementById('visorPDF');
        var pdfVisor = document.getElementById('pdfVisor');
        var visorError = document.getElementById('visorError');
        var descargarBtn = document.getElementById('descargarArchivo');
        var infoArchivo = document.getElementById('infoArchivo');
        
        if (!modalTitulo || !cargando || !visorImagen || !imagenVisor || !visorPDF || !pdfVisor || !visorError || !descargarBtn || !infoArchivo) {
            window.open(rutaArchivo, '_blank');
            return;
        }

        modalTitulo.textContent = titulo;
        descargarBtn.href = rutaArchivo;
        descargarBtn.download = nombreArchivo;
        infoArchivo.textContent = nombreArchivo;

        cargando.classList.remove('d-none');
        visorImagen.classList.add('d-none');
        visorPDF.classList.add('d-none');
        visorError.classList.add('d-none');

        var bsModal = bootstrap.Modal.getInstance(modalElement);
        if (!bsModal) {
            bsModal = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
        }
        
        bsModal.show();

        var onShown = function() {
            modalElement.removeEventListener('shown.bs.modal', onShown);

            var modalBody = modalElement.querySelector('.modal-body');
            var modalHeader = modalElement.querySelector('.modal-header');
            var modalFooter = modalElement.querySelector('.modal-footer');

            var headerHeight = modalHeader ? modalHeader.offsetHeight : 0;
            var footerHeight = modalFooter ? modalFooter.offsetHeight : 0;
            var windowHeight = window.innerHeight;
            var maxModalHeight = windowHeight * 0.9;
            var modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40;

            if (tipoArchivo === 'imagen') {
                var img = new Image();
                img.onload = function() {
                    imagenVisor.src = rutaArchivo;
                    cargando.classList.add('d-none');
                    visorImagen.classList.remove('d-none');

                    var maxWidth = modalBody.offsetWidth - 40;
                    var maxHeight = modalBodyHeight - 40;

                    if (this.width > maxWidth || this.height > maxHeight) {
                        var ratio = Math.min(maxWidth / this.width, maxHeight / this.height);
                        imagenVisor.style.width = (this.width * ratio) + 'px';
                        imagenVisor.style.height = (this.height * ratio) + 'px';
                    } else {
                        imagenVisor.style.width = this.width + 'px';
                        imagenVisor.style.height = this.height + 'px';
                    }

                    infoArchivo.textContent = nombreArchivo + ' (' + this.width + '×' + this.height + 'px)';
                };
                img.onerror = function() {
                    cargando.classList.add('d-none');
                    visorError.classList.remove('d-none');
                };
                img.src = rutaArchivo;

            } else if (tipoArchivo === 'pdf') {
                pdfVisor.src = rutaArchivo + '#view=fitH';
                pdfVisor.style.height = modalBodyHeight + 'px';

                pdfVisor.onload = function() {
                    cargando.classList.add('d-none');
                    visorPDF.classList.remove('d-none');
                };
                pdfVisor.onerror = function() {
                    cargando.classList.add('d-none');
                    visorError.classList.remove('d-none');
                };

                setTimeout(function() {
                    if (!cargando.classList.contains('d-none')) {
                        cargando.classList.add('d-none');
                        visorPDF.classList.remove('d-none');
                    }
                }, 3000);
            } else {
                setTimeout(function() {
                    cargando.classList.add('d-none');
                    visorError.classList.remove('d-none');
                }, 500);
            }

            modalBody.style.display = 'none';
            modalBody.offsetHeight;
            modalBody.style.display = 'block';
        };
        
        modalElement.addEventListener('shown.bs.modal', onShown);
    };

    // =============================================
    // EVENTOS - DOCUMENT READY
    // =============================================
    
    $(document).ready(function() {
        // Inicializar filtros desde los campos del formulario
        var filtroPlanSelect = document.getElementById('filtroPlan');
        var filtroBusquedaInput = document.getElementById('filtroBusqueda');
        
        filtroPlan = filtroPlanSelect ? filtroPlanSelect.value : '';
        filtroBusqueda = filtroBusquedaInput ? filtroBusquedaInput.value.trim() : '';
        
        // Cargar tabla inicial
        cargarTabla(filtroPlan, filtroBusqueda, 1);
        
        // =============================================
        // FILTROS AUTOMÁTICOS - SIN BOTÓN
        // =============================================
        
        // EVENTO: Cambio en el select de plan - FILTRO AUTOMÁTICO
        $('#filtroPlan').on('change', function() {
            aplicarFiltrosAutomaticos();
        });
        
        // EVENTO: Escritura en el campo de búsqueda - FILTRO AUTOMÁTICO CON DELAY
        $('#filtroBusqueda').on('input', function() {
            // Limpiar el timer anterior
            if (timerBusqueda) {
                clearTimeout(timerBusqueda);
            }
            
            // Configurar nuevo timer con delay de 500ms
            timerBusqueda = setTimeout(function() {
                aplicarFiltrosAutomaticos();
            }, 500);
        });
        
        // EVENTO: Enter en el campo de búsqueda - aplica inmediatamente
        $('#filtroBusqueda').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                // Limpiar timer para aplicar inmediatamente
                if (timerBusqueda) {
                    clearTimeout(timerBusqueda);
                }
                aplicarFiltrosAutomaticos();
            }
        });
        
        // EVENTO: Botón Limpiar (también automático)
        $('#btnLimpiar').on('click', function() {
            $('#filtroPlan').val('todos');
            $('#filtroBusqueda').val('');
            if (timerBusqueda) {
                clearTimeout(timerBusqueda);
            }
            aplicarFiltrosAutomaticos();
        });
        
        // =============================================
        // EVENTO: CLIC EN FILA COMPLETA
        // =============================================
        
        $(document).on('click', '.fila-clickeable', function(e) {
            if ($(e.target).closest('.btn, .btn-group, a, button, input, select, textarea').length > 0) {
                return;
            }
            
            var empresaId = $(this).data('id');
            if (empresaId) {
                window.abrirModalDirecto(empresaId);
            }
        });
        
        // =============================================
        // EVENTO: CLIC EN BOTÓN VER DETALLE
        // =============================================
        
        $(document).on('click', '.btn-ver-detalle', function(e) {
            e.stopPropagation();
            var empresaId = $(this).data('id');
            if (empresaId) {
                window.abrirModalDirecto(empresaId);
            }
        });

        // =============================================
        // EVENTO: CLIC EN BOTÓN EDITAR (TABLA)
        // =============================================
        
        $(document).on('click', '.btn-editar-empresa', function(e) {
            e.stopPropagation();
            var empresaId = $(this).data('id');
            if (empresaId) {
                window.abrirModalEditar(empresaId);
            }
        });
        
        // =============================================
        // EVENTO: ENVÍO DEL FORMULARIO DE EDICIÓN
        // =============================================
        
        $(document).on('submit', '#formEditarEmpresa', function(e) {
            e.preventDefault();
            
            var form = $(this);
            var formData = new FormData(this);
            var btn = document.getElementById('btnGuardarEdicion');
            var mensajeDiv = document.getElementById('editMessage');
            var empresaId = formData.get('id_empresa');
            
            if (!btn || !mensajeDiv) {
                return;
            }
            
            var nombreEmpresa = formData.get('nombre_empresa');
            var giroComercial = formData.get('giro_comercial');
            var telefono = formData.get('telefono');
            var email = formData.get('email');
            var nombreContacto = formData.get('nombre_contacto');
            
            if (!nombreEmpresa || !giroComercial || !telefono || !email || !nombreContacto) {
                mensajeDiv.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Todos los campos requeridos deben estar llenos.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                mensajeDiv.style.display = 'block';
                return;
            }
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';
            btn.classList.add('btn-loading');
            
            mensajeDiv.style.display = 'none';
            
            $.ajax({
                url: 'Service/ajax_guardar_empresa.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 30000,
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        mensajeDiv.innerHTML = `
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                ${response.message || 'Empresa actualizada correctamente'}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        `;
                        mensajeDiv.style.display = 'block';
                        
                        setTimeout(function() {
                            cargarTabla(filtroPlan || '', filtroBusqueda || '', paginaActual || 1);
                            window.cerrarModalEditar();
                            
                            var modalDetalle = document.getElementById('modalDetalle');
                            if (modalDetalle && modalDetalle.classList.contains('show') && empresaId) {
                                window.abrirModalDirecto(empresaId);
                            }
                            
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Cambios';
                            btn.classList.remove('btn-loading');
                        }, 2000);
                        
                    } else {
                        var errorMsg = (response && response.message) ? response.message : 'Error al guardar los cambios';
                        mensajeDiv.innerHTML = `
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                ${errorMsg}
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        `;
                        mensajeDiv.style.display = 'block';
                        
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Cambios';
                        btn.classList.remove('btn-loading');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX guardado:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText
                    });
                    
                    let errorMsg = 'Error al guardar los cambios: ';
                    
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg += xhr.responseJSON.message;
                    } else if (xhr.responseText) {
                        try {
                            var jsonResp = JSON.parse(xhr.responseText);
                            if (jsonResp && jsonResp.message) {
                                errorMsg += jsonResp.message;
                            } else {
                                errorMsg += xhr.responseText.substring(0, 200);
                            }
                        } catch (e) {
                            errorMsg += xhr.responseText.substring(0, 200);
                        }
                    } else if (status === 'timeout') {
                        errorMsg += 'La solicitud ha tardado demasiado tiempo.';
                    } else if (status === 'parsererror') {
                        errorMsg += 'Error al procesar la respuesta del servidor.';
                    } else {
                        errorMsg += error || 'Error desconocido';
                    }
                    
                    mensajeDiv.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            ${errorMsg}
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    `;
                    mensajeDiv.style.display = 'block';
                    
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar Cambios';
                    btn.classList.remove('btn-loading');
                }
            });
        });

        // =============================================
        // EVENTO: CIERRE DEL MODAL DE EDICIÓN
        // =============================================
        
        var modalEditar = document.getElementById('modalEditar');
        if (modalEditar) {
            modalEditar.addEventListener('hidden.bs.modal', function() {
                var contenido = document.getElementById('contenidoEditar');
                if (contenido) {
                    contenido.innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-warning" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p class="mt-3 text-muted">Cargando formulario de edición...</p>
                        </div>
                    `;
                }
            });
        }
        
        // =============================================
        // EVENTO: CIERRE DEL MODAL DE DETALLES
        // =============================================
        
        var modalDetalle = document.getElementById('modalDetalle');
        if (modalDetalle) {
            modalDetalle.addEventListener('hidden.bs.modal', function() {
                var detalleEmpresa = document.getElementById('detalleEmpresa');
                if (detalleEmpresa) {
                    detalleEmpresa.innerHTML = `
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <p class="mt-3 text-muted">Cargando información...</p>
                        </div>
                    `;
                }
                
                $('.fila-clickeable').removeClass('seleccionada');
            });
        }
        
        // =============================================
        // EVENTO: CIERRE DEL MODAL DE ARCHIVOS
        // =============================================
        
        var modalArchivo = document.getElementById('modalArchivo');
        if (modalArchivo) {
            modalArchivo.addEventListener('hidden.bs.modal', function() {
                var imagenVisor = document.getElementById('imagenVisor');
                var pdfVisor = document.getElementById('pdfVisor');
                var cargando = document.getElementById('archivoCargando');
                var visorImagen = document.getElementById('visorImagen');
                var visorPDF = document.getElementById('visorPDF');
                var visorError = document.getElementById('visorError');

                if (imagenVisor) {
                    imagenVisor.src = '';
                    imagenVisor.style.width = '';
                    imagenVisor.style.height = '';
                }
                if (pdfVisor) {
                    pdfVisor.src = '';
                    pdfVisor.style.height = '100%';
                }
                if (cargando) cargando.classList.remove('d-none');
                if (visorImagen) visorImagen.classList.add('d-none');
                if (visorPDF) visorPDF.classList.add('d-none');
                if (visorError) visorError.classList.add('d-none');
            });
        }
        
        // =============================================
        // EVENTO: Paginación
        // =============================================
        
        $(document).on('click', '.paginacion-link', function(e) {
            e.preventDefault();
            var pagina = $(this).data('pagina');
            if (pagina) {
                cargarTabla(filtroPlan, filtroBusqueda, parseInt(pagina));
            }
        });

        // =============================================
        // EVENTO: Ver archivo
        // =============================================

        $(document).on('click', '.ver-archivo', function(e) {
            e.preventDefault();
            var ruta = $(this).data('archivo');
            var tipo = $(this).data('tipo');
            var nombre = $(this).data('nombre');
            var titulo = $(this).data('titulo');

            if (ruta && tipo && nombre && titulo) {
                if (typeof window.abrirArchivoModal === 'function') {
                    window.abrirArchivoModal(ruta, tipo, nombre, titulo);
                } else {
                    window.open(ruta, '_blank');
                }
            }
        });

        // =============================================
        // EVENTO: Redimensionar ventana para PDF
        // =============================================

        $(window).on('resize', function() {
            var modal = document.getElementById('modalArchivo');
            if (modal && modal.classList.contains('show')) {
                var pdfVisor = document.getElementById('pdfVisor');
                if (pdfVisor && !pdfVisor.classList.contains('d-none')) {
                    var modalBody = modal.querySelector('.modal-body');
                    var modalHeader = modal.querySelector('.modal-header');
                    var modalFooter = modal.querySelector('.modal-footer');

                    if (modalBody && modalHeader && modalFooter) {
                        var headerHeight = modalHeader.offsetHeight;
                        var footerHeight = modalFooter.offsetHeight;
                        var windowHeight = window.innerHeight;
                        var maxModalHeight = windowHeight * 0.9;
                        var modalBodyHeight = maxModalHeight - headerHeight - footerHeight - 40;

                        pdfVisor.style.height = modalBodyHeight + 'px';
                    }
                }
            }
        });
    });

})();