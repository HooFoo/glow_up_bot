<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class UserService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find or create user from Telegram update data.
     */
    public function findOrCreate(array $from): array
    {
        $telegramId = $from['id'];
        $user = $this->findByTelegramId($telegramId);

        if ($user) {
            // Update name/username on each interaction
            $this->db->execute(
                'UPDATE users SET username = :username, first_name = :first_name, last_name = :last_name, updated_at = NOW() WHERE telegram_id = :tid',
                [
                    ':username'   => $from['username'] ?? null,
                    ':first_name' => $from['first_name'] ?? 'User',
                    ':last_name'  => $from['last_name'] ?? null,
                    ':tid'        => $telegramId,
                ]
            );
            return $this->findByTelegramId($telegramId);
        }

        $this->db->insert(
            'INSERT INTO users (telegram_id, username, first_name, last_name, language_code, state) VALUES (:tid, :username, :first_name, :last_name, :lang, :state)',
            [
                ':tid'        => $telegramId,
                ':username'   => $from['username'] ?? null,
                ':first_name' => $from['first_name'] ?? 'User',
                ':last_name'  => $from['last_name'] ?? null,
                ':lang'       => $from['language_code'] ?? null,
                ':state'      => 'new',
            ]
        );

        return $this->findByTelegramId($telegramId);
    }

    public function findByTelegramId(int $telegramId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE telegram_id = :tid', [':tid' => $telegramId]);
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    }

    public function updateState(int $userId, string $state): void
    {
        $this->db->execute('UPDATE users SET state = :state WHERE id = :id', [':state' => $state, ':id' => $userId]);
    }

    public function setPersona(int $userId, string $persona): void
    {
        $this->db->execute('UPDATE users SET persona = :persona WHERE id = :id', [':persona' => $persona, ':id' => $userId]);
    }

    public function setActiveMode(int $userId, string $mode): void
    {
        $this->db->execute('UPDATE users SET active_mode = :mode WHERE id = :id', [':mode' => $mode, ':id' => $userId]);
    }

    public function completeQuiz(int $userId): void
    {
        $this->db->execute('UPDATE users SET quiz_completed_at = NOW(), state = :state WHERE id = :id', [':state' => 'quiz_done', ':id' => $userId]);
    }

    public function completeOnboarding(int $userId): void
    {
        $this->db->execute('UPDATE users SET onboarding_completed_at = NOW(), state = :state WHERE id = :id', [':state' => 'active', ':id' => $userId]);
    }

    public function resetOnboarding(int $userId): void
    {
        $this->db->execute('UPDATE users SET onboarding_completed_at = NULL, state = :state WHERE id = :id', [':state' => 'quiz_done', ':id' => $userId]);
    }

    public function incrementMessageCount(int $userId): int
    {
        $this->db->execute('UPDATE users SET message_count = message_count + 1 WHERE id = :id', [':id' => $userId]);
        $user = $this->findById($userId);
        return (int) ($user['message_count'] ?? 0);
    }

    public function setSubscriptionEnd(int $userId, string $datetime): void
    {
        $this->db->execute('UPDATE users SET subscription_end = :sub_end WHERE id = :id', [':sub_end' => $datetime, ':id' => $userId]);
    }

    // ─── Admin queries ───────────────────────────────────────────

    public function getListForAdmin(int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        return $this->db->fetchAll(
            'SELECT u.*, (u.subscription_end > NOW()) AS has_active_sub FROM users u ORDER BY has_active_sub DESC, u.created_at DESC LIMIT :limit OFFSET :offset',
            [':limit' => $perPage, ':offset' => $offset]
        );
    }

    public function getTotalCount(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM users');
        return (int) ($row['cnt'] ?? 0);
    }

    public function getActiveSubscriptionsCount(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM users WHERE subscription_end > NOW()');
        return (int) ($row['cnt'] ?? 0);
    }

    public function getNewUsersPerDay(int $days = 10): array
    {
        return $this->db->fetchAll(
            'SELECT DATE(created_at) AS day, COUNT(*) AS count FROM users WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) GROUP BY DATE(created_at) ORDER BY day ASC',
            [':days' => $days]
        );
    }
}
