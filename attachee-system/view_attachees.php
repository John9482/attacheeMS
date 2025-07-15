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

// Handle delete action
if(isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $query = "DELETE FROM attachees WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    $_SESSION['message'] = "Attachee deleted successfully";
    header("Location: view_attachees.php");
    exit();
}

// Get all departments for filter
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get financial years for filter
$financial_years = getFinancialYears();

// Initialize filter conditions
$filter_conditions = [];
$params = [];

// Handle department filter
if(isset($_GET['department']) && !empty($_GET['department'])) {
    $filter_conditions[] = "a.department_id = :dept_id";
    $params[':dept_id'] = $_GET['department'];
}

// Handle financial year filter
if(isset($_GET['financial_year']) && !empty($_GET['financial_year'])) {
    $fyDates = getFinancialYearDates($_GET['financial_year']);
    $filter_conditions[] = "a.created_at BETWEEN :fy_start AND :fy_end";
    $params[':fy_start'] = $fyDates['start'];
    $params[':fy_end'] = $fyDates['end'];
}

// Handle location filter
if(isset($_GET['location']) && !empty($_GET['location'])) {
    $filter_conditions[] = "a.location LIKE :location";
    $params[':location'] = '%' . $_GET['location'] . '%';
}

// Build the WHERE clause
$where_clause = empty($filter_conditions) ? '1=1' : implode(' AND ', $filter_conditions);

// Get all attachees with department names
$query = "SELECT a.*, d.name as department_name 
          FROM attachees a 
          JOIN departments d ON a.department_id = d.id 
          WHERE $where_clause
          ORDER BY a.status, a.last_name, a.first_name";
$stmt = $conn->prepare($query);

foreach ($params as $key => &$val) {
    $stmt->bindParam($key, $val);
}

$stmt->execute();
$attachees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle Excel export
if(isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="attachees_'.date('Y-m-d').'.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<table border="1">';
    echo '<tr><th>#</th><th>Name</th><th>Gender</th><th>Department</th><th>Location</th><th>Email</th><th>Phone</th><th>School</th><th>Period</th><th>Status</th><th>Progress</th></tr>';
    
    foreach($attachees as $index => $attachee) {
        $progress = calculateProgress($attachee['start_date'], $attachee['end_date']);
        
        echo '<tr>';
        echo '<td>'.($index+1).'</td>';
        echo '<td>'.htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']).'</td>';
        echo '<td>'.htmlspecialchars($attachee['gender']).'</td>';
        echo '<td>'.htmlspecialchars($attachee['department_name']).'</td>';
        echo '<td>'.htmlspecialchars($attachee['location'] ?? 'N/A').'</td>';
        echo '<td>'.htmlspecialchars($attachee['email']).'</td>';
        echo '<td>'.htmlspecialchars($attachee['phone']).'</td>';
        echo '<td>'.htmlspecialchars($attachee['school']).'</td>';
        echo '<td>'.date('M Y', strtotime($attachee['start_date'])).' - '.date('M Y', strtotime($attachee['end_date'])).'</td>';
        echo '<td>'.$attachee['status'].'</td>';
        echo '<td>'.$progress.'%</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    exit();
}

function calculateProgress($start_date, $end_date) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $today = new DateTime();
    
    if ($today < $start) return 0;
    if ($today > $end) return 100;
    
    $total_days = $end->diff($start)->days;
    $days_passed = $today->diff($start)->days;
    
    return round(($days_passed / $total_days) * 100);
}

