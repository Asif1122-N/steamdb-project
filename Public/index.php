
<?php

session_start();

// Only logged-in buyers can access the All Games page.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

// Connect to MariaDB and MongoDB.
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Config/mongodb.php';
require_once __DIR__ . '/../vendor/autoload.php';

$username = $_SESSION['username'];


// STEP 1: Retrieve metadata for all games from MongoDB.
$query = new MongoDB\Driver\Query([]);

$results = $mongoManager->executeQuery(
    "steam_content_db.game metadata",
    $query
);


// STEP 2: Organize MongoDB metadata by game ID.
$metadataByGameId = [];

foreach ($results as $document) {

    $metadataByGameId[(int) $document->game_id] = $document;

}


// STEP 3: Retrieve all games from MariaDB.
$sql = "SELECT game_id, game_name, description
        FROM game
        ORDER BY game_id";

$stmt = $pdo->query($sql);

$games = $stmt->fetchAll(PDO::FETCH_ASSOC);


// STEP 4: Find games that have cover images in GridFS.
$client = new MongoDB\Client("mongodb://127.0.0.1:27017");

$coverFiles = $client
    ->selectDatabase("steam_content_db")
    ->selectCollection("game_covers.files");

$coverGameIds = array_map(
    'intval',
    $coverFiles->distinct("metadata.game_id")
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - All Games</title>

    <style>

        /* Basic page spacing */
        body {
            margin: 20px;
        }

        /* Place navigation buttons on opposite sides */
        .navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            margin: 20px 0;
        }

        button {
            cursor: pointer;
        }

        /* Basic spacing between game records */
        .game {
            margin-bottom: 30px;
        }

    </style>

</head>

<body>

    <!-- Website heading -->
    <h1>SteamDB</h1>


    <!-- NAVIGATION SECTION -->
    <nav class="navigation">

        <!-- LEFT: Back to Home button -->
        <button
            type="button"
            onclick="window.location.href='home.php'">

            Back to Home

        </button>


        <!-- RIGHT: Logged-in user's profile button -->
        <button
            type="button"
            onclick="window.location.href='profile.php'">

            Hi, <?= htmlspecialchars(
                $username,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </button>

    </nav>


    <hr>

    <h2>All Games</h2>

    <p>Explore our games!</p>


    <!-- GAME CATALOG -->
    <?php foreach ($games as $game): ?>

        <?php

        $gameId = (int) $game['game_id'];

        $gameName = htmlspecialchars(
            $game['game_name'],
            ENT_QUOTES,
            'UTF-8'
        );

        // Dynamic URL for each individual game.
        $gameUrl = "game.php?game_id=" . $gameId;

        ?>

        <div class="game">

            <!-- Clickable game cover from MongoDB GridFS -->
            <?php if (in_array($gameId, $coverGameIds, true)): ?>

                <a href="<?= $gameUrl ?>">

                    <img
                        src="cover.php?game_id=<?= $gameId ?>"
                        alt="<?= $gameName ?> cover"
                        style="
                            width: 180px;
                            height: auto;
                            border-radius: 8px;
                        "
                    >

                </a>

            <?php endif; ?>


            <!-- Clickable game name -->
            <h2>

                <a href="<?= $gameUrl ?>">
                    <?= $gameName ?>
                </a>

            </h2>


            <!-- Game description from MariaDB -->
            <p>
                <?= htmlspecialchars(
                    $game['description'] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>


            <!-- Genre and tags from MongoDB -->
            <?php if (isset($metadataByGameId[$gameId])): ?>

                <?php
                $metadata = $metadataByGameId[$gameId];
                ?>

                <p>
                    <strong>Genre:</strong>

                    <?= htmlspecialchars(
                        (string) ($metadata->genre ?? ''),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>


                <p>
                    <strong>Tags:</strong>

                    <?php

                    $tags = [];

                    foreach ($metadata->tags ?? [] as $tag) {

                        $tags[] = htmlspecialchars(
                            (string) $tag,
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    }

                    echo implode(', ', $tags);

                    ?>

                </p>

            <?php endif; ?>

        </div>

        <hr>

    <?php endforeach; ?>

</body>
</html>
