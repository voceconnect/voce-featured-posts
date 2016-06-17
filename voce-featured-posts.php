<?php

if ( ! class_exists( 'Voce_Featured_Posts' ) ) :

class Voce_Featured_Posts {

	/*
	 * By default, posts are the only post type with a featured type set.
	 * Add new types by using the Voce_Featured_Posts::add_type() method.
	 */
	public static $types;

	public static function initialize() {
		self::$types =  array(
			'post' => array(
				'featured' => array(
					'title'       => apply_filters( 'featured_post_default_label', 'Featured' ),
					'sortable'    => true,
					'post_status' => array( 'publish' )
				)
			)
		);
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_post' ) );
		add_action( 'delete_post', array( __CLASS__, 'delete_post' ) );
		add_action( 'add_attachment', array( __CLASS__, 'save_post' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'save_post' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_post' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menus' ) );
		add_action( 'wp_ajax_unfeature_post', array( __CLASS__, 'ajax_unfeature_post' ) );
		add_action( 'wp_ajax_save_featured_posts_order', array(__CLASS__, 'ajax_save_featured_posts_order') );
		add_action( 'admin_enqueue_scripts', function($hook) {
			$allowed_hooks = array();
			foreach(  Voce_Featured_Posts::$types as $post_type => $types){
				foreach($types as $type_key => $type_data ){
					switch ( $post_type ) {
						case 'post' :
							$allowed_hooks[] = 'posts_page_' . $post_type . '_' . $type_key;
							break;
						case 'attachment' :
							$allowed_hooks[] = 'media_page_' . $post_type . '_' . $type_key;
							break;
						default :
							$allowed_hooks[] = $post_type . '_page_' . $post_type . '_' . $type_key;
					}
				}

			}
			if ( in_array($hook, $allowed_hooks) ){
				$deps = array( 'jquery', 'jquery-ui-core', 'jquery-ui-widget', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-selectable', 'jquery-ui-sortable', 'jquery-ui-mouse', 'wp-ajax-response' );
				wp_enqueue_script( 'voce-featured-posts', plugins_url( 'js/voce-featured-posts.js', __FILE__ ), $deps, SCRIPT_VERSION );
			}
		} );

		if ( isset($_POST['featured_ids_order']) && !empty($_POST['featured_ids_order']) )
			self::update_featured_order();
	}

	public static function add_type( $key, $name, $post_type, $post_status = array( 'publish' ), $sortable = true ) {
		$post_status = is_array( $post_status ) ? $post_status : array( $post_status );
		$type_args   = array( $key => array( 'title' => $name, 'sortable' => $sortable, 'post_status' => $post_status ) );
		if ( isset( self::$types[$post_type] ) && is_array( self::$types[$post_type] ) )
			self::$types[$post_type] = array_merge( self::$types[$post_type], $type_args );
		else
			self::$types[$post_type] = $type_args;

		return true;
	}

	public static function get_featured_ids( $post_type = 'post', $type = 'featured', $post_status = array('publish') ) {
		if( $post_type == 'all' ){
			$featured_ids = array();
			foreach( self::$types as $featured_post_type => $types ){
				if( in_array( $type, array_keys( $types ) ) ){
					$ids = get_option( sprintf( '%s_%s_ids', $type, $post_type ), array( ) );
					array_merge($featured_ids, self::verify_featured_ids($ids, $type, $post_type, $post_status));
				}
			}
		} else {
			$featured_ids = get_option( sprintf( '%s_%s_ids', $type, $post_type ), array( ) );
			if(is_admin())
				self::verify_featured_ids($featured_ids, $type, $post_type, $post_status);
		}
		return $featured_ids;
	}

	public static function update_is_featured( $post_id, $post_type = 'post', $is_featured = true, $type = 'featured' ) {
		$featured_ids = self::get_featured_ids( $post_type, $type );

		if ( ( $is_featured && in_array($post_id, $featured_ids ) ) || ( !$is_featured && !in_array($post_id, $featured_ids) ) )
			return true;

		if ( $is_featured )
			array_unshift($featured_ids, $post_id);
		else
			$featured_ids = array_filter( array_diff( $featured_ids, array( $post_id ) ) );

		update_option( sprintf( '%s_%s_ids', $type, $post_type ), array_filter( $featured_ids ) );
		do_action( sprintf( 'update_%s_%s', $type, $post_type ), $post_id, $is_featured );
		do_action( sprintf( 'update_%s', $type ), $post_id, $is_featured );
		return true;
	}

	public static function is_featured( $type = 'featured', $post_id = 0 ) {
		if ( !$post_id )
			$post_id = get_the_ID();

		$check = self::get_featured_ids( get_post_type( $post_id ), $type );
		return in_array( $post_id, $check );
	}

	static function add_admin_menus() {
		foreach ( self::$types as $post_type => $types ){
			if ( !post_type_exists( $post_type ) )
				continue;

			foreach ( $types as $type_key => $type_data ){
				$parent_slug = 'edit.php?post_type=' . $post_type;
				switch ( $post_type ) {
					case 'post' :
						$parent_slug = 'edit.php';
						break;
					case 'attachment' :
						$parent_slug = 'upload.php';
						break;
				}
				$title = $type_data['title'] . ' ' . get_post_type_object( $post_type )->labels->name;
				add_submenu_page( $parent_slug, sprintf( 'Manage %s', $title), $title, 'manage_options', $post_type . '_' . $type_key, function() use ($type_key, $type_data, $post_type) {
					$featured_posts = array_values(Voce_Featured_Posts::get_featured_ids( $post_type, $type_key ));
					$featured_posts_hidden_input = array();
					$table_columns = array(
						'sort' => '',
						'title' => 'Item Title',
						'date' => 'Item Date'
					);

					$table_columns = apply_filters('featured_post_table_columns', $table_columns, $post_type, $type_key);

					if( !isset( $type_data['sortable'] ) || !$type_data['sortable'] )
						unset($table_columns['sort']);

					$table_id = ($type_data['sortable']) ? 'sortable_featured_posts_list' : 'featured_posts_list';
					?>
						<div class="wrap">
							<div id="icon-tools" class="icon32"><br/></div>
							<h2><?php echo esc_html( get_admin_page_title() ); ?></h2>
							<?php if($type_data['sortable']): ?>
								<form id="<?php echo esc_attr( $table_id.'_form' ); ?>" method="post" action="" name="<?php echo esc_attr( $table_id.'_form'); ?>" >
								<input name="save" disabled="disabled" type="submit" style="margin-right: 10px; margin-bottom: 10px" 
									class="alignright button-primary" id="featured_order_publish" value="Save Order" 
									data-post-type="<?php echo esc_attr( $post_type ) ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'save_featured_posts_order' ) ) ?>"
									data-type="<?php echo esc_attr( $type_key ) ?>"
									 />
							<?php endif; ?>
								<br/>
								<div id="ajax-response"></div>
								<table id="<?php echo esc_attr($table_id); ?>" class="widefat">
									<thead>
										<tr>
											<?php foreach($table_columns as $column_key => $column_name)
												printf('<th class="%s">%s</th>', esc_attr( $column_key ), esc_html( $column_name ) );
											?>
										</tr>
									</thead>
									<tbody>
									<?php if( !empty( $featured_posts ) ):
											foreach ( $featured_posts as $key => $featured_post_id ):
												$class =  ($key % 2) ? 'alternate post' : 'post';

												$featured_post = get_post($featured_post_id);

												if(is_null($featured_post) || !$featured_post){
													Voce_Featured_Posts::update_is_featured($featured_post_id, $post_type, false, $type_key);
													continue;
												}

												if ( $featured_post_id > 0 ):
													$featured_posts_hidden_input[] = sprintf('featured-post-%s', $featured_post_id);
													Voce_Featured_Posts::get_featured_post_row($table_columns, $class, $featured_post_id, $type_key);
												endif; ?>
											<?php endforeach;
										endif; ?>
									</tbody>
								</table>
								<?php if($type_data['sortable']): ?>
									<input type="hidden" id="featured_ids_order" name="featured_ids_order" value="<?php echo esc_attr(implode(',', $featured_posts_hidden_input)); ?>" />
									<input type="hidden" name="post_type" value="<?php echo esc_attr($post_type) ?>" />
									<input type="hidden" name="type_key" value="<?php echo esc_attr($type_key) ?>" />
									<?php wp_nonce_field('update_featured_order', 'featured_order_nonce'); ?>
								<?php endif; ?>
							</form>

						</div>
				<?php } );
			}
		}
	}

	static function get_featured_post_row($table_columns, $class, $featured_post_id, $type_key){
		?>
		<tr id="<?php echo esc_attr( 'featured-post-' . $featured_post_id ); ?>" class="<?php echo esc_attr($class); ?>">
			<?php foreach($table_columns as $column_key => $column_name): ?>
				<td class="<?php echo esc_attr($column_key); ?>">
				<?php switch($column_key){
					case 'sort':
						printf('<a href="#" class="sort-post" style="display:block; width:13px; height:16px; background:url(%s) no-repeat;"></a>', plugins_url('/img/sort.png', __FILE__) );
					break;
					case 'title':
						printf( '<strong><a href="%s">%s</a></strong>', get_edit_post_link( $featured_post_id ), get_the_title( $featured_post_id ) );
						?>
						<div class="row-actions">
							<a href="#" class="unfeature_post" data-nonce="<?php echo esc_attr( wp_create_nonce( 'unfeature_post' ) ); ?>" data-id="<?php echo esc_attr( $featured_post_id ); ?>" data-type="<?php echo esc_attr( $type_key); ?>">Remove from list</a> |
							<a href="<?php echo esc_url( get_edit_post_link( $featured_post_id ) ); ?>">Edit</a> |
							<a href="<?php echo esc_url( get_post_permalink( $featured_post_id ) ); ?>" target="_blank">View</a>
						</div>
						<?php
					break;

					case 'date':
						echo esc_html( get_the_time( 'm/d/Y', $featured_post_id ) );
					break;
					default:
						do_action('get_featured_post_custom_cell', $column_key, $column_name, $featured_post_id, $type_key);
					break;
				} ?>
			<?php endforeach; ?>

		</tr>
	<?php
	}

	private static function verify_featured_ids(&$featured_ids, $type, $post_type, $post_status){
		$new_ids = array();
		foreach($featured_ids as $featured_id){
			$featured_post_status = get_post_status( $featured_id );
			if($featured_post_status){
				if(!is_array($post_status) && $post_status != 'any')
					$post_status = array($post_status);
				if( ( $post_status == 'any' || in_array($featured_post_status, $post_status) ) && ( get_post_type($featured_id) == $post_type ) )
					$new_ids[] = $featured_id;
			}
		}

		return $new_ids;
	}

	static function add_metabox( $post_type, $post ) {
		foreach ( self::$types as $post_type => $types ) {
			foreach ( $types as $type_key => $type_data ) {
				add_meta_box( sprintf('%s_%s', $type_key, $post_type), $type_data['title'], function($post) use ($type_key, $type_data) {
					self::render_meta_box($post, $type_key, $type_data);
				}, $post_type, 'side' );
			}
		}
	}

	static function render_meta_box($post, $type_key, $type_data){
		$featured = self::is_featured( $type_key, $post->ID );
		$name = sprintf( '%s_%s', $type_key, $post->post_type );
		?>
		<p>
			<label for="<?php echo esc_attr( $name ); ?>"><?php echo $type_data['title']; ?>?</label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $featured ); ?>>
		</p>
		<?php
		wp_nonce_field( 'updating_' . $name, $name . '_nonce' );
	}

