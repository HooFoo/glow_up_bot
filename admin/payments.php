<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\SubscriptionService;
use App\Core\TelegramApi;

$telegram = new TelegramApi();
$subService = new SubscriptionService($telegram);

// Fetch stats
$statsAllTime = $subService->getStatsAllTime();
$statsLastMonth = $subService->getStatsLastMonth();
$renewalPercent = $subService->getRenewalPercentage();

// Charts data (10 days)
$linksData = $subService->getLinksPerDay(10);
$paidData = $subService->getPaidPerDay(10);

// Log table
$page = (int) ($_GET['page'] ?? 1);
$limit = 50;
$offset = ($page - 1) * $limit;
$paymentLogs = $subService->getPaymentLogs($limit, $offset);

// Prepare graph data
$days = [];
$now = new DateTime();
for ($i = 9; $i >= 0; $i--) {
    $date = (clone $now)->modify("-{$i} days")->format('Y-m-d');
    $days[$date] = [
        'label' => (clone $now)->modify("-{$i} days")->format('d.m'),
        'links' => 0,
        'paid' => 0
    ];
}

foreach ($linksData as $row) {
    if (isset($days[$row['day']])) {
        $days[$row['day']]['links'] = (int) $row['count'];
    }
}
foreach ($paidData as $row) {
    if (isset($days[$row['day']])) {
        $days[$row['day']]['paid'] = (int) $row['count'];
    }
}

$chartLabels = array_column($days, 'label');
$chartLinks = array_column($days, 'links');
$chartPaid = array_column($days, 'paid');

// Calculate efficiency
$totalLinks = (int) ($statsAllTime['total_links'] ?? 0);
$totalPaid = (int) ($statsAllTime['total_paid'] ?? 0);
$efficiency = $totalLinks > 0 ? round(($totalPaid / $totalLinks) * 100, 1) : 0;

adminHeader('Платежи и Аналитика', 'payments');
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-value"><?= number_format((float) ($statsAllTime['total_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
        <div class="kpi-label">Выручка (всего)</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value"><?= $totalPaid ?> / <?= $totalLinks ?></div>
        <div class="kpi-label">Оплаты / Ссылки</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color: #00e676;"><?= $efficiency ?>%</div>
        <div class="kpi-label">Эффективность воронки</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color: #e040fb;"><?= $renewalPercent ?>%</div>
        <div class="kpi-label">Процент продлений</div>
    </div>
</div>

<div class="kpi-grid" style="margin-top: 20px; grid-template-columns: repeat(4, 1fr);">
    <div class="kpi-card sub-card">
        <div class="kpi-label">За последний месяц:</div>
        <div class="kpi-value-small"><?= number_format((float) ($statsLastMonth['total_revenue'] ?? 0), 0, '.', ' ') ?> ₽</div>
    </div>
    <div class="kpi-card sub-card">
        <div class="kpi-label">Оплат за месяц:</div>
        <div class="kpi-value-small"><?= $statsLastMonth['total_paid'] ?? 0 ?></div>
    </div>
</div>

<div class="charts-grid">
    <div class="chart-card">
        <h3>📊 Воронка оплат (10 дней)</h3>
        <canvas id="paymentsFunnelChart"></canvas>
    </div>
    <div class="chart-card">
        <h3>📈 Динамика эффективности (%)</h3>
        <canvas id="efficiencyTrendChart"></canvas>
    </div>
</div>

<div class="table-container shadow-vibrant" style="margin-top: 30px;">
    <div class="table-header">
        <h3>История платежей и ссылок</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Пользователь</th>
                <th>Сумма</th>
                <th>Статус</th>
                <th>Тип</th>
                <th>Order ID</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($paymentLogs as $log): ?>
                <tr>
                    <td><?= date('d.m.Y H:i', strtotime($log['created_at'])) ?></td>
                    <td>
                        <a href="user_view.php?id=<?= $log['user_id'] ?>" style="color: #e040fb; text-decoration: none;">
                            <?= htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) ?>
                            <?php if ($log['username']): ?> <span style="color: #9e9e9e;">(@<?= htmlspecialchars($log['username']) ?>)</span><?php endif; ?>
                        </a>
                    </td>
                    <td><?= number_format((float) $log['amount'], 0, '.', ' ') ?> ₽</td>
                    <td>
                        <?php if ($log['status'] === 'paid'): ?>
                            <span class="badge badge-success">Оплачено</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Ссылка отправлена</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['is_renewal']): ?>
                            <span style="color: #7c4dff;">🔄 Продление</span>
                        <?php else: ?>
                            <span style="color: #00e676;">🆕 Первая покупка</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-family: monospace; font-size: 11px; color: #9e9e9e;"><?= htmlspecialchars($log['order_id']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="btn-small">⬅️ Назад</a>
        <?php endif; ?>
        <span class="page-info">Страница <?= $page ?></span>
        <?php if (count($paymentLogs) === $limit): ?>
            <a href="?page=<?= $page + 1 ?>" class="btn-small">Вперёд ➡️</a>
        <?php endif; ?>
    </div>
</div>

<style>
.kpi-value-small { font-size: 24px; font-weight: 700; margin-top: 5px; }
.sub-card { padding: 15px; border-left: 4px solid #7c4dff; }
.badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
.badge-success { background: rgba(0, 230, 118, 0.1); color: #00e676; border: 1px solid rgba(0, 230, 118, 0.2); }
.badge-warning { background: rgba(255, 196, 0, 0.1); color: #ffc400; border: 1px solid rgba(255, 196, 0, 0.2); }
.pagination { padding: 20px; text-align: center; }
.btn-small { background: #1a1a2e; color: #e0e0e0; padding: 6px 12px; border-radius: 6px; text-decoration: none; border: 1px solid #333; margin: 0 10px; }
.page-info { color: #9e9e9e; font-size: 14px; }
</style>

<script>
const funnelCtx = document.getElementById('paymentsFunnelChart').getContext('2d');
new Chart(funnelCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
            {
                label: 'Отправлено ссылок',
                data: <?= json_encode($chartLinks) ?>,
                backgroundColor: 'rgba(224, 64, 251, 0.2)',
                borderColor: '#e040fb',
                borderWidth: 2,
                borderRadius: 5
            },
            {
                label: 'Оплат',
                data: <?= json_encode($chartPaid) ?>,
                backgroundColor: 'rgba(0, 230, 118, 0.4)',
                borderColor: '#00e676',
                borderWidth: 2,
                borderRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9e9e9e' } },
            x: { grid: { display: false }, ticks: { color: '#9e9e9e' } }
        },
        plugins: {
            legend: { position: 'top', labels: { color: '#e0e0e0' } }
        }
    }
});

// For simplicity, efficiency trend chart can be a line chart of Paid / Links ratio
const efficiencyData = <?= json_encode(array_map(function($l, $p) { 
    return $l > 0 ? round(($p / $l) * 100, 1) : 0; 
}, $chartLinks, $chartPaid)) ?>;

const efficiencyCtx = document.getElementById('efficiencyTrendChart').getContext('2d');
new Chart(efficiencyCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [{
            label: 'Конверсия %',
            data: efficiencyData,
            borderColor: '#00e676',
            backgroundColor: 'rgba(0, 230, 118, 0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 6,
            pointBackgroundColor: '#00e676'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9e9e9e', callback: v => v + '%' } },
            x: { grid: { display: false }, ticks: { color: '#9e9e9e' } }
        },
        plugins: {
            legend: { display: false }
        }
    }
});
</script>

<?php adminFooter(); ?>
