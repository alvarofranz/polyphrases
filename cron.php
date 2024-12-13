<?php
$debug = true;

// Internal debug function
function debug_log($message) {
    global $debug;
    if ($debug) {
        $logFile = __DIR__ . '/debug_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $formatted_message = "[$timestamp] $message\n";
        file_put_contents($logFile, $formatted_message, FILE_APPEND);
    }
}

debug_log("Script started.");

// Include dependencies
debug_log("Loading dependencies...");
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/functions.php';

debug_log("Dependencies loaded.");

// Load environment variables
debug_log("Loading environment variables...");
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
debug_log("Environment variables loaded.");

// Define a color variable for the email
$colors = array(
    '#0099e5',
    '#ff4c4c',
    '#00a98f',
    '#be0027',
    '#371777',
    '#008374',
    '#037ef3',
    '#f85a40',
    '#0cb9c1',
    '#f48924',
    '#da1884',
    '#a51890'
);
$emailColor = $colors[array_rand($colors)];
debug_log("Email color selected: $emailColor");

// Establish a database connection
debug_log("Establishing database connection...");
try {
    $pdo = new PDO(
        "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    debug_log("Database connection successful.");
} catch (PDOException $e) {
    debug_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Fetch today's date
$today = date('Y-m-d');
debug_log("Today's date: $today");

// Fetch today's phrase
debug_log("Fetching today's phrase...");
$stmt = $pdo->prepare("SELECT * FROM phrases WHERE date = :today LIMIT 1");
$stmt->execute([':today' => $today]);
$phrase = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$phrase) {
    debug_log("No phrase found for today ($today). Exiting.");
    exit;
}
debug_log("Phrase found: " . print_r($phrase, true));

// Fetch up to 20 verified subscribers whose last_sent is less than today
debug_log("Fetching subscribers...");
$stmt = $pdo->prepare("SELECT * FROM subscribers WHERE verified = 1 AND last_sent < :today LIMIT 20");
$stmt->execute([':today' => $today]);
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$subscribers) {
    debug_log("No subscribers found to send to. Exiting.");
    exit;
}
debug_log("Subscribers found: " . count($subscribers));

// Prepare HTML parts
$hr_separator = '<hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">';

