<?php
require_once 'config.php';

class Database {
    private $host = 'localhost';
    private $user = 'root';
    private $pass = '';
    private $dbname = 'attachee_management_system';
    private $conn;

    public function __construct() {
        try {
            $this->conn = new PDO("mysql:host={$this->host};dbname={$this->dbname}", $this->user, $this->pass);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->conn;
    }
    
}
$today = date('Y-m-d');
$update_query = "UPDATE attachees SET status = 'Completed' 
                WHERE department_id = :dept_id 
                AND status = 'Active' 
                AND end_date <= :today";
?>