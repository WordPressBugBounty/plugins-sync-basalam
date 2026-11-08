/* Shared bootstrap. Page controllers are registered explicitly by ScriptModules. */
(function (window) {
  "use strict";
  if (window.WoosalamGateBoot) return;
  var started = Object.create(null);
  window.WoosalamGateBoot = {
    claim: function (name) {
      if (started[name]) return false;
      started[name] = true;
      return true;
    }
  };
})(window);