include 'includes/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <h2 class="mb-4">View All Attachees</h2>
        
        <?php if(isset($_SESSION['message'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Attachee Records</h5>
                    <div>
                        <a href="view_attachees.php?export=excel<?php echo isset($_GET['department']) ? '&department='.$_GET['department'] : ''; ?><?php echo isset($_GET['financial_year']) ? '&financial_year='.$_GET['financial_year'] : ''; ?><?php echo isset($_GET['location']) ? '&location='.$_GET['location'] : ''; ?>" class="btn btn-success btn-sm mr-2">
                            <i class="fas fa-file-excel"></i> Export to Excel
                        </a>
                        <a href="add_attachee.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add New Attachee
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <!-- Filter Form -->
                <form method="get" action="" class="mb-4">
                    <div class="row">
                        <div class="col-md-3">
                            <label for="department">Department:</label>
                            <select name="department" id="department" class="form-control">
                                <option value="">All Departments</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo (isset($_GET['department']) && $_GET['department'] == $dept['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="financial_year">Financial Year:</label>
                            <select name="financial_year" id="financial_year" class="form-control">
                                <option value="">All Years</option>
                                <?php foreach($financial_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo (isset($_GET['financial_year']) && $_GET['financial_year'] == $year) ? 'selected' : ''; ?>>
                                        FY <?php echo htmlspecialchars(getShortFinancialYear($year)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="location">Location:</label>
                            <input type="text" name="location" id="location" class="form-control" 
                                   value="<?php echo isset($_GET['location']) ? htmlspecialchars($_GET['location']) : ''; ?>" 
                                   placeholder="Enter location">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary mr-2">Filter</button>
                            <a href="view_attachees.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </div>
                </form>
                
                <div class="table-responsive">
                    <table class="table table-striped table-bordered" id="attacheesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Department</th>
                                <th>Location</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>School</th>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($attachees as $index => $attachee): 
                                $progress = calculateProgress($attachee['start_date'], $attachee['end_date']);
                                $progress_color = '';
                                if ($progress < 30) {
                                    $progress_color = 'danger';
                                } elseif ($progress < 70) {
                                    $progress_color = 'warning';
                                } else {
                                    $progress_color = 'success';
                                }
                            ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($attachee['gender']); ?></td>
                                    <td><?php echo htmlspecialchars($attachee['department_name']); ?></td>
                                    <td><?php echo htmlspecialchars($attachee['location'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($attachee['email']); ?></td>
                                    <td><?php echo htmlspecialchars($attachee['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($attachee['school']); ?></td>
                                    <td><?php echo date('M Y', strtotime($attachee['start_date'])).' - '.date('M Y', strtotime($attachee['end_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $attachee['status'] == 'Active' ? 'success' : 'warning'; ?>">
                                            <?php echo $attachee['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-<?php echo $progress_color; ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $progress; ?>%" 
                                                 aria-valuenow="<?php echo $progress; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?php echo $progress; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="edit_attachee.php?id=<?php echo $attachee['id']; ?>" class="btn btn-sm btn-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="view_attachees.php?delete=<?php echo $attachee['id']; ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this attachee?')">
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
    // Initialize DataTable
    document.addEventListener('DOMContentLoaded', function() {
        $('#attacheesTable').DataTable({
            responsive: true,
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'copy',
                    exportOptions: {
                        columns: ':not(:last-child)'
                    }
                },
                {
                    extend: 'csv',
                    exportOptions: {
                        columns: ':not(:last-child)'
                    }
                },
                {
                    extend: 'excel',
                    exportOptions: {
                        columns: ':not(:last-child)'
                    }
                },
                {
                    extend: 'pdf',
                    exportOptions: {
                        columns: ':not(:last-child)'
                    }
                },
                {
                    extend: 'print',
                    exportOptions: {
                        columns: ':not(:last-child)'
                    }
                }
            ],
            columnDefs: [
                { orderable: false, targets: -1 } // Disable sorting for actions column
            ],
            initComplete: function() {
                // Add custom filter for department
                this.api().columns([3]).every(function() {
                    var column = this;
                    var select = $('<select class="form-control form-control-sm"><option value="">All Departments</option></select>')
                        .appendTo($(column.header()))
                        .on('change', function() {
                            var val = $.fn.dataTable.util.escapeRegex($(this).val());
                            column.search(val ? '^' + val + '$' : '', true, false).draw();
                        });

                    column.data().unique().sort().each(function(d) {
                        select.append('<option value="' + d + '">' + d + '</option>');
                    });
                });

                // Add custom filter for financial year
                this.api().columns([8]).every(function() {
                    var column = this;
                    var select = $('<select class="form-control form-control-sm"><option value="">All Years</option></select>')
                        .appendTo($(column.header()).empty())
                        .on('change', function() {
                            var val = $.fn.dataTable.util.escapeRegex($(this).val());
                            column.search(val ? val : '', true, false).draw();
                        });

                    // Extract year from period format (e.g., "Jul 2022 - Jun 2023")
                    var years = [];
                    column.data().each(function(d) {
                        var year = d.split(' - ')[1].split(' ')[1];
                        if ($.inArray(year, years) === -1) {
                            years.push(year);
                        }
                    });
                    
                    years.sort();
                    $.each(years, function(i, year) {
                        select.append('<option value="' + year + '">FY ' + year + '</option>');
                    });
                });
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>