<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notification->title }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #3b82f6;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .content {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 0 0 8px 8px;
        }
        .notification-type {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .type-info { background: #dbeafe; color: #1e40af; }
        .type-warning { background: #fef3c7; color: #92400e; }
        .type-error { background: #fee2e2; color: #991b1b; }
        .type-success { background: #dcfce7; color: #166534; }
        .type-urgent { background: #fee2e2; color: #991b1b; }
        .footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ config('app.name') }}</h1>
    </div>

    <div class="content">
        <div class="notification-type type-{{ $notification->type }}">
            {{ ucfirst($notification->type) }}
        </div>

        <h2>{{ $notification->title }}</h2>

        <p>{{ $notification->message }}</p>

        @if($notification->urgent)
            <p><strong>⚠️ This is an urgent notification</strong></p>
        @endif

        <div class="footer">
            <p>This notification was sent to {{ $user->name }} ({{ $user->email }})</p>
            <p>Sent at: {{ $notification->created_at->format('M j, Y \a\t g:i A') }}</p>
            <p>You can manage your notification preferences in your account settings.</p>
        </div>
    </div>
</body>
</html>
