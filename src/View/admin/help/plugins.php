<div class="p-1">
    <p class="guide-purpose mb-4">Plugins are for optional operator extensions. Core features like Rewards, storage providers, and Two-Factor Authentication are built into Fyuhls already and do not belong in this manager.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Supported Plugin Workflow</h6>
    <ol class="guide-steps mb-4">
        <li><strong>Upload or place the files:</strong> You can upload a plugin ZIP here or place a plugin folder under <code>src/Plugin/</code>.</li>
        <li><strong>Install:</strong> Registers the plugin in the database and runs its install logic.</li>
        <li><strong>Activate only after review:</strong> Turn it on after you understand what it changes and confirm it belongs on the live site.</li>
        <li><strong>Deactivate before uninstalling:</strong> Active plugins must be deactivated before they can be removed.</li>
    </ol>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Best Use Cases</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2">Custom ad networks or monetization add-ons.</li>
        <li class="mb-2">Site-specific workflow tools or partner integrations.</li>
        <li>Experimental features you do not want merged into the core script.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Operational Notes</h6>
    <ul class="extra-small text-muted mb-4">
        <li class="mb-2"><strong>Core features are not plugins:</strong> If you are looking for built-in rewards or security features, configure those in admin instead of here.</li>
        <li class="mb-2"><strong>Manual updates matter:</strong> Preserve <code>src/Plugin/</code> during manual upgrades or your installed plugin files can disappear.</li>
        <li><strong>Plugins are trusted code:</strong> Fyuhls intentionally allows operators to install their own plugins, so treat plugin installation as a trusted-code decision.</li>
    </ul>

    <div class="alert alert-warning border-0 shadow-sm small">
        <strong>Security Note:</strong> Only install plugins you trust. A plugin runs inside the same app context and can access the database, storage, and configuration.
    </div>
</div>
