<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class BroadcastService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get user count based on filters.
     */
    public function countUsers(array $filters): int
    {
        $sql = "SELECT COUNT(DISTINCT u.id) as cnt FROM users u LEFT JOIN user_profiles up ON u.id = up.user_id WHERE 1=1";
        $params = [];

        $this->applyFilters($sql, $params, $filters);

        $row = $this->db->fetchOne($sql, $params);
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Get user IDs based on filters.
     */
    public function getUserIds(array $filters): array
    {
        $sql = "SELECT DISTINCT u.id FROM users u LEFT JOIN user_profiles up ON u.id = up.user_id WHERE 1=1";
        $params = [];

        $this->applyFilters($sql, $params, $filters);

        $rows = $this->db->fetchAll($sql, $params);
        return array_column($rows, 'id');
    }

    /**
     * Create a new broadcast task.
     */
    public function createBroadcast(string $message, array $filters): int
    {
        $totalUsers = $this->countUsers($filters);
        
        return $this->db->insert(
            "INSERT INTO broadcasts (message_text, filters, total_users, status) VALUES (:msg, :filters, :total, 'pending')",
            [
                ':msg'     => $message,
                ':filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
                ':total'   => $totalUsers,
            ]
        );
    }

    /**
     * Get broadcast list for admin.
     */
    public function getBroadcasts(int $limit = 50): array
    {
        return $this->db->fetchAll("SELECT * FROM broadcasts ORDER BY created_at DESC LIMIT :limit", [':limit' => $limit]);
    }

    /**
     * Get broadcast details.
     */
    public function getBroadcast(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM broadcasts WHERE id = :id", [':id' => $id]);
    }

    /**
     * Apply filters to the SQL query.
     */
    private function applyFilters(string &$sql, array &$params, array $filters): void
    {
        // Payment status
        if (isset($filters['payment_status']) && $filters['payment_status'] !== '') {
            if ($filters['payment_status'] === 'paid') {
                $sql .= " AND u.subscription_end > NOW()";
            } else {
                $sql .= " AND (u.subscription_end IS NULL OR u.subscription_end <= NOW())";
            }
        }

        // Registration time
        if (!empty($filters['reg_from'])) {
            $sql .= " AND u.created_at >= :reg_from";
            $params[':reg_from'] = $filters['reg_from'] . ' 00:00:00';
        }
        if (!empty($filters['reg_to'])) {
            $sql .= " AND u.created_at <= :reg_to";
            $params[':reg_to'] = $filters['reg_to'] . ' 23:59:59';
        }

        // Last message time (earlier than date)
        if (!empty($filters['last_msg_before'])) {
            $sql .= " AND (u.last_message_at IS NULL OR u.last_message_at <= :last_msg_before)";
            $params[':last_msg_before'] = $filters['last_msg_before'] . ' 23:59:59';
        }

        // Profile fields: Goal (Цель)
        if (!empty($filters['goal'])) {
            $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(up.profile_json, '$.goal')) LIKE :goal";
            $params[':goal'] = '%' . $filters['goal'] . '%';
        }

        // Profile fields: Health Features (Здоровье)
        if (!empty($filters['health'])) {
            $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(up.profile_json, '$.health_features')) LIKE :health";
            $params[':health'] = '%' . $filters['health'] . '%';
        }
    }

    public function updateProgress(int $broadcastId, int $sent, int $failed, ?string $status = null): void
    {
        $sql = "UPDATE broadcasts SET sent_users = sent_users + :sent, failed_users = failed_users + :failed";
        $params = [':sent' => $sent, ':failed' => $failed, ':id' => $broadcastId];
        
        if ($status) {
            $sql .= ", status = :status";
            $params[':status'] = $status;
        }
        
        $sql .= " WHERE id = :id";
        $this->db->execute($sql, $params);
    }
}
