
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SteamDB Phase 5</title>
</head>

<body>

<h1>Welcome to SteamDB</h1>
<p>Explore our games!</p>

<?php

// Connect to MariaDB and MongoDB.
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Config/mongodb.php';


// STEP 1: Retrieve metadata for all games from MongoDB.
$query = new MongoDB\Driver\Query([]);

$results = $mongoManager->executeQuery(
    "steam_content_db.game metadata",
    $query
);


// STEP 2: Organize MongoDB documents by game_id.
$metadataByGameId = [];

foreach ($results as $document) {

    $metadataByGameId[(int) $document->game_id] = $document;

}


// STEP 3: Retrieve game records from MariaDB.
$sql = "SELECT game_id, game_name, description
        FROM game
        ORDER BY game_id";

$stmt = $pdo->query($sql);

$games = $stmt->fetchAll(PDO::FETCH_ASSOC);


// STEP 4: Find all game IDs with covers in MongoDB GridFS.
$client = new MongoDB\Client("mongodb://127.0.0.1:27017");

$coverFiles = $client
    ->selectDatabase("steam_content_db")
    ->selectCollection("game_covers.files");

$coverGameIds = array_map(
    'intval',
    $coverFiles->distinct("metadata.game_id")
);


// STEP 5: Display games with clickable covers and names.
foreach ($games as $game) {

    // Get the current game's ID from MariaDB.
    $gameId = (int) $game['game_id'];

    $gameName = htmlspecialchars(
        $game['game_name'],
        ENT_QUOTES,
        'UTF-8'
    );

    // The individual game details page for this game.
    $gameUrl = "game.php?game_id=" . $gameId;


    // CHANGE 1: Make the game cover image clickable.
    if (in_array($gameId, $coverGameIds, true)) {

        echo '<a href="' . $gameUrl . '">
            <img
                src="cover.php?game_id=' . $gameId . '"
                alt="' . $gameName . ' cover"
                style="width: 180px;
                       height: auto;
                       border-radius: 8px;"
            >
        </a>';
    }


    // CHANGE 2: Make the game name clickable.
    echo '<h2>
        <a href="' . $gameUrl . '">'
            . $gameName .
        '</a>
    </h2>';


    // Display the game's description from MariaDB.
    echo "<p>"
        . htmlspecialchars(
            $game['description'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        )
        . "</p>";


    // STEP 6: Display matching MongoDB metadata.
    if (isset($metadataByGameId[$gameId])) {

        $metadata = $metadataByGameId[$gameId];

        echo "<p><strong>Genre:</strong> "
            . htmlspecialchars(
                (string) ($metadata->genre ?? ''),
                ENT_QUOTES,
                'UTF-8'
            )
            . "</p>";

        // Convert MongoDB tags into displayable text.
        $tags = [];

        foreach ($metadata->tags ?? [] as $tag) {

            $tags[] = htmlspecialchars(
                (string) $tag,
                ENT_QUOTES,
                'UTF-8'
            );
        }

        echo "<p><strong>Tags:</strong> "
            . implode(", ", $tags)
            . "</p>";
    }

    // Add spacing between game records.
    echo "<hr>";
}

?>

</body>
</html>
