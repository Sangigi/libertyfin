// =============================================
// JAVASCRIPT EXCLUSIVO DEL NAVBAR
// =============================================

(function() {
    'use strict';

    // Cerrar dropdowns al hacer clic fuera
    document.addEventListener('click', function(event) {
        var dropdowns = document.querySelectorAll('.dropdown-menu');
        var toggles = document.querySelectorAll('.dropdown-toggle');
        
        dropdowns.forEach(function(dropdown) {
            if (!dropdown.contains(event.target)) {
                var parent = dropdown.closest('.dropdown');
                if (parent) {
                    var toggle = parent.querySelector('.dropdown-toggle');
                    if (toggle && !toggle.contains(event.target)) {
                        dropdown.classList.remove('show');
                    }
                }
            }
        });
    });

    // Cerrar navbar en móvil al hacer clic en un enlace
    var navLinks = document.querySelectorAll('.navbar .nav-link');
    var navbarCollapse = document.querySelector('.navbar-collapse');
    
    navLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                var toggler = document.querySelector('.navbar-toggler');
                if (toggler) {
                    toggler.click();
                }
            }
        });
    });
})();