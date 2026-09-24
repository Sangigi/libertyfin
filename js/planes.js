/**
 * suscripciones.js - JavaScript para la página de planes y checkout
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
    starter: {
        nombre: 'Profesional',
        precio_mensual: 599,
        precio_anual: 479,
        usuarios: 4,
        cajas: 2,
        productos: 500
    },
    emprendedor: {
        nombre: 'Empresarial',
        precio_mensual: 999,
        precio_anual: 799,
        usuarios: 6,
        cajas: 3,
        productos: 500,
        sucursales: 1
    },
    premium: {
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
        window._clienteEmail = data.clienteEmail || 'cliente@libertyfin.com.mx';
        window._clienteNombre = data.clienteNombre || 'Cliente Libertyfin';
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    const domSection = document.getElementById('domiciliacionSection');
    if (domSection && empresaPlan && empresaPlan !== 'prueba') {
        domSection.style.display = 'block';
    }
}

function inicializarCheckout(data) {
    inicializarSuscripciones(data);
}

// ============================================
// SELECCIÓN DE PLAN
// ============================================
function selectPlan(planId) {
    planActual = planId;
    const plan = PLANES_DATA[planId];
    if (!plan) return;

    document.querySelectorAll('.plan').forEach(el => {
        el.classList.remove('selected');
    });
    const selectedEl = document.querySelector(`.plan[data-plan="${planId}"]`);
    if (selectedEl) {
        selectedEl.classList.add('selected');
    }

    updateSummary(plan);
    document.getElementById('orderSummary').classList.add('visible');

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
    const isAnnualLocal = track ? track.classList.contains('annual') : false;
    const precio = isAnnualLocal ? plan.precio_anual * 12 : plan.precio_mensual;
    const periodo = isAnnualLocal ? 'Anual' : 'Mensual';

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

    const ahorroRow = document.getElementById('summaryAhorroRow');
    if (ahorroRow) {
        if (isAnnualLocal) {
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
// HELPERS
// ============================================
function obtenerMontoActual() {
    const totalTexto = document.getElementById('summaryTotal')?.textContent || '';
    const totalMatch = totalTexto.match(/\$([\d,]+\.?\d*)/);
    return totalMatch ? parseFloat(totalMatch[1].replace(/,/g, '')) : 0;
}

function obtenerNombrePlanActual() {
    const summaryPlan = document.getElementById('summaryPlan');
    if (summaryPlan && summaryPlan.textContent) return summaryPlan.textContent.trim();
    const planSeleccionado = document.querySelector('.plan.selected');
    return planSeleccionado ?
        planSeleccionado.querySelector('.plan-name')?.textContent || 'Plan Empresarial' :
        'Plan Empresarial';
}

function esPeriodoAnual() {
    return document.getElementById('togTrack')?.classList.contains('annual') || false;
}

/** Lee los datos de facturación del formulario, si el usuario los pidió */
function obtenerDatosFacturacion() {
    const facturarSi = document.getElementById('facturar_si');
    const requiere = facturarSi ? facturarSi.checked : false;
    if (!requiere) return { requiere_factura: false };
    return {
        requiere_factura: true,
        razon_social: document.getElementById('razon_social')?.value || '',
        rfc: document.getElementById('rfc')?.value || '',
        email_factura: document.getElementById('email_factura')?.value || '',
        regimen_fiscal: document.getElementById('regimen_fiscal')?.value || '',
        cp: document.getElementById('cp')?.value || '',
        metodo_pago_sat: document.getElementById('metodo_pago')?.value || '',
        uso_cfdi: document.getElementById('uso_cfdi')?.value || ''
    };
}

// ============================================
// POLLING DE ESTADO DE PAGO (tarjeta y SPEI)
// ============================================
let _pollingIntervalId = null;

function detenerPollingPago() {
    if (_pollingIntervalId) {
        clearInterval(_pollingIntervalId);
        _pollingIntervalId = null;
    }
}

/**
 * Consulta Service/verificar_estado_pago.php cada `intervaloMs` hasta que
 * el pago quede aprobado/rechazado o se agoten los intentos.
 */
