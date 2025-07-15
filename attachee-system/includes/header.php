<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attachee Management System - Ministry of Agriculture & Livestock Kenya</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css">
</head>
<body>
    <!-- Government Banner -->
    <div class="government-banner bg-dark text-white py-2">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <img src="<?php echo BASE_URL; ?>assets/images/coat-of-arms.png" alt="Kenya Coat of Arms" height="86" width="111" class="me-2">
                    <span style="font-size: 1.5em; font-weight: bold;">Ministry of Agriculture & Livestock Development</span>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Main Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-success">
        <div class="container">
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>">
                <i class="fas fa-leaf me-2"></i>Attachee MS
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                   <li class="nav-item">
    <a class="nav-link" href="<?php echo BASE_URL; ?>index.php">
        <i class="fas fa-home me-1"></i> Dashboard
    </a>
</li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="departmentsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-building me-1"></i> Departments
                        </a>
                        <ul class="dropdown-menu">
                            <?php
                            $db = new Database();
                            $conn = $db->getConnection();
                            $query = "SELECT id, name FROM departments ORDER BY name";
                            $stmt = $conn->prepare($query);
                            $stmt->execute();
                            
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo '<li><a class="dropdown-item" href="'.BASE_URL.'departments/'.strtolower(str_replace(' ', '_', $row['name'])).'.php"><i class="fas fa-chevron-right me-1"></i>'.$row['name'].'</a></li>';
                            }
                            ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>reports/completed.php"><i class="fas fa-graduation-cap me-1"></i> Completed Attachees</a>
                    </li>
                    <!-- Add this to the navbar menu (around line 40) -->
<li class="nav-item">
    <a class="nav-link" href="<?php echo BASE_URL; ?>viewall.php">
        <i class="fas fa-users me-1"></i>View All Attachees
    </a>
</li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>add_attachee.php"><i class="fas fa-user-plus me-1"></i> Add Attachee</a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i> <?php echo $_SESSION['username'] ?? 'Guest'; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if(isset($_SESSION['user_id'])): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>profile.php"><i class="fas fa-user me-1"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>logout.php"><i class="fas fa-sign-out-alt me-1"></i> Logout</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>login.php"><i class="fas fa-sign-in-alt me-1"></i> Login</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container mt-4">