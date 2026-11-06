# Security Policy

## Reporting a vulnerability

Report security issues through
[GitHub Private Vulnerability Reporting](https://github.com/cboxdk/statamic-mcp/security/advisories).
While signed in to GitHub, choose **Report a vulnerability** to open the private
reporting form. That keeps the report private until a fix is available.

Please do not open a public issue for a security problem.

This is a best-effort process run by a small team. There is no guaranteed
response time, and we do not operate as a CVE Numbering Authority — if an issue
warrants a CVE, we will work with you to request one through GitHub.

## Supported versions

Fixes land on the latest minor release. Older minors are not backported.

## What this package is responsible for

This addon exposes a Statamic site over the Model Context Protocol. Its own
security surface is:

- **Token authentication** — API tokens are stored SHA-256 hashed; the plaintext
  is shown once at creation and never again.
- **Scoped authorization** — every tool call and resource read is checked against
  the token's scopes, a site-wide resource policy, and the underlying Statamic
  user's permissions.
- **Confirmation gate** — destructive operations require a second call carrying an
  HMAC-bound confirmation token. Enabled in production by default.
- **OAuth 2.1 with PKCE** — for browser-based MCP clients, including dynamic client
  registration and token revocation.
- **Password-only access** — Basic Auth has been removed. Use scoped Bearer tokens or OAuth.
- **OAuth grant lifecycle** — the shipped drivers preserve consumed refresh-token hashes until grant expiry, revoke the grant on replay, and stop refresh after scope/expiry changes. Multi-node deployments require a shared lock-capable `oauth.lock_store`.
- **Transport** — plain HTTP is refused in production when `require_https` is on.

## What it is not responsible for

- **Securing the Statamic installation itself.** File permissions, the CP login,
  server hardening, and TLS termination are the operator's responsibility.
- **What an MCP client does with granted access.** A token's scopes are the
  boundary; anything inside them is permitted by design. Issue narrow scopes.
- **Auditing prompts or model behaviour.** The addon logs tool calls; it does not
  inspect or constrain what a model asks for beyond the authorization layers above.

## Known limitations

These are stated plainly because assuming otherwise would be a security mistake.

- **The audit log is append-only by convention, not by construction.** Entries are
  appended to a JSONL file (or a database table) with no hash chain or signature,
  so it is neither tamper-proof nor tamper-evident: anyone with write access to the
  storage path can edit or remove entries undetectably. Treat it as an operational
  record, not as evidence. Ship it to external, write-once storage if you need
  integrity guarantees.
- **Confirmation tokens are not single-use.** They are stateless HMACs bound to the
  tool and its canonicalized arguments, with a nonce and timestamp, but no replay
  store — within its validity window the same token confirms the same call again.
