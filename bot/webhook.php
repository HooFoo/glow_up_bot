<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Logger;
use App\Core\TelegramApi;
use App\Services\UserService;
use App\Services\QuizService;
use App\Services\OnboardingService;
use App\Services\PersonaService;
use App\Services\ChatService;
use App\Services\SubscriptionService;

// ─── 1. Read incoming update ─────────────────────────────────────
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    http_response_code(200);
    exit;
}

// ─── 2. Verify webhook secret ────────────────────────────────────
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (Config::getTelegramWebhookSecret() && $secret !== Config::getTelegramWebhookSecret()) {
    http_response_code(403);
    exit;
}

// ─── 3. Fast HTTP response (Non-blocking) ────────────────────────
http_response_code(200);
header('Content-Length: 0');
header('Connection: close');
if (ob_get_level() > 0) {
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);

// ─── 4. Process update (background) ─────────────────────────────
$logger = Logger::getInstance();
$logger->debug('Webhook received', $update);

try {
    $telegram = new TelegramApi();
    $userService = new UserService();

    // ─── Pre-checkout query (payments) ───────────────────────────
    if (isset($update['pre_checkout_query'])) {
        $telegram->answerPreCheckoutQuery($update['pre_checkout_query']['id'], true);
        exit;
    }

    // ─── Determine chat/user from the update ─────────────────────
    $message = $update['message'] ?? null;
    $callback = $update['callback_query'] ?? null;

    if ($callback) {
        $from = $callback['from'];
        $chatId = $callback['message']['chat']['id'];
        $data = $callback['data'] ?? '';
        $telegram->answerCallbackQuery($callback['id']);
    } elseif ($message) {
        $from = $message['from'];
        $chatId = $message['chat']['id'];
    } else {
        exit;
    }

    // ─── Find or create user ────────────────────────────────────
    $user = $userService->findOrCreate($from);
    $userId = (int) $user['id'];
    $state = $user['state'] ?? 'new';

    // ─── Handle successful payment ──────────────────────────────
    if ($message && isset($message['successful_payment'])) {
        $subService = new SubscriptionService($telegram);
        $subService->handlePayment($userId, $message['successful_payment']);

        $telegram->sendMessage($chatId, "🎉 Подписка активирована! Добро пожаловать в Prime Glow ✨");

        // If onboarding not done yet, start it
        if (empty($user['onboarding_completed_at'])) {
            $onboarding = new OnboardingService($telegram);
            $onboarding->start($chatId, $userId);
        }
        exit;
    }

    // ─── Callback query handling ────────────────────────────────
    if ($callback) {
        handleCallback($chatId, $userId, $data, $user, $telegram);
        exit;
    }

    // ─── Command handling ───────────────────────────────────────
    $text = $message['text'] ?? '';

    if (str_starts_with($text, '/')) {
        handleCommand($chatId, $userId, $text, $user, $telegram);
        exit;
    }

    // ─── State-based routing ────────────────────────────────────

    // User in onboarding text-input step?
    if (str_starts_with($state, 'onboard_') && $state === 'onboard_body_stats') {
        $onboarding = new OnboardingService($telegram);
        $onboarding->handleTextAnswer($chatId, $userId, $text);
        exit;
    }

    // Quiz not done → prompt to start
    if (empty($user['quiz_completed_at'])) {
        $telegram->sendMessage($chatId, 'Давай сначала пройдём короткий тест ✨ Нажми /start');
        exit;
    }

    // Onboarding not done → prompt
    if (empty($user['onboarding_completed_at'])) {
        $telegram->sendMessage($chatId, 'Осталось пару вопросов для настройки профиля 🙏');
        exit;
    }

    // ─── Access check (paywall) ─────────────────────────────────
    $subService = new SubscriptionService($telegram);
    if (!$subService->checkAccess($user)) {
        $subService->sendPaywall($chatId);
        exit;
    }

    // ─── Main chat flow ─────────────────────────────────────────
    $chatService = new ChatService($telegram);

    if (isset($message['voice'])) {
        $chatService->handleVoice($chatId, $userId, $message['voice']['file_id']);
    } elseif (isset($message['photo'])) {
        $photos = $message['photo'];
        $largestPhoto = end($photos);
        $caption = $message['caption'] ?? null;
        $chatService->handlePhoto($chatId, $userId, $largestPhoto['file_id'], $caption);
    } else {
        $chatService->handleMessage($chatId, $userId, $text);
    }

} catch (\Throwable $e) {
    $logger->error('Webhook error', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
}

// ─── Helper functions ────────────────────────────────────────────

function handleCommand(int $chatId, int $userId, string $text, array $user, TelegramApi $telegram): void
{
    $command = strtolower(trim(explode(' ', $text)[0]));

    switch ($command) {
        case '/start':
            if (!empty($user['quiz_completed_at']) && !empty($user['onboarding_completed_at'])) {
                // Already onboarded → show menu
                sendMainMenu($chatId, $user, $telegram);
            } elseif (!empty($user['quiz_completed_at'])) {
                // Quiz done but onboarding not
                $onboarding = new OnboardingService($telegram);
                $onboarding->start($chatId, $userId);
            } else {
                // New user → welcome + quiz
                sendWelcome($chatId, $telegram);
            }
            break;

        case '/menu':
            sendMainMenu($chatId, $user, $telegram);
            break;

        case '/nutrition':
            $userService = new UserService();
            $userService->setActiveMode($userId, 'nutrition');
            $telegram->sendMessage($chatId, '🥗 Режим: <b>Консультант по питанию</b>. Задавай вопросы!');
            break;

        case '/cosmetics':
            $userService = new UserService();
            $userService->setActiveMode($userId, 'cosmetics');
            $telegram->sendMessage($chatId, '✨ Режим: <b>Эксперт по косметике</b>. Присылай фото составов или задавай вопросы!');
            break;

        case '/coach':
            $userService = new UserService();
            $userService->setActiveMode($userId, 'coach');
            $telegram->sendMessage($chatId, '🧘 Режим: <b>Коуч</b>. Расскажи, как ты себя чувствуешь.');
            break;

        case '/subscribe':
            $subService = new SubscriptionService($telegram);
            $subService->sendInvoice($chatId, $userId);
            break;

        case '/profile':
            $profileService = new \App\Services\ProfileService();
            $profile = $profileService->getProfile($userId);
            if ($profile) {
                $persona = PersonaService::getPersonaEmoji($profile['persona'] ?? '') . ' ' . PersonaService::getPersonaLabel($profile['persona'] ?? '');
                $text  = "👤 <b>Твой профиль</b>\n\n";
                $text .= "Архетип: {$persona}\n";
                $text .= "Цель: " . ($profile['goal'] ?? '—') . "\n";
                $text .= "Вес/Рост: " . ($profile['weight_kg'] ?? '?') . " / " . ($profile['height_cm'] ?? '?') . "\n";
                $text .= "BMI: " . ($profile['bmi'] ?? '—') . "\n";
                $text .= "Цикл: " . ($profile['cycle_phase'] ?? '—') . "\n";
                $telegram->sendMessage($chatId, $text);
            } else {
                $telegram->sendMessage($chatId, 'Профиль ещё не заполнен. Пройди онбординг: /start');
            }
            break;

        default:
            $telegram->sendMessage($chatId, 'Неизвестная команда. Используй /menu для навигации.');
    }
}

function handleCallback(int $chatId, int $userId, string $data, array $user, TelegramApi $telegram): void
{
    // Quiz answers
    if (str_starts_with($data, 'quiz_')) {
        $quiz = new QuizService($telegram);
        $quiz->handleAnswer($chatId, $userId, $data);
        return;
    }

    // Onboarding answers
    if (str_starts_with($data, 'onboard_')) {
        $onboarding = new OnboardingService($telegram);
        $onboarding->handleAnswer($chatId, $userId, $data);
        return;
    }

    // Start quiz
    if ($data === 'start_quiz') {
        $quiz = new QuizService($telegram);
        $quiz->start($chatId, $userId);
        return;
    }

    // Start quiz intro
    if ($data === 'start_quiz_intro') {
        $text = "Прежде чем мы перейдем к цифрам и составам, давай поймем, в какой точке ты сейчас.\n\n";
        $text .= "Твой путь к сиянию зависит от того, какая энергия в тебе доминирует: нужно ли тебе восстановление, структура или масштаб.\n\n";
        $text .= "Ответь на 7 коротких вопросов. В конце я не только назову твой Glow-архетип, но и вышлю персональный 🎁 ПРИЗ, который поможет тебе сделать первый шаг уже сегодня.";

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => '🚀 ПОЕХАЛИ!', 'callback_data' => 'start_quiz']],
        ]);

        $telegram->sendMessage($chatId, $text, $keyboard);
        return;
    }

    // Get access (after quiz CTA)
    if ($data === 'get_access') {
        // Start onboarding (free trial activates with quiz completion)
        $onboarding = new OnboardingService($telegram);
        $onboarding->start($chatId, $userId);
        return;
    }

    // Buy subscription
    if ($data === 'buy_subscription') {
        $subService = new SubscriptionService($telegram);
        $subService->sendInvoice($chatId, $userId);
        return;
    }

    // Mode switching
    if (str_starts_with($data, 'mode_')) {
        $mode = str_replace('mode_', '', $data);
        $validModes = ['nutrition', 'cosmetics', 'coach'];
        if (in_array($mode, $validModes)) {
            $userService = new UserService();
            $userService->setActiveMode($userId, $mode);

            $labels = ['nutrition' => '🥗 Питание', 'cosmetics' => '✨ Косметика', 'coach' => '🧘 Коучинг'];
            $telegram->sendMessage($chatId, "Режим переключён на: <b>{$labels[$mode]}</b>\n\nЗадавай вопросы!");
        }
        return;
    }

    // Show profile
    if ($data === 'show_profile') {
        handleCommand($chatId, $userId, '/profile', $user, $telegram);
        return;
    }
}

