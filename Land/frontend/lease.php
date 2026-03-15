<?php
require_once "config.php";

if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

// Check if land_id parameter exists
if (!isset($_GET['land_id'])) {
    header("location: user_dashboard.php");
    exit;
}

$land_id = $_GET['land_id'];
$user_id = $_SESSION["id"];

// Verify that the land belongs to the user 
$land = null;
$sql = "SELECT * FROM land_assets WHERE id = ? AND owner_id = ?";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $land_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $land = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

if (!$land) {
    header("location: user_dashboard.php");
    exit;
}

$monthly_rent = $duration = "";
$monthly_rent_err = $duration_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate monthly rent
    if (empty(trim($_POST["monthly_rent"])) || !is_numeric($_POST["monthly_rent"])) {
        $monthly_rent_err = "Please enter a valid monthly rent amount.";
    } else {
        $monthly_rent = trim($_POST["monthly_rent"]);
    }
    
    // Validate duration
    if (empty(trim($_POST["duration"])) || !is_numeric($_POST["duration"])) {
        $duration_err = "Please enter a valid duration in months.";
    } else {
        $duration = trim($_POST["duration"]);
    }
    
    // Check input errors before processing
    if (empty($monthly_rent_err) && empty($duration_err)) {
        // Post lease on the blockchain
        try {
            $chaincodeLandId = (string)$land_id;
            fabric_invoke('postLease', [$chaincodeLandId, $duration, $monthly_rent]);
        } catch (Exception $e) {
            echo 'Error posting lease on blockchain: ' . $e->getMessage();
        }
        // Update land status locally to indicate it's available for lease
        $sql = "UPDATE land_assets SET status = 'ForLease', price = ?, duration_months = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "dii", $monthly_rent, $duration, $land_id);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                header("location: user_dashboard.php?lease_listed=1");
            } else {
                mysqli_stmt_close($stmt);
                echo "Error processing lease listing.";
            }
        }
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lease Land - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-file-signature"></i> Lease Land</h1>
                <div class="user-info">
                    <a href="user_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </header>
        
        <div class="form-wrapper">
            <div class="land-details">
                <h3>Land Details</h3>
                <p><strong>Location:</strong> <?php echo $land['location']; ?></p>
                <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                <?php if (!empty($land['deed_path'])): ?>
                    <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                <?php endif; ?>
            </div>
            
            <form method="post">
                <div class="form-group <?php echo (!empty($monthly_rent_err)) ? 'has-error' : ''; ?>">
                    <label>Monthly Rent (৳)</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                        <input type="number" step="0.01" name="monthly_rent" class="form-control" value="<?php echo $monthly_rent; ?>">
                    </div>
                    <span class="help-block"><?php echo $monthly_rent_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($duration_err)) ? 'has-error' : ''; ?>">
                    <label>Duration (months)</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-clock"></i></span>
                        <input type="number" name="duration" class="form-control" value="<?php echo $duration; ?>">
                    </div>
                    <span class="help-block"><?php echo $duration_err; ?></span>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-file-signature"></i> List for Lease</button>
                    <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
