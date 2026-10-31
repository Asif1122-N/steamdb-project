
<?php

session_start();

// Connect to MariaDB.
require_once __DIR__ . '/../Config/database.php';

$error = '';

// STEP 1: Redirect users who are already logged in.
if (isset($_SESSION['user_id'])) {

    // Existing buyer session.
    if (($_SESSION['role'] ?? '') === 'buyer') {
        header('Location: home.php');
        exit;
    }

    // Existing administrator session.
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    }
}


// STEP 2: Process the login form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validate submitted data.
    if (
        !is_string($username) ||
        !is_string($password)
    ) {

        $error = 'Invalid username or password.';

    } else {

        $username = trim($username);

        // STEP 3: Retrieve the account from MariaDB.
        $stmt = $pdo->prepare(
            "SELECT
                user_id,
                username,
                password_hash,
                role
             FROM `user`
             WHERE username = :username
             LIMIT 1"
        );

        $stmt->execute([
            'username' => $username
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);


        // STEP 4: Verify password and account role.
        if (
            $user &&
            !empty($user['password_hash']) &&
            password_verify(
                $password,
                $user['password_hash']
            ) &&
            in_array(
                $user['role'],
                ['buyer', 'admin'],
                true
            )
        ) {

            // Generate a new session ID for security.
            session_regenerate_id(true);

            // Store logged-in user's information.
            $_SESSION['user_id'] =
                (int) $user['user_id'];

            $_SESSION['username'] =
                $user['username'];

            $_SESSION['role'] =
                $user['role'];


            // STEP 5: Redirect according to role.

            // Administrator login.
            if ($user['role'] === 'admin') {

                header(
                    'Location: admin/dashboard.php'
                );

                exit;
            }

            // Buyer login.
            if ($user['role'] === 'buyer') {

                header('Location: home.php');

                exit;
            }

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

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - Login</title>

    <style>

        body {
            margin: 20px;
        }

        /* Center the login form */
        .login-container {
            min-height: 95vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-box {
            width: 280px;
        }

        .login-box h1 {
            text-align: center;
        }

        .login-box input {
            width: 100%;
            box-sizing: border-box;
            padding: 6px;
        }

        .login-button {
            text-align: center;
        }

        .create-account {
            text-align: right;
        }

        .error-message {
            color: red;
        }

        button {
            cursor: pointer;
        }

    </style>

</head>

<body>

    <!-- Shared login page for buyers and administrators -->
    <div class="login-container">

        <div class="login-box">

            <h1>SteamDB</h1>


            <!-- Display login error if authentication fails -->
            <?php if (!empty($error)): ?>

                <p class="error-message">

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </p>

            <?php endif; ?>


            <!-- Login form -->
            <form action="login.php" method="POST">

                <!-- Username -->
                <label for="username">
                    Name:
                </label>

                <br>

                <input
                    type="text"
                    id="username"
                    name="username"
                    autocomplete="username"
                    required
                >

                <br><br>


                <!-- Password -->
                <label for="password">
                    Password:
                </label>

                <br>

                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

                <br><br>


                <!-- Login button -->
                <div class="login-button">

                    <button type="submit">
                        Log in
                    </button>

                </div>

            </form>


            <br><br>


            <!-- Buyer account registration -->
            <div class="create-account">

                <form
                    action="create_account.php"
                    method="GET"
                >

                    <button type="submit">
                        Create Account
                    </button>

                </form>

            </div>

        </div>

    </div>

</body>
</html>