function sendWelcome(int $chatId, TelegramApi $telegram): void
{
    $root = Config::getProjectRoot();
    $messagesFile = file_get_contents($root . '/bot/Texts/messages.md');

    // Extract START_WELCOME block
    $text = "*Привет 🤍*\nЯ — твой *Prime Beauty AI-ассистент*.\n\n";
    $text .= "Я помогу тебе собрать систему: питание, цикл, антистресс, ритуалы, уход — без хаоса и насилия над собой.\n\n";
    $text .= "Важно: я не врач и не заменяю медицинские рекомендации. Я — инструмент осознанности и структуры.\n\n";
    $text .= "Готова настроить свой Prime-профиль?";

    $keyboard = TelegramApi::inlineKeyboard([
        [['text' => '✨ НАЧАТЬ НАСТРОЙКУ', 'callback_data' => 'start_quiz_intro']],
    ]);

    $telegram->sendMessage($chatId, $text, $keyboard);
}

function sendMainMenu(int $chatId, array $user, TelegramApi $telegram): void
{
    $name = $user['first_name'] ?? 'Подруга';
    $text = "Привет, {$name}! ✨\n\nВыбери, с чего начнём сегодня:";

    $keyboard = TelegramApi::inlineKeyboard([
        [['text' => '🥗 Питание', 'callback_data' => 'mode_nutrition']],
        [['text' => '✨ Косметика', 'callback_data' => 'mode_cosmetics']],
        [['text' => '🧘 Коучинг', 'callback_data' => 'mode_coach']],
        [['text' => '👤 Мой профиль', 'callback_data' => 'show_profile']],
    ]);

    $telegram->sendMessage($chatId, $text, $keyboard);
}
