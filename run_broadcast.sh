#!/bin/bash

# Путь к проекту
# Находим путь к директории скрипта (корень проекта)
PROJECT_PATH="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

PHP_BIN=$(which php)
WORKER_SCRIPT="$PROJECT_PATH/bot/broadcast_worker.php"
LOG_FILE="$PROJECT_PATH/logs/broadcast.log"
LOCK_FILE="/tmp/broadcast_worker.lock"

# Создаем директорию для логов, если ее нет
mkdir -p "$PROJECT_PATH/logs"

# Проверка, не запущен ли уже скрипт
if [ -e "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE")
    if ps -p $PID > /dev/null; then
        # Скрипт уже запущен, выходим
        exit 0
    else
        # Старый lock-файл, удаляем
        rm "$LOCK_FILE"
    fi
fi

# Сохраняем PID текущего процесса
echo $$ > "$LOCK_FILE"

# Запуск воркера
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting broadcast worker" >> "$LOG_FILE"
$PHP_BIN "$WORKER_SCRIPT" >> "$LOG_FILE" 2>&1
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Finished" >> "$LOG_FILE"

# Удаляем lock-файл
rm "$LOCK_FILE"
