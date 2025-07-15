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

// Get current user details
$user_id = $_SESSION['user_id'];
$query = "SELECT id, username, full_name, email, phone, created_at FROM users WHERE id = :id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle profile update
if(isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    
    $query = "UPDATE users SET full_name = :full_name, email = :email, phone = :phone WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':full_name', $full_name);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':phone', $phone);
    $stmt->bindParam(':id', $user_id);
    
    if($stmt->execute()) {
        $_SESSION['message'] = "Profile updated successfully!";
        header("Location: profile.php");
        exit();
    } else {
        $_SESSION['error'] = "Failed to update profile!";
    }
}

// Handle password change
if(isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Verify current password
    $query = "SELECT password FROM users WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(password_verify($current_password, $result['password'])) {
        if($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password = :password WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':id', $user_id);
            
            if($stmt->execute()) {
                $_SESSION['message'] = "Password updated successfully!";
                header("Location: profile.php");
                exit();
            }
        } else {
            $_SESSION['error'] = "New passwords do not match!";
        }
    } else {
        $_SESSION['error'] = "Current password is incorrect!";
    }
}

include 'includes/header.php';
?>

<div class="row">
    <div class="col-md-8 mx-auto">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0"><i class="fas fa-user-circle me-2"></i>User Profile</h4>
            </div>
            <div class="card-body">
                <?php if(isset($_SESSION['message'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['message']; unset($_SESSION['message']); ?></div>
                <?php endif; ?>
                
                <?php if(isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                <?php endif; ?>
                
                <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">Profile</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">Change Password</button>
                    </li>
                </ul>
                
                <div class="tab-content" id="profileTabsContent">
                    <div class="tab-pane fade show active" id="profile" role="tabpanel">
                        <form method="POST" action="">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="created_at" class="form-label">Member Since</label>
                                <input type="text" class="form-control" id="created_at" value="<?php echo date('M d, Y', strtotime($user['created_at'])); ?>" readonly>
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-success">
                                <i class="fas fa-save me-1"></i>Update Profile
                            </button>
                        </form>
                    </div>
                    
                    <div class="tab-pane fade" id="password" role="tabpanel">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <button type="submit" name="change_password" class="btn btn-success">
                                <i class="fas fa-save me-1"></i>Update Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>