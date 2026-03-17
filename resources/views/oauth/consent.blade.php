<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize {{ $client->clientName }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; color: #111827; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); max-width: 440px; width: 100%; padding: 2rem; }
        .icon { width: 56px; height: 56px; background: #dbeafe; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }
        .icon svg { width: 28px; height: 28px; color: #2563eb; }
        h1 { font-size: 1.25rem; font-weight: 600; text-align: center; margin-bottom: .25rem; }
        .subtitle { font-size: .875rem; color: #6b7280; text-align: center; margin-bottom: 1.5rem; }
        .scopes-label { font-size: .875rem; font-weight: 500; color: #374151; margin-bottom: .75rem; }
        .scopes { max-height: 200px; overflow-y: auto; margin-bottom: 1.5rem; border: 1px solid #e5e7eb; border-radius: 8px; }
        .scope { display: flex; align-items: center; gap: .5rem; padding: .5rem .75rem; font-size: .875rem; border-bottom: 1px solid #f3f4f6; }
        .scope:last-child { border-bottom: none; }
        .scope input[type="checkbox"] { width: 16px; height: 16px; accent-color: #2563eb; }
        .buttons { display: flex; gap: .75rem; }
        .btn { flex: 1; padding: .625rem 1rem; border-radius: 8px; font-size: .875rem; font-weight: 500; cursor: pointer; border: none; transition: background .15s; }
        .btn-deny { background: #fff; border: 1px solid #d1d5db; color: #374151; }
        .btn-deny:hover { background: #f9fafb; }
        .btn-approve { background: #2563eb; color: #fff; }
        .btn-approve:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <h1>Authorize {{ $client->clientName }}</h1>
        <p class="subtitle">This application wants access to your MCP server.</p>

        <form method="POST" action="{{ route('statamic.cp.statamic-mcp.oauth.approve') }}">
            @csrf
            <input type="hidden" name="client_id" value="{{ $oauthParams['client_id'] }}">
            <input type="hidden" name="redirect_uri" value="{{ $oauthParams['redirect_uri'] }}">
            <input type="hidden" name="state" value="{{ $oauthParams['state'] }}">
            <input type="hidden" name="code_challenge" value="{{ $oauthParams['code_challenge'] }}">
            <input type="hidden" name="code_challenge_method" value="{{ $oauthParams['code_challenge_method'] }}">
            <input type="hidden" name="scope" value="{{ $oauthParams['scope'] }}">

            <p class="scopes-label">Choose permissions to grant:</p>
            <div class="scopes">
                @php
                    $requestedWildcard = collect($scopes)->contains(fn($s) => $s['value'] === '*');
                    $defaultWildcard = in_array('*', $defaultScopes);
                    $individualScopes = collect($scopes)->filter(fn($s) => $s['value'] !== '*')->values();
                @endphp
                <label class="scope" style="font-weight: 600; border-bottom: 2px solid #e5e7eb;">
                    <input type="checkbox" name="scopes[]" value="*" id="full-access"
                        {{ $requestedWildcard || $defaultWildcard ? 'checked' : '' }}>
                    Full Access
                </label>
                <div id="individual-scopes" style="{{ $requestedWildcard || $defaultWildcard ? 'display:none' : '' }}">
                    @foreach ($individualScopes as $scope)
                        <label class="scope scope-individual">
                            <input type="checkbox" name="scopes[]" value="{{ $scope['value'] }}"
                                {{ in_array($scope['value'], $defaultScopes) || $requestedWildcard || $defaultWildcard ? 'checked' : '' }}>
                            {{ $scope['label'] }}
                        </label>
                    @endforeach
                </div>
            </div>
            <script>
                document.getElementById('full-access').addEventListener('change', function() {
                    var container = document.getElementById('individual-scopes');
                    container.style.display = this.checked ? 'none' : '';
                });
            </script>

            <div class="buttons">
                <button type="submit" name="decision" value="deny" class="btn btn-deny">Deny</button>
                <button type="submit" name="decision" value="approve" class="btn btn-approve">Approve</button>
            </div>
        </form>
    </div>
</body>
</html>
