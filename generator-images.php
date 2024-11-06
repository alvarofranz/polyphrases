<?php
require __DIR__ . '/Polyphraser.php';

use App\Polyphraser;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dbConfig = [
    'host' => $_ENV['DB_HOST'],
    'dbname' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'pass' => $_ENV['DB_PASS']
];

$generator = new Polyphraser(
    $_ENV['OPENAI_API_KEY'],
    $dbConfig,
    25, // Number of examples
    $_ENV['ADMIN_EMAIL'],
    $_ENV['CURRENT_ENV']
);

// Fetch the phrase for which to generate the image
$date = $_GET['date'] ?? null;
$phrase = $generator->fetchPhraseForImage($date);

if ($phrase) {
    echo 'Generating image for: ' . $phrase['phrase'] . PHP_EOL;
    $imageUrl = $generator->generateImage($phrase['phrase']);
    echo 'Image URL: ' . $imageUrl . PHP_EOL;
    $generator->saveImage($imageUrl, $phrase['date']);
    $generator->updateImageStatus($phrase['id']);
    echo 'Image saved and status updated for phrase with id ' . $phrase['id'] . PHP_EOL;
}
