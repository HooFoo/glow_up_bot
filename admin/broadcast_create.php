<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\BroadcastService;

$broadcastService = new BroadcastService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = $_POST['message'] ?? '';
    $filters = [
        'payment_status' => $_POST['payment_status'] ?? '',
        'reg_from'        => $_POST['reg_from'] ?? '',
        'reg_to'          => $_POST['reg_to'] ?? '',
        'last_msg_before' => $_POST['last_msg_before'] ?? '',
        'goal'            => $_POST['goal'] ?? '',
        'health'          => $_POST['health'] ?? '',
        'persona'         => $_POST['persona'] ?? '',
    ];

    if (!empty($message)) {
        $broadcastService->createBroadcast($message, $filters);
        header('Location: broadcasts.php');
        exit;
    }
}

adminHeader('Создать рассылку', 'broadcasts');
?>

<div class="form-container">
    <form method="POST" id="broadcastForm">
        <div class="form-grid">
            <div class="form-section">
                <h3>1. Сообщение</h3>
                <textarea name="message" id="message" placeholder="Введите текст сообщения... Поддерживается MarkdownV2" required></textarea>
                <div class="hint">Подсказка: используйте \ перед спецсимволами если ParseMode = MarkdownV2.</div>
            </div>

            <div class="form-section">
                <h3>2. Фильтры (Группы)</h3>
                
                <div class="filter-group">
                    <label>Статус оплаты</label>
                    <select name="payment_status" class="filter-input">
                        <option value="">Все</option>
                        <option value="paid">Платные</option>
                        <option value="free">Бесплатные / Истекшие</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Архетип (Персона)</label>
                    <select name="persona" class="filter-input">
                        <option value="">Все</option>
                        <option value="shadow_queen">👑 Тенистая Королева</option>
                        <option value="iron_controller">⚙️ Железный Контролер</option>
                        <option value="sleeping_muse">🎨 Спящая Муза</option>
                        <option value="magnetic_prime">💎 Магнитная Прайм</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Дата регистрации (С - По)</label>
                    <div class="date-range">
                        <input type="date" name="reg_from" class="filter-input">
                        <input type="date" name="reg_to" class="filter-input">
                    </div>
                </div>

                <div class="filter-group">
                    <label>Не писали в бота позже чем</label>
                    <input type="date" name="last_msg_before" class="filter-input">
                </div>

                <div class="filter-group">
                    <label>Цель (в профиле)</label>
                    <input type="text" name="goal" class="filter-input" placeholder="Напр: похудение">
                </div>

                <div class="filter-group">
                    <label>Здоровье (в профиле)</label>
                    <input type="text" name="health" class="filter-input" placeholder="Напр: диабет">
                </div>

                <div class="count-box">
                    Попадет в рассылку: <span id="userCount">...</span> пользователей
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a href="broadcasts.php" class="btn btn-outline">Отмена</a>
            <button type="submit" class="btn btn-primary">Запустить рассылку</button>
        </div>
    </form>
</div>

<style>
.form-container { background: #1a1a2e; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
.form-section h3 { margin-bottom: 20px; color: #7c4dff; }
textarea { width: 100%; height: 250px; background: #0f0f1a; border: 1px solid #333; color: #fff; padding: 15px; border-radius: 8px; font-family: inherit; resize: none; }
.filter-group { margin-bottom: 15px; }
.filter-group label { display: block; margin-bottom: 5px; font-size: 0.9em; color: #aaa; }
.filter-input { width: 100%; padding: 10px; background: #0f0f1a; border: 1px solid #333; color: #fff; border-radius: 6px; }
.date-range { display: flex; gap: 10px; }
.count-box { margin-top: 30px; padding: 20px; background: rgba(124, 77, 255, 0.1); border: 1px dashed #7c4dff; border-radius: 8px; text-align: center; font-weight: 600; font-size: 1.1em; }
#userCount { color: #e040fb; font-size: 1.3em; }
.form-actions { margin-top: 40px; display: flex; gap: 15px; justify-content: flex-end; }
.hint { font-size: 0.8em; color: #666; margin-top: 5px; }
</style>

<script>
const form = document.getElementById('broadcastForm');
const countSpan = document.getElementById('userCount');
const filterInputs = document.querySelectorAll('.filter-input');

async function updateCount() {
    countSpan.innerText = '...';
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    
    try {
        const response = await fetch('ajax/broadcast_count.php?' + params.toString());
        const data = await response.json();
        countSpan.innerText = data.count;
    } catch (e) {
        countSpan.innerText = 'ошибка';
    }
}

filterInputs.forEach(input => {
    input.addEventListener('change', updateCount);
    if (input.type === 'text') {
        input.addEventListener('input', debounce(updateCount, 500));
    }
});

function debounce(func, wait) {
    let timeout;
    return function() {
        clearTimeout(timeout);
        timeout = setTimeout(func, wait);
    };
}

updateCount();
</script>

<?php adminFooter(); ?>
