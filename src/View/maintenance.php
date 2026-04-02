<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - <?= htmlspecialchars($siteName ?? 'Site') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .container { max-width: 480px; padding: 2rem; }
        .icon { font-size: 4rem; margin-bottom: 1.5rem; }
        h1 { font-size: 2rem; font-weight: 700; margin-bottom: 0.75rem; }
        p { color: #94a3b8; line-height: 1.6; font-size: 1.0625rem; }
        .badge {
            display: inline-block;
            margin-bottom: 1.25rem;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 999px;
            padding: 0.25rem 0.875rem;
            font-size: 0.8125rem;
            color: #f59e0b;
            font-weight: 500;
            letter-spacing: 0.02em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔧</div>
        <div class="badge">Maintenance</div>
        <h1>We'll be right back</h1>
        <p>The site is down for a quick update. This usually doesn't take long - check back soon.</p>
    </div>
</body>
</html>
