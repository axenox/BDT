<?php
namespace axenox\BDT\Tests\Behat\Contexts;

use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Behat\Context\Context;
use Behat\MinkExtension\Context\RawMinkContext;
use PHPUnit\Framework\Assert;

class BackendErrorGuardContext extends RawMinkContext implements Context
{
    private bool $apiSnifferInstalled = false;

    /**
     * Safely ensure the JS API error sniffer is installed.
     * - Idempotent: runs only once per scenario.
     * - Defensive: returns silently if document/driver is not ready yet.
     */
    private function ensureApiSnifferInstalled(): void
    {
        if ($this->apiSnifferInstalled) return;

        $session = $this->getSession();
        if (!$session || !method_exists($session, 'executeScript') || !method_exists($session, 'evaluateScript')) return;

        // Verify a document context exists before injecting
        try {
            if (!$session->evaluateScript('return !!(window && window.document);')) return;
        } catch (\Throwable $e) { return; }

        // If already present, mark and return
        try {
            if ($session->evaluateScript('return !!window.__apierrq__;')) {
                $this->apiSnifferInstalled = true;
                return;
            }
        } catch (\Throwable $e) { /* continue to inject */ }

        try {
            $js = <<<'JS'
(function(){
  if (window.__apierrq__) return; // prevent duplicate installs
  window.__apierrq__ = [];

  // --- helpers (small & readable) ------------------------------------------
  function safeJSON(txt){ try{ return JSON.parse(txt); }catch(_){ return null; } }
  function pickErrorFromBody(bodyText){
    // 1) If full JSON with {error:{...}}
    var full = safeJSON(bodyText);
    if (full && full.error) return full.error;

    // 2) Regex: "error": { ... }
    var m = bodyText && bodyText.match(/"error"\s*:\s*(\{[\s\S]*?\})/i);
    if (m){
      var obj = safeJSON('{"error":'+m[1]+'}');
      if (obj && obj.error) return obj.error;
    }

    // 3) Heuristic: common fields (best-effort)
    var code = (bodyText.match(/"code"\s*:\s*"([^"]+)"/i)||[])[1]||null;
    var logid = (bodyText.match(/"logid"\s*:\s*"([^"]+)"/i)||[])[1]||null;
    var title = (bodyText.match(/"title"\s*:\s*"([^"]+)"/i)||[])[1]||null;
    var message = (bodyText.match(/"message"\s*:\s*"([^"]+)"/i)||[])[1]||null;

    // Fallback phrase for SQL errors
    if (!message){
      var m2 = bodyText.match(/(SQL\s+query\s+failed[^\\n]*|Invalid column name '.*?')/i);
      if (m2) message = m2[1];
    }
    if (code || logid || title || message){
      return { code: code, logid: logid, title: title, message: message };
    }
    return null;
  }
  function push(src, url, status, bodyText){
    var hasHttpErr = (typeof status==='number') && status>=400;
    var err = pickErrorFromBody(bodyText||'');
    if (hasHttpErr || err){
      err = err||{};
      window.__apierrq__.push({
        source: src,
        url: url||'',
        httpStatus: (typeof status==='number')?status:null,
        code: err.code||null,
        logid: err.logid||null,
        title: err.title||null,
        type: err.type||null,
        message: err.message||((bodyText||'').slice(0,2000)||null)
      });
    }
  }

  // --- fetch patch ----------------------------------------------------------
  var _fetch = window.fetch;
  if (typeof _fetch === 'function'){
    window.fetch = async function(){
      try{
        var res = await _fetch.apply(this, arguments);
        try{
          var t = await res.clone().text();
          push('fetch', res.url, res.status, t);
        } catch(_){ push('fetch', res && res.url, res && res.status, ''); }
        return res;
      }catch(err){
        // Network failure (status likely 0)
        push('fetch', (arguments[0] && arguments[0].toString())||'', 0, String(err && err.message || 'fetch error'));
        throw err;
      }
    };
  }

  // --- XHR patch ------------------------------------------------------------
  var _open = XMLHttpRequest.prototype.open;
  var _send = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function(method, url){
    this.__url = url;
    return _open.apply(this, arguments);
  };
  function report(self, note){
    var body=''; try{ body=self.responseText; }catch(_){}
    var st = (typeof self.status==='number') ? self.status : 0;
    push('xhr', self.__url||'', st, body||note||'');
  }
  XMLHttpRequest.prototype.send = function(){
    this.addEventListener('load', function(){ report(this); });
    this.addEventListener('error', function(){ report(this, 'XMLHttpRequest error'); });
    this.addEventListener('timeout', function(){ report(this, 'XMLHttpRequest timeout'); });
    this.addEventListener('abort', function(){ report(this, 'XMLHttpRequest abort'); });
    return _send.apply(this, arguments);
  };

  // --- global JS errors (optional but useful) -------------------------------
  window.addEventListener('error', function(e){
    try{ push('js', location.href, 0, String(e && e.message || 'JS error')); }catch(_){}
  }, true);
  window.addEventListener('unhandledrejection', function(e){
    try{
      var r=e && e.reason, msg=r && (r.message||r.toString&&r.toString()) || 'unhandledrejection';
      push('js', location.href, 0, String(msg));
    }catch(_){}
  }, true);
})();
JS;
            $session->executeScript($js);
            $this->apiSnifferInstalled = true;
        } catch (\Throwable $e) {
            // Best-effort: will try again in the next step
            return;
        }
    }

