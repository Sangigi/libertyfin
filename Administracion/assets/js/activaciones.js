// =============================================
// JAVASCRIPT ESPECÍFICO DE ACTIVACIONES
// =============================================

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // =============================================
        // INICIALIZAR TOOLTIPS
        // =============================================
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // =============================================
        // BUSCADOR EN CLIENTE
        // =============================================
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control form-control-sm';
        searchInput.placeholder = 'Buscar en la tabla...';
        searchInput.style.maxWidth = '300px';
        searchInput.style.marginBottom = '15px';
        searchInput.style.display = 'inline-block';

        const tableContainer = document.querySelector('.table-responsive');
        if (tableContainer) {
            const wrapper = tableContainer.parentElement;
            if (wrapper) {
                const searchWrapper = document.createElement('div');
                searchWrapper.className = 'd-flex justify-content-end p-3';
                searchWrapper.appendChild(searchInput);
                wrapper.insertBefore(searchWrapper, tableContainer);
            }
        }

        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const table = document.getElementById('activacionesTable');
            if (!table) return;

            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // =============================================
        // VALIDACIÓN DE FECHAS
        // =============================================
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                const fechaInicio = this.fecha_inicio.value;
                const fechaFin = this.fecha_fin.value;
                
                if (fechaInicio && fechaFin && fechaInicio > fechaFin) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Error',
                        text: 'La fecha de inicio no puede ser mayor que la fecha fin',
                        icon: 'error',
                        confirmButtonColor: '#dc3545'
                    });
                }
            });
        }

        // =============================================
        // SCROLL TÁCTIL MEJORADO
        // =============================================
        const tableContainers = document.querySelectorAll('.table-responsive');
        
        tableContainers.forEach(container => {
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

        console.log('Activaciones JS cargado correctamente');
    });

    // =============================================
    // FUNCIÓN PARA VER DETALLE
    // =============================================
    window.verDetalle = function(activacion) {
        let html = '';
        
        // Información general
        html += `
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <h6 class="mb-0">Información General</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Fecha:</strong> ${formatearFecha(activacion.fecha_activacion)}</p>
                            <p class="mb-2"><strong>Empresa:</strong> ${escapaHtml(activacion.nombre_empresa)}</p>
                            <p class="mb-2"><strong>Tipo:</strong> ${activacion.tipo.charAt(0).toUpperCase() + activacion.tipo.slice(1)}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-2"><strong>Usuario:</strong> ${activacion.usuario_activo || 'No registrado'}</p>
                            <p class="mb-2"><strong>Precio sin IVA:</strong> ${formatearMoneda(activacion.precio_sin_iva)}</p>
                            <p class="mb-2"><strong>Precio con IVA:</strong> ${formatearMoneda(activacion.precio_con_iva)}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Detalle según el tipo
        if (activacion.tipo === 'plan') {
            html += `
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">Cambio de Plan</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 text-center border-end">
                                <label class="text-muted d-block mb-2">Plan Anterior</label>
                                <span class="badge ${activacion.plan_anterior ? 'bg-secondary' : 'bg-light text-muted'} p-3" style="font-size: 1.2rem;">
                                    ${activacion.plan_anterior ? activacion.plan_anterior.toUpperCase() : 'N/A'}
                                </span>
                            </div>
                            <div class="col-md-6 text-center">
                                <label class="text-muted d-block mb-2">Plan Nuevo</label>
                                <span class="badge bg-success p-3" style="font-size: 1.2rem;">
                                    ${activacion.plan_nuevo.toUpperCase()}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else if (activacion.tipo === 'timbre') {
            html += `
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">Activación de Timbres</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <h3 class="text-primary">${activacion.cantidad}</h3>
                                <small class="text-muted">Timbres Adquiridos</small>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-info">${activacion.timbres_anteriores || 0}</h3>
                                <small class="text-muted">Timbres Anteriores</small>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-success">${activacion.timbres_nuevos || 0}</h3>
                                <small class="text-muted">Total Actual</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } else if (activacion.tipo === 'sucursal') {
            html += `
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0">Activación de Sucursales</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-4">
                                <h3 class="text-primary">${activacion.cantidad}</h3>
                                <small class="text-muted">Sucursales Nuevas</small>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-info">${activacion.sucursales_anteriores || 0}</h3>
                                <small class="text-muted">Sucursales Anteriores</small>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-success">${activacion.sucursales_nuevas || 0}</h3>
                                <small class="text-muted">Total Actual</small>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // Notas
        if (activacion.notas) {
            html += `
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Notas</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">${escapaHtml(activacion.notas)}</p>
                    </div>
                </div>
            `;
        }

        document.getElementById('detalleContent').innerHTML = html;
        new bootstrap.Modal(document.getElementById('modalDetalle')).show();
    };

    // =============================================
    // FUNCIÓN PARA FORMATEAR MONEDA
    // =============================================
    window.formatearMoneda = function(monto) {
        return '$' + parseFloat(monto).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,') + ' MXN';
    };

    // =============================================
    // FUNCIÓN PARA FORMATEAR FECHA
    // =============================================
    window.formatearFecha = function(fecha) {
        if (!fecha || fecha === '0000-00-00 00:00:00') return 'No registrada';
        const date = new Date(fecha);
        return date.toLocaleDateString('es-MX') + ' ' + date.toLocaleTimeString('es-MX', {hour: '2-digit', minute:'2-digit'});
    };

    // =============================================
    // FUNCIÓN PARA ESCAPAR HTML
    // =============================================
    window.escapaHtml = function(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    // =============================================
    // FUNCIÓN PARA EXPORTAR A EXCEL (CSV)
    // =============================================
    window.exportarExcel = function() {
        const table = document.getElementById('activacionesTable');
        if (!table) {
            Swal.fire({
                title: 'Error',
                text: 'No hay datos para exportar',
                icon: 'error',
                confirmButtonColor: '#dc3545'
            });
            return;
        }

        const rows = table.querySelectorAll('tbody tr');
        let csv = [];
        
        // Encabezados
        const headers = [];
        const headerCells = table.querySelectorAll('thead th');
        headerCells.forEach(th => {
            headers.push(th.innerText.trim());
        });
        csv.push(headers.join(','));

        // Datos
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                let rowData = [];
                const cells = row.querySelectorAll('td');
                cells.forEach(cell => {
                    let data = cell.innerText.trim().replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ');
                    data = data.replace(/"/g, '""');
                    rowData.push('"' + data + '"');
                });
                csv.push(rowData.join(','));
            }
        });

        if (csv.length <= 1) {
            Swal.fire({
                title: 'Sin datos',
                text: 'No hay registros visibles para exportar',
                icon: 'warning',
                confirmButtonColor: '#ffc107'
            });
            return;
        }

        const csvContent = csv.join('\n');
        const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', 'activaciones_' + new Date().toISOString().slice(0,10) + '.csv');
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        Swal.fire({
            title: 'Exportado',
            text: 'El archivo se ha descargado correctamente',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });
    };

})();