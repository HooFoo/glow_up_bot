<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Calculates the current menstrual cycle phase based on the last period start date.
 *
 * Standard 28-day cycle model:
 *   Day  1–5  → Менструация (menstruation)
 *   Day  6–13 → Фолликулярная фаза (follicular)
 *   Day 14–16 → Овуляция (ovulation)
 *   Day 17–28 → Лютеиновая / ПМС (luteal)
 */
class CycleService
{
    private const CYCLE_LENGTH = 28;

    /**
     * Phases configuration: [start_day, end_day, key, label, emoji]
     */
    private const PHASES = [
        [1,  5,  'menstruation', 'Менструация',        '🔴'],
        [6,  13, 'follicular',   'Фолликулярная фаза', '🟢'],
        [14, 16, 'ovulation',    'Овуляция',            '🟡'],
        [17, 28, 'luteal',       'Лютеиновая / ПМС',   '🟠'],
    ];

    /**
     * Calculate the current cycle phase from the last period start date.
     *
     * @param string $lastPeriodDate  Date string (Y-m-d or any strtotime-parseable format)
     * @return array{day: int, key: string, label: string, emoji: string}|null
     */
    public static function calculatePhase(string $lastPeriodDate): ?array
    {
        $start = strtotime($lastPeriodDate);
        if ($start === false) {
            return null;
        }

        $today = strtotime('today');
        $daysSinceStart = (int) floor(($today - $start) / 86400);

        if ($daysSinceStart < 0) {
            return null;
        }

        // Normalize to a position within the cycle (1-based)
        $cycleDay = ($daysSinceStart % self::CYCLE_LENGTH) + 1;

        foreach (self::PHASES as [$startDay, $endDay, $key, $label, $emoji]) {
            if ($cycleDay >= $startDay && $cycleDay <= $endDay) {
                return [
                    'day'   => $cycleDay,
                    'key'   => $key,
                    'label' => $label,
                    'emoji' => $emoji,
                ];
            }
        }

        return null;
    }

    /**
     * Get a human-readable string for the current phase.
     */
    public static function getPhaseString(string $lastPeriodDate): string
    {
        $phase = self::calculatePhase($lastPeriodDate);
        if (!$phase) {
            return 'Не определена';
        }

        return "{$phase['emoji']} {$phase['label']} (день {$phase['day']} цикла)";
    }

    /**
     * Get detailed cycle info for AI prompt context.
     */
    public static function getCycleContext(string $lastPeriodDate): array
    {
        $phase = self::calculatePhase($lastPeriodDate);
        if (!$phase) {
            return [
                'last_period_date' => $lastPeriodDate,
                'cycle_phase'      => 'unknown',
                'cycle_day'        => null,
            ];
        }

        return [
            'last_period_date' => $lastPeriodDate,
            'cycle_day'        => $phase['day'],
            'cycle_phase'      => $phase['key'],
            'cycle_phase_label' => $phase['label'],
        ];
    }
}