function iniciarPollingPago({ tipo, identificador, intervaloMs = 6000, maxIntentos = 100 }) {
    detenerPollingPago();
    let intentos = 0;

    _pollingIntervalId = setInterval(async () => {
        intentos++;
        if (intentos > maxIntentos) {
            detenerPollingPago();
            return;
        }

        try {
            const body = tipo === 'spei'
                ? { tipo: 'spei', clabe: identificador }
                : { tipo: 'tarjeta', reference: identificador };

            const resp = await fetch('Service/verificar_estado_pago.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const data = await resp.json();

            if (!data.success) return;

            if (data.status === 'aprobado') {
                detenerPollingPago();
                Swal.fire({
                    icon: 'success',
                    title: '¡Pago confirmado!',
                    text: 'Tu suscripción ya quedó activa. Te enviamos un correo de confirmación.',
                    confirmButtonColor: '#27ae60'
                }).then(() => {
                    window.location.href = 'Inicio';
                });
            } else if (data.status === 'rechazado') {
                detenerPollingPago();
                Swal.fire({
                    icon: 'error',
                    title: 'Pago no completado',
                    text: 'El pago fue rechazado o la referencia venció. Intenta de nuevo.',
                    confirmButtonColor: '#27ae60'
                });
            }
            // 'pendiente' -> seguimos esperando, no hacemos nada
        } catch (e) {
            console.error('Error verificando estado de pago:', e);
        }
    }, intervaloMs);
}

// ============================================
// TOGGLE FACTURACIÓN
// ============================================
function toggleFacturacion() {
    const facturarSi = document.getElementById('facturar_si');
    const camposFacturacion = document.getElementById('camposFacturacion');
    
    if (!facturarSi || !camposFacturacion) return;

    if (facturarSi.checked) {
        camposFacturacion.style.display = 'block';
        // Hacer required los campos al mostrarse
        document.getElementById('razon_social').setAttribute('required', 'required');
        document.getElementById('rfc').setAttribute('required', 'required');
        document.getElementById('email_factura').setAttribute('required', 'required');
        document.getElementById('regimen_fiscal').setAttribute('required', 'required');
        document.getElementById('cp').setAttribute('required', 'required');
        document.getElementById('metodo_pago').setAttribute('required', 'required');
        document.getElementById('uso_cfdi').setAttribute('required', 'required');
    } else {
        camposFacturacion.style.display = 'none';
        // Quitar required al ocultarse
        document.getElementById('razon_social').removeAttribute('required');
        document.getElementById('rfc').removeAttribute('required');
        document.getElementById('email_factura').removeAttribute('required');
        document.getElementById('regimen_fiscal').removeAttribute('required');
        document.getElementById('cp').removeAttribute('required');
        document.getElementById('metodo_pago').removeAttribute('required');
        document.getElementById('uso_cfdi').removeAttribute('required');
    }
}

// ============================================
// GENERAR PAGO CON TARJETA (IFRAME)
// ============================================
async function generarPago() {
    try {
        const overlay = document.getElementById('loadingOverlay');
        const loadingTitle = document.getElementById('loadingTitle');
        const loadingMessage = document.getElementById('loadingMessage');

        if (overlay) overlay.classList.add('active');
        if (loadingTitle) loadingTitle.textContent = 'Generando link de pago';
        if (loadingMessage) loadingMessage.textContent = 'Por favor espera un momento...';

        const monto = obtenerMontoActual();
        const nombrePlan = obtenerNombrePlanActual();
        const esAnual = esPeriodoAnual();
        const descripcion = `Suscripcion ${nombrePlan} - ${esAnual ? 'Anual' : 'Mensual'}`;

        // 👇 Variables que faltaban
        const planNombreInterno = planActual;                 // 'basico' | 'starter' | 'emprendedor' | 'premium'
        const plazo = esAnual ? 'anual' : 'mensual';

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
                descripcion: descripcion,
                empresa_id: empresaId,
                plan: planNombreInterno,
                plazo: plazo,
                tipo_servicio: 'Suscripcion',
                ...obtenerDatosFacturacion()
            })
        });

        // ✅ Parseo blindado (por si PHP devuelve HTML en vez de JSON)
        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            console.error('[generarPago] Respuesta no-JSON del servidor:', rawText);
            if (overlay) overlay.classList.remove('active');
            document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => btn.disabled = false);
            Swal.fire({
                icon: 'error',
                title: 'Error del servidor',
                html: `<p>El servidor devolvió una respuesta inválida.</p>
                       <pre style="text-align:left;font-size:11px;max-height:220px;overflow:auto;background:#f5f5f5;padding:8px;border-radius:4px;">${rawText.slice(0, 800).replace(/</g, '&lt;')}</pre>`,
                confirmButtonColor: '#27ae60'
            });
            return;
        }

        if (overlay) overlay.classList.remove('active');

        document.querySelectorAll('.btn-pay, .btn-primary.btn-sm').forEach(btn => {
            btn.disabled = false;
        });

        if (data.success && data.url) {
            const cardInfoView = document.getElementById('cardInfoView');
            const cardResultView = document.getElementById('cardResultView');
            const paymentIframe = document.getElementById('paymentIframe');

            if (cardInfoView) cardInfoView.style.display = 'none';
            if (cardResultView) cardResultView.style.display = 'block';
            if (paymentIframe) {
                paymentIframe.src = data.url;
                window._paymentUrl = data.url;
            }

            const tabCard = document.getElementById('tab-card');
            if (tabCard) {
                const tab = new bootstrap.Tab(tabCard);
                tab.show();
            }

            if (data.reference) {
                iniciarPollingPago({ tipo: 'tarjeta', identificador: data.reference });
            }

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
// FUNCIONES AUXILIARES PARA EL IFRAME DE TARJETA
// ============================================
function abrirLinkPago() {
    if (window._paymentUrl) {
        window.open(window._paymentUrl, '_blank');
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Enlace no disponible',
            text: 'Primero genera un enlace de pago.',
            confirmButtonColor: '#27ae60'
        });
    }
}

