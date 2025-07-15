<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';

$auth = new Auth();

if(!$auth->isLoggedIn()) {
    header("Location: login.php");
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$error = '';
$success = '';

// Get departments for dropdown
$departments = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get existing location slots for suggestions
$location_slots = $conn->query("SELECT DISTINCT location_slot FROM attachees WHERE location_slot IS NOT NULL AND location_slot != '' ORDER BY location_slot")->fetchAll(PDO::FETCH_COLUMN);

// Generate financial years (current year -5 to current year +10)
$current_year = date('Y');
$current_month = date('n');
$financial_years = [];
for ($i = $current_year - 5; $i <= $current_year + 10; $i++) {
    $financial_years[] = $i . '/' . ($i + 1);
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $gender = trim($_POST['gender']);
    $department_id = trim($_POST['department_id']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $school = trim($_POST['school']);
    $course = trim($_POST['course']);
    $start_date = trim($_POST['start_date']);
    $end_date = trim($_POST['end_date']);
    $progress_notes = trim($_POST['progress_notes']);
    $location_slot = trim($_POST['location_slot']);
    $financial_year = trim($_POST['financial_year']);

    // Validate dates
    $today = date('Y-m-d');
    $status = ($end_date < $today) ? 'Completed' : 'Active';
    
    // Determine financial year if not provided
    if (empty($financial_year)) {
        $start_year = date('Y', strtotime($start_date));
        $start_month = date('n', strtotime($start_date));
        $financial_year = ($start_month >= 7) ? $start_year . '/' . ($start_year + 1) : ($start_year - 1) . '/' . $start_year;
    }
    
    try {
        $query = "INSERT INTO attachees (first_name, last_name, gender, department_id, email, phone, school, course, start_date, end_date, status, progress_notes, location_slot, financial_year) 
                  VALUES (:first_name, :last_name, :gender, :department_id, :email, :phone, :school, :course, :start_date, :end_date, :status, :progress_notes, :location_slot, :financial_year)";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':gender', $gender);
        $stmt->bindParam(':department_id', $department_id);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':school', $school);
        $stmt->bindParam(':course', $course);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':progress_notes', $progress_notes);
        $stmt->bindParam(':location_slot', $location_slot);
        $stmt->bindParam(':financial_year', $financial_year);
        
        if($stmt->execute()) {
            $success = "Attachee added successfully!";
            // Clear form
            $_POST = array();
        } else {
            $error = "Failed to add attachee. Please try again.";
        }
    } catch(PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">Add New Attachee</h4>
            </div>
            <div class="card-body">
                <?php if($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="gender" class="form-label">Gender</label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo (($_POST['gender'] ?? '') == 'Male' ? 'selected' : ''); ?>>Male</option>
                                <option value="Female" <?php echo (($_POST['gender'] ?? '') == 'Female' ? 'selected' : ''); ?>>Female</option>
                                <option value="Other" <?php echo (($_POST['gender'] ?? '') == 'Other' ? 'selected' : ''); ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="department_id" class="form-label">Department</label>
                            <select class="form-select" id="department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo (($_POST['department_id'] ?? '') == $dept['id'] ? 'selected' : ''); ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="school" class="form-label">School/Institution</label>
                            <input type="text" class="form-control" id="school" name="school" value="<?php echo htmlspecialchars($_POST['school'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="course" class="form-label">Course/Program</label>
                            <input type="text" class="form-control" id="course" name="course" value="<?php echo htmlspecialchars($_POST['course'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="financial_year" class="form-label">Financial Year</label>
                            <select class="form-select" id="financial_year" name="financial_year">
                                <option value="">Auto-detect from dates</option>
                                <?php foreach($financial_years as $year): 
                                    $is_future = (int)explode('/', $year)[1] > ($current_month >= 7 ? $current_year + 1 : $current_year);
                                ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo (($_POST['financial_year'] ?? '') == $year ? 'selected' : ''); ?>>
                                        <?php echo htmlspecialchars($year); ?>
                                        <?php if($is_future): ?> (Future)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="location_slot" class="form-label">Location Slot</label>
                            <input type="text" class="form-control" id="location_slot" name="location_slot" 
                                   value="<?php echo htmlspecialchars($_POST['location_slot'] ?? ''); ?>" 
                                   placeholder="e.g., Building A, Room 101"
                                   list="location_suggestions">
                            <datalist id="location_suggestions">
                                <?php foreach($location_slots as $slot): ?>
                                    <option value="<?php echo htmlspecialchars($slot); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="progress_notes" class="form-label">Progress Notes</label>
                        <textarea class="form-control" id="progress_notes" name="progress_notes" rows="3"><?php echo htmlspecialchars($_POST['progress_notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Add Attachee</button>
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>