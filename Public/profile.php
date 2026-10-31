
<?php

session_start();
// Handle logout inside profile.php.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['logout'])
) {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !isset($_SESSION['logout_csrf']) ||
        !hash_equals($_SESSION['logout_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid logout request.');
    }

    // Clear all session data.
    $_SESSION = [];

    // Delete the session cookie.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]
        );
    }

    session_destroy();

    header('Location: login.php');
    exit;
}

// Keep your existing buyer authentication checks here.

// Generate a logout token for the profile form.
if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}


// Only authenticated buyers can access their profile.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

require_once __DIR__ . '/../Config/database.php';

// Get the user ID from the login session.
$userId = (int) $_SESSION['user_id'];

// Retrieve the current user's information from MariaDB.
$stmt = $pdo->prepare(
    "SELECT
        u.user_id,
        u.username,
        u.email,
        u.country_code,
        c.currency_code,
        u.role
     FROM `user` AS u
     LEFT JOIN country_code AS c
         ON u.country_code = c.country_code
     WHERE u.user_id = :user_id"
);

$stmt->execute(['user_id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    exit('User account not found.');
}

// Find the personal library belonging to this user.
$libraryStmt = $pdo->prepare(
    "SELECT library_id, created_date
     FROM library
     WHERE user_id = :user_id"
);

$libraryStmt->execute(['user_id' => $userId]);
$library = $libraryStmt->fetch(PDO::FETCH_ASSOC);

$games = [];

// Retrieve the owned games only if a library exists.
if ($library) {

    $gameStmt = $pdo->prepare(
        "SELECT
            g.game_id,
            g.game_name,
            g.description,
            gl.acquisition_date,
            gl.play_status,
            gl.playtime_minutes

         FROM game_library AS gl

         JOIN game AS g
             ON gl.game_id = g.game_id

         WHERE gl.library_id = :library_id

         ORDER BY gl.acquisition_date DESC, g.game_name"
    );

    $gameStmt->execute([
        'library_id' => $library['library_id']
    ]);

    $games = $gameStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Escape text before displaying it in HTML.
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SteamDB - My Profile</title>
</head>

<body>

<div style="max-width: 900px; margin: 30px auto;">

    
<h1>SteamDB</h1>

<!-- Navigation: Back to Home on left, Logout on right -->
<div style="
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
">

    <!-- Back to Home -->
    <a href="home.php">Back to Home</a>

    <!-- Logout form -->
    <form action="profile.php" method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['logout_csrf']) ?>"
        >

        <button
            type="submit"
            name="logout"
            value="1">
            Logout
        </button>

    </form>

</div>

<hr>


    <h2>My Profile</h2>
    
<?php if (!empty($_SESSION['purchase_message'])): ?>

    <p>
        <?= e($_SESSION['purchase_message']) ?>
    </p>

    <?php unset($_SESSION['purchase_message']); ?>

<?php endif; ?>


    <p>
        <strong>Username:</strong>
        <?= e($user['username']) ?>
    </p>

    <p>
        <strong>User ID:</strong>
        <?= (int) $user['user_id'] ?>
    </p>

    <p>
        <strong>Email:</strong>
        <?= e($user['email']) ?>
    </p>

    <p>
        <strong>Country:</strong>
        <?= e($user['country_code']) ?>
    </p>

    <p>
        <strong>Currency:</strong>
        <?= e($user['currency_code'] ?? 'Not available') ?>
    </p>

    <p>
        <strong>Account Type:</strong>
        <?= e($user['role']) ?>
    </p>

    <hr>

    <h2>My Game Library</h2>

    <?php if ($library): ?>

        <p>
            <strong>Library ID:</strong>
            <?= (int) $library['library_id'] ?>
        </p>

        <p>
            <strong>Library Created:</strong>
            <?= e($library['created_date']) ?>
        </p>

    <?php endif; ?>

    <p>
        <strong>Total Games Owned:</strong>
        <?= count($games) ?>
    </p>

    <?php if (empty($games)): ?>

        <p>Your library is currently empty.</p>

        <a href="index.php">Browse Games</a>

    <?php else: ?>

        <?php foreach ($games as $game): ?>

            <hr>

            <h3>
                <a href="game.php?game_id=<?= (int) $game['game_id'] ?>">
                    <?= e($game['game_name']) ?>
                </a>
            </h3>

            <!-- Game cover from MongoDB GridFS -->
            <a href="game.php?game_id=<?= (int) $game['game_id'] ?>">
                <img
                    src="cover.php?game_id=<?= (int) $game['game_id'] ?>"
                    alt="<?= e($game['game_name']) ?> cover"
                    width="120"
                >
            </a>

            <p>
                <strong>Description:</strong>
                <?= e($game['description'] ?? '') ?>
            </p>

            <p>
                <strong>Acquired On:</strong>
                <?= e($game['acquisition_date']) ?>
            </p>

            <p>
                <strong>Play Status:</strong>
                <?= e($game['play_status'] ?? 'Not Played') ?>
            </p>

            <p>
                <strong>Playtime:</strong>
                <?= (int) ($game['playtime_minutes'] ?? 0) ?> minutes
            </p>

        <?php endforeach; ?>

    <?php endif; ?>

    <hr>

    <p>
        <a href="home.php">Back to Home</a>
    </p>

</div>

</body>
</html>
