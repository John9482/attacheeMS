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

// Get department ID from URL
$dept_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get filter parameters
$financial_year_filter = $_GET['financial_year'] ?? '';
$quarter_filter = $_GET['quarter'] ?? '';

try {
    // Get department details
    $stmt = $conn->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$dept_id]);
    $department = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$department) {
        die("Department not found");
    }

    // Base query for attachees
    $query = "SELECT a.* FROM attachees a WHERE a.department_id = ?";
    $params = [$dept_id];

    // Apply filters
    if(!empty($financial_year_filter)) {
        $query .= " AND a.financial_year = ?";
        $params[] = $financial_year_filter;
    }

    if(!empty($quarter_filter) && !empty($financial_year_filter)) {
        $quarters = getFinancialYearQuarters($financial_year_filter);
        if (isset($quarters[$quarter_filter])) {
            $quarter = $quarters[$quarter_filter];
            $query .= " AND a.start_date <= ? AND a.end_date >= ?";
            $params[] = $quarter['end'];
            $params[] = $quarter['start'];
        }
    }

    $query .= " ORDER BY a.status, a.end_date ASC";

    // Get filtered attachees
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $attachees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get financial years with attachee counts
    $financial_years = $conn->prepare("
        SELECT financial_year, 
               COUNT(*) as total,
               SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
               SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed
        FROM attachees
        WHERE department_id = ?
        GROUP BY financial_year
        ORDER BY financial_year DESC
    ");
    $financial_years->execute([$dept_id]);
    $financial_years = $financial_years->fetchAll(PDO::FETCH_ASSOC);

    // Get quarters for the selected financial year
    $quarters = [];
    if (!empty($financial_year_filter)) {
        $quarters = getFinancialYearQuarters($financial_year_filter);
    }

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

include 'includes/header.php';
?>

<div class="container mt-4">
    <!-- Department Header -->
    <div class="row">
        <div class="col-md-12">
            <div class="department-header bg-light p-3 rounded mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-building text-primary"></i> <?php echo htmlspecialchars($department['name'] ?? ''); ?>
                        </h2>
                        <div class="department-meta">
                            <span class="badge bg-white text-dark border">
                                <i class="bi bi-geo-alt text-primary"></i> <?php echo htmlspecialchars($department['location'] ?? 'Not specified'); ?>
                            </span>
                            <span class="badge bg-white text-dark border ms-2">
                                <i class="bi bi-people text-primary"></i> Capacity: <?php echo $department['max_capacity'] ?? 10; ?>
                            </span>
                        </div>
                    </div>
                    <div class="current-year">
                        <span class="badge bg-primary">
                            <i class="bi bi-calendar"></i> <?php echo getCurrentFinancialYear(); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Year Navigation -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white rounded-top">
                    <h5 class="mb-0"><i class="bi bi-calendar-range"></i> Financial Years</h5>
                </div>
                <div class="card-body p-2 bg-light">
                    <div class="financial-year-slider">
                        <div class="btn-toolbar" role="toolbar">
                            <div class="btn-group" role="group">
                                <a href="department.php?id=<?php echo $dept_id; ?>" 
                                   class="btn btn-sm btn-outline-primary financial-year-btn <?php echo empty($financial_year_filter) ? 'active' : ''; ?>">
                                    <i class="bi bi-calendar"></i> All Years
                                </a>
                                <?php foreach($financial_years as $year): ?>
    <a href="department.php?id=<?php echo $dept_id; ?>&financial_year=<?php echo urlencode($year['financial_year'] ?? ''); ?>" 
       class="btn btn-outline-primary financial-year-btn <?php echo ($_GET['financial_year'] ?? '') == $year['financial_year'] ? 'active' : ''; ?>"
       data-year="<?php echo htmlspecialchars($year['financial_year'] ?? ''); ?>"
       data-bs-toggle="tooltip" 
       data-bs-html="true"
       title="<b><?php echo htmlspecialchars($year['financial_year'] ?? ''); ?></b><br>
              Total: <?php echo $year['total']; ?><br>
              Active: <?php echo $year['active']; ?><br>
              Completed: <?php echo $year['completed']; ?>">
        <?php echo htmlspecialchars($year['financial_year'] ?? ''); ?>
        <span class="badge bg-light text-dark ms-1"><?php echo $year['total']; ?></span>
    </a>
<?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quarter Navigation (if financial year selected) -->
    <?php if (!empty($financial_year_filter) && !empty($quarters)): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-info text-white rounded-top">
                    <h5 class="mb-0"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($financial_year_filter); ?> Quarters</h5>
                </div>
                <div class="card-body p-2 bg-light">
                    <div class="quarter-slider">
                        <div class="btn-toolbar" role="toolbar">
                            <div class="btn-group" role="group">
                                <a href="department.php?id=<?php echo $dept_id; ?>&financial_year=<?php echo urlencode($financial_year_filter); ?>" 
                                   class="btn btn-sm btn-outline-info quarter-btn <?php echo empty($quarter_filter) ? 'active' : ''; ?>">
                                    <i class="bi bi-grid"></i> All Quarters
                                </a>
                                <?php foreach($quarters as $q => $quarter): 
                                    $stmt = $conn->prepare("
                                        SELECT COUNT(*) as count 
                                        FROM attachees 
                                        WHERE department_id = ? 
                                        AND financial_year = ?
                                        AND start_date <= ? 
                                        AND end_date >= ?
                                    ");
                                    $stmt->execute([$dept_id, $financial_year_filter, $quarter['end'], $quarter['start']]);
                                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                                ?>
                                    <a href="department.php?id=<?php echo $dept_id; ?>&financial_year=<?php echo urlencode($financial_year_filter); ?>&quarter=<?php echo $q; ?>" 
                                       class="btn btn-sm btn-outline-info quarter-btn <?php echo $quarter_filter === $q ? 'active' : ''; ?>"
                                       data-bs-toggle="tooltip" 
                                       data-bs-html="true"
                                       title="<div class='text-start'>
                                           <strong><?php echo $quarter['name']; ?></strong><br>
                                           <?php echo date('M j', strtotime($quarter['start'])); ?> - <?php echo date('M j', strtotime($quarter['end'])); ?><br>
                                           <?php echo $count; ?> attachees
                                       </div>">
                                        <?php echo $q; ?>
                                        <span class="badge bg-white text-info ms-1"><?php echo $count; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100 border-start border-primary border-4">
                <div class="card-body text-center py-4">
                    <div class="text-primary mb-2">
                        <i class="bi bi-people fs-1"></i>
                    </div>
                    <h3 class="text-primary mb-1"><?php echo count($attachees); ?></h3>
                    <h6 class="text-muted">Total Attachees</h6>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100 border-start border-success border-4">
                <div class="card-body text-center py-4">
                    <div class="text-success mb-2">
                        <i class="bi bi-person-check fs-1"></i>
                    </div>
                    <h3 class="text-success mb-1"><?php echo count(array_filter($attachees, fn($a) => $a['status'] === 'Active')); ?></h3>
                    <h6 class="text-muted">Active</h6>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card shadow-sm h-100 border-start border-warning border-4">
                <div class="card-body text-center py-4">
                    <div class="text-warning mb-2">
                        <i class="bi bi-person-x fs-1"></i>
                    </div>
                    <h3 class="text-warning mb-1"><?php echo count(array_filter($attachees, fn($a) => $a['status'] === 'Completed')); ?></h3>
                    <h6 class="text-muted">Completed</h6>
                </div>
            </div>
        </div>
    </div>

    <!-- Attachees Table -->
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-0 pt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-table"></i> Attachee Records</h5>
                        <div>
                            <?php if (!empty($financial_year_filter)): ?>
                                <span class="badge bg-primary">
                                    <i class="bi bi-calendar"></i> <?php echo htmlspecialchars($financial_year_filter); ?>
                                </span>
                                <?php if (!empty($quarter_filter)): ?>
                                    <span class="badge bg-info ms-2">
                                        <i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($quarter_filter); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if(count($attachees) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th width="50" class="text-center">#</th>
                                        <th>Attachee</th>
                                        <th>School</th>
                                        <th>Year</th>
                                        <th>Quarter</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($attachees as $index => $attachee): 
                                        $quarter = getFinancialQuarterForDate($attachee['start_date'] ?? '');
                                    ?>
                                        <tr>
                                            <td class="text-center text-muted"><?php echo $index + 1; ?></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar bg-primary text-white rounded-circle me-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                        <?php echo strtoupper(substr($attachee['first_name'] ?? '', 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars(($attachee['first_name'] ?? '') . ' ' . ($attachee['last_name'] ?? '')); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($attachee['location_slot'] ?? 'No slot'); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($attachee['school'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($attachee['financial_year'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($quarter): ?>
                                                    <span class="badge bg-info-subtle text-info"><?php echo $quarter['quarter']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <small class="text-muted">Start: <?php echo isset($attachee['start_date']) ? date('M j, Y', strtotime($attachee['start_date'])) : 'N/A'; ?></small>
                                                    <small class="text-muted">End: <?php echo isset($attachee['end_date']) ? date('M j, Y', strtotime($attachee['end_date'])) : 'N/A'; ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge rounded-pill bg-<?php echo ($attachee['status'] ?? '') === 'Active' ? 'success-subtle text-success' : 'warning-subtle text-warning'; ?>">
                                                    <i class="bi <?php echo ($attachee['status'] ?? '') === 'Active' ? 'bi-check-circle' : 'bi-clock-history'; ?>"></i>
                                                    <?php echo htmlspecialchars($attachee['status'] ?? ''); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info border-0">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle-fill fs-4 me-3"></i>
                                <div>
                                    <h5 class="alert-heading">No attachees found</h5>
                                    <p class="mb-0">Try adjusting your filters or adding new attachees.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .department-header {
        background-color: #f8fafc;
        border-radius: 10px;
    }
    
    .department-meta .badge {
        padding: 5px 10px;
        font-weight: normal;
    }
    
    .financial-year-slider, .quarter-slider {
        overflow-x: auto;
        white-space: nowrap;
        padding-bottom: 5px;
    }
    
    .financial-year-slider::-webkit-scrollbar, 
    .quarter-slider::-webkit-scrollbar {
        height: 5px;
    }
    
    .financial-year-slider::-webkit-scrollbar-track, 
    .quarter-slider::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    
    .financial-year-slider::-webkit-scrollbar-thumb, 
    .quarter-slider::-webkit-scrollbar-thumb {
        background: #ddd;
        border-radius: 10px;
    }
    
    .financial-year-btn, .quarter-btn {
        margin-right: 8px;
        min-width: 120px;
        transition: all 0.2s;
        border-radius: 6px !important;
        position: relative;
        overflow: hidden;
    }
    
    .financial-year-btn.active, .quarter-btn.active {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .financial-year-btn.active {
        background-color: #0d6efd !important;
        color: white !important;
    }
    
    .quarter-btn.active {
        background-color: #0dcaf0 !important;
        color: white !important;
    }
    
    .financial-year-btn .badge, .quarter-btn .badge {
        position: relative;
        top: -1px;
    }
    
    .card {
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid rgba(0,0,0,0.05);
    }
    
    .card-header {
        padding: 12px 20px;
    }
    
    .table th {
        white-space: nowrap;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
    }
    
    .table td {
        vertical-align: middle;
    }
    
    .avatar {
        font-size: 14px;
        font-weight: 600;
    }
    
    .badge {
        font-weight: 500;
        padding: 5px 10px;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl, {
            html: true,
            boundary: 'window'
        });
    });

    // Auto-scroll to active financial year button
    const activeYearBtn = document.querySelector('.financial-year-btn.active');
    if (activeYearBtn) {
        setTimeout(() => {
            activeYearBtn.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'center'
            });
        }, 300);
    }

    // Auto-scroll to active quarter button
    const activeQuarterBtn = document.querySelector('.quarter-btn.active');
    if (activeQuarterBtn) {
        setTimeout(() => {
            activeQuarterBtn.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'center'
            });
        }, 300);
    }
});
</script>

<?php include 'includes/footer.php'; ?>