
<?php

session_start();

// Only authenticated administrators can access this page.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Administrator access only.');
}

header('Cache-Control: no-store, no-cache, must-revalidate');

// Database connections.
require_once __DIR__ . '/../../Config/database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$username = $_SESSION['username'] ?? 'Admin';

// Escape database values before displaying them.
function e($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Security token for deleting games.
if (empty($_SESSION['delete_game_csrf'])) {
    $_SESSION['delete_game_csrf'] = bin2hex(random_bytes(32));
}


// STEP 1: Handle a Delete Game request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    $submittedId = $_POST['game_id'] ?? '';

    $gameId = (
        is_string($submittedId) &&
        ctype_digit($submittedId)
    ) ? (int) $submittedId : 0;

    if (
        $action !== 'delete_game' ||
        !is_string($token) ||
        !hash_equals($_SESSION['delete_game_csrf'], $token) ||
        $gameId <= 0
    ) {
        $_SESSION['admin_game_error'] =
            'Invalid delete request.';

    } else {

        try {

            // Begin a MariaDB transaction.
            $pdo->beginTransaction();

            // Lock and retrieve the selected game.
            $stmt = $pdo->prepare(
                "SELECT game_id, game_name
                 FROM game
                 WHERE game_id = ?
                 FOR UPDATE"
            );

            $stmt->execute([$gameId]);
            $game = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$game) {
                throw new DomainException(
                    'Game not found.'
                );
            }

            $gameName = $game['game_name'];


            // STEP 2: Check if any buyer owns the game.
            $ownershipStmt = $pdo->prepare(
                "SELECT 1
                 FROM game_library
                 WHERE game_id = ?
                 LIMIT 1"
            );

            $ownershipStmt->execute([$gameId]);

            if ($ownershipStmt->fetchColumn()) {
                throw new DomainException(
                    'This game cannot be deleted because '
                    . 'it belongs to a buyer library.'
                );
            }


            // STEP 3: Check purchase history.
            $purchaseStmt = $pdo->prepare(
                "SELECT 1
                 FROM transaction_item AS ti
                 JOIN prices AS p
                     ON ti.price_id = p.price_id
                 WHERE p.game_id = ?
                 LIMIT 1"
            );

            $purchaseStmt->execute([$gameId]);

            if ($purchaseStmt->fetchColumn()) {
                throw new DomainException(
                    'This game cannot be deleted because '
                    . 'it exists in purchase history.'
                );
            }


            // STEP 4: Delete price records first.
            // These depend on the game through a foreign key.
            $deletePrices = $pdo->prepare(
                "DELETE FROM prices
                 WHERE game_id = ?"
            );

            $deletePrices->execute([$gameId]);


            // STEP 5: Delete the game from MariaDB.
            $deleteGame = $pdo->prepare(
                "DELETE FROM game
                 WHERE game_id = ?"
            );

            $deleteGame->execute([$gameId]);


            // STEP 6: Record administrator activity.
            $logStmt = $pdo->prepare(
                "INSERT INTO admin_activity_log
                 (
                     admin_user_id,
                     action_type,
                     entity_type,
                     entity_id
                 )
                 VALUES (?, 'DELETE_GAME', 'game', ?)"
            );

            $logStmt->execute([
                (int) $_SESSION['user_id'],
                $gameId
            ]);


            // Commit the MariaDB changes.
            $pdo->commit();


            // STEP 7: Remove MongoDB metadata and GridFS cover.
            // MongoDB and MariaDB have separate transactions.
            $mongoCleanupSuccessful = true;

            try {

                $client = new MongoDB\Client(
                    'mongodb://127.0.0.1:27017'
                );

                $mongoDb = $client->selectDatabase(
                    'steam_content_db'
                );


                // Find and delete this game's cover files.
                $bucket = $mongoDb->selectGridFSBucket([
                    'bucketName' => 'game_covers'
                ]);

                $coverFiles = $bucket->find([
                    'metadata.game_id' => $gameId
                ]);

                foreach ($coverFiles as $coverFile) {
                    $bucket->delete($coverFile->_id);
                }


                // Delete this game's MongoDB metadata.
                $metadataCollection = $mongoDb
                    ->selectCollection('game metadata');

                $metadataCollection->deleteMany([
                    'game_id' => $gameId
                ]);

            } catch (Throwable $mongoError) {

                $mongoCleanupSuccessful = false;

                error_log(
                    'MongoDB game cleanup failed: '
                    . $mongoError->getMessage()
                );
            }


            if ($mongoCleanupSuccessful) {

                $_SESSION['admin_game_message'] =
                    'Game deleted successfully: '
                    . $gameName
                    . ' (ID ' . $gameId . ').';

            } else {

                $_SESSION['admin_game_error'] =
                    'The game was deleted from MariaDB, '
                    . 'but MongoDB cleanup was incomplete. '
                    . 'Check the PHP server log.';
            }


        } catch (Throwable $exception) {

            // Roll back uncommitted MariaDB changes.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception instanceof DomainException) {

                $_SESSION['admin_game_error'] =
                    $exception->getMessage();

            } else {

                error_log(
                    'Delete game failed: '
                    . $exception->getMessage()
                );

                $_SESSION['admin_game_error'] =
                    'Could not delete the game. '
                    . 'It may be referenced by other records.';
            }
        }
    }

    // Prevent repeated deletion when refreshing the page.
    header('Location: games.php');
    exit;
}


