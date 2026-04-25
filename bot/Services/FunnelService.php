<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\TelegramApi;
use App\Services\TextService;
use App\Services\UserService;
use App\Services\OnboardingService;
use App\Services\ProdamusService;

class FunnelService
{
    private TelegramApi $telegram;
    private TextService $textService;
    private UserService $userService;

    public function __construct(TelegramApi $telegram)
    {
        $this->telegram = $telegram;
        $this->textService = new TextService();
        $this->userService = new UserService();
    }

    public function start(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_1');
        $this->sendStep1($chatId);
    }

    private function sendStep1(int $chatId): void
    {
        $text = $this->textService->get('msg_step_1_interest', '', true);
        if (empty($text)) {
            $this->advanceToStep2($chatId, 0); // fallback if skipped
            return;
        }

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_funnel_energy', 'Энергия / усталость', true), 'callback_data' => 'funnel_ans_step1_energy']],
            [['text' => $this->textService->get('btn_funnel_skin', 'Кожа / внешний вид', true), 'callback_data' => 'funnel_ans_step1_skin']],
            [['text' => $this->textService->get('btn_funnel_food', 'Питание / тело', true), 'callback_data' => 'funnel_ans_step1_food']],
            [['text' => $this->textService->get('btn_funnel_stress', 'Состояние (стресс, ПМС)', true), 'callback_data' => 'funnel_ans_step1_stress']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    public function handleInput(int $chatId, int $userId, string $state, string $input): void
    {
        if ($state === 'funnel_step_1') {
            $this->advanceToStep2($chatId, $userId);
        } elseif ($state === 'funnel_step_2') {
            $this->advanceToStep3($chatId, $userId);
        } elseif ($state === 'funnel_step_3') {
            $this->advanceToStep4($chatId, $userId);
        } elseif ($state === 'funnel_step_4') {
            $this->advanceToStep5($chatId, $userId);
        } elseif ($state === 'funnel_step_self') {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    public function handleCallback(int $chatId, int $userId, string $state, string $data): void
    {
        if ($state === 'funnel_step_1' && str_starts_with($data, 'funnel_ans_step1_')) {
            $answer = substr($data, 17); // energy, skin, food, stress
            $mapping = [
                'energy' => 'Интерес: Энергия / усталость',
                'skin'   => 'Интерес: Кожа / внешний вид',
                'food'   => 'Интерес: Питание / тело',
                'stress' => 'Интерес: Состояние (стресс, ПМС)',
            ];
            $fact = $mapping[$answer] ?? null;
            if ($fact) {
                (new ProfileService())->addFact($userId, $fact);
            }
            $this->advanceToStep2($chatId, $userId);
        } elseif ($state === 'funnel_step_5_offer') {
            if ($data === 'funnel_path_nastya') {
                $this->sendStepNastyaOffer($chatId, $userId);
                (new ProfileService())->addFact($userId, 'Выбран путь: прохождение с Настей');
            } elseif ($data === 'funnel_path_self') {
                (new ProfileService())->addFact($userId, 'Выбран путь: самостоятельное прохождение с ботом');
                $this->advanceToOnboarding($chatId, $userId);
            }
        } elseif ($state === 'funnel_step_nastya_offer' && $data === 'funnel_nastya_go') {
            $this->sendStepNastyaClose($chatId, $userId);
        } elseif ($state === 'funnel_step_nastya_close' && $data === 'funnel_nastya_pay') {
            $this->sendCoursePaymentLink($chatId, $userId);
        }
    }

    private function sendCoursePaymentLink(int $chatId, int $userId): void
    {
        $price = Config::getProdamusCoursePrice();
        $orderId = 'course_' . $userId . '_' . time();
        
        $prodamus = new ProdamusService();
        $payUrl = $prodamus->generatePaymentLink($userId, $orderId, (float)$price, 'Курс с Настей');
        
        $text = $this->textService->get('msg_course_payment_prompt', "Для оплаты курса с Настей перейдите по ссылке:", true);
        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => sprintf($this->textService->get('btn_pay_course', 'Оплатить курс — %d Р', true), $price), 'url' => $payUrl]]
        ]);
        
        $this->telegram->sendMessage($chatId, $text, $keyboard);
        
        // Also advance to onboarding as a fallback or next step
        $this->advanceToOnboarding($chatId, $userId);
    }

    private function advanceToStep2(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_2');
        $text = $this->textService->get('msg_step_2_context', '', true);
        if (!empty($text)) {
            $this->telegram->sendMessage($chatId, $text);
        }
        
        // Skip Step 3 and go directly to Step 4
        $this->advanceToStep4($chatId, $userId);
    }

    private function advanceToStep3(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_3');
        $text = $this->textService->get('msg_step_3_diagnostic', '', true);
        if (!empty($text)) {
            $this->telegram->sendMessage($chatId, $text);
        } else {
            $this->advanceToStep4($chatId, $userId);
        }
    }

    private function advanceToStep4(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_4');
        $text = $this->textService->get('msg_step_4_value', '', true);
        if (!empty($text)) {
            $this->telegram->sendMessage($chatId, $text);
        } else {
            $this->advanceToStep5($chatId, $userId);
        }
    }

    private function advanceToStep5(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_5_offer');
        $text = $this->textService->get('msg_step_5_soft_offer', '', true);
        if (!empty($text)) {
            $coursePrice = Config::getProdamusCoursePrice();
            $subPrice = Config::getProdamusSubscriptionPrice();
            
            $keyboard = TelegramApi::inlineKeyboard([
                [
                    ['text' => sprintf($this->textService->get('btn_funnel_nastya', 'С Настей (%d Р)', true), $coursePrice), 'callback_data' => 'funnel_path_nastya'], 
                    ['text' => sprintf($this->textService->get('btn_funnel_bot', 'С ботом (%d Р)', true), $subPrice), 'callback_data' => 'funnel_path_self']
                ]
            ]);
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        } else {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function sendStepSelfPath(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_self');
        $text = $this->textService->get('msg_step_self_path', '', true);
        if (!empty($text)) {
            $this->telegram->sendMessage($chatId, $text);
        } else {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function sendStepNastyaOffer(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_nastya_offer');
        $text = $this->textService->get('msg_step_6_offer_details', '', true);
        if (!empty($text)) {
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => $this->textService->get('btn_funnel_go', 'Иду!', true), 'callback_data' => 'funnel_nastya_go']]
            ]);
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        } else {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function sendStepNastyaClose(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_nastya_close');
        $text = $this->textService->get('msg_step_7_close', '', true);
        if (!empty($text)) {
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => $this->textService->get('btn_funnel_pay', 'Оплата', true), 'callback_data' => 'funnel_nastya_pay']]
            ]);
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        } else {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function advanceToOnboarding(int $chatId, int $userId): void
    {
        $onboarding = new OnboardingService($this->telegram);
        $onboarding->start($chatId, $userId);
    }
}
