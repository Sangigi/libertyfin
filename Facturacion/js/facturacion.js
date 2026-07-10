/**
 * Facturación - Funcionalidades JavaScript
 * Manejo de sidebar, productos, certificados y personalización
 */

// =============================================
// FUNCIONALIDAD DE SWIPE AUTOMÁTICO PARA SIDEBAR
// =============================================

// Variables para controlar el swipe
let touchStartX = 0;
let touchStartY = 0;
let touchEndX = 0;
let touchEndY = 0;
let isTouchActive = false;
const SWIPE_THRESHOLD = 50; // Mínimo de píxeles para considerar un swipe
const SWIPE_EDGE_ZONE = 30; // Zona del borde donde se activa el swipe
const VERTICAL_THRESHOLD = 30; // Máxima desviación vertical permitida

// Función para abrir el sidebar automáticamente
function openSidebarAuto() {
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (sidebar && sidebarBackdrop) {
        sidebar.classList.add('show');
        sidebarBackdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

// Función para cerrar el sidebar automáticamente
function closeSidebarAuto() {
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    if (sidebar && sidebarBackdrop) {
        sidebar.classList.remove('show');
        sidebarBackdrop.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Inicializar swipe detection
function initSwipeDetection() {
    // Detectar inicio del touch
    document.addEventListener('touchstart', function(e) {
        // Solo en dispositivos móviles
        if (window.innerWidth >= 768) return;

        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        touchEndX = touchStartX;
        touchEndY = touchStartY;
        isTouchActive = true;
    });

    // Detectar movimiento del touch
    document.addEventListener('touchmove', function(e) {
        if (!isTouchActive) return;

        touchEndX = e.touches[0].clientX;
        touchEndY = e.touches[0].clientY;

        // Calcular diferencia
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;

        // Solo prevenir el scroll si es un movimiento horizontal significativo
        if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 10) {
            e.preventDefault();
        }
    }, { passive: false });

    // Detectar fin del touch
    document.addEventListener('touchend', function(e) {
        if (!isTouchActive) return;

        isTouchActive = false;

        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;

        // Verificar que sea un swipe horizontal válido
        if (Math.abs(deltaY) > VERTICAL_THRESHOLD) {
            return; // Demasiada desviación vertical
        }

        const sidebar = document.getElementById('sidebar');
        const isSidebarOpen = sidebar && sidebar.classList.contains('show');

        // SWIPE DE IZQUIERDA A DERECHA (para abrir)
        if (deltaX > SWIPE_THRESHOLD) {
            // Solo abrir si empezó cerca del borde izquierdo
            if (touchStartX <= SWIPE_EDGE_ZONE && !isSidebarOpen) {
                openSidebarAuto();
            }
        }
        // SWIPE DE DERECHA A IZQUIERDA (para cerrar)
        else if (deltaX < -SWIPE_THRESHOLD) {
            // Cerrar si el sidebar está abierto
            if (isSidebarOpen) {
                closeSidebarAuto();
            }
        }

        // Resetear valores
        touchStartX = 0;
        touchStartY = 0;
        touchEndX = 0;
        touchEndY = 0;
    });
}

// =============================================
// FUNCIONALIDAD PARA LA PESTAÑA DE PRODUCTOS
// =============================================

function changeTab(tab) {
    console.log('Cambiando a pestaña:', tab);
    
    // Actualizar campo oculto
    const searchTypeInput = document.getElementById('search_type');
    if (searchTypeInput) {
        searchTypeInput.value = tab;
    }
    
    // Mostrar/ocultar campos según la pestaña
    const textGroup = document.getElementById('text-search-group');
    const skuGroup = document.getElementById('sku-search-group');
    
    if (textGroup) {
        textGroup.style.display = tab === 'text' ? 'block' : 'none';
    }
    
    if (skuGroup) {
        skuGroup.style.display = tab === 'sku' ? 'block' : 'none';
    }
    
    // Actualizar clases activas de pestañas
    document.querySelectorAll('.tab').forEach(function(element) {
        element.classList.remove('active');
        if (element.dataset.tab === tab) {
            element.classList.add('active');
        }
    });
    
    // Resetear otros campos de búsqueda según sea necesario
    if (tab !== 'text') {
        const qInput = document.getElementById('q');
        if (qInput) qInput.value = '';
    }
    
    if (tab !== 'sku') {
        const skuInput = document.getElementById('sku');
        if (skuInput) skuInput.value = '';
    }
    
    // Resetear a página 1
    const pageInput = document.querySelector('input[name="page"]');
    if (pageInput) pageInput.value = 1;
}

// Funciones para los botones de acción de productos
function verDetalleProducto(productoId) {
    console.log('Ver detalle del producto:', productoId);
    alert('Ver detalle del producto: ' + productoId);
}

function editarProducto(productoId) {
    console.log('Editar producto:', productoId);
    alert('Editar producto: ' + productoId);
}

function eliminarProducto(productoId) {
    if (confirm('¿Estás seguro de que deseas eliminar este producto?')) {
        console.log('Eliminar producto:', productoId);
        fetch('eliminar_producto.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ id: productoId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Producto eliminado correctamente');
                location.reload();
            } else {
                alert('Error al eliminar: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al eliminar el producto');
        });
    }
}

// Inicializar pestañas de productos
function initProductTabs() {
    // Configurar click en pestañas
    document.querySelectorAll('.tab[data-tab]').forEach(function(tab) {
        tab.addEventListener('click', function() {
            const tabType = this.dataset.tab;
            changeTab(tabType);
        });
    });
    
    // Inicializar pestañas según parámetros GET
    const urlParams = new URLSearchParams(window.location.search);
    const searchType = urlParams.get('search_type') || 'all';
    changeTab(searchType);
}

// =============================================
// FUNCIONALIDAD PARA LA PESTAÑA DE CERTIFICADOS
// =============================================

// Configurar arrastrar y soltar archivos
function setupFileUpload(dropAreaId, fileInputId, fileNameId) {
    const dropArea = document.getElementById(dropAreaId);
    const fileInput = document.getElementById(fileInputId);
    const fileName = document.getElementById(fileNameId);
    
    if (!dropArea || !fileInput) return;
    
    // Prevenir comportamientos por defecto
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    // Efectos visuales
    ['dragenter', 'dragover'].forEach(eventName => {
        dropArea.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        dropArea.style.backgroundColor = 'rgba(0, 123, 255, 0.1)';
        dropArea.style.borderColor = '#007bff';
    }
    
    function unhighlight() {
        dropArea.style.backgroundColor = '';
        dropArea.style.borderColor = '';
    }
    
    // Manejar drop
    dropArea.addEventListener('drop', handleDrop, false);
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        
        if (files.length > 0) {
            fileInput.files = files;
            updateFileName();
        }
    }
    
    // Click en el área
    dropArea.addEventListener('click', () => {
        fileInput.click();
    });
    
    // Cambio en el input
    fileInput.addEventListener('change', updateFileName);
    
    function updateFileName() {
        if (fileInput.files.length > 0) {
            if (fileName) {
                fileName.innerHTML = `<strong>Archivo seleccionado:</strong> ${fileInput.files[0].name}`;
            }
            dropArea.style.backgroundColor = 'rgba(40, 167, 69, 0.1)';
        } else {
            if (fileName) {
                fileName.innerHTML = '';
            }
        }
    }
}

// Toggle para mostrar/ocultar contraseña
function setupPasswordToggle() {
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        });
    }
}

