<?php
require_once "config.php";

if (!isLoggedIn()) {
    header("location: login.php");
    exit;
}

$user_id = $_SESSION["id"];

// Get user details
$user = null;
$sql = "SELECT * FROM users WHERE id = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

// Update profile if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $permanent_address = trim($_POST["permanent_address"]);
    
    $sql = "UPDATE users SET name = ?, email = ?, permanent_address = ? WHERE id = ?";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "sssi", $name, $email, $permanent_address, $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION["name"] = $name;
            $success = "Profile updated successfully!";
        } else {
            $error = "Error updating profile.";
        }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-user"></i> User Profile</h1>
                <div class="user-info">
                    <div class="user-welcome">
                        <i class="fas fa-user-circle"></i>
                        <span>Welcome, <?php echo $_SESSION["name"]; ?></span>
                    </div>
                    <div class="user-actions">
                        <a href="<?php echo isAdmin() ? 'admin_dashboard.php' : 'user_dashboard.php'; ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                        <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>
        
        <div class="form-wrapper">
            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label>Full Name</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-user"></i></span>
                        <input type="text" name="name" class="form-control" value="<?php echo $user['name']; ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control" value="<?php echo $user['email']; ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>National ID</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-id-card"></i></span>
                        <input type="text" class="form-control" value="<?php echo $user['national_id']; ?>" disabled>
                    </div>
                    <small class="help-block">National ID cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label>Date of Birth</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-calendar"></i></span>
                        <input type="text" class="form-control" value="<?php echo $user['dob']; ?>" disabled>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Present Address</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-home"></i></span>
                        <textarea name="permanent_address" class="form-control" rows="3" required><?php echo $user['permanent_address']; ?></textarea>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Account Status</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-info-circle"></i></span>
                        <input type="text" class="form-control" value="<?php echo $user['status']; ?>" disabled>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>User Type</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-user-tag"></i></span>
                        <input type="text" class="form-control" value="<?php echo $user['user_type']; ?>" disabled>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Registration Date</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-calendar-alt"></i></span>
                        <input type="text" class="form-control" value="<?php echo $user['created_at']; ?>" disabled>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
                    <a href="<?php echo isAdmin() ? 'admin_dashboard.php' : 'user_dashboard.php'; ?>" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>