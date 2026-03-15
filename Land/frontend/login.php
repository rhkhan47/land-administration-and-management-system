<?php
require_once "config.php";

if (isLoggedIn()) {
    header("location: " . (isAdmin() ? "admin_dashboard.php" : (isAuthority() ? "authority_dashboard.php" : "user_dashboard.php")));
    exit;
}

$national_id = $email = $password = "";
$national_id_err = $email_err = $password_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty(trim($_POST["national_id"]))) {
        $national_id_err = "Please enter National ID.";
    } else {
        $national_id = trim($_POST["national_id"]);
    }
    
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter email.";
    } else {
        $email = trim($_POST["email"]);
    }
    
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    if (empty($national_id_err) && empty($email_err) && empty($password_err)) {
        $sql = "SELECT id, name, email, password, status, user_type FROM users WHERE email = ? AND national_id = ?";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ss", $param_email, $param_nid);
            $param_email = $email;
            $param_nid = $national_id;
            
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    mysqli_stmt_bind_result($stmt, $id, $name, $email, $hashed_password, $status, $user_type);
                    if (mysqli_stmt_fetch($stmt)) {
                        if (password_verify($password, $hashed_password)) {
                            if ($status == "Active") {
                                session_start();
                                
                                $_SESSION["loggedin"] = true;
                                $_SESSION["id"] = $id;
                                $_SESSION["name"] = $name;
                                $_SESSION["email"] = $email;
                                $_SESSION["user_type"] = $user_type;
                                
                                if ($user_type == "Admin") {
                                    header("location: admin_dashboard.php");
                                } elseif ($user_type == "Authority") {
                                    header("location: authority_dashboard.php");
                                } else {
                                    header("location: user_dashboard.php");
                                }
                            } else {
                                $password_err = "Your account is pending approval from the Land Inspector.";
                            }
                        } else {
                            $password_err = "The password you entered was not valid.";
                        }
                    }
                } else {
                    $email_err = "No account found with that email and NID.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <div class="form-wrapper">
            <h2>Login</h2>
            <p>Please fill in your credentials to login.</p>
            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    Registration successful! Please wait for admin approval.
                </div>
            <?php endif; ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group <?php echo (!empty($national_id_err)) ? 'has-error' : ''; ?>">
                    <label>National ID</label>
                    <input type="text" name="national_id" class="form-control" value="<?php echo htmlspecialchars($national_id); ?>">
                    <span class="help-block"><?php echo $national_id_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($email_err)) ? 'has-error' : ''; ?>">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo $email; ?>">
                    <span class="help-block"><?php echo $email_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($password_err)) ? 'has-error' : ''; ?>">
                    <label>Password</label>
                    <input type="password" name="password" class="form-control">
                    <span class="help-block"><?php echo $password_err; ?></span>
                </div>
                <div class="form-group">
                    <input type="submit" class="btn btn-primary" value="Login">
                </div>
                <p>Don't have an account? <a href="register.php">Sign up now</a>.</p>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
