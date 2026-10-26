<?php

// Run from PowerShell: php scripts/import_game_metadata.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this importer from the command line.');
}

require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Config/mongodb.php';

try {
    $genrePath = __DIR__ . '/../seed-data/genre_options.json';
    $dataPath = __DIR__ . '/../seed-data/game_metadata.json';

    if (!is_file($genrePath) || !is_file($dataPath)) {
        throw new RuntimeException('Missing genre_options.json or game_metadata.json in seed-data.');
    }

    $allowedGenres = json_decode(file_get_contents($genrePath), true, 512, JSON_THROW_ON_ERROR);
    $rows = json_decode(file_get_contents($dataPath), true, 512, JSON_THROW_ON_ERROR);

    // The same eight genre names are used for both the primary genre and the tags.
    if (!is_array($allowedGenres) || !array_is_list($allowedGenres)
        || count($allowedGenres) !== 8
        || count($allowedGenres) !== count(array_unique($allowedGenres))) {
        throw new RuntimeException('The genre catalog must contain exactly eight unique genres.');
    }
    foreach ($allowedGenres as $genre) {
        if (!is_string($genre) || trim($genre) === '') {
            throw new RuntimeException('Each genre must be a nonempty string.');
        }
    }

    $idsInMariaDb = array_fill_keys(
        array_map('intval', $pdo->query('SELECT game_id FROM game')->fetchAll(PDO::FETCH_COLUMN)),
        true
    );

    // Validate every record before importing anything into MongoDB.
    if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($idsInMariaDb)) {
        throw new RuntimeException('Metadata must contain exactly one record per MariaDB game.');
    }

    $seenIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Invalid metadata record.');
        }
        $id = $row['game_id'] ?? null;
        $genre = $row['genre'] ?? null;
        $tags = $row['tags'] ?? null;

        if (!is_int($id) || !isset($idsInMariaDb[$id]) || isset($seenIds[$id])) {
            throw new RuntimeException('Duplicate, missing, or unknown game ID in metadata.');
        }
        $seenIds[$id] = true;

        if (!is_string($genre) || !in_array($genre, $allowedGenres, true)) {
            throw new RuntimeException("Invalid primary genre for game {$id}.");
        }
        if (!is_array($tags) || !array_is_list($tags) || count($tags) < 1
            || count($tags) > count($allowedGenres)
            || count($tags) !== count(array_unique($tags))
            || !in_array($genre, $tags, true)) {
            throw new RuntimeException("Invalid tag list for game {$id}.");
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || !in_array($tag, $allowedGenres, true)) {
                throw new RuntimeException("Unapproved tag for game {$id}.");
            }
        }
    }

    $client = new MongoDB\Client('mongodb://127.0.0.1:27017');
    $collection = $client->selectDatabase('steam_content_db')
        ->selectCollection('game metadata'); // The collection name contains a space.

    $created = 0;
    $updated = 0;
    $unchanged = 0;

    foreach ($rows as $row) {
        // Upsert by game_id; retain existing platforms and features.
        $result = $collection->updateOne(
            ['game_id' => $row['game_id']],
            ['$set' => ['genre' => $row['genre'], 'tags' => $row['tags']]],
            ['upsert' => true]
        );

        $created += $result->getUpsertedCount();
        $updated += $result->getModifiedCount();
        if ($result->getUpsertedCount() === 0 && $result->getModifiedCount() === 0) {
            $unchanged++;
        }
    }

    // A multikey index supports future buyer filtering with { tags: 'Action' }.
    $collection->createIndex(['tags' => 1], ['name' => 'idx_genre_tags']);

    echo "Metadata import complete.\n";
    echo "New: {$created}; Updated: {$updated}; Already current: {$unchanged}\n";
    echo 'Total processed: ' . count($rows) . " games.\n";
    echo 'Allowed genres/tags: ' . implode(', ', $allowedGenres) . "\n";

} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
