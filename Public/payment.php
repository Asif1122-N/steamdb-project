
<?php

session_start();

// Only logged-in buyers can make purchases.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

require_once __DIR__ . '/../Config/database.php';

$userId = (int) $_SESSION['user_id'];
$username = $_SESSION['username'];

$gameId = filter_input(INPUT_GET, 'game_id', FILTER_VALIDATE_INT);

if (!$gameId || $gameId < 1) {
    http_response_code(400);
    exit('Invalid game ID.');
}

// Retrieve the price directly from MariaDB.
$priceStmt = $pdo->prepare(
    "SELECT
        g.game_name,
        p.price_id,
        c.currency_code,
        CASE
            WHEN p.discount_percent > 0
                 AND CURDATE() BETWEEN p.discount_start AND p.discount_end
            THEN ROUND(
                p.base_price * (1 - p.discount_percent / 100), 2
            )
            ELSE p.base_price
        END AS current_price
     FROM game AS g
     JOIN prices AS p ON g.game_id = p.game_id
     JOIN country_code AS c
         ON p.country_code = c.country_code
     WHERE g.game_id = :game_id
     ORDER BY p.price_id
     LIMIT 1"
);

$priceStmt->execute(['game_id' => $gameId]);
$game = $priceStmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    http_response_code(404);
    exit('Game or price not found.');
}

$error = '';

