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

// Get all departments for filter
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all schools for filter
$school_query = "SELECT DISTINCT school FROM attachees WHERE school IS NOT NULL ORDER BY school";
$school_stmt = $conn->prepare($school_query);
$school_stmt->execute();
$schools = $school_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get financial years for filter
$financial_years = getFinancialYears();

// Initialize filter variables
$filter_conditions = [];
$params = [];

// Apply filters if set
if(isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_conditions[] = "a.department_id = :dept_id";
    $params[':dept_id'] = $_GET['department'];
}

if(isset($_GET['status']) && !empty($_GET['status'])) {
    $filter_conditions[] = "a.status = :status";
    $params[':status'] = $_GET['status'];
}

if(isset($_GET['school']) && !empty($_GET['school'])) {
    $filter_conditions[] = "a.school = :school";
    $params[':school'] = $_GET['school'];
}

if(isset($_GET['location']) && !empty($_GET['location'])) {
    $filter_conditions[] = "a.location LIKE :location";
    $params[':location'] = '%' . $_GET['location'] . '%';
}

if(isset($_GET['financial_year']) && !empty($_GET['financial_year'])) {
    $fyDates = getFinancialYearDates($_GET['financial_year']);
    $filter_conditions[] = "a.created_at BETWEEN :fy_start AND :fy_end";
    $params[':fy_start'] = $fyDates['start'];
    $params[':fy_end'] = $fyDates['end'];
}

if(isset($_GET['quarter']) && !empty($_GET['quarter'])) {
    switch($_GET['quarter']) {
        case 'Q1': $filter_conditions[] = "MONTH(a.start_date) BETWEEN 7 AND 9"; break;
        case 'Q2': $filter_conditions[] = "MONTH(a.start_date) BETWEEN 10 AND 12"; break;
        case 'Q3': $filter_conditions[] = "MONTH(a.start_date) BETWEEN 1 AND 3"; break;
        case 'Q4': $filter_conditions[] = "MONTH(a.start_date) BETWEEN 4 AND 6"; break;
    }
}

if(isset($_GET['months']) && is_numeric($_GET['months'])) {
    $filter_conditions[] = "a.start_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)";
    $params[':months'] = (int)$_GET['months'];
}

if(isset($_GET['registration_month']) && !empty($_GET['registration_month'])) {
    $filter_conditions[] = "MONTH(a.created_at) = :reg_month AND YEAR(a.created_at) = YEAR(CURDATE())";
    $params[':reg_month'] = $_GET['registration_month'];
}

// Build the WHERE clause
$where_clause = empty($filter_conditions) ? '1=1' : implode(' AND ', $filter_conditions);

