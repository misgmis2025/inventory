(function(){
  if (!document || !document.addEventListener) return;

  var body = document.body;
  var DURATION = 150;
  var fadeRoot = null;

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

  function fadeAndGo(url, useBody){
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

    var confirmMsg = link.getAttribute('data-confirm');
    if (confirmMsg && !window.confirm(confirmMsg)) {
      return;
    }

    var useBodyForFade = false;
    try {
      var urlObj = new URL(href, window.location.href);
      var path = urlObj.pathname || '';
      var lastSegment = path.split('/').pop().toLowerCase();
      if (lastSegment === 'logout.php') {
        useBodyForFade = true;
      }
    } catch(_){ }

    e.preventDefault();
    fadeAndGo(href, useBodyForFade);
  });

  document.addEventListener('submit', function(e){
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    if (form.target && form.target !== '' && form.target !== '_self') return;
    if (form.dataset && form.dataset.noTransition === '1') return;

    e.preventDefault();
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
