<?php

require_once __DIR__ . '/../Config/mongodb.php';

// Identify which game's cover the browser is requesting.
$gameId = filter_input(INPUT_GET, 'game_id', FILTER_VALIDATE_INT);

if (!$gameId || $gameId < 1) {
    http_response_code(400);
    exit("Invalid game ID.");
}

try {
    $client = new MongoDB\Client("mongodb://127.0.0.1:27017");
    $database = $client->selectDatabase("steam_content_db");

    // Find the most recently uploaded cover for this game.
    $file = $database->selectCollection("game_covers.files")->findOne(
        ["metadata.game_id" => $gameId],
        ["sort" => ["uploadDate" => -1]]
    );

    if ($file === null) {
        http_response_code(404);
        exit("Game cover not found.");
    }

    // Read the actual image from GridFS.
    $bucket = $database->selectGridFSBucket([
        "bucketName" => "game_covers"
    ]);

    $stream = $bucket->openDownloadStream($file["_id"]);

    // Tell the browser what kind of image it is receiving.
    header("Content-Type: " . $file["metadata"]["content_type"]);
    header("Content-Length: " . $file["length"]);

    // Send the image bytes to the browser.
    fpassthru($stream);
    fclose($stream);

} catch (Throwable $e) {
    error_log("GridFS error: " . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
    }
}