// STEP 8: Retrieve all remaining games.
$sql = "
    SELECT
        game_id,
        game_name,
        description,
        release_date,
        created_at
    FROM game
    ORDER BY game_id ASC
";

$stmt = $pdo->query($sql);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Only one statistic: total games.
$totalGames = count($games);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - Manage Games</title>

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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-top: 35px;
        }

        .statistics {
            display: flex;
            gap: 30px;
            margin: 25px 0;
        }

        .stat-box {
            border: 2px solid black;
            padding: 15px 25px;
            min-width: 150px;
            text-align: center;
        }

        .stat-box h3 {
            margin: 0 0 10px;
            font-size: 26px;
        }

        .stat-box p {
            margin: 0;
        }

        .add-button {
            display: inline-block;
            padding: 10px 16px;
            border: 1px solid black;
            background: white;
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 25px;
        }

        th,
        td {
            border: 1px solid #aaa;
            padding: 12px;
            text-align: left;
        }

        th {
            background: #eee;
        }

        .description {
            max-width: 300px;
        }

        .delete-button {
            padding: 8px 14px;
            border: 1px solid #a00;
            background: white;
            color: #a00;
            cursor: pointer;
        }

        .delete-button:hover {
            background: #ffecec;
        }

        .success {
            color: green;
            margin: 20px 0;
        }

        .error {
            color: #b00000;
            margin: 20px 0;
        }

        @media (max-width: 650px) {

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .navigation {
                gap: 15px;
            }

        }

    </style>

</head>

<body>

    <!-- ADMIN HEADER -->
    <h1>SteamDB - Administrator</h1>


    <!-- NAVIGATION -->
    <nav class="navigation">

        <a href="dashboard.php">
            Back to Dashboard
        </a>

        <strong>
            Hi, <?= e($username) ?> (Admin)
        </strong>

    </nav>

    <hr>


    <!-- PAGE HEADER -->
    <div class="page-header">

        <div>
            <h2>Manage Games</h2>

            <p>
                Add and delete games in SteamDB.
            </p>
        </div>


        <!-- Add New Game button -->
        <a href="add_game.php" class="add-button">
            + Add New Game
        </a>

    </div>


    <!-- SUCCESS MESSAGE -->
    <?php if (!empty($_SESSION['admin_game_message'])): ?>

        <p class="success">
            <?= e($_SESSION['admin_game_message']) ?>
        </p>

        <?php unset($_SESSION['admin_game_message']); ?>

    <?php endif; ?>


    <!-- ERROR MESSAGE -->
    <?php if (!empty($_SESSION['admin_game_error'])): ?>

        <p class="error">
            <?= e($_SESSION['admin_game_error']) ?>
        </p>

        <?php unset($_SESSION['admin_game_error']); ?>

    <?php endif; ?>


    <!-- TOTAL GAMES ONLY -->
    <section class="statistics">

        <div class="stat-box">

            <h3><?= $totalGames ?></h3>

            <p>Total Games</p>

        </div>

    </section>

    <hr>


    <!-- GAME CATALOG -->
    <h2>Game Catalog</h2>

    <?php if (empty($games)): ?>

        <p>No games are currently stored.</p>

    <?php else: ?>

        <div class="table-container">

            <table>

                <thead>
                    <tr>
                        <th>Game ID</th>
                        <th>Game Name</th>
                        <th>Description</th>
                        <th>Release Date</th>
                        <th>Date Added</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($games as $game): ?>

                        <tr>

                            <td>
                                <?= (int) $game['game_id'] ?>
                            </td>

                            <td>
                                <?= e($game['game_name']) ?>
                            </td>

                            <td class="description">
                                <?= e(
                                    $game['description'] ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $game['release_date'] ?? ''
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $game['created_at'] ?? ''
                                ) ?>
                            </td>

                            <td>

                                <!-- Only one action: Delete -->
                                <form
                                    method="POST"
                                    action="games.php"
                                    onsubmit="return confirm('Permanently delete this game? This action cannot be undone.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e(
                                            $_SESSION['delete_game_csrf']
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="game_id"
                                        value="<?= (int) $game['game_id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="action"
                                        value="delete_game"
                                        class="delete-button"
                                    >
                                        Delete
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</body>
</html>
