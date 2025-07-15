// General scripts for the application
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Confirm before delete
    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            if(!confirm('Are you sure you want to delete this record?')) {
                e.preventDefault();
            }
        });
    });
    
    // Date validation for attachee forms
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    if(startDateInput && endDateInput) {
        startDateInput.addEventListener('change', function() {
            endDateInput.min = this.value;
        });
        
        endDateInput.addEventListener('change', function() {
            if(this.value < startDateInput.value) {
                alert('End date cannot be before start date');
                this.value = '';
            }
        });
    }
});