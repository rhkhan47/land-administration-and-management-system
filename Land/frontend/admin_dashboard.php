<?php
require_once "config.php";

if (!isLoggedIn() || !isAdmin()) {
    header("location: login.php");
    exit;
}

// Determine active tab (server-side fallback)
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending-users';

// Get pending users
$pending_users = [];
$sql = "SELECT id, name, national_id, email, dob, permanent_address, created_at FROM users WHERE status = 'Pending'";
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $pending_users[] = $row;
    }
    mysqli_free_result($result);
}

// Get non-pending land assets
$land_assets = [];
$sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.status <> 'Pending'";
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $land_assets[] = $row;
    }
    mysqli_free_result($result);
}

// Get pending lands
$pending_lands = [];
$sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.status = 'Pending'";
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $pending_lands[] = $row;
    }
    mysqli_free_result($result);
}

// Get government property (approved or processed)
$government_lands = [];
$sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.government_property = 1 AND la.status <> 'Pending'";
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $government_lands[] = $row;
    }
    mysqli_free_result($result);
}

// Get pending transactions
$pending_transactions = [];
$sql = "SELECT t.*, la.location as land_location, seller.name as seller_name, buyer.name as buyer_name 
        FROM transactions t 
        JOIN land_assets la ON t.asset_id = la.id 
        LEFT JOIN users seller ON t.seller_id = seller.id 
        LEFT JOIN users buyer ON t.buyer_id = buyer.id 
        WHERE t.status = 'Pending'";
if ($result = mysqli_query($link, $sql)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $pending_transactions[] = $row;
    }
    mysqli_free_result($result);
}

