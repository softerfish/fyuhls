<style>
    .dashboard-shell { margin-top: 1rem; }
    .dashboard-plan-card { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); }
    .dashboard-plan-current { margin-bottom: 0.25rem; font-size: 0.875rem; color: var(--text-color); font-weight: 600; }
    .dashboard-plan-name { color: var(--primary-color); }
    .dashboard-plan-expiry { font-size: 0.75rem; color: var(--text-muted); }
    .dashboard-plan-expiry--tight { margin-bottom: 0.5rem; }
    .dashboard-plan-expiry--wide { margin-bottom: 1.25rem; }
    .dashboard-plan-button { width: auto; padding: 0.5rem 1.5rem; }
    .dashboard-account-title { margin-top: 1rem; }
    .dashboard-account-title:first-of-type { margin-top: 0; }
    .dashboard-nav { list-style: none; padding: 0.35rem 0 0.6rem; margin: 0; }
    .dashboard-nav li { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
    .dashboard-nav-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.5rem;
        height: 1.5rem;
        padding: 0 0.4rem;
        border-radius: 999px;
        background: #eef4ff;
        color: var(--primary-color);
        font-size: 0.72rem;
        font-weight: 700;
        margin-left: auto;
    }
    .dashboard-trash-item { padding: 0; display: flex; justify-content: space-between; align-items: center; min-height: 40px; }
    .dashboard-trash-link { flex: 1; padding: 0.6rem 0.75rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
    .dashboard-toolbar-controls { display: flex !important; align-items: center !important; gap: 12px !important; flex-wrap: wrap !important; width: auto !important; min-width: 0 !important; justify-content: flex-end !important; position: relative !important; z-index: 10 !important; }
    .dashboard-search-box { width: min(220px, 100%) !important; flex: 1 1 180px !important; position: relative !important; }
    .dashboard-search-input { width: 100% !important; box-sizing: border-box !important; }
    .dashboard-view-toggle { width: 80px !important; height: 38px !important; display: flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important; background: #f1f5f9 !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; font-size: 0.8rem !important; cursor: pointer !important; position: relative !important; z-index: 20 !important; }
    .dashboard-date-hidden,
    .dashboard-menu-hidden { display: none; }
    .dashboard-hidden { display: none; }
</style>
