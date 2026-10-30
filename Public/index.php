
<?php

session_start();

// Only authenticated buyers can access the catalog.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Config/mongodb.php';

$username = $_SESSION['username'];

// STEP 1: Load the eight allowed genres from our JSON file.
$genrePath = __DIR__ . '/../seed-data/genre_options.json';

if (!is_file($genrePath)) {
    http_response_code(500);
    exit('Genre configuration file not found.');
}

$genres = json_decode(file_get_contents($genrePath), true);

if (!is_array($genres)) {
    http_response_code(500);
    exit('Invalid genre configuration.');
}

// STEP 2: Read the buyer's selected genre.
$selectedGenre = $_GET['genre'] ?? '';

if (
    !is_string($selectedGenre) ||
    (
        $selectedGenre !== '' &&
        !in_array($selectedGenre, $genres, true)
    )
) {
    $selectedGenre = '';
}

// Only these sorting options are allowed.
$sortOptions = [
    'name_asc' => [
        'label' => 'Name: A-Z',
        'sql' => 'game_name ASC, game_id ASC'
    ],
    'name_desc' => [
        'label' => 'Name: Z-A',
        'sql' => 'game_name DESC, game_id DESC'
    ],
    'newest' => [
        'label' => 'Newest First',
        'sql' => 'release_date DESC, game_id ASC'
    ],
    'oldest' => [
        'label' => 'Oldest First',
        'sql' => 'release_date ASC, game_id ASC'
    ]
];

$selectedSort = $_GET['sort'] ?? 'name_asc';

if (
    !is_string($selectedSort) ||
    !isset($sortOptions[$selectedSort])
) {
    $selectedSort = 'name_asc';
}


// STEP 3: Apply the genre filter in MongoDB.
$mongoFilter = [];

if ($selectedGenre !== '') {
    // MongoDB matches a value inside the tags array.
    $mongoFilter = ['tags' => $selectedGenre];
}

$query = new MongoDB\Driver\Query($mongoFilter);

$results = $mongoManager->executeQuery(
    'steam_content_db.game metadata',
    $query
);

// Organize the matching metadata by game ID.
$metadataByGameId = [];

foreach ($results as $document) {
    $metadataByGameId[(int) $document->game_id] = $document;
}


// STEP 4: Retrieve the games from MariaDB.
$sql = "SELECT game_id, game_name, description, release_date
        FROM game";

$params = [];

// Restrict MariaDB results to the IDs matched by MongoDB.
if ($selectedGenre !== '') {

    $matchingIds = array_keys($metadataByGameId);

    if (empty($matchingIds)) {
        $sql .= " WHERE 1 = 0";
    } else {
        $placeholders = implode(
            ',',
            array_fill(0, count($matchingIds), '?')
        );

        $sql .= " WHERE game_id IN ($placeholders)";
        $params = $matchingIds;
    }
}

// Sorting comes from our predefined list, not raw user input.
$sql .= " ORDER BY " . $sortOptions[$selectedSort]['sql'];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$games = $stmt->fetchAll(PDO::FETCH_ASSOC);


// STEP 5: Find all games with images stored in GridFS.
$client = new MongoDB\Client("mongodb://127.0.0.1:27017");

$coverFiles = $client
    ->selectDatabase('steam_content_db')
    ->selectCollection('game_covers.files');

$coverGameIds = array_map(
    'intval',
    $coverFiles->distinct('metadata.game_id')
);

// Escape database values before displaying them in HTML.
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - All Games</title>

    <style>
        body {
            margin: 20px;
        }

        .navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
        }

        .filter-section {
            display: flex;
            align-items: end;
            gap: 20px;
            flex-wrap: wrap;
            margin: 30px 0;
        }

        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-section select {
            padding: 8px;
            min-width: 160px;
        }

        .game {
            margin-bottom: 30px;
        }

        .game img {
            width: 180px;
            height: auto;
            border-radius: 8px;
        }

        button {
            cursor: pointer;
        }
    </style>

</head>

<body>

    <h1>SteamDB</h1>

    <!-- Navigation -->
    <nav class="navigation">

        <button type="button"
                onclick="window.location.href='home.php'">
            Back to Home
        </button>

        <button type="button"
                onclick="window.location.href='profile.php'">
            Hi, <?= e($username) ?>
        </button>

    </nav>

    <hr>

    <h2>All Games</h2>
    <p>Explore our games!</p>


    <!-- FILTER AND SORTING FORM -->
    <form class="filter-section"
          action="index.php"
          method="GET">

        <!-- Genre filtering -->
        <div class="filter-field">

            <label for="genre">Filter by Genre:</label>

            <select id="genre" name="genre">

                <option value="">All Genres</option>

                <?php foreach ($genres as $genre): ?>

                    <option
                        value="<?= e($genre) ?>"
                        <?= $selectedGenre === $genre
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($genre) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- Sorting -->
        <div class="filter-field">

            <label for="sort">Sort Games:</label>

            <select id="sort" name="sort">

                <?php foreach ($sortOptions as $value => $option): ?>

                    <option
                        value="<?= e($value) ?>"
                        <?= $selectedSort === $value
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($option['label']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <button type="submit">Apply</button>

        <a href="index.php">Reset</a>

    </form>


    <!-- Number of matching games -->
    <p>
        <strong>Games Found:</strong>
        <?= count($games) ?>
    </p>

    <hr>


    <!-- GAME LIST -->
    <?php if (empty($games)): ?>

        <p>No games match the selected genre.</p>

    <?php else: ?>

        <?php foreach ($games as $game): ?>

            <?php
                $gameId = (int) $game['game_id'];

                // Individual details page for the selected game.
                $gameUrl = 'game.php?game_id=' . $gameId;
            ?>

            <div class="game">

                <!-- Clickable game cover from GridFS -->
                <?php if (in_array($gameId, $coverGameIds, true)): ?>

                    <a href="<?= e($gameUrl) ?>">

                        <img
                            src="cover.php?game_id=<?= $gameId ?>"
                            alt="<?= e($game['game_name']) ?> cover"
                        >

                    </a>

                <?php endif; ?>


                <!-- Clickable game name -->
                <h2>
                    <a href="<?= e($gameUrl) ?>">
                        <?= e($game['game_name']) ?>
                    </a>
                </h2>


                <!-- Game information from MariaDB -->
                <p>
                    <strong>Description:</strong>
                    <?= e($game['description'] ?? '') ?>
                </p>

                <p>
                    <strong>Release Date:</strong>
                    <?= e($game['release_date'] ?? '') ?>
                </p>


                <!-- Game metadata from MongoDB -->
                <?php if (isset($metadataByGameId[$gameId])): ?>

                    <?php
                        $metadata = $metadataByGameId[$gameId];
                    ?>

                    <p>
                        <strong>Genre:</strong>
                        <?= e($metadata->genre ?? '') ?>
                    </p>

                    <p>
                        <strong>Tags:</strong>

                        <?php
                            $tags = [];

                            foreach ($metadata->tags ?? [] as $tag) {
                                $tags[] = e($tag);
                            }

                            echo implode(', ', $tags);
                        ?>
                    </p>

                <?php endif; ?>

            </div>

            <hr>

        <?php endforeach; ?>

    <?php endif; ?>

</body>
</html>
