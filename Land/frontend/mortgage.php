<?php
require_once "config.php";

// Only regular users can list their land for mortgage
if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

// Ensure a land_id is provided
if (!isset($_GET['land_id'])) {
    header("location: user_dashboard.php");
    exit;
}

$land_id = (int)$_GET['land_id'];
$user_id = $_SESSION["id"];

// Verify ownership of the land in the local DB
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

$amount = $duration = "";
$amount_err = $duration_err = "";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate amount
    if (empty(trim($_POST["amount"])) || !is_numeric($_POST["amount"])) {
        $amount_err = "Please enter a valid amount.";
    } else {
        $amount = trim($_POST["amount"]);
    }
    // Validate duration
    if (empty(trim($_POST["duration"])) || !is_numeric($_POST["duration"])) {
        $duration_err = "Please enter a valid duration in months.";
    } else {
        $duration = trim($_POST["duration"]);
    }
    // If no validation errors, post the mortgage
    if (empty($amount_err) && empty($duration_err)) {
        // First attempt to invoke the chaincode to post the mortgage
        try {
            $chaincodeLandId = (string)$land_id;
            // Escrow address parameter is unused in this example; pass empty string
            fabric_invoke('postMortgage', [$chaincodeLandId, $amount, $duration, ""]);
        } catch (Exception $e) {
            $amount_err = 'Error posting mortgage on blockchain: ' . $e->getMessage();
        }
        // Only update the local DB if blockchain call succeeded
        if (empty($amount_err) && empty($duration_err)) {
            $sql = "UPDATE land_assets SET status = 'ForMortgage', price = ?, duration_months = ? WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "dii", $amount, $duration, $land_id);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    header("location: user_dashboard.php?mortgage_listed=1");
                    exit;
                }
                mysqli_stmt_close($stmt);
            }
            $amount_err = "Error processing mortgage listing.";
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
    <title>Mortgage Land - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-hand-holding-usd"></i> Mortgage Land</h1>
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
                <p><strong>IPFS CID:</strong> <span class="monospace"><?php echo $land['ipfs_cid']; ?></span></p>
            </div>
            
            <form method="post">
                <div class="form-group <?php echo (!empty($amount_err)) ? 'has-error' : ''; ?>">
                    <label>Mortgage Amount (৳)</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-money-bill-wave"></i></span>
                        <input type="number" step="0.01" name="amount" class="form-control" value="<?php echo htmlspecialchars($amount); ?>">
                    </div>
                    <span class="help-block"><?php echo $amount_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($duration_err)) ? 'has-error' : ''; ?>">
                    <label>Duration (months)</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-clock"></i></span>
                        <input type="number" name="duration" class="form-control" value="<?php echo htmlspecialchars($duration); ?>">
                    </div>
                    <span class="help-block"><?php echo $duration_err; ?></span>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-hand-holding-usd"></i> List for Mortgage</button>
                    <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>