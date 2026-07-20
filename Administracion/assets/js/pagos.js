(function() {
    'use strict';

    // Variables globales
    let tabActiva = 'liga';
    let paginaLiga = 1;
    let paginaSpei = 1;
    let registrosPorPagina = 5;

    // =============================================
    // FUNCIONES DE CARGA AJAX
    // =============================================

    function cargarLiga() {
        const filtroEstado = $('#filtroEstado').val();
        const filtroFechaInicio = $('#filtroFechaInicio').val();
        const filtroFechaFin = $('#filtroFechaFin').val();
        const busqueda = $('#busquedaLiga').val();
        
        $('#ligaLoading').show();
        $('#ligaTablaContainer, #ligaCardsContainer').addClass('opacity-50');
        
        $.ajax({
            url: 'ajax_pagos.php',
            type: 'POST',
            data: {
                action: 'get_liga',
                pagina: paginaLiga,
                registros_por_pagina: registrosPorPagina,
                estado: filtroEstado,
                fecha_inicio: filtroFechaInicio,
                fecha_fin: filtroFechaFin,
                busqueda: busqueda
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    actualizarTablaLiga(response);
                    actualizarCardsLiga(response);
                    actualizarPaginacionLiga(response.total_paginas, response.pagina_actual, response.total_registros);
                    $('#ligaRegistrosCount').text(response.total_registros + ' registros');
                    $('#ligaTotalBadge').text(response.total_registros);
                } else {
                    mostrarErrorLiga(response.message || 'Error al cargar los datos');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX:', error);
                mostrarErrorLiga('Error de conexión al servidor');
            },
            complete: function() {
                $('#ligaLoading').hide();
                $('#ligaTablaContainer, #ligaCardsContainer').removeClass('opacity-50');
            }
        });
    }

    function cargarSpei() {
        const filtroEstado = $('#filtroEstado').val();
        const filtroFechaInicio = $('#filtroFechaInicio').val();
        const filtroFechaFin = $('#filtroFechaFin').val();
        const busqueda = $('#busquedaSpei').val();
        
        $('#speiLoading').show();
        $('#speiTablaContainer, #speiCardsContainer').addClass('opacity-50');
        
        $.ajax({
            url: 'ajax_pagos.php',
            type: 'POST',
            data: {
                action: 'get_spei',
                pagina: paginaSpei,
                registros_por_pagina: registrosPorPagina,
                estado: filtroEstado,
                fecha_inicio: filtroFechaInicio,
                fecha_fin: filtroFechaFin,
                busqueda: busqueda
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    actualizarTablaSpei(response);
                    actualizarCardsSpei(response);
                    actualizarPaginacionSpei(response.total_paginas, response.pagina_actual, response.total_registros);
                    $('#speiRegistrosCount').text(response.total_registros + ' registros');
                    $('#speiTotalBadge').text(response.total_registros);
                } else {
                    mostrarErrorSpei(response.message || 'Error al cargar los datos');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX:', error);
                mostrarErrorSpei('Error de conexión al servidor');
            },
            complete: function() {
                $('#speiLoading').hide();
                $('#speiTablaContainer, #speiCardsContainer').removeClass('opacity-50');
            }
        });
    }

    // =============================================
    // FUNCIONES DE ACTUALIZACIÓN DE VISTAS
    // =============================================

    function actualizarTablaLiga(data) {
        const tbody = $('#ligaTablaBody');
        tbody.empty();
        
        if (data.pagos.length === 0) {
            tbody.html(`
                <tr>
                    <td colspan="10" class="text-center py-4">
                        <div class="text-muted">
                            <i class="fas fa-link fa-3x d-block mb-3"></i>
                            No se encontraron pagos con Liga
                        </div>
                    </td>
                </tr>
            `);
            return;
        }
        
        data.pagos.forEach(pago => {
            const estadoClass = getEstadoClass(pago.response);
            const estadoText = getEstadoText(pago.response);
            const montoFormateado = formatearMoneda(pago.amount, 'MXN');
            
            const folioEscaped = escapeHtml(pago.foliocpagos || '');
            const ccNameEscaped = escapeHtml(pago.cc_name || 'N/A');
            const emailEscaped = escapeHtml(pago.email || '');
            const referenceEscaped = escapeHtml(pago.reference || '');
            const authEscaped = escapeHtml(pago.auth || '');
            const ccMaskEscaped = escapeHtml(pago.cc_mask || '');
            const ccTypeEscaped = escapeHtml(pago.cc_type || '');
            
            const rawResponseJson = pago.raw_response ? JSON.stringify(pago.raw_response) : '{}';
            
            tbody.append(`
                <tr>
                    <td>${truncarTexto(folioEscaped || 'N/A', 12)}</td>
                    <td>${formatearFecha(pago.fecha_registro)}</td>
                    <td>${ccNameEscaped}</td>
                    <td>${emailEscaped ? `<a href="mailto:${emailEscaped}">${truncarTexto(emailEscaped, 20)}</a>` : 'N/A'}</td>
                    <td><span class="monto-positivo">${montoFormateado}</span></td>
                    <td><span class="badge bg-${estadoClass} badge-estado">${estadoText}</span></td>
                    <td>${referenceEscaped ? truncarTexto(referenceEscaped, 10) : 'N/A'}</td>
                    <td>${authEscaped ? `<span class="badge bg-success">${authEscaped}</span>` : 'N/A'}</td>
                    <td>${ccMaskEscaped ? `<span class="tooltip-custom" title="${ccTypeEscaped}"><i class="fas fa-credit-card me-1"></i>${ccMaskEscaped}</span>` : 'N/A'}</td>
                    <td>
                        <button class="btn btn-sm btn-info ver-detalle-liga"
                            data-id="${pago.id}"
                            data-folio='${folioEscaped}'
                            data-reference='${referenceEscaped}'
                            data-response="${pago.response}"
                            data-auth='${authEscaped}'
                            data-cc-name='${ccNameEscaped}'
                            data-email='${emailEscaped}'
                            data-amount="${pago.amount}"
                            data-cc-type='${ccTypeEscaped}'
                            data-cc-mask='${ccMaskEscaped}'
                            data-fecha="${formatearFecha(pago.fecha_registro)}"
                            data-raw-response='${rawResponseJson.replace(/'/g, "\\'")}'
                            data-cd-response="${escapeHtml(pago.cd_response || '')}"
                            data-cd-error="${escapeHtml(pago.cd_error || '')}"
                            data-nb-error="${escapeHtml(pago.nb_error || '')}"
                            data-nb-company="${escapeHtml(pago.nb_company || '')}"
                            data-nb-merchant="${escapeHtml(pago.nb_merchant || '')}">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    function actualizarCardsLiga(data) {
        const container = $('#ligaCardsBody');
        container.empty();
        
        if (data.pagos.length === 0) {
            container.html(`
                <div class="text-center py-4">
                    <div class="text-muted">
                        <i class="fas fa-link fa-3x d-block mb-3"></i>
                        No se encontraron pagos con Liga
                    </div>
                </div>
            `);
            return;
        }
        
        data.pagos.forEach(pago => {
            const estadoClass = getEstadoClass(pago.response);
            const estadoText = getEstadoText(pago.response);
            const montoFormateado = formatearMoneda(pago.amount, 'MXN');
            const fechaFormateada = formatearFecha(pago.fecha_registro);
            
            const folioEscaped = escapeHtml(pago.foliocpagos || '');
            const referenceEscaped = escapeHtml(pago.reference || '');
            const authEscaped = escapeHtml(pago.auth || '');
            const ccNameEscaped = escapeHtml(pago.cc_name || '');
            const emailEscaped = escapeHtml(pago.email || '');
            const ccMaskEscaped = escapeHtml(pago.cc_mask || '');
            const cdResponseEscaped = escapeHtml(pago.cd_response || '');
            const cdErrorEscaped = escapeHtml(pago.cd_error || '');
            const nbErrorEscaped = escapeHtml(pago.nb_error || '');
            
            const rawResponseJson = pago.raw_response ? JSON.stringify(pago.raw_response) : '{}';
            
            container.append(`
                <div class="pago-card">
                    <div class="pago-header">
                        <span class="pago-id">
                            <i class="fas fa-link me-1 text-info"></i>
                            ${truncarTexto(folioEscaped || 'Sin folio', 20)}
                        </span>
                        <span class="pago-fecha">${fechaFormateada}</span>
                    </div>
                    <div class="pago-info">
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-user me-1"></i> Nombre:</span>
                            <span class="pago-value">${ccNameEscaped || 'N/A'}</span>
                        </div>
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-envelope me-1"></i> Email:</span>
                            <span class="pago-value">${emailEscaped ? truncarTexto(emailEscaped, 25) : 'N/A'}</span>
                        </div>
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-money-bill-wave me-1"></i> Monto:</span>
                            <span class="pago-value pago-monto">${montoFormateado}</span>
                        </div>
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-qrcode me-1"></i> Referencia:</span>
                            <span class="pago-value pago-transaccion">${referenceEscaped ? truncarTexto(referenceEscaped, 15) : 'N/A'}</span>
                        </div>
                        ${ccMaskEscaped ? `
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-credit-card me-1"></i> Tarjeta:</span>
                            <span class="pago-value">${ccMaskEscaped}</span>
                        </div>
                        ` : ''}
                        ${authEscaped ? `
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-key me-1"></i> Autorización:</span>
                            <span class="pago-value"><span class="badge bg-success">${authEscaped}</span></span>
                        </div>
                        ` : ''}
                    </div>
                    <div class="pago-footer">
                        <span class="badge bg-${estadoClass} badge-estado-mobile">${estadoText}</span>
                        <button class="btn btn-sm btn-info btn-detalle-mobile ver-detalle-liga-mobile"
                            data-id="${pago.id}"
                            data-folio='${folioEscaped}'
                            data-reference='${referenceEscaped}'
                            data-response="${pago.response}"
                            data-auth='${authEscaped}'
                            data-cc-name='${ccNameEscaped}'
                            data-email='${emailEscaped}'
                            data-amount="${pago.amount}"
                            data-cc-type='${escapeHtml(pago.cc_type || '')}'
                            data-cc-mask='${ccMaskEscaped}'
                            data-fecha="${fechaFormateada}"
                            data-raw-response='${rawResponseJson.replace(/'/g, "\\'")}'
                            data-cd-response='${cdResponseEscaped}'
                            data-cd-error='${cdErrorEscaped}'
                            data-nb-error='${nbErrorEscaped}'>
                            <i class="fas fa-eye me-1"></i>Ver detalle
                        </button>
                    </div>
                </div>
            `);
        });
    }

    function actualizarTablaSpei(data) {
        const tbody = $('#speiTablaBody');
        tbody.empty();
        
        if (data.transacciones.length === 0) {
            tbody.html(`
                <tr>
                    <td colspan="9" class="text-center py-4">
                        <div class="text-muted">
                            <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                            No se encontraron transferencias SPEI
                        </div>
                    </td>
                </tr>
            `);
            return;
        }
        
        data.transacciones.forEach(trans => {
            // Determinar estado según el campo 'estado'
            const estadoClass = getEstadoClass(trans.estado);
            const estadoText = getEstadoText(trans.estado);
            
            const montoFormateado = formatearMoneda(trans.monto, 'MXN');
            const clabeEscaped = escapeHtml(trans.clabe);
            const transaccionExternaEscaped = escapeHtml(trans.transaccion_externa || 'N/A');
            const autorizacionEscaped = escapeHtml(trans.autorizacion || 'N/A');
            const nombreEmpresaEscaped = escapeHtml(trans.nombre_empresa || '');
            
            // Mostrar mensaje (priorizar nombre_empresa si existe)
            const mensajeMostrar = nombreEmpresaEscaped || 'Sin información';
            
            tbody.append(`
                <tr>
                    <td>${trans.id}</td>
                    <td>${formatearFecha(trans.fecha_solicitud)}</td>
                    <td><span class="tooltip-custom" title="${clabeEscaped}">${truncarTexto(clabeEscaped, 12)}</span></td>
                    <td><span class="monto-positivo">${montoFormateado}</span></td>
                    <td><span class="tooltip-custom" title="${transaccionExternaEscaped}">${truncarTexto(transaccionExternaEscaped, 15)}</span></td>
                    <td><span class="badge bg-${estadoClass} badge-estado">${estadoText}</span></td>
                    <td>${autorizacionEscaped !== 'N/A' ? `<span class="badge bg-success">${autorizacionEscaped}</span>` : 'N/A'}</td>
                    <td><span class="tooltip-custom" title="${mensajeMostrar}">${truncarTexto(mensajeMostrar, 20)}</span></td>
                    <td>
                        <button class="btn btn-sm btn-info ver-detalle-spei"
                            data-id="${trans.id}"
                            data-clabe="${clabeEscaped}"
                            data-monto="${trans.monto}"
                            data-transaccion-externa="${transaccionExternaEscaped}"
                            data-estado="${trans.estado}"
                            data-estado-texto="${estadoText}"
                            data-autorizacion="${autorizacionEscaped}"
                            data-nombre-empresa="${nombreEmpresaEscaped}"
                            data-fecha-solicitud="${formatearFecha(trans.fecha_solicitud)}"
                            data-fecha-confirmacion="${trans.fecha_confirmacion ? formatearFecha(trans.fecha_confirmacion) : 'No registrada'}">
                            <i class="fas fa-eye"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    function actualizarCardsSpei(data) {
        const container = $('#speiCardsBody');
        container.empty();
        
        if (data.transacciones.length === 0) {
            container.html(`
                <div class="text-center py-4">
                    <div class="text-muted">
                        <i class="fas fa-exchange-alt fa-3x d-block mb-3"></i>
                        No se encontraron transferencias SPEI
                    </div>
                </div>
            `);
            return;
        }
        
        data.transacciones.forEach(trans => {
            const estadoClass = getEstadoClass(trans.estado);
            const estadoText = getEstadoText(trans.estado);
            
            const montoFormateado = formatearMoneda(trans.monto, 'MXN');
            const fechaFormateada = formatearFecha(trans.fecha_solicitud);
            
            const clabeEscaped = escapeHtml(trans.clabe);
            const transaccionExternaEscaped = escapeHtml(trans.transaccion_externa || 'N/A');
            const autorizacionEscaped = escapeHtml(trans.autorizacion || 'N/A');
            const nombreEmpresaEscaped = escapeHtml(trans.nombre_empresa || '');
            
            container.append(`
                <div class="pago-card">
                    <div class="pago-header">
                        <span class="pago-id">
                            <i class="fas fa-exchange-alt me-1 text-warning"></i>
                            ID: ${trans.id}
                        </span>
                        <span class="pago-fecha">${fechaFormateada}</span>
                    </div>
                    <div class="pago-info">
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-university me-1"></i> CLABE:</span>
                            <span class="pago-value pago-transaccion">${truncarTexto(clabeEscaped, 20)}</span>
                        </div>
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-money-bill-wave me-1"></i> Monto:</span>
                            <span class="pago-value pago-monto">${montoFormateado}</span>
                        </div>
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-exchange-alt me-1"></i> Transacción:</span>
                            <span class="pago-value pago-transaccion">${truncarTexto(transaccionExternaEscaped, 20)}</span>
                        </div>
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-key me-1"></i> Autorización:</span>
                            <span class="pago-value">${autorizacionEscaped !== 'N/A' ? `<span class="badge bg-success">${autorizacionEscaped}</span>` : 'N/A'}</span>
                        </div>
                        ${nombreEmpresaEscaped ? `
                        <div class="pago-info-row">
                            <span class="pago-label"><i class="fas fa-building me-1"></i> Empresa:</span>
                            <span class="pago-value pago-transaccion">${truncarTexto(nombreEmpresaEscaped, 30)}</span>
                        </div>
                        ` : ''}
                    </div>
                    <div class="pago-footer">
                        <span class="badge bg-${estadoClass} badge-estado-mobile">${estadoText}</span>
                        <button class="btn btn-sm btn-info btn-detalle-mobile ver-detalle-spei-mobile"
                            data-id="${trans.id}"
                            data-clabe="${clabeEscaped}"
                            data-monto="${trans.monto}"
                            data-transaccion-externa="${transaccionExternaEscaped}"
                            data-estado="${trans.estado}"
                            data-estado-texto="${estadoText}"
                            data-autorizacion="${autorizacionEscaped}"
                            data-nombre-empresa="${nombreEmpresaEscaped}"
                            data-fecha-solicitud="${fechaFormateada}"
                            data-fecha-confirmacion="${trans.fecha_confirmacion ? formatearFecha(trans.fecha_confirmacion) : 'No registrada'}">
                            <i class="fas fa-eye me-1"></i>Ver detalle
                        </button>
                    </div>
                </div>
            `);
        });
    }

    // =============================================
    // FUNCIONES DE PAGINACIÓN
    // =============================================

    function actualizarPaginacionLiga(totalPaginas, paginaActual, totalRegistros) {
        const container = $('#ligaPaginacion');
        
        if (totalPaginas <= 1 && totalRegistros <= registrosPorPagina) {
            container.hide();
            return;
        }
        
        container.show();
        
        let html = '<nav aria-label="Paginación Liga"><ul class="pagination justify-content-center flex-wrap">';
        
        html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="1">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                 </li>`;
        
        html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="${paginaActual - 1}">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                 </li>`;
        
        let startPage = Math.max(1, paginaActual - 2);
        let endPage = Math.min(totalPaginas, startPage + 4);
        
        if (endPage - startPage < 4 && startPage > 1) {
            startPage = Math.max(1, endPage - 4);
        }
        
        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i == paginaActual ? 'active' : ''}">
                        <a class="page-link" href="#" data-pagina="${i}">${i}</a>
                     </li>`;
        }
        
        html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="${paginaActual + 1}">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                 </li>`;
        
        html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="${totalPaginas}">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                 </li>`;
        
        html += '</ul>';
        
        const desde = ((paginaActual - 1) * registrosPorPagina) + 1;
        const hasta = Math.min(paginaActual * registrosPorPagina, totalRegistros);
        html += `<div class="text-center text-muted mt-2 small">
                    Mostrando ${desde} - ${hasta} de ${totalRegistros} registros
                </div>`;
        
        html += '</nav>';
        container.html(html);
        
        container.find('.page-link').click(function(e) {
            e.preventDefault();
            const nuevaPagina = parseInt($(this).data('pagina'));
            if (!isNaN(nuevaPagina) && nuevaPagina != paginaLiga && nuevaPagina >= 1 && nuevaPagina <= totalPaginas) {
                paginaLiga = nuevaPagina;
                cargarLiga();
                $('html, body').animate({
                    scrollTop: $('#liga').offset().top - 100
                }, 300);
            }
        });
    }

    function actualizarPaginacionSpei(totalPaginas, paginaActual, totalRegistros) {
        const container = $('#speiPaginacion');
        
        if (totalPaginas <= 1 && totalRegistros <= registrosPorPagina) {
            container.hide();
            return;
        }
        
        container.show();
        
        let html = '<nav aria-label="Paginación SPEI"><ul class="pagination justify-content-center flex-wrap">';
        
        html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="1">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                 </li>`;
        
        html += `<li class="page-item ${paginaActual == 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="${paginaActual - 1}">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                 </li>`;
        
        let startPage = Math.max(1, paginaActual - 2);
        let endPage = Math.min(totalPaginas, startPage + 4);
        
        if (endPage - startPage < 4 && startPage > 1) {
            startPage = Math.max(1, endPage - 4);
        }
        
        for (let i = startPage; i <= endPage; i++) {
            html += `<li class="page-item ${i == paginaActual ? 'active' : ''}">
                        <a class="page-link" href="#" data-pagina="${i}">${i}</a>
                     </li>`;
        }
        
        html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="${paginaActual + 1}">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                 </li>`;
        
        html += `<li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-pagina="${totalPaginas}">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                 </li>`;
        
        html += '</ul>';
        
        const desde = ((paginaActual - 1) * registrosPorPagina) + 1;
        const hasta = Math.min(paginaActual * registrosPorPagina, totalRegistros);
        html += `<div class="text-center text-muted mt-2 small">
                    Mostrando ${desde} - ${hasta} de ${totalRegistros} registros
                </div>`;
        
        html += '</nav>';
        container.html(html);
        
        container.find('.page-link').click(function(e) {
            e.preventDefault();
            const nuevaPagina = parseInt($(this).data('pagina'));
            if (!isNaN(nuevaPagina) && nuevaPagina != paginaSpei && nuevaPagina >= 1 && nuevaPagina <= totalPaginas) {
                paginaSpei = nuevaPagina;
                cargarSpei();
                $('html, body').animate({
                    scrollTop: $('#spei').offset().top - 100
                }, 300);
            }
        });
    }

    // =============================================
    // FUNCIONES AUXILIARES
    // =============================================

    function getEstadoClass(estado) {
        const estadoLower = String(estado).toLowerCase();
        if (['completed', 'approved', 'a', 'aprobado', 'success', 'confirmado'].includes(estadoLower)) {
            return 'success';
        }
        if (['pending', 'p', 'pendiente'].includes(estadoLower)) {
            return 'warning';
        }
        if (['created'].includes(estadoLower)) {
            return 'info';
        }
        if (['failed', 'denied', 'd', 'declined', 'rejected', 'fallido', 'cancelled', 'expired', 'c', 'error', 'rechazado', 'cancelado'].includes(estadoLower)) {
            return 'danger';
        }
        return 'secondary';
    }

    function getEstadoText(estado) {
        const estadoLower = String(estado).toLowerCase();
        const estados = {
            'completed': 'Completado',
            'approved': 'Completado',
            'a': 'Completado',
            'aprobado': 'Completado',
            'success': 'Completado',
            'confirmado': 'Completado',
            'pending': 'Pendiente',
            'p': 'Pendiente',
            'pendiente': 'Pendiente',
            'created': 'Creado',
            'failed': 'Fallido',
            'denied': 'Fallido',
            'd': 'Fallido',
            'declined': 'Fallido',
            'rejected': 'Fallido',
            'fallido': 'Fallido',
            'cancelled': 'Cancelado',
            'c': 'Cancelado',
            'expired': 'Expirado',
            'error': 'Error',
            'rechazado': 'Rechazado',
            'cancelado': 'Cancelado'
        };
        return estados[estadoLower] || estado;
    }

    function formatearFecha(fecha) {
        if (!fecha || fecha === '0000-00-00 00:00:00') return 'No registrada';
        const date = new Date(fecha);
        return date.toLocaleDateString('es-MX') + ' ' + date.toLocaleTimeString('es-MX', {hour: '2-digit', minute:'2-digit'});
    }

    function formatearMoneda(monto, moneda = 'MXN') {
        if (monto === null || monto === undefined) return 'N/A';
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: moneda,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(monto);
    }

    function truncarTexto(texto, longitud = 30) {
        if (!texto || texto.length <= longitud) return texto;
        return texto.substring(0, longitud) + '...';
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // =============================================
    // FUNCIONES DE MODALES
    // =============================================

    function safeJSONParse(str) {
        if (!str || str === '{}' || str === 'null' || str === 'undefined') return {};
        try {
            if (typeof str === 'object') return str;
            return JSON.parse(str);
        } catch (e) {
            console.error('Error parsing JSON:', e);
            return {};
        }
    }

    function generarContenidoModalLiga(datos) {
        const estadoClass = getEstadoClass(datos.response);
        const estadoText = getEstadoText(datos.response);
        
        return `
            <div class="container-fluid px-0">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información del Pago</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th class="text-muted">Folio:</th>
                                        <td><code>${escapeHtml(datos.folio || 'N/A')}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Referencia:</th>
                                        <td><code>${escapeHtml(datos.reference || 'N/A')}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Estado:</th>
                                        <td><span class="badge bg-${estadoClass}">${estadoText}</span></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Código Autorización:</th>
                                        <td><span class="badge bg-success">${escapeHtml(datos.auth || 'N/A')}</span></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Fecha:</th>
                                        <td>${datos.fecha}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-user me-2 text-primary"></i>Información del Pagador</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th class="text-muted">Nombre:</th>
                                        <td class="fw-bold">${escapeHtml(datos.cc_name || 'N/A')}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Email:</th>
                                        <td><a href="mailto:${escapeHtml(datos.email)}">${escapeHtml(datos.email || 'N/A')}</a></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">ID Interno:</th>
                                        <td><small class="text-muted">${datos.id}</small></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-credit-card me-2 text-primary"></i>Información de la Tarjeta</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th class="text-muted">Tipo:</th>
                                        <td>${escapeHtml(datos.cc_type || 'N/A')}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Número:</th>
                                        <td>${escapeHtml(datos.cc_mask || 'N/A')}</td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Titular:</th>
                                        <td>${escapeHtml(datos.cc_name || 'N/A')}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2 text-success"></i>Monto</h6>
                            </div>
                            <div class="card-body">
                                <div class="text-center p-4 bg-success text-white rounded">
                                    <small class="text-white-50 d-block">Total</small>
                                    <span class="h2 mb-0">${formatearMoneda(datos.amount, 'MXN')}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                ${(datos.cd_response || datos.cd_error || datos.nb_error) ? `
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-exchange-alt me-2 text-info"></i>Detalles de la Transacción</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    ${datos.cd_response ? `<div class="col-md-4 mb-3"><div class="p-3 bg-light rounded"><small class="text-muted d-block">Código Respuesta</small><span class="h5 mb-0">${escapeHtml(datos.cd_response)}</span></div></div>` : ''}
                                    ${datos.cd_error ? `<div class="col-md-4 mb-3"><div class="p-3 bg-light rounded"><small class="text-muted d-block">Código Error</small><span class="h5 mb-0 text-danger">${escapeHtml(datos.cd_error)}</span></div></div>` : ''}
                                    ${datos.nb_error ? `<div class="col-md-4 mb-3"><div class="p-3 bg-light rounded"><small class="text-muted d-block">Mensaje Error</small><span class="h6 mb-0">${escapeHtml(datos.nb_error)}</span></div></div>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                ` : ''}
                ${generarAccordionJSON(datos.raw_response, 'Respuesta Completa (raw_response)', 'fa-database', 'collapseRawResponse')}
            </div>
        `;
    }

    function generarContenidoModalSpei(datos) {
        const estadoClass = getEstadoClass(datos.estado);
        const estadoText = getEstadoText(datos.estado);
        
        return `
            <div class="container-fluid px-0">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Información de la Transferencia</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th class="text-muted">ID:</th>
                                        <td><code>${datos.id}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">CLABE:</th>
                                        <td><code>${escapeHtml(datos.clabe)}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Monto:</th>
                                        <td><span class="h5 mb-0 text-success">${formatearMoneda(datos.monto, 'MXN')}</span></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Estado:</th>
                                        <td><span class="badge bg-${estadoClass}">${estadoText}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i>Datos de la Transacción</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-sm table-borderless">
                                    <tr>
                                        <th class="text-muted">Transacción:</th>
                                        <td><code>${escapeHtml(datos.transaccion_externa)}</code></td>
                                    </tr>
                                    <tr>
                                        <th class="text-muted">Autorización:</th>
                                        <td>${datos.autorizacion !== 'N/A' ? `<span class="badge bg-success">${escapeHtml(datos.autorizacion)}</span>` : 'N/A'}</td>
                                    </tr>
                                    ${datos.nombre_empresa ? `
                                    <tr>
                                        <th class="text-muted">Empresa:</th>
                                        <td class="fw-bold">${escapeHtml(datos.nombre_empresa)}</td>
                                    </tr>
                                    ` : ''}
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-calendar-alt me-2 text-primary"></i>Fechas</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <small class="text-muted d-block">Fecha Recibido</small>
                                            <span class="fw-bold">${datos.fecha_solicitud}</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <div class="p-3 bg-light rounded text-center">
                                            <small class="text-muted d-block">Fecha Confirmación</small>
                                            <span class="fw-bold">${datos.fecha_confirmacion}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function generarAccordionJSON(jsonString, titulo, icono, targetId) {
        try {
            if (!jsonString || jsonString === '{}' || jsonString === 'null' || jsonString === '') {
                return '';
            }
            let jsonData;
            if (typeof jsonString === 'string') {
                jsonData = JSON.parse(jsonString);
            } else {
                jsonData = jsonString;
            }
            if (Object.keys(jsonData).length === 0) {
                return '';
            }
            return `
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <button class="btn btn-link text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#${targetId}" aria-expanded="false">
                                <i class="fas ${icono} me-2 text-primary"></i>${titulo}
                            </button>
                        </h6>
                    </div>
                    <div id="${targetId}" class="collapse">
                        <div class="card-body">
                            <pre class="bg-light p-3 rounded mb-0" style="max-height: 300px; overflow-y: auto;"><code>${JSON.stringify(jsonData, null, 2)}</code></pre>
                        </div>
                    </div>
                </div>
            `;
        } catch (e) {
            console.error('Error parsing JSON:', e);
            return '';
        }
    }

    function mostrarErrorLiga(mensaje) {
        $('#ligaTablaBody').html(`
            <tr>
                <td colspan="10" class="text-center py-4">
                    <div class="text-danger">${mensaje}</div>
                </td>
            </tr>
        `);
        $('#ligaCardsBody').html(`
            <div class="text-center py-4">
                <div class="text-danger">${mensaje}</div>
            </div>
        `);
    }

    function mostrarErrorSpei(mensaje) {
        $('#speiTablaBody').html(`
            <tr>
                <td colspan="9" class="text-center py-4">
                    <div class="text-danger">${mensaje}</div>
                </td>
            </tr>
        `);
        $('#speiCardsBody').html(`
            <div class="text-center py-4">
                <div class="text-danger">${mensaje}</div>
            </div>
        `);
    }

    // =============================================
    // EVENTOS
    // =============================================

    $(document).ready(function() {
        // Cargar datos iniciales
        cargarLiga();
        
        // Registros por página
        $('#registrosPorPagina').on('change', function() {
            registrosPorPagina = parseInt($(this).val());
            paginaLiga = 1;
            paginaSpei = 1;
            if (tabActiva === 'liga') {
                cargarLiga();
            } else {
                cargarSpei();
            }
        });

        // Filtros
        $('#filtroEstado, #filtroFechaInicio, #filtroFechaFin').on('change', function() {
            paginaLiga = 1;
            paginaSpei = 1;
            if (tabActiva === 'liga') {
                cargarLiga();
            } else {
                cargarSpei();
            }
        });

        // Limpiar filtros
        $('#btnLimpiarFiltros').click(function() {
            $('#filtroEstado').val('');
            $('#filtroFechaInicio').val('');
            $('#filtroFechaFin').val('');
            $('#busquedaLiga').val('');
            $('#busquedaSpei').val('');
            $('#btnLimpiarBusquedaLiga').hide();
            $('#btnLimpiarBusquedaSpei').hide();
            paginaLiga = 1;
            paginaSpei = 1;
            if (tabActiva === 'liga') {
                cargarLiga();
            } else {
                cargarSpei();
            }
        });

        // Búsqueda Liga
        $('#btnBuscarLiga').click(function() {
            paginaLiga = 1;
            cargarLiga();
            $('#btnLimpiarBusquedaLiga').show();
        });

        $('#busquedaLiga').on('keypress', function(e) {
            if (e.which === 13) {
                $('#btnBuscarLiga').click();
            }
        });

        $('#btnLimpiarBusquedaLiga').click(function() {
            $('#busquedaLiga').val('');
            $(this).hide();
            paginaLiga = 1;
            cargarLiga();
        });

        // Búsqueda SPEI
        $('#btnBuscarSpei').click(function() {
            paginaSpei = 1;
            cargarSpei();
            $('#btnLimpiarBusquedaSpei').show();
        });

        $('#busquedaSpei').on('keypress', function(e) {
            if (e.which === 13) {
                $('#btnBuscarSpei').click();
            }
        });

        $('#btnLimpiarBusquedaSpei').click(function() {
            $('#busquedaSpei').val('');
            $(this).hide();
            paginaSpei = 1;
            cargarSpei();
        });

        // Cambio de tab
        $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
            const targetId = $(e.target).attr('data-bs-target');
            tabActiva = targetId === '#liga' ? 'liga' : 'spei';
            
            if (tabActiva === 'liga') {
                if ($('#ligaTablaBody').find('tr').length === 1 && $('#ligaTablaBody').find('td').text().includes('Selecciona filtros')) {
                    cargarLiga();
                }
            } else {
                if ($('#speiTablaBody').find('tr').length === 1 && $('#speiTablaBody').find('td').text().includes('Selecciona filtros')) {
                    cargarSpei();
                }
            }
        });

        // Eventos para botones de detalle Liga
        $(document).on('click', '.ver-detalle-liga, .ver-detalle-liga-mobile', function() {
            const button = $(this);
            const modal = $('#modalDetalleLiga');
            
            modal.find('#detalleLigaCargando').show();
            modal.find('#detalleLigaContenido').hide().empty();
            
            const datos = {
                id: button.data('id'),
                folio: button.data('folio'),
                reference: button.data('reference'),
                response: button.data('response'),
                auth: button.data('auth'),
                cc_name: button.data('cc-name'),
                email: button.data('email'),
                amount: button.data('amount'),
                cc_type: button.data('cc-type'),
                cc_mask: button.data('cc-mask'),
                fecha: button.data('fecha'),
                raw_response: safeJSONParse(button.data('raw-response')),
                cd_response: button.data('cd-response'),
                cd_error: button.data('cd-error'),
                nb_error: button.data('nb-error'),
                nb_company: button.data('nb-company'),
                nb_merchant: button.data('nb-merchant')
            };
            
            const contenido = generarContenidoModalLiga(datos);
            
            setTimeout(() => {
                modal.find('#detalleLigaCargando').hide();
                modal.find('#detalleLigaContenido').html(contenido).show();
                modal.modal('show');
            }, 300);
        });

        // Eventos para botones de detalle SPEI
        $(document).on('click', '.ver-detalle-spei, .ver-detalle-spei-mobile', function() {
            const button = $(this);
            const modal = $('#modalDetalleSpei');
            
            modal.find('#detalleSpeiCargando').show();
            modal.find('#detalleSpeiContenido').hide().empty();
            
            const datos = {
                id: button.data('id'),
                clabe: button.data('clabe'),
                monto: button.data('monto'),
                transaccion_externa: button.data('transaccion-externa'),
                estado: button.data('estado'),
                estado_texto: button.data('estado-texto'),
                autorizacion: button.data('autorizacion'),
                nombre_empresa: button.data('nombre-empresa'),
                fecha_solicitud: button.data('fecha-solicitud'),
                fecha_confirmacion: button.data('fecha-confirmacion')
            };
            
            const contenido = generarContenidoModalSpei(datos);
            
            setTimeout(() => {
                modal.find('#detalleSpeiCargando').hide();
                modal.find('#detalleSpeiContenido').html(contenido).show();
                modal.modal('show');
            }, 300);
        });
    });

    console.log('Pagos JS cargado correctamente');

})();