// Loop through subscribers
foreach ($subscribers as $subscriber) {
    debug_log("Processing subscriber ID: " . $subscriber['id']);

    $email = $subscriber['email'];
    debug_log("Subscriber email: $email");

    // Validate email address
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        debug_log("Invalid email detected ($email). Deleting subscriber ID: " . $subscriber['id']);
        // Delete this subscriber
        $delete_stmt = $pdo->prepare("DELETE FROM subscribers WHERE id = :id");
        $delete_stmt->execute([':id' => $subscriber['id']]);
        continue;
    }

    // Calculate engagement ratios
    $delivered = $subscriber['delivered'];
    $opens = $subscriber['opens'];
    $clicks = $subscriber['clicks'];
    debug_log("Engagement: delivered=$delivered, opens=$opens, clicks=$clicks");

    // Decide whether to send email
    $send_email = false;

    if ($delivered == 0) {
        $click_ratio = 0;
        $open_ratio = 0;
    } else {
        $click_ratio = ($clicks / $delivered) * 100;
        $open_ratio = ($opens / $delivered) * 100;
    }

    // Do we really wanna send to this person?
    debug_log("Open ratio: $open_ratio%, Click ratio: $click_ratio%");

    if ($delivered < 3 || $click_ratio > 49 || $open_ratio > 65) {
        $send_email = true;
        debug_log("Criteria met to send email (low delivered or high engagement).");
    } else {
        // Randomly send if open ratio under 65 but above 35
        if ($open_ratio > 35) {
            $rand = mt_rand(1, 100);
            debug_log("Open ratio is between 35% and 65%. Random check: $rand <= 70?");
            if ($rand <= 70) {
                $send_email = true;
                debug_log("Random criteria met, sending email.");
            } else {
                debug_log("Random criteria failed, not sending email.");
            }
        } else {
            // Don't send to unengaged people, set them as stale
            debug_log("Open ratio < 35%, marking subscriber as stale (verified=6).");
            $stmt = $pdo->prepare("UPDATE subscribers SET verified = 6 WHERE id = :id");
            $stmt->execute(['id' => $subscriber['id']]);
        }
    }

    debug_log("Send email decision: " . ($send_email ? 'Yes' : 'No'));

    // Generate the unsubscribe link
    $subscriber_token = generateToken($subscriber['id'], $email);
    debug_log("Generated subscriber token: $subscriber_token");
    $unsubscribe_link = $_ENV['SITE_URL'] . '/?id=' . urlencode($subscriber['id']) . '&token=' . urlencode($subscriber_token) . '&action=unsubscribe';

    // Build the message
    $message = "<h1 style='color: $emailColor;'>Today's Phrase</h1>
    <p style='font-size:16px;padding:15px;background-color:$emailColor;color:#FFF;border-radius:8px;'>"
        . htmlspecialchars($phrase['phrase']) . "</p>" . $hr_separator;

    // Add translations based on subscriber's preferences
    if ($subscriber['spanish']) {
        $message .= "<p><strong>Spanish:</strong> " . htmlspecialchars($phrase['spanish']) . "</p>";
    }
    if ($subscriber['german']) {
        $message .= "<p><strong>German:</strong> " . htmlspecialchars($phrase['german']) . "</p>";
    }
    if ($subscriber['italian']) {
        $message .= "<p><strong>Italian:</strong> " . htmlspecialchars($phrase['italian']) . "</p>";
    }
    if ($subscriber['french']) {
        $message .= "<p><strong>French:</strong> " . htmlspecialchars($phrase['french']) . "</p>";
    }
    if ($subscriber['portuguese']) {
        $message .= "<p><strong>Portuguese:</strong> " . htmlspecialchars($phrase['portuguese']) . "</p>";
    }
    if ($subscriber['norwegian']) {
        $message .= "<p><strong>Norwegian:</strong> " . htmlspecialchars($phrase['norwegian']) . "</p>";
    }

    $message .= $hr_separator;

    // Extended arrays of emojis and motivational phrases
    $emojis = [
        '🚀', '🎉', '🔥', '🌟', '🏆', '✨', '🌈', '💪', '👍', '👏',
        '😁', '😃', '😊', '🥳', '🤩', '🙌', '💫', '🎯', '🗣️', '📚',
        '📝', '🎓', '🌠', '🏅', '🎖️', '💎', '🥇', '🥈', '🥉', '💥',
        '💡', '🎈', '⚡', '💖', '👑', '🤠', '🤗', '😎', '😺', '🏍',
        '👊', '✌️', '🤟', '👌', '🙏', '💃', '🕺', '🎵', '🌞',
        '🌊', '🍀', '🍾', '🏖️', '🛡️', '🦾', '🧠', '💻', '📈', '📣',
        '🔔', '🎹', '🎸', '🎺', '🥁', '🌋', '⛰️', '🏔️', '🏝️', '🌅',
        '🌄', '🎆', '🌌', '🌃', '💐', '🌸', '🍎', '🍉', '🍇', '🍒',
        '🚴‍♂️', '🏄‍♀️', '🏇', '🚣‍♂️', '🏊‍♀️', '🤸‍♂️', '🤾‍♀️', '🥋', '🧗‍♂️', '🏹',
        '🛹', '🎢', '🎡', '🎠', '🛴', '🚂', '✈️', '🚁', '🚀', '🛸'
    ];

    $motivational_phrases = [
        "Keep up the great work!",
        "You're on a roll!",
        "Your dedication is inspiring!",
        "Stay motivated!",
        "Let's keep the momentum going!",
        "You're reaching new heights!",
        "Your commitment is exemplary!",
        "You're a language learning champion!",
        "Dive in and boost your learning journey!",
        "You're making fantastic progress!",
        "Keep the streak alive!",
        "Your hard work is paying off!",
        "Every day is a step closer to fluency!",
        "Amazing effort!",
        "Keep pushing forward!",
        "You're doing great!",
        "Keep shining!",
        "Way to go!",
        "Excellent job!",
        "You're unstoppable!",
        "Fantastic progress!",
        "Impressive dedication!",
        "Keep the fire burning!",
        "Nothing can stop you now!",
        "Keep it up!",
        "You're making us proud!",
        "Onwards and upwards!",
        "Great job!",
        "You're acing it!",
        "Bravo!",
        "You're a star!",
        "Success is yours!",
        "Keep conquering!",
        "Outstanding performance!",
        "Well done!",
        "Keep climbing!",
        "You're blazing trails!",
        "The sky's the limit!",
        "You're making waves!",
        "Keep rocking!",
        "You're a powerhouse!",
        "You're an inspiration!",
        "Keep aiming high!",
        "Your progress is amazing!",
        "Keep smashing those goals!",
        "You're doing a fantastic job!",
        "Keep the momentum!",
        "You're making a difference!",
        "Your efforts are commendable!",
        "Keep striving!",
        "You're a legend!",
        "The world is yours!",
        "You're going places!",
        "Your future is bright!",
        "Embrace the journey!",
        "Believe in yourself!",
        "You're unlocking greatness!",
        "Keep exploring!",
        "You're mastering it!",
        "Rise and shine!",
        "Seize the day!",
        "You're making history!",
        "Aim for the stars!",
        "Forge ahead!",
        "Unleash your potential!",
        "Victory is near!",
        "Shine on!",
        "Keep the flame alive!",
        "You're turning heads!",
        "Keep exceeding expectations!",
        "Your journey is remarkable!",
        "Keep elevating!",
        "You're a force to be reckoned with!",
        "March on!",
        "Your zeal is admirable!",
        "Keep up the pace!",
        "You're a trailblazer!",
        "Chart your own path!",
        "Keep dazzling!",
        "You're making leaps and bounds!",
        "Stay awesome!",
        "Keep setting records!",
        "Your passion is contagious!",
        "You're a game changer!",
        "Keep making strides!",
        "You're lighting the way!",
        "Soar high!",
        "You're doing wonders!",
        "Keep the spirit alive!",
        "You're creating ripples!",
        "Keep breaking barriers!",
        "Your enthusiasm is electrifying!",
        "Keep being amazing!",
        "You're a beacon of excellence!",
        "Keep making magic!",
        "You're the real MVP!",
        "Keep forging ahead!",
        "You're the architect of your success!",
        "Keep building greatness!"
    ];

    // Show current consecutive days and points
    $consecutive_days = $subscriber['streak'];
    $points = $subscriber['points'];
    debug_log("Subscriber streak: $consecutive_days, points: $points");

    // Singular or plural 'days'
    $day_str = $consecutive_days == 1 ? "day" : "days";

    // Randomly select an emoji and motivational phrase
    $emoji = $emojis[array_rand($emojis)];
    $motivational_phrase = $motivational_phrases[array_rand($motivational_phrases)];

    // Messages for different intervals
    $messages_zero_days = [
        "You haven't started practicing yet! Click the button below to kickstart your language learning adventure!",
        "Ready to begin your language journey? Tap the button and start today!",
        "Your path to fluency starts now! Hit the button below and dive in!",
        "The first step awaits! Click below to embark on your language adventure!",
        "No better time than now to start learning! Press the button and let's go!"
    ];

    $messages_zero_points = [
        "You've practiced for {$consecutive_days} {$day_str} but haven't earned any points yet. Try the practice sessions—you'll love them!",
        "{$consecutive_days} {$day_str} of effort! Engage in activities to earn points and track your progress!",
        "Keep going! Participate in practice sessions to start earning points!",
        "You're on your way! Earn points by completing practice tasks!",
        "Let's boost your progress! Start earning points with practice sessions!"
    ];

    $messages_one_day = [
        "Great start! You practiced for 1 day and earned {$points} points! {$motivational_phrase}",
        "Fantastic beginning! 1 day down, many more to go! {$motivational_phrase}",
        "You're off to a flying start with 1 day of practice and {$points} points! {$motivational_phrase}",
        "Awesome job on your first day! {$motivational_phrase}",
        "One day in, and you're already making progress! {$motivational_phrase}"
    ];

    $messages_two_days = [
        "Awesome! You've practiced for 2 consecutive days and earned {$points} points! {$motivational_phrase}",
        "Two days strong! Keep the momentum going! {$motivational_phrase}",
        "Great consistency over 2 days! {$motivational_phrase}",
        "Back-to-back practice! 2 days and counting! {$motivational_phrase}",
        "You're building a habit! 2 days straight! {$motivational_phrase}"
    ];

    $messages_three_to_five_days = [
        "Impressive! {$consecutive_days} days of consistent practice and {$points} points earned! {$motivational_phrase}",
        "You're on fire! {$consecutive_days} days in a row! {$motivational_phrase}",
        "Keep it up! {$consecutive_days} days of dedication! {$motivational_phrase}",
        "Outstanding commitment over {$consecutive_days} days! {$motivational_phrase}",
        "You're unstoppable! {$consecutive_days} days and going strong! {$motivational_phrase}"
    ];

    $messages_six_to_ten_days = [
        "Outstanding! {$consecutive_days} consecutive days of practice and {$points} points accumulated! {$motivational_phrase}",
        "Tenacity at its best! {$consecutive_days} days running! {$motivational_phrase}",
        "Double digits! {$consecutive_days} days of progress! {$motivational_phrase}",
        "You're a star performer! {$consecutive_days} days straight! {$motivational_phrase}",
        "Keep soaring! {$consecutive_days} days of excellence! {$motivational_phrase}"
    ];

    $messages_eleven_to_twenty_days = [
        "Amazing! {$consecutive_days} days in a row and {$points} points collected! {$motivational_phrase}",
        "Your dedication shines through {$consecutive_days} days! {$motivational_phrase}",
        "Over {$consecutive_days} days of relentless effort! {$motivational_phrase}",
        "You're a role model with {$consecutive_days} days of practice! {$motivational_phrase}",
        "Keep the energy high! {$consecutive_days} days and counting! {$motivational_phrase}"
    ];

    $messages_twentyone_to_thirty_days = [
        "Exceptional! {$consecutive_days} consecutive days of practice and {$points} points earned! {$motivational_phrase}",
        "What a milestone! {$consecutive_days} days of unwavering dedication! {$motivational_phrase}",
        "You're a powerhouse with {$consecutive_days} days of learning! {$motivational_phrase}",
        "Incredible persistence over {$consecutive_days} days! {$motivational_phrase}",
        "Keep breaking records! {$consecutive_days} days achieved! {$motivational_phrase}"
    ];

    $messages_over_thirty_days = [
        "Legendary! Over {$consecutive_days} days of continuous practice and {$points} points amassed! {$motivational_phrase}",
        "You're rewriting the record books with {$consecutive_days} days! {$motivational_phrase}",
        "An inspiration to all! {$consecutive_days} days of commitment! {$motivational_phrase}",
        "Your journey is epic! {$consecutive_days} days and unstoppable! {$motivational_phrase}",
        "You're a legend with {$consecutive_days} days of practice! {$motivational_phrase}"
    ];

    $messages_default = [
        "Keep it up! You've practiced for {$consecutive_days} {$day_str} and earned {$points} points! {$motivational_phrase}",
        "Great job! {$consecutive_days} {$day_str} of learning! {$motivational_phrase}",
        "You're making steady progress over {$consecutive_days} {$day_str}! {$motivational_phrase}",
        "Stay focused! {$consecutive_days} {$day_str} down! {$motivational_phrase}",
        "Keep the dedication strong! {$consecutive_days} {$day_str} and counting! {$motivational_phrase}"
    ];

    $message .= '<p>';

    if ($consecutive_days == 0) {
        if ($points == 0) {
            $msg = $messages_zero_days[array_rand($messages_zero_days)];
            debug_log("Using zero_days / zero_points message: $msg");
            $message .= $msg;
        } else {
            $msg = "You have {$points} points but haven't started practicing consistently yet! {$motivational_phrase}";
            debug_log("Using zero_days with points message: $msg");
            $message .= $msg;
        }
    } elseif ($points == 0) {
        $msg = $messages_zero_points[array_rand($messages_zero_points)];
        debug_log("Using zero_points message: $msg");
        $message .= $msg;
    } else {
        // Select appropriate messages based on consecutive days
        if ($consecutive_days == 1) {
            $msg = $messages_one_day[array_rand($messages_one_day)];
            debug_log("Using one_day message: $msg");
            $message .= $msg;
        } elseif ($consecutive_days == 2) {
            $msg = $messages_two_days[array_rand($messages_two_days)];
            debug_log("Using two_days message: $msg");
            $message .= $msg;
        } elseif ($consecutive_days >= 3 && $consecutive_days <= 5) {
            $msg = $messages_three_to_five_days[array_rand($messages_three_to_five_days)];
            debug_log("Using three_to_five_days message: $msg");
            $message .= $msg;
        } elseif ($consecutive_days >= 6 && $consecutive_days <= 10) {
            $msg = $messages_six_to_ten_days[array_rand($messages_six_to_ten_days)];
            debug_log("Using six_to_ten_days message: $msg");
            $message .= $msg;
        } elseif ($consecutive_days >= 11 && $consecutive_days <= 20) {
            $msg = $messages_eleven_to_twenty_days[array_rand($messages_eleven_to_twenty_days)];
            debug_log("Using eleven_to_twenty_days message: $msg");
            $message .= $msg;
        } elseif ($consecutive_days >= 21 && $consecutive_days <= 30) {
            $msg = $messages_twentyone_to_thirty_days[array_rand($messages_twentyone_to_thirty_days)];
            debug_log("Using twentyone_to_thirty_days message: $msg");
            $message .= $msg;
        } elseif ($consecutive_days > 30) {
            $msg = $messages_over_thirty_days[array_rand($messages_over_thirty_days)];
            debug_log("Using over_thirty_days message: $msg");
            $message .= $msg;
        } else {
            $msg = $messages_default[array_rand($messages_default)];
            debug_log("Using default message: $msg");
            $message .= $msg;
        }
    }

    $message .= '</p>';

    $message .= '<p>
        <a href="' . $_ENV['SITE_URL'] . '/' . $phrase['date'] . '?from=email&id=' . urlencode($subscriber['id']) . '&token=' . urlencode($subscriber_token) . '" style="
            display:inline-block;
            box-sizing:border-box;
            text-align:center;
            width:100%;
            max-width:600px;
            background-color:#fff;
            text-decoration:none;
            padding:10px 16px;
            border-radius:5px;
            border:3px solid ' . $emailColor . ';
            font-size:16px;
            font-family:Helvetica,sans-serif;
            font-weight:bold;
            color:' . $emailColor . ';
            line-height:16px;">Open Today’s Challenge! ' . $emoji . '</a></p>';

    $message .= $hr_separator . "<p><i>Don't just ignore this. Take your time to learn the new vocabulary, a small step a day makes wonders!</i></p>";

    // Add image if exists
    $image_path = __DIR__ . '/public/images/' . $phrase['date'] . '.jpg';
    if (file_exists($image_path)) {
        $image_url = $_ENV['SITE_URL'] . '/images/' . $phrase['date'] . '.jpg';
        debug_log("Adding image: $image_url");
        $message .= "<img src='" . $image_url . "' alt='Descriptive image for this phrase' style='width:100%;max-width:600px;height:auto;border-radius:8px;'>";
    } else {
        debug_log("No image found for date: " . $phrase['date']);
    }

    $message .= $hr_separator . '
    <p>Poly Phrases | Day: <i>' . $today . '</i></p>
    <p style="margin-top:30px;font-size:11px;color:#555;">
        30 N Gould St Ste N, Sheridan, WY 82801 - 
        <a href="' . $unsubscribe_link . '" title="Unsubscribe from Poly Phrases">Unsubscribe</a>
    </p>';

    // Send the email
    $subject = $phrase['phrase'];

    $do_not_update_as_sent = false;
    if ($send_email) {
        debug_log("Sending email to $email with subject $subject");
        try {
            send_email($email, $subject, $message);
            debug_log("Email sent successfully to $email.");
        } catch (Exception $e) {
            debug_log("Error sending email to $email: " . $e->getMessage());
            error_log('Caught exception: ' . $e->getMessage() . "\n", 3, __DIR__ . '/error_log.txt');
            $do_not_update_as_sent = true;
        }
    } else {
        debug_log("Not sending email to $email based on engagement criteria.");
    }

    if (!$do_not_update_as_sent) {
        debug_log("Updating last_sent for subscriber ID: " . $subscriber['id'] . " to $today");
        $update_stmt = $pdo->prepare("UPDATE subscribers SET last_sent = :today WHERE id = :id");
        $update_stmt->execute([':today' => $today, ':id' => $subscriber['id']]);
    } else {
        debug_log("Not updating last_sent due to send failure for subscriber ID: " . $subscriber['id']);
    }
}

debug_log("Script ended.");
