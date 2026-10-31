
<?php

session_start();

// Only logged-in buyers can access the homepage.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'buyer') {
    http_response_code(403);
    exit('Buyer access only.');
}

$username = $_SESSION['username'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>SteamDB - Home</title>

    <style>

        body {
            margin: 20px;
        }

        /* Header: SteamDB on left, user profile on right */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Three main homepage cards */
        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-top: 100px;
        }

        .card {
            min-height: 250px;
            border: 2px solid black;
            background: white;
            font-size: 22px;
            cursor: pointer;
        }

        button {
            cursor: pointer;
        }

        /* Mobile layout */
        @media (max-width: 650px) {
            .cards {
                grid-template-columns: 1fr;
                margin-top: 40px;
            }
        }

    </style>

</head>

<body>

    <!-- Homepage header -->
    <header class="header">

        <h1>SteamDB</h1>

        <!-- Logged-in user's profile -->
        <button
            type="button"
            onclick="window.location.href='profile.php'">

            Hi, <?= htmlspecialchars(
                $username,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </button>

    </header>


    <!-- Three homepage cards -->
    <section class="cards">

        <!-- All Games -->
        <button
            class="card"
            type="button"
            onclick="window.location.href='index.php'">

            All Games

        </button>


        <!-- Discount Games -->
        <button
            class="card"
            type="button"
            onclick="window.location.href='discount_games.php'">

            Discount Games

        </button>


        <!-- Weekly Best-->
       
<button
    class="card"
    type="button"
    onclick="window.location.href='weekly_best.php'">

    Weekly Best

</button>


    </section>

</body>
</html>
