<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication Code</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #1976D2;
            margin-bottom: 10px;
        }
        .title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
        }
        .code-container {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 30px 0;
        }
        .code {
            font-size: 32px;
            font-weight: bold;
            color: #1976D2;
            letter-spacing: 4px;
            font-family: 'Courier New', monospace;
        }
        .instructions {
            background-color: #e3f2fd;
            border-left: 4px solid #1976D2;
            padding: 15px;
            margin: 20px 0;
        }
        .instructions h3 {
            margin: 0 0 10px 0;
            color: #1976D2;
            font-size: 16px;
        }
        .instructions ul {
            margin: 0;
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #666;
            font-size: 12px;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 10px;
            margin: 20px 0;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ config('app.name', 'Thryft') }}</div>
            <div class="title">Two-Factor Authentication</div>
            <div class="subtitle">Your verification code is ready</div>
        </div>

        <p>Hello {{ $userName }},</p>

        <p>You've requested to log in to your account. To complete the login process, please use the verification code below:</p>

        <div class="code-container">
            <div class="code">{{ $code }}</div>
        </div>

        <div class="instructions">
            <h3>Important Security Information:</h3>
            <ul>
                <li>This code will expire in 10 minutes</li>
                <li>Never share this code with anyone</li>
                <li>If you didn't request this code, please ignore this email</li>
                <li>For security, this code can only be used once</li>
            </ul>
        </div>

        <div class="warning">
            <strong>Security Notice:</strong> If you didn't attempt to log in to your account, please change your password immediately and contact support.
        </div>

        <p>If you're having trouble with the code, you can request a new one from the login page.</p>

        <p>Best regards,<br>
        The {{ config('app.name', 'Thryft') }} Team</p>

        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'Thryft') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
