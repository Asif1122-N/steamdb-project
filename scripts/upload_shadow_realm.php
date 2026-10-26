
<?php

require_once __DIR__ . '/../Config/mongodb.php';

$imagePath = __DIR__ . '/../sample-files/Shadow_Realm.png';

// Check that our game cover exists before uploading.
if (!file_exists($imagePath)) {
    exit("Image file not found.\n");
}

$imageStream = fopen($imagePath, 'rb');

if ($imageStream === false) {
    exit("Unable to open image file.\n");
}

try {
    // Connect using the high-level MongoDB PHP library.
    $client = new MongoDB\Client("mongodb://127.0.0.1:27017");

    // Select our database and the GridFS game covers bucket.
    $database = $client->selectDatabase("steam_content_db");
    $bucket = $database->selectGridFSBucket([
        'bucketName' => 'game_covers'
    ]);

    // Upload the actual image bytes with associated game metadata.
    $fileId = $bucket->uploadFromStream(
        "Shadow_Realm.png",
        $imageStream,
        [
            'metadata' => [
                'game_id' => 401,
                'content_type' => 'image/png'
            ]
        ]
    );

    echo "Image uploaded successfully!\n";
    echo "GridFS File ID: " . (string) $fileId . "\n";

} catch (Throwable $e) {
    echo "Upload failed: " . $e->getMessage() . "\n";
} finally {
    fclose($imageStream);
}
