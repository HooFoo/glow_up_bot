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
use App\Services\FunnelService;

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

    // ─── 4.5. Send typing indicator immediately ─────────────────
    $telegram->sendChatAction($chatId, 'typing');

    // ─── Find or create user ────────────────────────────────────
    $user = $userService->findOrCreate($from);
    $userId = (int) $user['id'];
    $state = $user['state'] ?? 'new';

    // ─── Legal terms check ───────────────────────────────────────
    if (!$userService->hasAcceptedTerms($userId)) {
        if (!($callback && $data === 'accept_terms')) {
            sendLegalTerms($chatId, $telegram);
            exit;
        }
    }

    // ─── Handle successful payment ──────────────────────────────
    if ($message && isset($message['successful_payment'])) {
        $subService = new SubscriptionService($telegram);
        $subService->handlePayment($userId, $message['successful_payment']);

        $textService = new \App\Services\TextService();
        $telegram->sendMessage($chatId, $textService->get('msg_subscription_activated', "🎉 Подписка активирована! Добро пожаловать в Prime Glow ✨", true));

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

    // User in warm-up funnel
    if (str_starts_with($state, 'funnel_')) {
        $funnel = new FunnelService($telegram);
        $funnel->handleInput($chatId, $userId, $state, $text);
        exit;
    }

    // User in onboarding text-input step?
    if (str_starts_with($state, 'onboard_') && in_array($state, ['onboard_body_stats', 'onboard_last_period_date'])) {
        $onboarding = new OnboardingService($telegram);
        $onboarding->handleTextAnswer($chatId, $userId, $text);
        exit;
    }

    // Quiz not done → prompt to start
    if (empty($user['quiz_completed_at'])) {
        $textService = new \App\Services\TextService();
        $telegram->sendMessage($chatId, $textService->get('msg_quiz_prompt', 'Давай сначала пройдём короткий тест ✨ Нажми /start', true));
        exit;
    }
    // Onboarding not done → prompt
    if (empty($user['onboarding_completed_at'])) {
        $textService = new \App\Services\TextService();
        $telegram->sendMessage($chatId, $textService->get('msg_onboarding_prompt', 'Осталось пару вопросов для настройки профиля 🙏', true));
        exit;
    }

    // ─── Access check (paywall) ─────────────────────────────────
    $subService = new SubscriptionService($telegram);
    if (!$subService->checkAccess($user)) {
        $subService->sendPaywall($chatId, $userId);
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

    $textService = new \App\Services\TextService();

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
            $telegram->sendMessage($chatId, $textService->get('msg_mode_nutrition_header', '🥗 Режим: *Консультант по питанию*. Задавай вопросы!', true), null, 'Markdown');
            break;

        case '/cosmetics':
            $userService = new UserService();
            $userService->setActiveMode($userId, 'cosmetics');
            $telegram->sendMessage($chatId, $textService->get('msg_mode_cosmetics_header', '✨ Режим: *Эксперт по косметике*. Присылай фото составов или задавай вопросы!', true), null, 'Markdown');
            break;

        case '/assistant':
            $userService = new UserService();
            $userService->setActiveMode($userId, 'beauty_assistant');
            $telegram->sendMessage($chatId, $textService->get('msg_mode_beauty_assistant_header', '🤖 Режим: *Beauty-ассистент*. Задавай вопросы!', true), null, 'Markdown');
            break;

        case '/practices':
            $userService = new UserService();
            $userService->setActiveMode($userId, 'practices');
            $telegram->sendMessage($chatId, $textService->get('msg_mode_practices_header', '🧘‍♀️ Режим: *Практики*.', true), null, 'Markdown');
            break;

        case '/subscribe':
            $subService = new SubscriptionService($telegram);
            $subService->sendPaywall($chatId, $userId);
            break;

        case '/profile':
            $profileService = new \App\Services\ProfileService();
            $profile = $profileService->getProfile($userId);
            if ($profile) {
                $persona = PersonaService::getPersonaEmoji($profile['persona'] ?? '') . ' ' . PersonaService::getPersonaLabel($profile['persona'] ?? '');
                $text  = $textService->get('msg_profile_header', "👤 *Твой профиль*", true) . "\n\n";
                $text .= $textService->get('msg_profile_archetype', "Архетип: ", true) . "{$persona}\n";
                $text .= $textService->get('msg_profile_goal', "Цель: ", true) . ($profile['goal'] ?? $textService->get('msg_profile_cycle_empty', '—', true)) . "\n";
                $text .= $textService->get('msg_profile_weight_height', "Вес/Рост: ", true) . (($profile['weight_kg'] ?? '?') . " / " . ($profile['height_cm'] ?? '?')) . "\n";
                $text .= $textService->get('msg_profile_bmi', "BMI: ", true) . ($profile['bmi'] ?? $textService->get('msg_profile_cycle_empty', '—', true)) . "\n";

                // Cycle phase (dynamically calculated)
                if (!empty($profile['cycle_phase'])) {
                    $cycleDay = $profile['cycle_day'] ?? null;
                    $cycleText = $profile['cycle_phase'];
                    if ($cycleDay) {
                        $cycleText .= sprintf($textService->get('msg_profile_cycle_day', " (день %d)", true), $cycleDay);
                    }
                    $text .= $textService->get('msg_profile_cycle', "Цикл: ", true) . "{$cycleText}\n";
                } else {
                    $text .= $textService->get('msg_profile_cycle', "Цикл: ", true) . $textService->get('msg_profile_cycle_empty', "—", true) . "\n";
                }

                if (!empty($profile['last_period_date']) && $profile['last_period_date'] !== 'no_cycle') {
                    $text .= $textService->get('msg_profile_last_period', "Дата последних месячных: ", true) . $profile['last_period_date'] . "\n";
                }

                $keyboard = TelegramApi::inlineKeyboard([
                    [['text' => $textService->get('btn_edit_profile', '✏️ Редактировать профиль', true), 'callback_data' => 'edit_profile']],
                    [['text' => $textService->get('btn_back_to_menu', '⬅️ Назад в меню', true), 'callback_data' => 'back_to_menu']],
                ]);
                $telegram->sendMessage($chatId, $text, $keyboard);
            } else {
                $telegram->sendMessage($chatId, $textService->get('msg_profile_not_filled', 'Профиль ещё не заполнен. Пройди онбординг: /start', true));
            }
            break;

        default:
            $telegram->sendMessage($chatId, $textService->get('msg_unknown_command', 'Неизвестная команда. Используй /menu для навигации.', true));
    }
}

