(function(){
  if (!document || !document.addEventListener) return;

  var body = document.body;
  var DURATION = 150;
  var fadeRoot = null;
  var loaderEl = null;
  var loaderVisible = false;
  var loaderMode = 'shell';
  var logoutOverlay = null;
  var pendingLogoutHref = null;

  function ensureBody(){
    if (body) return body;
    body = document.body || body;
    return body;
  }

  function getFadeRoot(){
    if (fadeRoot && fadeRoot.ownerDocument === document && document.contains(fadeRoot)) {
      return fadeRoot;
    }
    var el = null;
    try {
      el = document.querySelector('[data-page-shell]');
      if (!el) el = document.getElementById('page-content-wrapper');
    } catch(_){ }
    if (!el) el = ensureBody();
    fadeRoot = el;
    return fadeRoot;
  }

  function ensureLoader(mode){
    if (mode === 'full' || mode === 'shell') {
      loaderMode = mode;
    }
    var b = ensureBody();
    if (!b) return null;

    var shellHost = null;
    try {
      shellHost = document.querySelector('[data-page-shell]') || document.getElementById('page-content-wrapper');
    } catch(_){ }

    var useFull = (loaderMode === 'full') || !shellHost;
    var host = useFull ? b : shellHost;

    if (loaderEl && loaderEl.ownerDocument === document && document.contains(loaderEl)) {
      if (loaderEl.parentNode !== host) {
        try { host.appendChild(loaderEl); } catch(_){ }
      }
    } else {
      var existing = null;
      try {
        existing = document.getElementById('page-loader');
      } catch(_){ }
      var el = existing || document.createElement('div');
      if (!existing) {
        el.id = 'page-loader';
        var inner = document.createElement('div');
        inner.className = 'page-loader-inner';
        var spinner = document.createElement('div');
        spinner.className = 'page-loader-spinner';
        var text = document.createElement('div');
        text.className = 'page-loader-text';
        text.textContent = 'Loading...';
        inner.appendChild(spinner);
        inner.appendChild(text);
        el.appendChild(inner);
      }
      host.appendChild(el);
      loaderEl = el;
    }

    if (!loaderEl) return null;
    loaderEl.classList.remove('page-loader-full', 'page-loader-shell');
    if (useFull) {
      loaderEl.classList.add('page-loader-full');
    } else {
      loaderEl.classList.add('page-loader-shell');
    }
    return loaderEl;
  }

  function showLoader(fullPage){
    var mode = fullPage ? 'full' : 'shell';
    var el = ensureLoader(mode);
    if (!el) return;
    loaderVisible = true;
    if (!el.classList.contains('page-loader-visible')) {
      el.classList.add('page-loader-visible');
    }
  }

  function hideLoader(){
    if (!loaderVisible && !loaderEl) return;
    var el = ensureLoader();
    if (!el) return;
    loaderVisible = false;
    el.classList.remove('page-loader-visible');
  }

  function ensureLogoutModal(){
    if (logoutOverlay && logoutOverlay.ownerDocument === document && document.contains(logoutOverlay)) {
      return logoutOverlay;
    }
    var b = ensureBody();
    if (!b || !b.appendChild) return null;

    var overlay = document.createElement('div');
    overlay.id = 'logout-confirm-overlay';
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(15,23,42,0.55)';
    overlay.style.display = 'none';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.zIndex = '2055';

    var box = document.createElement('div');
    box.style.background = '#ffffff';
    box.style.borderRadius = '12px';
    box.style.padding = '18px 20px 14px 20px';
    box.style.minWidth = '260px';
    box.style.maxWidth = '320px';
    box.style.boxShadow = '0 18px 40px rgba(15,23,42,0.35)';
    box.style.fontFamily = 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';

    var title = document.createElement('div');
    title.textContent = 'Logout';
    title.style.fontWeight = '600';
    title.style.marginBottom = '4px';

    var msg = document.createElement('div');
    msg.textContent = 'Are you sure you want to logout?';
    msg.style.fontSize = '0.9rem';
    msg.style.color = '#4b5563';

    var btnRow = document.createElement('div');
    btnRow.style.display = 'flex';
    btnRow.style.justifyContent = 'flex-end';
    btnRow.style.gap = '8px';
    btnRow.style.marginTop = '14px';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.className = 'btn btn-light btn-sm';

    var okBtn = document.createElement('button');
    okBtn.type = 'button';
    okBtn.textContent = 'Logout';
    okBtn.className = 'btn btn-danger btn-sm';

    btnRow.appendChild(cancelBtn);
    btnRow.appendChild(okBtn);
    box.appendChild(title);
    box.appendChild(msg);
    box.appendChild(btnRow);
    overlay.appendChild(box);
    b.appendChild(overlay);

    cancelBtn.addEventListener('click', function(){
      pendingLogoutHref = null;
      overlay.style.display = 'none';
    });

    overlay.addEventListener('click', function(ev){
      if (ev.target === overlay) {
        pendingLogoutHref = null;
        overlay.style.display = 'none';
      }
    });

    okBtn.addEventListener('click', function(){
      var href = pendingLogoutHref || 'logout.php';
      overlay.style.display = 'none';
      pendingLogoutHref = null;
      // Full-page fade + loader for logout
      fadeAndGo(href, true, true);
    });

    logoutOverlay = overlay;
    return logoutOverlay;
  }

  function requestLogout(href){
    pendingLogoutHref = href || 'logout.php';
    var ov = ensureLogoutModal();
    if (!ov) {
      window.location.href = pendingLogoutHref;
      return;
    }
    ov.style.display = 'flex';
  }

  function isSameOrigin(link){
    try {
      var href = link.getAttribute('href') || '';
      var url = new URL(href, window.location.href);
      return url.origin === window.location.origin;
    } catch (e) {
      return true;
    }
  }

  function shouldIgnoreLink(link, href){
    if (!href) return true;
    href = String(href).trim();
    if (!href || href.charAt(0) === '#') return true;
    if (/^javascript:/i.test(href)) return true;
    if (link.target && link.target !== '' && link.target !== '_self') return true;
    if (link.hasAttribute('download')) return true;
    if (link.dataset && link.dataset.noTransition === '1') return true;
    if (link.classList && link.classList.contains('no-page-transition')) return true;
    if (!isSameOrigin(link)) return true;
    return false;
  }

  function fadeAndGo(url, useBody, fullPage){
    showLoader(!!fullPage);
    var b = useBody ? ensureBody() : getFadeRoot();
    if (!b){ window.location.href = url; return; }
    if (!b.classList.contains('page-fade-out')) {
      b.classList.add('page-fade-out');
    }
    setTimeout(function(){ window.location.href = url; }, DURATION);
  }

  document.addEventListener('click', function(e){
    if (e.defaultPrevented) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var target = e.target;
    if (!target || !target.closest) return;
    var link = target.closest('a');
    if (!link) return;

    var href = link.getAttribute('href');
    if (shouldIgnoreLink(link, href)) return;

    var useBodyForFade = false;
    var isLogout = false;
    try {
      var urlObj = new URL(href, window.location.href);
      var path = urlObj.pathname || '';
      var lastSegment = path.split('/').pop().toLowerCase();
      if (lastSegment === 'logout.php') {
        useBodyForFade = true;
        isLogout = true;
      }
    } catch(_){ }

    if (isLogout) {
      e.preventDefault();
      requestLogout(href);
      return;
    }

    var hasShell = false;
    try {
      hasShell = !!document.querySelector('[data-page-shell]') || !!document.getElementById('page-content-wrapper');
    } catch(_2){ }
    var fullLoader = useBodyForFade || !hasShell;

    e.preventDefault();
    fadeAndGo(href, useBodyForFade, fullLoader);
  });

  document.addEventListener('submit', function(e){
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    if (form.target && form.target !== '' && form.target !== '_self') return;
    if (form.dataset && form.dataset.noTransition === '1') return;

    var hasShell = false;
    try {
      hasShell = !!document.querySelector('[data-page-shell]') || !!document.getElementById('page-content-wrapper');
    } catch(_2){ }
    var fullLoader = !hasShell;

    e.preventDefault();
    showLoader(fullLoader);
    var b = getFadeRoot();
    if (b) {
      b.classList.add('page-fade-out');
    }
    setTimeout(function(){ form.submit(); }, DURATION);
  });

  function applyInitialFadeIn(){
    try {
      var root = getFadeRoot();
      if (!root) return;
      if (!root.classList.contains('page-fade-in')) {
        root.classList.add('page-fade-in');
      }
      setTimeout(function(){
        try {
          root.classList.remove('page-fade-in');
          hideLoader();
        } catch(_){ }
      }, DURATION + 50);
    } catch(_){ }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyInitialFadeIn);
  } else {
    applyInitialFadeIn();
  }
})();
