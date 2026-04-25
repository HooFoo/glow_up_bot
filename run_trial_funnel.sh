#!/bin/bash

# Находим путь к директории скрипта (корень проекта)
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

# Выполняем воркер триальной воронки
php "$PROJECT_DIR/bot/trial_worker.php" >> "$PROJECT_DIR/logs/trial_funnel.log" 2>&1

echo "Trial funnel worker execution finished at $(date)" >> "$PROJECT_DIR/logs/trial_funnel.log"
