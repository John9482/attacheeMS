<?php
require_once 'db.php';

class Auth {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    // Register new admin user
    public function register($username, $email, $password) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, email, password) VALUES (:username, :email, :password)";
        $stmt = $this->db->prepare($query);
        
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', $hashed_password);
        
        return $stmt->execute();
    }

    // Login user
    public function login($username, $password) {
        $query = "SELECT id, username, password FROM users WHERE username = :username";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if($stmt->rowCount() == 1) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if(password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                return true;
            }
        }
        return false;
    }

    // Check if user is logged in
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    // Logout user
    public function logout() {
        session_destroy();
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
        return true;
    }
}
?>