function copiarLinkPago() {
    if (window._paymentUrl) {
        navigator.clipboard.writeText(window._paymentUrl).then(() => {
            Swal.fire({
                icon: 'success',
                title: 'Enlace copiado',
                text: 'El enlace de pago ha sido copiado al portapapeles.',
                timer: 1500,
                showConfirmButton: false,
                timerProgressBar: true
            });
        }).catch(() => {
            const textArea = document.createElement('textarea');
            textArea.value = window._paymentUrl;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            Swal.fire({
                icon: 'success',
                title: 'Enlace copiado',
                timer: 1500,
                showConfirmButton: false,
                timerProgressBar: true
            });
        });
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Enlace no disponible',
            text: 'Primero genera un enlace de pago.',
            confirmButtonColor: '#27ae60'
        });
    }
}

function volverDeResultado() {
    detenerPollingPago();
    const cardInfoView = document.getElementById('cardInfoView');
    const cardResultView = document.getElementById('cardResultView');
    const paymentIframe = document.getElementById('paymentIframe');

    if (cardInfoView) cardInfoView.style.display = 'block';
    if (cardResultView) cardResultView.style.display = 'none';
    if (paymentIframe) {
        paymentIframe.src = '';
    }
    window._paymentUrl = null;
}

// ============================================
// GENERAR CLABE SPEI (usando generar_clabe.php)
// ============================================
async function generarCLABE() {

    const overlay = document.getElementById('loadingOverlay');
    const loadingTitle = document.getElementById('loadingTitle');
    const loadingMessage = document.getElementById('loadingMessage');

    if (overlay) overlay.classList.add('active');
    if (loadingTitle) loadingTitle.textContent = 'Generando CLABE';
    if (loadingMessage) loadingMessage.textContent = 'Por favor espera un momento...';

    try {
        const monto = obtenerMontoActual();

        if (monto <= 0) {
            if (overlay) overlay.classList.remove('active');
            Swal.fire({
                icon: 'warning',
                title: 'Monto inválido',
                text: 'No se pudo determinar el monto a pagar.',
                confirmButtonColor: '#27ae60'
            });
            return;
        }

        const nombrePlan = obtenerNombrePlanActual();
        const esAnual = esPeriodoAnual();
        const periodo = esAnual ? 'Anual' : 'Mensual';
        const descripcion = `Suscripcion ${nombrePlan} - ${periodo}`;

        const clienteNombre = window._clienteNombre || 'Cliente Libertyfin';
        const clienteEmail = window._clienteEmail || 'cliente@libertyfin.com.mx';

        // ✅ NUEVOS CAMPOS: plan, plazo, tipo_servicio
        const payload = {
            MontoTotal: monto,
            monto: monto,
            Description: descripcion,
            CustomerEmail: clienteEmail,
            CustomerName: clienteNombre,
            empresa_id: empresaId,
            plan: planActual,                     // 'basico', 'profesional', 'empresarial', 'plus'
            plazo: esAnual ? 'anual' : 'mensual', // 'anual' o 'mensual'
            tipo_servicio: 'Suscripcion',          // Siempre 'Suscripcion'
            ...obtenerDatosFacturacion()
        };

        const response = await fetch('Service/generar_clabe.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        if (overlay) overlay.classList.remove('active');

        if (data.success && data.clabe) {
            document.getElementById('speiInfoView').style.display = 'none';
            document.getElementById('speiResultView').style.display = 'block';

            const clabeValue = document.getElementById('speiClabeValue');
            if (clabeValue) clabeValue.textContent = data.clabe;

            const speiBanco = document.getElementById('speiBanco');
            if (speiBanco) speiBanco.textContent = data.banco || 'STP';

            const speiBeneficiario = document.getElementById('speiBeneficiario');
            if (speiBeneficiario) speiBeneficiario.textContent = data.beneficiario || 'OPERACIONES Y MULTISERVICIOS IDEAS SA DE CV';

            const speiMonto = document.getElementById('speiMonto');
            if (speiMonto) speiMonto.textContent = `$${monto.toLocaleString('es-MX', { minimumFractionDigits: 2 })} MXN`;

            const speiFolio = document.getElementById('speiFolio');
            if (speiFolio) speiFolio.textContent = data.folio || '—';

            const speiExpiracion = document.getElementById('speiExpiracion');
            if (speiExpiracion && data.fecha_expiracion) {
                try {
                    const fecha = new Date(data.fecha_expiracion.replace(' ', 'T'));
                    speiExpiracion.textContent = fecha.toLocaleString('es-MX', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                } catch (e) {
                    speiExpiracion.textContent = data.fecha_expiracion;
                }
            } else if (speiExpiracion) {
                speiExpiracion.textContent = '24 horas';
            }

            window._speiClabe = data.clabe;
            window._speiFolio = data.folio;
            window._speiAccount = data.account;
            window._speiId = data.id;

            // Empezamos a preguntar si ya llegó la transferencia SPEI
            iniciarPollingPago({ tipo: 'spei', identificador: data.clabe });

        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error al generar CLABE',
                text: data.error || 'Error desconocido, por favor intenta de nuevo.',
                confirmButtonColor: '#27ae60'
            });
            console.error('Error:', data);
        }

    } catch (error) {
        if (overlay) overlay.classList.remove('active');
        console.error('Error en generarCLABE:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor de pagos. Por favor, intenta de nuevo.',
            confirmButtonColor: '#27ae60'
        });
    }
}

