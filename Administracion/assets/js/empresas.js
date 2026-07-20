// =============================================
// JAVASCRIPT ESPECÍFICO DE EMPRESAS
// =============================================

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // =============================================
        // MODAL DE DETALLES
        // =============================================
        const modalDetalle = document.getElementById('modalDetalle');
        if (modalDetalle) {
            modalDetalle.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const empresaId = button.getAttribute('data-id');
                const modalBody = this.querySelector('#detalleEmpresa');

                // Mostrar cargando
                modalBody.innerHTML = `
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-3 text-muted">Cargando información de la empresa...</p>
                    </div>
                `;

                // Cargar detalles via AJAX
                fetch(`ajax_detalle_empresa.php?id=${empresaId}`)
                    .then(response => response.text())
                    .then(html => {
                        modalBody.innerHTML = html;
                    })
                    .catch(error => {
                        modalBody.innerHTML = `
                            <div class="alert alert-danger m-3">
                                <h6><i class="fas fa-exclamation-triangle me-2"></i>Error al cargar los detalles</h6>
                                <p class="mb-0">No se pudieron cargar los detalles de la empresa. Por favor, intente nuevamente.</p>
                            </div>
                        `;
                        console.error('Error:', error);
                    });
            });

            // Limpiar modal cuando se cierra
            modalDetalle.addEventListener('hidden.bs.modal', function() {
                const modalBody = this.querySelector('#detalleEmpresa');
                if (modalBody) {
                    modalBody.innerHTML = '';
                }
            });
        }

        // =============================================
        // AUTO-SELECCIONAR ÚLTIMO MES EN FILTROS DE FECHA
        // =============================================
        const hoy = new Date();
        const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
        const fechaDesdeInput = document.querySelector('input[name="fecha_desde"]');
        const fechaHastaInput = document.querySelector('input[name="fecha_hasta"]');

        if (fechaDesdeInput && !fechaDesdeInput.value) {
            fechaDesdeInput.value = primerDiaMes.toISOString().split('T')[0];
        }
        if (fechaHastaInput && !fechaHastaInput.value) {
            fechaHastaInput.value = hoy.toISOString().split('T')[0];
        }

        // =============================================
        // SCROLL TÁCTIL MEJORADO PARA LA TABLA
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

        // =============================================
        // CONFIRMACIÓN PARA ACCIONES DE ACTIVAR/DESACTIVAR
        // =============================================
        const confirmLinks = document.querySelectorAll('[onclick*="confirm"]');
        confirmLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                // El confirm ya está en el onclick, solo agregamos feedback visual
                const btn = this;
                const originalText = btn.innerHTML;
                
                // Guardar referencia para restaurar
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 100);
            });
        });

        console.log('Empresas JS cargado correctamente');
    });

})();