var ZWM;
(function() {
	var $ = jQuery;

	ZWM = function(settings) {
		zsc.Plugin.call(this, settings);
	}

	ZWM.prototype = Object.create(zsc.Plugin.prototype);
	$.extend(ZWM.prototype, {
		/* Begin overrides */

		init: function(args) {
			var _ = this;
			zsc.Plugin.prototype.init.call(_, args);

			// Database refresh button
			$('#refreshBtn').click(function() {
				_.ajax({
					data: {action: 'zwm_refresh'},
				});
			});
		},
	});
})();
