<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/helpers.php';

$auth = new Auth();

if(!$auth->isLoggedIn()) {
    header("Location: ../login.php");
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
$filter_conditions = ["a.status = 'Completed'"];
$params = [];

// Apply filters if set
if(isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_conditions[] = "a.department_id = :dept_id";
    $params[':dept_id'] = $_GET['department'];
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
    $filter_conditions[] = "a.end_date BETWEEN :fy_start AND :fy_end";
    $params[':fy_start'] = $fyDates['start'];
    $params[':fy_end'] = $fyDates['end'];
}

if(isset($_GET['quarter']) && !empty($_GET['quarter'])) {
    switch($_GET['quarter']) {
        case 'Q1': $filter_conditions[] = "MONTH(a.end_date) BETWEEN 7 AND 9"; break;
        case 'Q2': $filter_conditions[] = "MONTH(a.end_date) BETWEEN 10 AND 12"; break;
        case 'Q3': $filter_conditions[] = "MONTH(a.end_date) BETWEEN 1 AND 3"; break;
        case 'Q4': $filter_conditions[] = "MONTH(a.end_date) BETWEEN 4 AND 6"; break;
    }
}

// Build the WHERE clause
$where_clause = implode(' AND ', $filter_conditions);

// Handle Excel export
if(isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="completed_attachees_'.date('Y-m-d').'.xls"');
    header('Cache-Control: max-age=0');
    
    // Re-execute query for export with all selected fields
    $export_query = "SELECT 
        a.*,
        d.name as department_name,
        DATE_FORMAT(a.end_date, '%Y-%m-%d') as completion_date,
        CASE 
            WHEN MONTH(a.end_date) BETWEEN 7 AND 9 THEN 'Q1'
            WHEN MONTH(a.end_date) BETWEEN 10 AND 12 THEN 'Q2'
            WHEN MONTH(a.end_date) BETWEEN 1 AND 3 THEN 'Q3'
            WHEN MONTH(a.end_date) BETWEEN 4 AND 6 THEN 'Q4'
        END as quarter
    FROM attachees a 
    JOIN departments d ON a.department_id = d.id 
    WHERE $where_clause
    ORDER BY 
        CASE WHEN d.name = 'FSRP' THEN 0 ELSE 1 END,
        a.end_date DESC";
    
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
        <th>Completion Date</th>
    </tr>';
    
    foreach($export_data as $index => $row) {
        $end_date = new DateTime($row['end_date']);
        $month = $end_date->format('n');
        $year = $end_date->format('Y');
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
        echo '<td>'.$row['completion_date'].'</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    exit();
}

// Main query
$query = "SELECT a.*, d.name as department_name 
          FROM attachees a 
          JOIN departments d ON a.department_id = d.id 
          WHERE $where_clause
          ORDER BY 
            CASE WHEN d.name = 'FSRP' THEN 0 ELSE 1 END,
            a.end_date DESC";

$stmt = $conn->prepare($query);

foreach ($params as $key => &$val) {
    $stmt->bindParam($key, $val);
}

$stmt->execute();
$attachees = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<style>
    .fsrp-badge {
        background-color: #6f42c1;
        color: white;
    }
    .fsrp-row {
        border-left: 3px solid #6f42c1;
    }
    .fsrp-row:hover {
        background-color: rgba(111, 66, 193, 0.05);
    }
    .btn-purple {
        background-color: #6f42c1;
        color: white;
    }
    .btn-purple:hover {
        background-color: #5a32b0;
        color: white;
    }
</style>

<div class="row">
    <div class="col-md-12">
        <h2 class="mb-4">Completed Attachees</h2>
        
        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Past Attachees</h5>
                    <div>
                        <a href="completed.php?export=excel<?= isset($_GET['department']) ? '&department='.$_GET['department'] : '' ?><?= isset($_GET['school']) ? '&school='.$_GET['school'] : '' ?><?= isset($_GET['location']) ? '&location='.$_GET['location'] : '' ?><?= isset($_GET['financial_year']) ? '&financial_year='.$_GET['financial_year'] : '' ?><?= isset($_GET['quarter']) ? '&quarter='.$_GET['quarter'] : '' ?>" class="btn btn-success btn-sm me-2">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                        <button class="btn btn-info btn-sm" id="filterFsrp">
                            <i class="fas fa-filter me-1"></i> Filter FSRP
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="card-body bg-light">
                <form method="get" action="">
                    <div class="row">
                        <div class="col-md-3">
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
                        
                        <div class="col-md-3">
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
                        
                        <div class="col-md-3">
                            <label>Location</label>
                            <input type="text" name="location" class="form-control" 
                                   value="<?= isset($_GET['location']) ? htmlspecialchars($_GET['location']) : '' ?>" 
                                   placeholder="Enter location">
                        </div>
                        
                        <div class="col-md-3">
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
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <label>Quarter</label>
                            <select name="quarter" class="form-select">
                                <option value="">All Quarters</option>
                                <option value="Q1" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q1' ? 'selected' : '' ?>>Q1 (Jul-Sep)</option>
                                <option value="Q2" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q2' ? 'selected' : '' ?>>Q2 (Oct-Dec)</option>
                                <option value="Q3" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q3' ? 'selected' : '' ?>>Q3 (Jan-Mar)</option>
                                <option value="Q4" <?= isset($_GET['quarter']) && $_GET['quarter'] == 'Q4' ? 'selected' : '' ?>>Q4 (Apr-Jun)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <!-- Empty column for spacing -->
                        </div>
                        
                        <div class="col-md-3 text-end">
                            <button type="submit" class="btn btn-success me-2">
                                <i class="fas fa-search me-1"></i> Apply Filters
                            </button>
                            <a href="completed.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped" id="completedTable">
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($attachees as $index => $attachee): 
                                $is_fsrp = ($attachee['department_name'] ?? '') === 'FSRP';
                                $end_date = new DateTime($attachee['end_date']);
                                $month = $end_date->format('n');
                                $year = $end_date->format('Y');
                                $quarter = '';
                                if($month >= 7 && $month <= 9) $quarter = 'Q1';
                                elseif($month >= 10 && $month <= 12) $quarter = 'Q2';
                                elseif($month >= 1 && $month <= 3) $quarter = 'Q3';
                                elseif($month >= 4 && $month <= 6) $quarter = 'Q4';
                                
                                $financial_year = ($month >= 7) ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
                            ?>
                                <tr class="<?= $is_fsrp ? 'fsrp-row' : '' ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <?= htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']) ?>
                                        <?php if($is_fsrp): ?>
                                            <span class="badge fsrp-badge ms-1">FSRP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($attachee['gender'] ?? 'N/A') ?></td>
                                    <td>
                                        <?= htmlspecialchars($attachee['department_name']) ?>
                                        <?php if($is_fsrp): ?>
                                            <span class="badge fsrp-badge ms-1">FSRP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($attachee['school']) ?></td>
                                    <td><?= htmlspecialchars($attachee['location'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($attachee['email']) ?></td>
                                    <td><?= htmlspecialchars($attachee['phone']) ?></td>
                                    <td><?= date('d M Y', strtotime($attachee['start_date'])) ?></td>
                                    <td><?= date('d M Y', strtotime($attachee['end_date'])) ?></td>
                                    <td><?= $quarter ?></td>
                                    <td><?= $financial_year ?></td>
                                    <td>
                                        <a href="../edit_attachee.php?id=<?= $attachee['id'] ?>" class="btn btn-sm btn-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="../view_attachees.php?delete=<?= $attachee['id'] ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this attachee?')">
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
    var table = $('#completedTable').DataTable({
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
                title: 'Completed_Attachees_<?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-sm btn-success',
                title: 'Completed_Attachees_<?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-danger',
                title: 'Completed_Attachees_<?= date("Y-m-d") ?>',
                exportOptions: {
                    columns: ':not(:last-child)'
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-sm btn-primary',
                title: 'Completed Attachees - <?= date("Y-m-d") ?>',
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
            { orderable: false, targets: -1 }, // Disable sorting for actions column
            {
                targets: [3], // Department column
                render: function(data, type, row) {
                    if (type === 'sort') {
                        return data.includes('FSRP') ? '0' + data : '1' + data;
                    }
                    return data;
                }
            }
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

    // Filter button for FSRP
    $('#filterFsrp').click(function() {
        if ($(this).hasClass('active')) {
            table.search('').columns().search('').draw();
            $(this).removeClass('active').removeClass('btn-purple').addClass('btn-info');
        } else {
            table.search('FSRP').draw();
            $(this).addClass('active').removeClass('btn-info').addClass('btn-purple');
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>