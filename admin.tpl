<div class="titrePage">
  <h2>Piwigo Privacy — 2026 Security &amp; Delivery Status</h2>
</div>

<div class="piwigo-privacy-admin" style="text-align:left; max-width:960px; margin:16px auto;">
  <fieldset>
    <legend>Active Configuration &amp; Compatibility</legend>
    <table class="table2" style="width:100%;">
      <tbody>
        <tr>
          <td style="width:35%;"><strong>Delivery Mode</strong></td>
          <td><code>{$PWG_PRIVACY_MODE|escape:'html'}</code></td>
        </tr>
        <tr>
          <td><strong>Redirect Header / Prefix</strong></td>
          <td><code>{$PWG_PRIVACY_REDIRECT_HEADER|escape:'html'}</code> &rarr; <code>{$PWG_PRIVACY_INTERNAL_PREFIX|escape:'html'}</code></td>
        </tr>
        <tr>
          <td><strong>URL Rewriting</strong></td>
          <td>{$PWG_PRIVACY_REWRITE_URLS|escape:'html'}</td>
        </tr>
        <tr>
          <td><strong>Derivative URL Style (<code>$conf['derivative_url_style']</code>)</strong></td>
          <td>{$PWG_PRIVACY_DERIV_STYLE|escape:'html'} (0, 1, and 2 all supported)</td>
        </tr>
        <tr>
          <td><strong>Cache-Control Header</strong></td>
          <td><code>{$PWG_PRIVACY_CACHE_CONTROL|escape:'html'}</code></td>
        </tr>
        <tr>
          <td><strong>Metadata Stripping</strong></td>
          <td>{$PWG_PRIVACY_STRIP_META|escape:'html'}</td>
        </tr>
        <tr>
          <td><strong>Runtime Environment</strong></td>
          <td>PHP <code>{$PWG_PRIVACY_PHP_VERSION|escape:'html'}</code> | Piwigo <code>{$PWG_PRIVACY_PIWIGO_VERSION|escape:'html'}</code> | Graphics: <code>{$PWG_PRIVACY_GFX_LIB|escape:'html'}</code></td>
        </tr>
        <tr>
          <td><strong>Ecosystem Integrations</strong></td>
          <td>
            API Keys: {$PWG_PRIVACY_API_KEY_SUPPORT|escape:'html'}<br>
            Private Share: {$PWG_PRIVACY_PSHARE_SUPPORT|escape:'html'}<br>
            Multiple Formats: {$PWG_PRIVACY_FORMATS_SUPPORT|escape:'html'}
          </td>
        </tr>
      </tbody>
    </table>
  </fieldset>

  <fieldset style="margin-top:20px;">
    <legend>Web Server Perimeter Self-Check (Unauthenticated Probes)</legend>
    <p>Runs anonymous <code>fetch()</code> probes (<code>credentials: 'omit'</code>) from your browser to verify Nginx blocks direct leaks of configs, logs, and media.</p>
    <p><button type="button" id="pwg-privacy-run-probes" class="buttonLike" data-root="{$PWG_PRIVACY_ROOT_URL|escape:'html'}">Run Perimeter Check</button></p>
    <div id="pwg-privacy-probe-results"></div>
  </fieldset>
</div>

<script>
(function () {
  const btn = document.getElementById('pwg-privacy-run-probes');
  const out = document.getElementById('pwg-privacy-probe-results');
  if (!btn || !out) return;
  const root = btn.getAttribute('data-root') || '/';

  const probes = [
    { label: 'Database config blocked (/local/config/database.inc.php)', path: 'local/config/database.inc.php', allowedStatuses: [403] },
    { label: 'Private logs directory blocked (/_data/logs/)', path: '_data/logs/', allowedStatuses: [403] },
    { label: 'Plugin Nginx sample blocked (/plugins/piwigo_privacy/piwigo-nginx-site)', path: 'plugins/piwigo_privacy/piwigo-nginx-site', allowedStatuses: [403] },
    { label: 'Raw get.php rejects missing parameters (/plugins/piwigo_privacy/get.php)', path: 'plugins/piwigo_privacy/get.php', allowedStatuses: [400] },
    { label: 'Direct /upload/ intercepted by get.php (not raw Nginx 404)', path: 'upload/nonexistent-probe.jpg', allowedStatuses: [401, 403], allowRedirect: true },
    { label: 'Unauthenticated /i.php intercepted by get.php (not core 404)', path: 'i.php?/upload/nonexistent-probe-th.jpg', allowedStatuses: [401, 403], allowRedirect: true }
  ];

  btn.addEventListener('click', async function () {
    out.textContent = 'Running probes...';
    const ul = document.createElement('ul');
    ul.style.listStyle = 'none';
    ul.style.paddingLeft = '0';

    for (const p of probes) {
      const li = document.createElement('li');
      li.style.padding = '4px 0';
      try {
        const res = await fetch(root + p.path, { method: 'GET', credentials: 'omit', redirect: 'manual' });
        const isOpaqueRedirect = res.type === 'opaqueredirect';
        const blocked = (p.allowRedirect && isOpaqueRedirect) || p.allowedStatuses.includes(res.status);
        const statusDesc = isOpaqueRedirect ? 'HTTP 302 redirect' : ('HTTP ' + res.status);
        li.textContent = (blocked ? '[PASS] ' : '[WARN] ') + p.label + ' (' + statusDesc + ')';
        li.style.color = blocked ? '#1b7f3b' : '#b42318';
      } catch (err) {
        li.textContent = '[ERR] ' + p.label + ' (' + err.message + ')';
      }
      ul.appendChild(li);
    }
    out.innerHTML = '';
    out.appendChild(ul);
  });
})();
</script>
