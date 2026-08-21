/**
 * suscripciones.js - JavaScript para la página de planes
 * Maneja selección de planes, toggle de precios, pagos y domiciliación
 */

// ============================================
// DATOS DE PLANES
// ============================================
const PLANES_DATA = {
    basico: {
        nombre: 'Básico',
        precio_mensual: 299,
        precio_anual: 239,
        usuarios: 1,
        cajas: 1,
        productos: 100
    },
    profesional: {
        nombre: 'Profesional',
        precio_mensual: 599,
        precio_anual: 479,
        usuarios: 4,
        cajas: 2,
        productos: 500
    },
    empresarial: {
        nombre: 'Empresarial',
        precio_mensual: 999,
        precio_anual: 799,
        usuarios: 6,
        cajas: 3,
        productos: 500,
        sucursales: 1
    },
    plus: {
        nombre: 'Empresarial Plus',
        precio_mensual: 1499,
        precio_anual: 1199,
        usuarios: 10,
        cajas: 10,
        productos: 'Ilimitados',
        sucursales: 3,
        timbres: 500
    }
};

// ============================================
// VARIABLES GLOBALES
// ============================================
let planActual = 'empresarial';
let isAnnual = false;
let tieneDomiciliacion = false;
let empresaId = '';
let empresaPlan = '';

// ============================================
// INICIALIZACIÓN
// ============================================
function inicializarSuscripciones(data) {
    if (data) {
        empresaId = data.empresaId || '';
        empresaPlan = data.empresaPlan || '';
        tieneDomiciliacion = data.tieneDomiciliacion || false;
        planActual = data.planSeleccionado || 'empresarial';
    }
    
    // Inicializar tooltips de Bootstrap
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Mostrar sección de domiciliación si la empresa no está en plan prueba
    const domSection = document.getElementById('domiciliacionSection');
    if (domSection && empresaPlan && empresaPlan !== 'prueba') {
        domSection.style.display = 'block';
    }
}

