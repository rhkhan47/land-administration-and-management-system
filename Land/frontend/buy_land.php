<?php
require_once "config.php";

if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

// Check if land_id parameter exists
if (!isset($_GET['land_id']) || !isset($_GET['action'])) {
    header("location: user_dashboard.php");
    exit;
}

$land_id = $_GET['land_id'];
$action = $_GET['action'];
$user_id = $_SESSION["id"];

// Get land details
$land = null;
$sql = "SELECT la.*, u.name as owner_name, u.national_id as owner_nid FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.id = ?";
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

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($action == "sell") {
        // Handle sell request
        $price = trim($_POST["price"]);
        if (empty($price) || !is_numeric($price)) {
            $error = "Please enter a valid price.";
        } else {
            // Submit the sale to the blockchain
            try {
                $chaincodeLandId  = (string)$land_id;
                fabric_invoke('postForSale', [$chaincodeLandId, $price]);
            } catch (Exception $e) {
                $error = 'Error posting land for sale on blockchain: ' . $e->getMessage();
            }
            if (empty($error)) {
                // Update local DB status
                $sql = "UPDATE land_assets SET status = 'ForSale', price = ? WHERE id = ? AND owner_id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "dii", $price, $land_id, $user_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                $message = "Land listed for sale successfully!";
            }
        }
    } elseif ($action == "buy") {
        // Handle buy request
        $price = trim($_POST["price"]);
        $method = isset($_POST['method']) ? trim($_POST['method']) : '';
        $reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
        if ($method === '' || $reference === '') {
            $error = "Please provide payment method and reference.";
        }
        if (empty($error)) {
            $chaincodeLandId = (string)$land_id;
            $buyerChaincodeId = 'user' . $user_id;
            try {
                ensureUserActiveOnChain($link, $user_id);
                ensureUserActiveOnChain($link, (int)$land['owner_id']);
                try {
                    $locationObj = ['location' => $land['location'], 'area' => (float)$land['area']];
                    fabric_invoke('addLand', [$chaincodeLandId, json_encode($locationObj), "", 'user' . $land['owner_id']]);
                } catch (Exception $eAdd) {
                    if (strpos($eAdd->getMessage(), 'already exists') === false) { throw $eAdd; }
                }
                try {
                    $state = fabric_query('readLand', [$chaincodeLandId]);
                    if (!is_array($state) || !isset($state['status']) || $state['status'] !== 'ForSale') {
                        fabric_invoke('postForSale', [$chaincodeLandId, $price]);
                    }
                } catch (Exception $eQuery) {}
                fabric_invoke('buyRequest', [$chaincodeLandId, $buyerChaincodeId, $price]);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'does not exist') !== false) {
                    try {
                        $ownerChaincodeId = 'user' . $land['owner_id'];
                        try {
                            fabric_invoke('approveUser', [$ownerChaincodeId]);
                        } catch (Exception $eApprove) {
                            if (strpos($eApprove->getMessage(), 'does not exist') !== false) {
                                fabric_invoke('registerUser', [$ownerChaincodeId, $land['owner_nid'], json_encode(['name' => $land['owner_name']])]);
                                fabric_invoke('approveUser', [$ownerChaincodeId]);
                            }
                        }
                        $locationObj = ['location' => $land['location'], 'area' => (float)$land['area']];
                        try {
                            fabric_invoke('addLand', [
                                $chaincodeLandId,
                                json_encode($locationObj),
                                "",
                                $ownerChaincodeId
                            ]);
                        } catch (Exception $eAdd) {
                            if (strpos($eAdd->getMessage(), 'already exists') === false) {
                                throw $eAdd;
                            }
                        }
                        try {
                            $landState = fabric_query('readLand', [$chaincodeLandId]);
                            if (!is_array($landState) || !isset($landState['status']) || $landState['status'] !== 'ForSale') {
                                fabric_invoke('postForSale', [$chaincodeLandId, $price]);
                            }
                        } catch (Exception $eState) {}
                        fabric_invoke('buyRequest', [$chaincodeLandId, $buyerChaincodeId, $price]);
                    } catch (Exception $e2) {
                        $error = 'Error submitting buy request on blockchain: ' . $e2->getMessage();
                    }
                } elseif (strpos($msg, 'not currently for sale') !== false) {
                    try {
                        fabric_invoke('postForSale', [$chaincodeLandId, $price]);
                        fabric_invoke('buyRequest', [$chaincodeLandId, $buyerChaincodeId, $price]);
                    } catch (Exception $e3) {
                        $error = 'Error submitting buy request on blockchain: ' . $e3->getMessage();
                    }
                } elseif (strpos($msg, 'Buyer') !== false && strpos($msg, 'does not exist') !== false) {
                    // Ensure buyer exists and is approved on-chain
                    try {
                        $buyerRow = null;
                        $qB = "SELECT national_id, name, dob, permanent_address FROM users WHERE id = ?";
                        if ($stmtB = mysqli_prepare($link, $qB)) {
                            mysqli_stmt_bind_param($stmtB, "i", $user_id);
                            mysqli_stmt_execute($stmtB);
                            $resB = mysqli_stmt_get_result($stmtB);
                            $buyerRow = mysqli_fetch_assoc($resB);
                            mysqli_stmt_close($stmtB);
                        }
                        try {
                            fabric_invoke('approveUser', [$buyerChaincodeId]);
                        } catch (Exception $eApproveBuyer) {
                            if (strpos($eApproveBuyer->getMessage(), 'does not exist') !== false && $buyerRow) {
                                fabric_invoke('registerUser', [$buyerChaincodeId, $buyerRow['national_id'], json_encode(['name' => $buyerRow['name'], 'dob' => $buyerRow['dob'], 'address' => $buyerRow['permanent_address']])]);
                                fabric_invoke('approveUser', [$buyerChaincodeId]);
                            }
                        }
                        // Retry buy
                        fabric_invoke('buyRequest', [$chaincodeLandId, $buyerChaincodeId, $price]);
                    } catch (Exception $eBuyer) {
                        $error = 'Error submitting buy request on blockchain: ' . $eBuyer->getMessage();
                    }
                } else {
                    $error = 'Error submitting buy request on blockchain: ' . $msg;
                }
            }
        }
        if (empty($error)) {
            // Create a local pending transaction for admin approval
            $sql = "INSERT INTO transactions (asset_id, buyer_id, seller_id, type, status, amount) VALUES (?, ?, ?, 'Buy', 'Pending', ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "iiid", $land_id, $user_id, $land['owner_id'], $price);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    // Update land status locally
                    $sql2 = "UPDATE land_assets SET status = 'SalePending' WHERE id = ?";
                    if ($stmt2 = mysqli_prepare($link, $sql2)) {
                        mysqli_stmt_bind_param($stmt2, "i", $land_id);
                        mysqli_stmt_execute($stmt2);
                        mysqli_stmt_close($stmt2);
                    }
                    // Save payment submission
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
                    $sql3 = "INSERT INTO payments (asset_id, transaction_id, payer_id, type, amount, method, reference, proof_path, status) VALUES (?, NULL, ?, 'BuyPayment', ?, ?, ?, ?, 'Submitted')";
                    if ($stmt3 = mysqli_prepare($link, $sql3)) {
                        mysqli_stmt_bind_param($stmt3, "iidsss", $land_id, $user_id, $price, $method, $reference, $proofPath);
                        mysqli_stmt_execute($stmt3);
                        mysqli_stmt_close($stmt3);
                    }
                    $message = "Purchase request and payment submitted! Awaiting verification and approval.";
                } else {
                    $error = "Error processing purchase request.";
                    mysqli_stmt_close($stmt);
                }
            }
        }
    } elseif ($action == "cancel") {
        // Handle cancel sale request
        // Try to cancel on the blockchain; if it's already not for sale, continue locally
        try {
            $chaincodeLandId = (string)$land_id;
            fabric_invoke('cancelSale', [$chaincodeLandId]);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'not currently for sale') === false) {
                $error = 'Error cancelling sale on blockchain: ' . $msg;
            }
        }
        if (empty($error)) {
            $sql = "UPDATE land_assets SET status = 'Owned', price = NULL WHERE id = ? AND owner_id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ii", $land_id, $user_id);
                if (mysqli_stmt_execute($stmt)) {
                    $message = "Sale cancelled successfully!";
                } else {
                    $error = "Error cancelling sale.";
                }
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
    <title>
        <?php 
        if ($action == "buy") echo "Buy Land";
        elseif ($action == "sell") echo "Sell Land";
        elseif ($action == "cancel") echo "Cancel Sale";
        ?> - Land Administration System
    </title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1>
                    <?php 
                    if ($action == "buy") echo "<i class='fas fa-shopping-cart'></i> Buy Land";
                    elseif ($action == "sell") echo "<i class='fas fa-tag'></i> Sell Land";
                    elseif ($action == "cancel") echo "<i class='fas fa-times-circle'></i> Cancel Sale";
                    ?>
                </h1>
                <div class="user-info">
                    <a href="user_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </header>
        
        <div class="form-wrapper">
            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                    <p><a href="user_dashboard.php">Return to Dashboard</a></p>
                </div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($_SERVER["REQUEST_METHOD"] != "POST" || !empty($error)): ?>
                <?php if ($action == "buy"): ?>
                    <div class="land-details">
                        <h3>Land Details</h3>
                        <p><strong>Location:</strong> <?php echo $land['location']; ?></p>
                        <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                        <p><strong>Current Owner:</strong> <?php echo $land['owner_name']; ?></p>
                        <p><strong>Price:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                        <?php if (!empty($land['deed_path'])): ?>
                            <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                        <?php endif; ?>
                    </div>
                    
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="price" value="<?php echo $land['price']; ?>">
                        <div class="form-group">
                            <p>Provide payment details to submit your purchase request for ৳<?php echo number_format($land['price'], 2); ?>.</p>
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
                            <label>Proof </label>
                            <input type="file" name="proof" class="form-control" accept="image/*,application/pdf" />
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-shopping-cart"></i> Submit Purchase</button>
                            <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                        </div>
                    </form>
                
                <?php elseif ($action == "sell"): ?>
                    <div class="land-details">
                        <h3>Your Land Details</h3>
                        <p><strong>Location:</strong> <?php echo $land['location']; ?></p>
                        <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                        <?php if (!empty($land['deed_path'])): ?>
                            <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                        <?php endif; ?>
                    </div>
                    
                    <form method="post">
                        <div class="form-group">
                            <label>Sale Price (৳)</label>
                            <div class="input-group">
                                <span class="input-icon"><i class="fas fa-tag"></i></span>
                                <input type="number" step="0.01" name="price" class="form-control" value="<?php echo $land['price']; ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-tag"></i> List for Sale</button>
                            <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                        </div>
                    </form>
                
                <?php elseif ($action == "cancel"): ?>
                    <div class="land-details">
                        <h3>Land Details</h3>
                        <p><strong>Location:</strong> <?php echo $land['location']; ?></p>
                        <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                        <p><strong>Current Price:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                    </div>
                    
                    <form method="post">
                        <div class="form-group">
                            <p>Are you sure you want to cancel the sale of this land?</p>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-warning"><i class="fas fa-times-circle"></i> Cancel Sale</button>
                            <a href="user_dashboard.php" class="btn btn-default">Keep Listed</a>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
