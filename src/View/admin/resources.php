<?php include 'header.php'; ?>

<div class="page-header mb-4">
    <div>
        <h1>Resources</h1>
        <p class="text-muted mb-0">Partners, services, and supporters that can help new Fyuhls operators build a stronger hosting business.</p>
    </div>
</div>

<div class="alert alert-info border-0 shadow-sm mb-4">
    <strong>Supported by real partners:</strong> These companies have helped build this script by supporting us. If you'd like to sponsor and have a spot here, email <a href="mailto:<?= htmlspecialchars($sponsorEmail) ?>"><?= htmlspecialchars($sponsorEmail) ?></a> for a listing.
</div>

<?php foreach (($resourceSections ?? []) as $section): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
            <h4 class="mb-1"><?= htmlspecialchars($section['title']) ?></h4>
            <p class="text-muted mb-0"><?= htmlspecialchars($section['description']) ?></p>
        </div>
        <div class="card-body px-4 pb-4">
            <?php if (!empty($section['items'])): ?>
                <div class="row g-4">
                    <?php foreach ($section['items'] as $item): ?>
                        <div class="col-12 col-xl-6">
                            <div class="h-100 rounded-4 border bg-white p-4">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                    <div>
                                        <h5 class="mb-1"><?= htmlspecialchars($item['name']) ?></h5>
                                        <div class="small text-muted">External resource</div>
                                    </div>
                                    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars($item['url']) ?>" target="_blank" rel="noopener noreferrer">Visit Site</a>
                                </div>
                                <p class="mb-0 text-muted"><?= htmlspecialchars($item['description']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rounded-4 border border-dashed bg-light-subtle p-4">
                    <h6 class="mb-2">Open for future partnerships</h6>
                    <p class="text-muted mb-0">This section is ready for host and infrastructure partners. When you line up sponsorships or preferred providers, add them here so new operators can find trusted starting points quickly.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php include 'footer.php'; ?>
