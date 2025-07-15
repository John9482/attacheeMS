<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/helpers.php';

$auth = new Auth();

if(!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Get selected financial year from request or use current
$selectedFY = $_GET['financial_year'] ?? getCurrentFinancialYear();
if (!isValidFinancialYear($selectedFY)) {
    $selectedFY = getCurrentFinancialYear();
}

// Get selected quarter (1-4 or 'all')
$selectedQuarter = $_GET['quarter'] ?? 'all';
if (!in_array($selectedQuarter, ['1', '2', '3', '4', 'all'])) {
    $selectedQuarter = 'all';
}

// Get date range for selected financial year and quarter
try {
    $fyDates = getFinancialYearDates($selectedFY);
    $quarterDates = getQuarterDates($selectedFY, $selectedQuarter);
} catch (InvalidArgumentException $e) {
    $selectedFY = getCurrentFinancialYear();
    $fyDates = getFinancialYearDates($selectedFY);
    $quarterDates = getQuarterDates($selectedFY, $selectedQuarter);
}

// Determine the actual date range to use based on quarter selection
$dateRange = ($selectedQuarter === 'all') ? $fyDates : $quarterDates;

// Error handling for database queries
try {
    // Get total attachees for the selected period
    $total_attachees = $conn->prepare("
        SELECT COUNT(*) FROM attachees 
        WHERE created_at BETWEEN :start_date AND :end_date
    ");
    $total_attachees->execute([':start_date' => $dateRange['start'], ':end_date' => $dateRange['end']]);
    $total_attachees = $total_attachees->fetchColumn();

    // Get active attachees for the selected period
    $active_attachees = $conn->prepare("
        SELECT COUNT(*) FROM attachees 
        WHERE status = 'Active' 
        AND created_at BETWEEN :start_date AND :end_date
    ");
    $active_attachees->execute([':start_date' => $dateRange['start'], ':end_date' => $dateRange['end']]);
    $active_attachees = $active_attachees->fetchColumn();

    // Get completed attachees for the selected period
    $completed_attachees = $conn->prepare("
        SELECT COUNT(*) FROM attachees 
        WHERE status = 'Completed' 
        AND end_date BETWEEN :start_date AND :end_date
    ");
    $completed_attachees->execute([':start_date' => $dateRange['start'], ':end_date' => $dateRange['end']]);
    $completed_attachees = $completed_attachees->fetchColumn();

    // Get departments with detailed counts for the selected period
    $departments = $conn->prepare("
        SELECT 
            d.id, 
            d.name, 
            COALESCE(d.max_capacity, 10) as max_capacity,
            COUNT(a.id) as total_attachees,
            SUM(CASE WHEN a.status = 'Active' THEN 1 ELSE 0 END) as active_attachees,
            SUM(CASE WHEN a.status = 'Completed' THEN 1 ELSE 0 END) as completed_attachees
        FROM departments d 
        LEFT JOIN attachees a ON d.id = a.department_id 
            AND a.created_at BETWEEN :start_date AND :end_date
        GROUP BY d.id, d.name, d.max_capacity
        ORDER BY 
            CASE 
                WHEN d.name = 'FSRP' THEN 1
                ELSE 2
            END,
            d.name
    ");
    $departments->execute([':start_date' => $dateRange['start'], ':end_date' => $dateRange['end']]);
    $departments = $departments->fetchAll(PDO::FETCH_ASSOC);

    // Get recent attachees for the selected period
    $recent_attachees = $conn->prepare("
        SELECT a.*, d.name as department_name 
        FROM attachees a 
        JOIN departments d ON a.department_id = d.id 
        WHERE a.created_at BETWEEN :start_date AND :end_date
        ORDER BY a.created_at DESC 
        LIMIT 5
    ");
    $recent_attachees->execute([':start_date' => $dateRange['start'], ':end_date' => $dateRange['end']]);
    $recent_attachees = $recent_attachees->fetchAll(PDO::FETCH_ASSOC);

    // Get quarterly department data for heatmap
    $quarterly_department_data = [];
    $all_departments = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($all_departments as $dept) {
        $dept_data = [
            'id' => $dept['id'],
            'name' => $dept['name'],
            'quarters' => [],
            'total' => 0
        ];
        
        for ($q = 1; $q <= 4; $q++) {
            $qDates = getQuarterDates($selectedFY, $q);
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM attachees 
                WHERE department_id = :dept_id 
                AND created_at BETWEEN :start_date AND :end_date
            ");
            $stmt->execute([
                ':dept_id' => $dept['id'],
                ':start_date' => $qDates['start'],
                ':end_date' => $qDates['end']
            ]);
            $count = $stmt->fetchColumn();
            $dept_data['quarters'][$q] = $count;
            $dept_data['total'] += $count;
        }
        
        $quarterly_department_data[] = $dept_data;
    }

    // Check for departments nearing capacity
    $capacity_warnings = array_filter($departments, function($dept) {
        return $dept['max_capacity'] > 0 && ($dept['active_attachees'] / $dept['max_capacity']) >= 0.8;
    });

    // Get quarterly breakdown data for the selected FY
    $quarterly_data = [];
    for ($q = 1; $q <= 4; $q++) {
        $qDates = getQuarterDates($selectedFY, $q);
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM attachees 
            WHERE created_at BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([':start_date' => $qDates['start'], ':end_date' => $qDates['end']]);
        $quarterly_data[$q] = $stmt->fetchColumn();
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

include 'includes/header.php';
?>

<style>
    :root {
        --primary-color: #4e73df;
        --secondary-color: #1cc88a;
        --warning-color: #f6c23e;
        --danger-color: #e74a3b;
        --info-color: #36b9cc;
        --fsrp-color: #6f42c1;
        --dark-color: #5a5c69;
        --light-color: #f8f9fc;
    }
    
    body {
    background: linear-gradient(
        145deg,
rgb(60, 248, 43) 1%, 
rgba(22, 208, 214, 0.71) 51%,        /* Light sky blue (middle) */
rgb(49, 223, 127) 70%       /* Creamy off-white (bottom) */
    );
    color: #2a3439;        /* Light black (dark gray with blue undertone) */
    font-family: 'Segoe UI', 'Open Sans', Roboto, sans-serif;
    line-height: 1.6;
    min-height: 100vh;     /* Ensures gradient covers full screen */
}
    
    .card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border-radius: 0.35rem;
        border: none;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        margin-bottom: 1.5rem;
    }
    
    .card:hover {
        transform: translateY(-5px);
        box-shadow: 0 0.5rem 1.5rem 0.5rem rgba(58, 59, 69, 0.2);
    }
    
    .card-header {
        background-color:rgb(252, 248, 250);
        border-bottom: 1px solid #e3e6f0;
        padding: 1rem 1.35rem;
        font-weight: 600;
    }
    
    .department-card {
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .department-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 0.5rem 1.5rem 0.5rem rgba(58, 59, 69, 0.15);
    }
    
    .progress {
        height: 0.75rem;
        border-radius: 0.35rem;
        background-color: #eaecf4;
    }
    
    .progress-bar {
        background-color: var(--primary-color);
    }
    
    .badge {
        font-size: 0.75em;
        font-weight: 600;
        padding: 0.35em 0.65em;
        border-radius: 0.25rem;
    }
    
    .status-badge {
        min-width: 80px;
        display: inline-block;
        text-align: center;
    }
    
    .financial-year-selector {
        background-color: white;
        padding: 1rem;
        border-radius: 0.35rem;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
        margin-bottom: 1.5rem;
    }
    
    .fy-carousel {
        display: flex;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        margin: 1rem 0;
        padding-bottom: 1rem;
    }
    
    .fy-carousel::-webkit-scrollbar {
        display: none;
    }
    
    .fy-item {
        flex: 0 0 auto;
        scroll-snap-align: start;
        margin-right: 1rem;
        text-align: center;
        min-width: 120px;
    }
    
    .fy-btn {
        display: block;
        padding: 0.75rem 1rem;
        border-radius: 0.35rem;
        background-color: white;
        border: 1px solid #e3e6f0;
        color: var(--dark-color);
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .fy-btn:hover {
        background-color: #f8f9fc;
        border-color: #d1d3e2;
    }
    
    .fy-btn.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    .quarter-selector {
        display: flex;
        justify-content: center;
        margin-top: 1rem;
    }
    
    .quarter-btn {
        padding: 0.5rem 1rem;
        margin: 0 0.25rem;
        border-radius: 0.25rem;
        background-color: white;
        border: 1px solid #e3e6f0;
        color: var(--dark-color);
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .quarter-btn:hover {
        background-color: #f8f9fc;
    }
    
    .quarter-btn.active {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    .summary-card {
        border-left: 0.25rem solid;
        height: 100%;
    }
    
    .summary-card .card-body {
        padding: 1.25rem;
    }
    
    .summary-card .card-title {
        font-size: 0.9rem;
        font-weight: 600;
        text-transform: uppercase;
        color: #b7b9cc;
        margin-bottom: 0.5rem;
    }
    
    .summary-card .card-text {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--dark-color);
    }
    
    .summary-card.primary {
        border-left-color: var(--primary-color);
    }
    
    .summary-card.success {
        border-left-color: var(--secondary-color);
    }
    
    .summary-card.warning {
        border-left-color: var(--warning-color);
    }
    
    .summary-card.info {
        border-left-color: var(--info-color);
    }
    
    .fsrp-card {
        border-left: 0.25rem solid var(--fsrp-color);
    }
    
    .fsrp-badge {
        background-color: var(--fsrp-color);
    }
    
    .heatmap-cell {
        transition: all 0.3s ease;
        border-radius: 0.25rem;
        text-align: center;
        padding: 0.5rem;
        font-weight: 600;
        cursor: pointer;
    }
    
    .heatmap-cell:hover {
        transform: scale(1.05);
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }
    
    .heatmap-header {
        font-weight: 700;
        text-align: center;
        padding: 0.5rem;
        background-color: #f8f9fc;
    }
    
    .heatmap-dept-name {
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .dashboard-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }
    
    .dashboard-title h2 {
        font-weight: 700;
        color: var(--dark-color);
        margin: 0;
    }
    
    .chart-area {
        position: relative;
        height: 250px;
        width: 100%;
    }
    
    .department-summary-card {
        transition: all 0.3s ease;
    }
    
    .department-summary-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 0.5rem 1.5rem 0.5rem rgba(58, 59, 69, 0.15);
    }
    
    .department-stats {
        display: flex;
        justify-content: space-between;
        margin-top: 1rem;
    }
    
    .stat-item {
        text-align: center;
        flex: 1;
    }
    
    .stat-value {
        font-size: 1.2rem;
        font-weight: 700;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: #6c757d;
    }
    
    @media (max-width: 768px) {
        .dashboard-title {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .financial-year-selector {
            width: 100%;
            margin-top: 1rem;
        }
        
        .quarter-selector {
            flex-wrap: wrap;
        }
        
        .quarter-btn {
            margin: 0.25rem;
        }
        
        .chart-area {
            height: 200px;
        }
        
        .heatmap-cell {
            padding: 0.25rem;
            font-size: 0.8rem;
        }
    }
</style>

<div class="container-fluid">
    <!-- Dashboard Header and Financial Year Selector -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Dashboard</h1>
        <div class="d-flex">
            <div class="dropdown mr-2">
                <button class="btn btn-primary dropdown-toggle" type="button" id="quarterDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <?php echo $selectedQuarter === 'all' ? 'All Quarters' : 'Q' . $selectedQuarter; ?>
                </button>
                <div class="dropdown-menu" aria-labelledby="quarterDropdown">
                    <a class="dropdown-item <?php echo $selectedQuarter === 'all' ? 'active' : ''; ?>" href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=all">All Quarters</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item <?php echo $selectedQuarter === '1' ? 'active' : ''; ?>" href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=1">Q1 (Jul-Sep)</a>
                    <a class="dropdown-item <?php echo $selectedQuarter === '2' ? 'active' : ''; ?>" href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=2">Q2 (Oct-Dec)</a>
                    <a class="dropdown-item <?php echo $selectedQuarter === '3' ? 'active' : ''; ?>" href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=3">Q3 (Jan-Mar)</a>
                    <a class="dropdown-item <?php echo $selectedQuarter === '4' ? 'active' : ''; ?>" href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=4">Q4 (Apr-Jun)</a>
                </div>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-primary dropdown-toggle" type="button" id="fyDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    FY <?php echo htmlspecialchars(getShortFinancialYear($selectedFY)); ?>
                </button>
                <div class="dropdown-menu" aria-labelledby="fyDropdown">
                    <?php foreach(getFinancialYears() as $year): ?>
                        <a class="dropdown-item <?php echo $year === $selectedFY ? 'active' : ''; ?>" href="?financial_year=<?php echo urlencode($year); ?>&quarter=<?php echo $selectedQuarter; ?>">
                            FY <?php echo htmlspecialchars(getShortFinancialYear($year)); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Year Carousel -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="financial-year-selector">
                <h5 class="text-center mb-3">Select Financial Year</h5>
                <div class="fy-carousel">
                    <?php foreach(getFinancialYears() as $year): ?>
                        <div class="fy-item">
                            <a href="?financial_year=<?php echo urlencode($year); ?>&quarter=<?php echo $selectedQuarter; ?>" 
                               class="fy-btn <?php echo $year === $selectedFY ? 'active' : ''; ?>">
                                FY <?php echo htmlspecialchars(getShortFinancialYear($year)); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="quarter-selector">
                    <a href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=all" 
                       class="quarter-btn <?php echo $selectedQuarter === 'all' ? 'active' : ''; ?>">
                        All Quarters
                    </a>
                    <a href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=1" 
                       class="quarter-btn <?php echo $selectedQuarter === '1' ? 'active' : ''; ?>">
                        Q1
                    </a>
                    <a href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=2" 
                       class="quarter-btn <?php echo $selectedQuarter === '2' ? 'active' : ''; ?>">
                        Q2
                    </a>
                    <a href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=3" 
                       class="quarter-btn <?php echo $selectedQuarter === '3' ? 'active' : ''; ?>">
                        Q3
                    </a>
                    <a href="?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=4" 
                       class="quarter-btn <?php echo $selectedQuarter === '4' ? 'active' : ''; ?>">
                        Q4
                    </a>
                </div>
                
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <?php if ($selectedQuarter === 'all'): ?>
                            Showing data for entire <?php echo htmlspecialchars($selectedFY); ?> financial year
                        <?php else: ?>
                            Showing data for Q<?php echo $selectedQuarter; ?> of <?php echo htmlspecialchars($selectedFY); ?>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card summary-card primary h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="card-title">TOTAL ATTACHEES</div>
                            <div class="card-text"><?php echo $total_attachees; ?></div>
                            <div class="mt-2 text-xs font-weight-bold text-primary">
                                <span><?php echo date('M j, Y', strtotime($dateRange['start'])); ?> to <?php echo date('M j, Y', strtotime($dateRange['end'])); ?></span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card summary-card success h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="card-title">ACTIVE ATTACHEES</div>
                            <div class="card-text"><?php echo $active_attachees; ?></div>
                            <div class="mt-2">
                                <span class="text-xs font-weight-bold text-success">
                                    <?php echo $total_attachees > 0 ? round(($active_attachees / $total_attachees) * 100) : 0; ?>% of total
                                </span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card summary-card warning h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="card-title">COMPLETED ATTACHEES</div>
                            <div class="card-text"><?php echo $completed_attachees; ?></div>
                            <div class="mt-2">
                                <span class="text-xs font-weight-bold text-warning">
                                    <?php echo $total_attachees > 0 ? round(($completed_attachees / $total_attachees) * 100) : 0; ?>% of total
                                </span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-graduate fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card summary-card info h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="card-title">QUARTERLY BREAKDOWN</div>
                            <div class="card-text">
                                <?php if ($selectedQuarter === 'all'): ?>
                                    <?php echo array_sum($quarterly_data); ?> total
                                <?php else: ?>
                                    <?php echo $quarterly_data[$selectedQuarter]; ?> in Q<?php echo $selectedQuarter; ?>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2">
                                <span class="text-xs font-weight-bold text-info">
                                    <?php if ($selectedQuarter !== 'all' && array_sum($quarterly_data) > 0): ?>
                                        <?php echo round(($quarterly_data[$selectedQuarter] / array_sum($quarterly_data)) * 100); ?>% of FY
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Department Heatmap Visualization -->
    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Department Activity Heatmap (FY <?php echo htmlspecialchars(getShortFinancialYear($selectedFY)); ?>)</h6>
                    <div class="dropdown no-arrow">
                        <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" 
                           data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right shadow animated--fade-in" 
                             aria-labelledby="dropdownMenuLink">
                            <div class="dropdown-header">View Options:</div>
                            <a class="dropdown-item" href="#" onclick="changeHeatmapView('count')">Show Counts</a>
                            <a class="dropdown-item" href="#" onclick="changeHeatmapView('percentage')">Show Percentages</a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th class="text-center">Q1<br>(Jul-Sep)</th>
                                    <th class="text-center">Q2<br>(Oct-Dec)</th>
                                    <th class="text-center">Q3<br>(Jan-Mar)</th>
                                    <th class="text-center">Q4<br>(Apr-Jun)</th>
                                    <th class="text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Find max value for color scaling
                                $max_value = 0;
                                foreach ($quarterly_department_data as $dept) {
                                    foreach ($dept['quarters'] as $count) {
                                        if ($count > $max_value) $max_value = $count;
                                    }
                                }
                                
                                foreach ($quarterly_department_data as $dept): 
                                    $is_fsrp = ($dept['name'] ?? '') === 'FSRP';
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="heatmap-dept-name">
                                                <?php echo htmlspecialchars($dept['name'] ?? ''); ?>
                                                <?php if($is_fsrp): ?>
                                                    <span class="badge fsrp-badge ms-2">FSRP</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <?php foreach ([1, 2, 3, 4] as $quarter): ?>
                                        <?php 
                                        $count = $dept['quarters'][$quarter];
                                        $percentage = $max_value > 0 ? round(($count / $max_value) * 100) : 0;
                                        $bg_color = $is_fsrp ? 'rgba(111, 66, 193, ' : 'rgba(78, 115, 223, ';
                                        $bg_color .= max(0.1, $percentage / 100) . ')';
                                        $text_color = $percentage > 50 ? 'white' : 'var(--dark-color)';
                                        ?>
                                        <td class="heatmap-cell" 
                                            style="background-color: <?php echo $bg_color; ?>; color: <?php echo $text_color; ?>"
                                            title="<?php echo htmlspecialchars($dept['name']); ?> - Q<?php echo $quarter; ?>: <?php echo $count; ?> attachees"
                                            onclick="window.location.href='department.php?id=<?php echo $dept['id']; ?>&financial_year=<?php echo urlencode($selectedFY); ?>&quarter=<?php echo $quarter; ?>'">
                                            <?php echo $count; ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="heatmap-cell" 
                                        style="background-color: <?php echo $is_fsrp ? 'var(--fsrp-color)' : 'var(--primary-color)'; ?>; color: white"
                                        title="<?php echo htmlspecialchars($dept['name']); ?> - Total: <?php echo $dept['total']; ?> attachees"
                                        onclick="window.location.href='department.php?id=<?php echo $dept['id']; ?>&financial_year=<?php echo urlencode($selectedFY); ?>&quarter=all'">
                                        <?php echo $dept['total']; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Department Summary Cards -->
    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Department Overview</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach($departments as $dept): ?>
                            <?php 
                            $capacity_percent = $dept['max_capacity'] > 0 ? ($dept['active_attachees'] / $dept['max_capacity']) * 100 : 0;
                            $card_class = $capacity_percent >= 80 ? 'border-danger' : '';
                            $is_fsrp = ($dept['name'] ?? '') === 'FSRP';
                            $progress_color = $is_fsrp ? 'bg-fsrp' : ($capacity_percent >= 80 ? 'bg-danger' : 'bg-info');
                            ?>
                            <div class="col-lg-3 col-md-6 mb-4">
                                <div class="card department-card <?php echo $card_class; ?> <?php echo $is_fsrp ? 'fsrp-card' : ''; ?>" 
                                     onclick="window.location.href='department.php?id=<?php echo $dept['id']; ?>&financial_year=<?php echo urlencode($selectedFY); ?>&quarter=<?php echo $selectedQuarter; ?>'">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h5 class="card-title mb-0">
                                                <?php echo htmlspecialchars($dept['name'] ?? ''); ?>
                                                <?php if($is_fsrp): ?>
                                                    <span class="badge fsrp-badge ms-2">FSRP</span>
                                                <?php endif; ?>
                                            </h5>
                                            <span class="badge <?php echo $is_fsrp ? 'fsrp-badge' : 'bg-primary'; ?>">
                                                <?php echo $dept['total_attachees']; ?>
                                            </span>
                                        </div>
                                        
                                        <div class="department-stats">
                                            <div class="stat-item">
                                                <div class="stat-value text-success"><?php echo $dept['active_attachees']; ?></div>
                                                <div class="stat-label">Active</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value text-warning"><?php echo $dept['completed_attachees']; ?></div>
                                                <div class="stat-label">Completed</div>
                                            </div>
                                        </div>
                                        
                                        <?php if($dept['max_capacity'] > 0): ?>
                                            <div class="mt-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <small class="text-muted">Capacity Utilization</small>
                                                    <small class="font-weight-bold <?php echo $capacity_percent >= 80 ? 'text-danger' : 'text-muted'; ?>">
                                                        <?php echo round($capacity_percent); ?>%
                                                    </small>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar <?php echo $progress_color; ?>" 
                                                         role="progressbar" 
                                                         style="width: <?php echo $capacity_percent; ?>%" 
                                                         aria-valuenow="<?php echo $capacity_percent; ?>" 
                                                         aria-valuemin="0" 
                                                         aria-valuemax="100">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Capacity Warnings -->
    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Capacity Warnings</h6>
                </div>
                <div class="card-body">
                    <?php if(count($capacity_warnings) > 0): ?>
                        <div class="alert alert-warning">
                            <strong>Warning:</strong> The following departments are nearing or exceeding their capacity limits.
                        </div>
                        <div class="row">
                            <?php foreach($capacity_warnings as $warning): 
                                $utilization = round(($warning['active_attachees'] / $warning['max_capacity']) * 100);
                                $is_fsrp = ($warning['name'] ?? '') === 'FSRP';
                            ?>
                                <div class="col-md-4 mb-4">
                                    <div class="card department-summary-card border-left-danger">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="card-title mb-0">
                                                    <?php echo htmlspecialchars($warning['name'] ?? ''); ?>
                                                    <?php if($is_fsrp): ?>
                                                        <span class="badge fsrp-badge ms-2">FSRP</span>
                                                    <?php endif; ?>
                                                </h5>
                                                <span class="badge bg-danger"><?php echo $utilization; ?>%</span>
                                            </div>
                                            <div class="progress mb-2">
                                                <div class="progress-bar bg-danger" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $utilization; ?>%" 
                                                     aria-valuenow="<?php echo $utilization; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                </div>
                                            </div>
                                            <div class="department-stats">
                                                <div class="stat-item">
                                                    <div class="stat-value"><?php echo $warning['active_attachees']; ?></div>
                                                    <div class="stat-label">Active</div>
                                                </div>
                                                <div class="stat-item">
                                                    <div class="stat-value"><?php echo $warning['max_capacity']; ?></div>
                                                    <div class="stat-label">Capacity</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <p class="mb-0">All departments are within capacity limits</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Attachees -->
    <div class="row">
        <div class="col-lg-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Attachees</h6>
                    <a href="view_attachees.php?financial_year=<?php echo urlencode($selectedFY); ?>&quarter=<?php echo $selectedQuarter; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-list"></i> View All
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="thead-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>School</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_attachees as $attachee): ?>
                                    <?php $is_fsrp = ($attachee['department_name'] ?? '') === 'FSRP'; ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="mr-2">
                                                    <div class="avatar-sm" style="width: 32px; height: 32px; background-color: <?php echo $is_fsrp ? 'var(--fsrp-color)' : 'var(--primary-color)'; ?>; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                                        <?php echo strtoupper(substr($attachee['first_name'] ?? '', 0, 1) . substr($attachee['last_name'] ?? '', 0, 1)); ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <?php echo htmlspecialchars(($attachee['first_name'] ?? '') . ' ' . ($attachee['last_name'] ?? '')); ?>
                                                    <?php if($is_fsrp): ?>
                                                        <span class="badge fsrp-badge ms-1">FSRP</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($attachee['department_name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['school'] ?? ''); ?></td>
                                        <td>
                                            <?php 
                                            $start_date = !empty($attachee['start_date']) ? date('M Y', strtotime($attachee['start_date'])) : 'N/A';
                                            $end_date = !empty($attachee['end_date']) ? date('M Y', strtotime($attachee['end_date'])) : 'N/A';
                                            echo $start_date . ' - ' . $end_date;
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo ($attachee['status'] ?? '') == 'Active' ? 'success' : 'warning'; ?> status-badge">
                                                <?php echo $attachee['status'] ?? 'Unknown'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit_attachee.php?id=<?php echo $attachee['id']; ?>" class="btn btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="view_attachees.php?delete=<?php echo $attachee['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this attachee?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript Libraries -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/js/all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize financial year carousel to center the selected year
    const carousel = document.querySelector('.fy-carousel');
    const activeItem = document.querySelector('.fy-btn.active').parentElement;
    if (carousel && activeItem) {
        const containerWidth = carousel.offsetWidth;
        const itemWidth = activeItem.offsetWidth;
        const itemOffset = activeItem.offsetLeft;
        const scrollPosition = itemOffset - (containerWidth / 2) + (itemWidth / 2);
        carousel.scrollTo({ left: scrollPosition, behavior: 'smooth' });
    }
});

function changeHeatmapView(type) {
    const cells = document.querySelectorAll('.heatmap-cell');
    const max_value = <?php echo $max_value; ?>;
    
    cells.forEach(cell => {
        if (!cell.textContent.match(/^\d+$/)) return; // Skip non-numeric cells (like department names)
        
        const count = parseInt(cell.textContent);
        const is_fsrp = cell.closest('tr').querySelector('.fsrp-badge') !== null;
        
        if (type === 'percentage') {
            const percentage = max_value > 0 ? Math.round((count / max_value) * 100) : 0;
            cell.textContent = percentage + '%';
            cell.title = cell.title.replace(/: \d+ attachees/, `: ${percentage}% of max`);
        } else {
            cell.textContent = count;
            cell.title = cell.title.replace(/: \d+% of max/, `: ${count} attachees`);
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>