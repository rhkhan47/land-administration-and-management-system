<?php
session_start();

// Database configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'land_admin_db');

// Attempt to connect to MySQL database
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($link === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
}

// Function to check if user is admin
function isAdmin() {
    return isset($_SESSION["user_type"]) && $_SESSION["user_type"] === "Admin";
}

// -----------------------------------------------------------------------------
// Hyperledger Fabric helpers
//
// The application uses a separate Node.js backend (see server.js) to interact
// with the Hyperledger Fabric network.  PHP cannot talk directly to the
// blockchain, so these helpers send HTTP requests to the Node API.  The
// functions below wrap those REST calls and provide a simple interface for
// invoking chaincode transactions (fabric_invoke) and evaluating read-only
// queries (fabric_query).  They throw Exceptions on HTTP or Fabric errors so
// callers can decide how to surface the error to the user.
//
// Adjust the URL if your Node server is running on a different host or port.

/**
 * Send a POST request to the Fabric API and return the decoded JSON.
 *
 * @param string $endpoint The endpoint path (e.g. '/api/invoke' or '/api/query').
 * @param array  $payload  Associative array to be JSON encoded and sent as the
 *                         request body.
 * @return array Decoded JSON response from the Node server.
 * @throws Exception when the request fails or the response cannot be parsed.
 */
function fabric_post($endpoint, $payload)
{
    $url = 'http://localhost:3000' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('Fabric API request failed: ' . $error);
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($status >= 400) {
        $message = isset($data['error']) ? $data['error'] : ('HTTP ' . $status);
        throw new Exception('Fabric API returned error: ' . $message);
    }
    return $data;
}

/**
 * Invoke a state-changing chaincode function.  The transaction will be
 * submitted to the Fabric network and committed.  On success the response
 * includes the transaction ID and the chaincode return value (if any).
 *
 * @param string $fcn  The chaincode function name to invoke.
 * @param array  $args Array of string arguments to pass to the function.
 * @return array Response array with keys: ok, txId, fcn, args, result.
 * @throws Exception if the Fabric API reports an error.
 */
function fabric_invoke($fcn, $args = [])
{
    $payload = ['fcn' => $fcn, 'args' => $args];
    $response = fabric_post('/api/invoke', $payload);
    if (!isset($response['ok']) || !$response['ok']) {
        $message = isset($response['error']) ? $response['error'] : 'Unknown error';
        throw new Exception('Fabric invoke error: ' . $message);
    }
    return $response;
}

/**
 * Evaluate a read-only chaincode function.  The transaction is executed on
 * a peer without committing anything to the ledger.  The returned value
 * depends on the chaincode implementation.
 *
 * @param string $fcn  The chaincode function name to evaluate.
 * @param array  $args Array of string arguments to pass to the function.
 * @return mixed The decoded result from the chaincode evaluation.
 * @throws Exception if the Fabric API reports an error.
 */
function fabric_query($fcn, $args = [])
{
    $payload = ['fcn' => $fcn, 'args' => $args];
    $response = fabric_post('/api/query', $payload);
    if (!isset($response['ok']) || !$response['ok']) {
        $message = isset($response['error']) ? $response['error'] : 'Unknown error';
        throw new Exception('Fabric query error: ' . $message);
    }
    return $response['result'];
}

// Function to get user lands
function getUserLands($link, $user_id, $status = null) {
    $lands = [];
    $sql = "SELECT * FROM land_assets WHERE owner_id = ?";
    
    if ($status) {
        $sql .= " AND status = ?";
    }
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        if ($status) {
            mysqli_stmt_bind_param($stmt, "is", $user_id, $status);
        } else {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
        }
        
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get available lands for sale (not owned by current user)
function getAvailableLandsForSale($link, $user_id) {
    $lands = [];
    $sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.status = 'ForSale' AND la.owner_id != ?";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get available lands for mortgage
function getAvailableLandsForMortgage($link, $user_id) {
    $lands = [];
    $sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.status = 'ForMortgage' AND la.owner_id != ?";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get available lands for lease
function getAvailableLandsForLease($link, $user_id) {
    $lands = [];
    $sql = "SELECT la.*, u.name as owner_name FROM land_assets la JOIN users u ON la.owner_id = u.id WHERE la.status = 'ForLease' AND la.owner_id != ?";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get user's mortgage lands (as temporary owner)
function getUserMortgageLands($link, $user_id) {
    $lands = [];
    $sql = "SELECT la.* FROM land_assets la 
            JOIN transactions t ON la.id = t.asset_id 
            WHERE t.buyer_id = ? AND t.type = 'Mortgage' AND t.status = 'Completed'";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get user's lease lands (as temporary owner)
function getUserLeaseLands($link, $user_id) {
    $lands = [];
    $sql = "SELECT la.* FROM land_assets la 
            JOIN transactions t ON la.id = t.asset_id 
            WHERE t.buyer_id = ? AND t.type = 'Lease' AND t.status = 'Completed'";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get lands where user needs to pay mortgage (as original owner)
function getMortgagePaymentLands($link, $user_id) {
    $lands = [];
    $sql = "SELECT la.*, t.end_date, t.amount FROM land_assets la 
            JOIN transactions t ON la.id = t.asset_id 
            WHERE t.seller_id = ? AND t.type = 'Mortgage' AND t.status = 'Completed'";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    
    return $lands;
}

// Function to get lands where user needs to pay lease (as original owner)
function getLeasePaymentLands($link, $user_id) {
    /*
     * Return lands for which the given user is responsible for making
     * lease payments.  A lease payment is owed by the tenant (buyer)
     * rather than the original owner.  Therefore we select transactions
     * where the current user is the buyer and the lease has been
     * completed/activated.  The caller can then display these lands
     * in the "Pay for Lease" tab.
     */
    $lands = [];
    $sql = "SELECT la.*, t.end_date, t.amount, t.monthly_payment FROM land_assets la 
            JOIN transactions t ON la.id = t.asset_id 
            WHERE t.buyer_id = ? AND t.type = 'Lease' AND t.status = 'Completed'";
    
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $lands[] = $row;
        }
        mysqli_stmt_close($stmt);
    }
    return $lands;
}
?>