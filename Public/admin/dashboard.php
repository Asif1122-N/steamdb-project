
<?php

session_start();

// STEP 1: Only logged-in users can access this page.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// STEP 2: Only administrators are allowed.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Access denied. Administrator only.');
}

// STEP 3: Handle admin logout on this page.
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

// Generate security token for logout.
if (empty($_SESSION['admin_logout_csrf'])) {
    $_SESSION['admin_logout_csrf'] =
        bin2hex(random_bytes(32));
}

// STEP 4: Connect to MariaDB.
// dashboard.php is inside Public/admin,
// so we go up two folders to Config.
require_once __DIR__ . '/../../Config/database.php';

$username = $_SESSION['username'] ?? 'Administrator';

// STEP 5: Get live database statistics.

// Total games in MariaDB.
$totalGames = (int) $pdo->query(
    "SELECT COUNT(*) FROM game"
)->fetchColumn();

// Total buyer accounts.
$totalBuyers = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM `user`
     WHERE role = 'buyer'"
)->fetchColumn();

// Total recorded transactions.
$totalTransactions = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM `transaction`"
)->fetchColumn();

// Total games with active discounts.
// DISTINCT avoids counting the same game twice
// if multiple regional price records exist.
$activeDiscounts = (int) $pdo->query(
    "SELECT COUNT(DISTINCT game_id)
     FROM prices
     WHERE discount_percent > 0
       AND CURDATE() BETWEEN discount_start
                         AND discount_end"
)->fetchColumn();

// Escape text before displaying it in HTML.
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

        .management {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-top: 30px;
        }

        .management-card {
            border: 2px solid black;
            min-height: 160px;
            padding: 20px;
        }

        .management-card h3 {
            margin-top: 0;
        }

        .coming-soon {
            color: #666;
            font-size: 14px;
            margin-top: 20px;
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
                align-items: flex-start;
                flex-direction: column;
            }
        }

    </style>

</head>

<body>

    <!-- ADMIN HEADER -->
    <header class="header">

        <h1>SteamDB - Administrator</h1>

        <div class="admin-info">

            <strong>
                Hi, <?= e($username) ?> (Admin)
            </strong>

            <!-- Admin logout -->
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


    <!-- DATABASE STATISTICS -->
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


    <!-- ADMINISTRATOR MANAGEMENT SECTIONS -->
    <h2>Management and Reports</h2>

    <section class="management">

        <!-- Game Management -->
        <div class="management-card">

            <h3>Manage Games</h3>

            <p>
                Add, edit, or deactivate games in SteamDB.
            </p>

            <p class="coming-soon">
                Coming next
            </p>

        </div>


        <!-- Discount Management -->
        <div class="management-card">

            <h3>Manage Discounts</h3>

            <p>
                Update discount percentages,
                start dates and end dates.
            </p>

            <p class="coming-soon">
                Coming soon
            </p>

        </div>


        <!-- Weekly Best -->
        <div class="management-card">

            <h3>Weekly Best</h3>

            <p>
                Add or remove featured games and
                manage weekly selections.
            </p>

            <p class="coming-soon">
                Coming soon
            </p>

        </div>


        <!-- User Management -->
        <div class="management-card">

            <h3>Manage Users</h3>

            <p>
                Create, update, or deactivate
                buyer accounts.
            </p>

            <p class="coming-soon">
                Coming soon
            </p>

        </div>


        <!-- AI Reports -->
        <div class="management-card">

            <h3>Generate AI Reports</h3>

            <p>
                Generate Administrator Activity
                and Weekly Best reports with
                AI summaries and CSV export.
            </p>

            <p class="coming-soon">
                Coming soon
            </p>

        </div>


        <!-- Automated Reports -->
        <div class="management-card">

            <h3>Automated Purchase Reports</h3>

            <p>
                Access daily purchase history
                CSV reports generated by n8n.
            </p>

            <p class="coming-soon">
                Coming soon
            </p>

        </div>

    </section>

</body>

</html>
