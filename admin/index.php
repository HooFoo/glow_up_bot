<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\UserService;
use App\Services\SubscriptionService;
use App\Core\TelegramApi;

$userService = new UserService();
$telegram = new TelegramApi();
$subService = new SubscriptionService($telegram);

$totalUsers = $userService->getTotalCount();
$activeSubs = $userService->getActiveSubscriptionsCount();
$newUsersData = $userService->getNewUsersPerDay(10);
$subsData = $subService->getSubscriptionsPerDay(10);

// Prepare chart data
$days = [];
$newUsersCounts = [];
foreach ($newUsersData as $row) {
    $days[] = date('d.m', strtotime($row['day']));
    $newUsersCounts[] = (int) $row['count'];
}

$subsDays = [];
$subsCounts = [];
foreach ($subsData as $row) {
    $subsDays[] = date('d.m', strtotime($row['day']));
    $subsCounts[] = (int) $row['count'];
}

adminHeader('Dashboard', 'dashboard');
?>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-value"><?= $totalUsers ?></div>
        <div class="kpi-label">Всего пользователей</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value"><?= $activeSubs ?></div>
        <div class="kpi-label">Активных подписок</div>
    </div>
</div>

<div class="charts-grid">
    <div class="chart-card">
        <h3>📈 Новые пользователи (10 дней)</h3>
        <canvas id="usersChart"></canvas>
    </div>
    <div class="chart-card">
        <h3>💳 Покупки подписок (10 дней)</h3>
        <canvas id="subsChart"></canvas>
    </div>
</div>

<script>
const chartOptions = {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9e9e9e' } },
        y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#9e9e9e', stepSize: 1 }, beginAtZero: true }
    }
};

new Chart(document.getElementById('usersChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($days) ?>,
        datasets: [{
            data: <?= json_encode($newUsersCounts) ?>,
            borderColor: '#e040fb',
            backgroundColor: 'rgba(224,64,251,0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#e040fb'
        }]
    },
    options: chartOptions
});

new Chart(document.getElementById('subsChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($subsDays) ?>,
        datasets: [{
            data: <?= json_encode($subsCounts) ?>,
            borderColor: '#7c4dff',
            backgroundColor: 'rgba(124,77,255,0.1)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#7c4dff'
        }]
    },
    options: chartOptions
});
</script>

<?php adminFooter(); ?>