    /** 
     * @AfterStep 
     */
    public function assertNoServerSideErrors(AfterStepScope $scope): void
    {
        // Ensure sniffer is present (safe to call again)
        $this->ensureApiSnifferInstalled();

        $apiErrs = $this->popApiErrors();
        $netErrs = $this->getPerfErrors();
        $docErrs = $this->detectPageSideErrors();

        $errors = [];
        foreach (array_merge($apiErrs, $netErrs, $docErrs) as $e) {
            $errors[] = [
                'source'  => $e['source']     ?? 'UNKNOWN',
                'url'     => $e['url']        ?? null,
                'status'  => $e['httpStatus'] ?? null,
                'code'    => $e['code']       ?? null,
                'logid'   => $e['logid']      ?? null,
                'title'   => $e['title']      ?? null,
                'type'    => $e['type']       ?? null,
                'message' => $e['message']    ?? null,
            ];
        }

        // 1) Keep full detail centrally if you like (optional):
        $em = \axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager::getInstance();
        
        // 2) Set transient flag for this step (formatter will consume and fail)
        $em->setTransientDownstreamErrors($errors);
    }

    /**
     * Pull-and-clear the JS-side API error queue populated by the sniffer.
     * Returns empty array if the sniffer is not present or JS is unavailable.
     */
    private function popApiErrors(): array
    {
        $session = $this->getSession();
        if (!$session || !method_exists($session, 'evaluateScript') || !method_exists($session, 'executeScript')) return [];
        try {
            if (!$session->evaluateScript('return !!window.__apierrq__;')) return [];
            $json = $session->evaluateScript('return JSON.stringify(window.__apierrq__||[])');
            $session->executeScript('if (window.__apierrq__) window.__apierrq__ = [];');
            return json_decode((string)$json, true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }


    /**
     * Read Chrome DevTools "performance" logs via Selenium to catch:
     * - Top-level navigation "Document" 4xx/5xx
     * - XHR/Fetch responses 4xx/5xx
     * - Transport-level failures (Network.loadingFailed)
     */
    private function getPerfErrors(): array
    {
        $session = $this->getSession();
        if (!$session) return [];
        $driver = $session->getDriver();
        if (!$driver || !method_exists($driver, 'getWebDriverSession')) return [];

        try { $raw = $driver->getWebDriverSession()->log('performance'); }
        catch (\Throwable $e) { return []; }

        $errs = [];
        foreach ($raw as $entry) {
            $msg = json_decode($entry['message'] ?? '{}', true)['message'] ?? [];
            $method = $msg['method'] ?? '';
            $p = $msg['params'] ?? [];

            if ($method === 'Network.responseReceived') {
                $resp = (array)($p['response'] ?? []);
                $status = (int)($resp['status'] ?? 0);
                if ($status >= 400) {
                    $errs[] = [
                        'kind'       => 'resp',
                        'resType'    => (string)($p['type'] ?? ''),
                        'status'     => $status,
                        'statusText' => (string)($resp['statusText'] ?? ''),
                        'url'        => (string)($resp['url'] ?? ''),
                    ];
                }
            } elseif ($method === 'Network.loadingFailed') {
                $errs[] = [
                    'kind'      => 'failed',
                    'errorText' => (string)($p['errorText'] ?? ''),
                    'canceled'  => (bool)($p['canceled'] ?? false),
                ];
            }
        }
        return $errs;
    }

    /**
     * @BeforeStep
     */
    public function beforeEveryStep(BeforeStepScope $scope): void
    {
        // Ensure fetch/XHR sniffer is installed as early as possible each step.
        $this->ensureApiSnifferInstalled();
    }

    /**
     * Scan the current DOM for visible error indicators rendered by the app.
     * This covers cases where the server error is rendered into the page (no XHR).
     */
    private function detectPageSideErrors(): array
    {
        $session = $this->getSession();
        if (!$session || !method_exists($session, 'evaluateScript')) return [];

        try {
            $js = <<<'JS'
(function(){
  var out = [];

  // Common error containers (extend with your UI classes)
  var selectors = [
    '.sapUiMessageStripError', '.sapMMessageBoxError',
    '.alert-danger', '.alert-error', '.ui5-error', '#app-error', '.notification-error'
  ];
  try {
    selectors.forEach(function(s){
      document.querySelectorAll(s).forEach(function(el){
        var t = (el.innerText || el.textContent || '').trim();
        if (t) out.push("[DOM] "+s+" → "+t.replace(/\s+/g,' '));
      });
    });
  }catch(_){}

  // Heuristic: scan body text for SQL/exception markers
  try {
    var body = (document.body && (document.body.innerText || document.body.textContent) || '').trim();
    if (body) {
      var pats = [
        /(sql\s+error|sql\s+query\s+failed|database\s+error)/i,
        /(invalid column name\s+'[^']+')/i,
        /(exception|stack\s*trace)/i,
        /\b(logid|log\s*id|x-request-id)\b[:\s#-]*([A-F0-9-]{6,})/i
      ];
      pats.forEach(function(rx){
        if (rx.test(body)) {
          out.push("[DOM] Body match "+rx+" → "+ body.replace(/\s+/g,' ').slice(0,400));
        }
      });
    }
  }catch(_){}

  return JSON.stringify(out);
})();
JS;
            $json = $session->evaluateScript($js);
            $arr = json_decode((string)$json, true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}