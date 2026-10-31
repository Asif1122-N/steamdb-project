
<?php

session_start();

// Only logged-in administrators can access this page.
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

$adminId = (int) $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Admin';

function e($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// Protect add/remove operations.
if (empty($_SESSION['weekly_csrf'])) {
    $_SESSION['weekly_csrf'] = bin2hex(random_bytes(32));
}


// HANDLE ADMINISTRATOR ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $submittedId = $_POST['record_id'] ?? '';

    $recordId = (
        is_string($submittedId) &&
        ctype_digit($submittedId)
    ) ? (int) $submittedId : 0;

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['weekly_csrf'], $token) ||
        $recordId <= 0
    ) {
        $_SESSION['weekly_error'] = 'Invalid request.';

    } else {

        try {

            $pdo->beginTransaction();

            // ADD GAME TO WEEKLY BEST
            if ($action === 'add') {

                // Lock the game row and verify it exists.
                $gameStmt = $pdo->prepare(
                    "SELECT game_id, game_name
                     FROM game
                     WHERE game_id = ?
                     FOR UPDATE"
                );

                $gameStmt->execute([$recordId]);
                $game = $gameStmt->fetch(PDO::FETCH_ASSOC);

                if (!$game) {
                    throw new DomainException(
                        'Selected game does not exist.'
                    );
                }

                // Prevent duplicate current selections.
                $checkStmt = $pdo->prepare(
                    "SELECT feature_id
                     FROM weekly_featured_game
                     WHERE game_id = ?
                       AND removed_at IS NULL
                     LIMIT 1"
                );

                $checkStmt->execute([$recordId]);

                if ($checkStmt->fetchColumn()) {
                    throw new DomainException(
                        'This game is already in Weekly Best.'
                    );
                }

                // Add a new historical selection.
                $insertStmt = $pdo->prepare(
                    "INSERT INTO weekly_featured_game
                     (game_id, added_by)
                     VALUES (?, ?)"
                );

                $insertStmt->execute([
                    $recordId,
                    $adminId
                ]);

                $featureId = (int) $pdo->lastInsertId();

                // Log administrator action.
                $logStmt = $pdo->prepare(
                    "INSERT INTO admin_activity_log
                     (admin_user_id, action_type,
                      entity_type, entity_id)
                     VALUES
                     (?, 'ADD_WEEKLY_BEST',
                      'weekly_featured_game', ?)"
                );

                $logStmt->execute([
                    $adminId,
                    $featureId
                ]);

                $_SESSION['weekly_message'] =
                    $game['game_name']
                    . ' added to Weekly Best.';


            // REMOVE GAME FROM WEEKLY BEST
            } elseif ($action === 'remove') {

                // record_id is the feature_id here.
                $featureStmt = $pdo->prepare(
                    "SELECT
                        w.feature_id,
                        g.game_name
                     FROM weekly_featured_game AS w
                     JOIN game AS g
                         ON w.game_id = g.game_id
                     WHERE w.feature_id = ?
                       AND w.removed_at IS NULL
                     FOR UPDATE"
                );

                $featureStmt->execute([$recordId]);

                $feature = $featureStmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$feature) {
                    throw new DomainException(
                        'This Weekly Best selection is not active.'
                    );
                }

                // Preserve the record for historical reports.
                $removeStmt = $pdo->prepare(
                    "UPDATE weekly_featured_game
                     SET removed_at = NOW()
                     WHERE feature_id = ?
                       AND removed_at IS NULL"
                );

                $removeStmt->execute([$recordId]);

                if ($removeStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Could not update the selection.'
                    );
                }

                // Log the removal.
                $logStmt = $pdo->prepare(
                    "INSERT INTO admin_activity_log
                     (admin_user_id, action_type,
                      entity_type, entity_id)
                     VALUES
                     (?, 'REMOVE_WEEKLY_BEST',
                      'weekly_featured_game', ?)"
                );

                $logStmt->execute([
                    $adminId,
                    $recordId
                ]);

                $_SESSION['weekly_message'] =
                    $feature['game_name']
                    . ' removed from Weekly Best.';

            } else {

                throw new DomainException(
                    'Unknown administrator action.'
                );
            }

            // Save the selection and audit log together.
            $pdo->commit();

        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            unset($_SESSION['weekly_message']);

            if ($exception instanceof DomainException) {

                $_SESSION['weekly_error'] =
                    $exception->getMessage();

            } else {

                error_log(
                    'Weekly Best error: '
                    . $exception->getMessage()
                );

                $_SESSION['weekly_error'] =
                    'Could not save the Weekly Best change.';
            }
        }
    }

    // Avoid repeating the operation on refresh.
    header('Location: weekly_best.php');
    exit;
}


// LOAD CURRENT WEEKLY BEST GAMES
$currentStmt = $pdo->query(
    "SELECT
        w.feature_id,
        w.game_id,
        g.game_name,
        w.added_at
     FROM weekly_featured_game AS w
     JOIN game AS g
         ON w.game_id = g.game_id
     WHERE w.removed_at IS NULL
     ORDER BY w.added_at DESC, w.feature_id DESC"
);

$currentGames = $currentStmt->fetchAll(PDO::FETCH_ASSOC);


