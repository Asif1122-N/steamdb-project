<?php

// Shared template for administrator sections.
// Each destination page supplies its own title and features.

if (
    !isset($sectionTitle, $plannedFeatures) ||
    !is_string($sectionTitle) ||
    !is_array($plannedFeatures)
) {
    http_response_code(404);
    exit('Page not found.');
}

session_start();

// Require login.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Require administrator role.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Administrator access only.');
}

header('Cache-Control: no-store, no-cache, must-revalidate');

function e($value) {
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

    <title>
        SteamDB - <?= e($sectionTitle) ?>
    </title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .content {
            margin-top: 35px;
            max-width: 850px;
        }

        li {
            margin-bottom: 12px;
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
            Hi, <?= e($_SESSION['username'] ?? 'Admin') ?>
        </strong>

    </nav>

    <hr>

    <main class="content">

        <h2><?= e($sectionTitle) ?></h2>

        <p>
            This administrator section is ready
            for its management functionality.
        </p>

        <h3>Planned functionality</h3>

        <ul>
            <?php foreach ($plannedFeatures as $feature): ?>
                <li><?= e($feature) ?></li>
            <?php endforeach; ?>
        </ul>

        <p>
            We will implement these features in
            the next development stages.
        </p>

    </main>

</body>
</html>