// Limpiar formulario de certificados
function setupCertCleanup() {
    const btnLimpiar = document.getElementById('btnLimpiar');
    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function() {
            const cerFile = document.getElementById('cer_file');
            const keyFile = document.getElementById('key_file');
            const password = document.getElementById('password');
            
            if (cerFile) cerFile.value = '';
            if (keyFile) keyFile.value = '';
            if (password) password.value = '';
            
            const cerFileName = document.getElementById('cerFileName');
            const keyFileName = document.getElementById('keyFileName');
            
            if (cerFileName) cerFileName.innerHTML = '';
            if (keyFileName) keyFileName.innerHTML = '';
            
            const cerDropArea = document.getElementById('cerDropArea');
            const keyDropArea = document.getElementById('keyDropArea');
            
            if (cerDropArea) {
                cerDropArea.style.backgroundColor = '';
                cerDropArea.style.borderColor = '';
            }
            
            if (keyDropArea) {
                keyDropArea.style.backgroundColor = '';
                keyDropArea.style.borderColor = '';
            }
        });
    }
}

// Validar formulario de certificados
function initCertFormValidation() {
    const formCertificado = document.getElementById('formCertificado');
    if (formCertificado) {
        formCertificado.addEventListener('submit', function(e) {
            const cerFile = document.getElementById('cer_file');
            const keyFile = document.getElementById('key_file');
            const password = document.getElementById('password');
            
            if (!cerFile || !cerFile.files.length) {
                e.preventDefault();
                alert('Debes seleccionar un archivo .cer');
                return false;
            }
            
            if (!keyFile || !keyFile.files.length) {
                e.preventDefault();
                alert('Debes seleccionar un archivo .key');
                return false;
            }
            
            if (!password || !password.value) {
                e.preventDefault();
                alert('Debes ingresar la contraseña de la llave privada');
                return false;
            }
            
            const btnSubir = document.getElementById('btnSubir');
            if (btnSubir) {
                btnSubir.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Subiendo...';
                btnSubir.disabled = true;
            }
            
            return true;
        });
    }
}

