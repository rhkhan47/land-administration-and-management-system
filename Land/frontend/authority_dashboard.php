<?php
require_once "config.php";

if (!isLoggedIn() || !isAuthority()) {
    header("location: login.php");
    exit;
}

$message = $error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['add_user'])) {
        $name = trim($_POST['name'] ?? '');
        $nid  = trim($_POST['national_id'] ?? '');
        $dob  = trim($_POST['dob'] ?? '');
        if ($name === '' || $nid === '' || $dob === '') {
            $error = "Please provide name, NID, and date of birth.";
        } else {
            $exists = false;
            if ($stmt = mysqli_prepare($link, "SELECT id FROM users WHERE national_id=?")) {
                mysqli_stmt_bind_param($stmt, "s", $nid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $exists = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);
            }
            if ($exists) {
                $error = "A record with this NID already exists.";
            } else {
                if ($stmt = mysqli_prepare($link, "INSERT INTO users (name, national_id, dob, status, user_type) VALUES (?,?,?, 'PreEntered','User')")) {
                    mysqli_stmt_bind_param($stmt, "sss", $name, $nid, $dob);
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "User record created. The person can now register using NID.";
                    } else {
                        $error = "Failed to create user record.";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    } elseif (isset($_POST['assign_land'])) {
        $nid = trim($_POST['national_id'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $area = trim($_POST['area'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $deed_path = null;
        $isGovt = ($nid === 'GOVT001') ? 1 : 0;
        if ($nid === '' || $location === '' || !is_numeric($area) || ($price !== '' && !is_numeric($price))) {
            $error = "Please provide NID, location, valid area, and optional valid price.";
        } else {
            // Resolve owner
            $owner_id = null;
            if ($isGovt === 1) {
                // Use Authority user as Government owner
                if ($stmt = mysqli_prepare($link, "SELECT id FROM users WHERE user_type='Authority' LIMIT 1")) {
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    $row = mysqli_fetch_assoc($res);
                    mysqli_stmt_close($stmt);
                    if ($row) { $owner_id = (int)$row['id']; }
                }
            } else {
                if ($stmt = mysqli_prepare($link, "SELECT id FROM users WHERE national_id=?")) {
                    mysqli_stmt_bind_param($stmt, "s", $nid);
                    mysqli_stmt_execute($stmt);
                    $res = mysqli_stmt_get_result($stmt);
                    $row = mysqli_fetch_assoc($res);
                    mysqli_stmt_close($stmt);
                    if ($row) { $owner_id = (int)$row['id']; }
                }
            }
            if (!$owner_id) {
                $error = "NID not found. Please add the user first.";
            } else {
                if (!empty($_FILES['deed']['name'])) {
                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deeds';
                    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
                    $basename = basename($_FILES['deed']['name']);
                    $target = $uploadDir . DIRECTORY_SEPARATOR . time() . '_' . $owner_id . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $basename);
                    if (move_uploaded_file($_FILES['deed']['tmp_name'], $target)) {
                        $deed_path = 'uploads/deeds/' . basename($target);
                    }
                }
                $sql = "INSERT INTO land_assets (location, area, owner_id, price, status, deed_path, government_property) VALUES (?,?,?,?, 'Pending', ?, ?)";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    $area_num = (float)$area; $price_num = ($price === '' ? null : (float)$price);
                    mysqli_stmt_bind_param($stmt, "sdidsi", $location, $area_num, $owner_id, $price_num, $deed_path, $isGovt);
                    if (mysqli_stmt_execute($stmt)) {
                        $new_land_id = mysqli_insert_id($link);
                        // Ensure government owner exists on-chain
                        if ($isGovt === 1) {
                            // Ensure Authority user exists & is approved on-chain
                            $ownerChaincodeId = 'user' . $owner_id;
                            // Fetch authority details
                            $authRow = null;
                            if ($stmtA = mysqli_prepare($link, "SELECT national_id, name, dob, permanent_address FROM users WHERE id = ?")) {
                                mysqli_stmt_bind_param($stmtA, "i", $owner_id);
                                mysqli_stmt_execute($stmtA);
                                $resA = mysqli_stmt_get_result($stmtA);
                                $authRow = mysqli_fetch_assoc($resA);
                                mysqli_stmt_close($stmtA);
                            }
                            try {
                                fabric_invoke('approveUser', [$ownerChaincodeId]);
                            } catch (Exception $eApprove) {
                                if (strpos($eApprove->getMessage(), 'does not exist') !== false && $authRow) {
                                    try {
                                        fabric_invoke('registerUser', [$ownerChaincodeId, $authRow['national_id'], json_encode(['name' => $authRow['name'], 'dob' => $authRow['dob'], 'address' => $authRow['permanent_address']])]);
                                        fabric_invoke('approveUser', [$ownerChaincodeId]);
                                    } catch (Exception $eReg) {
                                        error_log('Error ensuring Authority user on blockchain: ' . $eReg->getMessage());
                                    }
                                }
                            }
                        }
                        // Register land on chain with empty CID
                        try {
                            $chaincodeLandId = (string)$new_land_id;
                            $locationObj = ['location' => $location, 'area' => (float)$area];
                            $ownerChaincodeId = 'user' . $owner_id;
                            fabric_invoke('addLand', [
                                $chaincodeLandId,
                                json_encode($locationObj),
                                "",
                                $ownerChaincodeId
                            ]);
                        } catch (Exception $e) {
                            error_log('Error registering land on blockchain: ' . $e->getMessage());
                        }
                        $message = "Land assigned and pending approval.";
                    } else {
                        $error = "Failed to assign land.";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authority Dashboard - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1> Authority Dashboard</h1>
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
            <button class="tab-btn active" onclick="openTab('add-user')"><i class="fas fa-user-plus"></i> Add User</button>
            <button class="tab-btn" onclick="openTab('assign-land')"><i class="fas fa-map"></i> Assign Land</button>
            <button class="tab-btn" onclick="openTab('government-property')"><i class="fas fa-university"></i> Government Property</button>
        </div>

        <div class="dashboard-content">
            <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
            <?php endif; ?>

            <div id="add-user" class="tab-content active">
            <div class="form-wrapper">
                <div class="form-header">
                    <h2><i class="fas fa-user-plus"></i> Add User (Pre-Entry)</h2>
                    <p>Enter a person’s details; they will complete registration later.</p>
                </div>
                <form action="authority_dashboard.php" method="post">
                    <div class="form-group">
                        <label>Full Name</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-user"></i></span>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>National ID</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-id-card"></i></span>
                            <input type="text" name="national_id" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-calendar"></i></span>
                            <input type="date" name="dob" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="add_user" class="btn btn-primary"><i class="fas fa-plus"></i> Add User</button>
                    </div>
                </form>
            </div>
            </div>

            <div id="assign-land" class="tab-content">
            <div class="form-wrapper">
                <div class="form-header">
                    <h2><i class="fas fa-map"></i> Assign Land</h2>
                    <p>Assign a land to a user by NID.</p>
                </div>
                <form action="authority_dashboard.php" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>National ID</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-id-card"></i></span>
                            <input type="text" name="national_id" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Location</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Area (sq ft)</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-ruler-combined"></i></span>
                            <input type="number" step="0.01" name="area" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Price</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-tag"></i></span>
                            <input type="number" step="0.01" name="price" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload Deed</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-file-upload"></i></span>
                            <input type="file" name="deed" class="form-control" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="assign_land" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Assign Land</button>
                    </div>
                </form>
            </div>
            </div>

            <div id="government-property" class="tab-content">
            <div class="form-wrapper">
                <div class="form-header">
                    <h2><i class="fas fa-university"></i> Government Property</h2>
                    <p>Assign land as government property.</p>
                </div>
                <form action="authority_dashboard.php" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="national_id" value="GOVT001" />
                    <div class="form-group">
                        <label>Location</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                            <input type="text" name="location" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Area (sq ft)</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-ruler-combined"></i></span>
                            <input type="number" step="0.01" name="area" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Price</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-tag"></i></span>
                            <input type="number" step="0.01" name="price" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Upload Deed</label>
                        <div class="input-group">
                            <span class="input-icon"><i class="fas fa-file-upload"></i></span>
                            <input type="file" name="deed" class="form-control" accept="image/*,application/pdf">
                        </div>
                    </div>
                    <div class="form-group">
                        <button type="submit" name="assign_land" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Assign Government Land</button>
                    </div>
                </form>
            </div>
            </div>
        </div>
    </div>
    <script>
        function openTab(tabName) {
            var contents = document.getElementsByClassName('tab-content');
            for (var i = 0; i < contents.length; i++) {
                contents[i].classList.remove('active');
            }
            var buttons = document.getElementsByClassName('tab-btn');
            for (var j = 0; j < buttons.length; j++) {
                buttons[j].classList.remove('active');
            }
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }
    </script>
</body>
</html>
