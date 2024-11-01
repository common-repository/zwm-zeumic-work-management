var jsgrid_field_name;

(function() {
	if (typeof jsGrid === 'undefined') {
		return;
	}
	var $ = jQuery;

	var CustomerNotesField = function (config) {
		jsGrid.fields.textarea.call(this, config);
	};
	CustomerNotesField.prototype = new jsGrid.fields.textarea({
		editTemplate: function(notes, item) {
			this._has_order = !!item.order_item_id;
			if (!this._has_order) {
				// If the task is not associated with a WC order, this cannot be edited
				return $('<i>This task is not linked to a WooCommerce order item.</i>');
			}
			return jsGrid.fields.textarea.prototype.editTemplate.call(this, notes, item);
		},

		editValue: function() {
			if (!this._has_order) {
				return null;
			}
			return jsGrid.fields.textarea.prototype.editValue.call(this);
		}
	});
	jsGrid.fields[jsgrid_field_name] = CustomerNotesField;
})();