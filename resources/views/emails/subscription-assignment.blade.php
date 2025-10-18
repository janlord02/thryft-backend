<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Assignment - {{ config('app.name') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .email-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }
        .content {
            padding: 30px 20px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #1f2937;
        }
        .subscription-card {
            background: #f8f9fa;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }
        .plan-name {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .plan-price {
            font-size: 18px;
            color: #059669;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .plan-description {
            color: #6b7280;
            margin-bottom: 16px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 16px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .detail-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
        }
        .cta-button {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin: 20px 0;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 14px;
            color: #6b7280;
            text-align: center;
        }
        .footer p {
            margin: 8px 0;
        }
        .success-badge {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 16px;
        }
        @media (max-width: 600px) {
            .details-grid {
                grid-template-columns: 1fr;
            }
            .content {
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>{{ config('app.name') }}</h1>
        </div>

        <div class="content">
            <div class="greeting">
                Hello {{ $user->name }},
            </div>

            <div class="success-badge">
                ✅ Subscription Assigned
            </div>

            <p>Great news! A subscription plan has been assigned to your business account. You now have access to premium features and services.</p>

            <div class="subscription-card">
                <div class="plan-name">{{ $subscription->name }}</div>
                <div class="plan-price">${{ number_format($subscription->price, 2) }}/{{ $subscription->billing_cycle }}</div>
                <div class="plan-description">{{ $subscription->description }}</div>

                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Start Date</div>
                        <div class="detail-value">{{ \Carbon\Carbon::parse($userSubscription->starts_at)->format('M j, Y') }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">End Date</div>
                        <div class="detail-value">{{ $userSubscription->ends_at ? \Carbon\Carbon::parse($userSubscription->ends_at)->format('M j, Y') : 'No expiration' }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">{{ ucfirst($userSubscription->status) }}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Payment Method</div>
                        <div class="detail-value">{{ ucfirst($userSubscription->payment_method) }}</div>
                    </div>
                </div>
            </div>

            <p>This subscription gives you access to:</p>
            @if($subscription->features && count($subscription->features) > 0)
                <ul style="margin: 0; padding-left: 20px; color: #374151;">
                    @foreach($subscription->features as $feature)
                        <li style="margin-bottom: 8px; line-height: 1.5;">{{ is_string($feature) ? $feature : ($feature['name'] ?? 'Feature') }}</li>
                    @endforeach
                </ul>
            @else
                <ul style="margin: 0; padding-left: 20px; color: #374151;">
                    <li style="margin-bottom: 8px; line-height: 1.5;">Enhanced business features</li>
                    <li style="margin-bottom: 8px; line-height: 1.5;">Priority customer support</li>
                    <li style="margin-bottom: 8px; line-height: 1.5;">Advanced analytics and reporting</li>
                    <li style="margin-bottom: 8px; line-height: 1.5;">Extended storage and bandwidth</li>
                </ul>
            @endif

            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url') }}/dashboard" class="cta-button">
                    Access Your Dashboard
                </a>
            </div>

            <p>If you have any questions about your subscription or need assistance, please don't hesitate to contact our support team.</p>

            <p>Thank you for choosing {{ config('app.name') }}!</p>
        </div>

        <div class="footer">
            <p><strong>{{ config('app.name') }}</strong></p>
            <p>This email was sent to {{ $user->email }}</p>
            <p>Sent on {{ \Carbon\Carbon::now()->format('M j, Y \a\t g:i A') }}</p>
            <p>You can manage your notification preferences in your account settings.</p>
        </div>
    </div>
</body>
</html>
