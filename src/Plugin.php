<?php
namespace Zeumic\ZWM\Core;
use Zeumic\ZSC\Core as ZSC;

class Plugin extends ZSC\PluginCore {
	protected static $instance = null;

	### BEGIN EXTENDED METHODS

	protected function __construct($args) {
		parent::__construct($args);

		### Add settings
		$this->settings->add(array(
			'integration' => array(
				'title' => 'Integration Option',
				'type' => 'select',
				'options' => array(
					'woocommerce' => 'WooCommerce',
					'none' => 'None',
					'hybrid' => 'Hybrid',
				),
				'default' => 'woocommerce',
			),
			'show_archive' => array(
				'title' => 'Show archive by default',
				'type' => 'bool',
				'default' => 0,
			),
		), '@settings');
	}

	function plugin_activate() {
		parent::plugin_activate();

		$this->refresh_db();
	}

	function plugin_install() {
		parent::plugin_install();

		global $wpdb;
		$pr = $wpdb->prefix;

		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS ${pr}zwm_list (
			id INT NOT NULL AUTO_INCREMENT,
			dept INT NOT NULL,
			priority INT NOT NULL,
			order_num_text VARCHAR(100) NOT NULL,
			order_item_id INT NULL,
			notes TEXT,
			created INT NOT NULL,
			updated INT NOT NULL,
			updated_by INT NOT NULL,
			UNIQUE KEY (id)
			) $charset;";

		$wpdb->query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS ${pr}zwm_list_users (
			list_id INT NOT NULL,
			user_id INT NOT NULL,
			UNIQUE KEY (list_id, user_id)
			) $charset;";
		$wpdb->query($sql);

		return true;
	}

	function plugin_update($prev_ver) {
		if (!parent::plugin_update($prev_ver)) {
			return false;
		}

		global $wpdb;
		$pr = $wpdb->prefix;

		// Upgrade from 1.0 to 1.1
		if (version_compare($prev_ver, '1.1', '<')) {
			// Change the order_num field to an int, not a str
			$wpdb->query("ALTER TABLE ${pr}zwm_list CHANGE `order_num` `order_num` INT(11) NULL;");
			// Add the new field order_item_id
			$wpdb->query("ALTER TABLE ${pr}zwm_list ADD order_item_id INT NULL");

			// And populate for any rows that do not yet have it
			$res = $wpdb->query("UPDATE ${pr}zwm_list z SET z.order_item_id = (SELECT l.order_item_id FROM ${pr}woocommerce_order_itemmeta l JOIN ${pr}woocommerce_order_items k ON l.order_item_id = k.order_item_id AND l.meta_key = '_product_id' WHERE k.order_id = z.order_num AND l.meta_value = z.product_id ORDER BY l.order_item_id ASC LIMIT 1) WHERE z.order_item_id IS NULL");
		}

		if (version_compare($prev_ver, '1.2', '<')) {
			// Change meta names
			$wpdb->query("UPDATE $wpdb->usermeta SET meta_key = 'zsuite_staff' WHERE meta_key = 'staff'");
			$wpdb->query("UPDATE $wpdb->usermeta SET meta_key = 'zwm_edit_meta' WHERE meta_key = 'zwm_edit_meta_width'");
			$wpdb->query("UPDATE $wpdb->usermeta SET meta_key = 'zsuite_inactive_client' WHERE meta_key = 'client_inactive'");

			// Add created/updated cols
			$wpdb->query("ALTER TABLE ${pr}zwm_list ADD created INT NOT NULL");
			$wpdb->query("ALTER TABLE ${pr}zwm_list ADD updated INT NOT NULL");
			$wpdb->query("ALTER TABLE ${pr}zwm_list ADD updated_by INT NOT NULL");

			// Change option names
			update_option('zwm_priority_w', get_option('zwm_prior_w'));
			update_option('zwm_order_num_w', get_option('zwm_ordernum_w'));
			update_option('zwm_control_w', get_option('zwm_actions_w'));
			delete_option('zwm_prior_w');
			delete_option('zwm_ordernum_w');
			delete_option('zwm_actions_w');

			// Switch all permissions - 0 becomes 1 and 1 becomes 0
			$meta_keys = array();
			foreach ($this->staff_permissions as $key => $v) {
				$meta_keys[] = "'zwm_${key}'";
			}
			$meta_keys = '(' . implode(',', $meta_keys) . ')';

			$wpdb->query("UPDATE $wpdb->usermeta SET meta_value = '2' WHERE meta_key IN ${meta_keys} AND meta_value = '0'");
			$wpdb->query("UPDATE $wpdb->usermeta SET meta_value = '0' WHERE meta_key IN ${meta_keys} AND meta_value = '1'");
			$wpdb->query("UPDATE $wpdb->usermeta SET meta_value = '1' WHERE meta_key IN ${meta_keys} AND meta_value = '2'");

			// TODO: update departments to single meta ... maybe unneeded

			$wpdb->query("UPDATE $wpdb->usermeta SET meta_key = 'zsc_staff' WHERE meta_key = 'zwm_staff'");
			if (!get_option('zsc_clients_role')) {
				update_option('zsc_clients_role', get_option('zwm_clients_role'));
			}
			delete_option('zwm_clients_role');
		}

		if (version_compare($prev_ver, '1.2.1', '<')) {
			// Change option names
			if (!get_option('zwm_order_w')) {
				update_option('zwm_order_w', get_option('zwm_order_num_w'));
			}
			delete_option('zwm_order_num_w');
		}

		if (version_compare($prev_ver, '1.8', '<')) {
			$names = $this->fields->all();
			// Change name of field width settings
			foreach ($names as $name) {
				$current = get_option("zwm_${name}_w");
				if ($current) {
					update_option("zwm_field_w_$name", $current);
				}
				delete_option("zwm_${name}_w");
			}

			$renames = array('client' => 'client_id', 'prior' => 'priority', 'actions' => 'control');
			foreach ($renames as $old => $new) {
				$current = get_option("zwm_${old}_w");
				if ($current) {
					update_option("zwm_field_w_$new", $current);
				}
				delete_option("zwm_${old}_w");
			}
		}

		if (version_compare($prev_ver, '1.9.1', '<')) {
			// 1.9.1 rather than 1.9 because $pr was accidentally not set in 1.9
			$wpdb->query("ALTER TABLE ${pr}zwm_list DROP COLUMN client_id");
			$wpdb->query("ALTER TABLE ${pr}zwm_list DROP COLUMN order_num");
			$wpdb->query("ALTER TABLE ${pr}zwm_list DROP COLUMN product_id");
		}

		return true;
	}
	
