/**
 * Local FAMMRO loader. It does not call the signed runtime endpoint.
 * After cookie consent it asks the public bootstrap for one of two fixed script URLs.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;

  var backend = (script.getAttribute('data-backend') || 'https://ormmaf.fammro.com').replace(/\/$/, '');
  var installation = script.getAttribute('data-installation') || '';
  var apiKey = script.getAttribute('data-api-key') || '';
  var shop = script.getAttribute('data-shopify-shop') || '';
  var consentRequired = script.getAttribute('data-consent') === 'required';
  var started = false;

  function allowed(url) {
    return url === 'https://ormmaf.fammro.com/fammro-widget.js'
      || url === 'https://ormmaf.fammro.com/fammro-widget.prev.js';
  }

  function inject(src, externalConsent) {
    if (started || !allowed(src)) return;
    if (document.querySelector('script[src="' + src + '"]')) return;
    started = true;
    var node = document.createElement('script');
    node.src = src;
    node.async = true;
    if (script.nonce) node.nonce = script.nonce;
    if (apiKey) node.setAttribute('data-api-key', apiKey);
    if (shop) node.setAttribute('data-shopify-shop', shop);
    node.setAttribute('data-backend', backend);
    if (externalConsent) node.setAttribute('data-gdpr', 'false');
    node.onerror = function () { started = false; };
    document.head.appendChild(node);
  }

  function fetchBootstrap() {
    var url = backend + '/api/widget/bootstrap';
    if (installation) url += '?installation=' + encodeURIComponent(installation);
    // Shopify-App-Einbettung: nur die Shop-Domain bekannt
    else if (!apiKey && shop) url += '?shop=' + encodeURIComponent(shop);
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = controller ? setTimeout(function () {
      try { controller.abort(); } catch (e) {}
    }, 2500) : null;
    var options = { credentials: 'omit' };
    if (controller) options.signal = controller.signal;
    if (!installation && apiKey) {
      options.method = 'POST';
      options.headers = { 'Content-Type': 'application/json' };
      options.body = JSON.stringify({ apiKey: apiKey });
    }
    return fetch(url, options).then(function (res) {
      if (timer) clearTimeout(timer);
      if (!res.ok) return null;
      return res.json();
    }, function () {
      if (timer) clearTimeout(timer);
      return null;
    });
  }

  function bootstrap(externalConsent) {
    fetchBootstrap().then(function (body) {
      if (!body || body.enabled !== true || !allowed(body.scriptUrl)) return;
      inject(body.scriptUrl, externalConsent);
    }).catch(function () {});
  }

  function consentGranted() {
    try {
      if (window.Shopify && window.Shopify.customerPrivacy && window.Shopify.customerPrivacy.analyticsProcessingAllowed) {
        return window.Shopify.customerPrivacy.analyticsProcessingAllowed() === true
          || (window.Shopify.customerPrivacy.preferencesProcessingAllowed && window.Shopify.customerPrivacy.preferencesProcessingAllowed() === true);
      }
    } catch (e) {}
    try {
      if (window.wp_consent_type || window.wp_has_consent) {
        return window.wp_has_consent ? window.wp_has_consent('functional') === true : false;
      }
    } catch (e2) {}
    try {
      if (window.Cookiebot && typeof window.Cookiebot.consent !== 'undefined') return window.Cookiebot.consent.preferences === true;
    } catch (e3) {}
    try {
      if (window.cookieyes && window.cookieyes._ckyConsentStore) {
        var store = window.cookieyes._ckyConsentStore.get();
        return Boolean(store && (store.functional === 'yes' || store.analytics === 'yes'));
      }
    } catch (e4) {}
    var gcm = window.google_tag_data && window.google_tag_data.ics;
    if (gcm && gcm.entries && gcm.entries.functionality_storage) {
      return gcm.entries.functionality_storage.update === true;
    }
    return null;
  }

  function whenConsented(done) {
    if (!consentRequired) {
      done(false);
      return;
    }
    var known = consentGranted();
    if (known === true) {
      done(true);
      return;
    }
    if (known === false) {
      window.addEventListener('fammro:consent', function () { done(true); }, { once: true });
      return;
    }
    var waits = 0;
    var timer = setInterval(function () {
      waits += 1;
      var state = consentGranted();
      if (state === true) {
        clearInterval(timer);
        done(true);
      } else if (waits > 20 && state === null) {
        clearInterval(timer);
        done(false);
      }
    }, 300);
  }

  window.FAMMRO = window.FAMMRO || {};
  var previousConsent = window.FAMMRO.consent;
  window.FAMMRO.consent = function (accepted) {
    if (typeof previousConsent === 'function' && previousConsent !== window.FAMMRO.consent) {
      try { previousConsent(accepted); } catch (e) {}
    }
    if (accepted) bootstrap(true);
    else {
      try {
        Object.keys(localStorage).forEach(function (key) {
          if (key.indexOf('fammro_') === 0) localStorage.removeItem(key);
        });
      } catch (err) {}
    }
  };

  whenConsented(function (externalConsent) {
    if (consentRequired && consentGranted() === false) return;
    bootstrap(externalConsent);
  });
})();
