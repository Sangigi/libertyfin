// =============================================
// JAVASCRIPT EXCLUSIVO DEL SIDEBAR
// =============================================

(function() {
    'use strict';

    // Elementos del DOM
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    var sidebarBackdrop = document.getElementById('sidebarBackdrop');
    var sidebarClose = document.getElementById('sidebarClose');

    // =============================================
    // FUNCIONES DE CONTROL
    // =============================================

    function openSidebar() {
        if (sidebar) {
            sidebar.classList.add('show');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.add('show');
            }
            document.body.style.overflow = 'hidden';
        }
    }

    function closeSidebar() {
        if (sidebar) {
            sidebar.classList.remove('show');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.remove('show');
            }
            document.body.style.overflow = '';
        }
    }

    function toggleSidebar() {
        if (sidebar && sidebar.classList.contains('show')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    // =============================================
    // EVENT LISTENERS
    // =============================================

    // Botón hamburguesa
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Backdrop (clic fuera del sidebar)
    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeSidebar);
    }

    // Botón cerrar del sidebar (móvil)
    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }

    // Cerrar sidebar al hacer clic en un enlace (móvil)
    if (sidebar) {
        var navLinks = sidebar.querySelectorAll('.nav-link');
        navLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth < 992) {
                    closeSidebar();
                }
            });
        });
    }

    // Cerrar sidebar al redimensionar a pantalla grande
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992) {
            closeSidebar();
        }
    });

    // =============================================
    // SWIPE PARA MÓVIL (abrir/cerrar con deslizamiento)
    // =============================================

    var touchStartX = 0;
    var touchStartY = 0;
    var touchEndX = 0;
    var touchEndY = 0;
    var isSwiping = false;
    var SWIPE_THRESHOLD = 50;
    var SWIPE_EDGE_ZONE = 30;
    var VERTICAL_THRESHOLD = 30;

    // Detectar si estamos en un elemento que no debe activar el swipe
    function isInsideElement(element, selectors) {
        while (element) {
            for (var i = 0; i < selectors.length; i++) {
                if (element.closest && element.closest(selectors[i])) {
                    return true;
                }
                if (element.classList && element.classList.contains(selectors[i].replace('.', ''))) {
                    return true;
                }
            }
            element = element.parentElement;
        }
        return false;
    }

    document.addEventListener('touchstart', function(e) {
        if (window.innerWidth >= 992) return;

        var touchX = e.touches[0].clientX;
        var touchY = e.touches[0].clientY;

        // Solo activar swipe cerca del borde izquierdo y no en elementos interactivos
        if (touchX <= SWIPE_EDGE_ZONE && 
            !isInsideElement(e.target, ['input', 'textarea', 'button', 'a', '.dropdown', '.modal'])) {
            touchStartX = touchX;
            touchStartY = touchY;
            isSwiping = true;
        }
    }, { passive: true });

    document.addEventListener('touchmove', function(e) {
        if (!isSwiping || window.innerWidth >= 992) return;

        var touchX = e.touches[0].clientX;
        var touchY = e.touches[0].clientY;
        touchEndX = touchX;
        touchEndY = touchY;

        var deltaX = touchEndX - touchStartX;
        var deltaY = touchEndY - touchStartY;

        if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
            e.preventDefault();
        }
    }, { passive: false });

    document.addEventListener('touchend', function(e) {
        if (!isSwiping || window.innerWidth >= 992) return;

        var deltaX = touchEndX - touchStartX;
        var deltaY = touchEndY - touchStartY;

        // Verificar que sea un swipe horizontal válido
        if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
            isSwiping = false;
            return;
        }

        var isSidebarOpen = sidebar && sidebar.classList.contains('show');

        // Swipe de izquierda a derecha (abrir)
        if (deltaX > SWIPE_THRESHOLD) {
            if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                openSidebar();
            }
        }
        // Swipe de derecha a izquierda (cerrar)
        else if (deltaX < -SWIPE_THRESHOLD) {
            if (isSidebarOpen) {
                closeSidebar();
            }
        }

        isSwiping = false;
        touchStartX = 0;
        touchStartY = 0;
        touchEndX = 0;
        touchEndY = 0;
    }, { passive: true });

})();