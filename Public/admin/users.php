<?php

session_start();

// This entire page is for administrators only.
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
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['delete_user_csrf'])) {
    $_SESSION['delete_user_csrf'] = bin2hex(random_bytes(32));
}

// Handle a deletion request. A buyer's related library and purchase records
// must be deleted in foreign-key order before deleting the account.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $action = $_POST['action'] ?? null;
    $rawId = $_POST['user_id'] ?? null;

    $buyerId = is_string($rawId) && ctype_digit($rawId)
        ? (int) $rawId
        : 0;

    if (
        $action !== 'delete_user' ||
        !is_string($token) ||
        !hash_equals($_SESSION['delete_user_csrf'], $token) ||
        $buyerId <= 0
    ) {
        http_response_code(403);
        exit('Invalid delete request.');
    }

    try {
        $pdo->beginTransaction();

        // Only a buyer account can be selected on this management page.
        $findBuyer = $pdo->prepare(
            "SELECT user_id, username
             FROM `user`
             WHERE user_id = ? AND role = 'buyer'
             FOR UPDATE"
        );
        $findBuyer->execute([$buyerId]);
        $buyer = $findBuyer->fetch(PDO::FETCH_ASSOC);

        if (!$buyer) {
            throw new DomainException('Buyer account not found.');
        }

        // 1. Delete transaction line items belonging to this buyer.
        $stmt = $pdo->prepare(
            "DELETE FROM transaction_item
             WHERE transaction_id IN (
                 SELECT t.transaction_id
                 FROM `transaction` AS t
                 JOIN payment_details AS pd
                     ON pd.payment_id = t.payment_id
                 WHERE pd.user_id = ?
             )"
        );
        $stmt->execute([$buyerId]);

        // 2. Delete the buyer's transactions.
        $stmt = $pdo->prepare(
            "DELETE FROM `transaction`
             WHERE payment_id IN (
                 SELECT payment_id FROM payment_details WHERE user_id = ?
             )"
        );
        $stmt->execute([$buyerId]);

        // 3. Delete payment records.
        $stmt = $pdo->prepare(
            'DELETE FROM payment_details WHERE user_id = ?'
        );
        $stmt->execute([$buyerId]);

        // 4. Delete game ownership links (not the games themselves).
        $stmt = $pdo->prepare(
            'DELETE FROM game_library
             WHERE library_id IN (
                 SELECT library_id FROM library WHERE user_id = ?
             )'
        );
        $stmt->execute([$buyerId]);

        // 5. Delete the buyer's library.
        $stmt = $pdo->prepare('DELETE FROM library WHERE user_id = ?');
        $stmt->execute([$buyerId]);

        // 6. Delete the buyer's account.
        $stmt = $pdo->prepare(
            "DELETE FROM `user` WHERE user_id = ? AND role = 'buyer'"
        );
        $stmt->execute([$buyerId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Buyer deletion could not be completed.');
        }

        // 7. Record the successful administrator action.
        $stmt = $pdo->prepare(
            "INSERT INTO admin_activity_log
                 (admin_user_id, action_type, entity_type, entity_id)
             VALUES (?, 'DELETE_USER', 'user', ?)"
        );
        $stmt->execute([(int) $_SESSION['user_id'], $buyerId]);

        $pdo->commit();
        $_SESSION['user_management_message'] =
            'Buyer deleted: ' . $buyer['username'] . ' (ID ' . $buyerId . ').';

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof DomainException) {
            $_SESSION['user_management_error'] = $exception->getMessage();
        } else {
            error_log('Delete user failed: ' . $exception->getMessage());
            $_SESSION['user_management_error'] =
                'Could not delete the buyer. Check the PHP server log for details.';
        }
    }

    // Prevent accidental repeated POST when refreshing the page.
    header('Location: users.php');
    exit;
}

// Display all buyers with their library and purchase counts.
$stmt = $pdo->query(
    "SELECT
        u.user_id,
        u.username,
        u.email,
        u.country_code,
        (SELECT COUNT(*)
           FROM library AS l
           JOIN game_library AS gl ON gl.library_id = l.library_id
          WHERE l.user_id = u.user_id) AS owned_games,
        (SELECT COUNT(*)
           FROM payment_details AS pd
           JOIN `transaction` AS t ON t.payment_id = pd.payment_id
          WHERE pd.user_id = u.user_id) AS purchase_count
     FROM `user` AS u
     WHERE u.role = 'buyer'
     ORDER BY u.user_id ASC"
);
$buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SteamDB - Manage Users</title>
    <style>
        body { margin: 20px; font-family: Arial, sans-serif; }
        .navigation { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin: 20px 0; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 22px; }
        th, td { border: 1px solid #aaa; padding: 12px; text-align: left; }
        th { background: #eee; }
        .delete-button { color: #b00000; background: white; border: 1px solid #b00000; padding: 8px 14px; cursor: pointer; }
        .delete-button:hover { background: #ffecec; }
        .success { color: green; }
        .error { color: #b00000; }
    </style>
</head>
<body>
    <h1>SteamDB - Administrator</h1>
    <nav class="navigation">
        <a href="dashboard.php">Back to Dashboard</a>
        <strong>Hi, <?= e($_SESSION['username'] ?? 'Admin') ?> (Admin)</strong>
    </nav>
    <hr>
    <h2>Manage Users</h2>
    <p>View buyer accounts and delete a selected buyer.</p>

    <?php if (!empty($_SESSION['user_management_message'])): ?>
        <p class="success"><?= e($_SESSION['user_management_message']) ?></p>
        <?php unset($_SESSION['user_management_message']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['user_management_error'])): ?>
        <p class="error"><?= e($_SESSION['user_management_error']) ?></p>
        <?php unset($_SESSION['user_management_error']); ?>
    <?php endif; ?>

    <h3>Total Buyer Accounts: <?= count($buyers) ?></h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Country</th>
                    <th>Games Owned</th>
                    <th>Purchases</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($buyers as $buyer): ?>
                <tr>
                    <td><?= (int) $buyer['user_id'] ?></td>
                    <td><?= e($buyer['username']) ?></td>
                    <td><?= e($buyer['email']) ?></td>
                    <td><?= e($buyer['country_code']) ?></td>
                    <td><?= (int) $buyer['owned_games'] ?></td>
                    <td><?= (int) $buyer['purchase_count'] ?></td>
                    <td>
                        <form action="users.php" method="POST"
                              onsubmit="return confirm('Permanently delete this buyer, their library, and ALL their purchase history? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['delete_user_csrf']) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $buyer['user_id'] ?>">
                            <button class="delete-button" type="submit" name="action" value="delete_user">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$buyers): ?>
                    <tr><td colspan="7">No buyer accounts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
