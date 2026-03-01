<?php 
    session_start();
    define("SERVER", "localhost");
    define("USER","root");
    define("PW","");
    define("DB","mcsbs_db");

    class MCsBoookingSystem {
        private $conn;

        function __construct() {
            return $this->conn = new mysqli(SERVER,USER,PW,DB);
        }

        function register($firstName,$lastName,$phoneNumber,$email,$password) {
            $insert = "INSERT INTO admins (firstName, lastName, phoneNumber, email, password) VALUES ('$firstName','$lastName',$phoneNumber,'$email','$password')";
            $result = mysqli_query($this->conn, $insert); 
            return $result;   
        }

        function login($email, $password) {
            $getAccount = "SELECT * FROM admins WHERE email = '$email' AND password='$password'";
            $result = mysqli_query($this->conn, $getAccount);
            if (mysqli_num_rows($result) > 0) {
                $row = mysqli_fetch_assoc($result);
                if ($email == $row['email'] AND $password == $row['password']) {
                   $_SESSION['firstName'] = $row['firstName']; 
                   $_SESSION['lastName'] = $row['lastName']; 
                   $_SESSION['email'] = $row['email']; 
                }
            } else {
                echo "
                    <script> 
                        alert('Login failed.');
                    </script>
                ";
            }
        }
    }
?>