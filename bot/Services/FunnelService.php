<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\TelegramApi;
use App\Services\TextService;
use App\Services\UserService;
use App\Services\OnboardingService;

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
        $text = $this->textService->get('msg_step_1_interest');
        if (empty($text)) {
            $this->advanceToStep2($chatId, 0); // fallback if skipped
            return;
        }

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => 'Энергия / усталость', 'callback_data' => 'funnel_ans_step1_energy']],
            [['text' => 'Кожа / внешний вид', 'callback_data' => 'funnel_ans_step1_skin']],
            [['text' => 'Питание / тело', 'callback_data' => 'funnel_ans_step1_food']],
            [['text' => 'Состояние (стресс, ПМС)', 'callback_data' => 'funnel_ans_step1_stress']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    public function handleInput(int $chatId, int $userId, string $state, string $input): void
    {
        if ($state === 'funnel_step_1') {
            $this->advanceToStep2($chatId, $userId);
        } elseif ($state === 'funnel_step_3') {
            $this->advanceToStep4($chatId, $userId);
        } elseif ($state === 'funnel_step_self') {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    public function handleCallback(int $chatId, int $userId, string $state, string $data): void
    {
        if ($state === 'funnel_step_1' && str_starts_with($data, 'funnel_ans_step1_')) {
            $this->advanceToStep2($chatId, $userId);
        } elseif ($state === 'funnel_step_5_offer') {
            if ($data === 'funnel_path_nastya') {
                $this->sendStepNastyaOffer($chatId, $userId);
            } elseif ($data === 'funnel_path_self') {
                $this->sendStepSelfPath($chatId, $userId);
            }
        } elseif ($state === 'funnel_step_nastya_offer' && $data === 'funnel_nastya_go') {
            $this->sendStepNastyaClose($chatId, $userId);
        } elseif ($state === 'funnel_step_nastya_close' && $data === 'funnel_nastya_pay') {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function advanceToStep2(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_3');
        $text2 = $this->textService->get('msg_step_2_context');
        if (!empty($text2)) {
            $this->telegram->sendMessage($chatId, $text2);
            sleep(1);
        }

        $text3 = $this->textService->get('msg_step_3_diagnostic');
        if (!empty($text3)) {
            $this->telegram->sendMessage($chatId, $text3);
        } else {
            // If skipped, move to step 4
            $this->advanceToStep4($chatId, $userId);
        }
    }

    private function advanceToStep4(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_5_offer');
        $text4 = $this->textService->get('msg_step_4_value');
        if (!empty($text4)) {
            $this->telegram->sendMessage($chatId, $text4);
            sleep(1);
        }

        $text5 = $this->textService->get('msg_step_5_soft_offer');
        if (!empty($text5)) {
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => 'С Настей', 'callback_data' => 'funnel_path_nastya'], ['text' => 'Сама', 'callback_data' => 'funnel_path_self']]
            ]);
            $this->telegram->sendMessage($chatId, $text5, $keyboard);
        } else {
            // Skipped, advance to onboarding
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function sendStepSelfPath(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_self');
        $text = $this->textService->get('msg_step_self_path');
        if (!empty($text)) {
            $this->telegram->sendMessage($chatId, $text);
        } else {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function sendStepNastyaOffer(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_nastya_offer');
        $text = $this->textService->get('msg_step_6_offer_details');
        if (!empty($text)) {
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => 'Иду!', 'callback_data' => 'funnel_nastya_go']]
            ]);
            $this->telegram->sendMessage($chatId, $text, $keyboard);
        } else {
            $this->advanceToOnboarding($chatId, $userId);
        }
    }

    private function sendStepNastyaClose(int $chatId, int $userId): void
    {
        $this->userService->updateState($userId, 'funnel_step_nastya_close');
        $text = $this->textService->get('msg_step_7_close');
        if (!empty($text)) {
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => 'Оплата', 'callback_data' => 'funnel_nastya_pay']]
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
