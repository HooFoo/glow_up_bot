#!/bin/bash

# Находим путь к директории скрипта (корень проекта)
PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

# Выполняем рассылку
php "$PROJECT_DIR/bot/mailing.php" >> "$PROJECT_DIR/logs/mailing.log" 2>&1

echo "Mailing execution finished at $(date)" >> "$PROJECT_DIR/logs/mailing.log"
