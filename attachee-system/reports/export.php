<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/db.php';

$auth = new Auth();

if(!$auth->isLoggedIn()) {
    header("Location: ../login.php");
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$type = $_GET['type'] ?? 'all';
$department_id = $_GET['department'] ?? 0;
$search = $_GET['search'] ?? '';
$school_filter = $_GET['school'] ?? '';
$financial_year_filter = $_GET['financial_year'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Validate department_id
if(!$department_id) {
    die("Department ID is required for export");
}

// Base query with department join
$query = "SELECT a.*, d.name as department_name 
          FROM attachees a 
          JOIN departments d ON a.department_id = d.id 
          WHERE a.department_id = :department_id";

$params = [':department_id' => $department_id];

// Apply status filter based on export type
if($type == 'active') {
    $query .= " AND a.status = 'Active'";
    $filename = "active_attachees_".date('Ymd').".xls";
} elseif($type == 'completed') {
    $query .= " AND a.status = 'Completed'";
    $filename = "completed_attachees_".date('Ymd').".xls";
} else {
    $filename = "all_attachees_".date('Ymd').".xls";
}

// Apply search filter
if(!empty($search)) {
    $query .= " AND (a.first_name LIKE :search OR a.last_name LIKE :search OR a.email LIKE :search OR a.school LIKE :search)";
    $params[':search'] = "%$search%";
}

// Apply school filter
if(!empty($school_filter)) {
    $query .= " AND a.school = :school";
    $params[':school'] = $school_filter;
}

// Apply financial year filter
if(!empty($financial_year_filter)) {
    $query .= " AND a.financial_year = :financial_year";
    $params[':financial_year'] = $financial_year_filter;
}

// Apply date range filter
if(!empty($date_from)) {
    $query .= " AND a.end_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if(!empty($date_to)) {
    $query .= " AND a.end_date <= :date_to";
    $params[':date_to'] = $date_to;
}

// Set ordering based on type
if($type == 'completed') {
    $query .= " ORDER BY a.end_date DESC";
} else {
    $query .= " ORDER BY a.status, a.last_name, a.first_name";
}

$stmt = $conn->prepare($query);
foreach($params as $key => &$val) {
    $stmt->bindParam($key, $val);
}
$stmt->execute();
$attachees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");

// Start Excel output with all relevant fields
echo "First Name\tLast Name\tGender\tDepartment\tEmail\tPhone\tSchool\tCourse\tStart Date\tEnd Date\tStatus\tProgress Notes\tFinancial Year\tLocation Slot\n";

foreach($attachees as $attachee) {
    echo $attachee['first_name'] . "\t";
    echo $attachee['last_name'] . "\t";
    echo $attachee['gender'] . "\t";
    echo $attachee['department_name'] . "\t";
    echo $attachee['email'] . "\t";
    echo $attachee['phone'] . "\t";
    echo $attachee['school'] . "\t";
    echo $attachee['course'] . "\t";
    echo $attachee['start_date'] . "\t";
    echo $attachee['end_date'] . "\t";
    echo $attachee['status'] . "\t";
    echo str_replace("\n", " ", $attachee['progress_notes']) . "\t";
    echo $attachee['financial_year'] . "\t";
    echo $attachee['location_slot'] . "\n";
}

exit();
?>