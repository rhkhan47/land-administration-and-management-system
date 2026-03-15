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

// Get land details
$land = null;
$sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.id = ? AND la.status = 'ForMortgage'";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $land_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $land = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

if (!$land) {
    header("location: user_dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Submit mortgage acceptance to the blockchain
    try {
        $chaincodeLandId  = (string)$land_id;
        $mortgageeChaincodeId = 'user' . $user_id;
        fabric_invoke('acceptMortgage', [$chaincodeLandId, $mortgageeChaincodeId]);
    } catch (Exception $e) {
        $error = 'Error accepting mortgage on blockchain: ' . $e->getMessage();
    }
    if (!isset($error)) {
        // Record a mortgage transaction locally for admin approval
        $sql = "INSERT INTO transactions (asset_id, buyer_id, seller_id, type, status, amount, duration_months) VALUES (?, ?, ?, 'Mortgage', 'Pending', ?, ?)";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "iiidi", $land_id, $user_id, $land['owner_id'], $land['price'], $land['duration_months']);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                // Update land status locally
                $sql2 = "UPDATE land_assets SET status = 'MortgagePending' WHERE id = ?";
                if ($stmt2 = mysqli_prepare($link, $sql2)) {
                    mysqli_stmt_bind_param($stmt2, "i", $land_id);
                    mysqli_stmt_execute($stmt2);
                    mysqli_stmt_close($stmt2);
                }
                header("location: user_dashboard.php?mortgage_requested=1");
            } else {
                $error = "Error processing mortgage request.";
                mysqli_stmt_close($stmt);
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
    <title>Accept Mortgage - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-hand-holding-usd"></i> Accept Mortgage</h1>
                <div class="user-info">
                    <a href="user_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
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
                <p><strong>Owner:</strong> <?php echo $land['owner_name']; ?></p>
                <p><strong>Mortgage Amount:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                <p><strong>Duration:</strong> <?php echo $land['duration_months']; ?> months</p>
                <p><strong>IPFS CID:</strong> <span class="monospace"><?php echo $land['ipfs_cid']; ?></span></p>
                <p><strong>Deed Hash:</strong> <span class="monospace"><?php echo $land['deed_hash']; ?></span></p>
            </div>
            
            <form method="post">
                <div class="form-group">
                    <p>By clicking "Accept Mortgage", you agree to provide a mortgage of ৳<?php echo number_format($land['price'], 2); ?> for <?php echo $land['duration_months']; ?> months. This request will be sent to the admin for approval.</p>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-handshake"></i> Accept Mortgage</button>
                    <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>