// ============================================
// SELECCIÓN DE PLAN
// ============================================
function selectPlan(planId) {
    planActual = planId;
    const plan = PLANES_DATA[planId];
    if (!plan) return;
    
    // Actualizar UI de planes
    document.querySelectorAll('.plan').forEach(el => {
        el.classList.remove('selected');
    });
    const selectedEl = document.querySelector(`.plan[data-plan="${planId}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
    }
    
    updateSummary(plan);
    document.getElementById('orderSummary').classList.add('visible');
    
    // Scroll en móviles
    if (window.innerWidth < 768) {
        document.getElementById('orderSummary').scrollIntoView({ 
            behavior: 'smooth', 
            block: 'center' 
        });
    }
}

// ============================================
// ACTUALIZAR RESUMEN
// ============================================
function updateSummary(plan) {
    const track = document.getElementById('togTrack');
    const isAnnual = track ? track.classList.contains('annual') : false;
    const precio = isAnnual ? plan.precio_anual * 12 : plan.precio_mensual;
    const periodo = isAnnual ? 'Anual' : 'Mensual';
    
    // Actualizar valores en el resumen
    const summaryPlan = document.getElementById('summaryPlan');
    const summaryUsuarios = document.getElementById('summaryUsuarios');
    const summaryCajas = document.getElementById('summaryCajas');
    const summaryProductos = document.getElementById('summaryProductos');
    const summaryPeriodo = document.getElementById('summaryPeriodo');
    const summaryTotal = document.getElementById('summaryTotal');
    
    if (summaryPlan) summaryPlan.textContent = plan.nombre;
    if (summaryUsuarios) summaryUsuarios.textContent = plan.usuarios;
    if (summaryCajas) summaryCajas.textContent = plan.cajas;
    if (summaryProductos) summaryProductos.textContent = plan.productos;
    if (summaryPeriodo) summaryPeriodo.textContent = periodo;
    if (summaryTotal) summaryTotal.textContent = `$${precio.toLocaleString()} MXN`;
    
    // Mostrar/ocultar fila de ahorro
    const ahorroRow = document.getElementById('summaryAhorroRow');
    if (ahorroRow) {
        if (isAnnual) {
            ahorroRow.style.display = 'flex';
            const ahorro = ((plan.precio_mensual - plan.precio_anual) / plan.precio_mensual * 100).toFixed(0);
            const ahorroMonto = (plan.precio_mensual - plan.precio_anual) * 12;
            const summaryAhorro = document.getElementById('summaryAhorro');
            if (summaryAhorro) {
                summaryAhorro.textContent = `-${ahorro}% ($${ahorroMonto.toLocaleString()} MXN/año)`;
            }
        } else {
            ahorroRow.style.display = 'none';
        }
    }
    
    // Mostrar/ocultar sucursales
    const sucursalesRow = document.getElementById('summarySucursales')?.parentElement;
    if (sucursalesRow) {
        if (plan.sucursales) {
            sucursalesRow.style.display = 'flex';
            const summarySucursales = document.getElementById('summarySucursales');
            if (summarySucursales) summarySucursales.textContent = plan.sucursales;
        } else {
            sucursalesRow.style.display = 'none';
        }
    }
    
    // Mostrar/ocultar timbres
    const timbresRow = document.getElementById('summaryTimbres')?.parentElement;
    if (timbresRow) {
        if (plan.timbres) {
            timbresRow.style.display = 'flex';
            const summaryTimbres = document.getElementById('summaryTimbres');
            if (summaryTimbres) summaryTimbres.textContent = plan.timbres;
        } else {
            timbresRow.style.display = 'none';
        }
    }
}

// ============================================
// TOGGLE DE PRECIOS (Mensual / Anual)
// ============================================
function togglePricing() {
    const track = document.getElementById('togTrack');
    if (!track) return;
    
    track.classList.toggle('annual');
    isAnnual = track.classList.contains('annual');
    
    // Actualizar precios en las tarjetas
    document.querySelectorAll('.plan').forEach(el => {
        const planId = el.dataset.plan;
        const plan = PLANES_DATA[planId];
        if (plan) {
            const priceEl = el.querySelector('.pv');
            if (priceEl) {
                const precio = isAnnual ? plan.precio_anual : plan.precio_mensual;
                priceEl.textContent = precio.toLocaleString();
            }
        }
    });
    
    // Actualizar resumen
    if (planActual && PLANES_DATA[planActual]) {
        updateSummary(PLANES_DATA[planActual]);
    }
}

// ============================================
// CANCELAR SELECCIÓN
// ============================================
function cancelarSeleccion() {
    document.querySelectorAll('.plan').forEach(el => {
        el.classList.remove('selected');
    });
    const orderSummary = document.getElementById('orderSummary');
    if (orderSummary) {
        orderSummary.classList.remove('visible');
    }
}

// ============================================
// GENERAR PAGO CON TARJETA
// ============================================
async function generarPago() {
    try {
        const overlay = document.getElementById('loadingOverlay');
        const loadingTitle = document.getElementById('loadingTitle');
        const loadingMessage = document.getElementById('loadingMessage');
        
        if (overlay) overlay.classList.add('active');
        if (loadingTitle) loadingTitle.textContent = 'Generando link de pago';
        if (loadingMessage) loadingMessage.textContent = 'Por favor espera un momento...';
        
        // Obtener monto del resumen
        const totalTexto = document.getElementById('summaryTotal')?.textContent || '';
        const totalMatch = totalTexto.match(/\$([\d,]+\.?\d*)/);
        let monto = totalMatch ? parseFloat(totalMatch[1].replace(/,/g, '')) : 0;
        
        // Obtener nombre del plan
        const planSeleccionado = document.querySelector('.plan.selected');
        const nombrePlan = planSeleccionado ? 
            planSeleccionado.querySelector('.plan-name')?.textContent || 'Plan Empresarial' : 
            'Plan Empresarial';
        
        const esAnual = document.getElementById('togTrack')?.classList.contains('annual') || false;
        const descripcion = `Suscripción ${nombrePlan} - ${esAnual ? 'Anual' : 'Mensual'} - Empresa: ${empresaId}`;
        
        // Deshabilitar botones
        document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
            btn.disabled = true;
        });
        
        const response = await fetch('Service/GenerarLigaPagoSuscripcion.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                monto: monto,
                descripcion: descripcion
            })
        });
        
        const data = await response.json();
        
        if (overlay) overlay.classList.remove('active');
        
        document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
            btn.disabled = false;
        });
        
        if (data.success && data.url) {
            window.location.href = data.url;
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error al generar el pago',
                text: data.error || 'Error desconocido, por favor intenta de nuevo.',
                confirmButtonColor: '#27ae60'
            });
            console.error('Error:', data);
        }
        
    } catch (error) {
        document.getElementById('loadingOverlay')?.classList.remove('active');
        console.error('Error en generarPago:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor de pagos. Por favor, intenta de nuevo.',
            confirmButtonColor: '#27ae60'
        });
        document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
            btn.disabled = false;
        });
    }
}

// ============================================
// PAGAR CON DOMICILIACIÓN
// ============================================
async function pagarConDomiciliacion() {
    if (!tieneDomiciliacion) {
        Swal.fire({
            icon: 'warning',
            title: 'Sin tarjeta domiciliada',
            text: 'Primero debes domiciliar una tarjeta para usar esta opción.',
            confirmButtonColor: '#27ae60'
        });
        return;
    }

    try {
        const btn = document.getElementById('btnPagoDomiciliado');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Procesando...';
        }

        const overlay = document.getElementById('loadingOverlay');
        const loadingTitle = document.getElementById('loadingTitle');
        const loadingMessage = document.getElementById('loadingMessage');
        
        if (overlay) overlay.classList.add('active');
        if (loadingTitle) loadingTitle.textContent = 'Procesando pago domiciliado';
        if (loadingMessage) loadingMessage.textContent = 'Validando tarjeta y procesando el cargo...';

        const totalTexto = document.getElementById('summaryTotal')?.textContent || '';
        const totalMatch = totalTexto.match(/\$([\d,]+\.?\d*)/);
        let monto = totalMatch ? parseFloat(totalMatch[1].replace(/,/g, '')) : 0;

        const planSeleccionado = document.querySelector('.plan.selected');
        const nombrePlan = planSeleccionado ? 
            planSeleccionado.querySelector('.plan-name')?.textContent || 'Plan Empresarial' : 
            'Plan Empresarial';
        const esAnual = document.getElementById('togTrack')?.classList.contains('annual') || false;

        const formData = new FormData();
        formData.append('empresa_id', empresaId);
        formData.append('monto', monto);
        formData.append('plan', planActual);
        formData.append('periodo', esAnual ? 'anual' : 'mensual');
        formData.append('nombre_plan', nombrePlan);

        const response = await fetch('Service/pagar_domiciliacion.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (overlay) overlay.classList.remove('active');
        
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i> Pagar con domiciliación <span class="badge bg-light text-dark ms-1" style="font-size: 9px; padding: 2px 8px;"><i class="fas fa-check-circle text-success"></i> Activado</span>';
        }

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Pago exitoso!',
                html: `
                    <p>El pago de <strong>$${monto.toFixed(2)} MXN</strong> se ha procesado correctamente.</p>
                    <p class="text-muted" style="font-size: 13px;">
                        <i class="fas fa-check-circle text-success me-1"></i>
                        Transacción: ${data.auth || 'N/A'}
                        <br>
                        <i class="fas fa-hashtag me-1"></i>
                        Referencia: ${data.reference || 'N/A'}
                    </p>
                `,
                confirmButtonColor: '#27ae60',
                timer: 5000,
                timerProgressBar: true
            }).then(() => {
                location.reload();
            });
        } else {
            let mensajeError = data.message || 'El pago no pudo ser procesado.';
            
            // Mensajes amigables según código de error
            const errorMessages = {
                '01': 'La tarjeta fue rechazada. Por favor, verifica que tenga fondos suficientes.',
                '02': 'La tarjeta ha expirado. Por favor, actualiza tus datos de pago.',
                '03': 'Hubo un problema con el banco emisor. Intenta nuevamente en unos minutos.',
                '04': 'El monto excede el límite de tu tarjeta.'
            };
            
            if (data.code && errorMessages[data.code]) {
                mensajeError = errorMessages[data.code];
            }

            Swal.fire({
                icon: 'error',
                title: 'Error en el pago',
                html: `
                    <p>${mensajeError}</p>
                    ${data.code ? `<p class="text-muted" style="font-size: 12px;">Código: ${data.code}</p>` : ''}
                `,
                confirmButtonColor: '#27ae60'
            });
        }

    } catch (error) {
        document.getElementById('loadingOverlay')?.classList.remove('active');
        console.error('Error en pagarConDomiciliacion:', error);
        
        const btn = document.getElementById('btnPagoDomiciliado');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt"></i> Pagar con domiciliación <span class="badge bg-light text-dark ms-1" style="font-size: 9px; padding: 2px 8px;"><i class="fas fa-check-circle text-success"></i> Activado</span>';
        }

        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor de pagos. Por favor, intenta de nuevo más tarde.',
            confirmButtonColor: '#27ae60'
        });
    }
}

// ============================================
// DOMICILIACIÓN DE TARJETA
// ============================================

function formatearNumeroTarjeta(input) {
    let value = input.value.replace(/\D/g, '');
    value = value.substring(0, 16);
    let formatted = value.replace(/(.{4})/g, '$1 ');
    input.value = formatted.trim();
    detectarTipoTarjeta(value);
}

function detectarTipoTarjeta(numero) {
    const iconos = document.querySelectorAll('.payment-icons i');
    iconos.forEach(icon => icon.classList.remove('active'));
    
    if (numero.startsWith('4')) {
        document.querySelector('.fa-cc-visa')?.classList.add('active');
    } else if (numero.startsWith('5')) {
        document.querySelector('.fa-cc-mastercard')?.classList.add('active');
    } else if (numero.startsWith('3')) {
        document.querySelector('.fa-cc-amex')?.classList.add('active');
    }
}

function validarTarjeta(numero) {
    const digits = numero.replace(/\D/g, '');
    if (digits.length < 13 || digits.length > 19) return false;
    
    let sum = 0;
    let isEven = false;
    
    for (let i = digits.length - 1; i >= 0; i--) {
        let digit = parseInt(digits[i]);
        if (isEven) {
            digit *= 2;
            if (digit > 9) digit -= 9;
        }
        sum += digit;
        isEven = !isEven;
    }
    
    return sum % 10 === 0;
}

async function procesarDomiciliacion(event) {
    event.preventDefault();
    
    const btn = document.getElementById('btnDomiciliar');
    const originalText = btn ? btn.innerHTML : '';
    
    const cardNumber = document.getElementById('cardNumber')?.value.replace(/\s/g, '') || '';
    const expMonth = document.getElementById('expMonth')?.value || '';
    const expYear = document.getElementById('expYear')?.value || '';
    const cvv = document.getElementById('cvv')?.value || '';
    const acepto = document.getElementById('aceptoTerminos')?.checked || false;
    
    // Validaciones
    if (!cardNumber || cardNumber.length < 13) {
        Swal.fire({
            icon: 'warning',
            title: 'Número inválido',
            text: 'Por favor, ingresa un número de tarjeta válido (13-19 dígitos).',
            confirmButtonColor: '#27ae60'
        });
        return false;
    }
    
    if (!validarTarjeta(cardNumber)) {
        Swal.fire({
            icon: 'error',
            title: 'Tarjeta inválida',
            text: 'El número de tarjeta no es válido. Por favor, verifica los datos.',
            confirmButtonColor: '#27ae60'
        });
        return false;
    }
    
    if (!expMonth || !expYear) {
        Swal.fire({
            icon: 'warning',
            title: 'Fecha de expiración',
            text: 'Por favor, selecciona la fecha de expiración de tu tarjeta.',
            confirmButtonColor: '#27ae60'
        });
        return false;
    }
    
    if (!cvv || cvv.length < 3) {
        Swal.fire({
            icon: 'warning',
            title: 'CVV inválido',
            text: 'Por favor, ingresa el código de seguridad de 3 dígitos.',
            confirmButtonColor: '#27ae60'
        });
        return false;
    }
    
    if (!acepto) {
        Swal.fire({
            icon: 'info',
            title: 'Acepta los términos',
            text: 'Debes aceptar los términos y condiciones para continuar.',
            confirmButtonColor: '#27ae60'
        });
        return false;
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Procesando...';
    }
    
    try {
        const formData = new FormData();
        formData.append('card_number', cardNumber);
        formData.append('exp_month', expMonth);
        formData.append('exp_year', expYear);
        formData.append('cvv', cvv);
        formData.append('empresa_id', empresaId);
        
        const response = await fetch('Service/domiciliar_tarjeta.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Tarjeta domiciliada!',
                text: 'Tu tarjeta ha sido guardada correctamente. Los pagos se realizarán automáticamente.',
                confirmButtonColor: '#27ae60',
                timer: 3000,
                timerProgressBar: true
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error al domiciliar',
                text: data.message || 'Ocurrió un error. Por favor, intenta de nuevo.',
                confirmButtonColor: '#27ae60'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor. Por favor, intenta de nuevo.',
            confirmButtonColor: '#27ae60'
        });
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

// ============================================
// CANCELAR DOMICILIACIÓN
// ============================================
async function cancelarDomiciliacion() {
    const result = await Swal.fire({
        icon: 'question',
        title: '¿Cancelar domiciliación?',
        text: 'Si cancelas, tus pagos ya no se realizarán automáticamente. ¿Estás seguro?',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt me-1"></i> Sí, cancelar',
        cancelButtonText: 'Cancelar'
    });
    
    if (result.isConfirmed) {
        try {
            const formData = new FormData();
            formData.append('empresa_id', empresaId);
            
            const response = await fetch('Service/cancelar_domiciliacion.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Cancelada',
                    text: 'La domiciliación ha sido cancelada correctamente.',
                    confirmButtonColor: '#27ae60',
                    timer: 2000,
                    timerProgressBar: true
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message || 'No se pudo cancelar la domiciliación.',
                    confirmButtonColor: '#27ae60'
                });
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error de conexión',
                text: 'No se pudo conectar con el servidor.',
                confirmButtonColor: '#27ae60'
            });
        }
    }
}

// ============================================
// ACTUALIZAR TARJETA (placeholder)
// ============================================
function cargarActualizarTarjeta() {
    Swal.fire({
        icon: 'info',
        title: 'Actualizar tarjeta',
        text: 'Próximamente podrás actualizar los datos de tu tarjeta. Mientras tanto, cancela la actual y vuelve a domiciliar una nueva.',
        confirmButtonColor: '#27ae60'
    });
}

// ============================================
// COPIAR CLABE
// ============================================
function copiarCLABE(clabe) {
    navigator.clipboard.writeText(clabe).then(function() {
        const btn = document.querySelector('.btn-outline-primary');
        if (btn) {
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-success');
            setTimeout(function() {
                btn.innerHTML = originalText;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-primary');
            }, 2000);
        }
    }).catch(function(err) {
        // Fallback para navegadores sin Clipboard API
        const textArea = document.createElement('textarea');
        textArea.value = clabe;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('CLABE copiada al portapapeles');
    });
}