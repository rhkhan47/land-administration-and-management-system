<?php
$server = 'localhost';
$user = 'root';
$pass = '';
$conn = mysqli_connect($server, $user, $pass);
if ($conn === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'connect_failed']);
    exit;
}
$sqls = [];
$sqls[] = "CREATE DATABASE IF NOT EXISTS land_admin_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
mysqli_query($conn, $sqls[0]);
mysqli_select_db($conn, 'land_admin_db');
$sqls[] = "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, national_id VARCHAR(100) NOT NULL, email VARCHAR(255) NULL UNIQUE, password VARCHAR(255) NULL, dob DATE NULL, permanent_address TEXT NULL, status ENUM('PreEntered','Pending','Active','Rejected') NOT NULL DEFAULT 'Pending', user_type ENUM('User','Admin','Authority') NOT NULL DEFAULT 'User', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)";
$sqls[] = "CREATE TABLE IF NOT EXISTS land_assets (id INT AUTO_INCREMENT PRIMARY KEY, location VARCHAR(255) NOT NULL, area DECIMAL(12,2) NOT NULL, deed_hash VARCHAR(255) NULL, ipfs_cid VARCHAR(255) NULL, owner_id INT NOT NULL, price DECIMAL(12,2) NULL, status ENUM('Pending','Owned','Rejected','ForSale','SalePending','ForMortgage','MortgagePending','Mortgaged','ForLease','LeasePending','Leased') NOT NULL DEFAULT 'Pending', duration_months INT NULL, monthly_payment DECIMAL(12,2) NULL, government_property TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_land_owner FOREIGN KEY (owner_id) REFERENCES users(id))";
$sqls[] = "ALTER TABLE land_assets ADD COLUMN IF NOT EXISTS deed_path VARCHAR(255) NULL";
$sqls[] = "ALTER TABLE land_assets ADD COLUMN IF NOT EXISTS government_property TINYINT(1) NOT NULL DEFAULT 0";
$sqls[] = "CREATE TABLE IF NOT EXISTS transactions (id INT AUTO_INCREMENT PRIMARY KEY, asset_id INT NOT NULL, buyer_id INT NULL, seller_id INT NULL, type ENUM('Buy','Lease','LeasePayment','Mortgage') NOT NULL, status ENUM('Pending','Completed','Rejected','Expired','Paid') NOT NULL DEFAULT 'Pending', amount DECIMAL(12,2) NULL, duration_months INT NULL, monthly_payment DECIMAL(12,2) NULL, start_date DATE NULL, end_date DATE NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_tx_asset FOREIGN KEY (asset_id) REFERENCES land_assets(id), CONSTRAINT fk_tx_buyer FOREIGN KEY (buyer_id) REFERENCES users(id), CONSTRAINT fk_tx_seller FOREIGN KEY (seller_id) REFERENCES users(id))";
$sqls[] = "CREATE TABLE IF NOT EXISTS payments (id INT AUTO_INCREMENT PRIMARY KEY, asset_id INT NOT NULL, transaction_id INT NULL, payer_id INT NOT NULL, type ENUM('LeasePayment','MortgageRepayment','BuyPayment') NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(50) NOT NULL, reference VARCHAR(100) NOT NULL, proof_path VARCHAR(255) NULL, status ENUM('Submitted','Verified','Rejected') NOT NULL DEFAULT 'Submitted', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_pay_asset FOREIGN KEY (asset_id) REFERENCES land_assets(id), CONSTRAINT fk_pay_tx FOREIGN KEY (transaction_id) REFERENCES transactions(id), CONSTRAINT fk_pay_payer FOREIGN KEY (payer_id) REFERENCES users(id))";
// Ensure payments.type includes BuyPayment in existing installations
mysqli_query($conn, "ALTER TABLE payments MODIFY COLUMN type ENUM('LeasePayment','MortgageRepayment','BuyPayment') NOT NULL");
// Ensure users table supports Authority role and partial records
mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN user_type ENUM('User','Admin','Authority') NOT NULL DEFAULT 'User'");
mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN email VARCHAR(255) NULL");
mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL");
mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN status ENUM('PreEntered','Pending','Active','Rejected') NOT NULL DEFAULT 'Pending'");
// Ensure gender column exists, then restrict to Male/Female and NOT NULL
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS gender ENUM('Male','Female') NULL");
// Normalize any existing values to allowed set before enforcing NOT NULL
mysqli_query($conn, "UPDATE users SET gender='Male' WHERE gender IS NULL OR gender NOT IN ('Male','Female')");
mysqli_query($conn, "ALTER TABLE users MODIFY COLUMN gender ENUM('Male','Female') NOT NULL");
// Create inheritors mapping table
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS user_inheritors (id INT AUTO_INCREMENT PRIMARY KEY, owner_id INT NOT NULL, inheritor_id INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_inh_owner FOREIGN KEY (owner_id) REFERENCES users(id), CONSTRAINT fk_inh_inheritor FOREIGN KEY (inheritor_id) REFERENCES users(id), UNIQUE KEY uniq_owner_inheritor (owner_id, inheritor_id))");
// Add inheritance support columns
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS inheritor_national_id VARCHAR(100) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_deceased TINYINT(1) NOT NULL DEFAULT 0");
foreach ($sqls as $i => $q) {
    mysqli_query($conn, $q);
}
$res = mysqli_query($conn, "SELECT id FROM users WHERE user_type='Admin' LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    $name = 'Admin';
    $nid = 'ADMIN001';
    $email = 'admin@example.com';
    $pwd = password_hash('admin123', PASSWORD_DEFAULT);
    $dob = '1980-01-01';
    $addr = 'HQ';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (name, national_id, email, password, dob, permanent_address, status, user_type) VALUES (?,?,?,?,?,?, 'Active','Admin')");
    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $nid, $email, $pwd, $dob, $addr);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
