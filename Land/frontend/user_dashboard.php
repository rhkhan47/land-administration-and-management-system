<?php
require_once "config.php";

// Ensure a regular user is logged in
if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

// Current user ID
$user_id = $_SESSION["id"];

// Handle multi-inheritor management
$heritage_msg = null;
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_inheritor"])) {
    $nid = trim($_POST["inheritor_nid"] ?? "");
    if ($nid !== '') {
        $inh_id = null;
        if ($stmt = mysqli_prepare($link, "SELECT id FROM users WHERE national_id=? AND status='Active'")) {
            mysqli_stmt_bind_param($stmt, "s", $nid);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            if ($row) { $inh_id = (int)$row['id']; }
        }
        if ($inh_id && $inh_id !== $user_id) {
            $exists = false;
            if ($stmt = mysqli_prepare($link, "SELECT id FROM user_inheritors WHERE owner_id=? AND inheritor_id=?")) {
                mysqli_stmt_bind_param($stmt, "ii", $user_id, $inh_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $exists = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);
            }
            if (!$exists) {
                if ($stmt = mysqli_prepare($link, "INSERT INTO user_inheritors (owner_id, inheritor_id) VALUES (?,?)")) {
                    mysqli_stmt_bind_param($stmt, "ii", $user_id, $inh_id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $heritage_msg = "Inheritor added";
                }
            }
        }
    }
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["remove_inheritor"])) {
    $inh_id = (int)($_POST['inheritor_id'] ?? 0);
    if ($inh_id > 0) {
        if ($stmt = mysqli_prepare($link, "DELETE FROM user_inheritors WHERE owner_id=? AND inheritor_id=?")) {
            mysqli_stmt_bind_param($stmt, "ii", $user_id, $inh_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $heritage_msg = "Inheritor removed";
        }
    }
}
// Fetch current inheritors list
$inheritors = [];
if ($stmt = mysqli_prepare($link, "SELECT u.id, u.name, u.national_id, u.gender FROM user_inheritors ui JOIN users u ON ui.inheritor_id=u.id WHERE ui.owner_id=?")) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $inheritors[] = $row; }
    mysqli_stmt_close($stmt);
}

// Fetch data for dashboard tabs using helper functions defined in config.php
// My Lands (owned by the user)
$user_lands = getUserLands($link, $user_id);
// Lands available for sale (owned by others)
$available_lands = getAvailableLandsForSale($link, $user_id);
// Lands available for mortgage
$available_mortgage_lands = getAvailableLandsForMortgage($link, $user_id);
// Lands available for lease
$available_lease_lands = getAvailableLandsForLease($link, $user_id);
// Lands where the user is the temporary owner via mortgage
$user_mortgage_lands = getUserMortgageLands($link, $user_id);
// Lands where the user is the temporary owner via lease
$user_lease_lands = getUserLeaseLands($link, $user_id);
// Lands for which the user needs to pay mortgage (user is the original owner)
$mortgage_payment_lands = getMortgagePaymentLands($link, $user_id);
// Lands for which the user needs to pay lease (user is the original owner)
$lease_payment_lands = getLeasePaymentLands($link, $user_id);

