<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ошибка оплаты | Prime Glow</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #ebbcba;
            --background: #191724;
            --surface: #1f1d2e;
            --text: #e0def4;
            --error: #eb6f92;
            --glass: rgba(31, 29, 46, 0.8);
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            background-color: var(--background);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow: hidden;
            background: radial-gradient(circle at top left, #3e2e3e 0%, transparent 40%),
                        radial-gradient(circle at bottom right, #2a2a37 0%, transparent 40%),
                        #191724;
        }

        .container {
            text-align: center;
            background: var(--glass);
            backdrop-filter: blur(20px);
            padding: 3rem;
            border-radius: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            max-width: 400px;
            width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .icon {
            font-size: 5rem;
            margin-bottom: 2rem;
            display: inline-block;
        }

        h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--error);
        }

        p {
            font-size: 1.1rem;
            font-family: 'Roboto', sans-serif;
            line-height: 1.6;
            margin-bottom: 2.5rem;
            opacity: 0.8;
        }

        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #eb6f92, #ebbcba);
            color: #191724;
            text-decoration: none;
            padding: 1rem 2.5rem;
            border-radius: 100px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px -5px rgba(235, 111, 146, 0.4);
        }

        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 15px 30px -5px rgba(235, 111, 146, 0.6);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .help-text {
            margin-top: 1.5rem;
            font-size: 0.9rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">😔</div>
        <h1>Упс! Ошибка</h1>
        <p>К сожалению, оплата не прошла. Попробуйте еще раз или выберите другой способ оплаты в боте.</p>
        <a href="tg://resolve?domain=Nasty_justybot" class="btn">Попробовать снова</a>
        <div class="help-text">Если деньги списались, но подписка не активировалась — напишите в поддержку.</div>
    </div>
</body>
</html>
