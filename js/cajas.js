// ========== VARIABLES GLOBALES ==========
        let lastScannedCode = '';
        let lastAutoScanTime = 0;
        const SCAN_DELAY = 1000;
        let barcodeBuffer = '';
        let barcodeTimeout;
        let searchTimeout;
        let currentSearchTerm = '';
        let currentCategory = '';
        let currentDescuentoProducto = null;
        let currentDescuentoIndex = null;
        let currentPrecioIndex = null;
        let currentPrecioProducto = null;

        window.currentCarrito = window.CajaConfig.carrito;

        // ========== FUNCIONES PARA ASIGNAR COMISIÓN POR PRODUCTO ==========
        let catalogosComision = null;
        let comisionIndexActual = null;

        function cargarCatalogosComision(callback) {
            if (catalogosComision) { callback(); return; }
            fetch('guardar_comision_producto.php?accion=obtener_catalogos')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        catalogosComision = data;
                        callback();
                    } else {
                        mostrarNotificacionError('No se pudieron cargar los catálogos de comisión');
                    }
                })
                .catch(() => mostrarNotificacionError('Error de conexión al cargar catálogos de comisión'));
        }

        function poblarSelectAreasComision() {
            const sel = document.getElementById('comisionArea');
            sel.innerHTML = catalogosComision.areas.map(a => `<option value="${a.id}">${a.nombre}</option>`).join('');
            poblarSelectReglasComision();
        }

        function poblarSelectReglasComision() {
            const areaId = document.getElementById('comisionArea').value;
            const reglas = catalogosComision.reglas.filter(r => r.area_id == areaId);
            const sel = document.getElementById('comisionRegla');
            sel.innerHTML = reglas.map(r => `<option value="${r.id}">${r.concepto} (${r.porcentaje}%)</option>`).join('');
        }

        function poblarSelectColaboradoresComision() {
            const sel = document.getElementById('comisionColaborador');
            sel.innerHTML = catalogosComision.colaboradores.map(c => `<option value="${c.id}">${c.nombre}</option>`).join('');
        }

        function poblarSelectPorcentajeRepartoComision() {
            const sel = document.getElementById('comisionPorcentajeReparto');
            let html = '<option value="100">100% (una sola persona)</option>';
            catalogosComision.porcentajes.forEach(p => {
                html += `<option value="${p.valor}">${p.valor}%</option>`;
            });
            sel.innerHTML = html;
        }

        function renderizarListaComisiones() {
            const item = window.currentCarrito[comisionIndexActual];
            const tbody = document.getElementById('comisionesListaTbody');
            const lista = (item && item.comisiones) || [];
            tbody.innerHTML = lista.map((c, i) => `
                <tr>
                    <td>${c.area_nombre}</td>
                    <td>${c.concepto}</td>
                    <td>${c.colaborador_nombre}</td>
                    <td>${c.porcentaje_reparto}%</td>
                    <td><button type="button" class="btn btn-sm btn-outline-danger btn-quitar-comision" data-i="${i}"><i class="fas fa-times"></i></button></td>
                </tr>
            `).join('') || '<tr><td colspan="5" class="text-center text-muted">Sin comisiones asignadas</td></tr>';
        }

        function guardarComisionesPendientesEnSesion() {
            const item = window.currentCarrito[comisionIndexActual];
            const formData = new FormData();
            formData.append('actualizar_comisiones_carrito_ajax', 'true');
            formData.append('index', comisionIndexActual);
            formData.append('comisiones', JSON.stringify((item && item.comisiones) || []));
            fetch('caja.php', { method: 'POST', body: formData });
        }

        function setupAsignarComision() {
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.btn-asignar-comision');
                if (btn) {
                    comisionIndexActual = btn.dataset.index;
                    document.getElementById('comisionProductoNombre').textContent = btn.dataset.productoNombre;

                    cargarCatalogosComision(function () {
                        poblarSelectAreasComision();
                        poblarSelectColaboradoresComision();
                        poblarSelectPorcentajeRepartoComision();
                        renderizarListaComisiones();
                        new bootstrap.Modal(document.getElementById('asignarComisionModal')).show();
                    });
                    return;
                }

                const btnQuitar = e.target.closest('.btn-quitar-comision');
                if (btnQuitar) {
                    window.currentCarrito[comisionIndexActual].comisiones.splice(parseInt(btnQuitar.dataset.i), 1);
                    renderizarListaComisiones();
                    guardarComisionesPendientesEnSesion();
                }
            });

            document.getElementById('comisionArea')?.addEventListener('change', poblarSelectReglasComision);

            document.getElementById('btnAgregarComisionLinea')?.addEventListener('click', function () {
                const areaSel = document.getElementById('comisionArea');
                const reglaSel = document.getElementById('comisionRegla');
                const colabSel = document.getElementById('comisionColaborador');
                const porcentajeReparto = document.getElementById('comisionPorcentajeReparto').value;

                if (!areaSel.value || !reglaSel.value || !colabSel.value) {
                    mostrarNotificacionError('Selecciona área, concepto y colaborador');
                    return;
                }

                const item = window.currentCarrito[comisionIndexActual];
                if (!item.comisiones) item.comisiones = [];
                item.comisiones.push({
                    area_id: areaSel.value,
                    area_nombre: areaSel.selectedOptions[0].textContent,
                    regla_id: reglaSel.value,
                    concepto: reglaSel.selectedOptions[0].textContent,
                    colaborador_id: colabSel.value,
                    colaborador_nombre: colabSel.selectedOptions[0].textContent,
                    porcentaje_reparto: porcentajeReparto
                });

                renderizarListaComisiones();
                guardarComisionesPendientesEnSesion();
            });
        }

        setupAsignarComision();

        // ========== FUNCIONES PARA DETECTAR DISPOSITIVOS ==========
        function esDispositivoMovil() {
            const userAgent = navigator.userAgent || navigator.vendor || window.opera;
            const esMobileUA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Tablet/i.test(userAgent);
            const esMobileSize = window.innerWidth <= 768;
            const tieneTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
            return esMobileUA || esMobileSize || tieneTouch;
        }

        // ========== FUNCIONES PARA EDITAR PRECIO UNITARIO ==========
        function setupEditarPrecio() {
            // Botones de escritorio
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-editar-precio');
                if (btn) {
                    e.preventDefault();
                    const index = btn.getAttribute('data-index');
                    const productoId = btn.getAttribute('data-producto-id');
                    const productoNombre = btn.getAttribute('data-producto-nombre');
                    const cantidad = parseFloat(btn.getAttribute('data-cantidad'));
                    const precioActual = parseFloat(btn.getAttribute('data-precio-actual'));

                    abrirModalEditarPrecio(index, productoId, productoNombre, cantidad, precioActual);
                }

                const btnMobile = e.target.closest('.btn-editar-precio-mobile');
                if (btnMobile) {
                    e.preventDefault();
                    const index = btnMobile.getAttribute('data-index');
                    const productoId = btnMobile.getAttribute('data-producto-id');
                    const productoNombre = btnMobile.getAttribute('data-producto-nombre');
                    const cantidad = parseFloat(btnMobile.getAttribute('data-cantidad'));
                    const precioActual = parseFloat(btnMobile.getAttribute('data-precio-actual'));

                    abrirModalEditarPrecio(index, productoId, productoNombre, cantidad, precioActual);
                }
            });
        }

        function abrirModalEditarPrecio(index, productoId, productoNombre, cantidad, precioActual) {
            const carrito = window.currentCarrito || [];
            const producto = carrito[index];

            if (!producto) {
                mostrarNotificacionError('Producto no encontrado en el carrito');
                return;
            }

            currentPrecioIndex = index;
            currentPrecioProducto = {
                id: productoId,
                nombre: productoNombre,
                cantidad: cantidad,
                precioActual: precioActual,
                index: index
            };

            document.getElementById('precioProductoNombre').textContent = productoNombre;
            document.getElementById('precioProductoCantidad').textContent = cantidad;
            const inputPrecio = document.getElementById('nuevoPrecio');
            inputPrecio.value = precioActual.toFixed(2);
            
            // Actualizar vista previa
            const subtotalActual = cantidad * precioActual;
            document.getElementById('precioSubtotalActual').textContent = `$${subtotalActual.toFixed(2)}`;
            document.getElementById('precioNuevoSubtotal').textContent = `$${subtotalActual.toFixed(2)}`;

            const modal = new bootstrap.Modal(document.getElementById('editarPrecioModal'));
            modal.show();

            inputPrecio.focus();
            inputPrecio.select();

            // Evento para actualizar vista previa en tiempo real
            inputPrecio.oninput = function() {
                const nuevoPrecio = parseFloat(this.value) || 0;
                const nuevoSubtotal = cantidad * nuevoPrecio;
                document.getElementById('precioNuevoSubtotal').textContent = `$${nuevoSubtotal.toFixed(2)}`;
            };
        }

