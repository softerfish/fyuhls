<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - <?= htmlspecialchars($siteName) ?></title>
    <style>
        :root {
            --primary: #ef4444;
            --text: #1f2937;
            --bg: #f9fafb;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            text-align: center;
        }
        .container {
            max-width: 500px;
            padding: 2rem;
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .icon {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 1.875rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #111827;
        }
        p {
            line-height: 1.625;
            color: #4b5563;
            margin-bottom: 2rem;
        }
        .ip-box {
            background: #f3f4f6;
            padding: 0.75rem;
            border-radius: 0.5rem;
            font-family: monospace;
            font-size: 0.875rem;
            margin-bottom: 2rem;
        }
        .footer {
            font-size: 0.875rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🛡️</div>
        <h1>VPN / Proxy Detected</h1>
        <p>
            In order to protect our service from abuse, we do not currently allow access via VPN, Proxy, or Tor services. Please disable your VPN first and refresh the page to continue.<br><br>Thank-you for your understanding.
        </p>
        <div class="ip-box">
            Your IP: <?= htmlspecialchars($ip) ?>
        </div>
        <div class="footer">
            If you believe this is an error, please contact support.
        </div>
    </div>
</body>
</html>
