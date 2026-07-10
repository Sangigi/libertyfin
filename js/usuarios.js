class UserManagementSystem {
    constructor() {
        this.init();
    }

    init() {
        this.bindEvents();
        this.setupSearch();
    }

    bindEvents() {
        // Editar usuario
        document.querySelectorAll('.edit-user').forEach(btn => {
            btn.addEventListener('click', (e) => this.editUser(e));
        });

        // Resetear formulario al abrir modal para nuevo usuario
        document.getElementById('userModal').addEventListener('show.bs.modal', (e) => {
            if (!e.relatedTarget) {
                this.resetForm();
            }
        });

        // Validación de formulario
        document.getElementById('userForm').addEventListener('submit', (e) => this.validateForm(e));
    }

    editUser(event) {
        const userData = JSON.parse(event.currentTarget.getAttribute('data-user'));
        
        document.getElementById('modalTitle').textContent = 'Editar Usuario';
        document.getElementById('formAction').value = 'editar';
        document.getElementById('userId').value = userData.id;
        document.getElementById('username').value = userData.username;
        document.getElementById('nombre').value = userData.nombre;
        document.getElementById('email').value = userData.email;
        document.getElementById('rol').value = userData.rol;
        document.getElementById('sucursal_id').value = userData.sucursal_id;
        
        // Hacer el campo de contraseña opcional en edición
        document.getElementById('password').required = false;
        document.getElementById('password').placeholder = 'Dejar en blanco para no cambiar';
        
        const modal = new bootstrap.Modal(document.getElementById('userModal'));
        modal.show();
    }

    resetForm() {
        document.getElementById('userForm').reset();
        document.getElementById('modalTitle').textContent = 'Nuevo Usuario';
        document.getElementById('formAction').value = 'crear';
        document.getElementById('userId').value = '';
        document.getElementById('password').required = true;
        document.getElementById('password').placeholder = '';
    }

    validateForm(event) {
        const form = event.target;
        const password = document.getElementById('password').value;
        const action = document.getElementById('formAction').value;

        // Validar longitud de contraseña solo para nuevos usuarios
        if (action === 'crear' && password.length < 6) {
            event.preventDefault();
            alert('La contraseña debe tener al menos 6 caracteres');
            return;
        }

        // Validar usuario único (simulación)
        const username = document.getElementById('username').value;
        if (!this.validateUsername(username)) {
            event.preventDefault();
            alert('El nombre de usuario solo puede contener letras, números y guiones bajos');
            return;
        }
    }

    validateUsername(username) {
        return /^[a-zA-Z0-9_]+$/.test(username);
    }

    setupSearch() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('usersTable');
        
        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let row of rows) {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            }
        });
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    new UserManagementSystem();
});