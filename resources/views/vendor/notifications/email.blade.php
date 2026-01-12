<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Engineering Hub' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #F8FAFC;
            color: #334155;
            line-height: 1.6;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #FFFFFF;
        }
        .email-header {
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            padding: 32px 24px;
            text-align: center;
        }
        .email-header .logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .email-header .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-size: 24px;
            font-weight: bold;
        }
        .email-header h1 {
            color: #FFFFFF;
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        .email-body {
            padding: 32px 24px;
        }
        .email-content {
            color: #334155;
            font-size: 16px;
            line-height: 1.6;
        }
        .email-content h2 {
            color: #1E3A8A;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .email-content p {
            margin-bottom: 16px;
        }
        .email-content .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #1E3A8A;
            margin-bottom: 16px;
        }
        .email-content .highlight {
            background-color: #F8FAFC;
            border-left: 4px solid #1E3A8A;
            padding: 16px;
            margin: 24px 0;
            border-radius: 4px;
        }
        .email-content .info-box {
            background-color: #F8FAFC;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 20px;
            margin: 24px 0;
        }
        .email-content .info-box h3 {
            color: #1E3A8A;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .email-content .info-box ul {
            list-style: none;
            padding: 0;
        }
        .email-content .info-box li {
            padding: 8px 0;
            color: #334155;
            border-bottom: 1px solid #E5E7EB;
        }
        .email-content .info-box li:last-child {
            border-bottom: none;
        }
        .email-content .info-box li strong {
            color: #1E3A8A;
            display: inline-block;
            min-width: 140px;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(135deg, #1E3A8A 0%, #2563EB 100%);
            color: #FFFFFF !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            text-align: center;
            margin: 24px 0;
            transition: all 0.3s ease;
        }
        .button:hover {
            background: linear-gradient(135deg, #1D4ED8 0%, #3B82F6 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }
        .button-secondary {
            background: #FFFFFF;
            color: #1E3A8A !important;
            border: 2px solid #1E3A8A;
        }
        .button-secondary:hover {
            background: #F8FAFC;
        }
        .button-danger {
            background: #DC2626;
        }
        .button-danger:hover {
            background: #B91C1C;
        }
        .email-footer {
            background-color: #F8FAFC;
            padding: 24px;
            text-align: center;
            border-top: 1px solid #E5E7EB;
        }
        .email-footer p {
            color: #64748B;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .email-footer a {
            color: #1E3A8A;
            text-decoration: none;
        }
        .email-footer a:hover {
            text-decoration: underline;
        }
        .divider {
            height: 1px;
            background-color: #E5E7EB;
            margin: 24px 0;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            margin: 8px 0;
        }
        .status-success {
            background-color: #D1FAE5;
            color: #065F46;
        }
        .status-warning {
            background-color: #FEF3C7;
            color: #92400E;
        }
        .status-error {
            background-color: #FEE2E2;
            color: #991B1B;
        }
        .status-info {
            background-color: #DBEAFE;
            color: #1E40AF;
        }
        @media only screen and (max-width: 600px) {
            .email-body {
                padding: 24px 16px;
            }
            .email-header {
                padding: 24px 16px;
            }
            .button {
                display: block;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <div class="logo">
                <div class="logo-icon">🏗️</div>
                <h1>Engineering Hub</h1>
            </div>
        </div>
        <div class="email-body">
            <div class="email-content">
                @isset($greeting)
                    <p class="greeting">{{ $greeting }}</p>
                @endisset

                @foreach ($introLines as $line)
                    <p>{{ $line }}</p>
                @endforeach

                @isset($actionText)
                    <div style="text-align: center;">
                        <a href="{{ $actionUrl }}" class="button">{{ $actionText }}</a>
                    </div>
                @endisset

                @foreach ($outroLines as $line)
                    <p>{{ $line }}</p>
                @endforeach

                @isset($salutation)
                    <p>{{ $salutation }}</p>
                @endisset
            </div>
        </div>
        <div class="email-footer">
            <p><strong>Engineering Hub</strong></p>
            <p>Secure construction platform connecting clients with verified companies</p>
            <p style="margin-top: 16px;">
                <a href="{{ config('app.url') }}">Visit Website</a> | 
                <a href="{{ config('app.url') }}/support">Support</a>
            </p>
            <p style="margin-top: 16px; font-size: 12px; color: #94A3B8;">
                This is an automated email. Please do not reply to this message.
            </p>
        </div>
    </div>
</body>
</html>
