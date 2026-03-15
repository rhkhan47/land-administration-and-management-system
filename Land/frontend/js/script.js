// Wait for the DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Tab functionality for user dashboard
    initializeTabs();
    
    // Form validation enhancements
    enhanceForms();
    
    // Auto-dismiss alerts after 5 seconds
    autoDismissAlerts();
    
    // Confirmations for important actions
    setupConfirmations();
});

// Initialize tab functionality
function initializeTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');
    
    if (tabButtons.length > 0 && tabContents.length > 0) {
        tabButtons.forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.getAttribute('onclick').match(/'([^']+)'/)[1];
                
                // Remove active class from all buttons and contents
                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));
                
                // Add active class to current button and content
                this.classList.add('active');
                document.getElementById(tabName).classList.add('active');
            });
        });
    }
}

// Enhance form validation
function enhanceForms() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            let valid = true;
            const requiredFields = this.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    highlightError(field, 'This field is required');
                } else {
                    clearError(field);
                    
                    // Additional validation based on field type
                    if (field.type === 'email' && !isValidEmail(field.value)) {
                        valid = false;
                        highlightError(field, 'Please enter a valid email address');
                    }
                    
                    if (field.type === 'number' && field.value < 0) {
                        valid = false;
                        highlightError(field, 'Please enter a positive value');
                    }
                }
            });
            
            if (!valid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = this.querySelector('.has-error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    });
    
    // Add input event listeners to clear errors when typing
    const inputs = document.querySelectorAll('input');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            clearError(this);
        });
    });
}

// Highlight field with error
function highlightError(field, message) {
    const formGroup = field.closest('.form-group');
    if (!formGroup) return;
    
    formGroup.classList.add('has-error');
    
    // Add error message if it doesn't exist
    if (!formGroup.querySelector('.help-block')) {
        const errorElement = document.createElement('span');
        errorElement.className = 'help-block';
        errorElement.textContent = message;
        formGroup.appendChild(errorElement);
    } else {
        formGroup.querySelector('.help-block').textContent = message;
    }
}

// Clear error highlighting
function clearError(field) {
    const formGroup = field.closest('.form-group');
    if (!formGroup) return;
    
    formGroup.classList.remove('has-error');
    const errorElement = formGroup.querySelector('.help-block');
    if (errorElement) {
        errorElement.remove();
    }
}

// Validate email format
function isValidEmail(email) {
    const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(String(email).toLowerCase());
}

// Auto-dismiss alerts after 5 seconds
function autoDismissAlerts() {
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
}

// Setup confirmation dialogs for important actions
function setupConfirmations() {
    const confirmableButtons = document.querySelectorAll('[data-confirm]');
    
    confirmableButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
}

// Utility function to format numbers as currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-BD', {
        style: 'currency',
        currency: 'BDT',
        minimumFractionDigits: 2
    }).format(amount);
}

// Utility function to copy text to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Copied to clipboard: ' + text);
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}

// Add event listeners for copy buttons
document.addEventListener('DOMContentLoaded', function() {
    const copyButtons = document.querySelectorAll('.copy-btn');
    
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.getAttribute('data-copy');
            copyToClipboard(textToCopy);
        });
    });
});