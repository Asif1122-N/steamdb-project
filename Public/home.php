
<?php

session_start();

// Only logged-in buyers can access the homepage.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

require_once __DIR__ . '/../Config/database.php';

$username = $_SESSION['username'];
$search = $_GET['search'] ?? '';
$results = [];

if (!is_string($search)) {
    $search = '';
}

$search = trim($search);

// Search game names in MariaDB.
if ($search !== '') {

    $stmt = $pdo->prepare(
        "SELECT game_id, game_name
         FROM game
         WHERE game_name LIKE :search
         ORDER BY game_name"
    );

    $stmt->execute([
        'search' => '%' . $search . '%'
    ]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - Home</title>

    <style>
        /* Basic layout. Full website styling comes later. */

        body {
            margin: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .search-section {
            display: flex;
            align-items: center;
            margin-top: 50px;
        }

        .search-section form {
            display: flex;
            gap: 10px;
        }

        .search-section input {
            width: 300px;
            max-width: 65vw;
            padding: 8px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 100px;
        }

        .card {
            min-height: 250px;
            border: 2px solid black;
            background: white;
            font-size: 22px;
            cursor: pointer;
        }

        button {
            cursor: pointer;
        }

        @media (max-width: 650px) {
            .cards {
                grid-template-columns: 1fr;
                margin-top: 40px;
            }
        }
    </style>

</head>

<body>

    <!-- SteamDB header -->
    <header class="header">

        <h1>SteamDB</h1>

        
<!-- Open the logged-in user's personal profile. -->
<button
    type="button"
    onclick="window.location.href='profile.php'">

    Hi, <?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>

</button>


    </header>

    <!-- Game search -->
    <section class="search-section">

        <form action="home.php" method="GET">

            <input
                type="search"
                name="search"
                placeholder="Search games..."
                value="<?= htmlspecialchars(
                    $search,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <button type="submit">Search</button>

        </form>

    </section>

    <!-- Search results from MariaDB -->
    <?php if ($search !== ''): ?>

        <h2>Search Results</h2>

        <?php if (empty($results)): ?>

            <p>No matching games found.</p>

        <?php else: ?>

            <?php foreach ($results as $game): ?>

                <p>
                    <?= htmlspecialchars(
                        $game['game_name'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

    <!-- Three homepage cards -->
    <section class="cards">

        <!-- All Games: opens existing game catalog. -->
        <button
            class="card"
            type="button"
            onclick="window.location.href='index.php'">

            All Games

        </button>

        
<!-- Open the dynamic Discount Games page. -->
<button
    class="card"
    type="button"
    onclick="window.location.href='discount_games.php'">

    Discount Games

</button>


        <!-- Weekly Best: selected by admin later. -->
        <button
            class="card"
            type="button"
            onclick="alert('Weekly Best page coming soon.')">

            Weekly Best

        </button>

    </section>

</body>
</html>
