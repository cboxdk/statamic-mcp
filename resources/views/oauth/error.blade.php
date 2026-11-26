<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Authorization failed</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; color: #111827; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); max-width: 440px; width: 100%; padding: 2rem; text-align: center; }
        .icon { width: 56px; height: 56px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
        .icon svg { width: 28px; height: 28px; color: #dc2626; }
        h1 { font-size: 1.25rem; font-weight: 600; margin-bottom: .5rem; }
        p { font-size: .875rem; color: #4b5563; line-height: 1.5; }
        code { display: inline-block; margin-top: 1rem; font-size: .75rem; color: #6b7280; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: .25rem .5rem; }
        @media (prefers-color-scheme: dark) {
            body { background: #111827; color: #f9fafb; }
            .card { background: #1f2937; box-shadow: none; }
            .icon { background: #450a0a; }
            p { color: #d1d5db; }
            code { background: #111827; border-color: #374151; color: #9ca3af; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h1>This app could not be authorized</h1>
        <p>{{ $description }}</p>
        <p style="margin-top: .75rem;">Close this window and try connecting again from the app. If it keeps happening, tell the app's developer.</p>
        <code>{{ $error }}</code>
    </main>
</body>
</html>
