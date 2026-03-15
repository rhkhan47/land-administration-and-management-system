<?php
require_once "config.php";

// Redirect logged in users to their dashboard
if (isLoggedIn()) {
    header("location: " . (isAdmin() ? "admin_dashboard.php" : "user_dashboard.php"));
    exit;
}

$national_id = $email = $password = $confirm_password = $permanent_address = $gender = "";
$national_id_err = $email_err = $password_err = $confirm_password_err = $permanent_address_err = $gender_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate national ID
    if (empty(trim($_POST["national_id"]))) {
        $national_id_err = "Please enter your National ID.";
    } else {
        $national_id = trim($_POST["national_id"]);
        // Ensure NID exists from Authority pre-entry
        $sql = "SELECT id, name, dob, email FROM users WHERE national_id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_nid);
            $param_nid = $national_id;
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                $row = mysqli_fetch_assoc($result);
                if (!$row) {
                    $national_id_err = "National ID not found. Please contact Authority.";
                } elseif (!empty($row['email'])) {
                    $national_id_err = "This National ID is already registered.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    // Validate email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter an email.";
    } else {
        $email = trim($_POST["email"]);
        // Check if email already exists
        $sql = "SELECT id FROM users WHERE email = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_email);
            $param_email = $email;
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) > 0) {
                    $email_err = "This email is already registered.";
                }
            } else {
                echo "Oops! Something went wrong. Please try again later.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    // Validate permanent address
    if (empty(trim($_POST["permanent_address"]))) {
        $permanent_address_err = "Please enter your permanent address.";
    } else {
        $permanent_address = trim($_POST["permanent_address"]);
    }
    // Validate gender (required: Male or Female)
    if (!isset($_POST['gender']) || trim($_POST['gender']) === '') {
        $gender_err = "Please select your gender.";
    } else {
        $gender = trim($_POST['gender']);
        $allowed = ['Male','Female'];
        if (!in_array($gender, $allowed, true)) {
            $gender_err = "Invalid gender selection.";
        }
    }
    // Validate password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }
    // Validate confirm password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Password did not match.";
        }
    }
    // If no errors, insert into database and register on blockchain
    if (empty($national_id_err) && empty($email_err) && empty($password_err) && empty($confirm_password_err) && empty($permanent_address_err) && empty($gender_err)) {
        // Update existing user record with email/password/address; keep name/dob from Authority entry
        $sql = "UPDATE users SET email = ?, password = ?, permanent_address = ?, gender = ?, status = 'Pending' WHERE national_id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            mysqli_stmt_bind_param($stmt, "sssss", $email, $hashed, $permanent_address, $gender, $national_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                // Fetch user id and details for chaincode registration
                $sql2 = "SELECT id, name, dob FROM users WHERE national_id = ?";
                if ($stmt2 = mysqli_prepare($link, $sql2)) {
                    mysqli_stmt_bind_param($stmt2, "s", $national_id);
                    mysqli_stmt_execute($stmt2);
                    $res2 = mysqli_stmt_get_result($stmt2);
                    $row2 = mysqli_fetch_assoc($res2);
                    mysqli_stmt_close($stmt2);
                    if ($row2) {
                        try {
                            $personalDetails = [
                                'name' => $row2['name'],
                                'email' => $email,
                                'dob' => $row2['dob'],
                                'permanent_address' => $permanent_address,
                                'gender' => $gender
                            ];
                            $chaincodeUserId = 'user' . $row2['id'];
                            fabric_invoke('registerUser', [$chaincodeUserId, $national_id, json_encode($personalDetails)]);
                        } catch (Exception $e) {
                            error_log('Error registering user on blockchain: ' . $e->getMessage());
                        }
                    }
                }
                header("location: login.php?registered=1");
                exit;
            } else {
                mysqli_stmt_close($stmt);
                echo "Something went wrong. Please try again later.";
            }
        }
        mysqli_close($link);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="form-wrapper">
            <div class="form-header">
                <h2><i class="fas fa-user-plus"></i> User Registration</h2>
                <p>Enter NID to link your pre-entered record, then complete.</p>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group <?php echo (!empty($national_id_err)) ? 'has-error' : ''; ?>">
                    <label>National ID</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-id-card"></i></span>
                        <input type="text" name="national_id" class="form-control" value="<?php echo htmlspecialchars($national_id); ?>">
                    </div>
                    <span class="help-block"><?php echo $national_id_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($email_err)) ? 'has-error' : ''; ?>">
                    <label>Email</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>">
                    </div>
                    <span class="help-block"><?php echo $email_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($permanent_address_err)) ? 'has-error' : ''; ?>">
                    <label>Permanent Address</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-home"></i></span>
                        <textarea name="permanent_address" class="form-control" rows="3"><?php echo htmlspecialchars($permanent_address); ?></textarea>
                    </div>
                    <span class="help-block"><?php echo $permanent_address_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($gender_err)) ? 'has-error' : ''; ?>">
                    <label>Gender</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-venus-mars"></i></span>
                        <select name="gender" class="form-control" required>
                            <option value="" disabled <?php echo $gender==='' ? 'selected' : ''; ?>>Select</option>
                            <option value="Male" <?php echo $gender==='Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo $gender==='Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <span class="help-block"><?php echo $gender_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($password_err)) ? 'has-error' : ''; ?>">
                    <label>Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" value="<?php echo htmlspecialchars($password); ?>">
                    </div>
                    <span class="help-block"><?php echo $password_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($confirm_password_err)) ? 'has-error' : ''; ?>">
                    <label>Confirm Password</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="confirm_password" class="form-control" value="<?php echo htmlspecialchars($confirm_password); ?>">
                    </div>
                    <span class="help-block"><?php echo $confirm_password_err; ?></span>
                </div>
                <div class="form-group">
                    <input type="submit" class="btn btn-primary" value="Register">
                    <input type="reset" class="btn btn-default" value="Reset">
                </div>
                <div class="form-footer">
                    <p>Already have an account? <a href="login.php">Login here</a>.</p>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
