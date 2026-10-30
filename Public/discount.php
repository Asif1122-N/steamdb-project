
<?php

session_start();

// Only authenticated buyers can access this page.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

require_once __DIR__ . '/../Config/database.php';

// Retrieve games with currently active discounts.
$sql = "
    SELECT
        g.game_id,
        g.game_name,
        p.country_code,
        c.currency_code,
        p.base_price,
        p.discount_percent,
        p.discount_start,
        p.discount_end,

        ROUND(
            p.base_price * (1 - p.discount_percent / 100),
            2
        ) AS discounted_price,

        DATEDIFF(p.discount_end, CURDATE()) + 1
            AS days_remaining

    FROM game AS g

    JOIN prices AS p
        ON g.game_id = p.game_id

    JOIN country_code AS c
        ON p.country_code = c.country_code

    WHERE p.discount_percent > 0
      AND CURDATE() BETWEEN p.discount_start
                        AND p.discount_end

    ORDER BY p.discount_end ASC, g.game_name ASC
";

$stmt = $pdo->query($sql);
$discountedGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Safely display text from the database.
function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>SteamDB - Discount Games</title>
</head>

<body>

    <h1>SteamDB</h1>

    <a href="home.php">Back to Home</a>

    <hr>

    <h2>Discount Games</h2>

    <p>Currently discounted games: <?= count($discountedGames) ?></p>

    <?php if (empty($discountedGames)): ?>

        <p>No games are currently on discount.</p>

    <?php else: ?>

        <?php foreach ($discountedGames as $game): ?>

            <div style="display: flex;
                        gap: 25px;
                        align-items: flex-start;
                        margin-bottom: 30px;">

                <!-- Clickable cover image from MongoDB GridFS -->
                <a href="game.php?game_id=<?= (int) $game['game_id'] ?>">

                    <img
                        src="cover.php?game_id=<?= (int) $game['game_id'] ?>"
                        alt="<?= e($game['game_name']) ?> cover"
                        width="130"
                    >

                </a>

                <div>

                    <!-- Clickable game name -->
                    <h3>
                        <a href="game.php?game_id=<?= (int) $game['game_id'] ?>">
                            <?= e($game['game_name']) ?>
                        </a>
                    </h3>

                    <p>
                        <strong>Country:</strong>
                        <?= e($game['country_code']) ?>
                    </p>

                    <p>
                        <strong>Original Price:</strong>
                        <?= e($game['currency_code']) ?>
                        <?= number_format(
                            (float) $game['base_price'], 2
                        ) ?>
                    </p>

                    <p>
                        <strong>Discount:</strong>
                        <?= number_format(
                            (float) $game['discount_percent'], 2
                        ) ?>%
                    </p>

                    <p>
                        <strong>Discounted Price:</strong>
                        <?= e($game['currency_code']) ?>
                        <?= number_format(
                            (float) $game['discounted_price'], 2
                        ) ?>
                    </p>

                    <p>
                        <strong>Discount Period:</strong>
                        <?= e($game['discount_start']) ?>
                        to
                        <?= e($game['discount_end']) ?>
                    </p>

                    <p>
                        <strong>Days Remaining:</strong>
                        <?= (int) $game['days_remaining'] ?>
                        (including today)
                    </p>

                </div>

            </div>

            <hr>

        <?php endforeach; ?>

    <?php endif; ?>

</body>
</html>