// Handle Excel export
if(isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="attachees_'.date('Y-m-d').'.xls"');
    header('Cache-Control: max-age=0');
    
    // Re-execute query for export with all selected fields
    $export_query = "SELECT 
        a.*,
        d.name as department_name,
        DATE_FORMAT(a.created_at, '%Y-%m-%d') as registration_date,
        CASE 
            WHEN MONTH(a.start_date) BETWEEN 7 AND 9 THEN 'Q1'
            WHEN MONTH(a.start_date) BETWEEN 10 AND 12 THEN 'Q2'
            WHEN MONTH(a.start_date) BETWEEN 1 AND 3 THEN 'Q3'
            WHEN MONTH(a.start_date) BETWEEN 4 AND 6 THEN 'Q4'
        END as quarter
    FROM attachees a 
    JOIN departments d ON a.department_id = d.id 
    WHERE $where_clause
    ORDER BY a.start_date DESC";
    
    $export_stmt = $conn->prepare($export_query);
    
    foreach ($params as $key => &$val) {
        $export_stmt->bindParam($key, $val);
    }
    
    $export_stmt->execute();
    $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Output Excel content with numbering
    echo '<table border="1">';
    echo '<tr>
        <th>#</th>
        <th>Name</th>
        <th>Gender</th>
        <th>Department</th>
        <th>School</th>
        <th>Location</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Quarter</th>
        <th>Financial Year</th>
        <th>Registered On</th>
        <th>Status</th>
    </tr>';
    
    foreach($export_data as $index => $row) {
        $start_date = new DateTime($row['start_date']);
        $month = $start_date->format('n');
        $year = $start_date->format('Y');
        $financial_year = ($month >= 7) ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
        
        echo '<tr>';
        echo '<td>'.($index+1).'</td>';
        echo '<td>'.htmlspecialchars($row['first_name'].' '.$row['last_name']).'</td>';
        echo '<td>'.htmlspecialchars($row['gender'] ?? 'N/A').'</td>';
        echo '<td>'.htmlspecialchars($row['department_name']).'</td>';
        echo '<td>'.htmlspecialchars($row['school']).'</td>';
        echo '<td>'.htmlspecialchars($row['location'] ?? 'N/A').'</td>';
        echo '<td>'.htmlspecialchars($row['email']).'</td>';
        echo '<td>'.htmlspecialchars($row['phone']).'</td>';
        echo '<td>'.$row['start_date'].'</td>';
        echo '<td>'.$row['end_date'].'</td>';
        echo '<td>'.$row['quarter'].'</td>';
        echo '<td>'.$financial_year.'</td>';
        echo '<td>'.$row['registration_date'].'</td>';
        echo '<td>'.$row['status'].'</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    exit();
}

// Main query
$query = "SELECT a.*, d.name as department_name, DATE_FORMAT(a.created_at, '%Y-%m-%d') as registration_date
          FROM attachees a 
          JOIN departments d ON a.department_id = d.id 
          WHERE $where_clause
          ORDER BY a.start_date DESC";

$stmt = $conn->prepare($query);

foreach ($params as $key => &$val) {
    $stmt->bindParam($key, $val);
}

$stmt->execute();
$attachees = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <h2 class="mb-4">All Attachees</h2>
        
        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Attachee Records</h5>
                    <div>
                        <a href="viewall.php?export=excel<?= isset($_GET['department']) ? '&department='.$_GET['department'] : '' ?><?= isset($_GET['status']) ? '&status='.$_GET['status'] : '' ?><?= isset($_GET['school']) ? '&school='.$_GET['school'] : '' ?><?= isset($_GET['location']) ? '&location='.$_GET['location'] : '' ?><?= isset($_GET['financial_year']) ? '&financial_year='.$_GET['financial_year'] : '' ?><?= isset($_GET['quarter']) ? '&quarter='.$_GET['quarter'] : '' ?><?= isset($_GET['months']) ? '&months='.$_GET['months'] : '' ?><?= isset($_GET['registration_month']) ? '&registration_month='.$_GET['registration_month'] : '' ?>" class="btn btn-success btn-sm me-2">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                        <a href="add_attachee.php" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-plus me-1"></i> Add New
                        </a>
                        <button class="btn btn-info btn-sm" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                            <i class="fas fa-filter me-1"></i> Filters
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="collapse" id="filterCollapse">
                <div class="card-body bg-light">
                    <form method="get" action="">
                        <div class="row">
                            <div class="col-md-2">
                                <label>Department</label>
                                <select name="department" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach($departments as $dept): ?>
                                        <option value="<?= $dept['id'] ?>" <?= isset($_GET['department']) && $_GET['department'] == $dept['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label>Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Statuses</option>
                                    <option value="Active" <?= isset($_GET['status']) && $_GET['status'] == 'Active' ? 'selected' : '' ?>>Active</option>
                                    <option value="Completed" <?= isset($_GET['status']) && $_GET['status'] == 'Completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label>School/Institution</label>
                                <select name="school" class="form-select">
                                    <option value="">All Schools</option>
                                    <?php foreach($schools as $school): ?>
                                        <option value="<?= htmlspecialchars($school) ?>" <?= isset($_GET['school']) && $_GET['school'] == $school ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($school) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label>Location</label>
                                <input type="text" name="location" class="form-control" 
                                       value="<?= isset($_GET['location']) ? htmlspecialchars($_GET['location']) : '' ?>" 
                                       placeholder="Enter location">
                            </div>
                            
                            <div class="col-md-2">
                                <label>Financial Year</label>
                                <select name="financial_year" class="form-select">
                                    <option value="">All Years</option>
                                    <?php foreach($financial_years as $year): ?>
                                        <option value="<?= $year ?>" <?= isset($_GET['financial_year']) && $_GET['financial_year'] == $year ? 'selected' : '' ?>>
                                            FY <?= htmlspecialchars(getShortFinancialYear($year)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label>Quarter</label>
                                <select name="quarter" class="form-select">
                                    <option value="">All Quarters</option>
                                    <option value="Q1" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q1' ? 'selected' : '' ?>>Q1 (Jul-Sep)</option>
                                    <option value="Q2" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q2' ? 'selected' : '' ?>>Q2 (Oct-Dec)</option>
                                    <option value="Q3" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q3' ? 'selected' : '' ?>>Q3 (Jan-Mar)</option>
                                    <option value="Q4" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q4' ? 'selected' : '' ?>>Q4 (Apr-Jun)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-2">
                                <label>Last X Months</label>
                                <select name="months" class="form-select">
                                    <option value="">All Time</option>
                                    <option value="1" <?= isset($_GET['months']) && $_GET['months'] == '1' ? 'selected' : '' ?>>Last 1 Month</option>
                                    <option value="2" <?= isset($_GET['months']) && $_GET['months'] == '2' ? 'selected' : '' ?>>Last 2 Months</option>
                                    <option value="3" <?= isset($_GET['months']) && $_GET['months'] == '3' ? 'selected' : '' ?>>Last 3 Months</option>
                                    <option value="6" <?= isset($_GET['months']) && $_GET['months'] == '6' ? 'selected' : '' ?>>Last 6 Months</option>
                                    <option value="12" <?= isset($_GET['months']) && $_GET['months'] == '12' ? 'selected' : '' ?>>Last 12 Months</option>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label>Registration Month</label>
                                <select name="registration_month" class="form-select">
                                    <option value="">Any Month</option>
                                    <?php 
                                    $current_month = date('n');
                                    for($i = 1; $i <= 12; $i++): 
                                        $month_name = date('F', mktime(0, 0, 0, $i, 1));
                                    ?>
                                        <option value="<?= $i ?>" <?= isset($_GET['registration_month']) && $_GET['registration_month'] == $i ? 'selected' : '' ?>>
                                            <?= $month_name ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-8 text-end">
                                <button type="submit" class="btn btn-success me-2">
                                    <i class="fas fa-search me-1"></i> Apply Filters
                                </button>
                                <a href="viewall.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-1"></i> Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="attacheesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Department</th>
                                <th>School</th>
                                <th>Location</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Quarter</th>
                                <th>Financial Year</th>
                                <th>Registered On</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($attachees as $index => $attachee): 
                                $start_date = new DateTime($attachee['start_date']);
                                $month = $start_date->format('n');
                                $year = $start_date->format('Y');
                                $quarter = '';
                                if($month >= 7 && $month <= 9) $quarter = 'Q1';
                                elseif($month >= 10 && $month <= 12) $quarter = 'Q2';
                                elseif($month >= 1 && $month <= 3) $quarter = 'Q3';
                                elseif($month >= 4 && $month <= 6) $quarter = 'Q4';
                                
                                $financial_year = ($month >= 7) ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
                            ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']) ?></td>
                                    <td><?= htmlspecialchars($attachee['gender'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($attachee['department_name']) ?></td>
                                    <td><?= htmlspecialchars($attachee['school']) ?></td>
                                    <td><?= htmlspecialchars($attachee['location'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($attachee['email']) ?></td>
                                    <td><?= htmlspecialchars($attachee['phone']) ?></td>
                                    <td><?= date('d M Y', strtotime($attachee['start_date'])) ?></td>
                                    <td><?= date('d M Y', strtotime($attachee['end_date'])) ?></td>
                                    <td><?= $quarter ?></td>
                                    <td><?= $financial_year ?></td>
                                    <td><?= date('d M Y', strtotime($attachee['registration_date'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $attachee['status'] == 'Active' ? 'success' : 'warning' ?>">
                                            <?= $attachee['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="edit_attachee.php?id=<?= $attachee['id'] ?>" class="btn btn-sm btn-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="viewall.php?delete=<?= $attachee['id'] ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this attachee?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
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

<script>
$(document).ready(function() {
    $('#attacheesTable').DataTable({
        dom: 'Blfrtip',
        buttons: [
            {
                extend: 'copy',
                text: '<i class="fas fa-copy"></i> Copy',
                className: 'btn btn-sm btn-secondary',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-sm btn-info',
                title: 'Attachees_<?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-sm btn-success',
                title: 'Attachees_<?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-danger',
                title: 'Attachees_<?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-sm btn-primary',
                title: 'Attachees Report - <?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                },
                customize: function (win) {
                    $(win.document.body).find('h1').css('text-align','center');
                    $(win.document.body).css('font-size', '10pt');
                    $(win.document.body).find('table')
                        .addClass('compact')
                        .css('font-size', 'inherit');
                }
            }
        ],
        responsive: true,
        pageLength: 25,
        columnDefs: [
            { orderable: false, targets: -1 } // Disable sorting for actions column
        ],
        initComplete: function() {
            // Add custom filter for financial year in the header
            this.api().columns([11]).every(function() {
                var column = this;
                var select = $('<select class="form-control form-control-sm"><option value="">All Years</option></select>')
                    .appendTo($(column.header()).empty())
                    .on('change', function() {
                        var val = $.fn.dataTable.util.escapeRegex($(this).val());
                        column.search(val ? val : '', true, false).draw();
                    });

                column.data().unique().sort().each(function(d) {
                    select.append('<option value="' + d + '">' + d + '</option>');
                });
            });
            
            // Add custom filter for quarter in the header
            this.api().columns([10]).every(function() {
                var column = this;
                var select = $('<select class="form-control form-control-sm"><option value="">All Quarters</option></select>')
                    .appendTo($(column.header()).empty())
                    .on('change', function() {
                        var val = $.fn.dataTable.util.escapeRegex($(this).val());
                        column.search(val ? '^' + val + '$' : '', true, false).draw();
                    });

                select.append('<option value="Q1">Q1</option>');
                select.append('<option value="Q2">Q2</option>');
                select.append('<option value="Q3">Q3</option>');
                select.append('<option value="Q4">Q4</option>');
            });
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>