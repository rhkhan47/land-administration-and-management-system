<?php
require_once "config.php";

// If a user is already logged in, redirect them to the appropriate dashboard
if (isLoggedIn()) {
    header("location: " . (isAdmin() ? "admin_dashboard.php" : "user_dashboard.php"));
    exit;
}

// Initialise form variables and error holders
$email = $password = "";
$email_err = $password_err = "";

// Handle the login submission.  Instead of querying a local
// MySQL database, this implementation uses the fabric-app API to
// retrieve the user's chaincode entry and validate the supplied
// password against the stored hash in the personalDetails object.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Simple email validation
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter email.";
    } else {
        $email = trim($_POST["email"]);
    }
    
    // Simple password validation
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }
    
    // Only attempt login if there are no basic validation errors
    if (empty($email_err) && empty($password_err)) {
        // Attempt to fetch the user from the blockchain via the API
        $resp = callAPI('GET', API_BASE_URL . '/api/users/' . urlencode($email));
        if ($resp && !empty($resp['ok']) && isset($resp['result'])) {
            $user = $resp['result'];
            // personalDetails is stored as JSON on the chain; decode if necessary
            $details = $user['personalDetails'];
            if (is_string($details)) {
                $decoded = json_decode($details, true);
                $details = is_array($decoded) ? $decoded : [];
            }
            $hashed_password = isset($details['password']) ? $details['password'] : null;
            $user_type       = isset($details['user_type']) ? $details['user_type'] : 'User';
            // Verify the supplied password against the stored hash
            if ($hashed_password && password_verify($password, $hashed_password)) {
                if ($user['status'] === 'Active') {
                    // Successful login: populate the session and redirect
                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"]       = $user['userId'];
                    $_SESSION["name"]     = isset($details['name']) ? $details['name'] : $user['userId'];
                    $_SESSION["email"]    = isset($details['email']) ? $details['email'] : $email;
                    $_SESSION["user_type"] = $user_type;
                    $dest = ($user_type === 'Admin') ? 'admin_dashboard.php' : 'user_dashboard.php';
                    header("location: $dest");
                    exit;
                } else {
                    $password_err = "Your account is pending approval from the Land Inspector.";
                }
            } else {
                $password_err = "The password you entered was not valid.";
            }
        } else {
            // User not found on the ledger
            $email_err = "No account found with that email.";
        }
    }
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