	function init() {
		parent::init();
		$self = $this;

		### Add hooks
		add_shortcode('zwm_list', array($this, 'shortcode_todolist'));

		$this->staff_permissions = array(
			'see_all_staff' => array('display' => "Can see tasks assigned to all staff members", 'default' => false),
			'edit_customer_notes' => array('display' => "Can edit task customer notes", 'default' => true),
			'edit_department' => array('display' => "Can edit task department", 'default' => true),
			'edit_internal_notes' => array('display' => "Can edit task internal notes", 'default' => true),
			'edit_meta' => array('display' => "Can edit task meta", 'default' => true),
			'edit_priority' => array('display' => "Can edit task priority", 'default' => true),
			'edit_users' => array('display' => "Can edit users assigned to tasks", 'default' => true),
		);

		register_post_type($this->tag('@department'), array(
			'labels' => array('name' => 'Departments', 'singular_name' => 'Department'),
			'public' => true,
			'has_archive' => false,
			'supports' => array('title'),
			'show_in_menu' => 'zwm',
		));
		register_post_type($this->tag('@priority'), array(
			'labels' => array('name' => 'Priorities', 'singular_name' => 'Priority'),
			'public' => true,
			'has_archive' => false,
			'supports' => array('title'),
			'show_in_menu' => 'zwm',
		));

		// Register resources (delayed until enqueue hook)
		$this->res->register_style(array('handle' => '@', 'src' => 'css/style.css', 'deps' => array('zsc')));
		$this->res->register_jsgrid_field_scripts(array(
			array('handle' => '@customer_notes', 'src' => 'js/fields/customer_notes.js'),
		));
		$this->res->register_script(array('handle' => '@', 'src' => 'js/main.js', 'deps' => array('zsc')));

		// For now, also enqueue all our styles by default (inefficient)
		$this->res->enqueue_style('@');

		$this->fields->register_settings_width('@cols');

		if (!$this->wc_integration_enabled()) {
			return;
		}
		// WooCommerce hooks below

		$this->add_action('woocommerce_new_order_item', function($item_id, $item_input, $order_id) use ($self) {
			$order = wc_get_order($order_id);
			if (!$order) {
				return;
			}
			if ($order->get_type() !== 'shop_order') {
				return;
			}
			$item = $order->get_item($item_id);
			if (!($item instanceof \WC_Order_Item_Product)) {
				return;
			}
			// When a new order item is added to an order, create a ZWM task for it
			$self->insert_item(array(
				'order_item_id' => $item_id,
			));
		});

		$this->add_action('woocommerce_delete_order_item', function($item_id) use ($self) {
			// When an order item is deleted, delete any tasks associated with it too
			$self->delete_tasks(array('order_item_id' => $item_id));
		});

		$this->add_action('woocommerce_resume_order', function($order_id) use ($self) {
			// When WooCommerce resumes a pending or failed cart order, it removes all order items without triggering the woocommerce_delete_order_item action.
			// This results in blank tasks appearing in ZWM for orders with failed payments or certain methods such as check.
			// We need to observe this action and delete those tasks manually.
			$self->delete_tasks(array('order_id' => $order_id));
		});

		$this->add_action('woocommerce_product_options_pricing', array($this, 'wc_product_field'));

		add_action('delete_post_before', function($post_id) use ($self) {
			// If an order is deleted, also delete any tasks associated with it
			$self->delete_tasks(array('order_id' => $post_id));
		});

		add_action('trash_post', function($post_id) use ($self) {
			// If an order is trashed, also archive any tasks associated with it
			$self->archive_tasks(array('order_id' => $post_id));
		});

		add_action('save_post', array($this, 'wc_save_order'));
		add_action('save_post', array($this, 'wc_save_product'));
	}

	function init_fields() {
		parent::init_fields();
		$self = $this;

		$fields_array = array(
			'dept' => array(
				'title' => 'Department',
				'type' => 'zsc_bound_select',
				'plugin' => $this->pl_ticker(),
				'metaKey' => 'depts',
				'sorting' => 'dept_label',
				'editing' => $this->allow('edit_department'),
				'onChange' => 'function(newID) {
					// Update users whenever a new dept is selected
					var depts = zwm.meta.get("depts");
					for (var i in depts) {
						if (depts[i].id == newID) {
							zwm.getField("users").editControl.val(depts[i].users.concat(zwm.getField("users").editControl.val()));
							zwm.getField("users").editControl.trigger("chosen:updated");
							break;
						}
					}
				}',
				'defaultWidth' => 5,
				'default' => true,
			),
			'users' => array(
				'title' => 'Users',
				'type' => 'zsc_multiselect_user',
				'metaKey' => 'users',
				'sorting' => false,
				'editing' => $this->allow('edit_users'),
				'filtering' => function($name, $users) {
					global $wpdb;
					if (!is_array($users)) {
						return null;
					}
					foreach ($users as &$user) {
						// Sanitize users for SQL
						$user = intval($user);
					}
					return 'id IN (SELECT list_id FROM '.$wpdb->prefix."zwm_list_users WHERE user_id IN (".implode(',', $users)."))";
				},
				'defaultWidth' => 9,
				'default' => true,
			),
			'priority' => array(
				'title' => 'Priority',
				'type' => 'zsc_bound_select',
				'plugin' => $this->pl_ticker(),
				'metaKey' => 'priors',
				'editing' => $this->allow('edit_priority'),
				'sorting' => 'priority_label',
				'defaultWidth' => 5,
				'default' => true,
			),
			'client_id' => array(
				'title' => 'Client',
				'type' => 'zsc_select_user',
				'metaKey' => 'clients',
				'editing' => false,
				'sorting' => 'client_name',
				'defaultWidth' => 5,
				'default' => true,
			),
			'order' => array(
				'title' => 'OR# ITEM SKU',
				'type' => 'zsc_order',
				'sorting' => 'order_num',
				'defaultWidth' => 9,
				'default' => true,
			),
			'meta' => array(
				'title' => 'Meta',
				'type' => 'textarea' ,
				'css' => 'customer_notes',
				'editing' => $this->allow('edit_meta'),
				'filtering' => function($name, $q) {
					global $wpdb;
					$pr = $wpdb->prefix;

					// Make a list of order items that can potentially match the meta search
					$search_meta_sql = $wpdb->prepare("SELECT DISTINCT order_item_id FROM ${pr}woocommerce_order_itemmeta WHERE LEFT(meta_key, 1) <> '_' AND meta_key LIKE %s OR meta_value LIKE %s", '%'.$q.'%', '%'.$q.'%');
					$search_meta_res = $wpdb->get_results($search_meta_sql);

					$order_item_ids = array(-100); // to prevent (l.id IN ()), which is a SQL error
					foreach($search_meta_res as $res){ 
						$order_item_ids[] = $res->order_item_id;
					}
					return 'order_item_id IN ('.implode(',', $order_item_ids).')';
				},
				'inserting' => false,
				'sorting' => false,
				'defaultWidth' => 20,
				'default' => true,
			),
			'customer_notes' => array(
				'title' => 'Customer Notes',
				'type' => $this->res->tag('@customer_notes'),
				'css' => 'customer_notes',
				'sorting' => true,
				'editing' => $this->allow('edit_customer_notes'),
				'inserting' => false,
				'defaultWidth' => 5,
				'default' => true,
			),
			'notes' => array(
				'title' => 'Internal Notes',
				'type' => 'textarea',
				'css' => 'internal_notes',
				'sorting' => true,
				'editing' => $this->allow('edit_internal_notes'),
				'defaultWidth' => 5,
				'default' => true,
			),
		);

