<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Приглашение в Kubik</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 2px solid #4f46e5;
        }
        .header h1 {
            color: #4f46e5;
            margin: 0;
        }
        .content {
            padding: 30px 0;
        }
        .button {
            display: inline-block;
            background-color: #4f46e5;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #4338ca;
        }
        .footer {
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #6b7280;
        }
        .info {
            background-color: #f3f4f6;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Kubik</h1>
    </div>

    <div class="content">
        <p>Здравствуйте, {{ $employee->full_name }}!</p>

        <p>Вы были добавлены в систему Kubik в качестве сотрудника.</p>

        <div class="info">
            <strong>Ваши данные:</strong><br>
            Должность: {{ $employee->position }}<br>
            Email: {{ $employee->email }}<br>
            Роль: {{ $employee->role->display_name }}
        </div>

        <p>Для активации вашего аккаунта перейдите по ссылке ниже и установите пароль:</p>

        <p style="text-align: center;">
            <a href="{{ $inviteUrl }}" class="button">Активировать аккаунт</a>
        </p>

        <p>Или скопируйте эту ссылку в браузер:</p>
        <p style="word-break: break-all; color: #4f46e5;">{{ $inviteUrl }}</p>
    </div>

    <div class="footer">
        <p>Если вы не запрашивали это приглашение, просто проигнорируйте это письмо.</p>
        <p>С уважением,<br>Команда Kubik</p>
    </div>
</body>
</html>
