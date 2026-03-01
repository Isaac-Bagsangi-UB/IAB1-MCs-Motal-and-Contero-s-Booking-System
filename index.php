<?php 
    include "oop.php";
    $mcbs = new MCsBoookingSystem();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MC's Booking System | Home</title>
</head>
<body>
    <div class="header">
        <div>
            <h3>MC's Booking System</h3>
        </div>
        <div>
            <p>Running a transient business?</p>
            <button name="btn-register">Register</button>
            <button name="btn-login">Login</button>
        </div>
    </div> <!--so we can add a transient/unit? (not placed in srs) if not, only transients will be motal and contero only-->
    <div class="container">
        <div class=""> 
            <h2>Choose a Transient House</h2>
        </div>
        <?php
            $getTransients = "SELECT * FROM transients";
            $result = mysqli_query($mcbs->conn, $result);
            while ($row = mysqli_fetch_assoc($result)) {
            ?>
                <div class="card">
                    <h3><?php echo $row['transientName']; ?></h3>
                    <h3><?php echo $row['address'] ?></h3>
                    <a href=""><button>Choose Transient</button></a>
                </div>
            <?php
            }
        ?>
    </div>
</body>
</html>