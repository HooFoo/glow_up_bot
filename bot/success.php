<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата прошла успешно | Prime Glow</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #c4a7e7;
            --background: #191724;
            --surface: #1f1d2e;
            --text: #e0def4;
            --success: #9ccfd8;
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
            background: radial-gradient(circle at top right, #31748f 0%, transparent 40%),
                        radial-gradient(circle at bottom left, #ebbcba 0%, transparent 40%),
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
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--success);
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
            background: linear-gradient(135deg, #c4a7e7, #9ccfd8);
            color: #191724;
            text-decoration: none;
            padding: 1rem 2.5rem;
            border-radius: 100px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px -5px rgba(196, 167, 231, 0.4);
        }

        .btn:hover {
            transform: scale(1.05);
            box-shadow: 0 15px 30px -5px rgba(196, 167, 231, 0.6);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .stars {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }
    </style>
</head>
<body>
    <div class="stars" id="particles"></div>
    <div class="container">
        <div class="icon">✨</div>
        <h1>Успешно!</h1>
        <p>Ваша оплата прошла успешно. Благодарим за доверие! Теперь все функции Prime Glow доступны вам в полном объёме.</p>
        <a href="tg://resolve?domain=Nasty_justybot" class="btn">Вернуться в Telegram</a>
    </div>

    <script>
        // Simple star particles
        const container = document.getElementById('particles');
        for (let i = 0; i < 50; i++) {
            const star = document.createElement('div');
            star.style.position = 'absolute';
            star.style.left = Math.random() * 100 + '%';
            star.style.top = Math.random() * 100 + '%';
            star.style.width = Math.random() * 3 + 'px';
            star.style.height = star.style.width;
            star.style.background = 'white';
            star.style.borderRadius = '50%';
            star.style.opacity = Math.random();
            star.style.animation = `pulse ${2 + Math.random() * 3}s infinite alternate`;
            container.appendChild(star);
        }

        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                from { opacity: 0.2; transform: scale(0.8); }
                to { opacity: 0.8; transform: scale(1.2); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
