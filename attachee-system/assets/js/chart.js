// Chart configurations and functions
function createBarChart(ctx, labels, data, label, backgroundColor, borderColor) {
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: label,
                data: data,
                backgroundColor: backgroundColor,
                borderColor: borderColor,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

function createPieChart(ctx, labels, data, backgroundColor) {
    return new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: backgroundColor,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true
        }
    });
}

// Department specific charts
function initDepartmentCharts() {
    const deptCtx = document.getElementById('departmentChart');
    const statusCtx = document.getElementById('statusChart');
    
    if(deptCtx) {
        // Example data - in real app this would come from PHP
        const deptLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May'];
        const deptData = [12, 19, 3, 5, 2];
        
        createBarChart(
            deptCtx, 
            deptLabels, 
            deptData, 
            'Attachees', 
            'rgba(54, 162, 235, 0.7)', 
            'rgba(54, 162, 235, 1)'
        );
    }
    
    if(statusCtx) {
        createPieChart(
            statusCtx,
            ['Active', 'Completed'],
            [15, 8],
            ['#28a745', '#ffc107']
        );
    }
}

document.addEventListener('DOMContentLoaded', initDepartmentCharts);