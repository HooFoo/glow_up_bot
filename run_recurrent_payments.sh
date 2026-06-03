#!/bin/bash

# Находим путь к директории скрипта (корень проекта)
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

# Выполняем скрипт рекуррентных списаний
php "$PROJECT_DIR/bot/recurrent_payments.php" >> "$PROJECT_DIR/logs/recurrent_payments.log" 2>&1

echo "Recurrent payments check finished at $(date)" >> "$PROJECT_DIR/logs/recurrent_payments.log"
