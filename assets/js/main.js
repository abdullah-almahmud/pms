// /assets/js/main.js

document.addEventListener('DOMContentLoaded', () => {
    // Confirm Dialogs for Destructive Actions
    const confirmForms = document.querySelectorAll('form[data-confirm]');
    confirmForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const message = this.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    const confirmLinks = document.querySelectorAll('a[data-confirm]');
    confirmLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // Basic Form Validation Feedback
    const requiredInputs = document.querySelectorAll('input[required], select[required], textarea[required]');
    requiredInputs.forEach(input => {
        input.addEventListener('invalid', function(e) {
            this.classList.add('is-invalid');
        });
        
        input.addEventListener('input', function(e) {
            if (this.validity.valid) {
                this.classList.remove('is-invalid');
            }
        });
    });
});
