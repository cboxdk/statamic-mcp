<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in to authorize</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; color: #111827; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); max-width: 400px; width: 100%; padding: 2rem; }
        .icon { width: 56px; height: 56px; background: #dbeafe; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
        .icon svg { width: 28px; height: 28px; color: #2563eb; }
        h1 { font-size: 1.25rem; font-weight: 600; text-align: center; margin-bottom: .25rem; }
        .subtitle { font-size: .875rem; color: #6b7280; text-align: center; margin-bottom: 1.5rem; }
        label { display: block; font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .25rem; }
        input[type="email"], input[type="password"] { width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: .875rem; margin-bottom: 1rem; }
        input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .btn { width: 100%; padding: .625rem 1rem; border-radius: 8px; font-size: .875rem; font-weight: 500; cursor: pointer; border: none; background: #2563eb; color: #fff; transition: background .15s; }
        .btn:hover { background: #1d4ed8; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: .5rem .75rem; border-radius: 8px; font-size: .875rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <h1>Sign in to continue</h1>
        <p class="subtitle">Sign in to your Statamic account to authorize this connection.</p>

        <div id="error" class="error" style="display:none;"></div>

        <form id="loginForm">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="btn">Sign in & Authorize</button>
        </form>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            document.getElementById('error').style.display = 'none';

            try {
                // Get CSRF token from Statamic's login page
                const tokenRes = await fetch('{{ url(config("statamic.cp.route", "cp") . "/auth/login") }}');
                const tokenHtml = await tokenRes.text();
                const csrfMatch = tokenHtml.match(/csrf[_-]token.*?content="([^"]+)"/);
                let csrf = csrfMatch ? csrfMatch[1] : '';

                // Also try meta tag
                if (!csrf) {
                    const metaMatch = tokenHtml.match(/<meta name="csrf-token" content="([^"]+)"/);
                    csrf = metaMatch ? metaMatch[1] : '';
                }

                // Try to get token from cookie
                if (!csrf) {
                    const cookies = document.cookie.split(';');
                    for (const c of cookies) {
                        const [name, val] = c.trim().split('=');
                        if (name === 'XSRF-TOKEN') csrf = decodeURIComponent(val);
                    }
                }

                // POST login to Statamic
                const loginRes = await fetch('{{ url(config("statamic.cp.route", "cp") . "/auth/login") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        email: document.getElementById('email').value,
                        password: document.getElementById('password').value,
                    }),
                    credentials: 'same-origin',
                });

                if (loginRes.ok || loginRes.status === 302 || loginRes.status === 200) {
                    // Login succeeded — redirect to the OAuth authorize URL
                    window.location.href = @json($oauthReturnUrl);
                } else {
                    const data = await loginRes.json().catch(() => null);
                    document.getElementById('error').textContent = data?.message || 'Invalid credentials. Please try again.';
                    document.getElementById('error').style.display = 'block';
                }
            } catch (err) {
                document.getElementById('error').textContent = 'Login failed. Please try again.';
                document.getElementById('error').style.display = 'block';
            }
        });
    </script>
</body>
</html>
