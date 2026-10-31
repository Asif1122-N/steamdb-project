<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Administrator access only.');
}
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../Config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function submittedText(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$genreFile = __DIR__ . '/../../seed-data/genre_options.json';
$genres = is_file($genreFile)
    ? json_decode(file_get_contents($genreFile), true)
    : null;
if (!is_array($genres) || count($genres) === 0) {
    http_response_code(500);
    exit('Genre options are not configured.');
}

$countries = $pdo->query(
    'SELECT country_code, currency_code FROM country_code ORDER BY country_code'
)->fetchAll(PDO::FETCH_ASSOC);
$validCountries = array_column($countries, 'country_code');

if (empty($_SESSION['add_game_csrf'])) {
    $_SESSION['add_game_csrf'] = bin2hex(random_bytes(32));
}

$name = '';
$description = '';
$releaseDate = '';
$price = '';
$country = '';
$genre = $genres[0];
$selectedTags = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = submittedText('game_name');
    $description = submittedText('description');
    $releaseDate = submittedText('release_date');
    $price = submittedText('base_price');
    $country = submittedText('country_code');
    $genre = submittedText('genre');
    $rawTags = $_POST['tags'] ?? [];

    if (!is_array($rawTags)) {
        $errors[] = 'Invalid genre tags.';
        $rawTags = [];
    }
    foreach ($rawTags as $tag) {
        if (!is_string($tag) || !in_array($tag, $genres, true)) {
            $errors[] = 'Select tags only from the available genres.';
            break;
        }
        $selectedTags[] = $tag;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) ||
        !hash_equals($_SESSION['add_game_csrf'], $token)) {
        $errors[] = 'Invalid form request. Refresh the page and try again.';
    }
    if ($name === '' || strlen($name) > 100) {
        $errors[] = 'Game name is required (maximum 100 characters).';
    }
    if ($description === '') {
        $errors[] = 'Game description is required.';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDate);
    if (!$date || $date->format('Y-m-d') !== $releaseDate) {
        $errors[] = 'Enter a valid release date.';
    }
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $price)) {
        $errors[] = 'Enter a valid nonnegative price (maximum 99999999.99).';
    }
    if (!in_array($country, $validCountries, true)) {
        $errors[] = 'Select a valid country.';
    }
    if (!in_array($genre, $genres, true)) {
        $errors[] = 'Select a valid primary genre.';
    }
    $tags = array_values(array_unique(array_merge([$genre], $selectedTags)));

    // Inspect the uploaded file; do not trust its original filename or MIME label.
    $upload = $_FILES['cover'] ?? null;
    $tmpPath = '';
    $extension = '';
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'A PNG or JPEG game cover is required.';
    } elseif (($upload['size'] ?? 0) > 5 * 1024 * 1024 || ($upload['size'] ?? 0) < 1) {
        $errors[] = 'Cover must be smaller than 5 MB.';
    } else {
        $tmpPath = $upload['tmp_name'] ?? '';
        if (!is_string($tmpPath) || !is_uploaded_file($tmpPath)) {
            $errors[] = 'Invalid uploaded image.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
            $extension = match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => ''
            };
            if ($extension === '' || getimagesize($tmpPath) === false) {
                $errors[] = 'Cover must be a valid PNG or JPEG image.';
            }
        }
    }

    if (empty($errors)) {
        $gameId = null;
        $metadataInserted = false;
        $coverId = null;
        $metadataCollection = null;
        $bucket = null;

        try {
            $pdo->beginTransaction();

            $exists = $pdo->prepare('SELECT game_id FROM game WHERE game_name = ? LIMIT 1');
            $exists->execute([$name]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('A game with this name already exists.');
            }

           // Create the game inside the MariaDB transaction.
// The transaction is committed after the required
// MongoDB metadata, GridFS cover, and activity log
// operations have completed.
            $insertGame = $pdo->prepare(
                'INSERT INTO game (game_name, description, release_date, created_at)
                 VALUES (?, ?, ?, NOW())'
            );
            $insertGame->execute([$name, $description, $releaseDate]);
            $gameId = (int) $pdo->lastInsertId();

            $insertPrice = $pdo->prepare(
                'INSERT INTO prices (game_id, country_code, base_price, discount_percent)
                 VALUES (?, ?, ?, 0)'
            );
            $insertPrice->execute([$gameId, $country, $price]);

            $client = new MongoDB\Client('mongodb://127.0.0.1:27017');
            $mongoDb = $client->selectDatabase('steam_content_db');
            $metadataCollection = $mongoDb->selectCollection('game metadata');
            $metadataCollection->insertOne([
                'game_id' => $gameId,
                'genre' => $genre,
                'tags' => $tags
            ]);
            $metadataInserted = true;

            $bucket = $mongoDb->selectGridFSBucket(['bucketName' => 'game_covers']);
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to read uploaded image.');
            }
            try {
                $coverId = $bucket->uploadFromStream(
                    'game_' . $gameId . '.' . $extension,
                    $stream,
                    ['metadata' => ['game_id' => $gameId]]
                );
            } finally {
                fclose($stream);
            }

            $log = $pdo->prepare(
                "INSERT INTO admin_activity_log
                 (admin_user_id, action_type, entity_type, entity_id)
                 VALUES (?, 'ADD_GAME', 'game', ?)"
            );
            $log->execute([(int) $_SESSION['user_id'], $gameId]);
            $pdo->commit();

            $_SESSION['admin_game_message'] = 'Game successfully added: ' . $name . ' (ID ' . $gameId . ').';
            $_SESSION['add_game_csrf'] = bin2hex(random_bytes(32));
            header('Location: games.php');
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Best-effort cleanup. SQL and MongoDB do not share one transaction.
            try {
                if ($coverId !== null && $bucket !== null) {
                    $bucket->delete($coverId);
                }
                if ($metadataInserted && $metadataCollection !== null) {
                    $metadataCollection->deleteOne(['game_id' => $gameId]);
                }
            } catch (Throwable $cleanupError) {
                error_log('Add game cleanup failed: ' . $cleanupError->getMessage());
            }
            error_log('Add game failed: ' . $exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Could not save the game. Check the PHP server log and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SteamDB - Add New Game</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .top { display: flex; justify-content: space-between; align-items: center; }
        .form { max-width: 680px; }
        .field { margin: 16px 0; }
        label { display: block; margin-bottom: 7px; }
        input[type="text"], input[type="number"], input[type="date"],
        select, textarea { padding: 9px; width: 100%; box-sizing: border-box; }
        .tags { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .tags label { display: block; }
        button { padding: 10px 18px; cursor: pointer; }
        .errors { border: 1px solid #b22; padding: 12px; color: #a00; }
    </style>
</head>
<body>
    <h1>SteamDB - Administrator</h1>
    <nav class="top">
        <a href="games.php">Back to Manage Games</a>
        <strong>Hi, <?= e($_SESSION['username'] ?? 'Admin') ?> (Admin)</strong>
    </nav>
    <hr>
    <main class="form">
        <h2>Add New Game</h2>
        <p>Game details and price are saved in MariaDB; tags in MongoDB; cover in GridFS.</p>

        <?php if ($errors): ?>
            <div class="errors"><strong>Please fix:</strong><ul>
                <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul></div>
        <?php endif; ?>

        <form action="add_game.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['add_game_csrf']) ?>">
            <div class="field">
                <label for="game_name">Game name *</label>
                <input type="text" id="game_name" name="game_name" maxlength="100" required value="<?= e($name) ?>">
            </div>
            <div class="field">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="3" required><?= e($description) ?></textarea>
            </div>
            <div class="field">
                <label for="release_date">Release date *</label>
                <input type="date" id="release_date" name="release_date" required value="<?= e($releaseDate) ?>">
            </div>
            <div class="field">
                <label for="country_code">Price country / currency *</label>
                <select id="country_code" name="country_code" required>
                    <option value="">Select country</option>
                    <?php foreach ($countries as $row): ?>
                        <option value="<?= e($row['country_code']) ?>" <?= $country === $row['country_code'] ? 'selected' : '' ?>>
                            <?= e($row['country_code']) ?> (<?= e($row['currency_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="base_price">Base price *</label>
                <input type="number" id="base_price" name="base_price" min="0" max="99999999.99" step="0.01" required value="<?= e($price) ?>">
            </div>
            <div class="field">
                <label for="genre">Primary genre *</label>
                <select name="genre" id="genre" required>
                    <?php foreach ($genres as $option): ?>
                        <option value="<?= e($option) ?>" <?= $genre === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <fieldset class="field">
                <legend>Additional genre tags (optional)</legend>
                <div class="tags">
                    <?php foreach ($genres as $option): ?>
                        <label><input type="checkbox" name="tags[]" value="<?= e($option) ?>" <?= in_array($option, $selectedTags, true) ? 'checked' : '' ?>> <?= e($option) ?></label>
                    <?php endforeach; ?>
                </div>
                <p>The primary genre is automatically included among the tags.</p>
            </fieldset>
            <div class="field">
                <label for="cover">Game cover (PNG or JPEG, max 5 MB) *</label>
                <input type="file" id="cover" name="cover" accept="image/png,image/jpeg" required>
            </div>
            <button type="submit">Save New Game</button>
        </form>
    </main>
</body>
</html>
