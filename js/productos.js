
        $(document).ready(function() {
            // =============================================
            // VARIABLES GLOBALES
            // =============================================
            let searchTimeout = null;
            let isSearching = false;
            let currentPage = 1;
            let currentSearch = '';
            let cargandoProductos = false;

            let imagenesExistentes = [];
            let nuevasImagenes = [];
            let reglasMayoreo = [];
            let mayoreoHabilitado = false;

            let filtrosActuales = {
                search: '',
                categoria: '',
                proveedor: '',
                sucursal: '',
                show_inactive: false,
                pagina: 1
            };

            // =============================================
            // FUNCIONES DE FILTRADO AJAX
            // =============================================

            function obtenerValoresFiltros() {
                return {
                    search: $('#searchInput').val(),
                    categoria: $('#filterCategoria').val(),
                    proveedor: $('#filterProveedor').val(),
                    sucursal: $('#filterSucursal').val(),
                    show_inactive: $('#showInactive').is(':checked'),
                    pagina: filtrosActuales.pagina
                };
            }

            function sincronizarFiltrosMoviles() {
                $('#searchInputMobile').val(filtrosActuales.search);
                $('#filterCategoriaMobile').val(filtrosActuales.categoria);
                $('#filterProveedorMobile').val(filtrosActuales.proveedor);
                $('#filterSucursalMobile').val(filtrosActuales.sucursal);
                $('#showInactiveMobile').prop('checked', filtrosActuales.show_inactive);
            }

            function actualizarFiltrosDesdeMoviles() {
                $('#searchInput').val($('#searchInputMobile').val());
                $('#filterCategoria').val($('#filterCategoriaMobile').val());
                $('#filterProveedor').val($('#filterProveedorMobile').val());
                $('#filterSucursal').val($('#filterSucursalMobile').val());
                $('#showInactive').prop('checked', $('#showInactiveMobile').is(':checked'));
            }

            function mostrarCargando(mostrar) {
                if (mostrar) {
                    cargandoProductos = true;
                    $('#searchLoading').show();
                    $('#productsTableBody').html(`
                <tr>
                    <td colspan="14" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2 text-muted">Cargando productos...</p>
                    </td>
                </tr>
            `);
                    $('#mobileProductsContainer').empty();
                    $('#mobileProductsContainer').append(`
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="mt-2 text-muted">Cargando productos...</p>
                </div>
            `);
                } else {
                    cargandoProductos = false;
                    $('#searchLoading').hide();
                    $('#mobileProductsContainer .spinner-border').closest('.text-center').remove();
                }
            }

            function mostrarMensajeTemporal(mensaje, tipo) {
                const alertDiv = $(`
            <div class="alert alert-${tipo} alert-dismissible fade show" role="alert" style="position: fixed; top: 70px; right: 20px; z-index: 9999; min-width: 250px; z-index: 1060;">
                <i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'} me-2"></i>
                ${mensaje}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `);
                $('body').append(alertDiv);
                setTimeout(() => {
                    alertDiv.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 3000);
            }

            function formatearStockJS(stock, unidad) {
                if (stock === undefined || stock === null) stock = 0;
                let stockFormateado;
                if (stock % 1 !== 0) {
                    stockFormateado = parseFloat(stock).toFixed(3).replace(/\.?0+$/, '');
                } else {
                    stockFormateado = Math.floor(stock);
                }
                let sufijo = '';
                switch (unidad) {
                    case 'kg':
                    case 'kilo':
                    case 'kilogramo':
                        sufijo = ' kg';
                        break;
                    case 'litro':
                    case 'l':
                        sufijo = ' L';
                        break;
                    case 'tonelada':
                    case 'ton':
                        sufijo = ' ton';
                        break;
                    case 'pieza':
                        sufijo = stock == 1 ? ' pieza' : ' piezas';
                        break;
                    case 'unidad':
                        sufijo = stock == 1 ? ' unidad' : ' unidades';
                        break;
                    default:
                        sufijo = '';
                }
                return stockFormateado + sufijo;
            }

            // Agregar badge flotante de ayuda (solo para PC, primera visita)
function mostrarAyudaClickeable() {
    // Verificar si ya se mostró antes
    if (!localStorage.getItem('clickHintShown')) {
        const hintBadge = $('<div class="click-hint-badge"><i class="fas fa-mouse-pointer"></i> Haz clic en cualquier producto para ver/editar detalles</div>');
        $('body').append(hintBadge);
        
        // Auto-ocultar después de 5 segundos
        setTimeout(() => {
            hintBadge.addClass('fade-out');
            setTimeout(() => hintBadge.remove(), 1000);
        }, 5000);
        
        // También ocultar al hacer clic en cualquier producto
        $(document).one('click', '.producto-row, .producto-card-mobile', function() {
            hintBadge.addClass('fade-out');
            setTimeout(() => hintBadge.remove(), 500);
        });
        
        localStorage.setItem('clickHintShown', 'true');
    }
}

// Llamar a la función después de 1 segundo
setTimeout(mostrarAyudaClickeable, 1000);

// Agregar título/tooltip nativo para dispositivos que lo soporten
$('.producto-row, .producto-card-mobile').attr('title', 'Haz clic para ver/editar detalles del producto');

            function formatearFechaCaducidadJS(fecha) {
                if (!fecha) return '<span class="text-muted small">N/A</span>';
                const fechaObj = new Date(fecha);
                const hoy = new Date();
                hoy.setHours(0, 0, 0, 0);
                const diffDays = Math.ceil((fechaObj - hoy) / (1000 * 60 * 60 * 24));
                if (diffDays < 0) {
                    return '<span class="badge bg-danger" title="Producto vencido"><i class="fas fa-exclamation-triangle"></i> Vencido</span>';
                } else if (diffDays <= 7) {
                    const dia = fechaObj.getDate().toString().padStart(2, '0');
                    const mes = (fechaObj.getMonth() + 1).toString().padStart(2, '0');
                    const anio = fechaObj.getFullYear();
                    return `<span class="badge bg-warning" title="${diffDays} días para vencer"><i class="fas fa-clock"></i> ${dia}/${mes}/${anio}</span>`;
                } else {
                    const dia = fechaObj.getDate().toString().padStart(2, '0');
                    const mes = (fechaObj.getMonth() + 1).toString().padStart(2, '0');
                    const anio = fechaObj.getFullYear();
                    return `<span class="badge bg-light text-dark">${dia}/${mes}/${anio}</span>`;
                }
            }

            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function ucfirst(str) {
                if (!str) return '';
                return str.charAt(0).toUpperCase() + str.slice(1);
            }

            function actualizarTablaProductos(response) {
                const tbody = $('#productsTableBody');
                tbody.empty();

                if (!response.productos || response.productos.length === 0) {
                    tbody.html(`
                <tr>
                    <td colspan="14" class="text-center text-muted py-4">
                        <i class="fas fa-box fa-3x mb-3"></i>
                        <p>No se encontraron productos</p>
                    </td>
                </tr>
            `);
                    return;
                }

                response.productos.forEach(producto => {
                    let imagenesHtml = '';
                    if (producto.imagenes && producto.imagenes.length > 0) {
                        imagenesHtml = `
                    <div id="carouselSmall-${producto.id}" class="carousel slide producto-imagen-carousel" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-inner">
                            ${producto.imagenes.map((img, idx) => `
                                <div class="carousel-item ${idx === 0 ? 'active' : ''}">
                                    <img src="${img.ruta_imagen}" class="d-block w-100" alt="${escapeHtml(producto.nombre)}" onclick="abrirCarruselAmpliado('${producto.id}', ${idx}, event)">
                                </div>
                            `).join('')}
                        </div>
                        ${producto.imagenes.length > 1 ? `
                            <button class="carousel-control-prev" type="button" data-bs-target="#carouselSmall-${producto.id}" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Anterior</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#carouselSmall-${producto.id}" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Siguiente</span>
                            </button>
                            <div class="carousel-indicators">
                                ${producto.imagenes.map((_, idx) => `
                                    <button type="button" data-bs-target="#carouselSmall-${producto.id}" data-bs-slide-to="${idx}" class="${idx === 0 ? 'active' : ''}" aria-label="Slide ${idx + 1}"></button>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>
                `;
                    } else {
                        imagenesHtml = `
                    <div class="producto-imagen bg-light d-flex align-items-center justify-content-center no-imagen-container" style="width: 60px; height: 60px; cursor: pointer;" onclick="abrirCarruselAmpliado('${producto.id}', 0, event)">
                        <i class="fas fa-image text-muted"></i>
                    </div>
                `;
                    }

                    let unidadBadgeClass = 'unidad-pieza';
                    switch (producto.unidad_medida) {
                        case 'kilo':
                            unidadBadgeClass = 'unidad-kilo';
                            break;
                        case 'litro':
                            unidadBadgeClass = 'unidad-litro';
                            break;
                    }

                    let precioFinal = producto.precio;
                    if (producto.descuento > 0 && producto.subprecio > 0) {
                        precioFinal = producto.subprecio - (producto.subprecio * (producto.descuento / 100));
                    }

                    let stockFormateado = formatearStockJS(producto.stock_total, producto.unidad_medida);
                    let stockBadgeClass = 'bg-success';
                    if (producto.stock_total <= 0) stockBadgeClass = 'bg-danger';
                    else if (producto.stock_total <= response.stock_minimo_global) stockBadgeClass = 'bg-warning';

                    const row = `
                <tr data-categoria="${producto.categoria_id || ''}" data-proveedor="${producto.proveedor_id || ''}" data-activo="${producto.activo}" class="producto-row">
                    <td>${imagenesHtml}</td>
                    <td><strong>${escapeHtml(producto.codigo)}</strong>${producto.tiene_mayoreo ? '<span class="badge mayoreo-badge ms-1" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;"><i class="fas fa-tags"></i> Mayoreo</span>' : ''}</td>
                    <td><div><strong>${escapeHtml(producto.nombre)}</strong>${producto.descripcion ? `<br><small class="text-muted">${escapeHtml(producto.descripcion)}</small>` : ''}</div></td>
                    <td><span class="badge unidad-medida-badge ${unidadBadgeClass}">${ucfirst(producto.unidad_medida || 'pieza')}</span></td>
                    <td>${escapeHtml(producto.marca || 'N/A')}</td>
                    <td>${escapeHtml(producto.categoria_nombre || 'Sin categoría')}</td>
                    <td><span class="badge badge-subprecio">$${parseFloat(producto.subprecio || 0).toFixed(2)}</span></td>
                    <td>${producto.descuento > 0 ? `<span class="badge badge-descuento">-${parseFloat(producto.descuento).toFixed(0)}%</span>` : '<span class="text-muted">0%</span>'}</td>
                    <td><span class="badge badge-precio ${producto.descuento > 0 ? 'text-danger fw-bold' : ''}">$${precioFinal.toFixed(2)}</span></td>
                    <td><span class="badge ${stockBadgeClass} badge-stock">${stockFormateado}</span><br><small class="text-muted">Mín: ${response.stock_minimo_global}</small>${producto.porcentaje_merma_danado > 0 || producto.porcentaje_merma_deshidratacion > 0 ? `<br><small class="text-muted merma-badge">Merma: ${parseFloat(producto.porcentaje_merma_danado) + parseFloat(producto.porcentaje_merma_deshidratacion)}%</small>` : ''}</td>
                    <td>${formatearFechaCaducidadJS(producto.fecha_caducidad)}</td>
                    <td><span class="status-badge ${producto.activo ? 'status-active' : 'status-inactive'}">${producto.activo ? 'Activo' : 'Inactivo'}</span>
                        <button class="btn btn-outline-primary btn-sm edit-producto d-none" data-id="${producto.id}" data-activo="${producto.activo}" data-codigo="${escapeHtml(producto.codigo)}" data-nombre="${escapeHtml(producto.nombre)}" data-descripcion="${escapeHtml(producto.descripcion || '')}" data-marca="${escapeHtml(producto.marca || '')}" data-precio="${precioFinal}" data-subprecio="${producto.subprecio}" data-descuento="${producto.descuento}" data-costo="${producto.costo}" data-categoria_id="${producto.categoria_id || ''}" data-proveedor_id="${producto.proveedor_id || ''}" data-unidad_medida="${producto.unidad_medida}" data-peso_kg="${producto.peso_kg}" data-permite_fracciones="${producto.permite_fracciones}" data-fecha_caducidad="${producto.fecha_caducidad || ''}" data-tipo_producto="${escapeHtml(producto.tipo_producto || 'Estandar')}" data-porcentaje_merma_danado="${producto.porcentaje_merma_danado}" data-porcentaje_merma_deshidratacion="${producto.porcentaje_merma_deshidratacion}" data-aplicar_merma_venta="${producto.aplicar_merma_venta}" data-aplicar_merma_compra="${producto.aplicar_merma_compra}" data-imagenes='${JSON.stringify(producto.imagenes || [])}' data-sucursales='${producto.sucursales_ids || ""}' data-precios-mayoreo='${JSON.stringify(producto.precios_mayoreo || [])}' data-stocks='${JSON.stringify(producto.stocks_por_sucursal || {})}' title="Editar"></button>
                    </td>
                </tr>
            `;
                    tbody.append(row);
                });

                reinicializarEventosProductos();
            }

            function cargarListaProductosStats(tipo, modalId, containerId) {
    $.ajax({
        url: 'ajax_productos_stats.php',
        type: 'GET',
        data: { tipo: tipo },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.productos && response.productos.length > 0) {
                let html = '<ul class="lista-productos-stats">';
                response.productos.forEach(function(producto) {
                    let stockClass = '';
                    let stockText = '';
                    if (producto.stock_total <= 0) {
                        stockClass = 'stock-cero-stats';
                        stockText = 'Sin stock';
                    } else if (producto.stock_total <= response.stock_minimo) {
                        stockClass = 'stock-bajo-stats';
                        stockText = 'Stock bajo: ' + formatearStockStats(producto.stock_total, producto.unidad_medida);
                    } else {
                        stockClass = 'stock-normal-stats';
                        stockText = 'Stock: ' + formatearStockStats(producto.stock_total, producto.unidad_medida);
                    }
                    
                    html += `
                        <li>
                            <div style="flex: 1;">
                                <div class="producto-nombre-stats">${escapeHtml(producto.nombre)}</div>
                                <div class="producto-codigo-stats">${escapeHtml(producto.codigo)}</div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge-unidad-stats">${escapeHtml(producto.unidad_medida || 'pieza')}</span>
                                <span class="producto-stock-stats ${stockClass}">${stockText}</span>
                            </div>
                        </li>
                    `;
                });
                html += '</ul>';
                $(containerId).html(html);
            } else {
                $(containerId).html(`
                    <div class="empty-state-stats">
                        <i class="fas fa-box-open"></i>
                        <p>No hay productos en esta categoría</p>
                    </div>
                `);
            }
        },
        error: function() {
            $(containerId).html(`
                <div class="empty-state-stats">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error al cargar los productos</p>
                </div>
            `);
        }
    });
}

function formatearStockStats(stock, unidad) {
    if (stock === undefined || stock === null) stock = 0;
    let stockFormateado;
    if (stock % 1 !== 0) {
        stockFormateado = parseFloat(stock).toFixed(3).replace(/\.?0+$/, '');
    } else {
        stockFormateado = Math.floor(stock);
    }
    
    let sufijo = '';
    switch (unidad) {
        case 'kg': case 'kilo': case 'kilogramo':
            sufijo = ' kg';
            break;
        case 'litro': case 'l':
            sufijo = ' L';
            break;
        case 'tonelada': case 'ton':
            sufijo = ' ton';
            break;
        case 'pieza':
            sufijo = stock == 1 ? ' pieza' : ' piezas';
            break;
        default:
            sufijo = '';
    }
    return stockFormateado + sufijo;
}

// Eventos click en las tarjetas de estadísticas
$('.stat-card').on('click', function(e) {
    e.stopPropagation();
    const card = $(this);
    const label = card.find('.metric-label').text().trim();
    const value = card.find('.metric-value').text().trim();
    
    switch(label) {
        case 'Total Productos':
            $('#listaTotalProductos').html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalTotalProductos').modal('show');
            cargarListaProductosStats('total', '#modalTotalProductos', '#listaTotalProductos');
            break;
        case 'Con Stock':
            $('#listaConStock').html('<div class="text-center py-4"><div class="spinner-border text-success" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalConStock').modal('show');
            cargarListaProductosStats('con_stock', '#modalConStock', '#listaConStock');
            break;
        case 'Stock Bajo':
            $('#listaStockBajo').html('<div class="text-center py-4"><div class="spinner-border text-warning" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalStockBajo').modal('show');
            cargarListaProductosStats('bajo_stock', '#modalStockBajo', '#listaStockBajo');
            break;
        case 'Sin Stock':
            $('#listaSinStock').html('<div class="text-center py-4"><div class="spinner-border text-danger" role="status"><span class="visually-hidden">Cargando...</span></div><p class="mt-2 text-muted">Cargando productos...</p></div>');
            $('#modalSinStock').modal('show');
            cargarListaProductosStats('sin_stock', '#modalSinStock', '#listaSinStock');
            break;
    }
});

// Agregar cursor pointer a las tarjetas de estadísticas
$('.stat-card').css('cursor', 'pointer');

            function actualizarTarjetasMoviles(response) {
                const container = $('#mobileProductsContainer');
                container.empty();

                if (!response.productos || response.productos.length === 0) {
                    container.append(`
                <div class="card text-center text-muted py-4">
                    <i class="fas fa-box fa-3x mb-3"></i>
                    <p>No se encontraron productos</p>
                </div>
            `);
                    return;
                }

                response.productos.forEach(producto => {
                    let unidadBadgeClass = 'unidad-pieza';
                    switch (producto.unidad_medida) {
                        case 'kilo':
                            unidadBadgeClass = 'unidad-kilo';
                            break;
                        case 'litro':
                            unidadBadgeClass = 'unidad-litro';
                            break;
                    }

                    let precioFinal = producto.precio;
                    if (producto.descuento > 0 && producto.subprecio > 0) {
                        precioFinal = producto.subprecio - (producto.subprecio * (producto.descuento / 100));
                    }

                    let stockFormateado = formatearStockJS(producto.stock_total, producto.unidad_medida);
                    let stockBadgeClass = 'bg-success';
                    if (producto.stock_total <= 0) stockBadgeClass = 'bg-danger';
                    else if (producto.stock_total <= response.stock_minimo_global) stockBadgeClass = 'bg-warning';

                    let imagenesHtml = '';
                    if (producto.imagenes && producto.imagenes.length > 0) {
                        imagenesHtml = `
                    <div id="carouselMobile-${producto.id}" class="carousel slide producto-imagen-carousel me-2" style="width: 80px;" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-inner">
                            ${producto.imagenes.map((img, idx) => `
                                <div class="carousel-item ${idx === 0 ? 'active' : ''}">
                                    <img src="${img.ruta_imagen}" class="d-block w-100" alt="${escapeHtml(producto.nombre)}" onclick="abrirCarruselAmpliado('${producto.id}', ${idx}, event)">
                                </div>
                            `).join('')}
                        </div>
                        ${producto.imagenes.length > 1 ? `
                            <button class="carousel-control-prev" type="button" data-bs-target="#carouselMobile-${producto.id}" data-bs-slide="prev" style="width: 15px;">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Anterior</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#carouselMobile-${producto.id}" data-bs-slide="next" style="width: 15px;">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Siguiente</span>
                            </button>
                        ` : ''}
                    </div>
                `;
                    } else {
                        imagenesHtml = `
                    <div class="producto-imagen-mobile bg-light d-flex align-items-center justify-content-center me-2 no-imagen-container" style="width: 70px; height: 70px; cursor: pointer;" onclick="abrirCarruselAmpliado('${producto.id}', 0, event)">
                        <i class="fas fa-image text-muted"></i>
                    </div>
                `;
                    }

                    const card = `
                <div class="producto-card-mobile" data-categoria="${producto.categoria_id || ''}" data-proveedor="${producto.proveedor_id || ''}" data-activo="${producto.activo}">
                    <div class="producto-card-header">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-center">
                                ${imagenesHtml}
                                <div>
                                    <h6 class="mb-0 text-white">${escapeHtml(producto.nombre)}</h6>
                                    <div class="d-flex align-items-center mt-1">
                                        <span class="badge bg-light text-dark me-2">${escapeHtml(producto.codigo)}</span>
                                        ${producto.tiene_mayoreo ? '<span class="badge mayoreo-badge" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); font-size: 0.65rem;"><i class="fas fa-tags"></i> Mayoreo</span>' : ''}
                                        ${producto.tipo_producto ? `<span class="badge tipo-producto-badge ms-1" style="font-size: 0.65rem;"><i class="fas fa-tag"></i> ${escapeHtml(producto.tipo_producto)}</span>` : ''}
                                        <button class="btn btn-outline-light btn-sm edit-producto-mobile d-none" data-id="${producto.id}" data-activo="${producto.activo}" data-codigo="${escapeHtml(producto.codigo)}" data-nombre="${escapeHtml(producto.nombre)}" data-descripcion="${escapeHtml(producto.descripcion || '')}" data-marca="${escapeHtml(producto.marca || '')}" data-precio="${precioFinal}" data-subprecio="${producto.subprecio}" data-descuento="${producto.descuento}" data-costo="${producto.costo}" data-categoria_id="${producto.categoria_id || ''}" data-proveedor_id="${producto.proveedor_id || ''}" data-unidad_medida="${producto.unidad_medida}" data-peso_kg="${producto.peso_kg}" data-permite_fracciones="${producto.permite_fracciones}" data-fecha_caducidad="${producto.fecha_caducidad || ''}" data-tipo_producto="${escapeHtml(producto.tipo_producto || 'Estandar')}" data-porcentaje_merma_danado="${producto.porcentaje_merma_danado}" data-porcentaje_merma_deshidratacion="${producto.porcentaje_merma_deshidratacion}" data-aplicar_merma_venta="${producto.aplicar_merma_venta}" data-aplicar_merma_compra="${producto.aplicar_merma_compra}" data-imagenes='${JSON.stringify(producto.imagenes || [])}' data-sucursales='${producto.sucursales_ids || ""}' data-precios-mayoreo='${JSON.stringify(producto.precios_mayoreo || [])}' data-stocks='${JSON.stringify(producto.stocks_por_sucursal || {})}'>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="producto-card-body">
                        <div class="producto-info-row"><span class="producto-info-label">Unidad Medida:</span><span class="producto-info-value"><span class="badge unidad-medida-badge ${unidadBadgeClass}">${ucfirst(producto.unidad_medida || 'pieza')}</span></span></div>
                        ${producto.tipo_producto ? `<div class="producto-info-row"><span class="producto-info-label">Tipo:</span><span class="producto-info-value"><span class="badge tipo-producto-badge">${escapeHtml(producto.tipo_producto)}</span></span></div>` : ''}
                        ${(producto.porcentaje_merma_danado > 0 || producto.porcentaje_merma_deshidratacion > 0) ? `<div class="producto-info-row"><span class="producto-info-label">Merma:</span><span class="producto-info-value"><span class="badge merma-badge"><i class="fas fa-charging-station me-1"></i> D: ${producto.porcentaje_merma_danado}% / Des: ${producto.porcentaje_merma_deshidratacion}%</span></span></div>` : ''}
                        <div class="producto-info-row"><span class="producto-info-label">Marca:</span><span class="producto-info-value">${escapeHtml(producto.marca || 'N/A')}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Categoría:</span><span class="producto-info-value">${escapeHtml(producto.categoria_nombre || 'Sin categoría')}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Subprecio:</span><span class="producto-info-value text-dark">$${parseFloat(producto.subprecio || 0).toFixed(2)}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Descuento:</span><span class="producto-info-value">${producto.descuento > 0 ? `<span class="badge bg-danger">-${parseFloat(producto.descuento).toFixed(0)}%</span>` : '<span class="text-muted">0%</span>'}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Precio Final:</span><span class="producto-info-value text-success fw-bold">$${precioFinal.toFixed(2)}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Stock Total:</span><span class="producto-info-value"><span class="badge ${stockBadgeClass}">${stockFormateado}</span> <small class="text-muted ms-2">Mín: ${response.stock_minimo_global}</small></span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Fecha Caducidad:</span><span class="producto-info-value">${formatearFechaCaducidadJS(producto.fecha_caducidad)}</span></div>
                        <div class="producto-info-row"><span class="producto-info-label">Estado:</span><span class="producto-info-value"><span class="status-badge ${producto.activo ? 'status-active' : 'status-inactive'}">${producto.activo ? 'Activo' : 'Inactivo'}</span></span></div>
                        ${producto.descripcion ? `<div class="producto-info-row"><span class="producto-info-label">Descripción:</span><span class="producto-info-value"><small>${escapeHtml(producto.descripcion)}</small></span></div>` : ''}
                    </div>
                </div>
            `;
                    container.append(card);
                });

                reinicializarEventosProductos();
            }

            // =============================================
// SISTEMA DE TOOLTIPS INFORMATIVOS
// =============================================

// Configuración de los tooltips para cada campo
const tooltipsConfig = {
    'costo': {
        titulo: 'Costo del producto',
        descripcion: 'Precio de compra del producto. Este valor sirve como base para calcular la utilidad.'
    },
    'utilidad': {
        titulo: 'Utilidad (%)',
        descripcion: 'Porcentaje de ganancia sobre el costo. El precio de venta se calculará automáticamente: Precio = Costo × (1 + Utilidad/100)'
    },
    'descuento': {
        titulo: 'Descuento (%)',
        descripcion: 'Descuento directo sobre el precio de venta. Si aplicas descuento, el precio final se recalculará automáticamente.'
    },
    'precio_venta_base': {
        titulo: 'Precio base',
        descripcion: 'Precio original del producto sin descuentos aplicados. Este es el precio de referencia.'
    },
    'precio_venta_final': {
        titulo: 'Precio final',
        descripcion: 'Precio después de aplicar el descuento. Este es el precio que pagará el cliente.'
    },
    'codigo': {
        titulo: 'Código de producto',
        descripcion: 'Identificador único del producto. Puedes usar el botón "Auto" para generarlo automáticamente o escribir uno personalizado.'
    },
    'nombre': {
        titulo: 'Nombre del producto',
        descripcion: 'Nombre descriptivo del producto que aparecerá en ventas y facturas.'
    },
    'marca': {
        titulo: 'Marca',
        descripcion: 'Marca o fabricante del producto. Campo opcional.'
    },
    'descripcion': {
        titulo: 'Descripción',
        descripcion: 'Información adicional sobre el producto (características, especificaciones, etc.). Opcional.'
    },
    'categoria': {
        titulo: 'Categoría',
        descripcion: 'Agrupa productos similares para facilitar la organización y búsqueda.'
    },
    'proveedor': {
        titulo: 'Proveedor',
        descripcion: 'Empresa o persona que suministra el producto. Útil para gestión de compras.'
    },
    'unidad_medida': {
        titulo: 'Unidad de medida',
        descripcion: 'Cómo se mide el producto: piezas (unidades completas), kilos (peso) o litros (volumen).'
    },
    'peso': {
        titulo: 'Peso/Volumen',
        descripcion: 'Para kilos: peso en kg por unidad. Para litros: volumen en L por unidad.'
    },
    'fracciones': {
        titulo: 'Ventas por fracciones',
        descripcion: 'Permite vender cantidades fraccionadas (ej: 0.5 kg, 1.75 L). Para piezas normalmente se venden completas.'
    },
    'fecha_caducidad': {
        titulo: '⏰ Fecha de caducidad',
        descripcion: 'Fecha límite de consumo/venta. El sistema alertará cuando el producto esté próximo a vencer.'
    },
    'tipo_producto': {
        titulo: 'Tipo/Calidad',
        descripcion: 'Clasificación adicional del producto por calidad, tamaño o categoría especial.'
    },
    'merma_danado': {
        titulo: 'Merma por daño',
        descripcion: 'Porcentaje de producto que se estima se dañará durante el almacenamiento o manejo.'
    },
    'merma_deshidratacion': {
        titulo: 'Merma por deshidratación',
        descripcion: 'Para productos perecederos que pierden peso/volumen con el tiempo (frutas, verduras, carnes).'
    },
    'aplicar_merma_venta': {
        titulo: 'Aplicar merma en venta',
        descripcion: 'Al vender, se descuenta automáticamente el porcentaje de merma del inventario disponible.'
    },
    'aplicar_merma_compra': {
        titulo: 'Aplicar merma en compra',
        descripcion: 'Al comprar mercancía, se aplica el descuento por merma automáticamente al inventario.'
    },
    'mayoreo': {
        titulo: 'Precios de Mayoreo',
        descripcion: 'Define precios especiales según la cantidad de compra. Ej: 10 piezas a $100 c/u, 50 piezas a $90 c/u.'
    },
    'stock': {
        titulo: 'Stock por sucursal',
        descripcion: 'Cantidad de producto disponible en cada sucursal. Según la unidad de medida, puedes usar decimales (kilos/litros) o enteros (piezas).'
    },
    'transferencia': {
        titulo: 'Transferencia entre sucursales',
        descripcion: 'Mueve stock de una sucursal a otra. El sistema actualizará automáticamente los inventarios y registrará el movimiento.'
    }
};

// Función para mostrar tooltip
function showTooltip(content, targetElement, event) {
    // Eliminar tooltip existente
    $('.custom-tooltip').remove();
    
    // Crear nuevo tooltip
    const tooltip = $('<div class="custom-tooltip">' + content + '</div>');
    $('body').append(tooltip);
    
    // Posicionar el tooltip
    const targetRect = targetElement.getBoundingClientRect();
    const tooltipHeight = tooltip.outerHeight();
    const tooltipWidth = tooltip.outerWidth();
    
    let top = targetRect.bottom + window.scrollY + 8;
    let left = targetRect.left + window.scrollX + (targetRect.width / 2) - (tooltipWidth / 2);
    
    // Ajustar si se sale de la pantalla
    if (left + tooltipWidth > window.innerWidth) {
        left = window.innerWidth - tooltipWidth - 10;
    }
    if (left < 10) {
        left = 10;
    }
    
    // Si no hay espacio abajo, mostrar arriba
    if (top + tooltipHeight > window.innerHeight + window.scrollY) {
        top = targetRect.top + window.scrollY - tooltipHeight - 8;
        tooltip.addClass('bottom');
    }
    
    tooltip.css({
        top: top,
        left: left
    }).addClass('show');
    
    // Auto-cerrar después de 4 segundos
    setTimeout(() => {
        tooltip.fadeOut(300, function() {
            $(this).remove();
        });
    }, 4000);
}

// Función para agregar tooltips a los labels
function agregarTooltipALabel(labelId, configKey, textoLabel) {
    const labelElement = $(labelId);
    if (labelElement.length && tooltipsConfig[configKey]) {
        const config = tooltipsConfig[configKey];
        
        // Cambiar estructura del label para incluir el ícono
        labelElement.html(`
            <span class="label-with-tooltip">
                ${textoLabel}
                <span class="info-tooltip-icon" data-tooltip-key="${configKey}">
                    <i class="fas fa-question"></i>
                </span>
            </span>
        `);
    }
}

// Inicializar todos los tooltips
function inicializarTooltips() {
    // Mapeo de selectores a configuraciones
    const tooltipMappings = [
        { selector: 'label[for="costo"], label:contains("Costo")', key: 'costo', texto: 'Costo' },
        { selector: 'label:contains("Utilidad")', key: 'utilidad', texto: 'Utilidad (%)' },
        { selector: 'label:contains("Descuento")', key: 'descuento', texto: 'Descuento (%)' },
        { selector: 'label:contains("Precio Venta (Base)")', key: 'precio_venta_base', texto: 'Precio Venta (Base) *' },
        { selector: 'label:contains("Precio Venta (Final)")', key: 'precio_venta_final', texto: 'Precio Venta (Final) *' },
        { selector: 'label[for="codigo"], label:contains("Código")', key: 'codigo', texto: 'Código *' },
        { selector: 'label[for="nombre"], label:contains("Nombre")', key: 'nombre', texto: 'Nombre *' },
        { selector: 'label[for="marca"], label:contains("Marca")', key: 'marca', texto: 'Marca' },
        { selector: 'label[for="descripcion"], label:contains("Descripción")', key: 'descripcion', texto: 'Descripción' },
        { selector: 'label[for="categoria_id"], label:contains("Categoría")', key: 'categoria', texto: 'Categoría' },
        { selector: 'label[for="proveedor_id"], label:contains("Proveedor")', key: 'proveedor', texto: 'Proveedor' },
        { selector: 'label[for="unidad_medida"], label:contains("Unidad de Medida")', key: 'unidad_medida', texto: 'Unidad de Medida *' },
        { selector: 'label[for="peso_kg"], label:contains("Peso")', key: 'peso', texto: 'Peso por Unidad (kg)' },
        { selector: 'label[for="permite_fracciones"], label:contains("Permitir venta por fracciones")', key: 'fracciones', texto: 'Permitir venta por fracciones' },
        { selector: 'label[for="fecha_caducidad"], label:contains("Fecha de Caducidad")', key: 'fecha_caducidad', texto: 'Fecha de Caducidad' },
        { selector: 'label[for="tipo_producto"], label:contains("Clasificación / Calidad")', key: 'tipo_producto', texto: 'Clasificación / Calidad' },
        { selector: 'label:contains("Merma por Daño")', key: 'merma_danado', texto: 'Merma por Daño (%)' },
        { selector: 'label:contains("Merma por Deshidratación")', key: 'merma_deshidratacion', texto: 'Merma por Deshidratación / Desgaste (%)' },
        { selector: 'label[for="aplicar_merma_venta"], label:contains("Aplicar merma al calcular existencias en venta")', key: 'aplicar_merma_venta', texto: 'Aplicar merma al calcular existencias en venta' },
        { selector: 'label[for="aplicar_merma_compra"], label:contains("Aplicar merma al recibir mercancía")', key: 'aplicar_merma_compra', texto: 'Aplicar merma al recibir mercancía' },
        { selector: '.mayoreo-header .form-check-label', key: 'mayoreo', texto: 'Habilitar precios por cantidad' },
        { selector: '.sucursal-stock-header', key: 'stock', texto: 'Sucursales y Stock' }
    ];
    
    // Aplicar tooltips
    tooltipMappings.forEach(mapping => {
        $(mapping.selector).each(function() {
            if (!$(this).find('.info-tooltip-icon').length) {
                agregarTooltipALabel($(this), mapping.key, mapping.texto);
            }
        });
    });
    
    // Evento para mostrar tooltip al hacer clic o hover
    $(document).off('click mouseenter', '.info-tooltip-icon').on('click mouseenter', '.info-tooltip-icon', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const key = $(this).data('tooltip-key');
        const config = tooltipsConfig[key];
        
        if (config) {
            const content = `<i class="fas fa-info-circle"></i><strong>${config.titulo}</strong><br><small>${config.descripcion}</small>`;
            showTooltip(content, this, e);
        }
    });
    
    // También agregar tooltip al header de transferencia de stock
    if ($('#seccionTransferenciaStock').length) {
        const transferHeader = $('#seccionTransferenciaStock h6');
        if (transferHeader.length && !transferHeader.find('.info-tooltip-icon').length) {
            transferHeader.html(`
                <i class="fas fa-exchange-alt me-2"></i>
                Transferir Stock entre Sucursales
                <span class="info-tooltip-icon" data-tooltip-key="transferencia" style="margin-left: 8px;">
                    <i class="fas fa-question"></i>
                </span>
            `);
        }
    }
}

// Llamar a inicialización después de que el modal se muestre
$(document).on('shown.bs.modal', '#productoModal', function() {
    inicializarTooltips();
});

// También inicializar cuando se carga la página por si el modal está visible
setTimeout(inicializarTooltips, 100);

            function actualizarPaginacion(response) {
                const paginaActual = response.pagina_actual;
                const totalPaginas = response.total_paginas;
                const totalRegistros = response.total_registros;
                const productosMostrados = response.productos ? response.productos.length : 0;

                filtrosActuales.pagina = paginaActual;

                if (totalPaginas > 1) {
                    let paginacionHtml = `
                <div class="pagination-container" id="desktopPagination">
                    <div class="pagination-info">Mostrando ${productosMostrados} de ${totalRegistros} productos</div>
                    <nav><ul class="pagination mb-0">
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="1"><i class="fas fa-angle-double-left"></i></a></li>
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="${paginaActual - 1}"><i class="fas fa-angle-left"></i></a></li>`;

                    let inicio = Math.max(1, paginaActual - 2);
                    let fin = Math.min(totalPaginas, paginaActual + 2);
                    for (let i = inicio; i <= fin; i++) {
                        paginacionHtml += `<li class="page-item ${i == paginaActual ? 'active' : ''}"><a class="page-link" href="#" data-pagina="${i}">${i}</a></li>`;
                    }

                    paginacionHtml += `
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="${paginaActual + 1}"><i class="fas fa-angle-right"></i></a></li>
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina="${totalPaginas}"><i class="fas fa-angle-double-right"></i></a></li>
                    </ul></nav>
                </div>
            `;

                    if ($('#desktopPagination').length) $('#desktopPagination').replaceWith(paginacionHtml);
                    else $('.producto-grid .card-body').append(paginacionHtml);

                    $('.pagination .page-link[data-pagina]').off('click').on('click', function(e) {
                        e.preventDefault();
                        const pagina = $(this).data('pagina');
                        if (pagina && pagina !== filtrosActuales.pagina) {
                            filtrosActuales.pagina = pagina;
                            cargarProductosConFiltros();
                        }
                    });
                } else {
                    $('#desktopPagination').remove();
                }

                if (totalPaginas > 1) {
                    let paginacionMobileHtml = `
                <div class="pagination-container" id="mobilePagination">
                    <div class="pagination-info">${productosMostrados} de ${totalRegistros} productos</div>
                    <nav><ul class="pagination pagination-sm mb-0 justify-content-center">
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="1"><i class="fas fa-angle-double-left"></i></a></li>
                        <li class="page-item ${paginaActual == 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="${paginaActual - 1}"><i class="fas fa-angle-left"></i></a></li>
                        <li class="page-item disabled"><span class="page-link text-dark"><strong>${paginaActual}</strong> / ${totalPaginas}</span></li>
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="${paginaActual + 1}"><i class="fas fa-angle-right"></i></a></li>
                        <li class="page-item ${paginaActual == totalPaginas ? 'disabled' : ''}"><a class="page-link" href="#" data-pagina-mobile="${totalPaginas}"><i class="fas fa-angle-double-right"></i></a></li>
                    </ul></nav>
                </div>
            `;

                    if ($('#mobilePagination').length) $('#mobilePagination').replaceWith(paginacionMobileHtml);
                    else $('#mobileProductsContainer').append(paginacionMobileHtml);

                    $('.pagination .page-link[data-pagina-mobile]').off('click').on('click', function(e) {
                        e.preventDefault();
                        const pagina = $(this).data('pagina-mobile');
                        if (pagina && pagina !== filtrosActuales.pagina) {
                            filtrosActuales.pagina = pagina;
                            cargarProductosConFiltros();
                        }
                    });
                } else {
                    $('#mobilePagination').remove();
                }

                const texto = `Mostrando ${productosMostrados} de ${totalRegistros} productos`;
                $('#resultCount, #resultCountDesktop, #resultCountMobile').text(texto);

                if (totalPaginas > 1) {
                    $('.producto-grid .card-header .badge').text(`Página ${paginaActual} de ${totalPaginas}`);
                    $('.producto-cards .d-flex .badge').text(`Pág. ${paginaActual}/${totalPaginas}`);
                }
            }

            function actualizarEstadisticas(response) {
                if (response.estadisticas) {
                    $('.stat-card:eq(0) .metric-value').text(response.estadisticas.total_productos || 0);
                    $('.stat-card:eq(1) .metric-value').text(response.estadisticas.con_stock || 0);
                    $('.stat-card:eq(2) .metric-value').text(response.estadisticas.bajo_stock || 0);
                    $('.stat-card:eq(3) .metric-value').text(response.estadisticas.sin_stock || 0);
                }
            }

            function eliminarProducto(id, nombre) {
                if (!id || id <= 0) {
                    mostrarMensajeTemporal('ID de producto inválido', 'danger');
                    return;
                }

                // PRIMERA confirmación
                if (!confirm(`¿Estás SEGURO de que deseas eliminar el producto "${nombre}"?\n\nEsto verificará si tiene dependencias (ventas, compras, movimientos).`)) {
                    return;
                }

                // Mostrar indicador de carga
                const btnEliminar = $('#btnEliminarProducto');
                const originalHtml = btnEliminar.html();
                btnEliminar.html('<i class="fas fa-spinner fa-spin me-2"></i>Verificando...').prop('disabled', true);

                // Verificar dependencias
                $.ajax({
                    url: 'verificar_dependencias_producto.php',
                    type: 'POST',
                    data: {
                        id: id
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            if (response.tiene_dependencias) {
                                // Mostrar mensaje detallado de dependencias
                                let mensaje = `No se puede eliminar el producto "${nombre}" porque tiene registros asociados:\n\n`;
                                if (response.ventas > 0) mensaje += `Ventas: ${response.ventas} registros\n`;
                                if (response.compras > 0) mensaje += `Compras: ${response.compras} registros\n`;
                                if (response.movimientos > 0) mensaje += `Movimientos de inventario: ${response.movimientos} registros\n`;
                                mensaje += `\nSugerencia: Desactive el producto en lugar de eliminarlo.`;
                                alert(mensaje);
                                btnEliminar.html(originalHtml).prop('disabled', false);
                            } else {
                                // SEGUNDA confirmación (final)
                                const confirmacionFinal = confirm(`CONFIRMACIÓN FINAL \n\n¿ELIMINAR PERMANENTEMENTE el producto "${nombre}"?\n\nEsta acción ELIMINARÁ:\n• Las imágenes del producto\n• Los precios de mayoreo\n• La relación con sucursales\n• El producto en sí\n\nEsta acción es IRREVERSIBLE. `);

                                if (confirmacionFinal) {
                                    ejecutarEliminacionProducto(id, nombre);
                                } else {
                                    btnEliminar.html(originalHtml).prop('disabled', false);
                                }
                            }
                        } else {
                            mostrarMensajeTemporal(response.message || 'Error al verificar dependencias', 'danger');
                            btnEliminar.html(originalHtml).prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al verificar dependencias:', error);
                        mostrarMensajeTemporal('Error de conexión al verificar dependencias', 'danger');
                        btnEliminar.html(originalHtml).prop('disabled', false);
                    }
                });
            }



            function ejecutarEliminacionProducto(id, nombre) {
                const btnEliminar = $('#btnEliminarProducto');
                const originalHtml = btnEliminar.html();
                btnEliminar.html('<i class="fas fa-spinner fa-spin me-2"></i>Eliminando...').prop('disabled', true);

                $.ajax({
                    url: 'eliminar_producto.php',
                    type: 'POST',
                    data: {
                        id: id,
                        confirmacion: 'true'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            mostrarMensajeTemporal(`Producto "${nombre}" eliminado exitosamente`, 'success');
                            $('#productoModal').modal('hide');
                            // Recargar la página después de 1 segundo
                            setTimeout(() => {
                                window.location.href = window.location.pathname + '?recargado=' + Date.now();
                            }, 1000);
                        } else {
                            mostrarMensajeTemporal(response.message || 'Error al eliminar producto', 'danger');
                            btnEliminar.html(originalHtml).prop('disabled', false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al eliminar producto:', error);
                        let errorMsg = 'Error de conexión al eliminar producto';
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.message) errorMsg = response.message;
                        } catch (e) {}
                        mostrarMensajeTemporal(errorMsg, 'danger');
                        btnEliminar.html(originalHtml).prop('disabled', false);
                    }
                });
            }

            // Mostrar/ocultar botones según sea edición o nuevo producto
            $(document).on('shown.bs.modal', '#productoModal', function() {
                const formAction = $('#formAction').val();
                if (formAction === 'editar') {
                    const productoId = $('#productoId').val();
                    const productoNombre = $('#nombre').val() || 'este producto';

                    // Sección de transferencia (solo edición, mínimo 2 sucursales)
                    <?php if (count($sucursales) >= 2): ?>
                        $('#seccionTransferenciaStock').show();
                    <?php endif; ?>

                    // Botón Eliminar
                    $('#btnEliminarProducto').show();
                    $('#btnEliminarProducto').off('click').on('click', function(e) {
                        e.preventDefault();
                        eliminarProducto(productoId, productoNombre);
                    });

                    // Botón Clonar
                    $('#btnClonarProductoModal').show();
                    $('#btnClonarProductoModal').off('click').on('click', function() {
                        $('#productoModal').modal('hide');
                        // Recolectar datos actuales del producto para clonar
                        const productoData = {
                            id: productoId,
                            nombre: productoNombre,
                            descripcion: $('#descripcion').val() || '',
                            marca: $('#marca').val() || '',
                            precio: $('#precio_hidden').val() || $('#precio_desktop').val() || '',
                            subprecio: $('#subprecio_hidden').val() || $('#subprecio_desktop').val() || '',
                            descuento: $('#descuento_hidden').val() || $('#descuento_desktop').val() || '0',
                            costo: $('#costo_hidden').val() || $('#costo_desktop').val() || '',
                            categoria_id: $('#categoria_id').val() || '',
                            proveedor_id: $('#proveedor_id').val() || '',
                            unidad_medida: $('#unidad_medida').val() || 'pieza',
                            peso_kg: $('#peso_kg').val() || '1.000',
                            permite_fracciones: $('#permite_fracciones').is(':checked') ? 1 : 0,
                            fecha_caducidad: $('#fecha_caducidad').val() || '',
                            tipo_producto: $('#tipo_producto').val() || 'Estandar',
                            porcentaje_merma_danado: $('#porcentaje_merma_danado').val() || 0,
                            porcentaje_merma_deshidratacion: $('#porcentaje_merma_deshidratacion').val() || 0,
                            aplicar_merma_venta: $('#aplicar_merma_venta').is(':checked') ? 1 : 0,
                            aplicar_merma_compra: $('#aplicar_merma_compra').is(':checked') ? 1 : 0,
                            imagenes: JSON.parse($('#imagenes_existentes').val() || '[]'),
                            precios_mayoreo: reglasMayoreo || [],
                            sucursales: [],
                            stocks: {}
                        };
                        setTimeout(() => clonarProducto(productoData), 400);
                    });

                    // Botón Activar/Desactivar
                    $('#btnToggleEstadoModal').show();
                    // Leer estado actual desde hidden input
                    const productoActivo = parseInt($('#productoActivo').val()) || 0;
                    if (productoActivo) {
                        $('#btnToggleEstadoModal').removeClass('btn-outline-success').addClass('btn-outline-warning');
                        $('#btnToggleEstadoModal').find('i').removeClass('fa-toggle-off').addClass('fa-toggle-on');
                        $('#btnToggleEstadoTexto').text('Desactivar');
                    } else {
                        $('#btnToggleEstadoModal').removeClass('btn-outline-warning').addClass('btn-outline-success');
                        $('#btnToggleEstadoModal').find('i').removeClass('fa-toggle-on').addClass('fa-toggle-off');
                        $('#btnToggleEstadoTexto').text('Activar');
                    }
                    $('#btnToggleEstadoModal').off('click').on('click', function() {
                        const estadoActual = parseInt($('#productoActivo').val()) || 0;
                        const nuevoActivo = estadoActual ? 0 : 1;
                        const texto = nuevoActivo == 1 ? 'activar' : 'desactivar';
                        if (confirm(`¿Estás seguro de ${texto} el producto "${productoNombre}"?`)) {
                            $('#productoModal').modal('hide');
                            $.ajax({
                                url: 'productos.php',
                                type: 'POST',
                                data: {
                                    accion: 'cambiar_estado',
                                    id: productoId,
                                    activo: nuevoActivo
                                },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        mostrarMensajeTemporal(response.message || `Producto ${texto === 'activar' ? 'activado' : 'desactivado'} correctamente`, 'success');
                                        cargarProductosConFiltros();
                                    } else {
                                        mostrarMensajeTemporal(response.message || 'Error al cambiar estado', 'danger');
                                    }
                                },
                                error: function() {
                                    mostrarMensajeTemporal('Error de conexión al cambiar estado', 'danger');
                                }
                            });
                        }
                    });
                } else {
                    $('#btnEliminarProducto').hide();
                    $('#btnClonarProductoModal').hide();
                    $('#btnToggleEstadoModal').hide();
                    $('#seccionTransferenciaStock').hide();
                    $('#trans_resultado').hide().empty();
                    $('#trans_sucursal_origen, #trans_sucursal_destino').val('');
                    $('#trans_cantidad').val('');
                    $('#trans_observaciones').val('');
                }
            });

            function cargarProductosConFiltros() {
                if (cargandoProductos) return;

                const filtros = {
                    search: filtrosActuales.search,
                    categoria: filtrosActuales.categoria,
                    proveedor: filtrosActuales.proveedor,
                    sucursal: filtrosActuales.sucursal,
                    show_inactive: filtrosActuales.show_inactive ? '1' : '0',
                    pagina: filtrosActuales.pagina
                };

                mostrarCargando(true);

                $.ajax({
                    url: 'ajax_productos.php',
                    type: 'GET',
                    data: filtros,
                    dataType: 'json',
                    timeout: 30000,
                    success: function(response) {
                        if (response.success) {
                            actualizarTablaProductos(response);
                            actualizarTarjetasMoviles(response);
                            actualizarPaginacion(response);
                            actualizarEstadisticas(response);

                            const params = new URLSearchParams();
                            if (filtros.search) params.set('search', filtros.search);
                            if (filtros.categoria) params.set('categoria', filtros.categoria);
                            if (filtros.proveedor) params.set('proveedor', filtros.proveedor);
                            if (filtros.sucursal) params.set('sucursal', filtros.sucursal);
                            if (filtros.show_inactive === '1') params.set('show_inactive', '1');
                            if (filtros.pagina > 1) params.set('pagina', filtros.pagina);
                            window.history.pushState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
                        } else {
                            mostrarMensajeTemporal(response.message || 'Error al cargar productos', 'danger');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error AJAX:', error);
                        mostrarMensajeTemporal('Error al cargar los productos. Por favor, intenta de nuevo.', 'danger');
                    },
                    complete: function() {
                        mostrarCargando(false);
                    }
                });
            }

            function aplicarFiltrosYRecargar(resetPagina = true) {
                const nuevosFiltros = {
                    search: $('#searchInput').val(),
                    categoria: $('#filterCategoria').val(),
                    proveedor: $('#filterProveedor').val(),
                    sucursal: $('#filterSucursal').val(),
                    show_inactive: $('#showInactive').is(':checked'),
                    pagina: resetPagina ? 1 : filtrosActuales.pagina
                };

                filtrosActuales = nuevosFiltros;
                sincronizarFiltrosMoviles();
                cargarProductosConFiltros();
            }

            // =============================================
            // FUNCIONES PARA PRECIOS DE MAYOREO
            // =============================================

            function agregarReglaMayoreo(cantidad = '', precio = '') {
                reglasMayoreo.push({
                    cantidad: parseFloat(cantidad) || 0,
                    precio: parseFloat(precio) || 0
                });
                renderizarReglasMayoreo();
            }

            function eliminarReglaMayoreo(index) {
                reglasMayoreo.splice(index, 1);
                renderizarReglasMayoreo();
            }

            function renderizarReglasMayoreo() {
                const container = $('#reglasMayoreoContainer');
                container.empty();
                if (reglasMayoreo.length === 0) {
                    container.html(`<div class="text-center text-muted py-3"><i class="fas fa-chart-line fa-2x mb-2"></i><p>No hay reglas de mayoreo configuradas</p><small>Agrega reglas para precios especiales por cantidad</small></div>`);
                    return;
                }
                const reglasOrdenadas = [...reglasMayoreo].sort((a, b) => a.cantidad - b.cantidad);
                reglasOrdenadas.forEach((regla, idx) => {
                    const indexOriginal = reglasMayoreo.findIndex(r => r.cantidad === regla.cantidad && r.precio === regla.precio);
                    const unidad = $('#unidad_medida').val() || 'pieza';
                    const unidadTexto = unidad === 'pieza' ? 'piezas' : unidad;
                    const reglaHtml = `<div class="regla-mayoreo-item"><div class="regla-mayoreo-inputs"><div class="flex-grow-1"><label class="form-label small">Cantidad mínima (${unidadTexto})</label><input type="number" class="form-control form-control-sm" value="${regla.cantidad}" step="any" min="0.001" data-index="${indexOriginal}" data-campo="cantidad" onchange="actualizarReglaMayoreoDesdeInput(this)"></div><div class="flex-grow-1"><label class="form-label small">Precio especial ($)</label><input type="number" class="form-control form-control-sm" value="${regla.precio}" step="0.01" min="0" data-index="${indexOriginal}" data-campo="precio" onchange="actualizarReglaMayoreoDesdeInput(this)"></div><button type="button" class="btn-eliminar-regla" onclick="eliminarReglaMayoreoDesdeJS(${indexOriginal})"><i class="fas fa-trash-alt"></i></button></div><small class="text-muted">Aplica para compras de ${regla.cantidad} o más ${unidadTexto}</small></div>`;
                    container.append(reglaHtml);
                });
                actualizarCampoMayoreoOculto();
            }

            window.actualizarReglaMayoreoDesdeInput = function(input) {
                const index = $(input).data('index');
                const campo = $(input).data('campo');
                const valor = $(input).val();
                if (reglasMayoreo[index]) {
                    reglasMayoreo[index][campo] = parseFloat(valor) || 0;
                    renderizarReglasMayoreo();
                }
            };

            window.eliminarReglaMayoreoDesdeJS = function(index) {
                reglasMayoreo.splice(index, 1);
                renderizarReglasMayoreo();
            };

            function actualizarCampoMayoreoOculto() {
                const reglasValidas = reglasMayoreo.filter(r => r.cantidad > 0 && r.precio > 0);
                $('#precios_mayoreo').val(JSON.stringify(reglasValidas));
            }

            function validarReglasMayoreo() {
                if (!mayoreoHabilitado) return true;
                if (reglasMayoreo.length === 0) {
                    alert('Debes agregar al menos una regla de mayoreo o deshabilitar la opción');
                    return false;
                }
                for (const regla of reglasMayoreo) {
                    if (regla.cantidad <= 0 || regla.precio <= 0) {
                        alert('Todas las reglas deben tener cantidad y precio válidos (mayores a 0)');
                        return false;
                    }
                    const precioNormal = parseFloat($('#precio_hidden').val()) || 0;
                    if (regla.precio >= precioNormal && precioNormal > 0) {
                        alert(`El precio especial ($${regla.precio}) debe ser menor al precio normal ($${precioNormal})`);
                        return false;
                    }
                }
                const cantidades = reglasMayoreo.map(r => r.cantidad);
                const duplicados = cantidades.filter((c, i) => cantidades.indexOf(c) !== i);
                if (duplicados.length > 0) {
                    alert(`No puedes tener dos reglas con la misma cantidad mínima (${duplicados[0]})`);
                    return false;
                }
                return true;
            }

            function cargarReglasMayoreo(preciosMayoreo) {
                if (preciosMayoreo && Array.isArray(preciosMayoreo) && preciosMayoreo.length > 0) {
                    mayoreoHabilitado = true;
                    reglasMayoreo = preciosMayoreo.map(p => ({
                        cantidad: parseFloat(p.cantidad_minima) || 0,
                        precio: parseFloat(p.precio_especial) || 0
                    }));
                    $('#habilitarMayoreo').prop('checked', true);
                    $('#mayoreoSection').show();
                    $('#btnAgregarReglaMayoreo').show();
                } else {
                    mayoreoHabilitado = false;
                    reglasMayoreo = [];
                    $('#habilitarMayoreo').prop('checked', false);
                    $('#mayoreoSection').hide();
                    $('#btnAgregarReglaMayoreo').hide();
                }
                renderizarReglasMayoreo();
            }

            $('#habilitarMayoreo').on('change', function() {
                mayoreoHabilitado = $(this).is(':checked');
                if (mayoreoHabilitado) {
                    $('#mayoreoSection').slideDown();
                    $('#btnAgregarReglaMayoreo').show();
                    if (reglasMayoreo.length === 0) agregarReglaMayoreo(10, 0);
                } else {
                    $('#mayoreoSection').slideUp();
                    $('#btnAgregarReglaMayoreo').hide();
                    reglasMayoreo = [];
                    renderizarReglasMayoreo();
                }
            });

            $('#btnAgregarReglaMayoreo').on('click', function() {
                let sugerencia = 10;
                if (reglasMayoreo.length > 0) sugerencia = Math.max(...reglasMayoreo.map(r => r.cantidad)) + 10;
                agregarReglaMayoreo(sugerencia, 0);
            });

            // =============================================
            // FUNCIONES PARA CARRUSEL AMPLIADO
            // =============================================

            window.abrirCarruselAmpliado = function(productoId, slideIndex, event) {
                if (event) event.stopPropagation();
                const modal = new bootstrap.Modal(document.getElementById('imagenAmpliadaModal'));
                const carouselInner = document.getElementById('imagenAmpliadaCarouselInner');
                const carouselIndicators = document.getElementById('imagenAmpliadaCarouselIndicators');
                const imagenCargando = document.getElementById('imagenCargando');
                const sinImagenMensaje = document.getElementById('sinImagenMensaje');
                const btnDescargar = document.getElementById('btnDescargarImagen');
                let imagenes = [];
                let productoNombre = '';
                const carouselSmall = document.getElementById('carouselSmall-' + productoId);
                if (carouselSmall) {
                    const items = carouselSmall.querySelectorAll('.carousel-item img');
                    items.forEach(img => {
                        if (img.src) imagenes.push({
                            ruta_imagen: img.src
                        });
                    });
                    const row = carouselSmall.closest('tr');
                    if (row) productoNombre = row.querySelector('td:nth-child(3) strong')?.textContent.trim() || 'Producto';
                }
                if (imagenes.length === 0) {
                    const carouselMobile = document.getElementById('carouselMobile-' + productoId);
                    if (carouselMobile) {
                        const items = carouselMobile.querySelectorAll('.carousel-item img');
                        items.forEach(img => {
                            if (img.src) imagenes.push({
                                ruta_imagen: img.src
                            });
                        });
                        const card = carouselMobile.closest('.producto-card-mobile');
                        if (card) productoNombre = card.querySelector('.producto-card-header h6')?.textContent.trim() || 'Producto';
                    }
                }

                function mostrarSinImagen() {
                    carouselInner.innerHTML = '';
                    carouselIndicators.innerHTML = '';
                    imagenCargando.style.display = 'none';
                    sinImagenMensaje.style.display = 'block';
                    btnDescargar.style.display = 'none';
                    modal.show();
                }
                if (imagenes.length === 0) {
                    mostrarSinImagen();
                    return;
                }

                function cargarImagenes() {
                    if (imagenes.length === 0) {
                        mostrarSinImagen();
                        return;
                    }
                    imagenCargando.style.display = 'none';
                    sinImagenMensaje.style.display = 'none';
                    btnDescargar.style.display = 'flex';
                    let innerHtml = '',
                        indicatorsHtml = '';
                    imagenes.forEach((img, index) => {
                        const activeClass = index === slideIndex ? 'active' : '';
                        innerHtml += `<div class="carousel-item ${activeClass}"><img src="${img.ruta_imagen}" class="d-block w-100" alt="${productoNombre} - Imagen ${index + 1}"></div>`;
                        indicatorsHtml += `<button type="button" data-bs-target="#imagenAmpliadaCarousel" data-bs-slide-to="${index}" class="${index === slideIndex ? 'active' : ''}" aria-label="Slide ${index + 1}"></button>`;
                    });
                    carouselInner.innerHTML = innerHtml;
                    carouselIndicators.innerHTML = indicatorsHtml;
                    btnDescargar.onclick = function() {
                        descargarImagen(imagenes[slideIndex].ruta_imagen, productoNombre);
                    };
                    const carouselElement = document.getElementById('imagenAmpliadaCarousel');
                    carouselElement.addEventListener('slid.bs.carousel', function(event) {
                        btnDescargar.onclick = function() {
                            descargarImagen(imagenes[event.to].ruta_imagen, productoNombre);
                        };
                    });
                }
                imagenCargando.style.display = 'flex';
                sinImagenMensaje.style.display = 'none';
                btnDescargar.style.display = 'none';
                carouselInner.innerHTML = '';
                carouselIndicators.innerHTML = '';
                setTimeout(cargarImagenes, 200);
                modal.show();
            };

            function descargarImagen(src, nombreProducto) {
                const link = document.createElement('a');
                link.href = src;
                const nombreArchivo = nombreProducto.toLowerCase().replace(/[^a-z0-9áéíóúñü]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '') + '.jpg';
                link.download = nombreArchivo;
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            // =============================================
            // FUNCIONES PARA MÚLTIPLES IMÁGENES (CÁMARA MÓVIL)
            // =============================================

            function regenerarGaleriaImagenes() {
                const galeria = $('#galeriaImagenes');
                galeria.empty();
                if (imagenesExistentes.length === 0) {
                    galeria.html(`<div class="col-12 text-center text-muted py-3"><i class="fas fa-images fa-3x mb-2"></i><p>No hay imágenes para este producto</p></div>`);
                    return;
                }
                imagenesExistentes.forEach((img, index) => {
                    const isPrincipal = img.es_principal == 1;
                    const imagenHtml = `<div class="col-md-4 col-6 galeria-item" data-index="${index}" data-id="${img.id || ''}"><div class="imagen-container ${isPrincipal ? 'principal' : ''}"><img src="${img.ruta_imagen}" alt="Imagen ${index + 1}"><span class="badge-principal">${isPrincipal ? 'Principal' : ''}</span><button type="button" class="btn-eliminar-imagen" onclick="eliminarImagenExistente(${index})"><i class="fas fa-times"></i></button><button type="button" class="btn-principal ${isPrincipal ? 'activo' : ''}" onclick="marcarComoPrincipal(${index})">${isPrincipal ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>'}</button></div></div>`;
                    galeria.append(imagenHtml);
                });
            }

            function inicializarGaleriaImagenes(imagenesData) {
                imagenesExistentes = imagenesData || [];
                if (imagenesExistentes.length > 0 && !imagenesExistentes.some(img => img.es_principal == 1)) imagenesExistentes[0].es_principal = 1;
                regenerarGaleriaImagenes();
                $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                const principalIndex = imagenesExistentes.findIndex(img => img.es_principal == 1);
                if (principalIndex !== -1) $('#imagen_principal').val(principalIndex);
                if (imagenesExistentes.length > 1) {
                    const galeriaEl = document.getElementById('galeriaImagenes');
                    if (galeriaEl) new Sortable(galeriaEl, {
                        animation: 150,
                        ghostClass: 'galeria-sortable-ghost',
                        dragClass: 'galeria-sortable-drag',
                        onEnd: function(evt) {
                            const items = Array.from(galeriaEl.children);
                            const newOrder = [];
                            items.forEach(item => newOrder.push(imagenesExistentes[$(item).data('index')]));
                            imagenesExistentes = newOrder;
                            const principalIndex = imagenesExistentes.findIndex(img => img.es_principal == 1);
                            if (principalIndex !== -1) $('#imagen_principal').val(principalIndex);
                            $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                        }
                    });
                }
            }

            window.marcarComoPrincipal = function(index) {
                imagenesExistentes = imagenesExistentes.map((img, i) => {
                    img.es_principal = (i === index) ? 1 : 0;
                    return img;
                });
                $('#imagen_principal').val(index);
                $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                regenerarGaleriaImagenes();
            };

            window.eliminarImagenExistente = function(index) {
                if (confirm('¿Estás seguro de eliminar esta imagen?')) {
                    const eraPrincipal = imagenesExistentes[index]?.es_principal == 1;
                    imagenesExistentes.splice(index, 1);
                    if (eraPrincipal && imagenesExistentes.length > 0) {
                        imagenesExistentes[0].es_principal = 1;
                        $('#imagen_principal').val(0);
                    } else if (imagenesExistentes.length === 0) $('#imagen_principal').val(0);
                    regenerarGaleriaImagenes();
                    $('#imagenes_existentes').val(JSON.stringify(imagenesExistentes));
                }
            };

            // Función para manejar selección de archivos (desktop y galería)
            function manejarSeleccionImagenesArchivo(files) {
                if (!files || files.length === 0) return;
                const totalImagenes = imagenesExistentes.length + nuevasImagenes.length + files.length;
                if (totalImagenes > 5) {
                    alert('Solo puedes tener hasta 5 imágenes en total.');
                    return;
                }

                Array.from(files).forEach((file, index) => {
                    if (file.size > 4 * 1024 * 1024) {
                        alert(`La imagen "${file.name}" es demasiado grande. Máximo 4MB`);
                        return;
                    }
                    if (!file.type.match('image.*')) {
                        alert(`El archivo "${file.name}" no es una imagen válida`);
                        return;
                    }

                    // Guardar el archivo en el array nuevasImagenes
                    const previewUrl = URL.createObjectURL(file);
                    nuevasImagenes.push({
                        file: file,
                        preview: previewUrl
                    });
                    mostrarPreviewNuevaImagen(previewUrl, nuevasImagenes.length - 1);
                });
            }

            // Función para manejar captura de cámara
            function manejarCapturaCamara() {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.capture = 'environment'; // 'environment' para cámara trasera, 'user' para frontal
                input.onchange = function(e) {
                    if (e.target.files && e.target.files.length > 0) {
                        manejarSeleccionImagenesArchivo(e.target.files);
                    }
                    // Limpiar el input para poder tomar otra foto después
                    input.value = '';
                };
                input.click();
            }

            // Función para mostrar preview de nueva imagen
            function mostrarPreviewNuevaImagen(previewUrl, index) {
                const previewContainer = $('#nuevasImagenesPreview');
                previewContainer.append(`
            <div class="col-md-4 col-6 nueva-imagen-preview" data-file-index="${index}">
                <img src="${previewUrl}" alt="Nueva imagen">
                <button type="button" class="btn-eliminar-nueva" onclick="eliminarNuevaImagen(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `);
            }

            window.eliminarNuevaImagen = function(index) {
                // Liberar el objeto URL si existe
                if (nuevasImagenes[index] && nuevasImagenes[index].preview && nuevasImagenes[index].preview.startsWith('blob:')) {
                    URL.revokeObjectURL(nuevasImagenes[index].preview);
                }
                nuevasImagenes = nuevasImagenes.filter((_, i) => i !== index);
                // Actualizar los índices en los elementos del DOM
                $('.nueva-imagen-preview').each((i, el) => {
                    $(el).attr('data-file-index', i);
                    $(el).find('.btn-eliminar-nueva').attr('onclick', `eliminarNuevaImagen(${i})`);
                });
                $(`.nueva-imagen-preview[data-file-index="${index}"]`).remove();
            };

            function resetearGaleriaImagenes() {
                // Liberar URLs de objetos blob antes de limpiar
                nuevasImagenes.forEach(img => {
                    if (img.preview && img.preview.startsWith('blob:')) {
                        URL.revokeObjectURL(img.preview);
                    }
                });
                imagenesExistentes = [];
                nuevasImagenes = [];
                $('#galeriaImagenes').empty();
                $('#nuevasImagenesPreview').empty();
                $('#imagenes').val('');
                $('#imagenes_existentes').val('[]');
                $('#imagen_principal').val('0');
            }

            // Eventos para los botones móviles
            $('#btnSeleccionarGaleria').on('click', function() {
                $('#imagenes').click();
            });

            $('#btnTomarFoto').on('click', function() {
                manejarCapturaCamara();
            });

            // Evento para el input de archivo original (desktop)
            $('#imagenes').on('change', function() {
                manejarSeleccionImagenesArchivo(this.files);
                this.value = ''; // Limpiar para permitir seleccionar el mismo archivo nuevamente
            });

            // =============================================
            // FUNCIONES PARA PRECIOS Y STOCK
            // =============================================

            function sincronizarCamposPrecio() {
                const isMobile = window.innerWidth < 768;
                if (isMobile) {
                    $('#subprecio_desktop').val($('#subprecio_mobile').val());
                    $('#descuento_desktop').val($('#descuento_mobile').val());
                    $('#precio_desktop').val($('#precio_mobile').val());
                    $('#costo_desktop').val($('#costo_mobile').val() || '');
                     $('#utilidad_desktop').val($('#utilidad_mobile').val() || '');
                    $('#subprecio_hidden').val($('#subprecio_mobile').val());
                    $('#descuento_hidden').val($('#descuento_mobile').val());
                    $('#precio_hidden').val($('#precio_mobile').val());
                    $('#costo_hidden').val($('#costo_mobile').val() || '');
                } else {
                    $('#subprecio_mobile').val($('#subprecio_desktop').val());
                    $('#descuento_mobile').val($('#descuento_desktop').val());
                    $('#precio_mobile').val($('#precio_desktop').val());
                    $('#costo_mobile').val($('#costo_desktop').val() || '');
                     $('#utilidad_mobile').val($('#utilidad_desktop').val() || '');
                    $('#subprecio_hidden').val($('#subprecio_desktop').val());
                    $('#descuento_hidden').val($('#descuento_desktop').val());
                    $('#precio_hidden').val($('#precio_desktop').val());
                    $('#costo_hidden').val($('#costo_desktop').val() || '');
                    $('#utilidad_hidden').val($('#utilidad_mobile').val() || '');
                    $('#utilidad_hidden').val($('#utilidad_desktop').val() || '');
                }
            }

            // Calcular Precio Base (subprecio) desde Costo + Utilidad
function calcularPrecioDesdeUtilidad() {
    const isMobile = window.innerWidth < 768;
    let costo = isMobile ? parseFloat($('#costo_mobile').val()) || 0 : parseFloat($('#costo_desktop').val()) || 0;
    let utilidad = isMobile ? parseFloat($('#utilidad_mobile').val()) || 0 : parseFloat($('#utilidad_desktop').val()) || 0;
    
    if (costo > 0 && utilidad > 0) {
        let precioCalculado = costo * (1 + (utilidad / 100));
        if (isMobile) {
            $('#subprecio_mobile').val(precioCalculado.toFixed(2));
        } else {
            $('#subprecio_desktop').val(precioCalculado.toFixed(2));
        }
        sincronizarCamposPrecio();
        // Recalcular precio final si hay descuento
        calcularPrecioDesdeDescuento();
    } else if (costo > 0 && utilidad === 0) {
        // Utilidad 0% = precio igual al costo
        if (isMobile) {
            $('#subprecio_mobile').val(costo.toFixed(2));
        } else {
            $('#subprecio_desktop').val(costo.toFixed(2));
        }
        sincronizarCamposPrecio();
        calcularPrecioDesdeDescuento();
    }
}

// Calcular Utilidad desde Costo y Precio Base (subprecio)
function calcularUtilidadDesdePrecio() {
    const isMobile = window.innerWidth < 768;
    let costo = isMobile ? parseFloat($('#costo_mobile').val()) || 0 : parseFloat($('#costo_desktop').val()) || 0;
    let precioBase = isMobile ? parseFloat($('#subprecio_mobile').val()) || 0 : parseFloat($('#subprecio_desktop').val()) || 0;
    
    if (costo > 0 && precioBase > 0 && precioBase > costo) {
        let utilidadCalculada = ((precioBase - costo) / costo) * 100;
        if (isMobile) {
            $('#utilidad_mobile').val(utilidadCalculada.toFixed(2));
        } else {
            $('#utilidad_desktop').val(utilidadCalculada.toFixed(2));
        }
        sincronizarCamposPrecio();
    } else if (costo > 0 && precioBase > 0 && precioBase <= costo) {
        // Precio menor o igual al costo = sin ganancia
        if (isMobile) {
            $('#utilidad_mobile').val('0.00');
        } else {
            $('#utilidad_desktop').val('0.00');
        }
        sincronizarCamposPrecio();
    }
}

            function formatearDecimales(valor) {
                const num = parseFloat(valor);
                return isNaN(num) ? '' : num.toFixed(2);
            }

            function actualizarValidacionStockPorUnidad() {
    const unidad = $('#unidad_medida').val();
    let step = 1;
    let mensaje = '';
    let placeholder = '';
    
    switch (unidad) {
        case 'pieza':
        case 'unidad':
            step = 1;
            mensaje = '<strong>Piezas:</strong> Stock en unidades enteras (1, 2, 3 piezas...)';
            placeholder = 'Ej: 5, 10, 25 piezas';
            break;
        case 'kg':
        case 'kilo':
        case 'kilogramo':
            step = 0.001;
            mensaje = '<strong>Kilos:</strong> Stock en kilogramos (puede usar decimales, ej: 1.5 kg)';
            placeholder = 'Ej: 1.5, 2.75, 0.5 kg';
            break;
        case 'litro':
        case 'l':
            step = 0.001;
            mensaje = '<strong>Litros:</strong> Stock en litros (puede usar decimales, ej: 1.75 L)';
            placeholder = 'Ej: 1.75, 2.5, 0.3 L';
            break;
        case 'tonelada':
        case 'ton':
            step = 0.001;
            mensaje = '<strong>Toneladas:</strong> Stock en toneladas (puede usar decimales)';
            placeholder = 'Ej: 1.5, 2.75 toneladas';
            break;
        default:
            step = 1;
            mensaje = 'Stock en unidades enteras';
            placeholder = 'Ej: 5, 10, 25';
    }
    
    $('.stock-input').each(function() {
        $(this).attr('step', step).attr('placeholder', placeholder).data('tipo', (step === 1) ? 'entero' : 'decimal');
    });
    
    $('.stock-unidad-indicador').html(mensaje);
}

            function sanitizarStock(input) {
                const tipo = $(input).data('tipo');
                let valor = $(input).val();
                if (valor === '' || valor === null) {
                    $(input).val(0);
                    return;
                }
                valor = valor.replace(',', '.');
                let numero = parseFloat(valor);
                if (isNaN(numero) || numero < 0) {
                    $(input).val(0);
                    return;
                }
                if (tipo === 'entero') $(input).val(Math.round(numero));
                else if (tipo === 'decimal') $(input).val((Math.round(numero * 1000) / 1000).toString().replace(/\.?0+$/, ''));
            }

            function prevenirCaracteresNoPermitidos(e, input) {
                const tipo = $(input).data('tipo');
                if (e.key === 'Backspace' || e.key === 'Delete' || e.key === 'Tab' || e.key === 'Escape' || e.key === 'Enter' || e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Home' || e.key === 'End') return;
                if (tipo === 'entero' && !/^\d$/.test(e.key)) {
                    e.preventDefault();
                    return false;
                }
                if (tipo === 'decimal') {
                    if (/^\d$/.test(e.key)) return;
                    if (e.key === '.' && !$(input).val().includes('.')) return;
                    e.preventDefault();
                    return false;
                }
            }

            $('#unidad_medida').on('change', function() {
                actualizarValidacionStockPorUnidad();
            });
            $(document).on('keydown', '.stock-input', function(e) {
                prevenirCaracteresNoPermitidos(e, this);
            });
            $(document).on('keyup', '.stock-input', function() {
                sanitizarStock(this);
            });
            $(document).on('blur', '.stock-input', function() {
                if ($(this).val() === '' || $(this).val() === null) $(this).val(0);
            });

            function calcularPrecioDesdeDescuento() {
                const isMobile = window.innerWidth < 768;
                const subprecio = isMobile ? parseFloat($('#subprecio_mobile').val()) || 0 : parseFloat($('#subprecio_desktop').val()) || 0;
                const descuento = isMobile ? parseFloat($('#descuento_mobile').val()) || 0 : parseFloat($('#descuento_desktop').val()) || 0;
                if (subprecio <= 0) return;
                let precioFinal = subprecio;
                if (descuento > 0 && descuento <= 100) precioFinal = subprecio - (subprecio * (descuento / 100));
                if (isMobile) $('#precio_mobile').val(precioFinal.toFixed(2));
                else $('#precio_desktop').val(precioFinal.toFixed(2));
                sincronizarCamposPrecio();
            }

            function calcularDescuentoDesdePrecio() {
                const isMobile = window.innerWidth < 768;
                const subprecio = isMobile ? parseFloat($('#subprecio_mobile').val()) || 0 : parseFloat($('#subprecio_desktop').val()) || 0;
                const precioFinal = isMobile ? parseFloat($('#precio_mobile').val()) || 0 : parseFloat($('#precio_desktop').val()) || 0;
                if (subprecio <= 0 || precioFinal <= 0 || precioFinal >= subprecio) {
                    if (isMobile) $('#descuento_mobile').val(0);
                    else $('#descuento_desktop').val(0);
                    return;
                }
                const descuento = ((subprecio - precioFinal) / subprecio) * 100;
                if (isMobile) $('#descuento_mobile').val(Math.min(100, descuento).toFixed(2));
                else $('#descuento_desktop').val(Math.min(100, descuento).toFixed(2));
                sincronizarCamposPrecio();
            }

 function cargarValoresPrecio(productoData) {
    const isMobile = window.innerWidth < 768;
    if (isMobile) {
        $('#subprecio_mobile').val(productoData.subprecio);
        $('#descuento_mobile').val(productoData.descuento);
        $('#precio_mobile').val(productoData.precio);
        $('#costo_mobile').val(productoData.costo || '');
        $('#utilidad_mobile').val(productoData.utilidad || '');
    } else {
        $('#subprecio_desktop').val(productoData.subprecio);
        $('#descuento_desktop').val(productoData.descuento);
        $('#precio_desktop').val(productoData.precio);
        $('#costo_desktop').val(productoData.costo || '');
        $('#utilidad_desktop').val(productoData.utilidad || '');
    }
    sincronizarCamposPrecio();
}

            $('#subprecio_desktop, #descuento_desktop, #subprecio_mobile, #descuento_mobile').on('input', function() {
    calcularPrecioDesdeDescuento();
});
           $('#precio_desktop, #precio_mobile').on('input', function() {
    calcularDescuentoDesdePrecio();
});

// Nuevos eventos para Utilidad y Costo
$('#costo_desktop, #costo_mobile').on('input', function() {
    calcularPrecioDesdeUtilidad();
});
$('#utilidad_desktop, #utilidad_mobile').on('input', function() {
    calcularPrecioDesdeUtilidad();
});
$('#subprecio_desktop, #subprecio_mobile').on('input', function() {
    calcularUtilidadDesdePrecio();
    calcularPrecioDesdeDescuento();
});

            function actualizarCamposPorUnidad() {
                const unidad = $('#unidad_medida').val();
                const permiteFraccionesCheckbox = $('#permite_fracciones');
                const fraccionesHelper = $('#fracciones_helper');
                const fraccionesContainer = permiteFraccionesCheckbox.closest('.col-md-6');
                const pesoLabel = $('#peso_label');
                const pesoHelper = $('#peso_helper');
                const pesoContainer = $('#peso_kg').closest('.col-md-6');

                if (unidad === 'kilo' || unidad === 'litro') {
                    // Para kilos y litros: mostrar opción de fracciones
                    fraccionesContainer.show();
                    permiteFraccionesCheckbox.prop('checked', true).prop('disabled', true);
                    fraccionesHelper.html(`<strong>Para ${unidad}s:</strong> permite vender fracciones (ej: 0.5 ${unidad})`);

                    // Mostrar campo de peso
                    pesoContainer.show();
                    if (unidad === 'kilo') {
                        pesoLabel.text('Peso por Unidad (kg)');
                        pesoHelper.text('Peso de cada unidad en kilogramos (ej: 1 kg = 1.000)');
                    } else if (unidad === 'litro') {
                        pesoLabel.text('Volumen por Unidad (L)');
                        pesoHelper.text('Volumen de cada unidad en litros (ej: 1 L = 1.000)');
                    }

                } else if (unidad === 'pieza') {
                    // Para piezas: OCULTAR opción de fracciones
                    fraccionesContainer.hide();
                    permiteFraccionesCheckbox.prop('checked', false).prop('disabled', false);

                    // Cambiar textos para indicar PIEZAS
                    pesoLabel.text('Piezas');
                    pesoHelper.html('Piezas');

                    // Asegurar que el campo de peso sea visible pero con el texto correcto
                    pesoContainer.show();

                    // Actualizar también los campos de stock para que usen el nuevo texto
                    actualizarValidacionStockPorUnidad();

                } else {
                    // Para otros casos
                    fraccionesContainer.show();
                    permiteFraccionesCheckbox.prop('disabled', false);
                    fraccionesHelper.html('Permite vender fracciones del producto (ej: 0.5 unidades)');

                    pesoContainer.show();
                    pesoLabel.text('Piezas');
                    pesoHelper.text('Piezas');
                }
            }
            $('#unidad_medida').on('change', actualizarCamposPorUnidad);

            // =============================================
            // ENVÍO DEL FORMULARIO CON AJAX PARA MANEJAR IMÁGENES
            // =============================================

            // Reemplazar el evento submit original
            $('#productoForm').off('submit').on('submit', function(e) {
                e.preventDefault();

                sincronizarCamposPrecio();

                if (mayoreoHabilitado && !validarReglasMayoreo()) {
                    return false;
                }

                const reglasValidas = reglasMayoreo.filter(r => r.cantidad > 0 && r.precio > 0);
                $('#precios_mayoreo').val(JSON.stringify(reglasValidas));

                const subprecio = parseFloat($('#subprecio_hidden').val());
                const precio = parseFloat($('#precio_hidden').val());

                // if (!subprecio || subprecio <= 0) {
                //     alert('El precio original es requerido y debe ser mayor a 0.');
                //     return false;
                // }

                // if (!precio || precio <= 0) {
                //     alert('El precio final es requerido y debe ser mayor a 0.');
                //     return false;
                // }

                if ($('.sucursal-checkbox:checked').length === 0) {
                    alert('Debe seleccionar al menos una sucursal para el producto.');
                    return false;
                }

                let stockValido = true;
                $('.sucursal-checkbox:checked').each(function() {
                    if (parseFloat($('#stock_' + $(this).val()).val()) < 0) stockValido = false;
                });

                if (!stockValido) {
                    alert('El stock no puede ser negativo.');
                    return false;
                }

                const totalImagenes = imagenesExistentes.length + nuevasImagenes.length;
                if (totalImagenes > 5) {
                    alert('Solo puedes tener hasta 5 imágenes por producto.');
                    return false;
                }

                // Crear FormData y agregar todos los datos del formulario
                const formData = new FormData(this);

                // Eliminar el campo imagenes[] original (puede estar vacío o no contener los archivos de cámara)
                formData.delete('imagenes[]');

                // Agregar cada archivo de nuevasImagenes al FormData
                for (let i = 0; i < nuevasImagenes.length; i++) {
                    if (nuevasImagenes[i].file) {
                        formData.append('imagenes[]', nuevasImagenes[i].file);
                    }
                }

                // Mostrar indicador de carga y DESHABILITAR el botón Guardar
                const submitBtn = $(this).find('button[type="submit"]');
                const originalBtnText = submitBtn.html();
                submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...').prop('disabled', true);

                // También deshabilitar el botón Cancelar (opcional, pero recomendado)
                const cancelBtn = $(this).closest('.modal-content').find('.btn-secondary');
                if (cancelBtn.length) {
                    cancelBtn.prop('disabled', true);
                }

                // Enviar con AJAX
                $.ajax({
                    url: $(this).attr('action') || window.location.href,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        // Verificar si hay mensaje de éxito
                        if (response.includes('Producto creado') || response.includes('Producto actualizado') || response.includes('exitosa')) {
                            mostrarMensajeTemporal('Producto guardado exitosamente', 'success');
                            setTimeout(() => {
                                window.location.reload();
                            }, 1500);
                        } else {
                            // Buscar mensaje de alerta en la respuesta
                            const tempDiv = $('<div>').html(response);
                            const alertMsg = tempDiv.find('.alert');
                            if (alertMsg.length) {
                                mostrarMensajeTemporal(alertMsg.text(), 'info');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                mostrarMensajeTemporal('Producto guardado exitosamente', 'success');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1500);
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error al guardar:', error);
                        mostrarMensajeTemporal('Error al guardar el producto. Intenta de nuevo.', 'danger');
                        // REHABILITAR el botón en caso de error
                        submitBtn.html(originalBtnText).prop('disabled', false);
                        if (cancelBtn.length) {
                            cancelBtn.prop('disabled', false);
                        }
                    },
                    complete: function() {
                        // Nota: No rehabilitamos el botón en complete porque en caso de éxito
                        // la página se recargará, y en caso de error ya lo rehabilitamos arriba.
                    }
                });

                return false;
            });

            // =============================================
            // CONTROL DEL SIDEBAR
            // =============================================
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function openSidebar() {
                if (sidebar && sidebarBackdrop) {
                    sidebar.classList.add('show');
                    sidebarBackdrop.classList.add('show');
                    document.body.classList.add('sidebar-open');
                    document.body.style.overflow = 'hidden';
                }
            }

            function closeSidebar() {
                if (sidebar && sidebarBackdrop) {
                    sidebar.classList.remove('show');
                    sidebarBackdrop.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                    document.body.style.overflow = '';
                }
            }

            function toggleSidebar() {
                if (sidebar.classList.contains('show')) closeSidebar();
                else openSidebar();
            }
            if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebar);
            if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);
            document.querySelectorAll('#sidebar .nav-link').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) closeSidebar();
                });
            });

            // =============================================
            // FUNCIONES PARA NUEVO/EDITAR/CLONAR PRODUCTO
            // =============================================

            function nuevoProducto() {
                <?php if ($limite_alcanzado): ?>
                    mostrarMensajeTemporal('Ha alcanzado el límite de productos para su plan (<?php echo $limite_productos; ?> productos). Actualice su plan.', 'danger');
                    return;
                <?php endif; ?>

                // SOLO cambiar el título y la acción - NO limpiar nada
                $('#modalTitle').text('Nuevo Producto');
                $('#formAction').val('crear');
                $('#productoId').val('');

                if (mayoreoHabilitado) {
                    $('#mayoreoSection').show();
                    $('#btnAgregarReglaMayoreo').show();
                }

                $('.sucursal-checkbox:checked').each(function() {
                    $('#stock_fields_' + $(this).val()).show();
                });

                actualizarCamposPorUnidad();
                actualizarValidacionStockPorUnidad();

                $('#productoModal').modal('show');
            }

            function abrirModalEdicionProducto(productoData) {
    $('#modalTitle').text('Editar Producto');
    $('#formAction').val('editar');
    $('#productoId').val(productoData.id);
    $('#productoActivo').val(productoData.activo !== undefined ? productoData.activo : 1);
    $('#codigo').val(productoData.codigo);
    $('#nombre').val(productoData.nombre);
    $('#descripcion').val(productoData.descripcion || '');
    $('#marca').val(productoData.marca || '');
    cargarValoresPrecio(productoData);
    
    // ⭐ NUEVO: Asignar el costo explícitamente
    if (productoData.costo !== undefined && productoData.costo !== null) {
        const isMobile = window.innerWidth < 768;
        if (isMobile) {
            $('#costo_mobile').val(productoData.costo);
        } else {
            $('#costo_desktop').val(productoData.costo);
        }
        sincronizarCamposPrecio();
    }
    
    $('#categoria_id').val(productoData.categoria_id || '');
    $('#proveedor_id').val(productoData.proveedor_id || '');
    $('#unidad_medida').val(productoData.unidad_medida || 'pieza');
    $('#peso_kg').val(productoData.peso_kg || '1.000');
    $('#permite_fracciones').prop('checked', productoData.permite_fracciones == 1);
    $('#fecha_caducidad').val(productoData.fecha_caducidad);
    $('#tipo_producto').val(productoData.tipo_producto || 'Estandar');
    $('#imagenes_existentes').val(JSON.stringify(productoData.imagenes || []));
    $('#porcentaje_merma_danado').val(productoData.porcentaje_merma_danado || 0);
    $('#porcentaje_merma_deshidratacion').val(productoData.porcentaje_merma_deshidratacion || 0);
    $('#aplicar_merma_venta').prop('checked', productoData.aplicar_merma_venta == 1);
    $('#aplicar_merma_compra').prop('checked', productoData.aplicar_merma_compra == 1);
    resetearGaleriaImagenes();
    if (productoData.imagenes && productoData.imagenes.length > 0) inicializarGaleriaImagenes(productoData.imagenes);
    cargarReglasMayoreo(productoData.precios_mayoreo);
    $('.sucursal-checkbox').prop('checked', false);
    $('.stock-fields').hide();
    $('.stock-input').val(0);
    productoData.sucursales.forEach(sucursalId => {
        if (sucursalId) {
            $('#sucursal_' + sucursalId).prop('checked', true);
            $('#stock_fields_' + sucursalId).show();
            if (productoData.stocks && productoData.stocks[sucursalId]) $('#stock_' + sucursalId).val(productoData.stocks[sucursalId].stock || 0);
        }
    });
    toggleNuevaCategoria(false);
    toggleNuevoProveedor(false);
    actualizarCamposPorUnidad();
    actualizarValidacionStockPorUnidad();
    $('#productoModal').modal('show');
}

            function clonarProducto(productoData) {
                <?php if ($limite_alcanzado): ?>
                    mostrarMensajeTemporal('Ha alcanzado el límite de productos para su plan (<?php echo $limite_productos; ?> productos). Actualice su plan.', 'danger');
                    return;
                <?php endif; ?>
                $('#productoForm')[0].reset();
                $('#modalTitle').text('Clonar Producto: ' + productoData.nombre);
                $('#formAction').val('crear');
                $('#productoId').val('');
                $('#nombre').val(productoData.nombre + ' (Copia)');
                $('#descripcion').val(productoData.descripcion || '');
                $('#marca').val(productoData.marca || '');
                cargarValoresPrecio(productoData);
                let codigoClonado = String(productoData.codigo || '');
                if (codigoClonado && !codigoClonado.startsWith('S')) codigoClonado = 'S' + codigoClonado;
                $('#codigo').val(codigoClonado).removeClass('codigo-autogenerado');
                resetearGaleriaImagenes();
                cargarReglasMayoreo(productoData.precios_mayoreo || []);
                $('#categoria_id').val(productoData.categoria_id || '');
                $('#proveedor_id').val(productoData.proveedor_id || '');
                $('#unidad_medida').val(productoData.unidad_medida || 'pieza');
                $('#peso_kg').val(productoData.peso_kg || '1.000');
                $('#permite_fracciones').prop('checked', productoData.permite_fracciones == 1);
                $('#fecha_caducidad').val(productoData.fecha_caducidad || '');
                $('#tipo_producto').val(productoData.tipo_producto || 'Estandar');
                $('#porcentaje_merma_danado').val(productoData.porcentaje_merma_danado || 0);
                $('#porcentaje_merma_deshidratacion').val(productoData.porcentaje_merma_deshidratacion || 0);
                $('#aplicar_merma_venta').prop('checked', productoData.aplicar_merma_venta == 1);
                $('#aplicar_merma_compra').prop('checked', productoData.aplicar_merma_compra == 1);
                $('.sucursal-checkbox').prop('checked', false);
                $('.stock-fields').hide();
                $('.stock-input').val(0);
                if (productoData.sucursales && productoData.sucursales.length > 0) {
                    productoData.sucursales.forEach(sucursalId => {
                        if (sucursalId) {
                            $('#sucursal_' + sucursalId).prop('checked', true);
                            $('#stock_fields_' + sucursalId).show();
                            if (productoData.stocks && productoData.stocks[sucursalId]) $('#stock_' + sucursalId).val(productoData.stocks[sucursalId].stock || 0);
                        }
                    });
                }
                toggleNuevaCategoria(false);
                toggleNuevoProveedor(false);
                actualizarCamposPorUnidad();
                actualizarValidacionStockPorUnidad();
                $('#productoModal').modal('show');
            }

            function reinicializarEventosProductos() {
                $('.edit-producto').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    abrirModalEdicionProducto({
                        id: $(this).data('id'),
                        activo: $(this).data('activo') !== undefined ? $(this).data('activo') : 1,
                        codigo: $(this).data('codigo'),
                        nombre: $(this).data('nombre'),
                        descripcion: $(this).data('descripcion'),
                        marca: $(this).data('marca'),
                        precio: $(this).data('precio'),
                        subprecio: $(this).data('subprecio') || $(this).data('precio'),
                        descuento: $(this).data('descuento') || 0,
                        costo: $(this).data('costo'),
                        categoria_id: $(this).data('categoria_id'),
                        proveedor_id: $(this).data('proveedor_id'),
                        unidad_medida: $(this).data('unidad_medida'),
                        peso_kg: $(this).data('peso_kg'),
                        permite_fracciones: $(this).data('permite_fracciones'),
                        fecha_caducidad: $(this).data('fecha_caducidad') || '',
                        tipo_producto: $(this).data('tipo_producto') || 'Estandar',
                        porcentaje_merma_danado: $(this).data('porcentaje_merma_danado') || 0,
                        porcentaje_merma_deshidratacion: $(this).data('porcentaje_merma_deshidratacion') || 0,
                        aplicar_merma_venta: $(this).data('aplicar_merma_venta') || 0,
                        aplicar_merma_compra: $(this).data('aplicar_merma_compra') || 0,
                        imagenes: $(this).data('imagenes') || [],
                        sucursales: $(this).data('sucursales') ? $(this).data('sucursales').toString().split(',').filter(id => id !== '') : [],
                        stocks: $(this).data('stocks') || {},
                        precios_mayoreo: $(this).data('precios-mayoreo') || []
                    });
                });
                $('.clone-producto').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clonarProducto({
                        id: $(this).data('id'),
                        codigo: $(this).data('codigo'),
                        nombre: $(this).data('nombre'),
                        descripcion: $(this).data('descripcion'),
                        marca: $(this).data('marca'),
                        precio: $(this).data('precio'),
                        subprecio: $(this).data('subprecio') || $(this).data('precio'),
                        descuento: $(this).data('descuento') || 0,
                        costo: $(this).data('costo'),
                        categoria_id: $(this).data('categoria_id'),
                        proveedor_id: $(this).data('proveedor_id'),
                        unidad_medida: $(this).data('unidad_medida'),
                        peso_kg: $(this).data('peso_kg'),
                        permite_fracciones: $(this).data('permite_fracciones'),
                        fecha_caducidad: $(this).data('fecha_caducidad') || '',
                        tipo_producto: $(this).data('tipo_producto') || 'Estandar',
                        porcentaje_merma_danado: $(this).data('porcentaje_merma_danado') || 0,
                        porcentaje_merma_deshidratacion: $(this).data('porcentaje_merma_deshidratacion') || 0,
                        aplicar_merma_venta: $(this).data('aplicar_merma_venta') || 0,
                        aplicar_merma_compra: $(this).data('aplicar_merma_compra') || 0,
                        imagenes: $(this).data('imagenes') || [],
                        sucursales: $(this).data('sucursales') ? $(this).data('sucursales').toString().split(',').filter(id => id !== '') : [],
                        stocks: $(this).data('stocks') || {},
                        precios_mayoreo: $(this).data('precios-mayoreo') || []
                    });
                });
                $('.clone-producto-mobile').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clonarProducto({
                        id: $(this).data('id'),
                        codigo: $(this).data('codigo'),
                        nombre: $(this).data('nombre'),
                        descripcion: $(this).data('descripcion'),
                        marca: $(this).data('marca'),
                        precio: $(this).data('precio'),
                        subprecio: $(this).data('subprecio') || $(this).data('precio'),
                        descuento: $(this).data('descuento') || 0,
                        costo: $(this).data('costo'),
                        categoria_id: $(this).data('categoria_id'),
                        proveedor_id: $(this).data('proveedor_id'),
                        unidad_medida: $(this).data('unidad_medida'),
                        peso_kg: $(this).data('peso_kg'),
                        permite_fracciones: $(this).data('permite_fracciones'),
                        fecha_caducidad: $(this).data('fecha_caducidad') || '',
                        tipo_producto: $(this).data('tipo_producto') || 'Estandar',
                        porcentaje_merma_danado: $(this).data('porcentaje_merma_danado') || 0,
                        porcentaje_merma_deshidratacion: $(this).data('porcentaje_merma_deshidratacion') || 0,
                        aplicar_merma_venta: $(this).data('aplicar_merma_venta') || 0,
                        aplicar_merma_compra: $(this).data('aplicar_merma_compra') || 0,
                        imagenes: $(this).data('imagenes') || [],
                        sucursales: $(this).data('sucursales') ? $(this).data('sucursales').toString().split(',').filter(id => id !== '') : [],
                        stocks: $(this).data('stocks') || {},
                        precios_mayoreo: $(this).data('precios-mayoreo') || []
                    });
                });
                $('.cambiar-estado-btn, .cambiar-estado-btn-mobile').off('click').on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const id = $(this).data('id');
                    const nuevoActivo = $(this).data('activo');
                    const texto = nuevoActivo == 1 ? 'activar' : 'desactivar';
                    if (confirm(`¿Deseas ${texto} este producto?`)) {
                        $.ajax({
                            url: 'productos.php',
                            type: 'POST',
                            data: {
                                accion: 'cambiar_estado',
                                id: id,
                                activo: nuevoActivo
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    mostrarMensajeTemporal(response.message, 'success');
                                    cargarProductosConFiltros();
                                } else {
                                    mostrarMensajeTemporal(response.message, 'danger');
                                }
                            },
                            error: function() {
                                mostrarMensajeTemporal('Error al cambiar el estado del producto', 'danger');
                            }
                        });
                    }
                });
                $('.producto-row').off('click').on('click', function(event) {
                    if (!$(event.target).closest('.btn-group-actions, .carousel-control-prev, .carousel-control-next, .carousel-indicators, .producto-imagen-carousel, .no-imagen-container, .carousel-item img, .cambiar-estado-btn').length) {
                        const editBtn = $(this).find('.edit-producto');
                        if (editBtn.length) editBtn.click();
                    }
                });
                $('.producto-card-mobile').off('click').on('click', function(event) {
                    if (!$(event.target).closest('.edit-producto-mobile, .clone-producto-mobile, .cambiar-estado-btn-mobile, .carousel-control-prev, .carousel-control-next, .producto-imagen-carousel, .no-imagen-container, .carousel-item img').length) {
                        const editBtn = $(this).find('.edit-producto-mobile');
                        if (editBtn.length) editBtn.click();
                    }
                });
            }

            function toggleNuevaCategoria(mostrar) {
                if (mostrar) {
                    $('#nuevaCategoriaRow').show();
                    $('#categoria_id').prop('disabled', true);
                    $('#nuevaCategoriaNombre').focus();
                } else {
                    $('#nuevaCategoriaRow').hide();
                    $('#categoria_id').prop('disabled', false);
                    $('#nuevaCategoriaNombre').val('');
                }
            }

            function toggleNuevoProveedor(mostrar) {
                if (mostrar) {
                    $('#nuevoProveedorRow').show();
                    $('#proveedor_id').prop('disabled', true);
                    $('#nuevoProveedorNombre').focus();
                } else {
                    $('#nuevoProveedorRow').hide();
                    $('#proveedor_id').prop('disabled', false);
                    $('#nuevoProveedorNombre').val('');
                }
            }

            function inicializarCamposPrecio() {
                sincronizarCamposPrecio();
                $('#subprecio_desktop, #costo_desktop, #precio_desktop, #utilidad_desktop').on('blur', function() {
    $(this).val(formatearDecimales($(this).val()));
    sincronizarCamposPrecio();
});
            }

            function obtenerDatosProductoDesdeElemento(elemento) {
                let targetElement = elemento.closest('.producto-row, .producto-card-mobile');
                if (!targetElement) return null;
                if (targetElement.classList.contains('producto-row')) {
                    const editButton = targetElement.querySelector('.edit-producto');
                    if (editButton) return {
                        id: editButton.dataset.id,
                        activo: editButton.dataset.activo !== undefined ? editButton.dataset.activo : 1,
                        codigo: editButton.dataset.codigo,
                        nombre: editButton.dataset.nombre,
                        descripcion: editButton.dataset.descripcion,
                        marca: editButton.dataset.marca,
                        precio: editButton.dataset.precio,
                        subprecio: editButton.dataset.subprecio,
                        descuento: editButton.dataset.descuento,
                        costo: editButton.dataset.costo,
                        categoria_id: editButton.dataset.categoria_id,
                        proveedor_id: editButton.dataset.proveedor_id,
                        unidad_medida: editButton.dataset.unidad_medida,
                        peso_kg: editButton.dataset.peso_kg,
                        permite_fracciones: editButton.dataset.permite_fracciones,
                        fecha_caducidad: editButton.dataset.fecha_caducidad,
                        tipo_producto: editButton.dataset.tipo_producto,
                        porcentaje_merma_danado: editButton.dataset.porcentaje_merma_danado,
                        porcentaje_merma_deshidratacion: editButton.dataset.porcentaje_merma_deshidratacion,
                        aplicar_merma_venta: editButton.dataset.aplicar_merma_venta,
                        aplicar_merma_compra: editButton.dataset.aplicar_merma_compra,
                        imagenes: editButton.dataset.imagenes ? JSON.parse(editButton.dataset.imagenes) : [],
                        sucursales: editButton.dataset.sucursales ? editButton.dataset.sucursales.toString().split(',').filter(id => id !== '') : [],
                        stocks: editButton.dataset.stocks ? JSON.parse(editButton.dataset.stocks) : {},
                        precios_mayoreo: editButton.dataset.preciosMayoreo ? JSON.parse(editButton.dataset.preciosMayoreo) : [],
                        utilidad: editButton.dataset.utilidad || '',
                    };
                } else if (targetElement.classList.contains('producto-card-mobile')) {
                    const editButtonMobile = targetElement.querySelector('.edit-producto-mobile');
                    if (editButtonMobile) return {
                        id: editButtonMobile.dataset.id,
                        activo: editButtonMobile.dataset.activo !== undefined ? editButtonMobile.dataset.activo : 1,
                        codigo: editButtonMobile.dataset.codigo,
                        nombre: editButtonMobile.dataset.nombre,
                        descripcion: editButtonMobile.dataset.descripcion,
                        marca: editButtonMobile.dataset.marca,
                        precio: editButtonMobile.dataset.precio,
                        subprecio: editButtonMobile.dataset.subprecio,
                        descuento: editButtonMobile.dataset.descuento,
                        costo: editButtonMobile.dataset.costo,
                        categoria_id: editButtonMobile.dataset.categoria_id,
                        proveedor_id: editButtonMobile.dataset.proveedor_id,
                        unidad_medida: editButtonMobile.dataset.unidad_medida,
                        peso_kg: editButtonMobile.dataset.peso_kg,
                        permite_fracciones: editButtonMobile.dataset.permite_fracciones,
                        fecha_caducidad: editButtonMobile.dataset.fecha_caducidad,
                        tipo_producto: editButtonMobile.dataset.tipo_producto,
                        porcentaje_merma_danado: editButtonMobile.dataset.porcentaje_merma_danado,
                        porcentaje_merma_deshidratacion: editButtonMobile.dataset.porcentaje_merma_deshidratacion,
                        aplicar_merma_venta: editButtonMobile.dataset.aplicar_merma_venta,
                        aplicar_merma_compra: editButtonMobile.dataset.aplicar_merma_compra,
                        imagenes: editButtonMobile.dataset.imagenes ? JSON.parse(editButtonMobile.dataset.imagenes) : [],
                        sucursales: editButtonMobile.dataset.sucursales ? editButtonMobile.dataset.sucursales.toString().split(',').filter(id => id !== '') : [],
                        stocks: editButtonMobile.dataset.stocks ? JSON.parse(editButtonMobile.dataset.stocks) : {},
                        precios_mayoreo: editButtonMobile.dataset.preciosMayoreo ? JSON.parse(editButtonMobile.dataset.preciosMayoreo) : [],
                        utilidad: editButtonMobile.dataset.utilidad || '',
                    };
                }
                return null;
            }

            function abrirModalEdicionDesdeClick(event, elemento) {
                if ($(event.target).closest('.btn-group-actions, .edit-producto, .edit-producto-mobile, .clone-producto, .clone-producto-mobile, .cambiar-estado-btn, .cambiar-estado-btn-mobile, form, .btn, .carousel-control-prev, .carousel-control-next, .carousel-indicators, .producto-imagen-carousel, .no-imagen-container, .carousel-item img').length) return;
                const productoData = obtenerDatosProductoDesdeElemento(elemento);
                if (productoData && productoData.id) abrirModalEdicionProducto(productoData);
            }

            $(document).on('click', '.producto-row', function(event) {
                abrirModalEdicionDesdeClick(event, this);
            });
            $(document).on('click', '.producto-card-mobile', function(event) {
                abrirModalEdicionDesdeClick(event, this);
            });

            // =============================================
            // EVENTOS DE FILTROS
            // =============================================

            $('#searchInput, #searchInputMobile').on('input', function() {
                clearTimeout(searchTimeout);
                $('#searchLoading').show();
                searchTimeout = setTimeout(() => {
                    if ($(this).attr('id') === 'searchInput') $('#searchInputMobile').val($(this).val());
                    else $('#searchInput').val($(this).val());
                    aplicarFiltrosYRecargar(true);
                }, 500);
            });

            $('#filterCategoria, #filterProveedor, #filterSucursal, #showInactive').on('change', function() {
                aplicarFiltrosYRecargar(true);
            });
            $('#filterCategoriaMobile, #filterProveedorMobile, #filterSucursalMobile, #showInactiveMobile').on('change', function() {
                actualizarFiltrosDesdeMoviles();
                aplicarFiltrosYRecargar(true);
            });

            $('#btnAplicarFiltrosMobile').on('click', function() {
                actualizarFiltrosDesdeMoviles();
                aplicarFiltrosYRecargar(true);
                $('#filtrosPanel').removeClass('show');
            });

            $('#btnClearFilters, #btnClearFiltersMobile').on('click', function() {
                $('#searchInput, #searchInputMobile').val('');
                $('#filterCategoria, #filterCategoriaMobile').val('');
                $('#filterProveedor, #filterProveedorMobile').val('');
                $('#filterSucursal, #filterSucursalMobile').val('');
                $('#showInactive, #showInactiveMobile').prop('checked', false);
                aplicarFiltrosYRecargar(true);
            });

            // =============================================
            // OTROS EVENTOS
            // =============================================

            $('#btnNuevoProducto').on('click', nuevoProducto);

            $('#btnGenerarCodigo').on('click', function() {
                $.ajax({
                    url: 'generar_codigo.php',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#codigo').val(response.codigo).addClass('codigo-autogenerado');
                            setTimeout(() => $('#codigo').removeClass('codigo-autogenerado'), 2000);
                            $('#nombre').focus();
                        } else alert('Error al generar código: ' + response.message);
                    },
                    error: function() {
                        alert('Error de conexión al generar código');
                    }
                });
            });

            $('#btnSugerirCodigo').on('click', function() {
                if (!$('#codigo').val()) $('#btnGenerarCodigo').click();
                else {
                    $('#codigo').focus();
                    $('#codigo').select();
                }
            });

            $('.sucursal-checkbox').on('change', function() {
                const sucursalId = $(this).val();
                if ($(this).is(':checked')) $('#stock_fields_' + sucursalId).show();
                else {
                    $('#stock_fields_' + sucursalId).hide();
                    $('#stock_' + sucursalId).val(0);
                }
            });

            $('#btnNuevaCategoria').on('click', () => toggleNuevaCategoria(true));
            $('#btnCancelarCategoria').on('click', () => toggleNuevaCategoria(false));
            $('#btnGuardarCategoria').on('click', function() {
                const nombreCategoria = $('#nuevaCategoriaNombre').val().trim();
                if (!nombreCategoria) {
                    alert('Por favor ingresa un nombre para la categoría.');
                    return;
                }
                $(this).html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...').prop('disabled', true);
                $.ajax({
                    url: 'guardar_categoria.php',
                    type: 'POST',
                    data: {
                        accion: 'crear',
                        nombre: nombreCategoria
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const nuevaOpcion = new Option(response.nombre, response.categoria_id, true, true);
                            $('#categoria_id').append(nuevaOpcion).trigger('change');
                            toggleNuevaCategoria(false);
                            $('#filterCategoria, #filterCategoriaMobile').append(new Option(response.nombre, response.categoria_id));
                            alert(response.message);
                        } else alert('Error al crear la categoría: ' + response.message);
                    },
                    error: function() {
                        alert('Error de conexión al crear la categoría');
                    },
                    complete: function() {
                        $('#btnGuardarCategoria').html('<i class="fas fa-save me-2"></i>Guardar').prop('disabled', false);
                    }
                });
            });

            $('#btnNuevoProveedor').on('click', () => toggleNuevoProveedor(true));
            $('#btnCancelarProveedor').on('click', () => toggleNuevoProveedor(false));
            $('#btnGuardarProveedor').on('click', function() {
                const nombreProveedor = $('#nuevoProveedorNombre').val().trim();
                if (!nombreProveedor) {
                    alert('Por favor ingresa un nombre para el proveedor.');
                    return;
                }
                $(this).html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...').prop('disabled', true);
                $.ajax({
                    url: 'guardar_proveedor.php',
                    type: 'POST',
                    data: {
                        accion: 'crear',
                        nombre: nombreProveedor
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const nuevaOpcion = new Option(response.nombre, response.proveedor_id, true, true);
                            $('#proveedor_id').append(nuevaOpcion).trigger('change');
                            toggleNuevoProveedor(false);
                            $('#filterProveedor, #filterProveedorMobile').append(new Option(response.nombre, response.proveedor_id));
                            alert(response.message);
                        } else alert('Error al crear el proveedor: ' + response.message);
                    },
                    error: function() {
                        alert('Error de conexión al crear el proveedor');
                    },
                    complete: function() {
                        $('#btnGuardarProveedor').html('<i class="fas fa-save me-2"></i>Guardar').prop('disabled', false);
                    }
                });
            });

            $('#nuevaCategoriaNombre, #nuevoProveedorNombre').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    if ($(this).attr('id') === 'nuevaCategoriaNombre') $('#btnGuardarCategoria').click();
                    else $('#btnGuardarProveedor').click();
                }
            });

            // =============================================
            // TRANSFERENCIA DE STOCK ENTRE SUCURSALES
            // =============================================

            // Exclusión mutua: al cambiar un select deshabilita esa opción en el otro
            function sincronizarSelectsTransferencia() {
                const origenVal = $('#trans_sucursal_origen').val();
                const destinoVal = $('#trans_sucursal_destino').val();

                // Resetear todas las opciones en ambos selects
                $('#trans_sucursal_origen option, #trans_sucursal_destino option').prop('disabled', false);

                // Deshabilitar en destino la opción seleccionada en origen
                if (origenVal) {
                    $('#trans_sucursal_destino option[value="' + origenVal + '"]').prop('disabled', true);
                    // Si destino tenía ese mismo valor, limpiarlo
                    if ($('#trans_sucursal_destino').val() === origenVal) {
                        $('#trans_sucursal_destino').val('');
                    }
                }

                // Deshabilitar en origen la opción seleccionada en destino
                if (destinoVal) {
                    $('#trans_sucursal_origen option[value="' + destinoVal + '"]').prop('disabled', true);
                    // Si origen tenía ese mismo valor, limpiarlo
                    if ($('#trans_sucursal_origen').val() === destinoVal) {
                        $('#trans_sucursal_origen').val('');
                    }
                }
            }

            $(document).on('change', '#trans_sucursal_origen, #trans_sucursal_destino', function() {
                sincronizarSelectsTransferencia();
            });

            // Actualiza la sección Sucursales y Stock del modal con los nuevos stocks
            function refrescarStockSucursales(stocksActualizados) {
                $.each(stocksActualizados, function(sucId, stockVal) {
                    const $checkbox = $('#sucursal_' + sucId);
                    const $stockFields = $('#stock_fields_' + sucId);
                    const $input = $('#stock_' + sucId);

                    // Si el checkbox existe pero no estaba marcado (sucursal destino nueva),
                    // marcarlo y mostrar sus campos igual que cuando el usuario lo activa
                    if ($checkbox.length && !$checkbox.is(':checked')) {
                        $checkbox.prop('checked', true);
                        $stockFields.show();
                    }

                    // Actualizar el valor del input
                    if ($input.length) {
                        $input.val(stockVal);

                        // Destello visual para indicar el cambio
                        $stockFields
                            .addClass('stock-actualizado-highlight')
                            .delay(1500)
                            .queue(function(next) {
                                $(this).removeClass('stock-actualizado-highlight');
                                next();
                            });
                    }
                });
            }

            $(document).on('click', '#btnEjecutarTransferencia', function() {
                const productoId = $('#productoId').val();
                const origenId = $('#trans_sucursal_origen').val();
                const destinoId = $('#trans_sucursal_destino').val();
                const cantidad = parseFloat($('#trans_cantidad').val());
                const observaciones = $('#trans_observaciones').val().trim();
                const $resultado = $('#trans_resultado');

                // Validaciones del lado cliente
                if (!origenId || !destinoId) {
                    $resultado.show().html('<div class="alert alert-warning py-2 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Selecciona origen y destino.</div>');
                    return;
                }
                if (!cantidad || cantidad <= 0) {
                    $resultado.show().html('<div class="alert alert-warning py-2 mb-0"><i class="fas fa-exclamation-triangle me-1"></i>Ingresa una cantidad válida mayor a 0.</div>');
                    return;
                }

                const $btn = $(this);
                $btn.html('<i class="fas fa-spinner fa-spin me-1"></i>Transfiriendo...').prop('disabled', true);
                $resultado.hide().empty();

                $.ajax({
                    url: 'transferir_stock.php',
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        producto_id: parseInt(productoId),
                        sucursal_origen_id: parseInt(origenId),
                        sucursal_destino_id: parseInt(destinoId),
                        cantidad: cantidad,
                        observaciones: observaciones
                    }),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $resultado.show().html('<div class="alert alert-success py-2 mb-0">' + response.message + '</div>');

                            // Actualizar sección Sucursales y Stock
                            if (response.data && response.data.stocks_actualizados) {
                                refrescarStockSucursales(response.data.stocks_actualizados);
                            }

                            // Limpiar campos y restaurar opciones de los selects
                            $('#trans_sucursal_origen, #trans_sucursal_destino').val('');
                            $('#trans_sucursal_origen option, #trans_sucursal_destino option').prop('disabled', false);
                            $('#trans_cantidad').val('');
                            $('#trans_observaciones').val('');
                        } else {
                            $resultado.show().html('<div class="alert alert-danger py-2 mb-0"><i class="fas fa-times-circle me-1"></i>' + response.message + '</div>');
                        }
                    },
                    error: function() {
                        $resultado.show().html('<div class="alert alert-danger py-2 mb-0"><i class="fas fa-times-circle me-1"></i>Error de conexión al transferir stock.</div>');
                    },
                    complete: function() {
                        $btn.html('<i class="fas fa-paper-plane me-1"></i>Transferir').prop('disabled', false);
                    }
                });
            });

            $('#productoModal').on('hidden.bs.modal', function() {
                toggleNuevaCategoria(false);
                toggleNuevoProveedor(false);
                $('.alert-temp').remove();
            });

            const filtrosToggle = document.getElementById('filtrosToggle');
            const filtrosPanel = document.getElementById('filtrosPanel');
            if (filtrosToggle) {
                filtrosToggle.addEventListener('click', () => filtrosPanel.classList.toggle('show'));
                document.addEventListener('click', function(e) {
                    if (filtrosToggle && filtrosPanel && !filtrosToggle.contains(e.target) && !filtrosPanel.contains(e.target)) filtrosPanel.classList.remove('show');
                });
            }

            let archivoSeleccionado = null;
            $('#btnImportarProductos').on('click', function() {
                $('#importarModal').modal('show');
                $('#archivoImportar').val('');
                $('#importProgress').hide();
                $('#importResult').hide();
            });
            $('#archivoImportar').on('change', function(e) {
                archivoSeleccionado = e.target.files[0];
                if (archivoSeleccionado && archivoSeleccionado.size > 5 * 1024 * 1024) {
                    alert('El archivo es demasiado grande. Máximo 5MB.');
                    $(this).val('');
                    archivoSeleccionado = null;
                }
            });
            $('#btnProcesarImportacion').on('click', function() {
                if (!archivoSeleccionado) {
                    alert('Por favor selecciona un archivo');
                    return;
                }
                if (!confirm('¿Estás seguro de importar productos? Esta acción no se puede deshacer.')) return;
                $('#importProgress').show();
                $('#importProgressBar').css('width', '50%').text('Procesando...');
                $('#importResult').hide();
                $(this).prop('disabled', true);
                const formData = new FormData();
                formData.append('archivo', archivoSeleccionado);
                $.ajax({
                    url: 'importar_productos.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        $('#importProgressBar').css('width', '100%').text('Completado');
                        setTimeout(() => {
                            $('#importProgress').hide();
                            $('#importResult').show();
                            if (response.success) {
                                $('#importResultAlert').removeClass('alert-danger').addClass('alert-success');
                                $('#importResultTitle').html('<i class="fas fa-check-circle me-2"></i>Importación Exitosa');
                                $('#importResultMessage').text(response.message);
                                if (response.errores && response.errores.length > 0) {
                                    let erroresHtml = '<hr><h6>Errores encontrados:</h6><ul class="mb-0">';
                                    response.errores.forEach(error => erroresHtml += `<li class="text-danger small">${error}</li>`);
                                    erroresHtml += '</ul>';
                                    $('#importResultErrors').html(erroresHtml);
                                } else $('#importResultErrors').empty();
                                setTimeout(() => window.location.reload(), 2000);
                            } else {
                                $('#importResultAlert').removeClass('alert-success').addClass('alert-danger');
                                $('#importResultTitle').html('<i class="fas fa-exclamation-triangle me-2"></i>Error');
                                $('#importResultMessage').text(response.message);
                                $('#importResultErrors').empty();
                            }
                        }, 500);
                    },
                    error: function(xhr, status, error) {
                        $('#importProgress').hide();
                        $('#importResult').show();
                        $('#importResultAlert').removeClass('alert-success').addClass('alert-danger');
                        $('#importResultTitle').html('<i class="fas fa-exclamation-triangle me-2"></i>Error');
                        $('#importResultMessage').text('Error de conexión: ' + error);
                        $('#importResultErrors').empty();
                    },
                    complete: function() {
                        $('#btnProcesarImportacion').prop('disabled', false);
                    }
                });
            });

            const urlParams = new URLSearchParams(window.location.search);
            filtrosActuales = {
                search: urlParams.get('search') || '',
                categoria: urlParams.get('categoria') || '',
                proveedor: urlParams.get('proveedor') || '',
                sucursal: urlParams.get('sucursal') || '',
                show_inactive: urlParams.get('show_inactive') === '1',
                pagina: parseInt(urlParams.get('pagina')) || 1
            };
            $('#searchInput, #searchInputMobile').val(filtrosActuales.search);
            $('#filterCategoria, #filterCategoriaMobile').val(filtrosActuales.categoria);
            $('#filterProveedor, #filterProveedorMobile').val(filtrosActuales.proveedor);
            $('#filterSucursal, #filterSucursalMobile').val(filtrosActuales.sucursal);
            $('#showInactive, #showInactiveMobile').prop('checked', filtrosActuales.show_inactive);

            if (filtrosActuales.search || filtrosActuales.categoria || filtrosActuales.proveedor || filtrosActuales.sucursal || filtrosActuales.show_inactive) {
                cargarProductosConFiltros();
            }

            actualizarCamposPorUnidad();
            actualizarValidacionStockPorUnidad();
            inicializarCamposPrecio();
        });