// =============================================
// FUNCIONALIDAD PARA LA PESTAÑA DE PERSONALIZACIÓN
// =============================================

// Sincronizar input color y hex
function setupColorPicker() {
    const colorPicker = document.getElementById('color');
    const colorHex = document.getElementById('color_hex');
    
    if (colorPicker && colorHex) {
        colorPicker.addEventListener('input', function() {
            colorHex.value = this.value;
        });
        
        colorHex.addEventListener('input', function() {
            let value = this.value;
            if (!value.startsWith('#')) {
                value = '#' + value;
            }
            
            const hexRegex = /^#[0-9A-F]{6}$/i;
            if (hexRegex.test(value)) {
                colorPicker.value = value;
                this.value = value;
            }
        });
        
        colorHex.addEventListener('blur', function() {
            let value = this.value;
            if (!value.startsWith('#')) {
                value = '#' + value;
            }
            
            if (value.length === 4) {
                value = value + value.substring(1);
            }
            
            value = value.substring(0, 7);
            this.value = value;
        });
    }
}

// Resetear configuración PDF
function setupPDFReset() {
    const btnResetPDF = document.getElementById('btnResetPDF');
    if (btnResetPDF) {
        btnResetPDF.addEventListener('click', function() {
            const defaultValues = {
                'codes': true,
                'product_key': true,
                'round_unit_price': false,
                'tax_breakdown': true,
                'ieps_breakdown': true,
                'render_carta_porte': false
            };
            
            for (const [key, value] of Object.entries(defaultValues)) {
                const checkbox = document.getElementById(key);
                if (checkbox) {
                    checkbox.checked = value;
                }
            }
            
            alert('Configuración de PDF restaurada a valores por defecto');
        });
    }
}

