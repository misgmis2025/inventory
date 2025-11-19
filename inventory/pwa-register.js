(function(){
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      // Append a version to force update when file changes
      navigator.serviceWorker.register('service-worker.js?v=4').catch(function(){});
    });
  }
})();
