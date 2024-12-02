<?php
require '../vendor/autoload.php';
require '../includes/functions.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Establish a database connection
try {
    $pdo = new PDO("mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4", $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Session
session_start();
if (!isset($_SESSION['visit_comes_from'])) {
    $_SESSION['visit_comes_from'] = 'unknown';
}

if (isset($_SESSION['current_subscriber']) and $_SESSION['current_subscriber'] !== false) {
    // Update user preferences if sent
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update-preferences'])) {
        $updateStmt = $pdo->prepare("
        UPDATE subscribers SET
            spanish = :spanish,
            german = :german,
            italian = :italian,
            french = :french,
            portuguese = :portuguese,
            norwegian = :norwegian
        WHERE id = :id
        ");
        $params = [
            ':spanish' => isset($_POST['spanish']) ? 1 : 0,
            ':german' => isset($_POST['german']) ? 1 : 0,
            ':italian' => isset($_POST['italian']) ? 1 : 0,
            ':french' => isset($_POST['french']) ? 1 : 0,
            ':portuguese' => isset($_POST['portuguese']) ? 1 : 0,
            ':norwegian' => isset($_POST['norwegian']) ? 1 : 0,
            ':id' => $_SESSION['current_subscriber']
        ];
        $updateStmt->execute($params);
    }

    // Get subscriber data
    $stmt = $pdo->prepare("SELECT * FROM subscribers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $_SESSION['current_subscriber']]);
    $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $_SESSION['current_subscriber'] = false;
}

if (!isset($_SESSION['token_access_trials_via_link'])) {
    $_SESSION['token_access_trials_via_link'] = 0;
}

// Does the visit come from an email link
if (isset($_GET['from']) and $_GET['from'] === 'email') {
    $_SESSION['visit_comes_from'] = 'email';
}

// Does the visit come from a whatsapp link
if (isset($_GET['from']) and $_GET['from'] === 'whatsapp') {
    $_SESSION['visit_comes_from'] = 'whatsapp';
}

// View
$view = 'default_view';

// Any forced section to visit?
if (isset($_GET['do']) && $_GET['do'] === 'subscribe') {
    $view = 'subscribe';
}

if (isset($_GET['phrase'])) {
    // Check if it is a valid Y-m-d date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['phrase'])) {
        header('Location: /');
        exit;
    }
    // Check if date is less than or equal to today
    if ($_GET['phrase'] > date('Y-m-d')) {
        header('Location: /');
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM phrases WHERE date = :phrase");
    $stmt->execute([':phrase' => $_GET['phrase']]);
    $view_phrase = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($view_phrase) {
        $view = 'view_phrase';
    }
}

// Handle form submission
if ($view === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) && isset($_POST['g-recaptcha-response']) && is_recaptcha_token_verification_successful($_POST['g-recaptcha-response'])) {
    $email = $_POST['email'];
    $languages = ['spanish', 'german', 'italian', 'french', 'portuguese', 'norwegian'];
    $last_sent = date('Y-m-d', strtotime('-1 day'));

    // Prepare your statement
    $insertOrUpdateStmt = $pdo->prepare("
    INSERT INTO subscribers (email, last_sent, spanish, german, italian, french, portuguese, norwegian)
    VALUES (:email, :last_sent, :spanish, :german, :italian, :french, :portuguese, :norwegian)
    ON DUPLICATE KEY UPDATE
        last_sent = VALUES(last_sent),
        spanish = VALUES(spanish),
        german = VALUES(german),
        italian = VALUES(italian),
        french = VALUES(french),
        portuguese = VALUES(portuguese),
        norwegian = VALUES(norwegian)
    ");

    // Define your parameters
    $params = [
        ':email' => $email,
        ':last_sent' => $last_sent,
        ':spanish' => isset($_POST['spanish']) ? 1 : 0,
        ':german' => isset($_POST['german']) ? 1 : 0,
        ':italian' => isset($_POST['italian']) ? 1 : 0,
        ':french' => isset($_POST['french']) ? 1 : 0,
        ':portuguese' => isset($_POST['portuguese']) ? 1 : 0,
        ':norwegian' => isset($_POST['norwegian']) ? 1 : 0,
    ];

    // Execute the statement
    if ($insertOrUpdateStmt->execute($params)) {
        // Check if the record was inserted or updated
        // Use a SELECT query to retrieve the ID based on the unique key (email)
        $selectStmt = $pdo->prepare("SELECT id FROM subscribers WHERE email = :email");
        $selectStmt->execute([':email' => $email]);

        // Fetch the ID
        $subscriber = $selectStmt->fetch(PDO::FETCH_ASSOC);
        $subscriber_id = $subscriber ? $subscriber['id'] : null;

        $token = generateToken($subscriber_id, $email);
        $verification_link = $_ENV['SITE_URL'] . '/?id=' . urlencode($subscriber_id) . '&token=' . urlencode($token) . '&action=verify&from=email';

        $welcome_email = '<h1>Thanks for joining Poly Phrases!</h1>
        <p>Please confirm your email clicking the link below, in order to start receiving your daily multilingual phrases:</p>
        <p><a href="' . $verification_link . '" style="background-color:#1b74e4;text-decoration:none;display:inline-block;padding:10px 16px;border-radius:5px;font-size: 16px;font-family:Helvetica,sans-serif;font-weight:bold;color:#FFFFFF;line-height:16px;">Confirm&nbsp;now</a></p><p>You will enjoy the daily phrases, hope you learn something useful every day! :)</p><hr>
        <p style="margin-top:30px;font-size:11px;color:#555;">30 N Gould St Ste N, Sheridan, WY 82801</p>';

        // Send verification email (Use your own mail function or mail library)
        send_email($email, "Verify your email to start receiving your Poly Phrases", $welcome_email);

        $view = 'sent_link';
    } else {
        $view = 'error';
    }
}

// Handle subscriber specific tasks that require a login via email link
if (isset($_GET['id']) && isset($_GET['token']) && $_SESSION['token_access_trials_via_link'] < 50) {
    $_SESSION['token_access_trials_via_link']++;

    $subscriber_id = urldecode($_GET['id']);
    $token = urldecode($_GET['token']);

    $stmt = $pdo->prepare("SELECT * FROM subscribers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $subscriber_id]);
    $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($subscriber) {
        $expected_token = generateToken($subscriber['id'], $subscriber['email']);
        if ($token === $expected_token) {
            $_SESSION['current_subscriber'] = $subscriber['id'];

            if (isset($_GET['action']) && $_GET['action'] === 'verify') {
                if (!$subscriber['verified']) {
                    $update_stmt = $pdo->prepare("UPDATE subscribers SET verified = 1 WHERE id = :id");
                    $update_stmt->execute([':id' => $subscriber['id']]);
                }
                $view = 'verification_completed';
            }

            if (isset($_GET['action']) && $_GET['action'] === 'unsubscribe') {
                $update_stmt = $pdo->prepare("UPDATE subscribers SET verified = 8 WHERE id = :id");
                $update_stmt->execute([':id' => $subscriber['id']]);
                $view = 'unsubscribed';
            }

            if (isset($view_phrase['date'])) {

                $today = new DateTime();
                try {
                    $last_visited = isset($subscriber['last_visited']) ? new DateTime($subscriber['last_visited']) : null;
                } catch (Exception $e) {
                    header('Location: /' . $view_phrase['date']);
                    exit;
                }

                if (!$last_visited) {
                    $updated_streak = 1;
                } else {
                    $interval = (new DateTime($last_visited->format('Y-m-d')))->diff(new DateTime($today->format('Y-m-d')))->days;

                    if ($interval === 1 or $interval === 2) {
                        // Continued streak (up to 2 days to be cool with people)
                        $updated_streak = $subscriber['streak'] + 1;
                    } elseif ($interval > 2) {
                        // Streak broken; reset
                        $updated_streak = 1;
                    }
                }

                // Update the subscriber's last visited date and streak
                if (isset($updated_streak)) {
                    $update_stmt = $pdo->prepare("UPDATE subscribers SET last_visited = :today, streak = :streak WHERE id = :id");
                    $update_stmt->execute([
                        ':today' => $today->format('Y-m-d'),
                        ':streak' => $updated_streak,
                        ':id' => $_SESSION['current_subscriber']
                    ]);
                    $subscriber['streak'] = $updated_streak;
                }
            }

        }

    }

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $page_og_image = '/assets/polyphrases.webp';
    $page_og_title = 'Poly Phrases';
    switch ($view) {
        case 'view_phrase':
            if (!isset($view_phrase)) {
                exit;
            }
            $page_title = '- ' . $view_phrase['phrase'];
            $page_og_title = $view_phrase['phrase'];
            $page_og_image = '/images/' . $view_phrase['date'] . '.jpg';
            break;
        case 'sent_link':
            $page_title = '- Verification link sent';
            break;
        case 'verification_completed':
            $page_title = '- Email verified';
            break;
        case 'subscribe':
            $page_title = '- Subscribe';
            echo '<script src="https://www.google.com/recaptcha/api.js"></script>';
            break;
        case 'unsubscribed':
            $page_title = '- Unsubscribed';
            break;
        case 'error':
            $page_title = '- Error';
            break;
        default:
            $page_title = ' | Practice your languages!';
    }
    ?>
    <title>Poly Phrases <?php echo $page_title; ?></title>
    <meta property="og:title" content="<?php echo $page_og_title; ?>">
    <meta property="og:description"
          content="Subscribe to receive daily phrases in various languages to boost your language skills. Simple, free, and effective learning experience.">
    <meta property="og:image" content="https://polyphrases.com<?php echo $page_og_image; ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Poly Phrases">
    <link rel="icon" href="/assets/icon.png" type="image/png">
    <link rel="stylesheet" href="/assets/suppastyle.css?version=yeah3">
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-XP2B88NNS7"></script>
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }

        gtag('js', new Date());
        gtag('config', 'G-XP2B88NNS7');
        gtag('event', 'visit_source', {
            'event_category': 'Visit Source',
            'event_label': '<?php echo $_SESSION['visit_comes_from']; ?>',
            'value': 1
        });
    </script>
</head>
<body>
<main>
    <?php
    switch ($view) {
        case 'view_phrase':
            include 'parts/view_phrase.php';
            break;
        case 'sent_link':
            include 'parts/sent_link.php';
            break;
        case 'verification_completed':
            include 'parts/verification_complete.php';
            break;
        case 'subscribe':
            include 'parts/subscribe.php';
            break;
        case 'unsubscribed':
            include 'parts/unsubscribed.php';
            break;
        case 'error':
            include 'parts/error.php';
            break;
        default:
            include 'parts/welcome.php';
    }
    ?>
</main>
<aside>
    <?php
    $views_without_aside = ['sent_link', 'subscribe'];
    if (!in_array($view, $views_without_aside)) {
        // Pick 5 random phrases
        if ($view === 'view_phrase') {
            $stmt = $pdo->prepare("SELECT * FROM phrases WHERE imaged=1 and date<CURDATE() and date!=:date ORDER BY RAND() LIMIT 4");
            $stmt->bindParam(':date', $_GET['phrase']);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM phrases WHERE imaged=1 and date<CURDATE() ORDER BY RAND() LIMIT 4");
        }
        $stmt->execute();
        $phrases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Randomize the position and size of the phrases
        $randomization_power = [
            [
                'top:' . rand(3, 6) . '%',
                'right:' . rand(3, 7) . '%',
                'width:' . rand(210, 280) . 'px'
            ],
            [
                'bottom:' . rand(3, 8) . '%',
                'right:' . rand(3, 7) . '%',
                'width:' . rand(205, 260) . 'px'
            ],
            [
                'bottom:' . rand(3, 6) . '%',
                'left:' . rand(3, 7) . '%',
                'width:' . rand(210, 285) . 'px'
            ],
            [
                'top:' . rand(3, 8) . '%',
                'left:' . rand(3, 7) . '%',
                'width:' . rand(206, 275) . 'px'
            ]
        ];

        // Display the phrases
        $i = 0;
        foreach ($phrases as $phrase) {
            $languages = ['phrase', 'spanish', 'german', 'italian', 'french', 'portuguese', 'norwegian'];
            echo '
        <div class="example-phrase" style="' . implode(';', $randomization_power[$i]) . '">
            <a href="/' . $phrase['date'] . '"><figure>
                <img src="/images/' . $phrase['date'] . '.jpg" alt="Day ' . $phrase['phrase'] . '">
                <figcaption><span>' . truncate_with_ellipsis($phrase[$languages[array_rand($languages)]]) . '</span></figcaption>
            </figure></a>
        </div>';
            $i++;
        }
    }
    ?>
</aside>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const mainElement = document.querySelector('main');
        const asideElement = document.querySelector('aside');

        // Make sure both elements exist
        if (!mainElement || !asideElement) {
            return;
        }

        mainElement.addEventListener('scroll', function () {
            const scrollTop = mainElement.scrollTop;
            const scrollHeight = mainElement.scrollHeight - mainElement.clientHeight;
            const scrollPercentage = scrollTop / scrollHeight;

            // Calculate the new opacity (0.2 to 0.9)
            const newOpacity = 0.2 + (0.7 * scrollPercentage);

            // Update the overlay's background color directly using a new style rule
            const style = document.createElement('style');
            style.innerHTML = `
                aside::after{
                    background: rgba(0, 0, 0, ${newOpacity});
                }
            `;

            // Remove any previously added styles
            const existingStyle = document.querySelector('#dynamic-style');
            if (existingStyle) existingStyle.remove();

            // Add the new style
            style.id = 'dynamic-style';
            document.head.appendChild(style);
        });
    });
</script>
</body>
</html>
