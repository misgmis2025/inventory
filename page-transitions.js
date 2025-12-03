(function(){
  if (!document || !document.addEventListener) return;

  var body = document.body;
  var DURATION = 250;

  function ensureBody(){
    if (body) return body;
    body = document.body || body;
    return body;
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

  function fadeAndGo(url){
    var b = ensureBody();
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

    e.preventDefault();
    fadeAndGo(href);
  });

  document.addEventListener('submit', function(e){
    if (e.defaultPrevented) return;
    var form = e.target;
    if (!form || form.nodeName !== 'FORM') return;
    if (form.target && form.target !== '' && form.target !== '_self') return;
    if (form.dataset && form.dataset.noTransition === '1') return;

    e.preventDefault();
    var b = ensureBody();
    if (b) {
      b.classList.add('page-fade-out');
    }
    setTimeout(function(){ form.submit(); }, DURATION);
  });
})();