function handleCallback(int $chatId, int $userId, string $data, array $user, TelegramApi $telegram): void
{
    // Legal terms acceptance
    if ($data === 'accept_terms') {
        $userService = new UserService();
        $userService->acceptTerms($userId);
        sendWelcome($chatId, $telegram);
        return;
    }

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

    // Funnel answers & Trial buttons
    if (str_starts_with($data, 'funnel_') || str_starts_with($data, 'trial_')) {
        $funnel = new FunnelService($telegram);
        
        // Map trial buttons to funnel paths
        if ($data === 'trial_nastya') {
            $funnel->handleCallback($chatId, $userId, 'funnel_step_5_offer', 'funnel_path_nastya');
            return;
        }
        if ($data === 'trial_bot') {
            $funnel->handleCallback($chatId, $userId, 'funnel_step_5_offer', 'funnel_path_self');
            return;
        }
        
        if ($data === 'trial_pdf') {
            $personaService = new PersonaService($telegram);
            $personaService->sendGift($chatId, $user['persona'] ?? 'sleeping_muse');
            return;
        }
        
        if ($data === 'trial_demo') {
            $userService = new UserService();
            $userService->updateState($userId, 'demo_prompt');
            
            $textService = new \App\Services\TextService();
            $demoText = $textService->get('msg_trial_demo_prompt_info', "Вот твой демо-промпт для самостоятельной работы ✨", true);
            $telegram->sendMessage($chatId, $demoText);
            return;
        }

        $funnel->handleCallback($chatId, $userId, $user['state'] ?? '', $data);
        return;
    }

    // Start quiz
    if ($data === 'start_quiz') {
        $quiz = new QuizService($telegram);
        $quiz->start($chatId, $userId);
        return;
    }

    // Start funnel (triggered by CTA after quiz result)
    if ($data === 'start_funnel') {
        $funnel = new FunnelService($telegram);
        $funnel->start($chatId, $userId);
        return;
    }

    // Start quiz intro
    if ($data === 'start_quiz_intro') {
        $textService = new \App\Services\TextService();
        $text = $textService->get('msg_quiz_intro_text', "Прежде чем мы перейдем к цифрам и составам, давай поймем, в какой точке ты сейчас.\n\nТвой путь к сиянию зависит от того, какая энергия в тебе доминирует: нужно ли тебе восстановление, структура или масштаб.\n\nОтветь на 7 коротких вопросов. В конце я не только назову твой Glow-архетип, но и вышлю персональный 🎁 ПРИЗ, который поможет тебе сделать первый шаг уже сегодня.", true);

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $textService->get('btn_quiz_intro_start', '🚀 ПОЕХАЛИ!', true), 'callback_data' => 'start_quiz']],
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
        $subService->sendPaywall($chatId, $userId);
        return;
    }

    // Mode switching
    if (str_starts_with($data, 'mode_')) {
        $mode = str_replace('mode_', '', $data);
        
        // Handle practices separately (upsell check)
        if ($mode === 'practices') {
            $subService = new SubscriptionService($telegram);
            if (!$subService->checkAccess($user) || empty($user['subscription_end'])) {
                // Not a subscriber? Show upsell message instead of switching
                $textService = new \App\Services\TextService();
                $upsellText = $textService->get('msg_mode_practices_locked', "Этот раздел доступен только в расширенной версии Prime ✨\n\nХочешь подключиться и получить доступ к эксклюзивным практикам?", true);
                $keyboard = TelegramApi::inlineKeyboard([
                    [['text' => $textService->get('btn_upsell_practices', '✨ Подключить Prime', true), 'callback_data' => 'buy_subscription']],
                    [['text' => $textService->get('btn_back_to_menu', '⬅️ Назад в меню', true), 'callback_data' => 'back_to_menu']],
                ]);
                $telegram->sendMessage($chatId, $upsellText, $keyboard);
                return;
            }
        }

        $validModes = ['nutrition', 'cosmetics', 'beauty_assistant', 'practices'];
        if (in_array($mode, $validModes)) {
            $userService = new UserService();
            $userService->setActiveMode($userId, $mode);

            $textService = new \App\Services\TextService();

            if ($mode === 'beauty_assistant') {
                $chatService = new ChatService($telegram);
                $chatService->handleMessage($chatId, $userId, "Собери мой прайм день");
            } else {
                $labels = [
                    'nutrition'        => $textService->get('btn_mode_nutrition', '🥗 Питание', true),
                    'cosmetics'        => $textService->get('btn_mode_cosmetics', '✨ Твой ИИ косметолог', true),
                    'beauty_assistant' => $textService->get('btn_mode_beauty_assistant', '🤖 Beauty-ассистент', true),
                    'practices'        => $textService->get('btn_mode_practices', '🧘‍♀️ Практики', true),
                ];
                $msgKey = "msg_mode_{$mode}_switched";
                $defaultMsg = "Режим переключён на: *%s*\n\n Чем я могу тебе помочь? Спроси что угодно и я отвечу!";
                $msg = sprintf($textService->get($msgKey, $defaultMsg, true), $labels[$mode]);
                $telegram->sendMessage($chatId, $msg);
            }
        }
        return;
    }

    // Show profile
    if ($data === 'show_profile') {
        handleCommand($chatId, $userId, '/profile', $user, $telegram);
        return;
    }

    // Edit profile → restart onboarding
    if ($data === 'edit_profile') {
        $onboarding = new OnboardingService($telegram);
        $onboarding->resetAndRestart($chatId, $userId);
        return;
    }

    // Back to main menu
    if ($data === 'back_to_menu') {
        sendMainMenu($chatId, $user, $telegram);
        return;
    }
}