// LOAD PREVIOUSLY FEATURED GAMES
$historyStmt = $pdo->query(
    "SELECT
        w.feature_id,
        w.game_id,
        g.game_name,
        w.added_at,
        w.removed_at
     FROM weekly_featured_game AS w
     JOIN game AS g
         ON w.game_id = g.game_id
     WHERE w.removed_at IS NOT NULL
     ORDER BY w.removed_at DESC, w.feature_id DESC"
);

$previousGames = $historyStmt->fetchAll(PDO::FETCH_ASSOC);


// LOAD GAMES AVAILABLE TO ADD
$availableStmt = $pdo->query(
    "SELECT g.game_id, g.game_name
     FROM game AS g
     WHERE NOT EXISTS (
         SELECT 1
         FROM weekly_featured_game AS w
         WHERE w.game_id = g.game_id
           AND w.removed_at IS NULL
     )
     ORDER BY g.game_name"
);

$availableGames = $availableStmt->fetchAll(
    PDO::FETCH_ASSOC
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - Weekly Best Management</title>

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

        .section {
            margin-top: 35px;
        }

        .add-form {
            border: 1px solid #aaa;
            padding: 20px;
            max-width: 650px;
        }

        select {
            min-width: 260px;
            padding: 9px;
        }

        button {
            padding: 9px 14px;
            cursor: pointer;
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #aaa;
            text-align: left;
        }

        th {
            background: #eee;
        }

        .remove-button {
            color: #b00000;
            border: 1px solid #b00000;
            background: white;
        }

        .success {
            color: green;
        }

        .error {
            color: #b00000;
        }
    </style>

</head>

<body>

    <h1>SteamDB - Administrator</h1>

    <nav class="navigation">

        <a href="dashboard.php">
            Back to Dashboard
        </a>

        <strong>
            Hi, <?= e($username) ?> (Admin)
        </strong>

    </nav>

    <hr>

    <h2>Manage Weekly Best</h2>

    <p>
        Add or remove games from the Weekly Best list.
        Original game records remain unchanged.
    </p>

    <?php if (!empty($_SESSION['weekly_message'])): ?>

        <p class="success">
            <?= e($_SESSION['weekly_message']) ?>
        </p>

        <?php unset($_SESSION['weekly_message']); ?>

    <?php endif; ?>

    <?php if (!empty($_SESSION['weekly_error'])): ?>

        <p class="error">
            <?= e($_SESSION['weekly_error']) ?>
        </p>

        <?php unset($_SESSION['weekly_error']); ?>

    <?php endif; ?>


    <!-- ADD GAME TO WEEKLY BEST -->
    <section class="section">

        <h2>Add Game to Weekly Best</h2>

        <form method="POST"
              action="weekly_best.php"
              class="add-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['weekly_csrf']) ?>"
            >

            <input type="hidden"
                   name="action"
                   value="add">

            <label for="record_id">
                Select an existing game:
            </label>

            <br><br>

            <select id="record_id"
                    name="record_id"
                    required>

                <option value="">Select Game</option>

                <?php foreach ($availableGames as $game): ?>

                    <option value="<?= (int) $game['game_id'] ?>">
                        <?= e($game['game_name']) ?>
                        (ID <?= (int) $game['game_id'] ?>)
                    </option>

                <?php endforeach; ?>

            </select>

            <br><br>

            <button
                type="submit"
                <?= empty($availableGames) ? 'disabled' : '' ?>
            >
                + Add to Weekly Best
            </button>

        </form>

    </section>


    <!-- CURRENT WEEKLY BEST -->
    <section class="section">

        <h2>Current Weekly Best</h2>

        <p>
            <strong>Total Featured Games:</strong>
            <?= count($currentGames) ?>
        </p>

        <?php if (empty($currentGames)): ?>

            <p>No games are currently featured.</p>

        <?php else: ?>

            <div class="table-container">

                <table>

                    <thead>
                        <tr>
                            <th>Game ID</th>
                            <th>Game Name</th>
                            <th>Added On</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($currentGames as $game): ?>

                        <tr>

                            <td>
                                <?= (int) $game['game_id'] ?>
                            </td>

                            <td>
                                <?= e($game['game_name']) ?>
                            </td>

                            <td>
                                <?= e($game['added_at']) ?>
                            </td>

                            <td>

                                <form
                                    method="POST"
                                    action="weekly_best.php"
                                    onsubmit="return confirm('Remove this game from Weekly Best? The game itself will not be deleted.');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['weekly_csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="remove"
                                    >

                                    <input
                                        type="hidden"
                                        name="record_id"
                                        value="<?= (int) $game['feature_id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="remove-button"
                                    >
                                        Remove from List
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>


    <!-- HISTORICAL WEEKLY BEST -->
    <section class="section">

        <hr>

        <h2>Previous Weekly Best Selections</h2>

        <?php if (empty($previousGames)): ?>

            <p>No previous selections yet.</p>

        <?php else: ?>

            <div class="table-container">

                <table>

                    <thead>
                        <tr>
                            <th>Game ID</th>
                            <th>Game Name</th>
                            <th>Added On</th>
                            <th>Removed On</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($previousGames as $game): ?>

                        <tr>

                            <td>
                                <?= (int) $game['game_id'] ?>
                            </td>

                            <td>
                                <?= e($game['game_name']) ?>
                            </td>

                            <td>
                                <?= e($game['added_at']) ?>
                            </td>

                            <td>
                                <?= e($game['removed_at']) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</body>
</html>