// Handle user approval
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["approve_user"])) {
        $user_id = (int)$_POST["user_id"];
        // Approve in the local DB
        $sql = "UPDATE users SET status = 'Active' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // Approve on the blockchain, registering first if needed
        $chaincodeUserId = 'user' . $user_id;
        try {
            fabric_invoke('approveUser', [$chaincodeUserId]);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'does not exist') !== false) {
                // Fetch details and register, then approve
                $userRow = null;
                $q = "SELECT national_id, name, dob, permanent_address FROM users WHERE id = ?";
                if ($stmt2 = mysqli_prepare($link, $q)) {
                    mysqli_stmt_bind_param($stmt2, "i", $user_id);
                    mysqli_stmt_execute($stmt2);
                    $res = mysqli_stmt_get_result($stmt2);
                    $userRow = mysqli_fetch_assoc($res);
                    mysqli_stmt_close($stmt2);
                }
                if ($userRow) {
                    try {
                        fabric_invoke('registerUser', [$chaincodeUserId, $userRow['national_id'], json_encode(['name' => $userRow['name'], 'dob' => $userRow['dob'], 'address' => $userRow['permanent_address']])]);
                        fabric_invoke('approveUser', [$chaincodeUserId]);
                    } catch (Exception $e2) {
                        error_log('Error registering/approving user on blockchain: ' . $e2->getMessage());
                    }
                }
            } else {
                error_log('Error approving user on blockchain: ' . $msg);
            }
        }
        header("location: admin_dashboard.php");
    } elseif (isset($_POST["reject_user"])) {
        $user_id = (int)$_POST["user_id"];
        $sql = "UPDATE users SET status = 'Rejected' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // Note: there is no chaincode concept of rejection; we simply leave the user unapproved.
        header("location: admin_dashboard.php");
    } elseif (isset($_POST["approve_land"])) {
        $land_id = (int)$_POST["land_id"];
        // Determine if land is government property and whether it has a price
        $land = null;
        $q = "SELECT government_property, price FROM land_assets WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $q)) {
            mysqli_stmt_bind_param($stmt, "i", $land_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $land = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }
        $newStatus = 'Owned';
        if ($land && (int)$land['government_property'] === 1 && $land['price'] !== null) {
            $newStatus = 'ForSale';
        }
        // Approve in the local DB with computed status
        $sql = "UPDATE land_assets SET status = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "si", $newStatus, $land_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // Approve on the blockchain
        try {
            $chaincodeLandId = (string)$land_id;
            fabric_invoke('approveLand', [$chaincodeLandId]);
            // If government property and price exists, post for sale on-chain
            if ($newStatus === 'ForSale') {
                // Re-fetch price to be safe
                $priceVal = null;
                $q2 = "SELECT price FROM land_assets WHERE id = ?";
                if ($stmt2 = mysqli_prepare($link, $q2)) {
                    mysqli_stmt_bind_param($stmt2, "i", $land_id);
                    mysqli_stmt_execute($stmt2);
                    $res2 = mysqli_stmt_get_result($stmt2);
                    if ($row2 = mysqli_fetch_assoc($res2)) { $priceVal = $row2['price']; }
                    mysqli_stmt_close($stmt2);
                }
                if ($priceVal !== null) {
                    fabric_invoke('postForSale', [$chaincodeLandId, (string)$priceVal]);
                }
            }
        } catch (Exception $e) {
            error_log('Error approving land on blockchain: ' . $e->getMessage());
        }
        header("location: admin_dashboard.php");
    } elseif (isset($_POST["reject_land"])) {
        $land_id = (int)$_POST["land_id"];
        // Reject locally; there is no chaincode reject
        $sql = "UPDATE land_assets SET status = 'Rejected' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $land_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("location: admin_dashboard.php");
    } elseif (isset($_POST["approve_transaction"])) {
        $transaction_id = (int)$_POST["transaction_id"];
        // Get transaction details
        $sql = "SELECT * FROM transactions WHERE id = ?";
        $transaction = null;
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $transaction_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $transaction = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
        }
        if ($transaction) {
            $assetId           = (int)$transaction['asset_id'];
            $buyerId           = (int)$transaction['buyer_id'];
            $sellerId          = (int)$transaction['seller_id'];
            $durationMonths    = isset($transaction['duration_months']) ? (int)$transaction['duration_months'] : 0;
            $amount            = isset($transaction['amount']) ? (float)$transaction['amount'] : 0;
            $assetChaincodeId  = (string)$assetId;
            $buyerChaincodeId  = 'user' . $buyerId;
            // Determine the type and act accordingly
            if ($transaction['type'] == 'Buy') {
                // Confirm sale on blockchain
                try {
                    fabric_invoke('confirmSale', [$assetChaincodeId]);
                } catch (Exception $e) {
                    error_log('Error confirming sale on blockchain: ' . $e->getMessage());
                }
                if ($amount === 0.0) {
                    $currentPrice = null;
                    $q = "SELECT price FROM land_assets WHERE id = ?";
                    if ($stmt = mysqli_prepare($link, $q)) {
                        mysqli_stmt_bind_param($stmt, "i", $assetId);
                        mysqli_stmt_execute($stmt);
                        $res = mysqli_stmt_get_result($stmt);
                        if ($row = mysqli_fetch_assoc($res)) { $currentPrice = $row['price']; }
                        mysqli_stmt_close($stmt);
                    }
                    if ($currentPrice !== null) {
                        $u = "UPDATE transactions SET amount = ? WHERE id = ?";
                        if ($stmt = mysqli_prepare($link, $u)) {
                            mysqli_stmt_bind_param($stmt, "di", $currentPrice, $transaction_id);
                            mysqli_stmt_execute($stmt);
                            mysqli_stmt_close($stmt);
                        }
                    }
                }
                // Update land ownership locally
                $sql = "UPDATE land_assets SET owner_id = ?, status = 'Owned', price = NULL WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "ii", $buyerId, $assetId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                // Mark transaction complete
                $sql = "UPDATE transactions SET status = 'Completed' WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "i", $transaction_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            } elseif ($transaction['type'] == 'Mortgage') {
                // Accept mortgage on blockchain
                try {
                    fabric_invoke('acceptMortgage', [$assetChaincodeId, $buyerChaincodeId]);
                } catch (Exception $e) {
                    error_log('Error accepting mortgage on blockchain: ' . $e->getMessage());
                }
                // Locally mark land as mortgaged and set temporary owner
                $sql = "UPDATE land_assets SET owner_id = ?, status = 'Mortgaged' WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "ii", $buyerId, $assetId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                // Set start and end dates
                $end_date = date('Y-m-d', strtotime('+' . $durationMonths . ' months'));
                $sql = "UPDATE transactions SET status = 'Completed', start_date = CURDATE(), end_date = ? WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "si", $end_date, $transaction_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            } elseif ($transaction['type'] == 'Lease') {
                // Accept lease on blockchain
                try {
                    fabric_invoke('acceptLease', [$assetChaincodeId, $buyerChaincodeId]);
                } catch (Exception $e) {
                    error_log('Error accepting lease on blockchain: ' . $e->getMessage());
                }
                // Locally mark land as leased and change owner temporarily
                $sql = "UPDATE land_assets SET owner_id = ?, status = 'Leased' WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "ii", $buyerId, $assetId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                // Set start and end dates
                $end_date = date('Y-m-d', strtotime('+' . $durationMonths . ' months'));
                $sql = "UPDATE transactions SET status = 'Completed', start_date = CURDATE(), end_date = ? WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "si", $end_date, $transaction_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
            header("location: admin_dashboard.php");
        }
    } elseif (isset($_POST["reject_transaction"])) {
        $transaction_id = (int)$_POST["transaction_id"];
        // Mark transaction as rejected locally
        $sql = "UPDATE transactions SET status = 'Rejected' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $transaction_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        // Fetch transaction to revert land status
        $sql = "SELECT * FROM transactions WHERE id = ?";
        $transaction = null;
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $transaction_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $transaction = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
        }
        if ($transaction) {
            $assetId = (int)$transaction['asset_id'];
            // Update land status based on type
            if ($transaction['type'] == 'Buy') {
                $newStatus = 'ForSale';
            } elseif ($transaction['type'] == 'Mortgage') {
                $newStatus = 'ForMortgage';
            } elseif ($transaction['type'] == 'Lease') {
                $newStatus = 'ForLease';
            } else {
                $newStatus = null;
            }
            if ($newStatus) {
                $sql = "UPDATE land_assets SET status = ? WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "si", $newStatus, $assetId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
        }
        header("location: admin_dashboard.php");
    } elseif (isset($_POST["verify_payment"])) {
        $payment_id = (int)$_POST["payment_id"];
        $sql = "SELECT p.*, la.owner_id as current_owner FROM payments p JOIN land_assets la ON p.asset_id = la.id WHERE p.id = ? AND p.status = 'Submitted'";
        $payment = null;
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $payment_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $payment = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);
        }
        if ($payment) {
            $assetId = (int)$payment['asset_id'];
            $payerId = (int)$payment['payer_id'];
            $amount = (float)$payment['amount'];
            $assetChaincodeId = (string)$assetId;
            if ($payment['type'] === 'LeasePayment') {
                try {
                    $tenantChaincodeId = 'user' . $payerId;
                    fabric_invoke('payRent', [$assetChaincodeId, $tenantChaincodeId, (string)$amount]);
                } catch (Exception $e) {
                    error_log('Error paying rent on blockchain: ' . $e->getMessage());
                }
                $sql = "INSERT INTO transactions (asset_id, buyer_id, seller_id, type, status, amount) VALUES (?, ?, ?, 'LeasePayment', 'Completed', ?)";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "iiid", $assetId, $payerId, $payment['current_owner'], $amount);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            } elseif ($payment['type'] === 'MortgageRepayment') {
                try {
                    fabric_invoke('repayMortgage', [$assetChaincodeId]);
                } catch (Exception $e) {
                    error_log('Error repaying mortgage on blockchain: ' . $e->getMessage());
                }
                $txId = (int)$payment['transaction_id'];
                $sql = "UPDATE transactions SET status = 'Paid' WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "i", $txId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                // Restore ownership to the original owner recorded on the mortgage transaction
                $originalOwnerId = null;
                $sql = "SELECT seller_id FROM transactions WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "i", $txId);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) {
                        $originalOwnerId = (int)$row['seller_id'];
                    }
                    mysqli_stmt_close($stmt);
                }
                if ($originalOwnerId) {
                    $sql = "UPDATE land_assets SET owner_id = ?, status = 'Owned', price = NULL, duration_months = NULL WHERE id = ?";
                    if ($stmt = mysqli_prepare($link, $sql)) {
                        mysqli_stmt_bind_param($stmt, "ii", $originalOwnerId, $assetId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            } elseif ($payment['type'] === 'BuyPayment') {
                // For buy payments, only mark payment verified here; transaction approval will confirm sale on-chain.
                // No chaincode call at verify stage.
            }
            $sql = "UPDATE payments SET status = 'Verified' WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "i", $payment_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        header("location: admin_dashboard.php");
    } elseif (isset($_POST["reject_payment"])) {
        $payment_id = (int)$_POST["payment_id"];
        $sql = "UPDATE payments SET status = 'Rejected' WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $payment_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header("location: admin_dashboard.php");
    } elseif (isset($_POST['mark_deceased'])) {
        $deceased_id = (int)$_POST['user_id'];
        $inheritors = [];
        if ($stmt = mysqli_prepare($link, "SELECT u.id, u.gender FROM user_inheritors ui JOIN users u ON ui.inheritor_id=u.id WHERE ui.owner_id=? AND u.status='Active'")) {
            mysqli_stmt_bind_param($stmt, "i", $deceased_id);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) { $inheritors[] = ['id'=>(int)$row['id'],'gender'=>$row['gender']]; }
            mysqli_stmt_close($stmt);
        }
        $fallbackAuthorityId = null;
        if (count($inheritors) === 0) {
            if ($stmt = mysqli_prepare($link, "SELECT id FROM users WHERE user_type='Authority' AND status='Active' LIMIT 1")) {
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                if ($row = mysqli_fetch_assoc($res)) { $fallbackAuthorityId = (int)$row['id']; }
                mysqli_stmt_close($stmt);
            }
            if ($fallbackAuthorityId === null) {
                $name = 'Authority';
                $nid = 'AUTH001';
                $email = 'authority@example.com';
                $pwd = password_hash('authority123', PASSWORD_DEFAULT);
                $dob = '1980-01-01';
                $addr = 'HQ';
                if ($stmt = mysqli_prepare($link, "INSERT INTO users (name, national_id, email, password, dob, permanent_address, status, user_type) VALUES (?,?,?,?,?,?, 'Active','Authority')")) {
                    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $nid, $email, $pwd, $dob, $addr);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                if ($stmt = mysqli_prepare($link, "SELECT id FROM users WHERE user_type='Authority' AND status='Active' LIMIT 1")) {
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) { $fallbackAuthorityId = (int)$row['id']; }
                    mysqli_stmt_close($stmt);
                }
            }
        }
        if (count($inheritors) > 0 || $fallbackAuthorityId) {
            $assignedAuthority = false;
            if ($fallbackAuthorityId) {
                if ($stmt = mysqli_prepare($link, "SELECT user_type FROM users WHERE id = ? LIMIT 1")) {
                    mysqli_stmt_bind_param($stmt, "i", $fallbackAuthorityId);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    if ($row = mysqli_fetch_assoc($res)) { $assignedAuthority = ($row['user_type'] === 'Authority'); }
                    mysqli_stmt_close($stmt);
                }
                ensureUserActiveOnChain($link, $fallbackAuthorityId);
            } else {
                foreach ($inheritors as $ih) { ensureUserActiveOnChain($link, $ih['id']); }
            }
            $sql = "UPDATE users SET is_deceased=1 WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "i", $deceased_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            if ($assignedAuthority) {
                $sql = "UPDATE land_assets SET owner_id = ?, government_property = 1 WHERE owner_id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "ii", $fallbackAuthorityId, $deceased_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                if ($stmt = mysqli_prepare($link, "SELECT id, price FROM land_assets WHERE owner_id = ? AND government_property = 1")) {
                    mysqli_stmt_bind_param($stmt, "i", $fallbackAuthorityId);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    while ($row = mysqli_fetch_assoc($res)) {
                        $assetId = (int)$row['id'];
                        $priceVal = $row['price'];
                        if ($priceVal === null) {
                            // Fallback to last completed Buy transaction amount
                            $amt = null;
                            if ($stmtAmt = mysqli_prepare($link, "SELECT amount FROM transactions WHERE asset_id=? AND type='Buy' AND status='Completed' ORDER BY id DESC LIMIT 1")) {
                                mysqli_stmt_bind_param($stmtAmt, "i", $assetId);
                                mysqli_stmt_execute($stmtAmt);
                                $resAmt = mysqli_stmt_get_result($stmtAmt);
                                if ($rAmt = mysqli_fetch_assoc($resAmt)) { $amt = $rAmt['amount']; }
                                mysqli_stmt_close($stmtAmt);
                            }
                            if ($amt !== null) { $priceVal = (float)$amt; }
                        }
                        if ($priceVal !== null) {
                            if ($stmt2 = mysqli_prepare($link, "UPDATE land_assets SET status='ForSale', price=? WHERE id=?")) {
                                mysqli_stmt_bind_param($stmt2, "di", $priceVal, $assetId);
                                mysqli_stmt_execute($stmt2);
                                mysqli_stmt_close($stmt2);
                            }
                        } else {
                            if ($stmt2 = mysqli_prepare($link, "UPDATE land_assets SET status='ForSale' WHERE id=?")) {
                                mysqli_stmt_bind_param($stmt2, "i", $assetId);
                                mysqli_stmt_execute($stmt2);
                                mysqli_stmt_close($stmt2);
                            }
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                $maleIds = array_values(array_column(array_filter($inheritors, function($ih){ return $ih['gender']==='Male'; }), 'id'));
                $femaleIds = array_values(array_column(array_filter($inheritors, function($ih){ return $ih['gender']==='Female'; }), 'id'));
                $maleCount = count($maleIds);
                $femaleCount = count($femaleIds);
                if ($stmt = mysqli_prepare($link, "SELECT id, location, area, deed_hash, ipfs_cid, price FROM land_assets WHERE owner_id = ?")) {
                    mysqli_stmt_bind_param($stmt, "i", $deceased_id);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    while ($row = mysqli_fetch_assoc($res)) {
                        $assetId = (int)$row['id'];
                        $loc = $row['location'];
                        $area = (float)$row['area'];
                        $deed = $row['deed_hash'];
                        $cid = $row['ipfs_cid'];
                        $price = $row['price'];
                        $maleTotal = 0.0;
                        $femaleTotal = 0.0;
                        $malePriceTotal = null;
                        $femalePriceTotal = null;
                        if ($maleCount > 0 && $femaleCount > 0) {
                            $maleTotal = $area * (2.0/3.0);
                            $femaleTotal = $area - $maleTotal;
                            if ($price !== null) {
                                $malePriceTotal = (float)$price * (2.0/3.0);
                                $femalePriceTotal = (float)$price - $malePriceTotal;
                            }
                        } elseif ($maleCount > 0) {
                            $maleTotal = $area;
                            if ($price !== null) { $malePriceTotal = (float)$price; $femalePriceTotal = 0.0; }
                        } elseif ($femaleCount > 0) {
                            $femaleTotal = $area;
                            if ($price !== null) { $femalePriceTotal = (float)$price; $malePriceTotal = 0.0; }
                        }
                        $idx = 0;
                        if ($maleCount > 0) {
                            $share = $maleTotal / $maleCount;
                            $sharePrice = ($malePriceTotal !== null) ? ($malePriceTotal / $maleCount) : null;
                            $firstId = $maleIds[$idx++];
                            if ($sharePrice !== null) {
                                if ($stmtU = mysqli_prepare($link, "UPDATE land_assets SET owner_id=?, government_property=0, area=?, price=? WHERE id=?")) {
                                    mysqli_stmt_bind_param($stmtU, "iddi", $firstId, $share, $sharePrice, $assetId);
                                    mysqli_stmt_execute($stmtU);
                                    mysqli_stmt_close($stmtU);
                                }
                            } else {
                                if ($stmtU = mysqli_prepare($link, "UPDATE land_assets SET owner_id=?, government_property=0, area=? WHERE id=?")) {
                                    mysqli_stmt_bind_param($stmtU, "idi", $firstId, $share, $assetId);
                                    mysqli_stmt_execute($stmtU);
                                    mysqli_stmt_close($stmtU);
                                }
                            }
                            for (; $idx < $maleCount; $idx++) {
                                $iid = $maleIds[$idx];
                                if ($sharePrice !== null) {
                                    if ($stmtI = mysqli_prepare($link, "INSERT INTO land_assets (location, area, deed_hash, ipfs_cid, owner_id, price, status, government_property) VALUES (?,?,?,?,?,?, 'Owned', 0)")) {
                                        mysqli_stmt_bind_param($stmtI, "sdssid", $loc, $share, $deed, $cid, $iid, $sharePrice);
                                        mysqli_stmt_execute($stmtI);
                                        mysqli_stmt_close($stmtI);
                                    }
                                } else {
                                    if ($stmtI = mysqli_prepare($link, "INSERT INTO land_assets (location, area, deed_hash, ipfs_cid, owner_id, status, government_property) VALUES (?,?,?,?,?, 'Owned', 0)")) {
                                        mysqli_stmt_bind_param($stmtI, "sdssi", $loc, $share, $deed, $cid, $iid);
                                        mysqli_stmt_execute($stmtI);
                                        mysqli_stmt_close($stmtI);
                                    }
                                }
                            }
                        } elseif ($femaleCount > 0) {
                            $share = $femaleTotal / $femaleCount;
                            $sharePrice = ($femalePriceTotal !== null) ? ($femalePriceTotal / $femaleCount) : null;
                            $firstId = $femaleIds[0];
                            if ($sharePrice !== null) {
                                if ($stmtU = mysqli_prepare($link, "UPDATE land_assets SET owner_id=?, government_property=0, area=?, price=? WHERE id=?")) {
                                    mysqli_stmt_bind_param($stmtU, "iddi", $firstId, $share, $sharePrice, $assetId);
                                    mysqli_stmt_execute($stmtU);
                                    mysqli_stmt_close($stmtU);
                                }
                            } else {
                                if ($stmtU = mysqli_prepare($link, "UPDATE land_assets SET owner_id=?, government_property=0, area=? WHERE id=?")) {
                                    mysqli_stmt_bind_param($stmtU, "idi", $firstId, $share, $assetId);
                                    mysqli_stmt_execute($stmtU);
                                    mysqli_stmt_close($stmtU);
                                }
                            }
                            for ($f = 1; $f < $femaleCount; $f++) {
                                $iid = $femaleIds[$f];
                                if ($sharePrice !== null) {
                                    if ($stmtI = mysqli_prepare($link, "INSERT INTO land_assets (location, area, deed_hash, ipfs_cid, owner_id, price, status, government_property) VALUES (?,?,?,?,?,?, 'Owned', 0)")) {
                                        mysqli_stmt_bind_param($stmtI, "sdssid", $loc, $share, $deed, $cid, $iid, $sharePrice);
                                        mysqli_stmt_execute($stmtI);
                                        mysqli_stmt_close($stmtI);
                                    }
                                } else {
                                    if ($stmtI = mysqli_prepare($link, "INSERT INTO land_assets (location, area, deed_hash, ipfs_cid, owner_id, status, government_property) VALUES (?,?,?,?,?, 'Owned', 0)")) {
                                        mysqli_stmt_bind_param($stmtI, "sdssi", $loc, $share, $deed, $cid, $iid);
                                        mysqli_stmt_execute($stmtI);
                                        mysqli_stmt_close($stmtI);
                                    }
                                }
                            }
                        }
                        if ($femaleTotal > 0 && $maleCount > 0) {
                            $shareF = $femaleTotal / max(1,$femaleCount);
                            $shareFPrice = ($femalePriceTotal !== null && $femaleCount > 0) ? ($femalePriceTotal / $femaleCount) : null;
                            for ($f = 0; $f < $femaleCount; $f++) {
                                $iid = $femaleIds[$f];
                                if ($shareFPrice !== null) {
                                    if ($stmtI = mysqli_prepare($link, "INSERT INTO land_assets (location, area, deed_hash, ipfs_cid, owner_id, price, status, government_property) VALUES (?,?,?,?,?,?, 'Owned', 0)")) {
                                        mysqli_stmt_bind_param($stmtI, "sdssid", $loc, $shareF, $deed, $cid, $iid, $shareFPrice);
                                        mysqli_stmt_execute($stmtI);
                                        mysqli_stmt_close($stmtI);
                                    }
                                } else {
                                    if ($stmtI = mysqli_prepare($link, "INSERT INTO land_assets (location, area, deed_hash, ipfs_cid, owner_id, status, government_property) VALUES (?,?,?,?,?, 'Owned', 0)")) {
                                        mysqli_stmt_bind_param($stmtI, "sdssi", $loc, $shareF, $deed, $cid, $iid);
                                        mysqli_stmt_execute($stmtI);
                                        mysqli_stmt_close($stmtI);
                                    }
                                }
                            }
                        } elseif ($maleTotal > 0 && $femaleCount > 0) {
                            // handled above
                        }
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
        header("location: admin_dashboard.php?tab=users");
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1>Land Inspector Dashboard</h1>
                <div class="user-info">
                    <div class="user-welcome">
                        <i class="fas fa-user-circle"></i>
                        <span>Welcome, <?php echo $_SESSION["name"]; ?></span>
                    </div>
                    <div class="user-actions">
                        <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
</header>
        
        <div class="dashboard-tabs">
            <a class="tab-btn <?php echo $active_tab==='pending-users' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=pending-users"><i class="fas fa-users"></i> Pending Users</a>
            <a class="tab-btn <?php echo $active_tab==='land-pending' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=land-pending"><i class="fas fa-hourglass-half"></i> Land Pending</a>
            <a class="tab-btn <?php echo $active_tab==='land-assets' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=land-assets"><i class="fas fa-landmark"></i> Land Assets</a>
            <a class="tab-btn <?php echo $active_tab==='government-property' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=government-property"><i class="fas fa-university"></i> Government Property</a>
            <a class="tab-btn <?php echo $active_tab==='pending-transactions' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=pending-transactions"><i class="fas fa-exchange-alt"></i> Pending Transactions</a>
            <a class="tab-btn <?php echo $active_tab==='pending-payments' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=pending-payments"><i class="fas fa-receipt"></i> Pending Payments</a>
            <a class="tab-btn <?php echo $active_tab==='users' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=users"><i class="fas fa-user-friends"></i> Users</a>
        </div>
        
        <div class="dashboard-content">
            <div id="pending-users" class="tab-content <?php echo $active_tab==='pending-users' ? 'active' : ''; ?>">
                <h2><i class="fas fa-users"></i> Pending User Approvals</h2>
                <?php if (count($pending_users) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($pending_users as $user): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo $user['name']; ?></h3>
                                    <span class="status-badge status-pending">Pending</span>
                                </div>
                                <div class="card-body">
                                    <p><strong>National ID:</strong> <?php echo $user['national_id']; ?></p>
                                    <p><strong>Email:</strong> <?php echo $user['email']; ?></p>
                                    <p><strong>Date of Birth:</strong> <?php echo $user['dob']; ?></p>
                                    <p><strong>Permanent Address:</strong> <?php echo $user['permanent_address']; ?></p>
                                    <p><strong>Registration Date:</strong> <?php echo $user['created_at']; ?></p>
                                </div>
                <div class="card-actions">
                    <form method="post" action="admin_dashboard.php" style="display: inline;">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <button type="submit" name="approve_user" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
                    </form>
                    <form method="post" action="admin_dashboard.php" style="display: inline;">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <button type="submit" name="reject_user" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                    </form>
                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tx ID</th>
                                <th>Land</th>
                                <th>Type</th>
                                <th>Seller</th>
                                <th>Buyer/Tenant</th>
                                <th>Duration</th>
                                <th>Amount</th>
                                <th>Payment Info</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo $transaction['id']; ?></td>
                                    <td><?php echo htmlspecialchars($transaction['land_location']); ?></td>
                                    <td><?php echo $transaction['type']; ?></td>
                                    <td><?php echo htmlspecialchars($transaction['seller_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['buyer_name']); ?></td>
                                    <td><?php echo ($transaction['type'] == 'Mortgage' || $transaction['type'] == 'Lease') ? $transaction['duration_months'] . ' months' : '-'; ?></td>
                                    <td>৳<?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td>
                                        <?php
                                        $pendingPaymentForTx = null;
                                        if ($transaction['type'] === 'Buy' && !empty($transaction['buyer_id'])) {
                                            $q = "SELECT id, amount, method, reference, proof_path FROM payments WHERE status='Submitted' AND type='BuyPayment' AND asset_id=? AND payer_id=? LIMIT 1";
                                            if ($stmt = mysqli_prepare($link, $q)) {
                                                mysqli_stmt_bind_param($stmt, "ii", $transaction['asset_id'], $transaction['buyer_id']);
                                                mysqli_stmt_execute($stmt);
                                                $r = mysqli_stmt_get_result($stmt);
                                                $pendingPaymentForTx = mysqli_fetch_assoc($r);
                                                mysqli_stmt_close($stmt);
                                            }
                                        } elseif ($transaction['type'] === 'Lease' && !empty($transaction['buyer_id'])) {
                                            $q = "SELECT id, amount, method, reference, proof_path FROM payments WHERE status='Submitted' AND type='LeasePayment' AND asset_id=? AND payer_id=? LIMIT 1";
                                            if ($stmt = mysqli_prepare($link, $q)) {
                                                mysqli_stmt_bind_param($stmt, "ii", $transaction['asset_id'], $transaction['buyer_id']);
                                                mysqli_stmt_execute($stmt);
                                                $r = mysqli_stmt_get_result($stmt);
                                                $pendingPaymentForTx = mysqli_fetch_assoc($r);
                                                mysqli_stmt_close($stmt);
                                            }
                                        } elseif ($transaction['type'] === 'Mortgage') {
                                            $q = "SELECT id, amount, method, reference, proof_path FROM payments WHERE status='Submitted' AND type='MortgageRepayment' AND transaction_id=? LIMIT 1";
                                            if ($stmt = mysqli_prepare($link, $q)) {
                                                mysqli_stmt_bind_param($stmt, "i", $transaction['id']);
                                                mysqli_stmt_execute($stmt);
                                                $r = mysqli_stmt_get_result($stmt);
                                                $pendingPaymentForTx = mysqli_fetch_assoc($r);
                                                mysqli_stmt_close($stmt);
                                            }
                                        }
                                        if ($pendingPaymentForTx): ?>
                                            <div>
                                                <div><strong>Method:</strong> <?php echo htmlspecialchars($pendingPaymentForTx['method']); ?></div>
                                                <div><strong>Reference:</strong> <?php echo htmlspecialchars($pendingPaymentForTx['reference']); ?></div>
                                                <div><strong>Amount:</strong> ৳<?php echo number_format($pendingPaymentForTx['amount'], 2); ?></div>
                                                <div><strong>Proof:</strong> <?php if (!empty($pendingPaymentForTx['proof_path'])) { echo '<a href="' . htmlspecialchars($pendingPaymentForTx['proof_path']) . '" target="_blank">View</a>'; } ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span>-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                            <form method="post" action="admin_dashboard.php">
                                                <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                                <button type="submit" name="approve_transaction" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
                                            </form>
                                            <form method="post" action="admin_dashboard.php">
                                                <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                                <button type="submit" name="reject_transaction" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                                            </form>
                                        </div>
                                        
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No Pending Users</h3>
                        <p>No pending user approvals at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            $active_users = [];
            $sql = "SELECT id, name, national_id, email, status, user_type, inheritor_national_id, is_deceased FROM users WHERE status='Active' ORDER BY user_type, name";
            if ($result = mysqli_query($link, $sql)) {
                while ($row = mysqli_fetch_assoc($result)) { $active_users[] = $row; }
                mysqli_free_result($result);
            }
            ?>
            <div id="users" class="tab-content <?php echo $active_tab==='users' ? 'active' : ''; ?>">
                <h2><i class="fas fa-user-friends"></i> Users</h2>
                <?php if (count($active_users) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>NID</th>
                                <th>Email</th>
                                <th>Type</th>
                                <th>Inheritor NID</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_users as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['national_id']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo htmlspecialchars($u['user_type']); ?></td>
                                    <td><?php echo htmlspecialchars($u['inheritor_national_id'] ?? ''); ?></td>
                                    <td><?php echo ((int)$u['is_deceased'] === 1) ? 'Deceased' : 'Active'; ?></td>
                                    <td>
                                        <?php if ((int)$u['is_deceased'] !== 1): ?>
                                            <form method="post" action="admin_dashboard.php" style="display:inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <button type="submit" name="mark_deceased" class="btn btn-danger"><i class="fas fa-user-slash"></i> Mark Deceased & Transfer Lands</button>
                                            </form>
                                        <?php else: ?>
                                            <span>-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-friends"></i>
                        <h3>No Active Users</h3>
                        <p>There are no active users to display.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div id="land-pending" class="tab-content <?php echo $active_tab==='land-pending' ? 'active' : ''; ?>">
                <h2><i class="fas fa-hourglass-half"></i> Land Pending</h2>
                <?php if (count($pending_lands) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Land ID</th>
                                <th>Location</th>
                                <th>Area</th>
                                <th>Price</th>
                                <th>Owner</th>
                                <th>Deed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_lands as $l): ?>
                                <tr>
                                    <td><?php echo $l['id']; ?></td>
                                    <td><?php echo htmlspecialchars($l['location']); ?></td>
                                    <td><?php echo $l['area']; ?></td>
                                    <td><?php echo $l['price'] !== null ? number_format($l['price'], 2) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($l['owner_name']); ?></td>
                                    <td><?php if (!empty($l['deed_path'])) { echo '<a href="' . htmlspecialchars($l['deed_path']) . '" target="_blank">View</a>'; } ?></td>
                                    <td>
                                        <form method="post" action="admin_dashboard.php" style="display:inline;">
                                            <input type="hidden" name="land_id" value="<?php echo $l['id']; ?>" />
                                            <button type="submit" name="approve_land" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
                                        </form>
                                        <form method="post" action="admin_dashboard.php" style="display:inline;">
                                            <input type="hidden" name="land_id" value="<?php echo $l['id']; ?>" />
                                            <button type="submit" name="reject_land" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hourglass-half"></i>
                        <h3>No Pending Land Requests</h3>
                        <p>There are no land records awaiting approval.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div id="pending-payments" class="tab-content <?php echo $active_tab==='pending-payments' ? 'active' : ''; ?>">
                <h2><i class="fas fa-receipt"></i> Pending Payments</h2>
                <?php
                $pending_payments = [];
                $sql = "SELECT p.*, la.location as land_location, u.name as payer_name FROM payments p JOIN land_assets la ON p.asset_id = la.id JOIN users u ON p.payer_id = u.id WHERE p.status = 'Submitted' AND p.type <> 'BuyPayment'";
                if ($result = mysqli_query($link, $sql)) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $pending_payments[] = $row;
                    }
                    mysqli_free_result($result);
                }
                ?>
                <?php if (count($pending_payments) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Payment ID</th>
                                <th>Land</th>
                                <th>Payer</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_payments as $p): ?>
                                <tr>
                                    <td><?php echo $p['id']; ?></td>
                                    <td><?php echo htmlspecialchars($p['land_location']); ?></td>
                                    <td><?php echo htmlspecialchars($p['payer_name']); ?></td>
                                    <td><?php echo $p['type']; ?></td>
                                    <td><?php echo number_format($p['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($p['method']); ?></td>
                                    <td><?php echo htmlspecialchars($p['reference']); ?></td>
                                    <td><?php if ($p['proof_path']) { echo '<a href="' . htmlspecialchars($p['proof_path']) . '" target="_blank">View</a>'; } ?></td>
                                    <td>
                                        <form method="post" action="admin_dashboard.php" style="display:inline;">
                                            <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>" />
                                            <button type="submit" name="verify_payment" class="btn btn-success"><i class="fas fa-check"></i> Verify</button>
                                        </form>
                                        <form method="post" action="admin_dashboard.php" style="display:inline;">
                                            <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>" />
                                            <button type="submit" name="reject_payment" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <h3>No Pending Payments</h3>
                        <p>There are no payment submissions awaiting verification.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="land-assets" class="tab-content <?php echo $active_tab==='land-assets' ? 'active' : ''; ?>">
                <h2><i class="fas fa-landmark"></i> All Land Assets</h2>
                <?php if (count($land_assets) > 0): ?>
                    <div style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap;">
                        <div class="input-group" style="flex:1 1 260px;max-width:420px;">
                            <span class="input-icon"><i class="fas fa-search"></i></span>
                            <input type="text" id="landSearch" class="form-control" placeholder="Search by location">
                        </div>
                        <div class="input-group" style="width:220px;">
                            <span class="input-icon"><i class="fas fa-sort"></i></span>
                            <select id="priceSort" class="form-control">
                                <option value="">Sort by price</option>
                                <option value="asc">Low to High</option>
                                <option value="desc">High to Low</option>
                            </select>
                        </div>
                        <div class="input-group" style="width:240px;">
                            <span class="input-icon"><i class="fas fa-filter"></i></span>
                            <select id="statusFilter" class="form-control">
                                <option value="">All statuses</option>
                                <option value="Owned">Owned</option>
                                <option value="ForSale">ForSale</option>
                                <option value="SalePending">SalePending</option>
                                <option value="ForMortgage">ForMortgage</option>
                                <option value="MortgagePending">MortgagePending</option>
                                <option value="Mortgaged">Mortgaged</option>
                                <option value="ForLease">ForLease</option>
                                <option value="LeasePending">LeasePending</option>
                                <option value="Leased">Leased</option>
                            </select>
                        </div>
                    </div>
                    <div class="card-container">
                        <?php foreach ($land_assets as $land): ?>
                            <div class="card" data-location="<?php echo htmlspecialchars($land['location']); ?>" data-status="<?php echo htmlspecialchars($land['status']); ?>" data-price="<?php echo $land['price']!==null ? (float)$land['price'] : 0; ?>">
                                <div class="card-header">
                                    <h3><?php echo $land['location']; ?></h3>
                                    <span class="status-badge status-<?php echo strtolower($land['status']); ?>"><?php echo $land['status']; ?></span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Owner:</strong> <?php echo $land['owner_name']; ?></p>
                                    <?php if (!empty($land['deed_path'])): ?>
                                        <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                                    <?php endif; ?>
                                    <?php if ($land['price']): ?>
                                        <p><strong>Price:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="card-actions">
                                    <?php if ($land['status'] == 'Pending'): ?>
                                        <form method="post" action="admin_dashboard.php" style="display: inline;">
                                            <input type="hidden" name="land_id" value="<?php echo $land['id']; ?>">
                                            <button type="submit" name="approve_land" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
                                        </form>
                                        <form method="post" action="admin_dashboard.php" style="display: inline;">
                                            <input type="hidden" name="land_id" value="<?php echo $land['id']; ?>">
                                            <button type="submit" name="reject_land" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="status-approved">Processed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <script>
                        (function(){
                            var container = document.querySelector('#land-assets .card-container');
                            var searchInput = document.getElementById('landSearch');
                            var sortSelect = document.getElementById('priceSort');
                            var statusFilter = document.getElementById('statusFilter');
                            function apply(){
                                var cards = Array.prototype.slice.call(container.querySelectorAll('.card'));
                                var q = (searchInput.value || '').toLowerCase();
                                var st = statusFilter.value || '';
                                cards.forEach(function(card){
                                    var loc = (card.getAttribute('data-location') || '').toLowerCase();
                                    var status = card.getAttribute('data-status') || '';
                                    var match = (q === '' || loc.indexOf(q) !== -1) && (st === '' || status === st);
                                    card.style.display = match ? '' : 'none';
                                });
                                var needSort = sortSelect.value === 'asc' || sortSelect.value === 'desc';
                                if (needSort){
                                    var visibleCards = cards.filter(function(c){ return c.style.display !== 'none'; });
                                    visibleCards.sort(function(a,b){
                                        var pa = parseFloat(a.getAttribute('data-price') || '0');
                                        var pb = parseFloat(b.getAttribute('data-price') || '0');
                                        return sortSelect.value === 'asc' ? (pa - pb) : (pb - pa);
                                    });
                                    visibleCards.forEach(function(c){ container.appendChild(c); });
                                }
                            }
                            searchInput.addEventListener('input', apply);
                            sortSelect.addEventListener('change', apply);
                            statusFilter.addEventListener('change', apply);
                        })();
                    </script>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-landmark"></i>
                        <h3>No Land Assets</h3>
                        <p>No land assets registered in the system.</p>
                    </div>
                <?php endif; ?>
            </div>
            <div id="government-property" class="tab-content <?php echo $active_tab==='government-property' ? 'active' : ''; ?>">
                <h2><i class="fas fa-university"></i> Government Property</h2>
                <?php if (count($government_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($government_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-<?php echo strtolower($land['status']); ?>"><?php echo $land['status']; ?></span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Owner:</strong> <?php echo htmlspecialchars($land['owner_name']); ?></p>
                                    <?php if (!empty($land['deed_path'])): ?>
                                        <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                                    <?php endif; ?>
                                    <?php if ($land['price']): ?>
                                        <p><strong>Price:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="card-actions">
                                    <span class="status-approved">Approved</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-university"></i>
                        <h3>No Government Properties</h3>
                        <p>No approved government properties available.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="pending-transactions" class="tab-content <?php echo $active_tab==='pending-transactions' ? 'active' : ''; ?>">
                <h2><i class="fas fa-exchange-alt"></i> Pending Transactions</h2>
                <?php if (count($pending_transactions) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Land</th>
                                <th>Seller</th>
                                <th>Buyer/Tenant</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Duration</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Proof</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_transactions as $transaction): ?>
                                <?php
                                $pendingPaymentForTx = null;
                                if ($transaction['type'] === 'Buy' && !empty($transaction['buyer_id'])) {
                                    $q = "SELECT id, amount, method, reference, proof_path FROM payments WHERE status='Submitted' AND type='BuyPayment' AND asset_id=? AND payer_id=? LIMIT 1";
                                    if ($stmt = mysqli_prepare($link, $q)) {
                                        mysqli_stmt_bind_param($stmt, "ii", $transaction['asset_id'], $transaction['buyer_id']);
                                        mysqli_stmt_execute($stmt);
                                        $r = mysqli_stmt_get_result($stmt);
                                        $pendingPaymentForTx = mysqli_fetch_assoc($r);
                                        mysqli_stmt_close($stmt);
                                    }
                                } elseif ($transaction['type'] === 'Lease' && !empty($transaction['buyer_id'])) {
                                    $q = "SELECT id, amount, method, reference, proof_path FROM payments WHERE status='Submitted' AND type='LeasePayment' AND asset_id=? AND payer_id=? LIMIT 1";
                                    if ($stmt = mysqli_prepare($link, $q)) {
                                        mysqli_stmt_bind_param($stmt, "ii", $transaction['asset_id'], $transaction['buyer_id']);
                                        mysqli_stmt_execute($stmt);
                                        $r = mysqli_stmt_get_result($stmt);
                                        $pendingPaymentForTx = mysqli_fetch_assoc($r);
                                        mysqli_stmt_close($stmt);
                                    }
                                } elseif ($transaction['type'] === 'Mortgage') {
                                    $q = "SELECT id, amount, method, reference, proof_path FROM payments WHERE status='Submitted' AND type='MortgageRepayment' AND transaction_id=? LIMIT 1";
                                    if ($stmt = mysqli_prepare($link, $q)) {
                                        mysqli_stmt_bind_param($stmt, "i", $transaction['id']);
                                        mysqli_stmt_execute($stmt);
                                        $r = mysqli_stmt_get_result($stmt);
                                        $pendingPaymentForTx = mysqli_fetch_assoc($r);
                                        mysqli_stmt_close($stmt);
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?php echo $transaction['id']; ?></td>
                                    <td><?php echo htmlspecialchars($transaction['land_location']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['seller_name']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['buyer_name']); ?></td>
                                    <td><?php echo $transaction['type']; ?></td>
                                    <td>৳<?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td><?php echo ($transaction['duration_months']) ? $transaction['duration_months'] . ' months' : '-'; ?></td>
                                    <td><?php echo $pendingPaymentForTx ? htmlspecialchars($pendingPaymentForTx['method']) : '-'; ?></td>
                                    <td><?php echo $pendingPaymentForTx ? htmlspecialchars($pendingPaymentForTx['reference']) : '-'; ?></td>
                                    <td><?php if ($pendingPaymentForTx && !empty($pendingPaymentForTx['proof_path'])) { echo '<a href="' . htmlspecialchars($pendingPaymentForTx['proof_path']) . '" target="_blank">View</a>'; } else { echo '-'; } ?></td>
                                    <td>
                                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                            <form method="post" action="admin_dashboard.php">
                                                <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                                <button type="submit" name="approve_transaction" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
                                            </form>
                                            <form method="post" action="admin_dashboard.php">
                                                <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                                                <button type="submit" name="reject_transaction" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
                                            </form>
                                            
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-exchange-alt"></i>
                        <h3>No Pending Transactions</h3>
                        <p>No pending transactions at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openTab(button, tabName) {
            // Hide all tab contents
            var tabContents = document.getElementsByClassName("tab-content");
            for (var i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove("active");
            }
            
            // Remove active class from all buttons
            var tabButtons = document.getElementsByClassName("tab-btn");
            for (var i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove("active");
            }
            
            // Show the selected tab and mark button as active
            document.getElementById(tabName).classList.add("active");
            if (button) { button.classList.add("active"); }
        }
    </script>
    <script src="js/script.js"></script>
</body>
</html>
