<style>
    :root {
        --color-brand-400: #60a5fa;
        --color-brand-500: #3b82f6;
        --color-brand-600: #2563eb;
        --color-brand-700: #1d4ed8;
        --color-surface-50: #f8fafc;
        --color-surface-100: #f1f5f9;
        --color-surface-200: #e2e8f0;
        --color-surface-300: #cbd5e1;
        --color-surface-400: #94a3b8;
        --color-surface-500: #64748b;
        --color-surface-600: #475569;
        --color-surface-700: #334155;
        --color-surface-800: #1e293b;
        --color-surface-900: #0f172a;
        --color-surface-950: #020617;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    .font-sans { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
    .font-mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }

    .h-full { height: 100%; }
    .min-h-full { min-height: 100%; }
    .flex { display: flex; }
    .inline-flex { display: inline-flex; }
    .grid { display: grid; }
    .hidden { display: none; }
    .block { display: block; }
    .flex-1 { flex: 1 1 0%; }
    .flex-col { flex-direction: column; }
    .flex-wrap { flex-wrap: wrap; }
    .items-center { align-items: center; }
    .items-start { align-items: flex-start; }
    .justify-center { justify-content: center; }
    .justify-between { justify-content: space-between; }
    .justify-end { justify-content: flex-end; }
    .gap-1 { gap: 0.25rem; }
    .gap-2 { gap: 0.5rem; }
    .gap-3 { gap: 0.75rem; }
    .gap-4 { gap: 1rem; }
    .gap-6 { gap: 1.5rem; }
    .space-y-1 > * + * { margin-top: 0.25rem; }
    .space-y-2 > * + * { margin-top: 0.5rem; }
    .space-y-3 > * + * { margin-top: 0.75rem; }
    .space-y-4 > * + * { margin-top: 1rem; }
    .space-y-6 > * + * { margin-top: 1.5rem; }
    .min-w-0 { min-width: 0; }
    .w-full { width: 100%; }
    .w-64 { width: 16rem; }
    .w-8 { width: 2rem; }
    .w-10 { width: 2.5rem; }
    .w-14 { width: 3.5rem; }
    .h-2 { height: 0.5rem; }
    .h-4 { height: 1rem; }
    .h-5 { height: 1.25rem; }
    .h-8 { height: 2rem; }
    .h-10 { height: 2.5rem; }
    .h-14 { height: 3.5rem; }
    .max-w-sm { max-width: 24rem; }
    .max-w-md { max-width: 28rem; }
    .max-w-lg { max-width: 32rem; }
    .max-w-2xl { max-width: 42rem; }
    .max-w-4xl { max-width: 56rem; }
    .max-h-96 { max-height: 24rem; }
    .overflow-hidden { overflow: hidden; }
    .overflow-y-auto { overflow-y: auto; }
    .overflow-x-auto { overflow-x: auto; }
    .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .whitespace-nowrap { white-space: nowrap; }
    .whitespace-pre-wrap { white-space: pre-wrap; }
    .break-all { word-break: break-all; }

    .p-2 { padding: 0.5rem; }
    .p-3 { padding: 0.75rem; }
    .p-4 { padding: 1rem; }
    .p-6 { padding: 1.5rem; }
    .px-2 { padding-left: 0.5rem; padding-right: 0.5rem; }
    .px-3 { padding-left: 0.75rem; padding-right: 0.75rem; }
    .px-4 { padding-left: 1rem; padding-right: 1rem; }
    .px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
    .py-1 { padding-top: 0.25rem; padding-bottom: 0.25rem; }
    .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
    .py-3 { padding-top: 0.75rem; padding-bottom: 0.75rem; }
    .py-8 { padding-top: 2rem; padding-bottom: 2rem; }
    .py-12 { padding-top: 3rem; padding-bottom: 3rem; }
    .pt-4 { padding-top: 1rem; }
    .pb-4 { padding-bottom: 1rem; }
    .pl-3 { padding-left: 0.75rem; }
    .mt-1 { margin-top: 0.25rem; }
    .mt-2 { margin-top: 0.5rem; }
    .mt-4 { margin-top: 1rem; }
    .mt-6 { margin-top: 1.5rem; }
    .mt-8 { margin-top: 2rem; }
    .mb-1 { margin-bottom: 0.25rem; }
    .mb-2 { margin-bottom: 0.5rem; }
    .mb-4 { margin-bottom: 1rem; }
    .mb-6 { margin-bottom: 1.5rem; }
    .ml-auto { margin-left: auto; }
    .mr-2 { margin-right: 0.5rem; }

    .text-xs { font-size: 0.75rem; line-height: 1rem; }
    .text-sm { font-size: 0.875rem; line-height: 1.25rem; }
    .text-base { font-size: 1rem; line-height: 1.5rem; }
    .text-lg { font-size: 1.125rem; line-height: 1.75rem; }
    .text-xl { font-size: 1.25rem; line-height: 1.75rem; }
    .text-2xl { font-size: 1.5rem; line-height: 2rem; }
    .text-3xl { font-size: 1.875rem; line-height: 2.25rem; }
    .font-medium { font-weight: 500; }
    .font-semibold { font-weight: 600; }
    .font-bold { font-weight: 700; }
    .tracking-tight { letter-spacing: -0.025em; }
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    .uppercase { text-transform: uppercase; }
    .leading-relaxed { line-height: 1.625; }

    .rounded { border-radius: 0.25rem; }
    .rounded-md { border-radius: 0.375rem; }
    .rounded-lg { border-radius: 0.5rem; }
    .rounded-xl { border-radius: 0.75rem; }
    .rounded-2xl { border-radius: 1rem; }
    .rounded-full { border-radius: 9999px; }

    .border { border-width: 1px; border-style: solid; }
    .border-t { border-top-width: 1px; border-top-style: solid; }
    .border-b { border-bottom-width: 1px; border-bottom-style: solid; }
    .border-surface-700 { border-color: var(--color-surface-700); }
    .border-surface-800 { border-color: var(--color-surface-800); }
    .border-red-800 { border-color: #991b1b; }
    .border-emerald-700 { border-color: #047857; }
    .border-blue-700 { border-color: #1d4ed8; }
    .border-brand-500 { border-color: var(--color-brand-500); }

    .bg-surface-800 { background-color: var(--color-surface-800); }
    .bg-surface-900 { background-color: var(--color-surface-900); }
    .bg-surface-900\/80 { background-color: rgba(15, 23, 42, 0.8); }
    .bg-surface-950 { background-color: var(--color-surface-950); }
    .bg-brand-600 { background-color: var(--color-brand-600); }
    .bg-brand-600\/10 { background-color: rgba(37, 99, 235, 0.1); }
    .bg-brand-600\/20 { background-color: rgba(37, 99, 235, 0.2); }
    .bg-emerald-600 { background-color: #059669; }
    .bg-emerald-900\/90 { background-color: rgba(6, 78, 59, 0.9); }
    .bg-red-600 { background-color: #dc2626; }
    .bg-red-900\/20 { background-color: rgba(127, 29, 29, 0.2); }
    .bg-red-900\/90 { background-color: rgba(127, 29, 29, 0.9); }
    .bg-blue-900\/90 { background-color: rgba(30, 58, 138, 0.9); }
    .bg-amber-600 { background-color: #d97706; }
    .bg-terminal { background-color: #0a0e14; }

    .text-white { color: #fff; }
    .text-surface-100 { color: var(--color-surface-100); }
    .text-surface-200 { color: var(--color-surface-200); }
    .text-surface-300 { color: var(--color-surface-300); }
    .text-surface-400 { color: var(--color-surface-400); }
    .text-surface-500 { color: var(--color-surface-500); }
    .text-brand-400 { color: var(--color-brand-400); }
    .text-brand-500 { color: var(--color-brand-500); }
    .text-emerald-400 { color: #34d399; }
    .text-emerald-100 { color: #d1fae5; }
    .text-red-400 { color: #f87171; }
    .text-red-100 { color: #fee2e2; }
    .text-amber-400 { color: #fbbf24; }
    .text-blue-100 { color: #dbeafe; }
    .text-terminal-green { color: #7ee787; }
    .text-terminal-dim { color: #484f58; }

    .shadow-lg { box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); }
    .shadow-xl { box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1); }
    .shadow-brand-600\/30 { box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3); }
    .backdrop-blur-sm { backdrop-filter: blur(4px); }

    .antialiased { -webkit-font-smoothing: antialiased; }

    .fixed { position: fixed; }
    .absolute { position: absolute; }
    .relative { position: relative; }
    .inset-0 { inset: 0; }
    .bottom-4 { bottom: 1rem; }
    .right-4 { right: 1rem; }
    .z-40 { z-index: 40; }
    .z-50 { z-index: 50; }

    .transition { transition-property: all; transition-duration: 150ms; }
    .transition-colors { transition-property: color, background-color, border-color; transition-duration: 150ms; }
    .cursor-pointer { cursor: pointer; }
    .cursor-not-allowed { cursor: not-allowed; }
    .opacity-50 { opacity: 0.5; }
    .opacity-60 { opacity: 0.6; }
    .opacity-75 { opacity: 0.75; }

    .grid-cols-1 { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    .grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .col-span-2 { grid-column: span 2 / span 2; }

    @media (min-width: 640px) {
        .sm\:px-6 { padding-left: 1.5rem; padding-right: 1.5rem; }
        .sm\:p-6 { padding: 1.5rem; }
        .sm\:mx-auto { margin-left: auto; margin-right: auto; }
        .sm\:w-full { width: 100%; }
        .sm\:max-w-md { max-width: 28rem; }
        .sm\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .sm\:flex-row { flex-direction: row; }
    }
    @media (min-width: 768px) {
        .md\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .md\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .md\:flex { display: flex; }
        .md\:hidden { display: none; }
    }
    @media (min-width: 1024px) {
        .lg\:p-8 { padding: 2rem; }
        .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .lg\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .lg\:px-8 { padding-left: 2rem; padding-right: 2rem; }
    }

    /* Form elements */
    input[type="text"], input[type="email"], input[type="password"], input[type="url"], select, textarea {
        width: 100%;
        padding: 0.625rem 0.875rem;
        background-color: var(--color-surface-950);
        border: 1px solid var(--color-surface-700);
        border-radius: 0.5rem;
        color: var(--color-surface-100);
        font-size: 0.875rem;
        outline: none;
        transition: border-color 150ms;
    }
    input:focus, select:focus, textarea:focus {
        border-color: var(--color-brand-500);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }
    input::placeholder, textarea::placeholder { color: var(--color-surface-500); }
    label { display: block; font-size: 0.875rem; font-weight: 500; color: var(--color-surface-300); margin-bottom: 0.375rem; }
    input[type="checkbox"] { width: auto; accent-color: var(--color-brand-600); }

    /* Buttons */
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
        padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500;
        border-radius: 0.5rem; border: none; cursor: pointer;
        transition: all 150ms; white-space: nowrap;
    }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-primary { background: var(--color-brand-600); color: #fff; }
    .btn-primary:hover:not(:disabled) { background: var(--color-brand-700); }
    .btn-secondary { background: var(--color-surface-800); color: var(--color-surface-200); border: 1px solid var(--color-surface-700); }
    .btn-secondary:hover:not(:disabled) { background: var(--color-surface-700); }
    .btn-danger { background: #991b1b; color: #fff; }
    .btn-danger:hover:not(:disabled) { background: #7f1d1d; }
    .btn-ghost { background: transparent; color: var(--color-surface-300); }
    .btn-ghost:hover:not(:disabled) { background: var(--color-surface-800); color: #fff; }
    .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.8125rem; }

    /* Cards */
    .card {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid var(--color-surface-800);
        border-radius: 0.75rem;
        padding: 1.25rem;
    }
    .card-hover:hover { border-color: var(--color-surface-700); background: rgba(15, 23, 42, 0.8); }

    /* Progress bar */
    .progress-bar { height: 0.5rem; background: var(--color-surface-800); border-radius: 9999px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 9999px; transition: width 500ms ease; }

    /* Spinner */
    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner {
        width: 1.25rem; height: 1.25rem;
        border: 2px solid var(--color-surface-600);
        border-top-color: var(--color-brand-500);
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    .spinner-lg { width: 2.5rem; height: 2.5rem; border-width: 3px; }

    /* Terminal */
    .terminal {
        background: #0a0e14;
        border: 1px solid #21262d;
        border-radius: 0.5rem;
        font-family: 'JetBrains Mono', ui-monospace, monospace;
        font-size: 0.8125rem;
        line-height: 1.6;
    }
    .terminal-header {
        display: flex; align-items: center; gap: 0.5rem;
        padding: 0.625rem 1rem;
        background: #161b22;
        border-bottom: 1px solid #21262d;
        border-radius: 0.5rem 0.5rem 0 0;
    }
    .terminal-dot { width: 0.75rem; height: 0.75rem; border-radius: 50%; }
    .terminal-body { padding: 1rem; max-height: 28rem; overflow-y: auto; color: #7ee787; }
    .terminal-line { padding: 0.0625rem 0; }
    .terminal-line.dim { color: #484f58; }
    .terminal-line.error { color: #f85149; }
    .terminal-line.warn { color: #d29922; }

    /* Badge */
    .badge {
        display: inline-flex; align-items: center;
        padding: 0.125rem 0.5rem; font-size: 0.75rem; font-weight: 500;
        border-radius: 9999px;
    }
    .badge-green { background: rgba(5, 150, 105, 0.2); color: #34d399; }
    .badge-red { background: rgba(220, 38, 38, 0.2); color: #f87171; }
    .badge-amber { background: rgba(217, 119, 6, 0.2); color: #fbbf24; }
    .badge-blue { background: rgba(37, 99, 235, 0.2); color: #60a5fa; }
    .badge-gray { background: rgba(100, 116, 139, 0.2); color: #94a3b8; }

    /* Table */
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 0.75rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-surface-400); border-bottom: 1px solid var(--color-surface-800); }
    td { padding: 0.875rem 1rem; font-size: 0.875rem; border-bottom: 1px solid var(--color-surface-800); }
    tr:hover td { background: rgba(30, 41, 59, 0.4); }

    /* Sidebar nav */
    .nav-link {
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.625rem 0.875rem; border-radius: 0.5rem;
        font-size: 0.875rem; font-weight: 500;
        color: var(--color-surface-400); text-decoration: none;
        transition: all 150ms;
    }
    .nav-link:hover { background: var(--color-surface-800); color: var(--color-surface-100); }
    .nav-link.active { background: rgba(37, 99, 235, 0.15); color: var(--color-brand-400); }
    .nav-link svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }

    /* Modal overlay */
    .modal-overlay {
        position: fixed; inset: 0; z-index: 50;
        background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px);
        display: flex; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-content {
        background: var(--color-surface-900);
        border: 1px solid var(--color-surface-800);
        border-radius: 0.75rem;
        width: 100%; max-width: 32rem;
        max-height: 90vh; overflow-y: auto;
        box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.5);
    }

    /* Job overlay pulse */
    @keyframes pulse-ring {
        0% { transform: scale(0.9); opacity: 1; }
        100% { transform: scale(1.3); opacity: 0; }
    }
    .pulse-ring::before {
        content: ''; position: absolute; inset: -4px;
        border-radius: 50%; border: 2px solid var(--color-brand-500);
        animation: pulse-ring 1.5s ease-out infinite;
    }

    a { color: var(--color-brand-400); text-decoration: none; }
    a:hover { color: var(--color-brand-500); }
</style>
