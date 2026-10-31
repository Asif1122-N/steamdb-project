
<?php

session_start();

// Only logged-in administrators can access this page.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrator only.');
}

header('Cache-Control: no-store, no-cache, must-revalidate');

// Handle administrator logout.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'logout'
) {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !isset($_SESSION['admin_logout_csrf']) ||
        !hash_equals($_SESSION['admin_logout_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid logout request.');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]
        );
    }

    session_destroy();

    header('Location: ../login.php');
    exit;
}

// Generate logout security token.
if (empty($_SESSION['admin_logout_csrf'])) {
    $_SESSION['admin_logout_csrf'] =
        bin2hex(random_bytes(32));
}

// Connect to MariaDB.
require_once __DIR__ . '/../../Config/database.php';

$username = $_SESSION['username'] ?? 'Administrator';

// LIVE DATABASE STATISTICS

$totalGames = (int) $pdo->query(
    "SELECT COUNT(*) FROM game"
)->fetchColumn();

$totalBuyers = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM `user`
     WHERE role = 'buyer'"
)->fetchColumn();

$totalTransactions = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM `transaction`"
)->fetchColumn();

$activeDiscounts = (int) $pdo->query(
    "SELECT COUNT(DISTINCT game_id)
     FROM prices
     WHERE discount_percent > 0
       AND CURDATE() BETWEEN discount_start
                         AND discount_end"
)->fetchColumn();

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - Administrator Dashboard</title>

    <style>

        body {
            margin: 20px;
            font-family: Arial, sans-serif;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Dynamic statistics */
        .statistics {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin: 30px 0;
        }

        .stat-card {
            border: 2px solid black;
            padding: 20px;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .stat-card p {
            margin-bottom: 0;
        }

        /* Clickable management cards */
        .management {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-top: 30px;
        }

        .management-card {
            display: block;
            border: 2px solid black;
            min-height: 160px;
            padding: 20px;
            text-decoration: none;
            color: black;
            background: white;
            box-sizing: border-box;
            cursor: pointer;
        }

        .management-card h3 {
            margin-top: 0;
        }

        /* Hover feedback */
        .management-card:hover {
            background: #f0f0f0;
            border-color: #444;
        }

        /* Keyboard accessibility */
        .management-card:focus-visible {
            outline: 3px solid #2563eb;
            outline-offset: 3px;
        }

        .card-description {
            color: #555;
            font-size: 14px;
            line-height: 1.5;
        }

        button {
            padding: 8px 14px;
            cursor: pointer;
        }

        @media (max-width: 800px) {

            .statistics {
                grid-template-columns: repeat(2, 1fr);
            }

            .management {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 500px) {

            .statistics,
            .management {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
            }

        }

    </style>

</head>

<body>

    <!-- ADMINISTRATOR HEADER -->
    <header class="header">

        <h1>SteamDB - Administrator</h1>

        <div class="admin-info">

            <strong>
                Hi, <?= e($username) ?> (Admin)
            </strong>

            <!-- Logout button -->
            <form action="dashboard.php" method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(
                        $_SESSION['admin_logout_csrf']
                    ) ?>"
                >

                <button
                    type="submit"
                    name="action"
                    value="logout">

                    Logout

                </button>

            </form>

        </div>

    </header>

    <hr>

    <h2>Administrator Dashboard</h2>

    <p>
        Welcome to the SteamDB administrative control panel.
    </p>


    <!-- LIVE DATABASE STATISTICS -->
    <h2>Database Overview</h2>

    <section class="statistics">

        <div class="stat-card">
            <h3><?= $totalGames ?></h3>
            <p>Total Games</p>
        </div>

        <div class="stat-card">
            <h3><?= $totalBuyers ?></h3>
            <p>Buyer Accounts</p>
        </div>

        <div class="stat-card">
            <h3><?= $totalTransactions ?></h3>
            <p>Recorded Transactions</p>
        </div>

        <div class="stat-card">
            <h3><?= $activeDiscounts ?></h3>
            <p>Games on Discount</p>
        </div>

    </section>

    <hr>


    <!-- CLICKABLE MANAGEMENT CARDS -->
    <h2>Management and Reports</h2>

    <section class="management">

        <!-- 1. Manage Games -->
        <a class="management-card"
           href="games.php">

            <h3>Manage Games</h3>

            <p>
                Add, and Delete games
                in SteamDB.
            </p>

            <p class="card-description">
                Manage game information, metadata,
                and cover images.
            </p>

        </a>


        <!-- 2. Manage Discounts -->
        <a class="management-card"
           href="discounts.php">

            <h3>Manage Discounts</h3>

            <p>
                Change discount percentages,
                starting dates, and ending dates.
            </p>

            <p class="card-description">
                Manage game pricing and promotions.
            </p>

        </a>


        <!-- 3. Weekly Best -->
        <a class="management-card"
           href="weekly_best.php">

            <h3>Weekly Best</h3>

            <p>
                Add or remove featured games
                from the Weekly Best list.
            </p>

            <p class="card-description">
                Manage current and previous selections.
            </p>

        </a>


        <!-- 4. Manage Users -->
        <a class="management-card"
           href="users.php">

            <h3>Manage Users</h3>

            <p>
                Create, update, and deactivate
                buyer accounts.
            </p>

            <p class="card-description">
                Manage SteamDB buyer accounts.
            </p>

        </a>


        <!-- 5. Generate AI Reports -->
        <a class="management-card"
           href="reports.php">

            <h3>Generate AI Reports</h3>

            <p>
                Generate administrator activity
                and Weekly Best reports.
            </p>

            <p class="card-description">
                AI summaries with CSV export.
            </p>

        </a>


        <!-- 6. Automated Reports -->
        <a class="management-card"
           href="automated_reports.php">

            <h3>Automated Purchase Reports</h3>

            <p>
                Access daily purchase history
                reports generated automatically.
            </p>

            <p class="card-description">
                Scheduled CSV reports using n8n.
            </p>

        </a>

    </section>

</body>
</html>
