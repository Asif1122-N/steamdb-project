<?php

require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Config/mongodb.php';

// Folder containing our game cover images.
$imageFolder = __DIR__ . '/../sample-files/';

// Connect to the existing MongoDB GridFS bucket.
$client = new MongoDB\Client("mongodb://127.0.0.1:27017");
$database = $client->selectDatabase("steam_content_db");

$bucket = $database->selectGridFSBucket([
    'bucketName' => 'game_covers'
]);

$filesCollection = $database->selectCollection("game_covers.files");

// Get the official game IDs and names from MariaDB.
$stmt = $pdo->query(
    "SELECT game_id, game_name FROM game ORDER BY game_id"
);

$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

$uploaded = 0;
$skipped = 0;
$failed = 0;

foreach ($games as $game) {

    $gameId = (int) $game['game_id'];
    $gameName = $game['game_name'];

    // Example: Cyber Warriors becomes Cyber_Warriors.png.
    $filename = preg_replace('/\s+/', '_', trim($gameName)) . '.png';
    $imagePath = $imageFolder . $filename;

    if (!is_file($imagePath)) {
        echo "Missing image: $filename\n";
        $failed++;
        continue;
    }

    try {
        // Don't upload a second cover if the game already has one.
        $existing = $filesCollection->findOne([
            'metadata.game_id' => $gameId
        ]);

        if ($existing !== null) {
            echo "Already exists: $gameName ($gameId)\n";
            $skipped++;
            continue;
        }

        $imageInfo = getimagesize($imagePath);

        if ($imageInfo === false ||
            $imageInfo['mime'] !== 'image/png' ||
            $imageInfo[0] !== 1024 ||
            $imageInfo[1] !== 1536) {

            echo "Invalid PNG or resolution: $filename\n";
            $failed++;
            continue;
        }

        $stream = fopen($imagePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Cannot open $filename");
        }

        try {
            // GridFS stores the image bytes and links them to the game ID.
            $fileId = $bucket->uploadFromStream(
                $filename,
                $stream,
                [
                    'metadata' => [
                        'game_id' => $gameId,
                        'content_type' => 'image/png'
                    ]
                ]
            );
        } finally {
            fclose($stream);
        }

        echo "Uploaded: $gameName ($gameId)\n";
        $uploaded++;

    } catch (Throwable $e) {
        echo "Failed: $gameName - " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nUpload complete!\n";
echo "Uploaded: $uploaded\n";
echo "Skipped existing: $skipped\n";
echo "Missing or failed: $failed\n";