function guardarPrecioProducto() {
    if (!currentPrecioProducto) {
        console.error('No hay producto seleccionado');
        return;
    }

    const nuevoPrecio = parseFloat(document.getElementById('nuevoPrecio').value) || 0;

    if (nuevoPrecio <= 0) {
        mostrarNotificacionError('El precio debe ser mayor a 0');
        return;
    }

    mostrarCargandoPrecio(true);

    const formData = new FormData();
    formData.append('actualizar_precio_ajax', 'true');
    formData.append('index', currentPrecioProducto.index);
    formData.append('precio', nuevoPrecio.toFixed(2));

    fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(async response => {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Error parseando JSON:', text.substring(0, 200));
                throw new Error('Respuesta inválida del servidor');
            }
        })
        .then(data => {
            if (data.success) {
                // IMPORTANTE: Recargar la página después de actualizar el precio
                // Esto asegura que todo el estado se reinicie correctamente
                mostrarNotificacionExito(`Precio actualizado a $${nuevoPrecio.toFixed(2)}`);
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('editarPrecioModal'));
                if (modal) modal.hide();
                
                // Recargar la página después de 1 segundo para mostrar el cambio
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                throw new Error(data.message || 'Error al actualizar el precio');
            }
        })
        .catch(error => {
            console.error('❌ Error al guardar precio:', error);
            mostrarNotificacionError('Error: ' + error.message);
        })
        .finally(() => {
            mostrarCargandoPrecio(false);
        });
}

        function mostrarCargandoPrecio(mostrar) {
            const btnGuardar = document.getElementById('btnGuardarPrecio');
            if (btnGuardar) {
                if (mostrar) {
                    btnGuardar.disabled = true;
                    btnGuardar.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Guardando...';
                } else {
                    btnGuardar.disabled = false;
                    btnGuardar.innerHTML = '<i class="fas fa-save me-1"></i>Actualizar Precio';
                }
            }
        }


        function formatearReferencia(referencia) {
            if (!referencia) return '';
            const limpio = referencia.replace(/\s/g, '');
            return limpio.match(/.{1,6}/g).join(' ') || referencia;
        }

        function generarCodigoVenta() {
            const timestamp = Date.now();
            const random = Math.floor(Math.random() * 10000);
            const fecha = new Date();
            const año = fecha.getFullYear().toString().slice(-2);
            const mes = (fecha.getMonth() + 1).toString().padStart(2, '0');
            const dia = fecha.getDate().toString().padStart(2, '0');
            const horas = fecha.getHours().toString().padStart(2, '0');
            const minutos = fecha.getMinutes().toString().padStart(2, '0');

            const base = `${timestamp}${random}`;
            return base.slice(0, 15).padStart(15, '0');
        }

        function generarLinkPago(total) {

            const qrLinkImage = document.getElementById('qrLinkImage');
            const qrLinkCodeBadge = document.getElementById('qrLinkCodeBadge');
            const qrLinkTotalAmount = document.getElementById('qrLinkTotalAmount');
            const refreshBtn = document.getElementById('refreshLinkQrBtn');
            const linkElement = document.getElementById('paymentLinkElement');

            if (qrLinkImage) {
                qrLinkImage.src = '';
                qrLinkImage.alt = 'Generando código QR...';
            }

            if (linkElement) {
                linkElement.textContent = 'Generando link de pago...';
                linkElement.href = '#';
                linkElement.classList.add('text-muted');
            }

            if (qrLinkCodeBadge) {
                qrLinkCodeBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando...';
            }

            if (qrLinkTotalAmount) {
                qrLinkTotalAmount.textContent = `$${parseFloat(total).toFixed(2)}`;
            }

            if (refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generando...';
            }

            const montoNumerico = parseFloat(total);
            if (isNaN(montoNumerico) || montoNumerico < 10) {
                mostrarNotificacionError('El monto mínimo es $10.00');
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                    refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Intentar de nuevo';
                }
                return;
            }

            const formData = new FormData();
            formData.append('monto', montoNumerico.toString());
            formData.append('descripcion', `Pago en caja - Folio: ${generarCodigoVenta()}`);


            fetch('Service/generar_link_pago_ideas.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                   

                    const text = await response.text();
                    

                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('❌ Error parseando JSON:', e);
                        throw new Error('Respuesta no válida del servidor');
                    }
                })
                .then(data => {
                    

                    if (data.success && data.url) {
                        if (linkElement) {
                            linkElement.textContent = data.url;
                            linkElement.href = data.url;
                            linkElement.classList.remove('text-muted');
                            linkElement.classList.add('text-primary');
                        }

                        if (data.url) {
                            const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=10&data=${encodeURIComponent(data.url)}`;

                            const img = new Image();
                            img.onload = function() {
                                if (qrLinkImage) {
                                    qrLinkImage.src = img.src;
                                    qrLinkImage.alt = 'QR de pago';
                                }
                            };
                            img.onerror = function() {
                                console.error('Error al generar QR');
                            };
                            img.src = qrApiUrl;
                        }

                        if (qrLinkCodeBadge && data.reference) {
                            qrLinkCodeBadge.textContent = formatearReferencia(data.reference);
                            qrLinkCodeBadge.style.display = 'inline-block';
                        }

                        mostrarNotificacionExito('✓ Link de pago generado');
                    } else {
                        throw new Error(data.error || 'Error al generar link');
                    }
                })
                .catch(error => {
                    console.error('❌ Error:', error);

                    if (linkElement) {
                        linkElement.textContent = 'Error: ' + error.message;
                        linkElement.href = '#';
                        linkElement.classList.add('text-danger');
                    }

                    if (qrLinkCodeBadge) {
                        qrLinkCodeBadge.innerHTML = '<span class="text-danger">Error</span>';
                    }

                    mostrarNotificacionError('Error: ' + error.message);
                })
                .finally(() => {
                    if (refreshBtn) {
                        refreshBtn.disabled = false;
                        refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Intentar de nuevo';
                    }
                });
        }

        function refreshLinkPago() {
            const totalElement = document.getElementById('modal-total');
            if (!totalElement) return;

            const totalText = totalElement.textContent.replace('$', '').replace(',', '');
            const total = parseFloat(totalText) || 0;

            if (total < 50) {
                mostrarNotificacionAdvertencia('El monto mínimo recomendado es $50.00');
            }

            generarLinkPago(total);
        }

        function copiarReferenciaLink(event) {
            const badge = document.getElementById('qrLinkCodeBadge');

            if (!badge || !badge.textContent || badge.textContent.includes('Generando') || badge.textContent.includes('Error')) {
                mostrarNotificacionError('No hay referencia disponible');
                return;
            }

            const referencia = badge.textContent.replace(/\s/g, '');

            navigator.clipboard.writeText(referencia).then(function() {
                mostrarNotificacionExito('✓ Referencia copiada al portapapeles');

                const btn = event.currentTarget;
                const originalHtml = btn.innerHTML;
                const originalClass = btn.className;

                btn.innerHTML = '<i class="fas fa-check me-1"></i>Copiado!';
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-success');

                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.className = originalClass;
                }, 2000);

            }).catch(function(err) {
                console.error('Error al copiar referencia:', err);
                mostrarNotificacionError('Error al copiar la referencia');
            });
        }

        function setupLinkPagoEvents() {
            const refreshBtn = document.getElementById('refreshLinkQrBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    refreshLinkPago();
                });
            }

            const copyRefBtn = document.getElementById('copyLinkReferenceBtn');
            if (copyRefBtn) {
                copyRefBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    copiarReferenciaLink(e);
                });
            }

            const qrLinkCodeBadge = document.getElementById('qrLinkCodeBadge');
            if (qrLinkCodeBadge) {
                qrLinkCodeBadge.addEventListener('dblclick', function() {
                    const paymentLink = document.getElementById('paymentLinkElement');
                    if (paymentLink && paymentLink.href && paymentLink.href !== '#') {
                        window.open(paymentLink.href, '_blank');
                    }
                });
            }
        }

                function generarCLABE() {
    const clabeDisplay = document.getElementById('clabeDisplay');
    if (!clabeDisplay) return;

    clabeDisplay.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando CLABE...';

    const totalElement = document.getElementById('modal-total');
    const totalText = totalElement ? totalElement.textContent.replace('$', '').replace(/,/g, '') : '0';
    const total = parseFloat(totalText) || 0;

    if (total <= 0) {
        clabeDisplay.innerHTML = '<span style="color: #dc3545;">Monto inválido</span>';
        mostrarNotificacionError('El monto debe ser mayor a 0');
        return;
    }

    // Generar referencia única
    const timestamp = Date.now().toString();
    const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
    const referencia = (timestamp + random).slice(0, 15).padStart(15, '0');

    // Obtener datos del cliente
    const clienteSelect = document.getElementById('clienteSelect');
    const clienteNombre = clienteSelect && clienteSelect.options[clienteSelect.selectedIndex] 
        ? clienteSelect.options[clienteSelect.selectedIndex].text 
        : 'Cliente General';

    // Preparar payload para generar_clabe.php
    const payload = {
        Account: referencia,
        CustomerEmail: 'cliente@libertyfin.com.mx',
        CustomerName: clienteNombre,
        Description: `Pago venta - ${referencia} - Monto: $${total.toFixed(2)}`,
        MontoTotal: total
    };


    // Llamar directamente a generar_clabe.php (NO a spei_endpoints.php)
    fetch('Service/generar_clabe.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
           
            if (!response.ok) {
                throw new Error(`Error HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {

            if (data.success && data.clabe) {
                let clabe = data.clabe.toString().replace(/\D/g, '');

                // Formatear CLABE (4 dígitos por grupo)
                const clabeFormateada = clabe.match(/.{1,4}/g)?.join(' ') || clabe;

                clabeDisplay.innerHTML = clabeFormateada;
                clabeDisplay.style.letterSpacing = '2px';
                clabeDisplay.style.fontSize = '22px';
                clabeDisplay.style.fontWeight = 'bold';
                clabeDisplay.style.fontFamily = 'monospace';
                clabeDisplay.style.color = 'white';

                clabeDisplay.setAttribute('data-clabe-raw', clabe);
                clabeDisplay.setAttribute('data-account', data.account || '');
                clabeDisplay.setAttribute('data-folio', data.folio || '');

                mostrarNotificacionExito('✓ CLABE generada exitosamente');

                // Guardar referencia en el hidden
                const paymentLinkHidden = document.getElementById('modal-paymentLinkHidden');
                if (paymentLinkHidden) {
                    paymentLinkHidden.value = clabe;
                }
            } else {
                throw new Error(data.error || 'No se pudo generar la CLABE');
            }
        })
        .catch(error => {
            console.error('❌ Error al generar CLABE:', error);
            clabeDisplay.innerHTML = '<span style="color: #dc3545;">Error al generar CLABE</span>';
            mostrarNotificacionError('Error: ' + error.message);
        });
}

        function copiarCLABE() {
            const clabeDisplay = document.getElementById('clabeDisplay');
            if (!clabeDisplay) return;

            let clabe = clabeDisplay.getAttribute('data-clabe-raw') ||
                clabeDisplay.textContent.replace(/\s/g, '');

            if (!clabe || clabe === 'ErroralgenerarCLABE' || clabe.includes('Error')) {
                mostrarNotificacionError('No hay CLABE disponible para copiar');
                return;
            }

            navigator.clipboard.writeText(clabe).then(function() {
                mostrarNotificacionExito('✓ CLABE copiada al portapapeles');

                const btn = event.currentTarget;
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check me-2"></i>Copiada!';
                btn.style.background = '#28a745';
                btn.style.color = 'white';

                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy me-2"></i>Copiar CLABE';
                    btn.style.background = 'white';
                    btn.style.color = '#667eea';
                }, 2000);

            }).catch(function(err) {
                console.error('Error al copiar:', err);
                mostrarNotificacionError('Error al copiar la CLABE');
            });
        }

        function setupPaymentMethods() {
            document.querySelectorAll('#pagoModal .payment-btn').forEach(method => {
                method.addEventListener('click', function() {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        document.getElementById('modal-metodoPagoInput').value = radio.value;
                    }

                    document.querySelectorAll('#pagoModal .payment-btn').forEach(m => m.classList.remove('active'));
                    this.classList.add('active');

                    const metodoSeleccionado = radio.value;
                    const efectivoSection = document.querySelector('.efectivo-section');
                    const qrSection = document.getElementById('qrSection');
                    const speiSection = document.getElementById('speiSection');
                    const qrLinkSection = document.getElementById('qrLinkSection');

                    if (efectivoSection) efectivoSection.style.display = 'none';
                    if (qrSection) qrSection.style.display = 'none';
                    if (speiSection) speiSection.style.display = 'none';
                    if (qrLinkSection) qrLinkSection.style.display = 'none';

                    if (metodoSeleccionado === 'efectivo') {
                        if (efectivoSection) efectivoSection.style.display = 'block';
                        setTimeout(() => {
                            const efectivoInput = document.getElementById('modal-efectivo-recibido');
                            if (efectivoInput) {
                                efectivoInput.focus();
                                efectivoInput.select();
                            }
                        }, 300);

                    } else if (metodoSeleccionado === 'tarjeta') {
                        const totalElement = document.getElementById('modal-total');
                        const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
                        const total = parseFloat(totalText) || 0;

                        if (total < 50) {
                            mostrarNotificacionAdvertencia('El monto mínimo para pagos electrónicos es $50.00. Seleccione otro método de pago.');

                            const efectivoRadio = document.getElementById('modal-efectivo');
                            if (efectivoRadio) {
                                efectivoRadio.checked = true;
                                document.getElementById('modal-metodoPagoInput').value = 'efectivo';
                                document.querySelector('#pagoModal .payment-btn[data-method="efectivo"]').classList.add('active');
                                this.classList.remove('active');

                                if (efectivoSection) efectivoSection.style.display = 'block';
                            }
                            return;
                        }

                        if (qrSection) {
                            qrSection.style.display = 'block';
                            const qrImage = document.getElementById('qrImage');
                            const qrCodeBadge = document.getElementById('qrCodeBadge');
                            const lectorPaymentLink = document.getElementById('paymentLink');

                            if (qrImage) qrImage.src = '';
                            if (qrCodeBadge) qrCodeBadge.innerHTML = '';
                            if (lectorPaymentLink) lectorPaymentLink.textContent = 'Cargando...';

                            generarQRPago(total);
                        }

                        if (qrLinkSection) {
                            qrLinkSection.style.display = 'block';
                            const qrLinkImage = document.getElementById('qrLinkImage');
                            const qrLinkCodeBadge = document.getElementById('qrLinkCodeBadge');
                            const linkElement = document.getElementById('paymentLinkElement');

                            if (qrLinkImage) qrLinkImage.src = '';
                            if (qrLinkCodeBadge) qrLinkCodeBadge.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generando...';
                            if (linkElement) linkElement.textContent = 'Cargando link de pago...';

                            generarLinkPago(total);
                        }

                        if (qrSection && qrLinkSection) {
                            qrSection.style.marginBottom = '20px';
                            qrLinkSection.style.marginTop = '10px';
                            qrLinkSection.style.borderTop = '2px solid #e9ecef';
                            qrLinkSection.style.paddingTop = '20px';
                        }

                    } else if (metodoSeleccionado === 'transferencia') {
                        const totalElement = document.getElementById('modal-total');
                        const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
                        const total = parseFloat(totalText) || 0;

                        if (total < 50) {
                            mostrarNotificacionAdvertencia('El monto mínimo para transferencias es $50.00. Seleccione otro método de pago.');

                            const efectivoRadio = document.getElementById('modal-efectivo');
                            if (efectivoRadio) {
                                efectivoRadio.checked = true;
                                document.getElementById('modal-metodoPagoInput').value = 'efectivo';
                                document.querySelector('#pagoModal .payment-btn[data-method="efectivo"]').classList.add('active');
                                this.classList.remove('active');

                                if (efectivoSection) efectivoSection.style.display = 'block';
                            }
                            return;
                        }

                        if (speiSection) {
                            speiSection.style.display = 'block';
                            generarCLABE();
                        }
                    }
                });
            });
        }

        function setupEfectivoInput() {
            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            if (!efectivoInput) return;

            efectivoInput.addEventListener('input', function(e) {
                updatePaymentValues(this.value);
            });

            efectivoInput.addEventListener('blur', function() {
                let value = this.value.trim();
                if (value === '') value = '0';
                const numericValue = parseFloat(value.replace(/[^\d.]/g, '')) || 0;
                this.value = numericValue.toFixed(2);
                updatePaymentValues(numericValue);
            });

            efectivoInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('modal-btnPagar').click();
                }
            });
        }

        function setupDescripcionInput() {
            const descripcionInput = document.getElementById('modal-descripcion');
            const descripcionHidden = document.getElementById('modal-descripcionHidden');

            if (descripcionInput && descripcionHidden) {
                descripcionInput.addEventListener('input', function() {
                    descripcionHidden.value = this.value;
                });
            }
        }

        function updatePaymentValues(inputValue) {
            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            const cambioInput = document.getElementById('modal-cambio');
            const efectivoHidden = document.getElementById('modal-efectivoRecibidoHidden');
            const cambioHidden = document.getElementById('modal-cambioHidden');
            const totalPagarInput = document.getElementById('modal-total-pagar');
            const totalText = totalPagarInput ? totalPagarInput.value.replace('$', '') : '0.00';
            const total = parseFloat(totalText) || 0;

            let numericValue = 0;
            if (inputValue === '' || inputValue === null || inputValue === undefined) {
                numericValue = 0;
                if (efectivoInput) efectivoInput.value = '';
            } else {
                const cleanValue = inputValue.toString().replace(/[^\d.]/g, '');
                const parts = cleanValue.split('.');
                let finalValue = parts[0];
                if (parts.length > 1) {
                    finalValue += '.' + parts[1].substring(0, 2);
                }
                numericValue = parseFloat(finalValue) || 0;
                if (finalValue !== '' && finalValue !== '0' && efectivoInput) {
                    efectivoInput.value = finalValue;
                }
            }

            if (efectivoHidden) efectivoHidden.value = numericValue.toFixed(2);
            const cambio = numericValue - total;
            if (cambioInput) {
                cambioInput.value = cambio >= 0 ? '$' + cambio.toFixed(2) : '$0.00';
            }
            if (cambioHidden) {
                cambioHidden.value = cambio >= 0 ? cambio.toFixed(2) : '0.00';
            }
        }

        function setupNumpad() {
            document.querySelectorAll('#pagoModal .numpad .numpad-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const value = this.getAttribute('data-value');
                    addNumberModal(value);
                });
            });
        }

        function addNumberModal(num) {
            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            if (!efectivoInput) return;

            let currentValue = efectivoInput.value;

            if (num === 'clear') {
                currentValue = '';
            } else if (num === '.') {
                if (!currentValue.includes('.')) {
                    currentValue = currentValue === '' ? '0.' : currentValue + '.';
                }
            } else {
                if (currentValue === '0' || currentValue === '') {
                    currentValue = num;
                } else {
                    currentValue += num;
                }
            }

            efectivoInput.value = currentValue;
            updatePaymentValues(currentValue);
            efectivoInput.focus();
        }

        function setupQRActions() {
            const refreshBtn = document.getElementById('refreshQrBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const totalElement = document.getElementById('modal-total');
                    const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0';
                    const total = parseFloat(totalText) || 0;
                    if (total < 50) {
                        mostrarNotificacionError('El monto mínimo para pago con lector es $50.00');
                        return;
                    }
                    mostrarNotificacionExito('Generando nuevo código QR...');
                    generarQRPago(total);
                });
            }

            const copyBtn = document.getElementById('copyReferenceBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const badge = document.getElementById('qrCodeBadge');
                    if (badge && badge.textContent && badge.textContent.trim() !== '') {
                        const referencia = badge.textContent.replace(/\s/g, '');
                        navigator.clipboard.writeText(referencia).then(function() {
                            mostrarNotificacionExito('✓ Referencia copiada al portapapeles');
                            const originalHtml = copyBtn.innerHTML;
                            copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copiado!';
                            setTimeout(() => {
                                copyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copiar referencia';
                            }, 2000);
                        }).catch(function(err) {
                            console.error('Error al copiar:', err);
                            mostrarNotificacionError('Error al copiar la referencia');
                        });
                    } else {
                        mostrarNotificacionError('No hay referencia disponible');
                    }
                });
            }
        }

        function abrirModalPago() {
            if (!window.currentCarrito || window.currentCarrito.length === 0) {
                mostrarNotificacionError('El carrito está vacío');
                return;
            }

            let subtotal = 0;
            let descuento = 0;
            let subtotalConDescuento = 0;

            if (window.currentCarrito && window.currentCarrito.length > 0) {
                window.currentCarrito.forEach(item => {
                    subtotal += item.subtotal || 0;
                    descuento += item.descuento || 0;
                    subtotalConDescuento += item.subtotal_con_descuento || item.subtotal || 0;
                });
            }

            const total = subtotalConDescuento;
            const modalElement = document.getElementById('pagoModal');
            if (!modalElement) {
                console.error('❌ No se encontró el elemento del modal');
                return;
            }

            const modal = new bootstrap.Modal(modalElement);

            const modalSubtotal = document.getElementById('modal-subtotal');
            if (modalSubtotal) {
                modalSubtotal.textContent = '$' + subtotal.toFixed(2);
            }

            const modalDescuento = document.getElementById('modal-descuento');
            if (modalDescuento) {
                modalDescuento.textContent = '-$' + descuento.toFixed(2);
            }

            const modalSubtotalConDescuento = document.getElementById('modal-subtotal-con-descuento');
            if (modalSubtotalConDescuento) {
                modalSubtotalConDescuento.textContent = '$' + subtotalConDescuento.toFixed(2);
            }

            const modalTotal = document.getElementById('modal-total');
            if (modalTotal) {
                modalTotal.textContent = '$' + total.toFixed(2);
            }

            const modalTotalPagar = document.getElementById('modal-total-pagar');
            if (modalTotalPagar) {
                modalTotalPagar.value = '$' + total.toFixed(2);
                modalTotalPagar.setAttribute('value', '$' + total.toFixed(2));
            }

            const btnPagar = document.getElementById('modal-btnPagar');
            if (btnPagar) {
                btnPagar.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        CONFIRMAR PAGO - $${total.toFixed(2)}
    `;
            }

            const efectivoSection = document.querySelector('.efectivo-section');
            const qrSection = document.getElementById('qrSection');
            const speiSection = document.getElementById('speiSection');
            const qrLinkSection = document.getElementById('qrLinkSection');

            if (efectivoSection) efectivoSection.style.display = 'block';
            if (qrSection) qrSection.style.display = 'none';
            if (speiSection) speiSection.style.display = 'none';
            if (qrLinkSection) qrLinkSection.style.display = 'none';

            document.querySelectorAll('#pagoModal .payment-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            const efectivoBtn = document.querySelector('#pagoModal .payment-btn[data-method="efectivo"]');
            if (efectivoBtn) efectivoBtn.classList.add('active');

            const efectivoRadio = document.getElementById('modal-efectivo');
            if (efectivoRadio) {
                efectivoRadio.checked = true;
            }

            const metodoPagoInput = document.getElementById('modal-metodoPagoInput');
            if (metodoPagoInput) {
                metodoPagoInput.value = 'efectivo';
            }

            const efectivoInput = document.getElementById('modal-efectivo-recibido');
            if (efectivoInput) {
                efectivoInput.value = '';
            }

            const descripcionInput = document.getElementById('modal-descripcion');
            const descripcionHidden = document.getElementById('modal-descripcionHidden');
            if (descripcionInput) descripcionInput.value = '';
            if (descripcionHidden) descripcionHidden.value = '';


            const cambioInput = document.getElementById('modal-cambio');
            if (cambioInput) {
                cambioInput.value = '$0.00';
            }

            const efectivoHidden = document.getElementById('modal-efectivoRecibidoHidden');
            if (efectivoHidden) {
                efectivoHidden.value = '0';
            }

            const cambioHidden = document.getElementById('modal-cambioHidden');
            if (cambioHidden) {
                cambioHidden.value = '0';
            }

            const descuentoTotal = document.getElementById('modal-descuentoTotal');
            if (descuentoTotal) {
                descuentoTotal.value = descuento.toFixed(2);
            }

            const paymentLinkHidden = document.getElementById('modal-paymentLinkHidden');
            if (paymentLinkHidden) {
                paymentLinkHidden.value = '';
            }

            modal.show();

            setTimeout(() => {
                const efectivoInputFocus = document.getElementById('modal-efectivo-recibido');
                if (efectivoInputFocus) {
                    efectivoInputFocus.focus();
                    efectivoInputFocus.select();
                    if (total > 0) {
                        efectivoInputFocus.value = total.toFixed(2);
                        updatePaymentValues(total.toFixed(2));
                    }
                }
            }, 500);
        }

        // ========== FUNCIONES PARA ACTUALIZACIÓN DINÁMICA DE CANTIDADES ==========
        function setupDynamicQuantityUpdates() {
            document.querySelectorAll('.quantity-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`input[name="cantidad"][data-index="${index}"]`);
                    let value = parseFloat(input.value);
                    if (this.classList.contains('increase')) {
                        value++;
                    } else if (this.classList.contains('decrease') && value > 1) {
                        value--;
                    }
                    input.value = value;
                    actualizarCantidadProducto(index, value);
                });
            });

            document.querySelectorAll('#mobile-carrito .quantity-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`#mobile-carrito input[name="cantidad"][data-index="${index}"]`);
                    let value = parseFloat(input.value);
                    if (this.classList.contains('increase')) {
                        value++;
                    } else if (this.classList.contains('decrease') && value > 1) {
                        value--;
                    }
                    input.value = value;
                    actualizarCantidadProducto(index, value);
                });
            });

            document.querySelectorAll('.quantity-input, .cantidad-input').forEach(input => {
                input.addEventListener('change', function(e) {
                    const index = this.dataset.index;
                    const value = this.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const index = this.dataset.index;
                        const value = this.value;
                        if (value && parseFloat(value) > 0) {
                            actualizarCantidadProducto(index, value);
                        }
                    }
                });
            });

            document.querySelectorAll('#mobile-carrito .quantity-input, #mobile-carrito .cantidad-input').forEach(input => {
                input.addEventListener('change', function(e) {
                    const index = this.dataset.index;
                    const value = this.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const index = this.dataset.index;
                        const value = this.value;
                        if (value && parseFloat(value) > 0) {
                            actualizarCantidadProducto(index, value);
                        }
                    }
                });
            });

            document.querySelectorAll('.btn-actualizar').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`input[name="cantidad"][data-index="${index}"]`);
                    const value = input.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
            });

            document.querySelectorAll('.btn-actualizar-mobile').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const index = this.dataset.index;
                    const input = document.querySelector(`#mobile-carrito input[name="cantidad"][data-index="${index}"]`);
                    const value = input.value;
                    if (value && parseFloat(value) > 0) {
                        actualizarCantidadProducto(index, value);
                    }
                });
            });
        }

        function actualizarCantidadProducto(index, cantidad) {
            mostrarCargandoActualizacion(index, true);

            const formData = new FormData();
            formData.append('actualizar_cantidad_ajax', 'true');
            formData.append('index', index);
            formData.append('cantidad', cantidad);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.carrito_actualizado) {
                            data.carrito_actualizado = data.carrito_actualizado.map(item => {
                                item.cantidad = parseFloat(item.cantidad);
                                return item;
                            });
                        }
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(data.message);
                    } else {
                        throw new Error(data.message || 'Error al actualizar la cantidad');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al actualizar cantidad:', error);
                    mostrarNotificacionError('Error: ' + error.message);
                    setTimeout(() => window.location.reload(), 2000);
                })
                .finally(() => {
                    mostrarCargandoActualizacion(index, false);
                });
        }

        function mostrarCargandoActualizacion(index, mostrar) {
            const filaDesktop = document.querySelector(`.cart-table tbody tr[data-index="${index}"]`);
            const cardMobile = document.querySelector(`#mobile-carrito .card[data-index="${index}"]`);
            const btnActualizarDesktop = document.querySelector(`.btn-actualizar[data-index="${index}"]`);
            const btnActualizarMobile = document.querySelector(`.btn-actualizar-mobile[data-index="${index}"]`);

            if (mostrar) {
                if (filaDesktop) filaDesktop.classList.add('actualizando');
                if (cardMobile) cardMobile.classList.add('actualizando');
                if (btnActualizarDesktop) {
                    btnActualizarDesktop.disabled = true;
                    btnActualizarDesktop.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
                if (btnActualizarMobile) {
                    btnActualizarMobile.disabled = true;
                    btnActualizarMobile.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
            } else {
                if (filaDesktop) filaDesktop.classList.remove('actualizando');
                if (cardMobile) cardMobile.classList.remove('actualizando');
                if (btnActualizarDesktop && document.contains(btnActualizarDesktop)) {
                    btnActualizarDesktop.disabled = false;
                    btnActualizarDesktop.innerHTML = '<i class="fas fa-check"></i>';
                }
                if (btnActualizarMobile && document.contains(btnActualizarMobile)) {
                    btnActualizarMobile.disabled = false;
                    btnActualizarMobile.innerHTML = '<i class="fas fa-check"></i>';
                }
            }
        }

        // ========== FUNCIONES PARA ELIMINAR Y VACIAR CARRITO ==========
        function setupEliminarProducto() {
            document.addEventListener('click', function(e) {
                if (e.target.closest('.btn-eliminar')) {
                    const btn = e.target.closest('.btn-eliminar');
                    const index = btn.getAttribute('data-index');
                    eliminarProductoCarrito(index);
                }
            });
        }

        function eliminarProductoCarrito(index) {
            mostrarCargandoEliminacion(index, true);

            const formData = new FormData();
            formData.append('eliminar_producto_ajax', 'true');
            formData.append('index', index);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(data.message);
                    } else {
                        actualizarInterfazCarrito(
                            data.carrito_actualizado || [],
                            data.totales || {
                                subtotal: 0,
                                descuento: 0,
                                subtotal_con_descuento: 0,
                                iva: 0,
                                total: 0
                            }
                        );
                    }
                })
                .catch(error => {
                    console.error('❌ Error de conexión:', error);
                    mostrarNotificacionError('Error de conexión al servidor');
                })
                .finally(() => {
                    mostrarCargandoEliminacion(index, false);
                });
        }

        function setupVaciarCarrito() {
            const btnVaciarCarrito = document.getElementById('btnVaciarCarrito');
            const mobileBtnVaciarCarrito = document.getElementById('mobileBtnVaciarCarrito');

            if (btnVaciarCarrito) {
                btnVaciarCarrito.addEventListener('click', function(e) {
                    e.preventDefault();
                    vaciarCarritoCompleto();
                });
            }
            if (mobileBtnVaciarCarrito) {
                mobileBtnVaciarCarrito.addEventListener('click', function(e) {
                    e.preventDefault();
                    vaciarCarritoCompleto();
                });
            }
        }

        function vaciarCarritoCompleto() {
            if (!confirm('¿Está seguro de vaciar todo el carrito?')) return;

            mostrarCargandoGlobal(true);

            const formData = new FormData();
            formData.append('vaciar_carrito_ajax', 'true');

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(data.message);
                    } else {
                        throw new Error(data.message || 'Error al vaciar el carrito');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al vaciar carrito:', error);
                    mostrarNotificacionError('Error: ' + error.message);
                })
                .finally(() => {
                    mostrarCargandoGlobal(false);
                });
        }

        function mostrarCargandoEliminacion(index, mostrar) {
            const filaDesktop = document.querySelector(`.cart-table tbody tr[data-index="${index}"]`);
            const cardMobile = document.querySelector(`#mobile-carrito .card[data-index="${index}"]`);
            const btnEliminarDesktop = document.querySelector(`.btn-eliminar[data-index="${index}"]`);
            const btnEliminarMobile = document.querySelector(`#mobile-carrito .btn-eliminar[data-index="${index}"]`);

            if (mostrar) {
                if (filaDesktop) filaDesktop.classList.add('actualizando');
                if (cardMobile) cardMobile.classList.add('actualizando');
                if (btnEliminarDesktop) {
                    btnEliminarDesktop.disabled = true;
                    btnEliminarDesktop.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
                if (btnEliminarMobile) {
                    btnEliminarMobile.disabled = true;
                    btnEliminarMobile.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
            } else {
                if (filaDesktop) filaDesktop.classList.remove('actualizando');
                if (cardMobile) cardMobile.classList.remove('actualizando');
                if (btnEliminarDesktop && document.contains(btnEliminarDesktop)) {
                    btnEliminarDesktop.disabled = false;
                    btnEliminarDesktop.innerHTML = '<i class="fas fa-times"></i>';
                }
                if (btnEliminarMobile && document.contains(btnEliminarMobile)) {
                    btnEliminarMobile.disabled = false;
                    btnEliminarMobile.innerHTML = '<i class="fas fa-times"></i>';
                }
            }
        }

        function mostrarCargandoGlobal(mostrar) {
            const btnVaciarDesktop = document.getElementById('btnVaciarCarrito');
            const btnVaciarMobile = document.getElementById('mobileBtnVaciarCarrito');

            if (mostrar) {
                if (btnVaciarDesktop) {
                    btnVaciarDesktop.disabled = true;
                    btnVaciarDesktop.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div>Vaciando...';
                }
                if (btnVaciarMobile) {
                    btnVaciarMobile.disabled = true;
                    btnVaciarMobile.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';
                }
                document.querySelectorAll('.cart-table tbody tr').forEach(tr => tr.classList.add('actualizando'));
                document.querySelectorAll('#mobile-carrito .card').forEach(card => card.classList.add('actualizando'));
            } else {
                if (btnVaciarDesktop) {
                    btnVaciarDesktop.disabled = false;
                    btnVaciarDesktop.innerHTML = '<i class="fas fa-trash me-1"></i>Vaciar Todo';
                }
                if (btnVaciarMobile) {
                    btnVaciarMobile.disabled = false;
                    btnVaciarMobile.innerHTML = '<i class="fas fa-trash"></i>';
                }
                document.querySelectorAll('.cart-table tbody tr').forEach(tr => tr.classList.remove('actualizando'));
                document.querySelectorAll('#mobile-carrito .card').forEach(card => card.classList.remove('actualizando'));
            }
        }

        function agregarProductoConCantidad(productoId, cantidad, callback) {

            const cantidadFloat = parseFloat(cantidad);
            if (isNaN(cantidadFloat) || cantidadFloat <= 0) {
                if (callback) callback(false, 'Cantidad no válida');
                return;
            }

            mostrarCargandoProducto(productoId, true);

            const formData = new FormData();
            formData.append('agregar_producto_ajax', 'true');
            formData.append('producto_id', productoId);
            formData.append('cantidad', cantidadFloat.toString());

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        if (data.carrito_actualizado) {
                            data.carrito_actualizado = data.carrito_actualizado.map(item => {
                                item.cantidad = parseFloat(item.cantidad);
                                return item;
                            });
                        }
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        if (callback && typeof callback === 'function') {
                            callback(true, data.message);
                        }
                    } else {
                        throw new Error(data.message || 'Error al agregar el producto');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al agregar producto:', error);
                    if (callback && typeof callback === 'function') {
                        callback(false, error.message);
                    }
                    mostrarNotificacionError('Error: ' + error.message);
                })
                .finally(() => {
                    mostrarCargandoProducto(productoId, false);
                });
        }

        function agregarProducto(productoId, permiteFracciones, unidadMedida, element) {

            const unidad = String(unidadMedida).toLowerCase().trim();

            const unidadesDecimales = ['kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
            ];

            const unidadesEnteras = ['pieza', 'piezas', 'unidad', 'unidades', 'pza', 'pzas'];

            let permiteDecimales = false;

            if (permiteFracciones == 1) {
                permiteDecimales = true;
            }
            if (unidadesDecimales.includes(unidad)) {
                permiteDecimales = true;
            }
            if (unidadesEnteras.includes(unidad)) {
                permiteDecimales = false;
            }

            const callback = (success, message) => {
                if (success) {
                    mostrarFeedbackExitoAgregar(element);
                }
            };

            if (permiteDecimales) {
                abrirModalCantidad(productoId, permiteFracciones, unidadMedida, element, callback);
            } else {
                agregarProductoConCantidad(productoId, 1, callback);
            }
        }

        function abrirModalCantidad(productoId, permiteFracciones, unidadMedida, element, callback) {
            const modal = new bootstrap.Modal(document.getElementById('cantidadModal'));
            const input = document.getElementById('cantidadInput');
            const unidadText = document.getElementById('unidadMedidaText');
            const unidad = String(unidadMedida).toLowerCase().trim();

            if (unidad === 'kg' || unidad === 'kilo' || unidad === 'kilogramo' || unidad === 'kilogramos') {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = 'kg';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad (Kilogramos)';
            } else if (unidad === 'ton' || unidad === 'tonelada' || unidad === 'toneladas') {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = 'ton';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad (Toneladas)';
            } else if (unidad === 'l' || unidad === 'litro' || unidad === 'litros') {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = 'L';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad (Litros)';
            } else {
                input.step = '0.001';
                input.min = '0.001';
                input.value = '1.000';
                unidadText.textContent = unidadMedida || 'unidades';
                document.getElementById('cantidadModalTitle').textContent = 'Seleccionar Cantidad';
            }

            document.getElementById('productoIdModal').value = productoId;

            const btnAgregar = document.getElementById('btnAgregarConCantidad');
            btnAgregar.onclick = function() {
                const cantidad = parseFloat(input.value);
                if (cantidad && cantidad > 0) {
                    agregarProductoConCantidad(productoId, cantidad, (success, message) => {
                        if (success) {
                            if (element) mostrarFeedbackExitoAgregar(element);
                            modal.hide();
                        } else {
                            mostrarNotificacionError(message);
                        }
                    });
                } else {
                    mostrarNotificacionError('Ingrese una cantidad válida');
                }
            };

            modal.show();
            input.focus();
            input.select();
        }

        function mostrarCargandoProducto(productoId, mostrar) {
            const productButtons = document.querySelectorAll(`.product-btn[onclick*="${productoId}"]`);

            productButtons.forEach(btn => {
                if (mostrar) {
                    btn.classList.add('actualizando');
                    btn.style.pointerEvents = 'none';
                    btn.style.opacity = '0.7';
                    if (!btn.querySelector('.spinner-border')) {
                        const spinner = document.createElement('div');
                        spinner.className = 'spinner-border spinner-border-sm position-absolute';
                        spinner.style.top = '50%';
                        spinner.style.left = '50%';
                        spinner.style.transform = 'translate(-50%, -50%)';
                        spinner.style.zIndex = '100';
                        spinner.style.color = 'var(--primary-color)';
                        btn.appendChild(spinner);
                    }
                } else {
                    btn.classList.remove('actualizando');
                    btn.style.pointerEvents = 'auto';
                    btn.style.opacity = '1';
                    const spinner = btn.querySelector('.spinner-border');
                    if (spinner) spinner.remove();
                }
            });
        }

        function mostrarFeedbackExitoAgregar(element) {
            if (element) {
                const originalBackground = element.style.backgroundColor;
                const originalBorder = element.style.borderColor;
                element.classList.add('product-added');
                element.style.backgroundColor = 'var(--light-green)';
                element.style.borderColor = 'var(--primary-color)';
                element.style.boxShadow = '0 0 15px rgba(39, 174, 96, 0.3)';
                setTimeout(() => {
                    element.classList.remove('product-added');
                    element.style.backgroundColor = originalBackground;
                    element.style.borderColor = originalBorder;
                    element.style.boxShadow = '';
                }, 600);
            }
        }

        // ========== FUNCIONES PARA ACTUALIZACIÓN DE CLIENTE ==========
        function setupClienteAjax() {
            const clienteSelect = document.getElementById('clienteSelect');
            const mobileClienteSelect = document.getElementById('mobileClienteSelect');

            if (clienteSelect) {
                clienteSelect.addEventListener('change', function(e) {
                    e.preventDefault();
                    actualizarClienteSeleccionado(this.value);
                });
            }
            if (mobileClienteSelect) {
                mobileClienteSelect.addEventListener('change', function(e) {
                    e.preventDefault();
                    actualizarClienteSeleccionado(this.value);
                });
            }
        }

        function actualizarClienteSeleccionado(clienteId) {
            mostrarCargandoCliente(true);

            const formData = new FormData();
            formData.append('actualizar_cliente_ajax', 'true');
            formData.append('cliente_id', clienteId);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCliente(data.cliente_id, data.cliente_nombre);
                        mostrarNotificacionExito(data.message);
                    } else {
                        throw new Error(data.message || 'Error al actualizar el cliente');
                    }
                })
                .catch(error => {
                    console.error('❌ Error al actualizar cliente:', error);
                    mostrarNotificacionError('Error: ' + error.message);
                    revertirSelectCliente();
                })
                .finally(() => {
                    mostrarCargandoCliente(false);
                });
        }

        function actualizarInterfazCliente(clienteId, clienteNombre) {
            const clientSections = document.querySelectorAll('.client-section');
            const clienteSelects = document.querySelectorAll('select[name="cliente_id"]');

            clientSections.forEach(section => {
                if (clienteId) {
                    section.classList.add('cliente-seleccionado');
                } else {
                    section.classList.remove('cliente-seleccionado');
                }
            });

            const badges = document.querySelectorAll('.section-title .badge.bg-success');
            badges.forEach(badge => {
                if (clienteId) {
                    badge.textContent = 'Seleccionado';
                    badge.style.display = 'inline';
                } else {
                    badge.style.display = 'none';
                }
            });

            clienteSelects.forEach(select => {
                if (select.value !== clienteId) {
                    select.value = clienteId || '';
                }
            });
        }

        function mostrarCargandoCliente(mostrar) {
            const clienteSelects = document.querySelectorAll('select[name="cliente_id"]');
            const clientSections = document.querySelectorAll('.client-section');

            if (mostrar) {
                clienteSelects.forEach(select => {
                    select.disabled = true;
                    select.style.opacity = '0.7';
                });
                clientSections.forEach(section => section.classList.add('actualizando'));
            } else {
                clienteSelects.forEach(select => {
                    select.disabled = false;
                    select.style.opacity = '1';
                });
                clientSections.forEach(section => section.classList.remove('actualizando'));
            }
        }

        function revertirSelectCliente() {
            const clienteActual = window.CajaConfig.clienteActual;
            const clienteSelects = document.querySelectorAll('select[name="cliente_id"]');

            clienteSelects.forEach(select => {
                select.value = clienteActual || '';
            });
        }

        // ========== FUNCIONES PARA ACTUALIZACIÓN DE LA INTERFAZ DEL CARRITO ==========
        function actualizarInterfazCarrito(carrito, totales) {
            window.currentCarrito = carrito.map(item => {
                return {
                    ...item,
                    cantidad: parseFloat(item.cantidad),
                    precio: parseFloat(item.precio),
                    subtotal: parseFloat(item.subtotal),
                    descuento: parseFloat(item.descuento || 0),
                    subtotal_con_descuento: parseFloat(item.subtotal_con_descuento || item.subtotal)
                };
            });

            if (window.currentCarrito.length === 0) {
                actualizarCarritoVacio();
                actualizarTotales(totales);
                actualizarContadores(0);
                actualizarBotonPago(0);
                return;
            }

            actualizarCarritoDesktop(window.currentCarrito);
            actualizarCarritoMobile(window.currentCarrito);
            actualizarTotales(totales);
            actualizarContadores(window.currentCarrito.length);
            actualizarBotonPago(totales.total);

            setTimeout(() => {
                setupDynamicQuantityUpdates();
                setupEliminarProducto();
                setupEditarDescuento();
                setupEditarPrecio();
            }, 100);
        }

        function actualizarCarritoDesktop(carrito) {
            const tbody = document.getElementById('carrito-body');
            let html = '';

            if (carrito.length === 0) {
                html = `
        <tr>
            <td colspan="7" class="text-center py-5">
                <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                <br>
                <span class="text-muted">Carrito vacío - Agregue productos para comenzar</span>
            </td>
        </tr>
        `;
            } else {
                carrito.forEach((item, index) => {
                    const tiene_descuento = item.descuento > 0;
                    const subtotal_con_descuento = item.subtotal_con_descuento || item.subtotal;
                    const descuento_porcentaje = item.descuento_porcentaje || 0;
                    const tiene_precio_mayoreo = item.tiene_precio_mayoreo || false;
                    const precio_base = item.precio_base || item.precio;

                    let cantidadDisplay;
                    let cantidadValue;
                    let stepValue;
                    let minValue;

                    if (item.permite_fracciones == 1) {
                        cantidadDisplay = item.cantidad.toFixed(3);
                        cantidadValue = item.cantidad.toFixed(3);
                        stepValue = "0.001";
                        minValue = "0.001";
                    } else {
                        cantidadDisplay = Math.floor(item.cantidad);
                        cantidadValue = Math.floor(item.cantidad);
                        stepValue = "1";
                        minValue = "1";
                    }

                    let imagenUrl = item.imagen_ruta || '';

                    if (!imagenUrl && item.imagen) {
                        if (item.imagen.startsWith('http')) {
                            imagenUrl = item.imagen;
                        } else {
                            imagenUrl = `img/productos/${item.imagen}`;
                        }
                    }

                    html += `
            <tr data-index="${index}">
                <td width="8%">
                    ${imagenUrl ? `
                        <img src="${imagenUrl}"
                            alt="${escapeHtml(item.nombre)}"
                            class="product-image-cart"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            onload="this.style.display='block'; this.nextElementSibling.style.display='none';">
                        <div class="product-image-placeholder-cart" style="display: none;">
                            <i class="fas fa-box"></i>
                        </div>
                    ` : `
                        <div class="product-image-placeholder-cart">
                            <i class="fas fa-box"></i>
                        </div>
                    `}
                </td>
                <td width="22%">
                    <div class="fw-bold text-dark">${escapeHtml(item.nombre)}</div>
                    <small class="text-muted">Código: ${escapeHtml(item.codigo)}</small>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                        ${item.permite_fracciones == 1 ? `
                            <div>
                                <span class="badge tipo-venta-badge tipo-peso">
                                    ${item.unidad_medida ? item.unidad_medida.charAt(0).toUpperCase() + item.unidad_medida.slice(1) : 'Peso'}
                                </span>
                            </div>
                        ` : ''}
                        ${tiene_precio_mayoreo ? `
                            <div>
                                <span class="badge mayoreo-badge">
                                    <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                </span>
                            </div>
                        ` : ''}
                        <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio" 
                                data-index="${index}"
                                data-producto-id="${item.id}"
                                data-producto-nombre="${escapeHtml(item.nombre)}"
                                data-cantidad="${item.cantidad}"
                                data-precio-actual="${item.precio.toFixed(2)}">
                            <i class="fas fa-edit me-1"></i>Editar Precio
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success btn-asignar-comision"
                                data-index="${index}"
                                data-producto-id="${item.id}"
                                data-producto-nombre="${escapeHtml(item.nombre)}">
                            <i class="fas fa-user-tag me-1"></i>Comisión
                            ${item.comisiones && item.comisiones.length ? `<span class="badge bg-success ms-1">${item.comisiones.length}</span>` : ''}
                        </button>
                    </div>
                </td>
                <td width="12%">
                    <div class="quantity-control">
                        ${item.permite_fracciones == 0 ? `
                            <button type="button" class="quantity-btn decrease" data-index="${index}">-</button>
                            <input type="number" name="cantidad" value="${cantidadValue}"
                                min="${minValue}" step="${stepValue}" class="quantity-input" data-index="${index}">
                            <button type="button" class="quantity-btn increase" data-index="${index}">+</button>
                        ` : `
                            <input type="number" name="cantidad" value="${cantidadValue}"
                                step="${stepValue}" min="${minValue}" class="cantidad-input" data-index="${index}" style="width: 80px;">
                            <span class="unidad-medida ms-1">${item.unidad_medida || 'kg'}</span>
                        `}
                        <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar" data-index="${index}">
                            <i class="fas fa-check"></i>
                        </button>
                    </div>
                </td>
                <td width="12%" class="fw-bold text-success precio-unitario" data-index="${index}">
                    ${tiene_precio_mayoreo ? `
                        <div class="d-flex flex-column">
                            <span class="text-muted small" style="text-decoration: line-through;">$${precio_base.toFixed(2)}</span>
                            <span>$${item.precio.toFixed(2)}</span>
                        </div>
                    ` : `
                        $${item.precio.toFixed(2)}
                    `}
                </td>
                <td width="12%">
                    <div class="descuento-control">
                        <div class="descuento-info d-flex align-items-center gap-2">
                            ${tiene_descuento ? `
                                <span class="badge bg-danger">-${descuento_porcentaje.toFixed(0)}%</span>
                                <span class="small text-muted">-$${item.descuento.toFixed(2)}</span>
                            ` : `
                                <span class="badge bg-secondary">0%</span>
                            `}
                            <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento" 
                                    data-index="${index}"
                                    data-producto-id="${item.id}"
                                    data-descuento-actual="${descuento_porcentaje}"
                                    data-producto-nombre="${escapeHtml(item.nombre)}">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </div>
                </td>
                <td width="12%" class="subtotal-descuento" data-index="${index}">
                    ${tiene_descuento ? `
                        <div class="subtotal-descuento">
                            <span class="subtotal-original">$${item.subtotal.toFixed(2)}</span>
                            <span class="subtotal-final">$${subtotal_con_descuento.toFixed(2)}</span>
                        </div>
                    ` : `
                        <span class="fw-bold text-primary">$${item.subtotal.toFixed(2)}</span>
                    `}
                </td>
                <td width="10%">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" data-index="${index}">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
            `;
                });
            }

            tbody.innerHTML = html;
        }

        function actualizarCarritoMobile(carrito) {
            const container = document.getElementById('mobile-carrito-container');
            let html = '';

            if (carrito.length === 0) {
                html = `
        <div class="text-center py-5">
            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
            <p class="text-muted">Carrito vacío</p>
        </div>
        `;
            } else {
                carrito.forEach((item, index) => {
                    const tiene_descuento = item.descuento > 0;
                    const subtotal_con_descuento = item.subtotal_con_descuento || item.subtotal;
                    const descuento_porcentaje = item.descuento_porcentaje || 0;
                    const tiene_precio_mayoreo = item.tiene_precio_mayoreo || false;
                    const precio_base = item.precio_base || item.precio;

                    let cantidadValue;
                    let stepValue;
                    let minValue;
                    let inputWidth;

                    if (item.permite_fracciones == 1) {
                        cantidadValue = item.cantidad.toFixed(3);
                        stepValue = "0.001";
                        minValue = "0.001";
                        inputWidth = "80px";
                    } else {
                        cantidadValue = Math.floor(item.cantidad);
                        stepValue = "1";
                        minValue = "1";
                        inputWidth = "60px";
                    }

                    let imagenUrl = item.imagen_ruta || '';

                    if (!imagenUrl && item.imagen) {
                        if (item.imagen.startsWith('http')) {
                            imagenUrl = item.imagen;
                        } else {
                            imagenUrl = `img/productos/${item.imagen}`;
                        }
                    }

                    html += `
            <div class="card mb-3" data-index="${index}">
                <div class="card-body">
                    <div class="row align-items-start">
                        <div class="col-3">
                            ${imagenUrl ? `
                                <img src="${imagenUrl}"
                                    alt="${escapeHtml(item.nombre)}"
                                    class="product-image-cart"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                    onload="this.style.display='block'; this.nextElementSibling.style.display='none';">
                                <div class="product-image-placeholder-cart" style="display: none;">
                                    <i class="fas fa-box"></i>
                                </div>
                            ` : `
                                <div class="product-image-placeholder-cart">
                                    <i class="fas fa-box"></i>
                                </div>
                            `}
                        </div>
                        <div class="col-9">
                            <div class="row align-items-center">
                                <div class="col-12">
                                    <h6 class="card-title mb-1">${escapeHtml(item.nombre)}</h6>
                                    <p class="card-text text-muted small mb-1">Código: ${escapeHtml(item.codigo)}</p>
                                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                        ${item.permite_fracciones == 1 ? `
                                            <span class="badge tipo-venta-badge tipo-peso">
                                                ${item.unidad_medida ? item.unidad_medida.charAt(0).toUpperCase() + item.unidad_medida.slice(1) : 'Peso'}
                                            </span>
                                        ` : ''}
                                        ${tiene_precio_mayoreo ? `
                                            <span class="badge mayoreo-badge">
                                                <i class="fas fa-tags me-1"></i>Precio Mayoreo
                                            </span>
                                        ` : ''}
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-editar-precio-mobile" 
                                                data-index="${index}"
                                                data-producto-id="${item.id}"
                                                data-producto-nombre="${escapeHtml(item.nombre)}"
                                                data-cantidad="${item.cantidad}"
                                                data-precio-actual="${item.precio.toFixed(2)}">
                                            <i class="fas fa-edit me-1"></i>Editar Precio
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success btn-asignar-comision"
                                                data-index="${index}"
                                                data-producto-id="${item.id}"
                                                data-producto-nombre="${escapeHtml(item.nombre)}">
                                            <i class="fas fa-user-tag me-1"></i>Comisión
                                            ${item.comisiones && item.comisiones.length ? `<span class="badge bg-success ms-1">${item.comisiones.length}</span>` : ''}
                                        </button>
                                    </div>
                                    
                                    <div class="descuento-info mt-1">
                                        ${tiene_descuento ? `
                                            <span class="badge bg-danger">-${descuento_porcentaje.toFixed(0)}%</span>
                                            <span class="small text-muted">-$${item.descuento.toFixed(2)}</span>
                                        ` : `
                                            <span class="badge bg-secondary">0%</span>
                                        `}
                                        <button type="button" class="btn btn-sm btn-outline-warning btn-editar-descuento-mobile ms-1" 
                                                data-index="${index}"
                                                data-producto-id="${item.id}"
                                                data-descuento-actual="${descuento_porcentaje}"
                                                data-producto-nombre="${escapeHtml(item.nombre)}">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </div>
                                    
                                    <p class="card-text mb-0 mt-2">
                                        ${tiene_precio_mayoreo ? `
                                            <div class="d-flex flex-column">
                                                <span class="text-muted small" style="text-decoration: line-through;">$${precio_base.toFixed(2)}</span>
                                                <span class="text-success fw-bold">$${item.precio.toFixed(2)}</span>
                                            </div>
                                        ` : `
                                            <span class="text-success fw-bold">$${item.precio.toFixed(2)}</span>
                                        `}
                                        <span class="text-muted"> x </span>
                                    </p>
                                    <div class="quantity-control d-inline-flex align-items-center mt-1">
                                        ${item.permite_fracciones == 0 ? `
                                            <button type="button" class="quantity-btn decrease" data-index="${index}">-</button>
                                            <input type="number" name="cantidad" value="${cantidadValue}"
                                                min="${minValue}" step="${stepValue}" class="quantity-input" data-index="${index}" style="width: ${inputWidth}; font-size: 12px;">
                                            <button type="button" class="quantity-btn increase" data-index="${index}">+</button>
                                        ` : `
                                            <input type="number" name="cantidad" value="${cantidadValue}"
                                                step="${stepValue}" min="${minValue}" class="cantidad-input" data-index="${index}" style="width: ${inputWidth}; font-size: 12px;">
                                            <span class="unidad-medida ms-1" style="font-size: 11px;">${item.unidad_medida || 'kg'}</span>
                                        `}
                                        <button type="button" class="btn btn-sm btn-outline-primary ms-2 btn-actualizar-mobile" data-index="${index}">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </div>
                                    <p class="card-text mt-2">
                                        ${tiene_descuento ? `
                                            <span class="text-muted small" style="text-decoration: line-through;">Total: $${item.subtotal.toFixed(2)}</span><br>
                                            <span class="fw-bold text-primary">Total con descuento: $${subtotal_con_descuento.toFixed(2)}</span>
                                        ` : `
                                            <span class="fw-bold text-primary">Total: $${item.subtotal.toFixed(2)}</span>
                                        `}
                                    </p>
                                </div>
                                <div class="col-12 text-end mt-2">
                                    <button type="button" class="btn btn-outline-danger btn-sm btn-eliminar" data-index="${index}">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            `;
                });
            }

            container.innerHTML = html;
        }

        function setupEditarDescuento() {
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-editar-descuento');
                if (btn) {
                    e.preventDefault();
                    const index = btn.getAttribute('data-index');
                    const productoId = btn.getAttribute('data-producto-id');
                    const descuentoActual = parseFloat(btn.getAttribute('data-descuento-actual')) || 0;
                    const productoNombre = btn.getAttribute('data-producto-nombre');

                    abrirModalEditarDescuento(index, productoId, descuentoActual, productoNombre);
                }

                const btnMobile = e.target.closest('.btn-editar-descuento-mobile');
                if (btnMobile) {
                    e.preventDefault();
                    const index = btnMobile.getAttribute('data-index');
                    const productoId = btnMobile.getAttribute('data-producto-id');
                    const descuentoActual = parseFloat(btnMobile.getAttribute('data-descuento-actual')) || 0;
                    const productoNombre = btnMobile.getAttribute('data-producto-nombre');

                    abrirModalEditarDescuento(index, productoId, descuentoActual, productoNombre);
                }
            });
        }

        function abrirModalEditarDescuento(index, productoId, descuentoActual, productoNombre) {
            const carrito = window.currentCarrito || [];
            const producto = carrito[index];

            if (!producto) {
                mostrarNotificacionError('Producto no encontrado en el carrito');
                return;
            }

            currentDescuentoIndex = index;
            currentDescuentoProducto = {
                id: productoId,
                nombre: productoNombre,
                precio: producto.precio,
                cantidad: producto.cantidad,
                index: index
            };

            document.getElementById('productoNombreEditar').textContent = productoNombre;
            document.getElementById('precioUnitarioEditar').textContent = `$${parseFloat(producto.precio).toFixed(2)}`;
            const inputDescuento = document.getElementById('porcentajeDescuento');
            inputDescuento.value = descuentoActual;

            actualizarVistaPrevia(descuentoActual);

            const modal = new bootstrap.Modal(document.getElementById('editarDescuentoModal'));
            modal.show();

            inputDescuento.focus();
            inputDescuento.select();
        }

        function actualizarVistaPrevia(porcentaje) {
            if (!currentDescuentoProducto) return;

            const subtotal = currentDescuentoProducto.precio * currentDescuentoProducto.cantidad;
            const descuento = subtotal * (porcentaje / 100);
            const total = subtotal - descuento;

            document.getElementById('previewSubtotal').textContent = `$${subtotal.toFixed(2)}`;
            document.getElementById('previewDescuento').textContent = `-$${descuento.toFixed(2)}`;
            document.getElementById('previewTotal').textContent = `$${total.toFixed(2)}`;
        }

        function setupPreviewDescuento() {
            const inputDescuento = document.getElementById('porcentajeDescuento');
            if (inputDescuento) {
                inputDescuento.addEventListener('input', function() {
                    const porcentaje = parseFloat(this.value) || 0;
                    actualizarVistaPrevia(Math.min(100, Math.max(0, porcentaje)));
                });
            }
        }

        function guardarDescuentoProducto() {
            if (!currentDescuentoProducto) {
                console.error('No hay producto seleccionado');
                return;
            }

            const porcentaje = parseFloat(document.getElementById('porcentajeDescuento').value) || 0;
            const porcentajeValidado = Math.min(100, Math.max(0, porcentaje));

            if (porcentajeValidado !== porcentaje) {
                document.getElementById('porcentajeDescuento').value = porcentajeValidado;
            }

            mostrarCargandoDescuento(true);

            const formData = new FormData();
            formData.append('actualizar_descuento_ajax', 'true');
            formData.append('producto_id', currentDescuentoProducto.id);
            formData.append('descuento_porcentaje', porcentajeValidado);
            formData.append('index', currentDescuentoProducto.index);

            fetch('caja.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(async response => {

                    const text = await response.text();

                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Error parseando JSON:', e);
                        throw new Error('El servidor devolvió HTML en lugar de JSON. Esto suele indicar un error en el servidor.');
                    }
                })
                .then(data => {
                    if (data.success) {
                        actualizarInterfazCarrito(data.carrito_actualizado, data.totales);
                        mostrarNotificacionExito(`Descuento actualizado a ${porcentajeValidado}%`);

                        const modal = bootstrap.Modal.getInstance(document.getElementById('editarDescuentoModal'));
                        if (modal) modal.hide();
                    } else {
                        throw new Error(data.message || 'Error al actualizar el descuento');
                    }
                })
                .catch(error => {
                   
                    mostrarNotificacionError('Error: ' + error.message);

                    if (error.message.includes('HTML')) {
                        mostrarNotificacionError('Error de servidor. Por favor revise los logs.');
                    }
                })
                .finally(() => {
                    mostrarCargandoDescuento(false);
                });
        }

        function mostrarCargandoDescuento(mostrar) {
            const btnGuardar = document.getElementById('btnGuardarDescuento');
            if (btnGuardar) {
                if (mostrar) {
                    btnGuardar.disabled = true;
                    btnGuardar.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div>Guardando...';
                } else {
                    btnGuardar.disabled = false;
                    btnGuardar.innerHTML = '<i class="fas fa-save me-1"></i>Guardar Descuento';
                }
            }
        }

        function manejarErrorImagen(imgElement, nombreImagen) {
            const rutasPosibles = [
                `img/productos/${nombreImagen}`,
                `uploads/productos/${nombreImagen}`,
                `producto_imagenes/${nombreImagen}`,
                `../img/productos/${nombreImagen}`,
                `../uploads/productos/${nombreImagen}`,
                `admin/img/productos/${nombreImagen}`,
                `assets/img/productos/${nombreImagen}`,
                `images/productos/${nombreImagen}`
            ];

            let intento = parseInt(imgElement.getAttribute('data-intento') || '0');

            if (intento < rutasPosibles.length) {
                imgElement.src = rutasPosibles[intento];
                imgElement.setAttribute('data-intento', intento + 1);
        
            } else {
                imgElement.style.display = 'none';
                const placeholder = imgElement.parentElement.querySelector('.product-image-placeholder-cart');
                if (placeholder) {
                    placeholder.style.display = 'flex';
                }
            }
        }

        function actualizarCarritoVacio() {
            const tbody = document.getElementById('carrito-body');
            const container = document.getElementById('mobile-carrito-container');

            tbody.innerHTML = `
    <tr>
        <td colspan="7" class="text-center py-5">
            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
            <br>
            <span class="text-muted">Carrito vacío - Agregue productos para comenzar</span>
        </td>
    </tr>
`;

            container.innerHTML = `
    <div class="text-center py-5">
        <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
        <p class="text-muted">Carrito vacío</p>
    </div>
`;
        }

        function actualizarTotales(totales) {
            const subtotalDisplay = document.getElementById('subtotal-display');
            const descuentoDisplay = document.getElementById('descuento-display');
            const subtotalConDescuentoDisplay = document.getElementById('subtotal-con-descuento-display');
            const totalDisplay = document.getElementById('total-display');
            const totalPagarDisplay = document.getElementById('total-pagar-display');
            const modalSubtotal = document.getElementById('modal-subtotal');
            const modalDescuento = document.getElementById('modal-descuento');
            const modalSubtotalConDescuento = document.getElementById('modal-subtotal-con-descuento');
            const modalTotal = document.getElementById('modal-total');
            const modalTotalPagar = document.getElementById('modal-total-pagar');
            const modalDescuentoTotal = document.getElementById('modal-descuentoTotal');
            const modalBtnPagar = document.getElementById('modal-btnPagar');
            const mobileSubtotalDisplay = document.getElementById('mobile-subtotal-display');
            const mobileDescuentoDisplay = document.getElementById('mobile-descuento-display');
            const mobileSubtotalConDescuentoDisplay = document.getElementById('mobile-subtotal-con-descuento-display');
            const mobileTotalDisplay = document.getElementById('mobile-total-display');
            const mobileTotalPagarDisplay = document.getElementById('mobile-total-pagar-display');

            if (subtotalDisplay) subtotalDisplay.textContent = '$' + parseFloat(totales.subtotal).toFixed(2);
            if (descuentoDisplay) descuentoDisplay.textContent = '-$' + parseFloat(totales.descuento).toFixed(2);
            if (subtotalConDescuentoDisplay) subtotalConDescuentoDisplay.textContent = '$' + parseFloat(totales.subtotal_con_descuento).toFixed(2);
            if (totalDisplay) totalDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);
            if (totalPagarDisplay) totalPagarDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);

            if (modalSubtotal) modalSubtotal.textContent = '$' + parseFloat(totales.subtotal).toFixed(2);
            if (modalDescuento) modalDescuento.textContent = '-$' + parseFloat(totales.descuento).toFixed(2);
            if (modalSubtotalConDescuento) modalSubtotalConDescuento.textContent = '$' + parseFloat(totales.subtotal_con_descuento).toFixed(2);
            if (modalTotal) modalTotal.textContent = '$' + parseFloat(totales.total).toFixed(2);
            if (modalTotalPagar) {
                modalTotalPagar.value = '$' + parseFloat(totales.total).toFixed(2);
                modalTotalPagar.setAttribute('value', '$' + parseFloat(totales.total).toFixed(2));
            }
            if (modalDescuentoTotal) modalDescuentoTotal.value = parseFloat(totales.descuento).toFixed(2);
            if (modalBtnPagar) {
                modalBtnPagar.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        CONFIRMAR PAGO - $${parseFloat(totales.total).toFixed(2)}
    `;
            }

            if (mobileSubtotalDisplay) mobileSubtotalDisplay.textContent = '$' + parseFloat(totales.subtotal).toFixed(2);
            if (mobileDescuentoDisplay) mobileDescuentoDisplay.textContent = '-$' + parseFloat(totales.descuento).toFixed(2);
            if (mobileSubtotalConDescuentoDisplay) mobileSubtotalConDescuentoDisplay.textContent = '$' + parseFloat(totales.subtotal_con_descuento).toFixed(2);
            if (mobileTotalDisplay) mobileTotalDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);
            if (mobileTotalPagarDisplay) mobileTotalPagarDisplay.textContent = '$' + parseFloat(totales.total).toFixed(2);
        }

        function actualizarContadores(cantidadProductos) {
            const badgeCarrito = document.querySelector('.mobile-tab[data-tab="carrito"] .badge');
            if (badgeCarrito) {
                if (cantidadProductos > 0) {
                    badgeCarrito.textContent = cantidadProductos;
                    badgeCarrito.style.display = 'inline';
                } else {
                    badgeCarrito.style.display = 'none';
                }
            }

            const contadorDesktop = document.querySelector('.left-section .section-title .badge');
            if (contadorDesktop) {
                if (cantidadProductos > 0) {
                    contadorDesktop.textContent = cantidadProductos + ' productos';
                    contadorDesktop.style.display = 'inline';
                } else {
                    contadorDesktop.style.display = 'none';
                }
            }

            const btnVaciarDesktop = document.getElementById('btnVaciarCarrito');
            const btnVaciarMobile = document.getElementById('mobileBtnVaciarCarrito');
            if (btnVaciarDesktop) btnVaciarDesktop.style.display = cantidadProductos > 0 ? 'block' : 'none';
            if (btnVaciarMobile) btnVaciarMobile.style.display = cantidadProductos > 0 ? 'block' : 'none';
        }

        function actualizarBotonPago(total) {
            const btnPagarDesktop = document.getElementById('btnAbrirModalPago');
            const btnPagarMobile = document.getElementById('mobile-btnAbrirModalPago');
            const carritoVacio = !window.currentCarrito || window.currentCarrito.length === 0;

            if (btnPagarDesktop) btnPagarDesktop.disabled = (total <= 0 || carritoVacio);
            if (btnPagarMobile) btnPagarMobile.disabled = (total <= 0 || carritoVacio);
        }

        // ========== FUNCIONES PARA MODAL DE CLIENTE ==========
        function setupClienteModal() {
            const clienteModal = document.getElementById('clienteModal');
            if (clienteModal) {
                clienteModal.addEventListener('show.bs.modal', function() {
                    document.getElementById('modalTitle').textContent = 'Nuevo Cliente';
                    document.getElementById('formAction').value = 'crear';
                    document.getElementById('clienteId').value = '';
                    document.getElementById('clienteForm').reset();
                });
            }
        }

        // ========== FUNCIONES PARA BÚSQUEDA EN TIEMPO REAL ==========
        function initializeRealTimeSearch() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');
            const categoriaSelect = document.getElementById('categoriaSelect');
            const mobileCategoriaSelect = document.getElementById('mobileCategoriaSelect');

            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    currentSearchTerm = this.value.trim();
                    clearTimeout(searchTimeout);
                    if (currentSearchTerm.length >= 2 || currentSearchTerm.length === 0) {
                        searchTimeout = setTimeout(() => {
                            performRealTimeSearch(currentSearchTerm, currentCategory);
                        }, 300);
                    }
                    updateClearButton(this.value, 'btnClearSearch');
                    updateMobileClearButton(this.value, 'mobileBtnClearSearch');
                });

                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        currentSearchTerm = this.value.trim();
                        performRealTimeSearch(currentSearchTerm, currentCategory);
                    }
                });
            }

            if (mobileSearchInput) {
                mobileSearchInput.addEventListener('input', function(e) {
                    currentSearchTerm = this.value.trim();
                    clearTimeout(searchTimeout);
                    if (currentSearchTerm.length >= 2 || currentSearchTerm.length === 0) {
                        searchTimeout = setTimeout(() => {
                            performRealTimeSearch(currentSearchTerm, currentCategory);
                        }, 300);
                    }
                    updateClearButton(this.value, 'mobileBtnClearSearch');
                    updateMobileClearButton(this.value, 'btnClearSearch');
                });

                mobileSearchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        currentSearchTerm = this.value.trim();
                        performRealTimeSearch(currentSearchTerm, currentCategory);
                    }
                });
            }

            if (categoriaSelect) {
                categoriaSelect.addEventListener('change', function() {
                    currentCategory = this.value;
                    performRealTimeSearch(currentSearchTerm, currentCategory);
                });
            }
            if (mobileCategoriaSelect) {
                mobileCategoriaSelect.addEventListener('change', function() {
                    currentCategory = this.value;
                    performRealTimeSearch(currentSearchTerm, currentCategory);
                });
            }

            setupClearButtons();
        }

        function performRealTimeSearch(searchTerm, categoryId) {
            showSearchLoading(true);

            const formData = new FormData();
            if (searchTerm && searchTerm.length > 0) {
                formData.append('busqueda', searchTerm);
            }
            if (categoryId && categoryId !== '') {
                formData.append('categoria_id', categoryId);
            }
            formData.append('sucursal_id', window.CajaConfig.sucursalId);
            formData.append('real_time', 'true');

            fetch('buscar_productos_tiempo_real.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error(`Error HTTP: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    if (data.success) {
                        updateProductGrid(data.productos);
                        updateSearchResultsCount(data.productos.length, searchTerm);
                    } else {
                        throw new Error('Respuesta del servidor no exitosa');
                    }
                    showSearchLoading(false);
                })
                .catch(error => {
                    console.error('Error en búsqueda en tiempo real:', error);
                    showSearchLoading(false);
                    showSearchError('Error al buscar productos: ' + error.message);
                    fallbackSearch(searchTerm, categoryId);
                });
        }

        function fallbackSearch(searchTerm, categoryId) {
            const params = new URLSearchParams();
            if (searchTerm && searchTerm.length > 0) params.append('busqueda_nombre', searchTerm);
            if (categoryId && categoryId !== '') params.append('categoria_id', categoryId);
            window.location.href = 'caja.php?' + params.toString();
        }

        function updateProductGrid(productos) {
            const productGrid = document.getElementById('productGrid');
            const mobileProductGrid = document.getElementById('mobileProductGrid');
            const emptyProductsMessage = document.getElementById('emptyProductsMessage');
            const mobileEmptyProductsMessage = document.getElementById('mobileEmptyProductsMessage');
            const productCount = document.getElementById('productCount');
            const mobileProductCount = document.getElementById('mobileProductCount');

            const count = productos ? productos.length : 0;
            if (productCount) {
                productCount.textContent = count + ' productos';
                productCount.className = count > 0 ? 'badge bg-primary ms-2' : 'badge bg-secondary ms-2';
            }
            if (mobileProductCount) {
                mobileProductCount.textContent = count;
                mobileProductCount.className = count > 0 ? 'badge bg-primary ms-2' : 'badge bg-secondary ms-2';
            }

            let productsHTML = '';
            if (productos && productos.length > 0) {
                productos.forEach(producto => {
                    let imagenHTML = '';
                    if (producto.imagen) {
                        imagenHTML = `
            <img src="${escapeHtml(producto.imagen)}" 
                 alt="${escapeHtml(producto.nombre)}"
                 class="product-image"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <div class="product-image-placeholder" style="display: none;">
                <i class="fas fa-box"></i>
            </div>
        `;
                    } else {
                        imagenHTML = `
            <div class="product-image-placeholder">
                <i class="fas fa-box"></i>
            </div>
        `;
                    }

                    const tiene_descuento = producto.descuento > 0;
                    const precio_con_descuento = producto.precio_sin_iva - (producto.precio_sin_iva * producto.descuento / 100);
                    
                    let tiene_mayoreo = false;
                    if (producto.precios_mayoreo && producto.precios_mayoreo.length > 0) {
                        tiene_mayoreo = true;
                    }

                    let stockDisplay = '';
                    const stockValue = parseFloat(producto.stock_sucursal) || 0;
                    const permiteFracciones = producto.permite_fracciones == 1;
                    const unidadMedida = (producto.unidad_medida || '').toLowerCase();

                    const unidadesDecimales = ['kg', 'kilo', 'kilogramo', 'kilogramos', 'g', 'gramo', 'gramos',
                        'l', 'litro', 'litros', 'ton', 'tonelada', 'toneladas',
                        'lb', 'libra', 'libras', 'ml', 'mililitro', 'mililitros'
                    ];

                    const mostrarDecimales = permiteFracciones || unidadesDecimales.includes(unidadMedida);

                    if (mostrarDecimales) {
                        stockDisplay = stockValue.toFixed(3);
                    } else {
                        stockDisplay = Math.floor(stockValue);
                    }

                    let stockClass = '';
                    let stockBadge = '';
                    if (stockValue <= 0) {
                        stockClass = 'stock-bajo';
                        stockBadge = '<span class="badge bg-danger mt-1">Sin Stock</span>';
                    } else if (stockValue <= 5) {
                        stockClass = 'stock-bajo';
                        stockBadge = '<span class="badge bg-warning mt-1">Stock Bajo</span>';
                    } else {
                        stockBadge = '';
                    }

                    productsHTML += `
        <div class="product-btn"
            onclick="agregarProducto(
                ${producto.id}, 
                '${producto.permite_fracciones}', 
                '${producto.unidad_medida.replace(/'/g, "\\'")}', 
                this)">
            <div class="product-image-container">
                ${imagenHTML}
            </div>
            <div class="product-name">${escapeHtml(producto.nombre)}</div>
            <div class="product-price-descuento">
                ${tiene_descuento ? `
                    <span class="precio-original">$${parseFloat(producto.precio_sin_iva).toFixed(2)}</span>
                    <span class="precio-con-descuento">$${parseFloat(precio_con_descuento).toFixed(2)}</span>
                    <span class="descuento-badge">-${parseFloat(producto.descuento).toFixed(0)}%</span>
                ` : `
                    <span class="product-price">$${parseFloat(producto.precio_sin_iva).toFixed(2)}</span>
                `}
            </div>
            ${tiene_mayoreo ? `
                <div class="mt-1">
                    <span class="badge mayoreo-badge">
                        <i class="fas fa-tags me-1"></i>Precios por Mayoreo
                    </span>
                </div>
            ` : ''}
            ${producto.permite_fracciones == 1 ? `
                <div class="unidad-medida">
                    <span class="badge tipo-venta-badge tipo-peso">
                        ${producto.unidad_medida.charAt(0).toUpperCase() + producto.unidad_medida.slice(1)}
                    </span>
                    por ${producto.unidad_medida}
                </div>
            ` : ''}
            <small class="text-muted d-block mt-2">
                <i class="fas fa-tag me-1"></i>${escapeHtml(producto.categoria_nombre)}
            </small>
            <small class="text-muted d-block mt-1">
                <i class="fas fa-store me-1"></i>Stock Sucursal:
                <span class="${stockClass}">${stockDisplay}</span>
                ${mostrarDecimales ? ` <span class="unidad-medida" style="font-size: 10px;">${producto.unidad_medida || ''}</span>` : ''}
            </small>
            <small class="text-muted d-block">
                Código: ${escapeHtml(producto.codigo)}
            </small>
            ${stockBadge}
        </div>
    `;
                });
            } else {
                productsHTML = `
        <div class="col-12 text-center py-4">
            <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
            <p class="text-muted">
                ${currentSearchTerm || currentCategory ? 
                    'No se encontraron productos con stock que coincidan con los filtros' : 
                    'No se encontraron productos con stock en esta sucursal'}
            </p>
        </div>
    `;
            }

            if (productGrid) productGrid.innerHTML = productsHTML;
            if (mobileProductGrid) mobileProductGrid.innerHTML = productsHTML;
            if (emptyProductsMessage) emptyProductsMessage.style.display = count > 0 ? 'none' : 'block';
            if (mobileEmptyProductsMessage) mobileEmptyProductsMessage.style.display = count > 0 ? 'none' : 'block';
        }

        function updateSearchResultsCount(count, searchTerm) {
            const resultsCount = document.getElementById('searchResultsCount');
            const mobileResultsCount = document.getElementById('mobileSearchResultsCount');
            let message = '';

            if (searchTerm && searchTerm.length > 0) {
                message = count === 0 ?
                    'No se encontraron productos' :
                    `Mostrando ${count} producto${count !== 1 ? 's' : ''} para "${searchTerm}"`;
            } else {
                message = count === 0 ?
                    'No hay productos con stock' :
                    `Mostrando ${count} producto${count !== 1 ? 's' : ''}`;
            }

            if (resultsCount) resultsCount.textContent = message;
            if (mobileResultsCount) mobileResultsCount.textContent = message;
        }

        function showSearchLoading(show) {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            if (show) {
                if (searchInput) searchInput.classList.add('search-loading');
                if (mobileSearchInput) mobileSearchInput.classList.add('search-loading');
            } else {
                if (searchInput) searchInput.classList.remove('search-loading');
                if (mobileSearchInput) mobileSearchInput.classList.remove('search-loading');
            }
        }

        function setupClearButtons() {
            const btnClearSearch = document.getElementById('btnClearSearch');
            const mobileBtnClearSearch = document.getElementById('mobileBtnClearSearch');

            if (btnClearSearch) {
                btnClearSearch.addEventListener('click', function() {
                    clearSearch();
                });
            }
            if (mobileBtnClearSearch) {
                mobileBtnClearSearch.addEventListener('click', function() {
                    clearSearch();
                });
            }
        }

        function clearSearch() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            if (mobileSearchInput) {
                mobileSearchInput.value = '';
                mobileSearchInput.focus();
            }

            currentSearchTerm = '';
            updateClearButton('', 'btnClearSearch');
            updateClearButton('', 'mobileBtnClearSearch');
            performRealTimeSearch('', currentCategory);
        }

        function updateClearButton(value, buttonId) {
            const button = document.getElementById(buttonId);
            if (button) {
                button.style.display = value && value.length > 0 ? 'flex' : 'none';
            }
        }

        function updateMobileClearButton(value, buttonId) {
            updateClearButton(value, buttonId);
        }

        // ========== FUNCIONES AUXILIARES ==========
        function isFormField(element) {
            if (!element) return false;
            const tagName = element.tagName;
            const type = element.type || '';
            if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') {
                return true;
            }
            if (element.isContentEditable) {
                return true;
            }
            if (element.hasAttribute('data-form-field')) {
                return true;
            }
            return false;
        }

        function isInModal(element) {
            if (!element) return false;
            let currentElement = element;
            while (currentElement) {
                if (currentElement.classList &&
                    (currentElement.classList.contains('modal') ||
                        currentElement.classList.contains('modal-dialog') ||
                        currentElement.classList.contains('modal-content'))) {
                    return true;
                }
                currentElement = currentElement.parentElement;
            }
            return false;
        }

        // ========== ESCÁNER GLOBAL DE CÓDIGO DE BARRAS ==========
        function setupGlobalBarcodeScanner() {
            document.addEventListener('keydown', function(e) {
                const specialKeys = [
                    'Shift', 'Control', 'Alt', 'Meta', 'CapsLock',
                    'Tab', 'Escape', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight',
                    'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12',
                    'ContextMenu', 'PrintScreen', 'ScrollLock', 'Pause', 'Insert', 'Home',
                    'PageUp', 'PageDown', 'Delete', 'End', 'NumLock'
                ];

                if (specialKeys.includes(e.key)) {
                    return;
                }

                const activeElement = document.activeElement;
                if (isFormField(activeElement) || isInModal(activeElement)) {
                    if (e.key === 'Enter') {
                        return;
                    }
                    return;
                }

                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    if (barcodeBuffer.length >= 3) {
                        processBarcodeAutomatically(barcodeBuffer);
                    }
                    barcodeBuffer = '';
                    clearTimeout(barcodeTimeout);
                    return;
                }

                if (!isFormField(activeElement) && !isInModal(activeElement)) {
                    barcodeBuffer += e.key;
                    clearTimeout(barcodeTimeout);
                    barcodeTimeout = setTimeout(() => {
                        if (barcodeBuffer.length >= 3) {
                            processBarcodeAutomatically(barcodeBuffer);
                        }
                        barcodeBuffer = '';
                    }, 100);
                }
            });

            document.addEventListener('input', function(e) {
                const target = e.target;
                if (isFormField(target)) {
                    return;
                }
                if (target.value && target.value.length >= 6) {
                    const currentTime = Date.now();
                    if (currentTime - lastAutoScanTime > 200) {
                        processBarcodeAutomatically(target.value);
                        target.value = '';
                    }
                }
            });

            document.addEventListener('shown.bs.modal', function() {
                barcodeBuffer = '';
                lastScannedCode = '';
            });

            document.addEventListener('hidden.bs.modal', function() {
                setTimeout(() => {
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput && !isInModal(searchInput)) {
                        searchInput.focus();
                    }
                }, 300);
            });
        }

        function processBarcodeAutomatically(code) {
            const currentTime = Date.now();
            const modalOpen = document.querySelector('.modal.show');
            if (modalOpen) {
                return;
            }

            const activeElement = document.activeElement;
            if (isFormField(activeElement)) {
                return;
            }

            if (code === lastScannedCode && currentTime - lastAutoScanTime < SCAN_DELAY) {
                return;
            }

            lastScannedCode = code;
            lastAutoScanTime = currentTime;

            showScanFeedback();
            buscarYAgregarProducto(code);
        }

        function buscarYAgregarProducto(codigo) {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');
            if (searchInput) searchInput.classList.add('search-loading');
            if (mobileSearchInput) mobileSearchInput.classList.add('search-loading');

            fetch('buscar_producto.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'codigo_barras=' + encodeURIComponent(codigo) + '&sucursal_id=' +window.CajaConfig.sucursalId
                })
                .then(response => {
                    if (!response.ok) throw new Error('Error en la respuesta del servidor');
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.producto) {
                        const producto = data.producto;
                        const unidadMedida = String(producto.unidad_medida).toLowerCase().trim();
                        const permiteFracciones = producto.permite_fracciones == 1;
                        const stockSucursal = producto.stock_sucursal || 0;

                        if (stockSucursal <= 0) {
                            mostrarErrorBusqueda('Producto sin stock: ' + producto.nombre);
                            return;
                        }

                        if (permiteFracciones && (unidadMedida === 'kg' || unidadMedida === 'litro' || unidadMedida === 'litros')) {
                            mostrarInfoBusqueda('Producto encontrado: ' + producto.nombre);
                            abrirModalCantidad(
                                producto.id,
                                producto.permite_fracciones,
                                producto.unidad_medida,
                                null
                            );
                        } else {
                            agregarProductoConCantidad(producto.id, 1, function(success, message) {
                                if (success) {
                                    mostrarExitoBusqueda(message);
                                } else {
                                    mostrarErrorBusqueda(message);
                                }
                            });
                        }
                    } else {
                        mostrarErrorBusqueda(data.message || 'Producto no encontrado');
                    }
                })
                .catch(error => {
                    console.error('❌ Error en la búsqueda:', error);
                    mostrarErrorBusqueda('Error al buscar el producto: ' + error.message);
                })
                .finally(() => {
                    if (searchInput) searchInput.classList.remove('search-loading');
                    if (mobileSearchInput) mobileSearchInput.classList.remove('search-loading');
                });
        }

        function showScanFeedback() {
            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');

            [searchInput, mobileSearchInput].forEach(input => {
                if (input) {
                    input.classList.add('auto-scanner-active');
                    setTimeout(() => input.classList.remove('auto-scanner-active'), 1000);
                }
            });

            const notification = document.createElement('div');
            notification.className = 'alert alert-info floating-notification';
            notification.innerHTML = '<i class="fas fa-barcode me-2"></i>Código detectado, buscando producto...';
            notification.style.zIndex = '9999';
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 2000);
        }

        // ========== FUNCIONES DE NOTIFICACIÓN ==========
        function mostrarExitoBusqueda(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-success floating-notification';
            notification.innerHTML = `<i class="fas fa-check-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        function mostrarErrorBusqueda(mensaje) {
            console.error('Error en búsqueda:', mensaje);
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function mostrarInfoBusqueda(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-info floating-notification';
            notification.innerHTML = `<i class="fas fa-info-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        function mostrarNotificacionExito(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-success floating-notification';
            notification.innerHTML = `<i class="fas fa-check-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function mostrarNotificacionError(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function mostrarNotificacionAdvertencia(mensaje) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-warning floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i>${mensaje}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }

        function showSearchError(message) {
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger floating-notification';
            notification.innerHTML = `<i class="fas fa-exclamation-circle me-2"></i>${message}`;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
        }

        // ========== AUTO-OCULTAMIENTO DE ALERTAS ==========
        function setupAutoHideAlerts() {
            const alerts = document.querySelectorAll('.auto-hide-alert');
            alerts.forEach(alert => {
                const hideTime = alert.getAttribute('data-auto-hide') || 2000;
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, parseInt(hideTime));
            });
        }

        function setupAlertClickToClose() {
            document.addEventListener('click', function() {
                const alerts = document.querySelectorAll('.auto-hide-alert');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            });
        }

        // ========== FUNCIONES AUXILIARES ==========
        function obtenerRutaImagen(imagen_producto) {
            if (!imagen_producto) return null;
            const rutas_posibles = [
                imagen_producto,
                '../' + imagen_producto,
                'img/productos/' + imagen_producto,
                'images/productos/' + imagen_producto,
                'uploads/productos/' + imagen_producto
            ];
            return rutas_posibles[0];
        }

        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // ========== MANEJO POST VENTA ==========
        function manejarPostVenta() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('venta_exitosa') === 'true') {
                mostrarNotificacionExito('¡Venta realizada exitosamente! Generando ticket...');
                const newUrl = window.location.pathname;
                window.history.replaceState({}, document.title, newUrl);
                setTimeout(function() {
                    manejarTicketPostVenta();
                }, 1000);
            }
        }

        function manejarTicketPostVenta() {
            const ventaData = window.CajaConfig.ventaRealizada;
            if (!ventaData) {
                console.error('No hay datos de venta');
                return;
            }
            if (esDispositivoMovil()) {
                abrirPDFEnMovil();
            } else {
                abrirTicketParaImpresion();
            }
        }

        function abrirTicketParaImpresion() {
            const ticketWindow = window.open('imprimir_ticket.php', 'ticket_venta',
                'width=400,height=700,left=100,top=100,toolbar=no,menubar=no,scrollbars=yes');
            if (ticketWindow) {
                const checkWindow = setInterval(function() {
                    if (ticketWindow.closed) {
                        clearInterval(checkWindow);
                    }
                }, 1000);
            } else {
                console.error('No se pudo abrir la ventana de ticket');
                mostrarNotificacionError('Error: No se pudo abrir el ticket. Por favor, active las ventanas emergentes.');
            }
        }

        function abrirPDFEnMovil() {
             const ventaId = window.CajaConfig.ventaId;
            const url = 'generar_pdf_ticket.php?venta_id=' + ventaId + '&t=' + new Date().getTime();
            const pdfWindow = window.open(url, '_blank');
            if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed == 'undefined') {
                mostrarPDFEnIframe(url);
            }
        }

        function mostrarPDFEnIframe(pdfUrl) {
            const overlay = document.createElement('div');
            overlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
`;

            const container = document.createElement('div');
            container.style.cssText = `
    background: white;
    border-radius: 10px;
    width: 95%;
    height: 90%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
`;

            const header = document.createElement('div');
            header.style.cssText = `
    padding: 15px;
    background: var(--primary-color);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
`;
            header.innerHTML = `
    <h4 style="margin: 0;">Ticket de Venta</h4>
    <button id="cerrarPdf" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">×</button>
`;

            const iframe = document.createElement('iframe');
            iframe.style.cssText = `
    width: 100%;
    height: 100%;
    border: none;
    flex: 1;
`;
            iframe.src = pdfUrl;

            container.appendChild(header);
            container.appendChild(iframe);
            overlay.appendChild(container);
            document.body.appendChild(overlay);

            document.getElementById('cerrarPdf').onclick = function() {
                document.body.removeChild(overlay);
            };

            overlay.onclick = function(e) {
                if (e.target === overlay) {
                    document.body.removeChild(overlay);
                }
            };
        }

        // ========== INICIALIZACIÓN PRINCIPAL ==========
        document.addEventListener('DOMContentLoaded', function() {
            setupAutoHideAlerts();
            setupAlertClickToClose();
            setupEditarDescuento();
            setupPreviewDescuento();
            setupEditarPrecio();

            const alertObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        setupAutoHideAlerts();
                    }
                });
            });
            alertObserver.observe(document.body, {
                childList: true,
                subtree: true
            });

            const carritoInicial = window.CajaConfig.carrito;
            const totalInicial = window.CajaConfig.totalInicial;
             const subtotalInicial = window.CajaConfig.subtotalInicial;
             const descuentoInicial = window.CajaConfig.descuentoInicial;
            const subtotalConDescuentoInicial = window.CajaConfig.subtotalConDescuentoInicial;
             const carritoCountInicial = window.CajaConfig.carritoCountInicial;

            const btnPagarModal = document.getElementById('modal-btnPagar');
            if (btnPagarModal && totalInicial > 0) {
                btnPagarModal.innerHTML = `
        <i class="fas fa-check-circle me-2"></i>
        CONFIRMAR PAGO - $${totalInicial.toFixed(2)}
    `;
            }

            initializeRealTimeSearch();
            setupGlobalBarcodeScanner();
            setupPaymentMethods();
            setupEfectivoInput();
            setupDescripcionInput();
            setupNumpad();
            setupQRActions();
            setupLinkPagoEvents();
            setupClienteModal();
            setupDynamicQuantityUpdates();
            setupEliminarProducto();
            setupVaciarCarrito();
            setupClienteAjax();

            const mobileTabs = document.querySelectorAll('.mobile-tab');
            const mobileContents = document.querySelectorAll('.mobile-content');

            mobileTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');
                    mobileTabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    mobileContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === 'mobile-' + targetTab) {
                            content.classList.add('active');
                        }
                    });
                });
            });

            const searchInput = document.getElementById('searchInput');
            const mobileSearchInput = document.getElementById('mobileSearchInput');
            if (searchInput) searchInput.addEventListener('focus', function() {
                this.select();
            });
            if (mobileSearchInput) mobileSearchInput.addEventListener('focus', function() {
                this.select();
            });

            document.querySelectorAll('tr').forEach(row => {
                if (row.textContent.includes('IVA')) row.style.display = 'none';
            });

            const formPagoModal = document.getElementById('formPagoModal');
            if (formPagoModal) {
                formPagoModal.addEventListener('submit', function(e) {
                    const carritoActual = window.currentCarrito || [];
                    const totalElement = document.getElementById('total-display');
                    const totalText = totalElement ? totalElement.textContent.replace('$', '') : '0.00';
                    const total = parseFloat(totalText) || 0;
                    const metodoPago = document.getElementById('modal-metodoPagoInput').value;
                    const efectivoRecibido = parseFloat(document.getElementById('modal-efectivoRecibidoHidden').value);

                    if (carritoActual.length === 0 || total <= 0) {
                        e.preventDefault();
                        mostrarNotificacionError('El carrito está vacío');
                        return false;
                    }

                    if (metodoPago === 'efectivo' && efectivoRecibido < total) {
                        e.preventDefault();
                        alert('El efectivo recibido es menor al total a pagar');
                        return false;
                    }

                    if (!confirm('¿Está seguro de procesar la venta?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            const btnAbrirModalPago = document.getElementById('btnAbrirModalPago');
            const mobileBtnAbrirModalPago = document.getElementById('mobile-btnAbrirModalPago');

            if (btnAbrirModalPago) {
                btnAbrirModalPago.addEventListener('click', function(e) {
                    e.preventDefault();
                    abrirModalPago();
                });
            }
            if (mobileBtnAbrirModalPago) {
                mobileBtnAbrirModalPago.addEventListener('click', function(e) {
                    e.preventDefault();
                    abrirModalPago();
                });
            }

            const btnAgregarConCantidad = document.getElementById('btnAgregarConCantidad');
            if (btnAgregarConCantidad) {
                const newBtn = btnAgregarConCantidad.cloneNode(true);
                btnAgregarConCantidad.parentNode.replaceChild(newBtn, btnAgregarConCantidad);

                newBtn.addEventListener('click', function() {
                    const productoId = document.getElementById('productoIdModal').value;
                    const cantidad = document.getElementById('cantidadInput').value;
                    const modal = bootstrap.Modal.getInstance(document.getElementById('cantidadModal'));

                    if (!productoId) {
                        console.error('No hay ID de producto');
                        return;
                    }

                    if (!cantidad || parseFloat(cantidad) <= 0) {
                        mostrarNotificacionError('Por favor ingrese una cantidad válida');
                        return;
                    }

                    const element = this.dataset.element === 'true' ?
                        document.querySelector(`.product-btn[onclick*="${productoId}"]`) : null;

                    const callback = (success, message) => {
                        if (success) {
                            if (element) {
                                mostrarFeedbackExitoAgregar(element);
                            }
                            modal.hide();
                            mostrarNotificacionExito(message || 'Producto agregado al carrito');
                        } else {
                            mostrarNotificacionError(message || 'Error al agregar el producto');
                        }
                    };

                    agregarProductoConCantidad(productoId, cantidad, callback);
                });
            }

            manejarPostVenta();

             updateClearButton(window.CajaConfig.busquedaNombre, 'btnClearSearch');
            updateClearButton(window.CajaConfig.busquedaNombre, 'mobileBtnClearSearch');

            document.querySelectorAll('#clienteModal input, #clienteModal textarea, #clienteModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });
            document.querySelectorAll('#cantidadModal input, #cantidadModal textarea, #cantidadModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });
            document.querySelectorAll('#pagoModal input, #pagoModal textarea, #pagoModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });
            document.querySelectorAll('#editarPrecioModal input, #editarPrecioModal textarea, #editarPrecioModal select').forEach(field => {
                field.setAttribute('data-form-field', 'true');
            });

            const qrSection = document.getElementById('qrSection');
            if (qrSection) qrSection.style.display = 'none';

            const efectivoSection = document.querySelector('.efectivo-section');
            if (efectivoSection) efectivoSection.style.display = 'block';

            const speiSection = document.getElementById('speiSection');
            if (speiSection) speiSection.style.display = 'none';

            const qrLinkSection = document.getElementById('qrLinkSection');
            if (qrLinkSection) qrLinkSection.style.display = 'none';

            const btnGuardarDescuento = document.getElementById('btnGuardarDescuento');
            if (btnGuardarDescuento) {
                btnGuardarDescuento.addEventListener('click', function(e) {
                    e.preventDefault();
                    guardarDescuentoProducto();
                });
            }

            const inputDescuento = document.getElementById('porcentajeDescuento');
            if (inputDescuento) {
                inputDescuento.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        guardarDescuentoProducto();
                    }
                });
            }

            const btnGuardarPrecio = document.getElementById('btnGuardarPrecio');
            if (btnGuardarPrecio) {
                btnGuardarPrecio.addEventListener('click', function(e) {
                    e.preventDefault();
                    guardarPrecioProducto();
                });
            }

            const inputNuevoPrecio = document.getElementById('nuevoPrecio');
            if (inputNuevoPrecio) {
                inputNuevoPrecio.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        guardarPrecioProducto();
                    }
                });
            }
        });