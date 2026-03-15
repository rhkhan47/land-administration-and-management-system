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

// Get land and transaction details
$land = null;
$sql = "SELECT la.*, t.* FROM land_assets la 
        JOIN transactions t ON la.id = t.asset_id 
        WHERE la.id = ? AND t.buyer_id = ? AND t.type = 'Lease' AND t.status = 'Completed'";
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

// Check if lease is expired
$current_date = date('Y-m-d');
$is_expired = strtotime($current_date) > strtotime($land['end_date']);

// Check if there are any unpaid months
// We'll check the last payment date and see if the current month is paid
$last_payment_date = null;
$sql_payment = "SELECT MAX(created_at) as last_payment FROM transactions WHERE asset_id = ? AND buyer_id = ? AND type = 'LeasePayment'";
if ($stmt = mysqli_prepare($link, $sql_payment)) {
    mysqli_stmt_bind_param($stmt, "ii", $land_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $payment_data = mysqli_fetch_assoc($result);
    $last_payment_date = $payment_data['last_payment'];
    mysqli_stmt_close($stmt);
}

// Calculate if the current month is paid
$current_month_start = date('Y-m-01');
$is_current_month_paid = false;
if ($last_payment_date) {
    $last_payment_month = date('Y-m-01', strtotime($last_payment_date));
    $is_current_month_paid = ($last_payment_month >= $current_month_start);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($is_expired) {
        // Lease expired: return land to original owner
        $sql = "UPDATE land_assets SET owner_id = ?, status = 'Owned' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ii", $land['seller_id'], $land_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // Mark transaction expired locally
        $sql = "UPDATE transactions SET status = 'Expired' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $land['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("location: user_dashboard.php?lease_expired=1");
    } else {
        if (!$is_current_month_paid) {
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
                $amount = $land['monthly_payment'];
                $sql = "INSERT INTO payments (asset_id, transaction_id, payer_id, type, amount, method, reference, proof_path, status) VALUES (?, NULL, ?, 'LeasePayment', ?, ?, ?, ?, 'Submitted')";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "iidsss", $land_id, $user_id, $amount, $method, $reference, $proofPath);
                    if (mysqli_stmt_execute($stmt)) {
                        mysqli_stmt_close($stmt);
                        header("location: user_dashboard.php?lease_payment_submitted=1");
                    } else {
                        $error = "Error submitting payment.";
                        mysqli_stmt_close($stmt);
                    }
                }
            }
        } else {
            $error = "You have already paid for this month.";
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
    <title>Pay Lease - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-money-bill-wave"></i> Pay Lease</h1>
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
                <h3>Lease Details</h3>
                <p><strong>Land Location:</strong> <?php echo $land['location']; ?></p>
                <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                <p><strong>Monthly Rent:</strong> ৳<?php echo number_format($land['monthly_payment'], 2); ?></p>
                <p><strong>Duration:</strong> <?php echo $land['duration_months']; ?> months</p>
                <p><strong>Start Date:</strong> <?php echo $land['start_date']; ?></p>
                <p><strong>End Date:</strong> <?php echo $land['end_date']; ?></p>
                <?php if (!empty($land['deed_path'])): ?>
                    <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                <?php endif; ?>
                
                <?php if ($is_expired): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <p>The lease period has ended. The land will be returned to the original owner.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <p>You are making a payment for the monthly rent of ৳<?php echo number_format($land['monthly_payment'], 2); ?>.</p>
                        <?php if ($is_current_month_paid): ?>
                            <p>You have already paid for the current month.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!$is_expired && !$is_current_month_paid): ?>
                <form method="post" enctype="multipart/form-data">
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
                        <label>Proof </label>
                        <input type="file" name="proof" class="form-control" accept="image/*,application/pdf" />
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-money-bill-wave"></i> Submit Payment
                        </button>
                        <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
