// =============================================
// JAVASCRIPT ESPECÍFICO DE USUARIOS
// =============================================

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // =============================================
        // MOSTRAR/OCULTAR CONTRASEÑA
        // =============================================
        window.togglePassword = function(inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            
            const icon = input.parentElement.querySelector('.password-toggle i');
            if (!icon) return;
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        };

        // =============================================
        // MODAL EDITAR USUARIO - CARGAR DATOS
        // =============================================
        const modalEditar = document.getElementById('modalEditarUsuario');
        if (modalEditar) {
            modalEditar.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                
                document.getElementById('edit_id').value = button.getAttribute('data-id');
                document.getElementById('edit_nombre').value = button.getAttribute('data-nombre') || '';
                document.getElementById('edit_apellidos').value = button.getAttribute('data-apellidos') || '';
                document.getElementById('edit_email').value = button.getAttribute('data-email') || '';
                document.getElementById('edit_rol').value = button.getAttribute('data-rol') || 'empleado';
                
                const activo = button.getAttribute('data-activo');
                document.getElementById('edit_activo').checked = activo === '1';
                
                // Limpiar campo de contraseña
                document.getElementById('edit_password').value = '';
            });
        }
        
        // =============================================
        // MODAL ELIMINAR USUARIO - CARGAR DATOS
        // =============================================
        const modalEliminar = document.getElementById('modalEliminarUsuario');
        if (modalEliminar) {
            modalEliminar.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                
                document.getElementById('delete_id').value = button.getAttribute('data-id');
                document.getElementById('delete_nombre').textContent = button.getAttribute('data-nombre') || '';
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
        // HACER LA TABLA RESPONSIVA EN MÓVILES
        // =============================================
        function makeTableResponsive() {
            if (window.innerWidth < 576) {
                const tableCells = document.querySelectorAll('tbody td');
                const headers = ['ID', 'Nombre', 'Email', 'Rol', 'Estado', 'Registro', 'Último Acceso', 'Acciones'];
                
                tableCells.forEach((cell, index) => {
                    const headerIndex = index % headers.length;
                    cell.setAttribute('data-label', headers[headerIndex]);
                });
            }
        }

        makeTableResponsive();
        window.addEventListener('resize', makeTableResponsive);

        // =============================================
        // SCROLL TÁCTIL MEJORADO EN LA TABLA
        // =============================================
        function setupTableTouchScrolling() {
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
        }
        
        setupTableTouchScrolling();

        // =============================================
        // CONFIRMACIÓN PARA ACCIONES
        // =============================================
        const confirmLinks = document.querySelectorAll('[onclick*="confirm"]');
        confirmLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                const btn = this;
                const originalText = btn.innerHTML;
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 100);
            });
        });

        console.log('Usuarios JS cargado correctamente');
    });

})();