	static function ajax_unfeature_post() {
		check_ajax_referer( 'unfeature_post' );
		if ( !isset( $_POST['post_id'] ) || !isset( $_POST['type'] ) ) {
			$response = new WP_Ajax_Response( array(
					'what' => 'voce_featured_posts',
					'action' => 'unfeature_posts',
					'id' => 0,
					'position' => 1,
					'data' => new WP_Error( 'missing_something', 'Something went wrong trying to perform that action' )
				) );
			$response->send();
		}

		$post_id = (int) $_POST['post_id'];
		$type = sanitize_key( trim( $_POST['type'] ) );
		self::update_is_featured( $post_id, get_post_type($post_id), false, $type );
		$response = new WP_Ajax_Response( array(
				'what' => 'voce_featured_posts',
				'action' => 'unfeature_posts',
				'id' => 0,
				'position' => 1,
				'data' => 'success'
			) );
		$response->send();
	}

	static function ajax_save_featured_posts_order(){
		check_ajax_referer( 'save_featured_posts_order' );
		if ( !isset( $_POST['order'] ) || !isset( $_POST['type'] ) ) {
			$response = new WP_Ajax_Response( array(
					'what' => 'voce_featured_posts',
					'action' => 'save_featured_posts_order',
					'id' => 0,
					'position' => 1,
					'data' => new WP_Error( 'missing_something', 'Something went wrong trying to perform that action' )
				) );
			$response->send();
		}

		$post_type = trim( strip_tags( $_POST['post_type'] ) );
		$type = sanitize_key( trim( strip_tags( $_POST['type'] ) ) );
		$order = trim( strip_tags( $_POST['order'] ) );
		$order = str_replace('featured-post-', '', $order );
		$order = array_map('intval', explode(',', $order) );
		self::update_featured_order($order, $type, $post_type);
		$post_type_obj = get_post_type_object($post_type);
		$type_name = self::$types[$post_type][$type]['title'];
		$response = new WP_Ajax_Response( array(
				'what' => 'voce_featured_posts',
				'action' => 'save_featured_posts_order',
				'id' => 1,
				'position' => 1,
				'data' => sprintf('The order of the %s %s have been saved', $type_name, $post_type_obj->labels->name)
			) );
		$response->send();
	}

