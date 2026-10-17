(function () {
  'use strict';

  /**
   * Shared REST client for the POP tools.
   *
   * The wp_rest nonce printed with the page expires after 12–24 hours and is also
   * invalidated when the user logs in again elsewhere. When WordPress rejects it
   * ("Cookie check failed"), fetch a fresh nonce once and replay the request. If no
   * fresh nonce can be issued, the login session itself is gone: show a banner with
   * a login link instead of the raw error.
   */
  var SESSION_EXPIRED = 'Your session has expired. Please log in again to keep saving your changes.';
  var refreshing = null;
  var banner = null;

  function refreshNonce(config) {
    if (!config.ajaxUrl) return Promise.reject(new Error('missing ajaxUrl'));
    if (!refreshing) {
      refreshing = fetch(config.ajaxUrl + (config.ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=rest-nonce', {
        credentials: 'same-origin',
        cache: 'no-store'
      }).then(function (response) {
        return response.text().then(function (text) {
          text = (text || '').trim();
          // admin-ajax answers "0" (HTTP 400) when the visitor is no longer logged in.
          if (!response.ok || !/^[a-f0-9]{10}$/i.test(text)) throw new Error('nonce refresh failed');
          return text;
        });
      }).finally(function () { refreshing = null; });
    }
    return refreshing.then(function (nonce) { config.nonce = nonce; return nonce; });
  }

  function showSessionBanner(config) {
    if (banner) return;
    banner = document.createElement('div');
    banner.className = 'popp-session-banner';
    banner.setAttribute('role', 'alert');
    var text = document.createElement('span');
    text.textContent = SESSION_EXPIRED + ' Log in in a new tab, then retry here — your unsaved changes on this page are kept.';
    banner.appendChild(text);
    if (config.loginUrl) {
      var link = document.createElement('a');
      link.href = config.loginUrl;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'Log in again';
      banner.appendChild(link);
    }
    document.body.appendChild(banner);
  }

  function hideSessionBanner() {
    if (banner) { banner.remove(); banner = null; }
  }

  function isSessionError(status, json) {
    return status === 401 || (status === 403 && json && json.code === 'rest_cookie_invalid_nonce');
  }

  function request(config, path, options, retried) {
    options = options || {};
    return fetch(config.root + path, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: Object.assign({ 'Accept': 'application/json', 'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'X-WP-Nonce': config.nonce }, options.headers || {}),
      body: options.body ? JSON.stringify(options.body) : undefined
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (json) {
        if (response.ok) { hideSessionBanner(); return json; }

        if (json && json.code === 'rest_cookie_invalid_nonce' && !retried) {
          return refreshNonce(config).then(function () {
            return request(config, path, options, true);
          }, function () {
            return fail(config, 401, { code: 'popp_session_expired' });
          });
        }
        return fail(config, response.status, json);
      });
    });
  }

  function fail(config, status, json) {
    var sessionLost = isSessionError(status, json) || (json && json.code === 'popp_session_expired');
    if (sessionLost) showSessionBanner(config);
    var error = new Error(sessionLost ? SESSION_EXPIRED : ((json && json.message) || 'Something went wrong. Please try again.'));
    error.status = sessionLost ? 401 : status;
    error.code = (json && json.code) || '';
    error.sessionExpired = sessionLost;
    throw error;
  }

  window.POPP_REST = { request: function (config, path, options) { return request(config, path, options, false); } };
})();
