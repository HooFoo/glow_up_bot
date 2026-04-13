<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\BroadcastService;

$broadcastService = new BroadcastService();
$broadcasts = $broadcastService->getBroadcasts(50);

adminHeader('Рассылки', 'broadcasts');
?>

<div class="action-bar">
    <a href="broadcast_create.php" class="btn btn-primary">➕ Создать рассылку</a>
</div>

<table class="data-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Сообщение</th>
            <th>Фильтры</th>
            <th>Прогресс</th>
            <th>Статус</th>
            <th>Дата</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($broadcasts as $b): ?>
        <tr>
            <td><?= $b['id'] ?></td>
            <td>
                <div class="msg-preview" title="<?= htmlspecialchars($b['message_text']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($b['message_text'], 0, 50, "...")) ?>
                </div>
            </td>
            <td>
                <?php 
                $f = json_decode($b['filters'], true);
                $filterParts = [];
                if (!empty($f['payment_status'])) $filterParts[] = ($f['payment_status'] === 'paid' ? '💰 Платники' : '🆓 Бесплатные');
                if (!empty($f['goal'])) $filterParts[] = "🎯 " . htmlspecialchars($f['goal']);
                if (!empty($f['reg_from'])) $filterParts[] = "📅 От " . $f['reg_from'];
                echo implode(', ', $filterParts) ?: '—';
                ?>
            </td>
            <td>
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $b['total_users'] > 0 ? min(100, ($b['sent_users'] / $b['total_users']) * 100) : 0 ?>%"></div>
                    </div>
                    <div class="progress-text">
                        <?= $b['sent_users'] ?> / <?= $b['total_users'] ?>
                        <?php if ($b['failed_users'] > 0): ?>
                        <span class="text-danger">(! <?= $b['failed_users'] ?>)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td>
                <?php
                $statusColors = [
                    'pending' => 'badge-pending',
                    'sending' => 'badge-active',
                    'completed' => 'badge-completed',
                    'paused' => 'badge-trial',
                    'failed' => 'badge-inactive'
                ];
                $color = $statusColors[$b['status']] ?? 'badge-inactive';
                ?>
                <span class="badge <?= $color ?>"><?= strtoupper($b['status']) ?></span>
            </td>
            <td><?= date('d.m.Y H:i', strtotime($b['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<style>
.msg-preview { cursor: help; color: #aaa; font-size: 0.9em; }
.progress-container { width: 150px; }
.progress-bar { background: #333; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 4px; }
.progress-fill { background: linear-gradient(90deg, #e040fb, #7c4dff); height: 100%; transition: width 0.3s; }
.progress-text { font-size: 0.8em; }
.action-bar { margin-bottom: 20px; display: flex; justify-content: flex-end; }
.badge-pending { background: #555; }
.badge-completed { background: #4caf50; }
.text-danger { color: #ff5252; }
</style>

<?php adminFooter(); ?>
