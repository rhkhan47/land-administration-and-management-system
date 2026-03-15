<?php
require_once "config.php";

if (!isLoggedIn() || isAdmin()) {
    header("location: login.php");
    exit;
}

$location = $area = $price = "";
$location_err = $area_err = $price_err = "";
$deed_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate location
    if (empty(trim($_POST["location"]))) {
        $location_err = "Please enter land location.";
    } else {
        $location = trim($_POST["location"]);
    }
    
    // Validate area
    if (empty(trim($_POST["area"])) || !is_numeric($_POST["area"])) {
        $area_err = "Please enter a valid area.";
    } else {
        $area = trim($_POST["area"]);
    }
    
    // Validate deed upload
    $deed_path = null;
    if (!isset($_FILES['deed']) || empty($_FILES['deed']['name'])) {
        $deed_err = "Please upload the deed file.";
    } else {
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'deeds';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $basename = basename($_FILES['deed']['name']);
        $target = $uploadDir . DIRECTORY_SEPARATOR . time() . '_' . $_SESSION['id'] . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $basename);
        if (move_uploaded_file($_FILES['deed']['tmp_name'], $target)) {
            $deed_path = 'uploads/deeds/' . basename($target);
        } else {
            $deed_err = "Failed to upload deed.";
        }
    }
    
    // Validate price
    if (empty(trim($_POST["price"])) || !is_numeric($_POST["price"])) {
        $price_err = "Please enter a valid price.";
    } else {
        $price = trim($_POST["price"]);
    }
    
    // Check input errors before inserting
    if (empty($location_err) && empty($area_err) && empty($price_err) && empty($deed_err)) {
        $sql = "INSERT INTO land_assets (location, area, owner_id, price, status, deed_path) VALUES (?, ?, ?, ?, 'Pending', ?)";
        
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sdids", $param_location, $param_area, $param_owner_id, $param_price, $param_deed_path);
            
            $param_location = $location;
            $param_area = $area;
            $param_deed_path = $deed_path;
            $param_owner_id = $_SESSION["id"];
            $param_price = $price;
            
            if (mysqli_stmt_execute($stmt)) {
                // Capture the inserted land ID
                $new_land_id = mysqli_insert_id($link);
                mysqli_stmt_close($stmt);
                // After inserting into the local DB, also register the land on the blockchain
                try {
                    // Build a simple location object for the ledger.  Include both the
                    // free-form location string and the numeric area so that the
                    // details can be reconstructed later.
                    $locationObj = [
                        'description' => $location,
                        'area' => $area
                    ];
                    // Compose the chaincode arguments: landId, location JSON,
                    // placeholder CID and owner chaincode ID.  We use the database
                    // numeric ID as the land identifier on the ledger for easy
                    // correlation.
                    $chaincodeLandId = (string)$new_land_id;
                    $ownerChaincodeId = 'user' . $_SESSION['id'];
                    fabric_invoke('addLand', [
                        $chaincodeLandId,
                        json_encode($locationObj),
                        "",
                        $ownerChaincodeId
                    ]);
                } catch (Exception $e) {
                    // If blockchain registration fails we leave the land pending in DB.
                    error_log('Error registering land on blockchain: ' . $e->getMessage());
                }
                header("location: user_dashboard.php?land_added=1");
            } else {
                mysqli_stmt_close($stmt);
                echo "Something went wrong. Please try again later.";
            }
        }
    }
    
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Land - Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <h1><i class="fas fa-plus-circle"></i> Add New Land</h1>
                <div class="user-info">
                    <a href="user_dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                    <a href="logout.php" class="btn btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </header>
        
        <div class="form-wrapper">
            <p>Please fill in the details of your land. The Land Inspector will need to approve it before it appears in the system.</p>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                <div class="form-group <?php echo (!empty($location_err)) ? 'has-error' : ''; ?>">
                    <label>Location</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-map-marker-alt"></i></span>
                        <input type="text" name="location" class="form-control" value="<?php echo $location; ?>">
                    </div>
                    <span class="help-block"><?php echo $location_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($area_err)) ? 'has-error' : ''; ?>">
                    <label>Area (sq ft)</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-ruler-combined"></i></span>
                        <input type="number" step="0.01" name="area" class="form-control" value="<?php echo $area; ?>">
                    </div>
                    <span class="help-block"><?php echo $area_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($price_err)) ? 'has-error' : ''; ?>">
                    <label>Price (৳)</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-tag"></i></span>
                        <input type="number" step="0.01" name="price" class="form-control" value="<?php echo $price; ?>">
                    </div>
                    <span class="help-block"><?php echo $price_err; ?></span>
                </div>
                <div class="form-group <?php echo (!empty($deed_err)) ? 'has-error' : ''; ?>">
                    <label>Upload Deed</label>
                    <div class="input-group">
                        <span class="input-icon"><i class="fas fa-file-upload"></i></span>
                        <input type="file" name="deed" class="form-control" accept="image/*,application/pdf" required>
                    </div>
                    <span class="help-block"><?php echo $deed_err; ?></span>
                </div>
                <div class="form-group">
                    <input type="submit" class="btn btn-primary" value="Submit">
                    <a href="user_dashboard.php" class="btn btn-default">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
