<?php
require_once 'vendor/autoload.php';

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$botToken = $_ENV['BOT_TOKEN'];
$dsn = $_ENV['DB_DSN'];
$dbUser = $_ENV['DB_USER'];
$dbPassword = $_ENV['DB_PASSWORD'];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

$bot = new BotApi($botToken);

function handleMessage($message, $chatId, $pdo, $bot)
{
    $text = trim($message);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = :chat_id");
    $stmt->execute(['chat_id' => $chatId]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (chat_id, balance) VALUES (:chat_id, :balance)");
        $stmt->execute(['chat_id' => $chatId, 'balance' => 0.00]);
        $bot->sendMessage($chatId, "Привет! Ваш аккаунт создан с балансом $0.00.");
        return;
    }

    if (preg_match('/^-?[0-9]+([.,][0-9]+)?$/', $text)) {
        $amount = (float)str_replace(',', '.', $text);
        $newBalance = $user['balance'] + $amount;

        if ($newBalance < 0) {
            $bot->sendMessage($chatId, "Недостаточно средств. Ваш текущий баланс: $" . number_format($user['balance'], 2));
            return;
        }

        // Обновляем баланс
        $stmt = $pdo->prepare("UPDATE users SET balance = :balance WHERE chat_id = :chat_id");
        $stmt->execute(['balance' => $newBalance, 'chat_id' => $chatId]);
        $bot->sendMessage($chatId, "Ваш новый баланс: $" . number_format($newBalance, 2));
    } else {
        $bot->sendMessage($chatId, "Пожалуйста, отправьте число для обновления баланса.");
    }
}

$update = Update::fromResponse(json_decode(file_get_contents('php://input'), true));
$message = $update->getMessage();

if ($message) {
    $chatId = $message->getChat()->getId();
    $text = $message->getText();

    handleMessage($text, $chatId, $pdo, $bot);
}