// Validar formulario de logo
function initLogoFormValidation() {
    const formLogo = document.getElementById('formLogo');
    if (formLogo) {
        formLogo.addEventListener('submit', function(e) {
            const logoFile = document.getElementById('logo_file');
            
            if (!logoFile || !logoFile.files.length) {
                e.preventDefault();
                alert('Debes seleccionar un archivo de logo');
                return false;
            }
            
            const file = logoFile.files[0];
            const maxSize = 2 * 1024 * 1024;
            
            if (file.size > maxSize) {
                e.preventDefault();
                alert('El archivo es demasiado grande. Máximo 2MB');
                return false;
            }
            
            const btnSubirLogo = document.getElementById('btnSubirLogo');
            if (btnSubirLogo) {
                btnSubirLogo.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Subiendo...';
                btnSubirLogo.disabled = true;
            }
            
            return true;
        });
    }
}

// =============================================
// FUNCIONALIDAD GENERAL
// =============================================

// Validar formulario de datos fiscales
function setupFiscalValidation() {
    const formEditarFiscales = document.getElementById('formEditarFiscales');
    if (!formEditarFiscales) return;
    
    const zipInput = document.getElementById('zip');
    if (zipInput) {
        zipInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').substring(0, 5);
        });
    }
    
    const cityInput = document.getElementById('city');
    const municipioInput = document.getElementById('municipality');
    
    if (cityInput && municipioInput) {
        cityInput.addEventListener('change', function() {
            if (!municipioInput.value && this.value) {
                municipioInput.value = this.value;
            }
        });
    }
    
    formEditarFiscales.addEventListener('submit', function(e) {
        if (zipInput && zipInput.value.length !== 5) {
            e.preventDefault();
            alert('El código postal debe tener exactamente 5 dígitos');
            zipInput.focus();
            return false;
        }
        
        const taxSystem = document.getElementById('tax_system');
        if (taxSystem && taxSystem.value.length !== 3) {
            e.preventDefault();
            alert('Selecciona un régimen fiscal válido');
            taxSystem.focus();
            return false;
        }
        
        if (!confirm('¿Estás seguro de que deseas guardar los cambios?')) {
            e.preventDefault();
            return false;
        }
        
        return true;
    });
}

// Configurar sidebar móvil
function initMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    
    function toggleSidebar() {
        if (sidebar.classList.contains('show')) {
            closeSidebarAuto();
        } else {
            openSidebarAuto();
        }
    }
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
    
    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', closeSidebarAuto);
    }
    
    const sidebarLinks = document.querySelectorAll('#sidebar .nav-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 768) {
                closeSidebarAuto();
            }
        });
    });
    
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 768) {
            closeSidebarAuto();
        }
    });
}

// Inicializar tooltips de Bootstrap
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Auto-cerrar alertas después de 5 segundos
function initAutoCloseAlerts() {
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        });
    }, 5000);
}

// =============================================
// FUNCIONES AUXILIARES GLOBALES
// =============================================

function showLoading(buttonId, text = 'Procesando...') {
    const button = document.getElementById(buttonId);
    if (button) {
        button.innerHTML = `<i class="fas fa-spinner fa-spin me-2"></i> ${text}`;
        button.disabled = true;
    }
}

function restoreButton(buttonId, originalHtml) {
    const button = document.getElementById(buttonId);
    if (button) {
        button.innerHTML = originalHtml;
        button.disabled = false;
    }
}

// =============================================
// INICIALIZACIÓN PRINCIPAL
// =============================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM cargado, inicializando...');
    
    // Inicializar componentes
    initSwipeDetection();
    initMobileSidebar();
    initProductTabs();
    initTooltips();
    initAutoCloseAlerts();
    setupFiscalValidation();
    
    // Certificados
    setupFileUpload('cerDropArea', 'cer_file', 'cerFileName');
    setupFileUpload('keyDropArea', 'key_file', 'keyFileName');
    setupPasswordToggle();
    setupCertCleanup();
    initCertFormValidation();
    
    // Personalización
    setupFileUpload('logoDropArea', 'logo_file', 'logoFileName');
    setupColorPicker();
    setupPDFReset();
    initLogoFormValidation();
    
    console.log('Inicialización completa');
});