// Protect the Pay form from unwanted submissions.
if (!isset($_SESSION['pay_token'])) {
    $_SESSION['pay_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $method = $_POST['payment_method'] ?? '';
    $provider = $_POST['provider'] ?? '';
    $token = $_POST['pay_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['pay_token'], $token)) {

        $error = 'Invalid payment request.';

    } elseif (!in_array($method, ['Credit Card', 'Debit Card'], true)) {

        $error = 'Select a valid payment method.';

    } elseif (!in_array($provider, ['Visa', 'Mastercard'], true)) {

        $error = 'Select a valid provider.';

    } else {

        try {
            // All purchase-related changes succeed or fail together.
            $pdo->beginTransaction();

            // Lock this user's record while processing the purchase.
            $lockUser = $pdo->prepare(
                "SELECT user_id FROM `user`
                 WHERE user_id = :user_id
                 FOR UPDATE"
            );
            $lockUser->execute(['user_id' => $userId]);

            if (!$lockUser->fetchColumn()) {
                throw new RuntimeException('Buyer not found.');
            }

            // Recalculate the price at the time of purchase.
            $priceStmt->execute(['game_id' => $gameId]);
            $purchaseGame = $priceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$purchaseGame) {
                throw new RuntimeException('Game price not found.');
            }

            // Find the buyer's existing library.
            $libraryStmt = $pdo->prepare(
                "SELECT library_id FROM library
                 WHERE user_id = :user_id"
            );

            $libraryStmt->execute(['user_id' => $userId]);
            $libraryId = $libraryStmt->fetchColumn();

            // Create a library only if the buyer doesn't have one.
            if ($libraryId === false) {

                $createLibrary = $pdo->prepare(
                    "INSERT INTO library (user_id, created_date)
                     VALUES (:user_id, CURDATE())"
                );

                $createLibrary->execute(['user_id' => $userId]);
                $libraryId = (int) $pdo->lastInsertId();
            }

            // Prevent purchasing a game already in the library.
            $ownershipStmt = $pdo->prepare(
                "SELECT 1 FROM game_library
                 WHERE library_id = :library_id
                   AND game_id = :game_id
                 LIMIT 1"
            );

            $ownershipStmt->execute([
                'library_id' => $libraryId,
                'game_id' => $gameId
            ]);

            if ($ownershipStmt->fetchColumn()) {

                $pdo->rollBack();
                $error = 'You already own this game.';

            } else {

                // 1. Save payment method and provider only.
                $paymentStmt = $pdo->prepare(
                    "INSERT INTO payment_details
                     (user_id, payment_method, provider)
                     VALUES (:user_id, :method, :provider)"
                );

                $paymentStmt->execute([
                    'user_id' => $userId,
                    'method' => $method,
                    'provider' => $provider
                ]);

                $paymentId = (int) $pdo->lastInsertId();

                // 2. Record the simulated purchase.
                $transactionStmt = $pdo->prepare(
                    "INSERT INTO `transaction`
                     (payment_id, purchase_date, total_amount,
                      currency_code, status)
                     VALUES
                     (:payment_id, CURDATE(), :amount,
                      :currency, 'Simulated')"
                );

                $transactionStmt->execute([
                    'payment_id' => $paymentId,
                    'amount' => $purchaseGame['current_price'],
                    'currency' => $purchaseGame['currency_code']
                ]);

                $transactionId = (int) $pdo->lastInsertId();

                // 3. Link the purchased game through its price ID.
                $itemStmt = $pdo->prepare(
                    "INSERT INTO transaction_item
                     (transaction_id, item_no, price_id)
                     VALUES (:transaction_id, 1, :price_id)"
                );

                $itemStmt->execute([
                    'transaction_id' => $transactionId,
                    'price_id' => $purchaseGame['price_id']
                ]);

                // 4. Automatically add the game to the buyer's library.
                $gameLibraryStmt = $pdo->prepare(
                    "INSERT INTO game_library
                     (library_id, game_id, acquisition_date,
                      play_status, playtime_minutes)
                     VALUES
                     (:library_id, :game_id, CURDATE(),
                      'Not Started', 0)"
                );

                $gameLibraryStmt->execute([
                    'library_id' => $libraryId,
                    'game_id' => $gameId
                ]);

                // Save all four related operations.
                $pdo->commit();

                // Prevent resubmission using the previous token.
                $_SESSION['pay_token'] = bin2hex(random_bytes(32));
                $_SESSION['purchase_message'] =
                    'Game successfully added to your library. No real payment was charged.';

                // Open the buyer's personal profile.
                header('Location: profile.php');
                exit;
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Purchase error: ' . $e->getMessage());
            $error = 'Purchase could not be recorded. Please try again.';
        }
    }
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SteamDB - Payment</title>
</head>

<body>

<div style="max-width: 350px; margin: 60px auto;">

    <h1 style="text-align: center;">SteamDB</h1>

    <h2>Payment Details</h2>

    <p><strong>Game:</strong> <?= e($game['game_name']) ?></p>

    <p>
        <strong>Price:</strong>
        <?= e($game['currency_code']) ?>
        <?= number_format((float) $game['current_price'], 2) ?>
    </p>

    <p><strong>Username:</strong> <?= e($username) ?></p>
    <p><strong>User ID:</strong> <?= $userId ?></p>

    <hr>

    <?php if ($error): ?>
        <p style="color: red;"><?= e($error) ?></p>
    <?php endif; ?>

    <form action="payment.php?game_id=<?= $gameId ?>" method="POST">

        <input
            type="hidden"
            name="pay_token"
            value="<?= e($_SESSION['pay_token']) ?>"
        >

        <label for="payment_method">Payment Method:</label>
        <br>

        <select id="payment_method" name="payment_method" required>
            <option value="">Select Method</option>
            <option value="Credit Card">Credit Card</option>
            <option value="Debit Card">Debit Card</option>
        </select>

        <br><br>

        <label for="provider">Provider:</label>
        <br>

        <select id="provider" name="provider" required>
            <option value="">Select Provider</option>
            <option value="Visa">Visa</option>
            <option value="Mastercard">MasterCard</option>
        </select>

        <br><br><br>

        <div style="text-align: center;">
            <button type="submit"
                    style="padding: 12px 50px; font-size: 18px;">
                Pay
            </button>
        </div>

    </form>

    <br><br>

    <a href="game.php?game_id=<?= $gameId ?>">
        Back to Game
    </a>

</div>

</body>
</html>
