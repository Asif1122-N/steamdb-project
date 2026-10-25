
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


// STEP 1: Retrieve Shadow Realm's metadata from MongoDB.
$query = new MongoDB\Driver\Query(
    ["game_id" => 401]
);

$results = $mongoManager->executeQuery(
    "steam_content_db.game metadata",
    $query
);


// STEP 2: Organize MongoDB documents by game_id.
$metadataByGameId = [];

foreach ($results as $document) {

    $metadataByGameId[(int)$document->game_id] = $document;

}


// STEP 3: Retrieve game records from MariaDB.
$sql = "SELECT game_id, game_name, description
        FROM game
        ORDER BY game_id";

$stmt = $pdo->query($sql);

$games = $stmt->fetchAll(PDO::FETCH_ASSOC);


// STEP 4: Display MariaDB information and matching MongoDB metadata.
foreach ($games as $game) {

    $gameId = (int)$game['game_id'];

    echo "<h2>" . htmlspecialchars($game['game_name']) . "</h2>";

    echo "<p>" . htmlspecialchars($game['description'] ?? '') . "</p>";


    // Add MongoDB metadata only when the game has a matching document.
    if (isset($metadataByGameId[$gameId])) {

        $metadata = $metadataByGameId[$gameId];

        echo "<p><strong>Genre:</strong> "
            . htmlspecialchars((string)$metadata->genre)
            . "</p>";

        // Convert the MongoDB tags array into displayable text.
        $tags = [];

        foreach ($metadata->tags as $tag) {
            $tags[] = htmlspecialchars((string)$tag);
        }

        echo "<p><strong>Tags:</strong> "
            . implode(", ", $tags)
            . "</p>";
    }
}

?>

</body>
</html>
