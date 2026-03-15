<?php
require_once "config.php";

if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

// Check if parameters exist
if (!isset($_GET['action']) || !isset($_GET['land_id'])) {
    header("location: user_dashboard.php");
    exit;
}

$action = $_GET['action'];
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

// Determine the current status and what status to revert to
$current_status = $land['status'];
$new_status = 'Owned'; // Default revert status

if ($action == 'sale' && $current_status == 'ForSale') {
    $new_status = 'Owned';
} elseif ($action == 'mortgage' && $current_status == 'ForMortgage') {
    $new_status = 'Owned';
} elseif ($action == 'lease' && $current_status == 'ForLease') {
    $new_status = 'Owned';
} else {
    // Invalid operation
    header("location: user_dashboard.php");
    exit;
}

// Process cancellation
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["confirm"]) && $_POST["confirm"] == "yes") {
        // When cancelling a sale, also cancel on the blockchain
        if ($action == 'sale' && $current_status == 'ForSale') {
            try {
                $chaincodeLandId = (string)$land_id;
                fabric_invoke('cancelSale', [$chaincodeLandId]);
            } catch (Exception $e) {
                $error = 'Error cancelling sale on blockchain: ' . $e->getMessage();
            }
        }
        if (!isset($error)) {
            // Update land status locally
            $sql = "UPDATE land_assets SET status = ? WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "si", $new_status, $land_id);
                if (mysqli_stmt_execute($stmt)) {
                    // Also remove pending transactions for this land
                    $sql2 = "DELETE FROM transactions WHERE asset_id = ? AND status = 'Pending'";
                    if ($stmt2 = mysqli_prepare($link, $sql2)) {
                        mysqli_stmt_bind_param($stmt2, "i", $land_id);
                        mysqli_stmt_execute($stmt2);
                        mysqli_stmt_close($stmt2);
                    }
                    header("location: user_dashboard.php?cancel_success=1");
                } else {
                    $error = "Error cancelling operation.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    } else {
        header("location: user_dashboard.php");
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Operation - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-times-circle"></i> Cancel Operation</h1>
                <div class="user-info">
                    <div class="user-welcome">
                        <i class="fas fa-user-circle"></i>
                        <span>Welcome, <?php echo $_SESSION["name"]; ?></span>
                    </div>
                    <div class="user-actions">
                        <a href="user_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                        <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>
        
        <div class="form-wrapper">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="land-details">
                <h3>Land Details</h3>
                <p><strong>Location:</strong> <?php echo $land['location']; ?></p>
                <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                <p><strong>Current Status:</strong> <span class="status-<?php echo strtolower($current_status); ?>"><?php echo $current_status; ?></span></p>
                <p><strong>IPFS CID:</strong> <span class="monospace"><?php echo $land['ipfs_cid']; ?></span></p>
                <p><strong>Deed Hash:</strong> <span class="monospace"><?php echo $land['deed_hash']; ?></span></p>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Are you sure you want to cancel this operation?</h3>
                <p>This will remove your land from the <?php echo $action; ?> market and revert its status to <?php echo $new_status; ?>.</p>
            </div>
            
            <form method="post">
                <div class="form-group">
                    <div class="confirmation-options">
                        <label class="confirmation-option">
                            <input type="radio" name="confirm" value="yes" required>
                            <span>Yes, cancel the <?php echo $action; ?> operation</span>
                        </label>
                        <label class="confirmation-option">
                            <input type="radio" name="confirm" value="no">
                            <span>No, keep the land listed for <?php echo $action; ?></span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-times-circle"></i> Confirm Cancellation</button>
                    <a href="user_dashboard.php" class="btn btn-default">Go Back</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>