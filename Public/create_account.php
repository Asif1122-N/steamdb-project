<?php

session_start();
require_once __DIR__ . '/../Config/database.php';

$error = '';
$success = false;

// Retrieve the available countries from MariaDB.
$stmt = $pdo->query(
    "SELECT country_code FROM country_code ORDER BY country_code"
);
$countries = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Generate a security token for the registration form.
if (!isset($_SESSION['register_token'])) {
    $_SESSION['register_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $country = $_POST['country_code'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate the registration form before saving any data.
    if (
        !is_string($_POST['register_token'] ?? null) ||
        !hash_equals(
            $_SESSION['register_token'],
            $_POST['register_token']
        )
    ) {
        $error = 'Invalid form submission. Please try again.';

    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $error = 'Username must be 3-50 characters using letters, numbers or underscores.';

    } elseif (strtolower($username) === 'admin') {
        $error = 'This username is reserved.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = 'Please enter a valid email address.';

    } elseif (!is_string($country) || !in_array($country, $countries, true)) {
        $error = 'Please select a valid country.';

    } elseif (strlen($password) < 12) {
        $error = 'Password must contain at least 12 characters.';

    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';

    } else {

        try {
            // Hash the password instead of storing plain text.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // All publicly registered accounts are buyers.
            $sql = "INSERT INTO `user`
                    (username, email, country_code, password_hash, role)
                    VALUES
                    (:username, :email, :country, :password_hash, 'buyer')";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'country' => $country,
                'password_hash' => $passwordHash
            ]);

            // The account was successfully saved in MariaDB.
            $success = true;

            // Renew the form token after successful registration.
            $_SESSION['register_token'] = bin2hex(random_bytes(32));

        } catch (PDOException $e) {

            if ($e->getCode() === '23000') {
                $error = 'Username or email is already registered.';
            } else {
                error_log("Registration error: " . $e->getMessage());
                $error = 'Account creation failed. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SteamDB - Create Account</title>
</head>

<body>

<div style="min-height: 95vh;
            display: flex;
            justify-content: center;
            align-items: center;">

    <div style="width: 300px;">

        <h1 style="text-align: center;">SteamDB</h1>
        <h2>Create Account</h2>

        <?php if ($success): ?>

            <script>
                // Show the popup only after successful database insertion.
                alert("Account created successfully! Please log in.");

                // Redirect to the login page after the user clicks OK.
                window.location.replace("login.php");
            </script>

            <noscript>
                <p>Account created successfully!</p>
                <a href="login.php">Continue to Login</a>
            </noscript>

        <?php else: ?>

            <?php if ($error): ?>
                <p style="color: red;">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <form action="create_account.php" method="POST">

                <!-- Security token -->
                <input
                    type="hidden"
                    name="register_token"
                    value="<?= htmlspecialchars($_SESSION['register_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <!-- Username -->
                <label for="username">Name:</label>
                <br>
                <input
                    type="text"
                    id="username"
                    name="username"
                    maxlength="50"
                    autocomplete="username"
                    required
                >

                <br><br>

                <!-- Email -->
                <label for="email">Email:</label>
                <br>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="100"
                    autocomplete="email"
                    required
                >

                <br><br>

                <!-- Country -->
                <label for="country">Country:</label>
                <br>
                <select id="country" name="country_code" required>

                    <option value="">Select country</option>

                    <?php foreach ($countries as $code): ?>

                        <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <br><br>

                <!-- Password -->
                <label for="password">Password:</label>
                <br>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="12"
                    autocomplete="new-password"
                    required
                >

                <br><br>

                <!-- Confirm password -->
                <label for="confirm_password">Confirm Password:</label>
                <br>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="12"
                    autocomplete="new-password"
                    required
                >

                <br><br>

                <!-- Registration button -->
                <button type="submit">Create Account</button>

            </form>

            <br>

            <a href="login.php">Back to Login</a>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