/** Volver a la vista inicial de SPEI */
function volverDeSPEI() {
    detenerPollingPago();
    document.getElementById('speiInfoView').style.display = 'block';
    document.getElementById('speiResultView').style.display = 'none';
    window._speiClabe = null;
    window._speiFolio = null;
    window._speiAccount = null;
    window._speiId = null;
}

/** Copiar CLABE desde un elemento por ID */
function copiarCLABE(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const texto = el.textContent.trim();
    if (!texto || texto === '—') return;

    navigator.clipboard.writeText(texto).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'CLABE copiada',
            text: 'La CLABE ha sido copiada al portapapeles.',
            timer: 1500,
            showConfirmButton: false,
            timerProgressBar: true
        });
    }).catch(() => {
        const textArea = document.createElement('textarea');
        textArea.value = texto;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        Swal.fire({
            icon: 'success',
            title: 'CLABE copiada',
            timer: 1500,
            showConfirmButton: false,
            timerProgressBar: true
        });
    });
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

        const monto = obtenerMontoActual();
        const nombrePlan = obtenerNombrePlanActual();
        const esAnual = esPeriodoAnual();

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

    if (!cardNumber || cardNumber.length < 13) {
        Swal.fire({ icon: 'warning', title: 'Número inválido', text: 'Por favor, ingresa un número de tarjeta válido (13-19 dígitos).', confirmButtonColor: '#27ae60' });
        return false;
    }

    if (!validarTarjeta(cardNumber)) {
        Swal.fire({ icon: 'error', title: 'Tarjeta inválida', text: 'El número de tarjeta no es válido. Por favor, verifica los datos.', confirmButtonColor: '#27ae60' });
        return false;
    }

    if (!expMonth || !expYear) {
        Swal.fire({ icon: 'warning', title: 'Fecha de expiración', text: 'Por favor, selecciona la fecha de expiración de tu tarjeta.', confirmButtonColor: '#27ae60' });
        return false;
    }

    if (!cvv || cvv.length < 3) {
        Swal.fire({ icon: 'warning', title: 'CVV inválido', text: 'Por favor, ingresa el código de seguridad de 3 dígitos.', confirmButtonColor: '#27ae60' });
        return false;
    }

    if (!acepto) {
        Swal.fire({ icon: 'info', title: 'Acepta los términos', text: 'Debes aceptar los términos y condiciones para continuar.', confirmButtonColor: '#27ae60' });
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
// TOGGLE CARGO AUTOMÁTICO
// ============================================
async function toggleCargoAutomatico() {
    const checkbox = document.getElementById('cargoAutomaticoSwitch');
    if (!checkbox) return;

    const nuevoEstado = checkbox.checked ? 1 : 0;
    const formData = new FormData();
    formData.append('empresa_id', empresaId);
    formData.append('cargo_automatico', nuevoEstado);

    try {
        const response = await fetch('Service/actualizar_cargo_automatico.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            const label = document.getElementById('cargoAutomaticoLabel');
            const desc = document.querySelector('.mt-3 .text-muted');
            if (label) {
                label.textContent = nuevoEstado ? 'Activado' : 'Desactivado';
            }
            if (desc) {
                desc.textContent = nuevoEstado
                    ? 'Los pagos se realizarán automáticamente cada periodo.'
                    : 'Los pagos deberán realizarse manualmente.';
            }
            Swal.fire({
                icon: 'success',
                title: 'Actualizado',
                text: `Cargo automático ${nuevoEstado ? 'activado' : 'desactivado'} correctamente.`,
                timer: 2000,
                timerProgressBar: true,
                showConfirmButton: false
            });
        } else {
            checkbox.checked = !checkbox.checked;
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'No se pudo actualizar el cargo automático.'
            });
        }
    } catch (error) {
        console.error('Error:', error);
        checkbox.checked = !checkbox.checked;
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor.'
        });
    }
}

