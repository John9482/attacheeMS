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

// Get department info
$department_name = 'Public Communication';
$query = "SELECT * FROM departments WHERE name = :name";
$stmt = $conn->prepare($query);
$stmt->bindParam(':name', $department_name);
$stmt->execute();
$department = $stmt->fetch(PDO::FETCH_ASSOC);

// Check for attachees whose period has ended and update their status
$today = date('Y-m-d');
$update_query = "UPDATE attachees SET status = 'Completed' 
                WHERE department_id = :dept_id 
                AND status = 'Active' 
                AND end_date <= :today";
$update_stmt = $conn->prepare($update_query);
$update_stmt->bindParam(':dept_id', $department['id']);
$update_stmt->bindParam(':today', $today);
$update_stmt->execute();

// Handle location slot update
if(isset($_POST['update_location'])) {
    $attachee_id = $_POST['attachee_id'];
    $location_slot = $_POST['location_slot'];
    
    $update_query = "UPDATE attachees SET location_slot = :location_slot WHERE id = :id";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':location_slot', $location_slot);
    $update_stmt->bindParam(':id', $attachee_id);
    
    if($update_stmt->execute()) {
        $_SESSION['success_message'] = "Location slot updated successfully!";
    } else {
        $_SESSION['error_message'] = "Failed to update location slot.";
    }
    
    header("Location: public_communication.php");
    exit();
}