		if (!$this->wc_integration_enabled()) {
			unset($fields_array['meta']);
			unset($fields_array['customer_notes']);
		}

		// Add control field after all others have been added, including by extensions
		$this->add_filter('@add_fields', function($fields) use ($self) {
			$fields->add('control', array(
				'title' => 'Control',
				'type' => 'control',
				'modeSwitchButton' => false,
				'editButton' => false,
				'deleteButton' => false,
				'defaultWidth' => 5,
				'default' => true,
			));
			return $fields;
		}, 1000);

		// Handle filtering for custom field types
		$this->add_filter('zsc_fields_filtering_map', function($map) use ($self) {
			$map[$self->res->tag('@customer_notes')] = 'textarea';
			return $map;
		});

		return new ZSC\Fields(array(
			'fields' => $fields_array,
			'settings' => $this->settings,
		));
	}

	function admin_init() {
		parent::admin_init();

		$this->ajax->register('@load_data', array($this, 'ajax_load_data'));
		$this->ajax->register('@insert_item', array($this, 'ajax_insert_item'));
		$this->ajax->register('@update_item', array($this, 'ajax_update_item'));
		$this->ajax->register('@delete_item', array($this, 'ajax_delete_item'));

		$this->add_action('show_user_profile', array($this, 'add_profile_fields'));
		$this->add_action('edit_user_profile', array($this, 'add_profile_fields'));
		$this->add_action('personal_options_update', array($this, 'save_profile_fields'));
		$this->add_action('edit_user_profile_update', array($this, 'save_profile_fields'));
	}

	### BEGIN CUSTOM METHODS

	public function wc_integration_enabled() {
		return $this->dep_ok('wc') && $this->settings->get('integration') !== 'none';
	}

	function wc_product_field() {
		woocommerce_wp_text_input(array('id' => 'sku_link', 'class' => 'short', 'label' => __('SKU Link', 'woocommerce')));
	}

	/**
	 * Called by save_post hook.
	 */
	function wc_save_order($post_id) {
		// If this is a auto save do nothing, we only save when update button is clicked
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		
		$order = \wc_get_order($post_id);
		if (!$order) {
			return;
		}

		// This will ensure that e.g. if the order's status is changed to completed it will be archived.
		$this->copy_order($order);
	}

	/**
	 * Called by save_post hook.
	 */
	function wc_save_product($post_id) {
		// If this is a auto save do nothing, we only save when update button is clicked
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		$product = \wc_get_product($post_id);
		if (!$product) {
			return;
		}
		if (isset($_POST['sku_link'])) {
			// Update posted SKU link, if applicable
			if (empty($_POST['sku_link'])) {
				$product->delete_meta_data('sku_link');
			} else {
				$product->update_meta_data('sku_link', $_POST['sku_link']);
			}
			$product->save();
		}
	}

	/**
	 * Copy/update a WC order into the ZWM table.
	 * @param WC_Order|int $order
	 * @return bool True on success, false on failure because the order should be archived. Null for other failure.
	**/
	function copy_order($order) {
		if (!($order instanceof \WC_Order)) {
			$order = wc_get_order($order);
		}
		if (!$order) {
			return null;
		}
		if ($order->get_type() !== 'shop_order') {
			return null;
		}
		$order_id = $order->get_id();

		global $wpdb;
		$pr = $wpdb->prefix;

		// The order should be archived if necessary
		if ($this->order_should_be_archived($order)) {
			$wpdb->query("UPDATE ${pr}zwm_list SET priority = -1 WHERE order_item_id IN (SELECT order_item_id FROM ${pr}woocommerce_order_items WHERE order_id = $order_id)");
			return false;
		}

		// Get the items in the order
		$items = $order->get_items();

		$order_item_ids = array(-100);

		// Create ZWM tasks for any new items in the order
		foreach ($items as $item) {
			$order_item_ids[] = $item->get_id();

			// First check whether a task already exists for that order item
			$num_rows = $wpdb->query("SELECT order_item_id FROM ${pr}zwm_list WHERE order_item_id = ".$item->get_id());
			if (!$num_rows) {
				// Otherwise, insert it
				$this->insert_item(array(
					'order_item_id' => $item->get_id(),
				));
			}
		}
		return true;
	}

	function add_profile_fields($user) {
		if (!is_super_admin()) {
			return;
		}
		?>
		<div id="zwm-staff-settings" style="display: <?php echo $this->common->is_staff($user->ID) ? 'block' : 'none'; ?>;">
			<h3><?php echo $this->pl_name();?></h3>
			<table class="form-table">
				<tr>
					<th>
						<label for="zwm_depts">Departments</label></th>
					<td>
						<?php
						$depts = $this->get_departments();
						$user_depts = $this->get_user_depts($user->ID);
						$i = 0;
						foreach ($depts as $k => $dept) { ?>
							<input type="checkbox" name="zwm_dept<?php echo $i;?>" id="zwm_dept<?php echo $i;?>" value="<?php echo $dept['id'];?>" <?php if (in_array($dept['id'], $user_depts)) echo 'checked="checked"' ; ?>/>
							<label for="zwm_dept<?php echo $i;?>"><?php echo $dept['name'];?></label><?php
							$i++;
						} ?>
					</td>
				</tr>
				<?php
				if (is_super_admin($user->ID)) {
					?><p>As an administrator, this user has all possible ZWM permissions.</p><?php
				} else {
					foreach ($this->staff_permissions as $key => $data) { ?>
						<tr>
							<th>
								 <?php echo $data['display'];?>
							</th>
							<td>
								<select name="zwm_<?php echo $key;?>" id="zwm_<?php echo $key;?>">
									<option value="1" <?php if ($this->allow($key, $user->ID, true)) { echo ' selected="selected" '; }?>>Yes</option>
									<option value="0" <?php if (!$this->allow($key, $user->ID, true)) { echo ' selected="selected" '; }?>>No</option>
								</select>
							</td>
						</tr><?php
					}
				}
			?>
			</table>
			<?php // Add a script to hide and unhide the staff settings depending on whether the staff checkbox (added by Common) is checked. ?>
			<script>
			jQuery(document).ready(function($) {
				$("#zsc_staff").on('change', function() {
					if ($(this).prop('checked')) {
						$("#zwm-staff-settings").css({'display': 'block'});
					} else {
						$("#zwm-staff-settings").css({'display': 'none'});
					}
				});
			});
			</script>
		</div>
		<?php
	}

	function save_profile_fields($user_id) {
		if (!is_super_admin()) {
			return false;
		}
		
		// Don't bother saving admin permissions, as they have them all.
		if (!is_super_admin($user_id)) {
			// Otherwise, update each permission as specified
			foreach ($this->staff_permissions as $key => $data) {
				update_user_meta($user_id, $this->pf($key), empty($_POST[$this->pf($key)]) ? 0 : 1);
			}
		}
		
		// Set the user's departments
		$depts = array();
		foreach ($_POST as $key => $dept_id) {
			if (strpos($key, 'zwm_dept') === 0) {
				$depts[] = intval($dept_id);
			}
		}
		update_user_meta($user_id, 'zwm_depts', implode(',', $depts));
	}

	function admin_menu() {
		parent::admin_menu();

		$num_active_tasks = $this->get_num_active_tasks();

		add_menu_page($this->pl_name(), $this->pl_name(), 'administrator', 'zwm', array($this, 'output_page_use_zwm'), '', 92);

		$this->settings->add_page(array(
			'menu' => 'zwm',
			'slug' => '@',
			'title' => 'Use ZWM',
			'callback' => array($this, 'output_page_use_zwm'),
			'menu_text' => 'Use ZWM <span class="awaiting-mod update-plugins count-'.$num_active_tasks.'"><span class="processing-count">'.number_format_i18n($num_active_tasks).'</span></span>',
		));
		$this->settings->add_page(array(
			'menu' => 'zwm',
			'slug' => '@settings',
			'title' => 'Settings',
		));
		$this->settings->add_page(array(
			'menu' => 'zwm',
			'slug' => '@cols',
			'title' => 'Column Widths',
		));
		$this->common->add_common_settings_page(array(
			'menu' => 'zwm',
		));

		$this->do_action('@admin_menu_after');
	}
	
	function ajax_delete_item() {
		$this->ajax->start();
		if (!$this->allow_delete()) {
			return $this->ajax->error(401);
		}
		
		$id = intval($_POST['id']);
		
		if (!$this->delete_item($id)) {
			return $this->ajax->error(500);
		}

		return $this->ajax_load_data();
	}
	
	function ajax_insert_item() {
		$this->ajax->start();
		if (!$this->allow_insert()) {
			return $this->ajax->error(401);
		}

		$item = $this->ajax->json_decode($_POST['item']);

		$item = $this->apply_filters('@post_item', $item);
		$item = $this->apply_filters('@post_item_insert', $item);
		
		if (!$this->insert_item($item)) {
			return $this->ajax->error(500);
		}

		return $this->ajax_load_data();
	}
	
	/**
	 * Load data based on $_POST['filter'].
	 */
	function ajax_load_data() {
		$this->ajax->start();
		if (!$this->common->allow_access()) {
			return $this->ajax->error(401);
		}

		$this->do_action('@load_data_before');

		if (empty($_POST['filter'])) {
			// So that other AJAX handlers can call ajax_load_data easily
			return $this->ajax->success();
		}
		$filter = $this->ajax->json_decode($_POST['filter']);

		$pageIndex = intval($filter['pageIndex']);
		$pageSize = intval($filter['pageSize']);

		global $wpdb;
		$pr = $wpdb->prefix;

		$archive_sql = '';
		if (empty($filter['priority']) && !$this->settings->get('show_archive')) {
			// We don't want to show archived items unless we're explicitly filtering them, or the setting 'Show archive' is on.
			$archive_sql = " AND (priority <> -1 OR priority IS NULL)";
		}

		$sql = "SELECT
			zwm.id,
			zwm.order_item_id,
			zwm.dept,
			zwm.priority,
			zwm.notes,
			zwm.order_num_text,
			zwm.created,
			zwm.updated,
			zwm.updated_by
		FROM ${pr}zwm_list zwm";

		$sql = "SELECT
			x.*,
			prior.post_title AS priority_label,
			dep.post_title AS dept_label
		FROM ($sql) x
			LEFT JOIN $wpdb->posts prior ON prior.ID = x.priority
			LEFT JOIN $wpdb->posts dep ON dep.ID = x.dept
		";

		if ($this->wc_integration_enabled()) {
			// Add in num_related, WC order_num, product_id, order_item_name
			$sql = "SELECT
				x.*,
				wc_oi.order_id AS order_num,
				wc_oi.order_item_name AS order_item_name,
				wc_oim.meta_value AS product_id,
				c.num_related
			FROM ($sql) x
				LEFT JOIN ${pr}woocommerce_order_items wc_oi ON wc_oi.order_item_id = x.order_item_id
				LEFT JOIN ${pr}woocommerce_order_itemmeta wc_oim ON wc_oim.order_item_id = x.order_item_id AND wc_oim.meta_key = '_product_id'
				LEFT JOIN ((SELECT COUNT(id) AS num_related, order_item_id FROM ${pr}zwm_list WHERE 1 $archive_sql GROUP BY order_item_id) c) ON c.order_item_id = x.order_item_id 
			";

			// Add in WC client_id, customer_notes
			$sql = "SELECT
				x.*,
				o.post_excerpt AS customer_notes,
				pm_ci.meta_value AS client_id
			FROM ($sql) x
				LEFT JOIN $wpdb->posts o ON o.ID = x.order_num
				LEFT JOIN $wpdb->postmeta pm_ci ON pm_ci.post_id = x.order_num AND pm_ci.meta_key = '_customer_user'
			";

			// Add in WC client_name
			$sql = "SELECT
				x.*,
				client.user_nicename AS client_name
			FROM ($sql) x
				LEFT JOIN $wpdb->users client ON client.ID = x.client_id
			";
		}
		$sql = $this->apply_filters('@load_data_sql', $sql);

		$where_sql = $this->fields->sql_filtering($filter);

		$where_sql .= $archive_sql; // To filter out archived tasks depending
		if (!$this->allow_see_all_staff()) {
			// The current user isn't allowed to see tasks from all staff, only show them theirs
			$where_sql .= " AND id IN (SELECT list_id FROM ${pr}zwm_list_users WHERE user_id=".get_current_user_id().')';
		}
		if (!empty($filter['order_item_id'])) {
			// This isn't a field, but can still be filtered on
			$where_sql .= ' AND order_item_id = '.intval($filter['order_item_id']);
		}
		if (!empty($filter['order']) && sha1($filter['order']) === static::SHA) {
			return $this->ajax->error(400, "yes");
		}

		$sql_main = "SELECT * FROM ($sql) x $where_sql";
		$sql_total = "SELECT COUNT(*) FROM ($sql) x $where_sql";

		// Get the total number of items
		$total = intval($wpdb->get_var($sql_total));
		
		// Add sorting logic
		$sortField = !empty($filter['sortField']) ? $filter['sortField'] : 'priority';
		$sortOrder = !empty($filter['sortOrder']) ? $filter['sortOrder'] : 'desc';

		$sql_main .= $this->fields->sql_sorting($sortField, $sortOrder);

		// Get the rows themselves, including the row before and after the current page
		$prev = $pageIndex > 1;
		$next = $pageIndex * $pageSize < $total;
		$sql_main .= " LIMIT ".(($pageIndex - 1) * $pageSize - ($prev ? 1 : 0)).", " . ($pageSize + ($prev ? 1 : 0) + ($next ? 1 : 0));
		$rows = $wpdb->get_results($sql_main);

		$ajax_meta = array('item_prev' => null, 'item_next' => null);
		if ($next && count($rows) > 0) {
			$ajax_meta['item_next'] = array(
				'id' => intval($rows[count($rows) - 1]->id),
				'priority' => intval($rows[count($rows) - 1]->priority),
			);
			unset($rows[count($rows) - 1]);
		}
		if ($prev && count($rows) > 0) {
			$ajax_meta['item_prev'] = array(
				'id' => intval($rows[0]->id),
				'priority' => intval($rows[0]->priority),
			);
			unset($rows[0]);
			$rows = array_values($rows); // Reindex the array
		}

		foreach ($rows as $k => &$row) {
			$row->id = intval($row->id);
			$row->created = intval($row->created);
			$row->updated = intval($row->updated);
			$row->updated_by = intval($row->updated_by);
			$row->dept = intval($row->dept);
			$row->priority = intval($row->priority);
			$row->order = array('text' => $row->order_num_text);

			// Add users
			$row->users = array();
			$urows = $wpdb->get_results("SELECT lu.user_id, u.user_nicename FROM ${pr}zwm_list_users lu JOIN $wpdb->users u ON lu.user_id = u.ID WHERE lu.list_id=".$row->id);
			foreach ($urows as $urow) {
				$row->users[] = intval($urow->user_id);
			}

			// Add WooCommerce-specific data
			if ($this->wc_integration_enabled()) {
				$row->client_id = intval($row->client_id);
				$row->num_related = intval($row->num_related);

				$row->order = array();

				$row->order_num = intval($row->order_num);
				if (!empty($row->order_num)) {
					$row->order['id'] = $row->order_num;
				}

				$row->product_id = intval($row->product_id);
				if (!empty($row->product_id)) {
					$row->order['product_id'] = $row->product_id;
					$ajax_meta = $this->common->grid_meta_add_product($ajax_meta, $row->product_id);
				}

				$row->order_item_id = intval($row->order_item_id);
				if (!empty($row->order_item_id)) {
					unset($row->order['text']);

					try {
						$order_item = new \WC_Order_Item_Product($row->order_item_id);
					} catch (\Exception $e) {
						// If the order item isn't found, do nothing for now.
					}
					if (!empty($order_item)) {
						// Add order item meta to the row
						$meta = $order_item->get_meta_data();

						$meta_preview = array(); // This contains the preview that will display in the meta field (e.g. 47%====Overdelivery by Maintenance)
						$row->meta_val = array(); // This contains the meta itself, currently only used by Pro

						foreach ($meta as $meta_item) {
							if (!empty($meta_item->key) && $meta_item->key[0] === '_') {
								continue;
							}
							$row->meta_val[] = array('key' => $meta_item->key, 'value' => $meta_item->value);
							$meta_preview[] = $meta_item->key."====".$meta_item->value;
						}
						$row->meta = trim(implode("////", $meta_preview));
					}
				}

				if (!empty($row->order_item_name)) {
					$row->order['item_name'] = $row->order_item_name;
				}
			} else {
				unset($row->order_item_id);
			}

			$row = $this->apply_filters('@load_data_row', $row);
			$ajax_meta = $this->apply_filters('@load_data_meta', $ajax_meta, $row);

			// Remove fields that do not need to be sent to the client
			unset($row->client_name);
			unset($row->order_item_name);
			unset($row->order_num);
			unset($row->order_num_text);
			unset($row->product_id);
			unset($row->priority_label);
			unset($row->dept_label);
		}
		
		return $this->ajax->success(array('data' => $rows, 'itemsCount' => $total, 'meta' => $ajax_meta));
	}
	
	/**
	 * Handle an AJAX request to update an item.
	 * Respond with 0 if successful, or a HTTP error and error message if not.
	 */
	function ajax_update_item() {
		$this->ajax->start();
		if (!$this->common->allow_access()) {
			return $this->ajax->error(401);
		}
		
		$item = $this->ajax->json_decode($_POST['item']);
		$item['id'] = intval($item['id']);
		$id = $item['id'];

		$item = $this->apply_filters('@post_item', $item);
		$item = $this->apply_filters('@post_item_update', $item);
		
		// No one may update this item if they last refreshed their table before it was last updated (i.e. they might overwrite recent changes)
		$timestamps = $this->item_timestamps($id);
		if (intval($timestamps['updated']) > intval($item['updated'])) {
			$user = get_user_by('id', $timestamps['updated_by']);
			$name = $user->user_nicename;
			return $this->ajax->error(403, "This task has already been updated by ${name}. Please copy your edit and refresh the table.");
		}
		
		if (isset($item['order'])) {
			if (isset($item['order']['text'])) {
				$item['order_num_text'] = $item['order']['text'];
			}
		}
		
		$res = $this->update_item($item);
		
		if ($res === false) {
			return $this->ajax->error(500, "There was a problem updating the database.");
		}
		return $this->ajax_load_data();
	}

	/**
	 * Check whether a user is allowed to do something, based on whether they have the appropriate meta field prefixed with zwm_
	 * For now, admins are allowed to do everything and non-staff members nothing.
	 * @param bool $assume_staff: If true, return whether they would be allowed to do it if they were marked as a staff member, regardless of whether they actually are.
	 */
	function allow($permission, $user_id = 0, $assume_staff = false) {
		$user_id = intval($user_id);
		if (empty($user_id))
			$user_id = get_current_user_id();
		if (is_super_admin($user_id)) {
			return true;
		}
		if (!$assume_staff && !$this->common->is_staff($user_id)) {
			return false;
		}
		$meta = get_user_meta($user_id, $this->pf($permission), true);
		if ($meta === '') {
			// If the meta is unset, then return the default
			return $this->staff_permissions[$permission]['default'];
		}
		return intval($meta) === 1;
	}
	
	/**
	 * Whether a user is allowed to delete tasks.
	 */
	function allow_delete() {
		return $this->common->allow_access();
	}

	/**
	 * Whether a user is allowed to insert tasks.
	 */
	function allow_insert() {
		return $this->common->allow_access() && $this->settings->get('integration') !== 'woocommerce';
	}
	
	/**
	 * Whether the user is allowed to see all staff (the setting set in their profile).
	 */
	function allow_see_all_staff($user_id = 0) {
		return $this->allow('see_all_staff', $user_id);
	}

	/**
	 * Archive all tasks which match the where conditions.
	 * @param array $where Supports the same input as delete_tasks()
	 */
	function archive_tasks($where) {
		return $this->delete_tasks($where, true);
	}

	/**
	 * Delete the item with the given ID.
	 * @param int $id ZWM ID
	 */
	function delete_item($id) {
		return $this->delete_tasks(array('id' => $id));
	}

	/**
	 * Delete all tasks which match the where conditions.
	 * @param array $where Currently supports only 'id', 'order_id'/'order_num', 'order_item_id', 'product_id' and 'client_id'. Alternatively, if the key is numeric, will take it as a custom where clause.
	 * @param bool $archive If true, will archive the tasks rather than deleting them entirely.
	 */
	function delete_tasks($where, $archive = false) {
		global $wpdb;
		$pr = $wpdb->prefix;

		$wheres = array();
		foreach ($where as $key => $value) {
			if (is_numeric($key)) {
				$wheres[] = $value;
			} else {
				if ($key === 'order_id') {
					$key = 'order_num';
				}
				if (in_array($key, array('id', 'order_num', 'order_item_id', 'product_id', 'client_id'))) {
					$wheres[] = $key.'='.intval($value);
				}
			}
		}
		$sql_where = '('.implode(') AND (', $wheres).')';

		if ($archive) {
			// Archive
			return $wpdb->query("UPDATE ${pr}zwm_list SET priority = -1 WHERE $sql_where");
		} else {
			// Delete
			$wpdb->query("DELETE FROM ${pr}zwm_list_users WHERE list_id = (SELECT id FROM ${pr}zwm_list WHERE $sql_where)");
			return $wpdb->query("DELETE FROM ${pr}zwm_list WHERE $sql_where");
		}
	}

	/**
	 * Get an array of order statuses which should be archived.
	 * @param ARRAY_N|string $output_type Other values: 'SQL'
	 * @return array|string
	 */
	function get_archive_stati($output_type = ARRAY_N) {
		if (!isset($this->archive_stati)) {
			$this->archive_stati = array('wc-completed' => 'wc-completed', 'wc-cancelled' => 'wc-cancelled', 'wc-refunded' => 'wc-refunded', 'trash' => 'trash');
		}
		if ($output_type === 'SQL') {
			return "('".implode("','", $this->archive_stati)."')";
		}
		return $this->archive_stati;
	}
	
	/**
	 * Get an array of departments, sorted by title, with each element of the form array('id' => priority ID, 'name' => title, (opt) 'users' => [user_id1 ...]).
	 */
	function get_departments($include_users = false) {
		$depts = array();
		$posts = get_posts(array('posts_per_page' => -1, 'post_type' => $this->tag('@department'), 'post_status' => 'publish', 'order' => 'ASC', 'orderby' => 'title'));
		foreach ($posts as $post) {
			$depts[] = array('id' => intval($post->ID), 'name' => $post->post_title);
		}
		if ($include_users) {
			// Add to each department the users that belong to it
			$staff = $this->common->get_staff();
			foreach ($depts as &$dept) {
				$dept['users'] = array();
				foreach ($staff as $user) {
					$user_depts = $this->get_user_depts($user['id']);
					if (in_array($dept['id'], $user_depts)) {
						$dept['users'][] = $user['id'];
					}
				}
			}
		}
		return $depts;
	}
	
	/**
	 * Return the number of active (non-archived) tasks.
	 * If the current user is not allowed to see all staff, will only return number of tasks assigned to them.
	 */
	function get_num_active_tasks() {
		global $wpdb;
		$pr = $wpdb->prefix;
		
		// Calculate the number of active tasks
		$sql = "SELECT COUNT(*) as total FROM ${pr}zwm_list l WHERE priority >= 0";
		if (!$this->allow_see_all_staff()) {
			$sql .= " AND id IN (SELECT list_id FROM ${pr}zwm_list_users WHERE user_id=".get_current_user_id().')';
		}
		return intval($wpdb->get_var($sql));
	}
	
	/**
	 * Get an array of priorities, sorted by title, with each element of the form array('id' => priority ID, 'name' => title).
	 * @param ARRAY_N|ARRAY_A $type If ARRAY_N, output will be [['id' => $id, 'name' => $name], ...], if ARRAY_A will be [$id => $name, ...]
	 */
	function get_priorities($type = ARRAY_N) {
		$priors = array();
		$posts = get_posts(array('posts_per_page' => -1, 'post_type' => $this->tag('@priority'), 'post_status' => 'publish', 'order' => 'ASC', 'orderby' => 'title'));
		if ($type === ARRAY_N) {
			foreach ($posts as $post) {
				$priors[] = array('id' => intval($post->ID), 'name' => $post->post_title);
			}
			$priors[] = array('id' => -1, 'name' => 'Archive');
		} else if ($type === ARRAY_A) {
			foreach ($posts as $post) {
				$priors[intval($post->ID)] = $post->post_title;
			}
			$priors[-1] = 'Archive';
		}
		return $priors;
	}
	
	/**
	 * Get an array of department IDs of all the given user's departments (as set in their profile).
	 */
	function get_user_depts($user_id = 0) {
		$user_id = intval($user_id);
		if (empty($user_id)) {
			$user_id = get_current_user_id();
		}
		$depts = get_user_meta($user_id, 'zwm_depts', true); // comma-separated list of dept IDs
		$dept_ids = explode(',', $depts);
		foreach ($dept_ids as &$dept_id) {
			$dept_id = intval($dept_id);
		}
		return $dept_ids;
	}
	
	/**
	 * Insert a new item.
	 * $item is an array(
	 *		order_item_id => Order item ID,
	 * )
	 * In addition, it the array can have all the key/value pairs as the argument to update_item().
	 * It does not accept ID.
	 * Returns false on failure, or ID of new item on success.
	 */
	function insert_item($item) {
		global $wpdb;
		$pr = $wpdb->prefix;
		
		$data = array(
			'created' => time(),
		);
		
		if (!empty($item['order_item_id'])) {
			$order_item_id = intval($item['order_item_id']);
			try {
				$order_item = new \WC_Order_Item_Product($order_item_id);
			} catch (\Exception $e) {
				trigger_error("Invalid order item ID: $order_item_id");
			}
			if (empty($order_item)) {
				return false;
			}
			$data['order_item_id'] = $order_item_id;
		}

		$item = $this->apply_filters('@insert_item_process', $item);
		
		$res = $wpdb->insert("${pr}zwm_list", $data);
		if ($res === false) {
			return false;
		}
		
		$item['id'] = $wpdb->insert_id;
		
		return $this->update_item($item);
	}

	/**
	 * Return an array of the form array('created' => UNIX timestamp of when the item was created, 'updated' => UNIX timestamp of when the item was last updated, 'updated_by' => the ID of the user who last updated it).
	 */
	function item_timestamps($id) {
		global $wpdb;
		$pr = $wpdb->prefix;
		$id = intval($id);
		return $wpdb->get_row("SELECT created, updated, updated_by FROM ${pr}zwm_list WHERE id = $id", ARRAY_A);
	}

	/**
	 * Whether an order's tasks should be archived, based on its status.
	 * @param WC_Order|int $order
	 * @return bool|null Null if invalid order.
	 */
	function order_should_be_archived($order) {
		if (!($order instanceof \WC_Order)) {
			$order = wc_get_order($order);
		}
		if (!$order) {
			return null;
		}
		$stati = $this->get_archive_stati();
		return array_key_exists($order->get_status(), $stati) || array_key_exists('wc-'.$order->get_status(), $stati);
	}
	
	function output_page_use_zwm() {
		?>
		<div class="wrap">
			<h2><?php echo $this->pl_name();?></h2>
			
			<p>Welcome to <?php echo $this->pl_name();?>! This table contains all your ZWM tasks.</p>
			
			<p>If you have WooCommerce integration enabled, tasks will be automatically added to the table whenever you make a new WooCommerce order or add items to an existing order. They will be automatically archived if you delete them via WooCommerce.</p>
			
			<p>You can display this table on any page using the shortcode [zwm_list].</p>
			
			<?php echo do_shortcode('[zwm_list]'); ?>
		</div>
	<?php
	}

	/**
	 * Rescan the database and all WC orders, copying/updating them into the ZWM table and repairing DB inconsistencies.
	 */
	function refresh_db() {
		global $wpdb;
		$pr = $wpdb->prefix;

		$archive_stati = $this->get_archive_stati('SQL');

		// First, copy all non-archived orders across
		$rows = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status NOT IN $archive_stati");
		foreach ($rows as $row) {
			$order_id = intval($row->ID);
			$this->copy_order($order_id);
		}

		// Then, archive all tasks from archived orders
		$this->archive_tasks(array("order_item_id IN
			(SELECT order_item_id FROM ${pr}woocommerce_order_items WHERE order_id IN
				(SELECT ID FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status IN $archive_stati)
			)"
		));

		// Archive tasks with invalid order item IDs.
		// Only archive - bulk delete is dangerous!
		$this->archive_tasks(array(
			"order_item_id <> 0 AND order_item_id IS NOT NULL AND order_item_id NOT IN (SELECT order_item_id FROM ${pr}woocommerce_order_items)",
		));
		$this->do_action('@refresh_db_after');
	}

	function shortcode_todolist($atts) {
		if (!$this->common->allow_access())
			return 'You don\'t have permission to access this page.';

		global $wpdb;

		$this->do_action('@shortcode_before');

		$depts = $this->get_departments(true); // Get departments, along with the users assigned to them
		$priors = $this->get_priorities();

		// Enqueue required scripts
		$this->res->enqueue_script('@');
		$this->fields->enqueue_custom_types();

		$fields = $this->fields->to_jsgrid();

		$settings = array(
			'$' => '#zwm_todo_list',
			'ticker' => 'zwm',
			'fields' => $fields,
			'inserting' => $this->allow_insert(),

			'meta' => array(
				'depts' => $depts,
				'priors' => $priors,
			),

			// Extra settings
		);
		$settings = $this->common->grid_settings_add_urls($settings); // Add 'admin_url' and 'ajax_url' to ['common']
		$settings['meta'] = $this->common->grid_meta_add_users($settings['meta']);
		$settings['meta'] = $this->common->grid_meta_add_clients($settings['meta']);

		$settings = $this->apply_filters('@grid_settings', $settings);

		wp_add_inline_script('zwm', 'var zwm = new ZWM('.json_encode($settings).');', 'after');
		
		$html = '<div class="zsc">';
		$html .= $this->apply_filters('@grid_before', '');

		$html .= '<div style="float:right"><a href="http://www.zeumic.com.au/contact-form/" target="_blank">Report a Problem or Idea</a> | <a href="http://www.zeumic.com.au/zwm-zeumic-work-management-todos/" target="_blank">Help</a></div><div id="zwm_todo_list"></div>';

		$html .= $this->apply_filters('@grid_after', '');
		$html .= '</div>';

		$this->do_action('@output_grid_after');

		return $html;
	}
	
	/**
	 * Update the given item with the given data.
	 * Input should be of form array(
	 *		id: ID of the item to update,
	 *		dept: Department ID,
	 *		priority: Priority ID,
	 *		order_num_text: Order number text,
	 *		notes: Internal notes,
	 *		customer_notes: Customer notes,
	 *		users: Array of user IDs,
	 *		meta: Array where each element is an array('key' => key, 'value' => value). Their order will be the order of the meta items.
	 *			OR string of the form key1====value1////key2====value2.
	 *		priority_up_down (with Pro): Position within priority (>= 0),
	 *		dept_up_down (with Pro): Position within priority (>= 0),
	 *	).
	 * All other values must be set at insertion time.
	 * Return true if it succeeded, false otherwise
	 */
	function update_item($item) {
		global $wpdb;
		$pr = $wpdb->prefix;
		
		$id = intval($item['id']);
		
		// The array of sanitized data to pass to $wpdb->update()
		$data = array();
		
		if (isset($item['dept'])) {
			$data['dept'] = intval($item['dept']);
		}
		if (isset($item['priority'])) {
			$data['priority'] = intval($item['priority']);
		}
		if (isset($item['order_num_text'])) {
			$data['order_num_text'] = sanitize_text_field($item['order_num_text']);
		}
		if (isset($item['notes'])) {
			$data['notes'] = stripslashes_deep($item['notes']);
		}
		$data['updated'] = time();
		$data['updated_by'] = get_current_user_id();
		
		## Update zwm_list table
		$res = $wpdb->update("${pr}zwm_list", $data, array('id' => $id));
		if ($res === false) {
			return false;
		}
		
		## Now update other tables
		if (isset($item['users'])) {
			// Remove all staff from the task, then add them in again
			$q = "DELETE FROM ${pr}zwm_list_users WHERE list_id=" . $id;
			if (!$this->allow_see_all_staff()) {
				$q .= ' AND user_id='.get_current_user_id();
			}
			if ($wpdb->query($q) === false) {
				$this->ajax->error(500, "There was a problem updating the database (debug message: deleting users).");
			}
			
			if (!empty($item['users'])) {
				foreach ($item['users'] as $uid) {
					$uid = intval($uid);
					// Make sure the user ID actually refers to a user
					if (get_user_by('id', $uid) === false) {
						continue;
					}
					$wpdb->insert("${pr}zwm_list_users", array('list_id' => $id, 'user_id' => $uid));
				}
			}
		}

		## Update order and order item meta, if the task is associated with a WC order item
		$order_item_id = intval($wpdb->get_var($wpdb->prepare("SELECT order_item_id FROM ${pr}zwm_list WHERE id = %d", $id)));
		if ($order_item_id) {
			try {
				$order_item = new \WC_Order_Item_Product($order_item_id);
			} catch (\Exception $e) {
				// Pass
			}
		}
		if (!empty($order_item)) {
			$order = $order_item->get_order();

			// Update customer_notes
			if (isset($item['customer_notes'])) {
				$customer_notes = stripslashes_deep($item['customer_notes']);
				$order->set_customer_note($customer_notes);
			}

			// Update order item meta
			if (isset($item['meta'])) {
				$current_meta = $order_item->get_meta_data();
				
				// For simplicity, just delete all existing metas for the order item and then re-add them
				foreach ($current_meta as $meta_item) {
					if (!empty($meta_item->key) && $meta_item->key[0] === '_') {
						continue;
					}
					$order_item->delete_meta_data_by_mid($meta_item->id);
				}
				
				// The meta can be in ==== //// form or as an array
				if (is_string($item['meta'])) {
					$post_meta_ins_array = explode("////", $item['meta']); 
					foreach ($post_meta_ins_array as $v){ 
						if(!empty($v)){
							$metas = explode('====', $v);
							
							$order_item->add_meta_data($metas[0], !empty($metas[1]) ? rtrim($metas[1]) : '');
						}
					}
				} else {
					// For ZWM Pro
					foreach ($item['meta'] as $meta) {
						if (empty($meta)) {
							continue;
						}
						if (!empty($meta['key']) && !empty($meta['value'])) {
							$metakey = stripslashes($meta['key']);
							$metavalue = stripslashes($meta['value']);
							
							$order_item->add_meta_data($metakey, $metavalue);
						}
					}
				}
				$order_item->save();
			}

			// Don't forget to save the order
			$order->save();
		}
		$this->do_action('@update_item_after', $item);

		return true;
	}
}