	static function save_post( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || !current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		$post_type   = get_post_type( $post_id );
		$post_status = get_post_status( $post_id );

		if ( isset( self::$types[$post_type] ) ) {
			foreach ( self::$types[$post_type] as $type_key => $type_args ) {
				$name     = sprintf( '%s_%s', $type_key, $post_type );
				$statuses = isset( $type_args['post_status'] ) ? $type_args['post_status'] : array();
				
				if ( !isset( $_POST[$name . '_nonce'] ) || !wp_verify_nonce( $_POST[$name . '_nonce'], 'updating_' . $name ) ) {
					continue;
				}

				// if the post status is not an acceptable feature type status, then unfeature the post
				if ( !in_array( $post_status, $statuses ) ) {
					self::update_is_featured( $post_id, $post_type, false, $type_key );
				} else {
					$is_featured = isset( $_POST[$name] );
					self::update_is_featured( $post_id, $post_type, $is_featured, $type_key );
				}
			}
		}
	}

	static function delete_post($post_id) {
		$post_type = get_post_type($post_id);
		if(isset(self::$types[$post_type])){
			foreach(self::$types[$post_type] as $type_key => $type_name){
				if(self::is_featured( $type_key, $post_id ))
					self::update_is_featured ( $post_id, false, $type_key );
			}
		}
	}

	static function update_featured_order($order, $type_key = 'featured', $post_type = 'post' ){
		update_option( sprintf( '%s_%s_ids', $type_key, $post_type ), array_filter( $order ) );
	}
}

add_action( 'init', array( 'Voce_Featured_Posts', 'initialize' ) );

endif;