// Handle search and filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$school_filter = $_GET['school'] ?? '';
$financial_year_filter = $_GET['financial_year'] ?? '';
$quarter_filter = $_GET['quarter'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Base query for attachees
$query = "SELECT a.*, DATEDIFF(a.end_date, CURDATE()) as days_remaining 
          FROM attachees a 
          JOIN departments d ON a.department_id = d.id 
          WHERE d.name = :name";

// Apply filters
$params = [':name' => $department_name];

if(!empty($search)) {
    $query .= " AND (a.first_name LIKE :search OR a.last_name LIKE :search OR a.email LIKE :search OR a.school LIKE :search)";
    $params[':search'] = "%$search%";
}

if($status_filter !== 'all') {
    $query .= " AND a.status = :status";
    $params[':status'] = $status_filter;
}

if(!empty($school_filter)) {
    $query .= " AND a.school = :school";
    $params[':school'] = $school_filter;
}

if(!empty($financial_year_filter)) {
    $query .= " AND a.financial_year = :financial_year";
    $params[':financial_year'] = $financial_year_filter;
}

if(!empty($quarter_filter) && !empty($financial_year_filter)) {
    $quarters = getFinancialYearQuarters($financial_year_filter);
    if (isset($quarters[$quarter_filter])) {
        $quarter = $quarters[$quarter_filter];
        $query .= " AND a.start_date <= :quarter_end AND a.end_date >= :quarter_start";
        $params[':quarter_start'] = $quarter['start'];
        $params[':quarter_end'] = $quarter['end'];
    }
}

if(!empty($date_from)) {
    $query .= " AND a.end_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if(!empty($date_to)) {
    $query .= " AND a.end_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$query .= " ORDER BY a.status, a.end_date ASC";

// Get filtered attachees
$stmt = $conn->prepare($query);
foreach($params as $key => &$val) {
    $stmt->bindParam($key, $val);
}
$stmt->execute();
$filtered_attachees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique schools for filter dropdown
$schools_query = "SELECT DISTINCT school FROM attachees ORDER BY school";
$schools = $conn->query($schools_query)->fetchAll(PDO::FETCH_COLUMN);

// Get unique financial years for filter dropdown
$financial_years_query = "SELECT DISTINCT financial_year FROM attachees WHERE financial_year IS NOT NULL ORDER BY financial_year DESC";
$financial_years = $conn->query($financial_years_query)->fetchAll(PDO::FETCH_COLUMN);

// Get unique location slots for dropdown
$locations_query = "SELECT DISTINCT location_slot FROM attachees WHERE location_slot IS NOT NULL AND location_slot != '' ORDER BY location_slot";
$locations = $conn->query($locations_query)->fetchAll(PDO::FETCH_COLUMN);

// Get quarters for the selected financial year
$quarters = [];
if (!empty($financial_year_filter)) {
    $quarters = getFinancialYearQuarters($financial_year_filter);
}

// Separate active and completed attachees
$active_attachees = array_filter($filtered_attachees, fn($a) => $a['status'] === 'Active');
$completed_attachees = array_filter($filtered_attachees, fn($a) => $a['status'] === 'Completed');

include '../includes/header.php';
?>

<div class="row">
    <div class="col-md-12">
        <!-- Enhanced Financial Year Selector -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-calendar-range"></i> Financial Year Timeline</h5>
            </div>
            <div class="card-body">
                <div class="financial-year-slider">
                    <div class="btn-group" role="group">
                        <a href="public_communication.php" 
                           class="btn btn-outline-primary financial-year-btn <?php echo empty($financial_year_filter) ? 'active' : ''; ?>"
                           data-year="">
                            All Years
                        </a>
                        <?php foreach($financial_years as $year): 
                            // Get count of attachees for this year
                            $count_query = "SELECT COUNT(*) as count FROM attachees WHERE department_id = :dept_id AND financial_year = :year";
                            $count_stmt = $conn->prepare($count_query);
                            $count_stmt->bindParam(':dept_id', $department['id']);
                            $count_stmt->bindParam(':year', $year);
                            $count_stmt->execute();
                            $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                            
                            // Get active/completed counts
                            $status_query = "SELECT 
                                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
                                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed
                                FROM attachees 
                                WHERE department_id = :dept_id AND financial_year = :year";
                            $status_stmt = $conn->prepare($status_query);
                            $status_stmt->bindParam(':dept_id', $department['id']);
                            $status_stmt->bindParam(':year', $year);
                            $status_stmt->execute();
                            $status_counts = $status_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                            <a href="public_communication.php?financial_year=<?php echo urlencode($year); ?>" 
                               class="btn btn-outline-primary financial-year-btn <?php echo $financial_year_filter === $year ? 'active' : ''; ?>"
                               data-year="<?php echo htmlspecialchars($year); ?>"
                               data-bs-toggle="tooltip" 
                               data-bs-html="true"
                               title="<b><?php echo htmlspecialchars($year); ?></b><br>
                                      Total: <?php echo $count; ?><br>
                                      Active: <?php echo $status_counts['active']; ?><br>
                                      Completed: <?php echo $status_counts['completed']; ?>">
                                <?php echo htmlspecialchars($year); ?>
                                <span class="badge bg-light text-dark ms-1"><?php echo $count; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="department-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="bi bi-megaphone text-primary"></i> 
                        <?php echo htmlspecialchars($department['name']); ?> Department
                    </h2>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($department['description'] ?? 'Public Relations and Communication Department'); ?></p>
                </div>
                <div class="department-actions">
                    <a href="../add_attachee.php?department=<?php echo $department['id']; ?>" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Add Attachee
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search and Filter Section -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="bi bi-funnel"></i> Search & Filter</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, school...">
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="school" class="form-label">School</label>
                <select class="form-select" id="school" name="school">
                    <option value="">All Schools</option>
                    <?php foreach($schools as $school): ?>
                        <option value="<?php echo htmlspecialchars($school); ?>" <?php echo $school_filter === $school ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($school); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="financial_year" class="form-label">Financial Year</label>
                <select class="form-select" id="financial_year" name="financial_year" onchange="this.form.submit()">
                    <option value="">All Years</option>
                    <?php foreach($financial_years as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $financial_year_filter === $year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($financial_year_filter) && !empty($quarters)): ?>
            <div class="col-md-2">
                <label for="quarter" class="form-label">Quarter</label>
                <select class="form-select" id="quarter" name="quarter">
                    <option value="">All Quarters</option>
                    <?php foreach($quarters as $q => $quarter): ?>
                        <option value="<?php echo htmlspecialchars($q); ?>" <?php echo $quarter_filter === $q ? 'selected' : ''; ?>>
                            <?php echo $q; ?> (<?php echo date('M', strtotime($quarter['start'])); ?>-<?php echo date('M', strtotime($quarter['end'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label for="date_range" class="form-label">Date Range</label>
                <div class="input-group">
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" placeholder="From">
                    <span class="input-group-text">to</span>
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" placeholder="To">
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="bi bi-filter-circle"></i> Apply Filters
                </button>
                <a href="public_communication.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Department Summary -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Department Summary</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="summary-item">
                    <h6>Total Attachees</h6>
                    <p class="fs-3"><?php echo count($filtered_attachees); ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-item">
                    <h6>Active Attachees</h6>
                    <p class="fs-3 text-success"><?php echo count($active_attachees); ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-item">
                    <h6>Completed Attachees</h6>
                    <p class="fs-3 text-warning"><?php echo count($completed_attachees); ?></p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-item">
                    <h6>Current Financial Year</h6>
                    <p class="fs-3">
                        <?php 
                        $current_year = date('Y');
                        $current_month = date('n');
                        $financial_year = ($current_month >= 7) ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year;
                        echo $financial_year;
                        ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="summary-item">
                    <h6>Unique Schools</h6>
                    <p class="fs-4"><?php echo count(array_unique(array_column($filtered_attachees, 'school'))); ?></p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="summary-item">
                    <h6>Average Duration (Completed)</h6>
                    <p class="fs-4">
                        <?php
                        $durations = array_map(function($a) {
                            return (strtotime($a['end_date']) - strtotime($a['start_date'])) / (60 * 60 * 24);
                        }, $completed_attachees);
                        echo count($durations) > 0 ? round(array_sum($durations) / count($durations)) . ' days' : 'N/A';
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- All Attachees Section (shown when no specific financial year is selected) -->
<?php if(empty($financial_year_filter)): ?>
    <div id="all-years">
        <!-- Active Attachees Section -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-check"></i> Active Attachees (<?php echo count($active_attachees); ?>)</h5>
                    <div>
                        <div class="btn-group">
                            <a href="../reports/export.php?department=<?php echo $department['id']; ?>&type=active&search=<?php echo urlencode($search); ?>&school=<?php echo urlencode($school_filter); ?>&financial_year=<?php echo urlencode($financial_year_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                               class="btn btn-light btn-sm">
                                <i class="bi bi-download"></i> Export Active
                            </a>
                            <a href="../reports/export.php?department=<?php echo $department['id']; ?>&type=all&search=<?php echo urlencode($search); ?>&school=<?php echo urlencode($school_filter); ?>&financial_year=<?php echo urlencode($financial_year_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                               class="btn btn-light btn-sm">
                                <i class="bi bi-download"></i> Export All
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if(count($active_attachees) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>School</th>
                                    <th>Financial Year</th>
                                    <th>Quarter</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Location Slot</th>
                                    <th>End Date</th>
                                    <th>Days Remaining</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($active_attachees as $index => $attachee): 
                                    $quarter = getFinancialQuarterForDate($attachee['start_date']);
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['school']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['financial_year'] ?? 'N/A'); ?></td>
                                        <td><?php echo $quarter ? $quarter['quarter'] : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['email']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['phone']); ?></td>
                                        <td>
                                            <form method="POST" class="d-flex">
                                                <input type="hidden" name="attachee_id" value="<?php echo $attachee['id']; ?>">
                                                <select name="location_slot" class="form-select form-select-sm" onchange="this.form.submit()">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach($locations as $location): ?>
                                                        <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $attachee['location_slot'] === $location ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($location); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <noscript>
                                                    <button type="submit" name="update_location" class="btn btn-sm btn-primary ms-2">Update</button>
                                                </noscript>
                                            </form>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($attachee['end_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $attachee['days_remaining'] <= 0 ? 'danger' : ($attachee['days_remaining'] < 7 ? 'warning' : 'success'); ?>">
                                                <?php echo $attachee['days_remaining'] <= 0 ? 'Ended' : $attachee['days_remaining']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="../edit_attachee.php?id=<?php echo $attachee['id']; ?>" class="btn btn-primary">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <a href="../view_attachees.php?delete=<?php echo $attachee['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this attachee?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        No active attachees found in the Public Communication department.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Completed Attachees Section -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-x"></i> Completed Attachees (<?php echo count($completed_attachees); ?>)</h5>
                    <div>
                        <div class="btn-group">
                            <a href="../reports/export.php?department=<?php echo $department['id']; ?>&type=completed&search=<?php echo urlencode($search); ?>&school=<?php echo urlencode($school_filter); ?>&financial_year=<?php echo urlencode($financial_year_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                               class="btn btn-light btn-sm">
                                <i class="bi bi-download"></i> Export Completed
                            </a>
                            <a href="../reports/export.php?department=<?php echo $department['id']; ?>&type=all&search=<?php echo urlencode($search); ?>&school=<?php echo urlencode($school_filter); ?>&financial_year=<?php echo urlencode($financial_year_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                               class="btn btn-light btn-sm">
                                <i class="bi bi-download"></i> Export All
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if(count($completed_attachees) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>School</th>
                                    <th>Financial Year</th>
                                    <th>Quarter</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Location Slot</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Duration (Days)</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($completed_attachees as $index => $attachee): 
                                    $duration = (strtotime($attachee['end_date']) - strtotime($attachee['start_date'])) / (60 * 60 * 24);
                                    $quarter = getFinancialQuarterForDate($attachee['start_date']);
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['school']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['financial_year'] ?? 'N/A'); ?></td>
                                        <td><?php echo $quarter ? $quarter['quarter'] : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['email']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['location_slot'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($attachee['start_date'])); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($attachee['end_date'])); ?></td>
                                        <td><?php echo round($duration); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="../edit_attachee.php?id=<?php echo $attachee['id']; ?>" class="btn btn-primary">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <a href="../view_attachees.php?delete=<?php echo $attachee['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this attachee?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        No completed attachees found in the Public Communication department.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Financial Year Sections -->
<?php if(!empty($financial_year_filter)): ?>
    <div id="financial-year-<?php echo htmlspecialchars(str_replace('/', '-', $financial_year_filter)); ?>" class="financial-year-section">
        <?php
        // Filter attachees for this specific financial year
        $year_attachees = array_filter($filtered_attachees, function($a) use ($financial_year_filter) {
            return $a['financial_year'] === $financial_year_filter;
        });
        $year_active = array_filter($year_attachees, fn($a) => $a['status'] === 'Active');
        $year_completed = array_filter($year_attachees, fn($a) => $a['status'] === 'Completed');
        ?>
        
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h4 class="mb-0">
                    <i class="bi bi-calendar-check"></i> Financial Year: <?php echo htmlspecialchars($financial_year_filter); ?>
                    <span class="badge bg-light text-dark float-end">
                        <?php echo count($year_attachees); ?> Attachees
                        (<?php echo count($year_active); ?> Active, <?php echo count($year_completed); ?> Completed)
                    </span>
                </h4>
            </div>
            <div class="card-body">
                <!-- Quarters Overview -->
                <h5 class="mt-2"><i class="bi bi-calendar3"></i> Quarters Overview</h5>
                <div class="row mb-4">
                    <?php 
                    $quarters = getFinancialYearQuarters($financial_year_filter);
                    foreach ($quarters as $q => $quarter): 
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as count 
                            FROM attachees 
                            WHERE department_id = ? 
                            AND financial_year = ?
                            AND start_date <= ? 
                            AND end_date >= ?
                        ");
                        $stmt->execute([$department['id'], $financial_year_filter, $quarter['end'], $quarter['start']]);
                        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    ?>
                        <div class="col-md-3 mb-3">
                            <div class="card h-100 <?php echo $quarter_filter === $q ? 'border-primary' : ''; ?>">
                                <div class="card-header">
                                    <h6 class="mb-0"><?php echo $quarter['name']; ?> (<?php echo $q; ?>)</h6>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><small><?php echo date('M j', strtotime($quarter['start'])); ?> - <?php echo date('M j, Y', strtotime($quarter['end'])); ?></small></p>
                                    <h4 class="text-center"><?php echo $count; ?> attachees</h4>
                                    <div class="text-center">
                                        <a href="public_communication.php?financial_year=<?php echo urlencode($financial_year_filter); ?>&quarter=<?php echo $q; ?>" class="btn btn-sm btn-outline-primary">
                                            View Quarter
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Active Attachees for this financial year -->
                <h5 class="mt-3"><i class="bi bi-person-check"></i> Active Attachees (<?php echo count($year_active); ?>)</h5>
                <?php if(count($year_active) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>School</th>
                                    <th>Quarter</th>
                                    <th>Location</th>
                                    <th>End Date</th>
                                    <th>Days Left</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($year_active as $index => $attachee): 
                                    $quarter = getFinancialQuarterForDate($attachee['start_date']);
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['school']); ?></td>
                                        <td><?php echo $quarter ? $quarter['quarter'] : 'N/A'; ?></td>
                                        <td>
                                            <form method="POST" class="d-flex">
                                                <input type="hidden" name="attachee_id" value="<?php echo $attachee['id']; ?>">
                                                <select name="location_slot" class="form-select form-select-sm" onchange="this.form.submit()">
                                                    <option value="">-- Select --</option>
                                                    <?php foreach($locations as $location): ?>
                                                        <option value="<?php echo htmlspecialchars($location); ?>" <?php echo $attachee['location_slot'] === $location ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($location); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <noscript>
                                                    <button type="submit" name="update_location" class="btn btn-sm btn-primary ms-2">Update</button>
                                                </noscript>
                                            </form>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($attachee['end_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $attachee['days_remaining'] <= 0 ? 'danger' : ($attachee['days_remaining'] < 7 ? 'warning' : 'success'); ?>">
                                                <?php echo $attachee['days_remaining'] <= 0 ? 'Ended' : $attachee['days_remaining']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="../edit_attachee.php?id=<?php echo $attachee['id']; ?>" class="btn btn-primary">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <a href="../view_attachees.php?delete=<?php echo $attachee['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this attachee?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">No active attachees for this financial year.</div>
                <?php endif; ?>
                
                <!-- Completed Attachees for this financial year -->
                <h5 class="mt-4"><i class="bi bi-person-x"></i> Completed Attachees (<?php echo count($year_completed); ?>)</h5>
                <?php if(count($year_completed) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>School</th>
                                    <th>Quarter</th>
                                    <th>Location</th>
                                    <th>Duration</th>
                                    <th>End Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($year_completed as $index => $attachee): 
                                    $duration = (strtotime($attachee['end_date']) - strtotime($attachee['start_date'])) / (60 * 60 * 24);
                                    $quarter = getFinancialQuarterForDate($attachee['start_date']);
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['first_name'].' '.$attachee['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($attachee['school']); ?></td>
                                        <td><?php echo $quarter ? $quarter['quarter'] : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($attachee['location_slot'] ?? 'N/A'); ?></td>
                                        <td><?php echo round($duration); ?> days</td>
                                        <td><?php echo date('M j, Y', strtotime($attachee['end_date'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="../edit_attachee.php?id=<?php echo $attachee['id']; ?>" class="btn btn-primary">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                                <a href="../view_attachees.php?delete=<?php echo $attachee['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this attachee?')">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">No completed attachees for this financial year.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
    .department-header {
        background-color: #f8f9fa;
        padding: 1.5rem;
        border-radius: 0.5rem;
        border-left: 5px solid #0d6efd;
    }
    
    .summary-item {
        background-color: #f8f9fa;
        padding: 1rem;
        border-radius: 0.5rem;
        height: 100%;
    }
    
    .summary-item h6 {
        color: #6c757d;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .table th {
        white-space: nowrap;
    }
    
    .btn-group-sm .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
    
    .financial-year-slider {
        overflow-x: auto;
        white-space: nowrap;
        padding-bottom: 10px;
    }
    
    .financial-year-slider .btn-group {
        display: inline-flex;
    }
    
    .financial-year-btn {
        margin-right: 5px;
        min-width: 100px;
    }
    
    .financial-year-section {
        margin-bottom: 30px;
        scroll-margin-top: 100px;
    }
    
    .financial-year-section h5 {
        border-bottom: 1px solid #dee2e6;
        padding-bottom: 8px;
    }
</style>

<script>
// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Highlight current financial year on page load
    const currentYear = "<?php echo $financial_year_filter; ?>";
    if (currentYear) {
        const activeBtn = document.querySelector(`.financial-year-btn[data-year="${currentYear}"]`);
        if (activeBtn) {
            activeBtn.classList.add('active');
            setTimeout(() => {
                activeBtn.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            }, 300);
        }
    } else {
        // Highlight "All Years" button if no specific year is selected
        const allYearsBtn = document.querySelector('.financial-year-btn[data-year=""]');
        if (allYearsBtn) {
            allYearsBtn.classList.add('active');
        }
    }
});

// Auto-refresh the page every 5 minutes
setTimeout(function(){
    window.location.reload();
}, 300000);
</script>

<?php include '../includes/footer.php'; ?>