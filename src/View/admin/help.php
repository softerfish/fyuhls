<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>help & docs</title>
</head>
<body>
    <h1>Help & Docs</h1>
    <a href="/admin">&laquo; back to dashboard</a><hr>
    <ul>
        <?php foreach ($sections as $i => $section): ?>
            <li><a href="#s<?= $i ?>"><?= htmlspecialchars($section['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php foreach ($sections as $i => $section): ?>
        <hr>
        <a id="s<?= $i ?>"></a>
        <?= $section['html'] ?>
    <?php endforeach; ?>
</body>
</html>
