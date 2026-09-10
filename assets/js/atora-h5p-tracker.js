(function(){
  if (!window.ATORA_H5P_TRACK) { return; }
  var cfg = window.ATORA_H5P_TRACK || {};
  var restUrl = cfg.restUrl;
  var nonce = cfg.nonce;
  var lessonId = parseInt(cfg.lessonId, 10) || 0;
  var contentId = parseInt(cfg.contentId, 10) || 0;
  if (!restUrl || !nonce || !lessonId || !contentId) { return; }

  var lastSentAt = 0;
  var pending = false;
  var queued = null;
  var MIN_INTERVAL_MS = 5000;

  function shouldSend(stmt){
    try {
      var verb = (stmt && stmt.verb && stmt.verb.id) ? String(stmt.verb.id) : '';
      if (!verb) { return false; }
      // Enviar eventos relevantes para progreso/nota.
      if (verb.indexOf('completed') !== -1) return true;
      if (verb.indexOf('answered') !== -1) return true;
      if (verb.indexOf('attempted') !== -1) return true;
      if (verb.indexOf('progressed') !== -1) return true;
      // Si trae score, enviar.
      var score = stmt && stmt.result && stmt.result.score;
      if (score && (score.raw !== undefined || score.max !== undefined)) return true;
    } catch(e){}
    return false;
  }

  function post(stmt){
    if (pending) { queued = stmt; return; }
    var now = Date.now();
    if (now - lastSentAt < MIN_INTERVAL_MS) { queued = stmt; return; }
    pending = true;
    lastSentAt = now;

    var payload = { lesson_id: lessonId, content_id: contentId, statement: (stmt || {}) };
    fetch(restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': String(nonce)
      },
      body: JSON.stringify(payload)
    })
      .catch(function(){})
      .finally(function(){
        pending = false;
        if (queued) {
          var next = queued;
          queued = null;
          setTimeout(function(){ post(next); }, 250);
        }
      });
  }

  function bind(){
    try {
      if (!window.H5P || !window.H5P.externalDispatcher || !window.H5P.externalDispatcher.on) { return false; }
      window.H5P.externalDispatcher.on('xAPI', function(event){
        try {
          var stmt = event && event.data && event.data.statement ? event.data.statement : null;
          if (!stmt || !shouldSend(stmt)) { return; }
          post(stmt);
        } catch(e){}
      });
      return true;
    } catch(e){}
    return false;
  }

  var tries = 0;
  var timer = setInterval(function(){
    tries++;
    if (bind()) { clearInterval(timer); }
    if (tries > 30) { clearInterval(timer); }
  }, 1000);
})();
