<?php
require_once "config.php";

// Redirect logged in users to appropriate dashboard
if (isLoggedIn()) {
    if (isAdmin()) {
        header("location: admin_dashboard.php");
    } else {
        header("location: user_dashboard.php");
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Land Administration System</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Secure Land Administration System</h1>
            <!-- <p>Using Blockchain Technology for Transparency</p> -->
        </header>
        
        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-text">
                    <h2>Welcome to Bangladesh Land Administration</h2>
                    <p>A secure, transparent system for land registration, sale, mortgage, and leasing</p>
                </div>
                <div class="cta-buttons">
                    <a href="register.php" class="btn btn-primary">Register</a>
                    <a href="login.php" class="btn btn-secondary">Login</a>
                </div>
            </div>
            <!-- <div class="hero-image">
                <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0IiBmaWxsPSJub25lIiBzdHJva2U9IiMwMDcwZmYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIj48cGF0aCBkPSJNOSAzSDVhMiAyIDAgMCAwLTIgMnY0YTIgMiAwIDAgMCAyIDJoNGMyIDAgMy0xIDMtM1Y2YzAtMi0xLTMtMy0zeiI+PC9wYXRoPjxwYXRoIGQ9Ik0xNSAzSDlhMiAyIDAgMCAwLTIgMnY0YTIgMiAwIDAgMCAyIDJoNmMyIDAgMy0xIDMtM1Y2YzAtMi0xLTMtMy0zeiI+PC9wYXRoPjxwYXRoIGQ9Ik05IDE1SDVhMiAyIDAgMCAwLTIgMnY0YTIgMiAwIDAgMCAyIDJoNGMyIDAgMy0xIDMtM3YtMWMwLTItMS0zLTMtM3oiPjwvcGF0aD48cGF0aCBkPSJNMTUgMTNoLTZhMiAyIDAgMCAwLTIgMnY0YTIgMiAwIDAgMCAyIDJoNmMyIDAgMy0xIDMtM3YtMWMwLTItMS0zLTMtM3oiPjwvcGF0aD48L3N2Zz4=" alt="Land Administration">
            </div> -->
        </div>
        
        <div class="features">
            <div class="feature">
                <h3>Transparent Transactions</h3>
                <p>All land transactions are recorded on a secure blockchain for complete transparency.</p>
            </div>
            <div class="feature">
                <h3>Secure Ownership</h3>
                <p>Digital deeds and cryptographic verification ensure secure land ownership.</p>
            </div>
            <div class="feature">
                <h3>Easy Management</h3>
                <p>Manage land sales, mortgages, and leases through a simple interface.</p>
            </div>
        </div>
    </div>
    <script src="js/script.js"></script>
</body>
</html>