// Seed Authority account if missing
$res2 = mysqli_query($conn, "SELECT id FROM users WHERE user_type='Authority' LIMIT 1");
if (!$res2 || mysqli_num_rows($res2) === 0) {
    $name = 'Authority';
    $nid = 'AUTH001';
    $email = 'authority@example.com';
    $pwd = password_hash('authority123', PASSWORD_DEFAULT);
    $dob = '1980-01-01';
    $addr = 'HQ';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (name, national_id, email, password, dob, permanent_address, status, user_type) VALUES (?,?,?,?,?,?, 'Active','Authority')");
    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $nid, $email, $pwd, $dob, $addr);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
// Remove mistakenly created Government user and purge their properties
if ($resGov = mysqli_query($conn, "SELECT id FROM users WHERE national_id='GOVT001' OR name='Government' LIMIT 1")) {
    if (mysqli_num_rows($resGov) > 0) {
        $govRow = mysqli_fetch_assoc($resGov);
        $govId = (int)$govRow['id'];
        // Collect land asset ids owned by this user
        $assetIds = [];
        if ($resAssets = mysqli_query($conn, "SELECT id FROM land_assets WHERE owner_id=" . $govId)) {
            while ($r = mysqli_fetch_assoc($resAssets)) { $assetIds[] = (int)$r['id']; }
            mysqli_free_result($resAssets);
        }
        if (!empty($assetIds)) {
            $inList = implode(',', array_map('intval', $assetIds));
            // Delete payments linked to these assets or their transactions
            mysqli_query($conn, "DELETE FROM payments WHERE asset_id IN ($inList)");
            // Delete transactions linked to these assets
            mysqli_query($conn, "DELETE FROM transactions WHERE asset_id IN ($inList)");
            // Delete the assets themselves
            mysqli_query($conn, "DELETE FROM land_assets WHERE id IN ($inList)");
        }
        // Remove any transactions or payments directly referencing the Government user
        mysqli_query($conn, "DELETE FROM payments WHERE payer_id=" . $govId);
        mysqli_query($conn, "DELETE FROM transactions WHERE seller_id=" . $govId . " OR buyer_id=" . $govId);
        // Finally delete the user record
        mysqli_query($conn, "DELETE FROM users WHERE id=" . $govId);
    }
    mysqli_free_result($resGov);
}
echo json_encode(['ok' => true]);
?>
