<?php

session_start();

// Only logged-in buyers can view Weekly Best.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

require_once __DIR__ . '/../Config/database.php';

$username = $_SESSION['username'] ?? 'Buyer';

// Retrieve only games currently selected by admin.
$stmt = $pdo->query(
    "SELECT
        g.game_id,
        g.game_name,
        g.description,
        g.release_date,
        w.added_at
     FROM weekly_featured_game AS w
     JOIN game AS g
         ON w.game_id = g.game_id
     WHERE w.removed_at IS NULL
     ORDER BY w.added_at DESC, w.feature_id DESC"
);

$weeklyGames = $stmt->fetchAll(PDO::FETCH_ASSOC);

function e($value): string {
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

    <title>SteamDB - Weekly Best</title>

    <style>
        body {
            margin: 20px;
            font-family: Arial, sans-serif;
        }

        .navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
        }

        .game {
            display: flex;
            align-items: flex-start;
            gap: 25px;
            margin: 30px 0;
        }

        .game img {
            width: 150px;
            height: auto;
            border-radius: 8px;
        }

        .game-details {
            flex: 1;
        }

        button {
            cursor: pointer;
        }

        @media (max-width: 600px) {
            .game {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

    <h1>SteamDB</h1>

    <!-- Navigation -->
    <nav class="navigation">

        <button
            type="button"
            onclick="window.location.href='home.php'">
            Back to Home
        </button>

        <button
            type="button"
            onclick="window.location.href='profile.php'">
            Hi, <?= e($username) ?>
        </button>

    </nav>

    <hr>

    <h2>Weekly Best</h2>

    <p>Games selected by the SteamDB administrator.</p>

    <p>
        <strong>Total Featured Games:</strong>
        <?= count($weeklyGames) ?>
    </p>

    <?php if (empty($weeklyGames)): ?>

        <p>No games are currently featured.</p>

    <?php else: ?>

        <?php foreach ($weeklyGames as $game): ?>

            <?php
                $gameId = (int) $game['game_id'];
                $gameUrl = 'game.php?game_id=' . $gameId;
            ?>

            <div class="game">

                <!-- Cover retrieved from MongoDB GridFS -->
                <a href="<?= e($gameUrl) ?>">

                    <img
                        src="cover.php?game_id=<?= $gameId ?>"
                        alt="<?= e($game['game_name']) ?> cover"
                    >

                </a>

                <div class="game-details">

                    <h3>
                        <a href="<?= e($gameUrl) ?>">
                            <?= e($game['game_name']) ?>
                        </a>
                    </h3>

                    <p>
                        <strong>Description:</strong>
                        <?= e($game['description'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Release Date:</strong>
                        <?= e($game['release_date'] ?? '') ?>
                    </p>

                    <p>
                        <strong>Featured Since:</strong>
                        <?= e($game['added_at']) ?>
                    </p>

                    <a href="<?= e($gameUrl) ?>">
                        View Game Details
                    </a>

                </div>

            </div>

            <hr>

        <?php endforeach; ?>

    <?php endif; ?>

</body>
</html>

