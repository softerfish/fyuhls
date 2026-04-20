<div class="small">
    <p class="mb-4">Use the Add Node page when you are creating a brand-new storage destination. Fill in the provider details, choose the delivery method, and save only after one connection test succeeds.</p>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Storage Types</h6>
    <ul class="mb-4">
        <li><strong>Local:</strong> Uses disk space on this server. Best for simpler installs and small deployments.</li>
        <li><strong>Backblaze B2 / Wasabi / R2 / S3 Compatible:</strong> Uses external object storage for better capacity and lower shared-hosting pressure during uploads.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Fields To Fill In</h6>
    <ul class="mb-4">
        <li><strong>Friendly name and status:</strong> Gives the node an admin label and decides whether it should accept uploads immediately.</li>
        <li><strong>Storage path / bucket info:</strong> Sets the real location where files will be stored.</li>
        <li><strong>Provider credentials:</strong> Stores the key, secret, region, and endpoint details used to connect.</li>
        <li><strong>Public URL and delivery method:</strong> Controls whether Fyuhls hands downloads off directly or keeps the app in the middle.</li>
        <li><strong>Capacity:</strong> Optional limit used only for planning and warning thresholds.</li>
    </ul>

    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Backblaze B2 Helpers</h6>
    <ul class="mb-4">
        <li><strong>Load My B2 Buckets:</strong> Uses the Key ID and Application Key currently in the form to list the available buckets.</li>
        <li><strong>Bucket picker:</strong> Choosing a bucket fills the bucket name, region, and endpoint fields automatically.</li>
        <li><strong>Apply Fyuhls CORS:</strong> Uses the current B2 Key ID, Application Key, and bucket name to write the recommended browser-upload CORS rule directly to the real B2 bucket.</li>
    </ul>

    <div class="alert alert-info border-0">
        <strong>Save flow:</strong> Complete the form, run any provider helpers you need, then use the page save button to create the node.
    </div>
</div>
