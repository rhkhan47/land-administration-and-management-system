<?php
require_once "config.php";

// Only regular users may repay mortgages
if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

// Land identifier must be supplied
if (!isset($_GET['land_id'])) {
    header("location: user_dashboard.php");
    exit;
}

$land_id = (int)$_GET['land_id'];
$user_id = $_SESSION["id"];

// Retrieve land and transaction details
$land = null;
$sql = "SELECT la.*, t.* FROM land_assets la 
        JOIN transactions t ON la.id = t.asset_id 
        WHERE la.id = ? AND t.seller_id = ? AND t.type = 'Mortgage' AND t.status = 'Completed'";
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $land_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $land = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

if (!$land) {
    // No active mortgage found
    header("location: user_dashboard.php");
    exit;
}

// Determine if mortgage period has expired
$current_date = date('Y-m-d');
$is_expired = strtotime($current_date) > strtotime($land['end_date']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($is_expired) {
        // Mortgage expired: transfer ownership to buyer (mortgagee) locally
        $sql = "UPDATE land_assets SET owner_id = ?, status = 'Owned' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $land['buyer_id'], $land_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // Update transaction status to expired
        $sql = "UPDATE transactions SET status = 'Expired' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $land['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("location: user_dashboard.php?mortgage_expired=1");
    } else {
        $method = isset($_POST['method']) ? trim($_POST['method']) : '';
        $reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
        if ($method === '' || $reference === '') {
            $error = "Please provide payment method and reference.";
        } else {
            $proofPath = null;
            if (!empty($_FILES['proof']['name'])) {
                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $basename = basename($_FILES['proof']['name']);
                $target = $uploadDir . DIRECTORY_SEPARATOR . time() . '_' . $user_id . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $basename);
                if (move_uploaded_file($_FILES['proof']['tmp_name'], $target)) {
                    $proofPath = 'uploads/' . basename($target);
                }
            }
            $amount = $land['amount'];
            $sql = "INSERT INTO payments (asset_id, transaction_id, payer_id, type, amount, method, reference, proof_path, status) VALUES (?, ?, ?, 'MortgageRepayment', ?, ?, ?, ?, 'Submitted')";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "iiidsss", $land_id, $land['id'], $user_id, $amount, $method, $reference, $proofPath);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    header("location: user_dashboard.php?mortgage_payment_submitted=1");
                } else {
                    $error = "Error submitting payment.";
                    mysqli_stmt_close($stmt);
                }
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
    <title>Pay Mortgage - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-credit-card"></i> Pay Mortgage</h1>
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
                <h3>Mortgage Details</h3>
                <p><strong>Land Location:</strong> <?php echo $land['location']; ?></p>
                <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                <p><strong>Mortgage Amount:</strong> ৳<?php echo number_format($land['amount'], 2); ?></p>
                <p><strong>Duration:</strong> <?php echo $land['duration_months']; ?> months</p>
                <p><strong>Start Date:</strong> <?php echo $land['start_date']; ?></p>
                <p><strong>End Date:</strong> <?php echo $land['end_date']; ?></p>
                <?php if (!empty($land['deed_path'])): ?>
                    <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                <?php endif; ?>
                <?php if ($is_expired): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Mortgage Expired</h3>
                        <p>The mortgage period has ended. Ownership will be transferred to the temporary owner.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <p>You have until <?php echo $land['end_date']; ?> to repay the mortgage amount of ৳<?php echo number_format($land['amount'], 2); ?>.</p>
                    </div>
                <?php endif; ?>
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <?php if ($is_expired): ?>
                        <p>Click confirm to transfer ownership to the temporary owner.</p>
                    <?php else: ?>
                        <p>Submit repayment details to request mortgage payoff verification.</p>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="method" class="form-control" required>
                        <option value="">Select</option>
                        <option value="MobileWallet">Mobile Wallet</option>
                        <option value="BankTransfer">Bank Transfer</option>
                        
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Reference</label>
                    <input type="text" name="reference" class="form-control" required />
                </div>
                <div class="form-group">
                    <label>Proof</label>
                    <input type="file" name="proof" class="form-control" accept="image/*,application/pdf" />
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <?php if ($is_expired): ?>
                            <i class="fas fa-exchange-alt"></i> Confirm Ownership Transfer
                        <?php else: ?>
                            <i class="fas fa-credit-card"></i> Submit Repayment
                        <?php endif; ?>
                    </button>
                    <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
