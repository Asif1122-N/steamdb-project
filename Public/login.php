
<?php

session_start();
require_once __DIR__ . '/../Config/database.php';

$error = '';

// Redirect buyers who are already logged in.
if (
    isset($_SESSION['user_id']) &&
    ($_SESSION['role'] ?? '') === 'buyer'
) {
    header('Location: home.php');
    exit;
}

// Process the login form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!is_string($username) || !is_string($password)) {
        $error = 'Invalid username or password.';
    } else {

        $username = trim($username);

        // Retrieve the account from MariaDB.
        $stmt = $pdo->prepare(
            "SELECT user_id, username, password_hash, role
             FROM `user`
             WHERE username = :username
             LIMIT 1"
        );

        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify the entered password using the stored hash.
        if (
            $user &&
            !empty($user['password_hash']) &&
            password_verify($password, $user['password_hash'])
        ) {

            // Generate a new session ID after successful login.
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Redirect buyers to their homepage.
            if ($user['role'] === 'buyer') {
                header('Location: home.php');
                exit;
            }

            // Admin stays on a temporary success screen.
            // We will redirect to the admin dashboard later.

        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SteamDB - Login</title>
</head>

<body>

<!-- Center the login form. -->
<div style="min-height: 95vh;
            display: flex;
            justify-content: center;
            align-items: center;">

    <div style="width: 280px;">

        <h1 style="text-align: center;">SteamDB</h1>

        <?php if (isset($_SESSION['user_id'])): ?>

            <!-- Temporary administrator success screen -->
            <h2>Login successful!</h2>

            <p>
                Welcome,
                <?= htmlspecialchars(
                    $_SESSION['username'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>

            <p>
                Role:
                <?= htmlspecialchars(
                    $_SESSION['role'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>

        <?php else: ?>

            <?php if (!empty($error)): ?>

                <p style="color: red;">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </p>

            <?php endif; ?>

            <!-- Shared login form for buyers and admin -->
            <form action="login.php" method="POST">

                <label for="username">Name:</label>
                <br>

                <input
                    type="text"
                    id="username"
                    name="username"
                    autocomplete="username"
                    style="width: 100%;"
                    required
                >

                <br><br>

                <label for="password">Password:</label>
                <br>

                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    style="width: 100%;"
                    required
                >

                <br><br>

                <div style="text-align: center;">
                    <button type="submit">Log in</button>
                </div>

            </form>

            <br><br>

            <!-- Buyer registration -->
            <div style="text-align: right;">

                <form action="create_account.php" method="GET">
                    <button type="submit">
                        Create Account
                    </button>
                </form>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