// Close the database connection early since no further queries are needed
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1>User Dashboard</h1>
                <div class="user-info">
                    <div class="user-welcome">
                        <i class="fas fa-user-circle"></i>
                        <span>Welcome, <?php echo htmlspecialchars($_SESSION["name"]); ?></span>
                    </div>
                    <div class="user-actions">
                        <a href="profile.php" class="btn btn-outline"><i class="fas fa-user"></i> Profile</a>
                        <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>
        <!-- Summary cards -->
        <div class="dashboard-summary">
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-landmark"></i>
                </div>
                <div class="summary-content">
                    <h3><?php echo count($user_lands); ?></h3>
                    <p>Total Lands</p>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="summary-content">
                    <?php
                    // Count lands currently listed for sale or pending sale
                    $lands_for_sale = array_filter($user_lands, function($land) {
                        return $land['status'] === 'ForSale' || $land['status'] === 'SalePending';
                    });
                    ?>
                    <h3><?php echo count($lands_for_sale); ?></h3>
                    <p>Lands for Sale</p>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-file-contract"></i>
                </div>
                <div class="summary-content">
                    <h3><?php echo count($user_mortgage_lands); ?></h3>
                    <p>Mortgaged Lands (Temporary)</p>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon">
                    <i class="fas fa-file-signature"></i>
                </div>
                <div class="summary-content">
                    <h3><?php echo count($user_lease_lands); ?></h3>
                    <p>Leased Lands (Temporary)</p>
                </div>
            </div>
        </div>
        <!-- Tab buttons -->
        <div class="dashboard-tabs">
            <button class="tab-btn active" onclick="openTab('my-lands')"><i class="fas fa-landmark"></i> My Lands</button>
            <button class="tab-btn" onclick="openTab('available-lands')"><i class="fas fa-shopping-cart"></i> Available Lands for Buy</button>
            <button class="tab-btn" onclick="openTab('available-mortgage')"><i class="fas fa-hand-holding-usd"></i> Available for Mortgage</button>
            <button class="tab-btn" onclick="openTab('available-lease')"><i class="fas fa-file-signature"></i> Available for Lease</button>
            <button class="tab-btn" onclick="openTab('mortgage-payment')"><i class="fas fa-credit-card"></i> Pay for Mortgage</button>
            <button class="tab-btn" onclick="openTab('lease-payment')"><i class="fas fa-money-bill-wave"></i> Pay for Lease</button>
            <button class="tab-btn" onclick="openTab('heritage')"><i class="fas fa-sitemap"></i> Heritage Ownership Details</button>
            <a href="add_land.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Land</a>
        </div>
        <!-- Tab contents -->
        <div class="dashboard-content">
            <!-- My Lands tab -->
            <div id="my-lands" class="tab-content active">
                <h2><i class="fas fa-landmark"></i> My Land Assets</h2>
                <?php if (count($user_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($user_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-<?php echo strtolower($land['status']); ?>"><?php echo $land['status']; ?></span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <?php if (!empty($land['deed_path'])): ?>
                                        <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                                    <?php endif; ?>
                                    <?php if ($land['status'] === 'Owned'): ?>
                                        <?php $displayPrice = (!empty($land['price'])) ? $land['price'] : (!empty($land['purchase_amount']) ? $land['purchase_amount'] : null); ?>
                                        <?php if ($displayPrice !== null): ?>
                                            <p><strong>Price:</strong> ৳<?php echo number_format($displayPrice, 2); ?></p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (!empty($land['price'])): ?>
                                            <p><strong><?php echo ($land['status'] === 'ForLease' || $land['status'] === 'LeasePending') ? 'Monthly Rent' : ($land['status'] === 'ForMortgage' || $land['status'] === 'MortgagePending' || $land['status'] === 'Mortgaged' ? 'Mortgage Amount' : 'Price'); ?>:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php 
                                    // if (!empty($land['duration_months'])):
                                    //     echo '<p><strong>Duration:</strong> ' . $land['duration_months'] . ' months</p>';
                                    // endif;
                                    ?>
                                </div>
                                <div class="card-actions">
                                    <?php if ($land['status'] === 'Owned'): ?>
                                        <a href="buy_land.php?action=sell&land_id=<?php echo $land['id']; ?>" class="btn btn-secondary"><i class="fas fa-tag"></i> Sell</a>
                                        <a href="mortgage.php?land_id=<?php echo $land['id']; ?>" class="btn btn-secondary"><i class="fas fa-hand-holding-usd"></i> Mortgage</a>
                                        <a href="lease.php?land_id=<?php echo $land['id']; ?>" class="btn btn-secondary"><i class="fas fa-file-signature"></i> Lease</a>
                                    <?php elseif ($land['status'] === 'ForSale' || $land['status'] === 'SalePending'): ?>
                                        <a href="buy_land.php?action=cancel&land_id=<?php echo $land['id']; ?>" class="btn btn-warning"><i class="fas fa-times-circle"></i> Cancel Sale</a>
                                    <?php elseif ($land['status'] === 'ForMortgage' || $land['status'] === 'MortgagePending'): ?>
                                        <a href="cancel_operation.php?action=mortgage&land_id=<?php echo $land['id']; ?>" class="btn btn-warning"><i class="fas fa-times-circle"></i> Cancel Mortgage</a>
                                    <?php elseif ($land['status'] === 'ForLease' || $land['status'] === 'LeasePending'): ?>
                                        <a href="cancel_operation.php?action=lease&land_id=<?php echo $land['id']; ?>" class="btn btn-warning"><i class="fas fa-times-circle"></i> Cancel Lease</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-landmark"></i>
                        <h3>No Land Assets</h3>
                        <p>You don't have any land assets registered. <a href="add_land.php">Add your first land</a>.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Available Lands for Sale tab -->
            <div id="available-lands" class="tab-content">
                <h2><i class="fas fa-shopping-cart"></i> Available Lands for Sale</h2>
                <?php if (count($available_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($available_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-forsale">For Sale</span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Price:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                                    <p><strong>Owner:</strong> <?php echo htmlspecialchars($land['owner_name']); ?></p>
                                    <?php if (!empty($land['deed_path'])): ?>
                                        <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                                    <?php endif; ?>
                                </div>
                                <div class="card-actions">
                                    <a href="buy_land.php?action=buy&land_id=<?php echo $land['id']; ?>" class="btn btn-primary"><i class="fas fa-shopping-cart"></i> Buy This Land</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-shopping-cart"></i>
                        <h3>No Lands Available</h3>
                        <p>No lands available for sale at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Available for Mortgage tab -->
            <div id="available-mortgage" class="tab-content">
                <h2><i class="fas fa-hand-holding-usd"></i> Available Lands for Mortgage</h2>
                <?php if (count($available_mortgage_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($available_mortgage_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-formortgage">For Mortgage</span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Mortgage Amount:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                                    <p><strong>Duration:</strong> <?php echo $land['duration_months']; ?> months</p>
                                    <p><strong>Owner:</strong> <?php echo htmlspecialchars($land['owner_name']); ?></p>
                                    <?php if (!empty($land['deed_path'])): ?>
                                        <p><strong>Deed:</strong> <a href="<?php echo htmlspecialchars($land['deed_path']); ?>" target="_blank">View</a></p>
                                    <?php endif; ?>
                                </div>
                                <div class="card-actions">
                                    <a href="accept_mortgage.php?land_id=<?php echo $land['id']; ?>" class="btn btn-primary"><i class="fas fa-handshake"></i> Accept Mortgage</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-hand-holding-usd"></i>
                        <h3>No Lands Available</h3>
                        <p>No lands available for mortgage at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Available for Lease tab -->
            <div id="available-lease" class="tab-content">
                <h2><i class="fas fa-file-signature"></i> Available Lands for Lease</h2>
                <?php if (count($available_lease_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($available_lease_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-forlease">For Lease</span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Monthly Rent:</strong> ৳<?php echo number_format($land['price'], 2); ?></p>
                                    <p><strong>Duration:</strong> <?php echo $land['duration_months']; ?> months</p>
                                    <p><strong>Owner:</strong> <?php echo htmlspecialchars($land['owner_name']); ?></p>
                                    <p><strong>IPFS CID:</strong> <span class="monospace"><?php echo htmlspecialchars($land['ipfs_cid']); ?></span></p>
                                </div>
                                <div class="card-actions">
                                    <a href="accept_lease.php?land_id=<?php echo $land['id']; ?>" class="btn btn-primary"><i class="fas fa-file-signature"></i> Accept Lease</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-signature"></i>
                        <h3>No Lands Available</h3>
                        <p>No lands available for lease at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Mortgage payment tab -->
            <div id="mortgage-payment" class="tab-content">
                <h2><i class="fas fa-credit-card"></i> Pay for Mortgage</h2>
                <?php if (count($mortgage_payment_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($mortgage_payment_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-mortgaged">Mortgaged</span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Mortgage Amount:</strong> ৳<?php echo number_format($land['amount'], 2); ?></p>
                                    <p><strong>Due Date:</strong> <?php echo $land['end_date']; ?></p>
                                    <p><strong>IPFS CID:</strong> <span class="monospace"><?php echo htmlspecialchars($land['ipfs_cid']); ?></span></p>
                                    <p><strong>Deed Hash:</strong> <span class="monospace"><?php echo htmlspecialchars($land['deed_hash']); ?></span></p>
                                </div>
                                <div class="card-actions">
                                    <a href="pay_mortgage.php?land_id=<?php echo $land['id']; ?>" class="btn btn-primary"><i class="fas fa-credit-card"></i> Make Payment</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-credit-card"></i>
                        <h3>No Mortgage Payments Due</h3>
                        <p>You don't have any mortgaged lands to pay for at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Lease payment tab -->
            <div id="lease-payment" class="tab-content">
                <h2><i class="fas fa-money-bill-wave"></i> Pay for Lease</h2>
                <?php if (count($lease_payment_lands) > 0): ?>
                    <div class="card-container">
                        <?php foreach ($lease_payment_lands as $land): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h3><?php echo htmlspecialchars($land['location']); ?></h3>
                                    <span class="status-badge status-leased">Leased</span>
                                </div>
                                <div class="card-body">
                                    <p><strong>Area:</strong> <?php echo $land['area']; ?> sq ft</p>
                                    <p><strong>Monthly Payment:</strong> ৳<?php echo number_format($land['monthly_payment'], 2); ?></p>
                                    <p><strong>Due Date:</strong> <?php echo $land['end_date']; ?></p>
                                    <p><strong>IPFS CID:</strong> <span class="monospace"><?php echo htmlspecialchars($land['ipfs_cid']); ?></span></p>
                                    <p><strong>Deed Hash:</strong> <span class="monospace"><?php echo htmlspecialchars($land['deed_hash']); ?></span></p>
                                </div>
                                <div class="card-actions">
                                    <a href="pay_lease.php?land_id=<?php echo $land['id']; ?>" class="btn btn-primary"><i class="fas fa-money-bill-wave"></i> Make Payment</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>No Lease Payments Due</h3>
                        <p>You don't have any leased lands to pay for at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Heritage Ownership Details tab -->
            <div id="heritage" class="tab-content">
                <h2><i class="fas fa-sitemap"></i> Heritage Ownership Details</h2>
                <?php if ($heritage_msg): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $heritage_msg; ?></div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form method="post" action="user_dashboard.php">
                            <div class="form-group">
                                <label>Inheritor National ID</label>
                                <div class="input-group">
                                    <span class="input-icon"><i class="fas fa-id-card"></i></span>
                                    <input type="text" name="inheritor_nid" class="form-control" placeholder="Enter NID">
                                </div>
                            </div>
                            <button type="submit" name="add_inheritor" class="btn btn-primary"><i class="fas fa-plus"></i> Add Inheritor</button>
                        </form>
                        <?php if (count($inheritors) > 0): ?>
                            <div style="margin-top:15px;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>NID</th>
                                            <th>Gender</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($inheritors as $inh): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($inh['name']); ?></td>
                                            <td><?php echo htmlspecialchars($inh['national_id']); ?></td>
                                            <td><?php echo htmlspecialchars($inh['gender']); ?></td>
                                            <td>
                                                <form method="post" action="user_dashboard.php" style="display:inline;">
                                                    <input type="hidden" name="inheritor_id" value="<?php echo (int)$inh['id']; ?>">
                                                    <button type="submit" name="remove_inheritor" class="btn btn-warning"><i class="fas fa-times"></i> Remove</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Simple tab switching logic: hide all tabs and show the selected one
        function openTab(tabName) {
            // Hide all contents
            var contents = document.getElementsByClassName("tab-content");
            for (var i = 0; i < contents.length; i++) {
                contents[i].classList.remove("active");
            }
            // Remove active class from all buttons
            var buttons = document.getElementsByClassName("tab-btn");
            for (var j = 0; j < buttons.length; j++) {
                buttons[j].classList.remove("active");
            }
            // Activate selected tab and corresponding button
            document.getElementById(tabName).classList.add("active");
            // event.currentTarget refers to the button that was clicked
            event.currentTarget.classList.add("active");
        }
    </script>
    <script src="js/script.js"></script>
</body>
</html>
