<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\UserService;

$userService = new UserService();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$users = $userService->getListForAdmin($page, $perPage);
$total = $userService->getTotalCount();
$totalPages = max(1, (int) ceil($total / $perPage));

adminHeader('Пользователи', 'users');
?>

<table class="data-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Имя</th>
            <th>Архетип</th>
            <th>Сообщений</th>
            <th>Подписка</th>
            <th>Регистрация</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><?= $u['username'] ? '@' . htmlspecialchars($u['username']) : '—' ?></td>
            <td><?= htmlspecialchars($u['first_name'] . ' ' . ($u['last_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars($u['persona'] ?? '—') ?></td>
            <td><?= $u['message_count'] ?></td>
            <td>
                <?php if (!empty($u['subscription_end']) && strtotime($u['subscription_end']) > time()): ?>
                    <span class="badge badge-active">Активна до <?= date('d.m.Y', strtotime($u['subscription_end'])) ?></span>
                <?php elseif (!empty($u['quiz_completed_at']) && (strtotime($u['quiz_completed_at']) + 2 * 86400) > time()): ?>
                    <span class="badge badge-trial">Триал</span>
                <?php else: ?>
                    <span class="badge badge-inactive">Нет</span>
                <?php endif; ?>
            </td>
            <td><?= date('d.m.Y H:i', strtotime($u['created_at'])) ?></td>
            <td>
                <a href="user_view.php?id=<?= $u['id'] ?>" class="btn btn-outline">👁</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php adminFooter(); ?>
