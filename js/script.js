class RegistrationSystem {
    constructor() {
        this.currentStep = 1;
        this.totalSteps = 3;
        this.formData = new FormData();
        this.init();
    }

    init() {
        this.bindEvents();
        this.updateProgress();
    }

    bindEvents() {
        // Navegación entre pasos
        document.querySelectorAll('.btn-next').forEach(btn => {
            btn.addEventListener('click', (e) => this.nextStep(e));
        });

        document.querySelectorAll('.btn-prev').forEach(btn => {
            btn.addEventListener('click', () => this.prevStep());
        });

        // Validación en tiempo real
        document.getElementById('empresaNombre').addEventListener('blur', () => this.validateField('empresaNombre'));
        document.getElementById('empresaRuc').addEventListener('blur', () => this.validateField('empresaRuc'));
        document.getElementById('adminEmail').addEventListener('blur', () => this.validateField('adminEmail'));
        document.getElementById('adminPassword').addEventListener('input', () => this.checkPasswordStrength());

        // Preview de logo
        document.getElementById('companyLogo').addEventListener('change', (e) => this.previewLogo(e));

        // Envío del formulario
        document.getElementById('registrationForm').addEventListener('submit', (e) => this.submitForm(e));
    }

    validateField(fieldId) {
        const field = document.getElementById(fieldId);
        const value = field.value.trim();
        let isValid = true;
        let message = '';

        switch(fieldId) {
            case 'empresaNombre':
                if (value.length < 3) {
                    isValid = false;
                    message = 'El nombre debe tener al menos 3 caracteres';
                }
                break;
            case 'empresaRuc':
                if (!/^[0-9]{7,15}$/.test(value)) {
                    isValid = false;
                    message = 'RUC debe contener solo números (7-15 dígitos)';
                }
                break;
            case 'adminEmail':
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    isValid = false;
                    message = 'Ingrese un email válido';
                }
                break;
        }

        this.showFieldValidation(field, isValid, message);
        return isValid;
    }

    showFieldValidation(field, isValid, message) {
        field.classList.remove('is-valid', 'is-invalid');
        field.classList.add(isValid ? 'is-valid' : 'is-invalid');

        let feedback = field.parentNode.querySelector('.invalid-feedback, .valid-feedback');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = isValid ? 'valid-feedback' : 'invalid-feedback';
            field.parentNode.appendChild(feedback);
        }
        feedback.textContent = message || (isValid ? '✓ Válido' : '');
    }

    checkPasswordStrength() {
        const password = document.getElementById('adminPassword').value;
        const strengthBar = document.getElementById('passwordStrength');
        const strengthText = document.getElementById('passwordStrengthText');

        let strength = 0;
        let text = '';
        let className = '';

        if (password.length >= 8) strength++;
        if (password.match(/[a-z]+/)) strength++;
        if (password.match(/[A-Z]+/)) strength++;
        if (password.match(/[0-9]+/)) strength++;
        if (password.match(/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]+/)) strength++;

        switch(strength) {
            case 0:
            case 1:
                text = 'Débil';
                className = 'strength-weak';
                break;
            case 2:
                text = 'Regular';
                className = 'strength-fair';
                break;
            case 3:
                text = 'Buena';
                className = 'strength-good';
                break;
            case 4:
            case 5:
                text = 'Fuerte';
                className = 'strength-strong';
                break;
        }

        strengthBar.className = `password-strength ${className}`;
        strengthText.textContent = text;
    }

    previewLogo(event) {
        const file = event.target.files[0];
        const preview = document.getElementById('logoPreview');
        
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" alt="Vista previa del logo">`;
            }
            reader.readAsDataURL(file);
        }
    }

    validateStep(step) {
        let isValid = true;

        switch(step) {
            case 1:
                isValid = this.validateField('empresaNombre') && 
                         this.validateField('empresaRuc');
                break;
            case 2:
                isValid = this.validateField('adminNombre') && 
                         this.validateField('adminEmail') && 
                         this.validateField('adminPassword');
                break;
        }

        return isValid;
    }

    nextStep(event) {
        event.preventDefault();
        
        if (this.validateStep(this.currentStep)) {
            this.currentStep++;
            this.updateProgress();
        }
    }

    prevStep() {
        this.currentStep--;
        this.updateProgress();
    }

    updateProgress() {
        // Actualizar indicadores de pasos
        document.querySelectorAll('.step').forEach((step, index) => {
            step.classList.remove('active', 'completed');
            if (index + 1 < this.currentStep) {
                step.classList.add('completed');
            } else if (index + 1 === this.currentStep) {
                step.classList.add('active');
            }
        });

        // Mostrar/ocultar contenido de pasos
        document.querySelectorAll('.step-content').forEach((content, index) => {
            content.classList.remove('active');
            if (index + 1 === this.currentStep) {
                content.classList.add('active');
            }
        });

        // Mostrar/ocultar botones
        document.getElementById('prevButtons').style.display = this.currentStep > 1 ? 'block' : 'none';
        document.getElementById('nextButtons').style.display = this.currentStep < this.totalSteps ? 'block' : 'none';
        document.getElementById('submitButton').style.display = this.currentStep === this.totalSteps ? 'block' : 'none';
    }

    async submitForm(event) {
        event.preventDefault();
        
        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Registrando...';
        submitBtn.disabled = true;

        try {
            const formData = new FormData(document.getElementById('registrationForm'));
            
            const response = await fetch('registrar.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                this.showSuccess(result.message);
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 3000);
            } else {
                this.showError(result.message);
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }

        } catch (error) {
            this.showError('Error de conexión: ' + error.message);
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }

    showSuccess(message) {
        this.showAlert(message, 'success');
    }

    showError(message) {
        this.showAlert(message, 'danger');
    }

    showAlert(message, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.querySelector('.registration-body').prepend(alertDiv);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    new RegistrationSystem();
});