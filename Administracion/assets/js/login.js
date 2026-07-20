// =============================================
// JAVASCRIPT ESPECÍFICO DEL LOGIN
// =============================================

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // =============================================
        // ELEMENTOS DEL DOM
        // =============================================
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const submitSpinner = document.getElementById('submitSpinner');
        const passwordInput = document.getElementById('password');
        const togglePasswordBtn = document.getElementById('togglePassword');
        const emailInput = document.getElementById('email');
        
        // =============================================
        // MOSTRAR/OCULTAR CONTRASEÑA
        // =============================================
        if (togglePasswordBtn) {
            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
        }
        
        // =============================================
        // FUNCIONES DE VALIDACIÓN
        // =============================================
        
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        function validateForm() {
            let isValid = true;
            
            // Limpiar validaciones anteriores
            const inputs = form.querySelectorAll('input');
            inputs.forEach(input => {
                input.classList.remove('is-invalid', 'is-valid');
            });
            
            // Validar email
            if (!emailInput.value.trim()) {
                emailInput.classList.add('is-invalid');
                isValid = false;
            } else if (!validateEmail(emailInput.value.trim())) {
                emailInput.classList.add('is-invalid');
                isValid = false;
            } else {
                emailInput.classList.add('is-valid');
            }
            
            // Validar contraseña
            if (!passwordInput.value.trim()) {
                passwordInput.classList.add('is-invalid');
                isValid = false;
            } else {
                passwordInput.classList.add('is-valid');
            }
            
            return isValid;
        }
        
        // =============================================
        // VALIDACIÓN EN TIEMPO REAL
        // =============================================
        
        // Validar email en tiempo real
        if (emailInput) {
            emailInput.addEventListener('input', function() {
                if (this.value.trim()) {
                    if (validateEmail(this.value.trim())) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                    }
                } else {
                    this.classList.remove('is-invalid', 'is-valid');
                }
            });
            
            emailInput.addEventListener('blur', function() {
                if (this.value.trim() && !validateEmail(this.value.trim())) {
                    this.classList.add('is-invalid');
                }
            });
        }
        
        // Validar contraseña en tiempo real
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                }
            });
        }
        
        // =============================================
        // PERMITIR ENVIAR CON ENTER
        // =============================================
        if (form) {
            form.querySelectorAll('input').forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        form.dispatchEvent(new Event('submit'));
                    }
                });
            });
        }
        
        // =============================================
        // MANEJAR ENVÍO DEL FORMULARIO
        // =============================================
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (validateForm()) {
                    // Mostrar spinner
                    submitText.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Iniciando sesión...';
                    submitSpinner.classList.remove('d-none');
                    submitBtn.disabled = true;
                    
                    // Enviar formulario
                    form.submit();
                } else {
                    // Mostrar el primer error
                    const firstInvalid = document.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({ 
                            behavior: 'smooth', 
                            block: 'center' 
                        });
                        firstInvalid.focus();
                    }
                    
                    // Feedback adicional
                    const invalidInputs = document.querySelectorAll('.is-invalid');
                    if (invalidInputs.length > 0) {
                        // Pequeña animación de sacudida en el primer error
                        firstInvalid.style.animation = 'shake 0.5s ease';
                        setTimeout(() => {
                            firstInvalid.style.animation = '';
                        }, 500);
                    }
                }
            });
        }
        
        // =============================================
        // AUTO-FOCUS
        // =============================================
        if (emailInput && !emailInput.value) {
            emailInput.focus();
        } else if (passwordInput) {
            passwordInput.focus();
        }
        
        // =============================================
        // MEJORAR EXPERIENCIA EN MÓVILES
        // =============================================
        if (window.innerWidth <= 768) {
            form.querySelectorAll('input').forEach(element => {
                element.addEventListener('focus', function() {
                    setTimeout(() => {
                        this.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                });
            });
        }
        
        // =============================================
        // LIMPIAR MENSAJES DE ERROR AL HACER CLICK
        // =============================================
        document.querySelectorAll('.alert .btn-close').forEach(btn => {
            btn.addEventListener('click', function() {
                const alert = this.closest('.alert');
                if (alert) {
                    alert.style.transition = 'opacity 0.3s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }
            });
        });
        
        // =============================================
        // AGREGAR ANIMACIÓN DE SACUDIDA
        // =============================================
        const style = document.createElement('style');
        style.textContent = `
            @keyframes shake {
                0%, 100% { transform: translateX(0); }
                10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
                20%, 40%, 60%, 80% { transform: translateX(5px); }
            }
        `;
        document.head.appendChild(style);
        
        console.log('Login JS cargado correctamente');
    });

})();