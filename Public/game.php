
<?php

session_start();

// Only authenticated users can view game details.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Config/mongodb.php';

// Get the selected game ID from the URL.
$gameId = filter_input(INPUT_GET, 'game_id', FILTER_VALIDATE_INT);

if (!$gameId || $gameId < 1) {
    http_response_code(400);
    exit('Invalid game ID.');
}

// STEP 1: Get game information from MariaDB.
$stmt = $pdo->prepare(
    "SELECT game_id, game_name, description, release_date
     FROM game
     WHERE game_id = :game_id"
);

$stmt->execute(['game_id' => $gameId]);
$game = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    http_response_code(404);
    exit('Game not found.');
}

// STEP 2: Get the game's prices and discounts from MariaDB.
$priceStmt = $pdo->prepare(
    "SELECT
        p.country_code,
        c.currency_code,
        p.base_price,
        p.discount_percent,
        p.discount_start,
        p.discount_end,

        CASE
            WHEN p.discount_percent > 0
             AND CURDATE() BETWEEN p.discount_start AND p.discount_end
            THEN ROUND(
                p.base_price * (1 - p.discount_percent / 100), 2
            )
            ELSE p.base_price
        END AS current_price,

        CASE
            WHEN p.discount_percent > 0
             AND CURDATE() BETWEEN p.discount_start AND p.discount_end
            THEN 1
            ELSE 0
        END AS discount_active

     FROM prices AS p
     LEFT JOIN country_code AS c
         ON p.country_code = c.country_code
     WHERE p.game_id = :game_id
     ORDER BY p.country_code"
);

$priceStmt->execute(['game_id' => $gameId]);
$prices = $priceStmt->fetchAll(PDO::FETCH_ASSOC);

// STEP 3: Get genre and tags from MongoDB.
$client = new MongoDB\Client("mongodb://127.0.0.1:27017");

$collection = $client
    ->selectDatabase("steam_content_db")
    ->selectCollection("game metadata");

$metadata = $collection->findOne([
    'game_id' => $gameId
]);

// Escape text before displaying it in HTML.
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= e($game['game_name']) ?> - SteamDB</title>
</head>

<body>

    <h1>SteamDB</h1>

    <a href="index.php">Back to All Games</a>

    <hr>

    <!-- Game title -->
    <h2><?= e($game['game_name']) ?></h2>

    <!-- Cover retrieved from MongoDB GridFS -->
    <img
        src="cover.php?game_id=<?= $gameId ?>"
        alt="<?= e($game['game_name']) ?> cover"
        width="180"
    >

    <h3>Game Information</h3>

    <p>
        <strong>Description:</strong>
        <?= e($game['description'] ?? '') ?>
    </p>

    <p>
        <strong>Release Date:</strong>
        <?= e($game['release_date']) ?>
    </p>

    <!-- MongoDB metadata -->
    <?php if ($metadata !== null): ?>

        <p>
            <strong>Genre:</strong>
            <?= e($metadata['genre'] ?? '') ?>
        </p>

        <p>
            <strong>Tags:</strong>

            <?php
                $tags = [];

                foreach ($metadata['tags'] ?? [] as $tag) {
                    $tags[] = e($tag);
                }

                echo implode(', ', $tags);
            ?>
        </p>

    <?php endif; ?>

    <hr>

    <h3>Game Pricing</h3>

    <?php if (empty($prices)): ?>

        <p>Price information is not available.</p>

    <?php else: ?>

        <?php foreach ($prices as $price): ?>

            <p>
                <strong>Country:</strong>
                <?= e($price['country_code']) ?>
            </p>

            <p>
                <strong>Original Price:</strong>
                <?= e($price['currency_code']) ?>
                <?= number_format((float) $price['base_price'], 2) ?>
            </p>

            <?php if ((int) $price['discount_active'] === 1): ?>

                <p>
                    <strong>Discount:</strong>
                    <?= e($price['discount_percent']) ?>%
                </p>

            <?php endif; ?>

            <p>
                <strong>Current Price:</strong>
                <?= e($price['currency_code']) ?>
                <?= number_format((float) $price['current_price'], 2) ?>
            </p>

            
            <?php if ((int) $price['discount_active'] === 1): ?>

    <!-- Show the discount period only during an active sale. -->
    <p>
        <strong>Discount Period:</strong>
        <?= e($price['discount_start']) ?>
        to
        <?= e($price['discount_end']) ?>
    </p>

<?php endif; ?>


            <hr>

        <?php endforeach; ?>

    <?php endif; ?>
    
<
<!-- Open the payment page for the selected game. -->
<form action="payment.php" method="GET"
      style="text-align: center; margin: 40px 0;">

    <input
        type="hidden"
        name="game_id"
        value="<?= (int) $gameId ?>"
    >

    <button type="submit"
            style="padding: 12px 40px; font-size: 18px;">
        Buy
    </button>

</form>


</div>


</body>
</html>
