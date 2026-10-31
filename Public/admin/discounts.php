<?php
session_start();

// Only administrators may manage discounts.
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

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function validSqlDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

if (empty($_SESSION['discount_csrf'])) {
    $_SESSION['discount_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$selectedPriceId = 0;
$submittedPercent = null;
$submittedStart = null;
$submittedEnd = null;

// Handle the form without creating an additional PHP page.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['discount_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid form request. Refresh the page and try again.');
    }

    $idRaw = $_POST['price_id'] ?? '';
    $selectedPriceId = is_string($idRaw) && ctype_digit($idRaw) ? (int) $idRaw : 0;
    $percentRaw = $_POST['discount_percent'] ?? '';
    $startRaw = $_POST['discount_start'] ?? '';
    $endRaw = $_POST['discount_end'] ?? '';

    if ($selectedPriceId <= 0 || !is_string($percentRaw) ||
        !is_string($startRaw) || !is_string($endRaw)) {
        $errors[] = 'Invalid form values.';
    } else {
        $submittedPercent = trim($percentRaw);
        $submittedStart = trim($startRaw);
        $submittedEnd = trim($endRaw);

        if (!preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/', $submittedPercent)
            || (float) $submittedPercent > 100) {
            $errors[] = 'Enter a discount between 0 and 100, with up to two decimal places.';
        }

        // Zero percent means no promotion, so clear both stored dates.
        if (!$errors && (float) $submittedPercent === 0.0) {
            $submittedStart = '';
            $submittedEnd = '';
        } elseif (!$errors) {
            if (!validSqlDate($submittedStart) || !validSqlDate($submittedEnd)) {
                $errors[] = 'A positive discount requires valid start and end dates.';
            } elseif ($submittedEnd < $submittedStart) {
                $errors[] = 'The end date cannot be earlier than the start date.';
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock the specific price record; each country may have its own price.
            $stmt = $pdo->prepare(
                'SELECT price_id, discount_percent, discount_start, discount_end
                 FROM prices WHERE price_id = ? FOR UPDATE'
            );
            $stmt->execute([$selectedPriceId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                throw new DomainException('Price record not found.');
            }

            $newPercent = number_format((float) $submittedPercent, 2, '.', '');
            $newStart = $submittedStart === '' ? null : $submittedStart;
            $newEnd = $submittedEnd === '' ? null : $submittedEnd;
            $oldPercent = number_format((float) $current['discount_percent'], 2, '.', '');

            if ($oldPercent === $newPercent &&
                $current['discount_start'] === $newStart &&
                $current['discount_end'] === $newEnd) {
                $pdo->rollBack();
                $_SESSION['discount_notice'] = 'No changes were made.';
            } else {
                $update = $pdo->prepare(
                    'UPDATE prices
                     SET discount_percent = :discount,
                         discount_start = :start_date,
                         discount_end = :end_date
                     WHERE price_id = :id'
                );
                $update->bindValue(':discount', $newPercent, PDO::PARAM_STR);
                $update->bindValue(':start_date', $newStart, $newStart === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $update->bindValue(':end_date', $newEnd, $newEnd === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $update->bindValue(':id', $selectedPriceId, PDO::PARAM_INT);
                $update->execute();

                // Log what action occurred, not previous/new field values.
                $log = $pdo->prepare(
                    "INSERT INTO admin_activity_log
                     (admin_user_id, action_type, entity_type, entity_id)
                     VALUES (?, 'UPDATE_DISCOUNT', 'prices', ?)"
                );
                $log->execute([(int) $_SESSION['user_id'], $selectedPriceId]);

                $pdo->commit();
                $_SESSION['discount_notice'] = 'Discount updated successfully for price ID ' . $selectedPriceId . '.';
            }

            $_SESSION['discount_csrf'] = bin2hex(random_bytes(32));
            header('Location: discounts.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof DomainException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('Discount update failed: ' . $exception->getMessage());
                $errors[] = 'Could not update the discount. Check the PHP server log.';
            }
        }
    }
} else {
    $idRaw = $_GET['edit'] ?? '';
    if (is_string($idRaw) && ctype_digit($idRaw)) {
        $selectedPriceId = (int) $idRaw;
    }
}

// Reload actual database values after every request.
$prices = $pdo->query(
    "SELECT p.price_id, p.game_id, g.game_name,
            p.country_code, c.currency_code, p.base_price,
            p.discount_percent, p.discount_start, p.discount_end,
            CASE
                WHEN COALESCE(p.discount_percent, 0) = 0 OR
                     p.discount_start IS NULL OR p.discount_end IS NULL THEN 'No discount'
                WHEN CURDATE() < p.discount_start THEN 'Scheduled'
                WHEN CURDATE() > p.discount_end THEN 'Expired'
                ELSE 'Active'
            END AS discount_status
     FROM prices AS p
     JOIN game AS g ON g.game_id = p.game_id
     JOIN country_code AS c ON c.country_code = p.country_code
     ORDER BY g.game_id, p.country_code, p.price_id"
)->fetchAll(PDO::FETCH_ASSOC);

$selectedPrice = null;
foreach ($prices as $row) {
    if ((int) $row['price_id'] === $selectedPriceId) {
        $selectedPrice = $row;
        break;
    }
}
if ($selectedPriceId > 0 && !$selectedPrice) {
    $errors[] = 'The selected price record does not exist.';
}

$formPercent = $submittedPercent ?? ($selectedPrice['discount_percent'] ?? '0.00');
$formStart = $submittedStart ?? ($selectedPrice['discount_start'] ?? '');
$formEnd = $submittedEnd ?? ($selectedPrice['discount_end'] ?? '');
$notice = $_SESSION['discount_notice'] ?? '';
unset($_SESSION['discount_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SteamDB - Manage Discounts</title>
    <style>
        body { margin: 20px; font-family: Arial, sans-serif; }
        nav { display: flex; justify-content: space-between; align-items: center; }
        table { border-collapse: collapse; width: 100%; margin-top: 22px; }
        th, td { border: 1px solid #aaa; padding: 10px; text-align: left; }
        th { background: #eee; }
        .table-wrap { overflow-x: auto; }
        .editor { max-width: 650px; padding: 20px; border: 1px solid #888; margin: 25px 0; }
        .field { margin: 15px 0; }
        .field label { display: block; margin-bottom: 6px; }
        .field input { padding: 8px; width: 100%; box-sizing: border-box; }
        button, .modify { padding: 8px 14px; cursor: pointer; }
        .modify { border: 1px solid #555; color: black; text-decoration: none; display: inline-block; }
        .error { color: #b00000; }
        .success { color: green; }
        .actions { display: flex; align-items: center; gap: 15px; }
    </style>
</head>
<body>
    <h1>SteamDB - Administrator</h1>
    <nav>
        <a href="dashboard.php">Back to Dashboard</a>
        <strong>Hi, <?= e($_SESSION['username'] ?? 'Admin') ?> (Admin)</strong>
    </nav>
    <hr>
    <h2>Manage Discounts</h2>
    <p>Update discount percentages and dates. Original prices remain unchanged.</p>

    <?php if ($notice !== ''): ?>
        <p class="success"><?= e($notice) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endforeach; ?>

    <?php if ($selectedPrice): ?>
        <section class="editor" id="editor">
            <h3>Modify Discount — <?= e($selectedPrice['game_name']) ?></h3>
            <p>Price ID: <?= (int) $selectedPrice['price_id'] ?> |
               Country: <?= e($selectedPrice['country_code']) ?> |
               Original price: <?= e($selectedPrice['currency_code']) ?>
               <?= number_format((float) $selectedPrice['base_price'], 2) ?></p>

            <form action="discounts.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['discount_csrf']) ?>">
                <input type="hidden" name="price_id" value="<?= (int) $selectedPrice['price_id'] ?>">
                <div class="field">
                    <label for="discount_percent">Discount percentage (0–100)</label>
                    <input type="number" name="discount_percent" id="discount_percent"
                           min="0" max="100" step="0.01" required value="<?= e($formPercent) ?>">
                </div>
                <div class="field">
                    <label for="discount_start">Discount start date</label>
                    <input type="date" name="discount_start" id="discount_start" value="<?= e($formStart) ?>">
                </div>
                <div class="field">
                    <label for="discount_end">Discount end date</label>
                    <input type="date" name="discount_end" id="discount_end" value="<?= e($formEnd) ?>">
                </div>
                <p>Set discount to <strong>0%</strong> to remove the discount; its dates will be cleared automatically.</p>
                <div class="actions">
                    <button type="submit">Save Discount</button>
                    <a href="discounts.php">Cancel</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <h3>Game Prices and Discounts</h3>
    <p>Price records found: <?= count($prices) ?></p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Price ID</th><th>Game</th><th>Country / Currency</th>
                    <th>Original Price</th><th>Discount</th><th>Price at Listed Discount</th>
                    <th>Start</th><th>End</th><th>Promotion</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($prices as $row): ?>
                <?php $salePrice = (float) $row['base_price'] * (1 - (float) $row['discount_percent'] / 100); ?>
                <tr>
                    <td><?= (int) $row['price_id'] ?></td>
                    <td><?= e($row['game_name']) ?> (<?= (int) $row['game_id'] ?>)</td>
                    <td><?= e($row['country_code']) ?> / <?= e($row['currency_code']) ?></td>
                    <td><?= number_format((float) $row['base_price'], 2) ?></td>
                    <td><?= number_format((float) $row['discount_percent'], 2) ?>%</td>
                    <td><?= number_format($salePrice, 2) ?></td>
                    <td><?= e($row['discount_start'] ?? '—') ?></td>
                    <td><?= e($row['discount_end'] ?? '—') ?></td>
                    <td><?= e($row['discount_status']) ?></td>
                    <td><a class="modify" href="discounts.php?edit=<?= (int) $row['price_id'] ?>#editor">Modify</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