// ============================================
// REFERENCIA EN EFECTIVO (OXXO / Paga de Todo)
// ============================================

/**
 * Genera una referencia de pago en efectivo vía Service/generar_referencia.php
 * y muestra el resultado en la vista #refResultView.
 *
 * IMPORTANTE: NO valida PLANES_DATA[planActual] (a diferencia de la versión
 * anterior) porque CCT no valida el nombre interno del plan y el nombre real
 * se obtiene del DOM con obtenerNombrePlanActual(). Funciona igual que
 * generarPago() y generarCLABE(), que sí funcionan con todos los planes.
 */
// ============================================
// REFERENCIA EN EFECTIVO (OXXO / Paga de Todo)
// ============================================
async function generarReferenciaEfectivo() {
    const btn = document.getElementById('btnGenerarReferencia');
    const overlay = document.getElementById('loadingOverlay');
    const loadingTitle = document.getElementById('loadingTitle');
    const loadingMessage = document.getElementById('loadingMessage');

    const monto = obtenerMontoActual();
    if (monto <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Monto inválido',
            text: 'No se pudo determinar el monto a pagar.',
            confirmButtonColor: '#27ae60'
        });
        return;
    }

    const nombrePlan = obtenerNombrePlanActual();
    if (!nombrePlan) {
        Swal.fire({
            icon: 'warning',
            title: 'Selecciona un plan',
            text: 'Primero elige el plan que deseas contratar.',
            confirmButtonColor: '#27ae60'
        });
        return;
    }

    const esAnual = esPeriodoAnual();
    const plazo = esAnual ? 'anual' : 'mensual';
    const descripcion = `Suscripcion ${nombrePlan} - ${esAnual ? 'Anual' : 'Mensual'}`.slice(0, 50);

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Generando referencia...';
    }
    if (overlay) overlay.classList.add('active');
    if (loadingTitle) loadingTitle.textContent = 'Generando referencia';
    if (loadingMessage) loadingMessage.textContent = 'Creando tu ficha de pago en efectivo...';

    try {
        const payload = {
            monto: monto,
            descripcion: descripcion,
            empresa_id: empresaId,
            plan: planActual,
            plazo: plazo,
            tipo_servicio: 'Suscripcion',
            CustomerEmail: document.getElementById('refCustomerEmail')?.value?.trim()
                          || window._clienteEmail || '',
            CustomerName:  document.getElementById('refCustomerName')?.value?.trim()
                          || window._clienteNombre || '',
            ...obtenerDatosFacturacion()
        };

        const resp = await fetch('Service/generar_referencia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const rawText = await resp.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            console.error('[generarReferenciaEfectivo] Respuesta no-JSON:', rawText);
            if (overlay) overlay.classList.remove('active');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-barcode me-2"></i> Generar referencia de pago';
            }
            Swal.fire({
                icon: 'error',
                title: 'Error del servidor',
                html: `<pre style="text-align:left;font-size:11px;max-height:220px;overflow:auto;background:#f5f5f5;padding:8px;border-radius:4px;">${rawText.slice(0, 800).replace(/</g, '&lt;')}</pre>`,
                confirmButtonColor: '#27ae60'
            });
            return;
        }

        if (overlay) overlay.classList.remove('active');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-barcode me-2"></i> Generar referencia de pago';
        }

        if (!data.success) {
            Swal.fire({
                icon: 'error',
                title: 'Error al generar la referencia',
                html: `<p>${data.error || 'Intenta de nuevo.'}</p>`,
                confirmButtonColor: '#27ae60'
            });
            return;
        }

        // --- Pintar resultado ---
        document.getElementById('refReferenceValue').textContent = data.reference || '—';
        document.getElementById('refFolio').textContent = data.folio || '—';
        document.getElementById('refMonto').textContent =
            `$${Number(data.monto).toLocaleString('es-MX', { minimumFractionDigits: 2 })} MXN`;
        document.getElementById('refFechaExpiracion').textContent = data.fecha_expiracion || '—';

        // ✅ BOTÓN QUE DESCARGA EL PDF
        const payformatLink = document.getElementById('refPayformatLink');
        if (payformatLink && (data.folio || data.reference)) {
            payformatLink.href = `generar_pdf_referencia.php?folio=${encodeURIComponent(data.folio || '')}&referencia=${encodeURIComponent(data.reference || '')}`;
            payformatLink.target = '_blank';
            payformatLink.style.display = 'inline-flex';
        } else if (payformatLink) {
            payformatLink.style.display = 'none';
        }

        // --- Código de barras (si la API lo devolvió) ---
        const barcodeContainer = document.getElementById('refBarcodeContainer');
        const barcodeImg = document.getElementById('refBarcodeImg');
        if (barcodeContainer && barcodeImg && data.barcode) {
            barcodeImg.src = data.barcode;
            barcodeContainer.style.display = 'block';
        } else if (barcodeContainer) {
            barcodeContainer.style.display = 'none';
        }

        window._refReference = data.reference || null;
        window._refFolio = data.folio || null;

        document.getElementById('refInfoView').style.display = 'none';
        document.getElementById('refResultView').style.display = 'block';

        setTimeout(() => {
            document.getElementById('refResultView')
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);

        if (data.reference) {
            iniciarPollingPago({
                tipo: 'tarjeta',
                identificador: data.reference
            });
        }

    } catch (err) {
        if (overlay) overlay.classList.remove('active');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-barcode me-2"></i> Generar referencia de pago';
        }
        console.error('Error generarReferenciaEfectivo:', err);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor de referencias.',
            confirmButtonColor: '#27ae60'
        });
    }
}

function volverDeReferencia() {
    document.getElementById('refInfoView').style.display = 'block';
    document.getElementById('refResultView').style.display = 'none';
    window._refReference = null;
    window._refFolio = null;
}

/**
 * Regresa a la vista del formulario de referencia.
 */
function volverDeReferencia() {
    document.getElementById('refInfoView').style.display = 'block';
    document.getElementById('refResultView').style.display = 'none';
    window._refReference = null;
    window._refFolio = null;
}

// Detener polling al salir de la pestaña
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('hidden.bs.tab', (e) => {
            if (e.target.id === 'tab-referencia' && typeof detenerPollingPago === 'function') {
                detenerPollingPago();
            }
        });
    });
});