function sendLegalTerms(int $chatId, TelegramApi $telegram): void
{
    $textService = new \App\Services\TextService();
    $text = $textService->get('msg_legal_terms', '', true);

    if (!$text) {
        $text = "Нажимая на кнопку и отвечая на сообщения в чат-боте, вы принимаете условия [оферты](https://admin.anketa.prodamus.ru/files/download/305056/944f19f129c825a682d68629b9986d4b) и выражаете [согласие](https://disk.yandex.ru/i/Ib2B2c7-SCeT9A) на обработку персональных данных согласно [Политике конфиденциальности](https://disk.yandex.ru/i/RbKm7-MsDOjpLQ), а также даете [согласие](https://disk.yandex.ru/i/BGrS3ie1gWj7kQ) на получение рекламных рассылок\n\nP.S. Здесь ты будешь получать только полезную информацию, без спама и воды.";
    }

    $keyboard = TelegramApi::inlineKeyboard([
        [['text' => $textService->get('btn_accept_terms', 'Принимаю', true), 'callback_data' => 'accept_terms']],
    ]);

    // Use legacy 'Markdown' for this message to support links without strict V2 escaping
    $telegram->sendMessage($chatId, $text, $keyboard, 'Markdown');
}

function sendWelcome(int $chatId, TelegramApi $telegram): void
{
    $textService = new \App\Services\TextService();
    $text = $textService->get('msg_start_welcome', '', true);

    if (!$text) {
        $text = "*Привет 🤍*\nЯ — твой *Prime Beauty AI-ассистент*.\n\n";
        $text .= "Я помогу тебе собрать систему: питание, цикл, антистресс, ритуалы, уход — без хаоса и насилия над собой.\n\n";
        $text .= "Важно: я не врач и не заменяю медицинские рекомендации. Я — инструмент осознанности и структуры.\n\n";
        $text .= "Готова настроить свой Prime-профиль?";
    }

    $keyboard = TelegramApi::inlineKeyboard([
        [['text' => $textService->get('btn_start_onboarding', '✨ НАЧАТЬ НАСТРОЙКУ', true), 'callback_data' => 'start_quiz_intro']],
    ]);

    $telegram->sendMessage($chatId, $text, $keyboard, 'Markdown');
}

function sendMainMenu(int $chatId, array $user, TelegramApi $telegram): void
{
    $textService = new \App\Services\TextService();
    $name = $user['first_name'] ?? 'Подруга';
    $text = sprintf($textService->get('msg_main_menu_text', "Привет, %s! ✨\n\nВыбери, с чего начнём сегодня:", true), $name);

    $keyboard = TelegramApi::inlineKeyboard([
        [['text' => $textService->get('btn_mode_nutrition', '🥗 Питание', true), 'callback_data' => 'mode_nutrition']],
        [['text' => $textService->get('btn_mode_cosmetics', '✨ Твой ИИ косметолог', true), 'callback_data' => 'mode_cosmetics']],
        [['text' => $textService->get('btn_mode_beauty_assistant', '🤖 Beauty-ассистент', true), 'callback_data' => 'mode_beauty_assistant']],
        [['text' => $textService->get('btn_mode_practices', '🧘‍♀️ Практики (доступ в Prime)', true), 'callback_data' => 'mode_practices']],
        [['text' => $textService->get('btn_mode_profile', '👤 Мой профиль', true), 'callback_data' => 'show_profile']],
    ]);

    $telegram->sendMessage($chatId, $text, $keyboard, 'Markdown');
}
