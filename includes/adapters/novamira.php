<?php
/**
 * Novamira adapter: who can drive this site through an AI agent, and what
 * the agent is allowed to do.
 *
 * Novamira (use-novamira/novamira) is an MCP server inside WordPress: an AI
 * client authenticates with OAuth or an Application Password and gets the
 * abilities Novamira registers through core's Abilities API (PHP execution,
 * WP-CLI, files, the block editor) plus every other plugin's abilities. Its
 * own screens are a connect wizard, a per-client setup flow and an Abilities
 * Hub. Minn covers the two daily-ops questions:
 *
 *  - Connections: every credential that can reach the site (OAuth apps
 *    connected through the current user, admin-created client ids, and
 *    Novamira-named Application Passwords), each revocable through the
 *    plugin's own verbs. A status card says whether abilities are on, the
 *    endpoint, and when an agent last called.
 *  - Abilities (settings): one switch per registered ability, grouped by the
 *    plugin that provides it, writing Novamira's own rules option through
 *    its own helper so its policy stays coherent.
 *
 * Everything gates on novamira_manage_capability() (manage_options, or the
 * network option on multisite), the plugin's own boundary. The OAuth tables
 * are created lazily when abilities are first enabled, so every read checks
 * novamira's schema marker and answers empty before it exists. Access tokens
 * and passwords never ride a response.
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_novamira_active() {
	return function_exists( 'novamira_manage_capability' ) && function_exists( 'novamira_get_ability_rules' );
}

function minn_admin_novamira_can() {
	return minn_admin_novamira_active() && current_user_can( novamira_manage_capability() );
}

function minn_admin_novamira_oauth_ready() {
	return false !== get_option( 'novamira_oauth_schema_version', false );
}

function minn_admin_novamira_enabled() {
	return '1' === (string) get_option( 'novamira_ai_abilities_enabled', '0' );
}

/** UTC "Y-m-d H:i:s" from the plugin's tables → ISO Z; anything else passes through. */
function minn_admin_novamira_iso( $value ) {
	$value = (string) $value;
	if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
		return '';
	}
	$ts = strtotime( $value . ' UTC' );
	return $ts ? gmdate( 'c', $ts ) : $value;
}

/**
 * The connection list: OAuth apps connected through the current user (keyed
 * off refresh tokens, the plugin's own definition of a live connection),
 * admin-created client ids, and this user's Novamira Application Passwords.
 */
function minn_admin_novamira_connections() {
	global $wpdb;
	$items = array();
	$uid   = get_current_user_id();
	if ( minn_admin_novamira_oauth_ready() ) {
		$t   = $wpdb->prefix . 'novamira_oauth_access_tokens';
		$r   = $wpdb->prefix . 'novamira_oauth_refresh_tokens';
		$c   = $wpdb->prefix . 'novamira_oauth_clients';
		$now = gmdate( 'Y-m-d H:i:s' );
		// The same query the plugin's Connections page runs: a connection is
		// a client with an unrevoked, unexpired refresh token for this user.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT c.client_name, at.client_id, MAX(rt.expires_at) AS expires_at, MAX(at.created_at) AS created_at
			   FROM `{$r}` rt
			   JOIN `{$t}` at ON at.identifier_hash = rt.access_token_hash
			   JOIN `{$c}` c ON c.client_id = at.client_id
			  WHERE at.user_id = %d AND rt.revoked = 0 AND rt.expires_at > %s
			  GROUP BY at.client_id, c.client_name
			  ORDER BY expires_at DESC",
			$uid,
			$now
		), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'       => 'oauth:' . $row['client_id'],
				'name'     => (string) $row['client_name'],
				'kind'     => 'oauth',
				'kindText' => __( 'OAuth app', 'minn-admin' ),
				'ident'    => (string) $row['client_id'],
				'created'  => minn_admin_novamira_iso( $row['created_at'] ?? '' ),
				'lastUsed' => '',
				'expires'  => minn_admin_novamira_iso( $row['expires_at'] ),
			);
		}
		$clients = $wpdb->get_results( "SELECT client_id, client_name, created_at, last_used_at FROM `{$c}` WHERE admin_created = 1 ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $clients as $row ) {
			$items[] = array(
				'id'       => 'client:' . $row['client_id'],
				'name'     => (string) $row['client_name'],
				'kind'     => 'client',
				'kindText' => __( 'Client ID', 'minn-admin' ),
				'ident'    => (string) $row['client_id'],
				'created'  => minn_admin_novamira_iso( $row['created_at'] ),
				'lastUsed' => minn_admin_novamira_iso( $row['last_used_at'] ?? '' ),
				'expires'  => '',
			);
		}
	}
	if ( class_exists( 'WP_Application_Passwords' ) && function_exists( 'novamira_is_application_password' ) ) {
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $uid ) as $pw ) {
			if ( ! novamira_is_application_password( (array) $pw ) ) {
				continue;
			}
			$items[] = array(
				'id'       => 'pw:' . $pw['uuid'],
				'name'     => (string) $pw['name'],
				'kind'     => 'password',
				'kindText' => __( 'Application password', 'minn-admin' ),
				'ident'    => '',
				'created'  => ! empty( $pw['created'] ) ? gmdate( 'c', (int) $pw['created'] ) : '',
				'lastUsed' => ! empty( $pw['last_used'] ) ? gmdate( 'c', (int) $pw['last_used'] ) : '',
				'expires'  => '',
			);
		}
	}
	return $items;
}

/** Revoke one connection row through the plugin's own verbs. */
function minn_admin_novamira_revoke( $id ) {
	$id  = (string) $id;
	$uid = get_current_user_id();
	if ( 0 === strpos( $id, 'pw:' ) ) {
		$uuid = substr( $id, 3 );
		$ok   = class_exists( 'WP_Application_Passwords' ) ? WP_Application_Passwords::delete_application_password( $uid, $uuid ) : false;
		return true === $ok ? true : new WP_Error( 'not_found', __( 'No such application password.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( ! minn_admin_novamira_oauth_ready() ) {
		return new WP_Error( 'not_found', __( 'No such connection.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$client_id = sanitize_key( substr( $id, strpos( $id, ':' ) + 1 ) );
	if ( '' === $client_id ) {
		return new WP_Error( 'bad_id', __( 'Connection id is required.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( ! function_exists( '\\Novamira\\OAuth\\Connections\\revoke_client_access' ) ) {
		require_once WP_PLUGIN_DIR . '/novamira/includes/oauth/connections.php';
	}
	\Novamira\OAuth\Connections\revoke_client_access( $client_id, $uid );
	if ( \Novamira\OAuth\Connections\load_client_repository() ) {
		$repo = new \Novamira\OAuth\Repositories\ClientRepository();
		if ( 0 === strpos( $id, 'client:' ) ) {
			$repo->revoke( $client_id ); // the admin-created row itself
		} else {
			$repo->delete_if_unused( $client_id );
		}
	}
	return true;
}

function minn_admin_novamira_status_model() {
	$enabled = minn_admin_novamira_enabled();
	$last    = get_option( 'novamira_mcp_last_request', false );
	$rows    = array(
		array(
			'label' => __( 'AI abilities', 'minn-admin' ),
			'value' => $enabled ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
			'hint'  => $enabled
				/* translators: %s: the domain the abilities are locked to. */
				? sprintf( __( 'agents may connect to %s', 'minn-admin' ), (string) get_option( 'novamira_ai_abilities_domain', '' ) )
				: __( 'no agent can connect until this is on', 'minn-admin' ),
		),
		array(
			'label' => __( 'Connections', 'minn-admin' ),
			'value' => (string) count( minn_admin_novamira_connections() ),
			'hint'  => __( 'OAuth apps, client ids and application passwords that can reach this site', 'minn-admin' ),
		),
	);
	$abilities = minn_admin_novamira_abilities();
	$disabled  = count( array_filter( $abilities, function ( $a ) { return $a['disabled']; } ) );
	$rows[]    = array(
		'label' => __( 'Abilities exposed', 'minn-admin' ),
		/* translators: 1: abilities an agent may call, 2: abilities registered. */
		'value' => sprintf( __( '%1$d of %2$d', 'minn-admin' ), count( $abilities ) - $disabled, count( $abilities ) ),
		'hint'  => $disabled ? sprintf( /* translators: %d: switched-off abilities. */ _n( '%d switched off in Settings', '%d switched off in Settings', $disabled, 'minn-admin' ), $disabled ) : __( 'everything registered is callable', 'minn-admin' ),
	);
	$pro = minn_admin_novamira_pro_row();
	if ( $pro ) {
		$rows[] = $pro;
	}
	if ( $last ) {
		$rows[] = array(
			'label' => __( 'Last agent request', 'minn-admin' ),
			'value' => is_numeric( $last ) ? human_time_diff( (int) $last ) . ' ' . __( 'ago', 'minn-admin' ) : (string) $last,
		);
	}
	$actions = array();
	if ( function_exists( 'novamira_enable_ai_abilities' ) ) {
		$actions[] = $enabled
			? array(
				'label'   => __( 'Turn AI abilities off', 'minn-admin' ),
				'route'   => 'minn-admin/v1/novamira/enabled/off',
				'method'  => 'POST',
				'confirm' => __( 'Turn off AI abilities? Connected agents lose access until it is on again.', 'minn-admin' ),
				'danger'  => true,
			)
			: array(
				'label'  => __( 'Turn AI abilities on', 'minn-admin' ),
				'route'  => 'minn-admin/v1/novamira/enabled/on',
				'method' => 'POST',
			);
	}
	$actions[] = array(
		'label' => __( 'Connect a client ↗', 'minn-admin' ),
		'href'  => admin_url( 'admin.php?page=novamira-connect' ),
	);
	return array(
		'rows'    => $rows,
		'command' => array(
			'label' => __( 'MCP endpoint', 'minn-admin' ),
			'text'  => rest_url( 'mcp/novamira' ),
			'hint'  => __( 'The URL an MCP client connects to; each client\'s own setup steps are on the Novamira screen.', 'minn-admin' ),
		),
		'actions' => $actions,
	);
}

/**
 * Novamira Pro on the card: its license state, and how many of its
 * specializations (the page-builder, theme, field and SEO ability packs)
 * apply to this site. Each manifest entry gates on the target plugin and a
 * version floor; the packs only load while the license is active, so the
 * count is what a licence would unlock, not what is registered.
 */
function minn_admin_novamira_pro_row() {
	if ( ! defined( 'NOVAMIRA_PRO_VERSION' ) ) {
		return null;
	}
	$active = function_exists( '\\Novamira\\Pro\\is_license_active' ) && \Novamira\Pro\is_license_active();
	$key    = (string) get_option( 'nvp_license_key', '' );
	$error  = trim( (string) get_option( 'nvp_license_error', '' ) );
	$apply  = array();
	$total  = 0;
	if ( function_exists( '\\Novamira\\Pro\\specialization_manifest' ) ) {
		foreach ( (array) \Novamira\Pro\specialization_manifest() as $entry ) {
			$total++;
			try {
				if ( isset( $entry['gate'] ) && is_callable( $entry['gate'] ) && $entry['gate']() ) {
					$apply[] = (string) ( $entry['category_label'] ?? $entry['slug'] );
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}
	if ( $active ) {
		$value = __( 'Licensed', 'minn-admin' );
	} elseif ( '' === $key ) {
		$value = __( 'No license', 'minn-admin' );
	} else {
		$value = preg_match( '/expired/i', $error ) ? __( 'License expired', 'minn-admin' ) : __( 'License not active', 'minn-admin' );
	}
	/* translators: 1: specializations that apply to this site, 2: specializations in the release. */
	$hint = sprintf( __( '%1$d of %2$d specializations apply here', 'minn-admin' ), count( $apply ), $total );
	if ( $apply ) {
		$hint .= ': ' . implode( ', ', array_slice( $apply, 0, 6 ) ) . ( count( $apply ) > 6 ? '…' : '' );
	}
	if ( ! $active ) {
		$hint .= '. ' . __( 'They load once the license is active (Extensions → Licenses).', 'minn-admin' );
	}
	return array( 'label' => 'Novamira Pro ' . NOVAMIRA_PRO_VERSION, 'value' => $value, 'hint' => $hint );
}

/**
 * Every registered ability with Novamira's rule applied: [ { name, label,
 * description, provider, disabled, off } ]. `off` marks an ability whose
 * Novamira feature is switched off (Design, Skills, Chat): the rule cannot
 * turn it on, so the switch is locked with a note.
 */
function minn_admin_novamira_abilities( $fresh = false ) {
	static $cache = null;
	if ( null !== $cache && ! $fresh ) {
		return $cache;
	}
	$rules = novamira_get_ability_rules();
	$out   = array();
	$seen  = array();
	if ( function_exists( 'wp_get_abilities' ) ) {
		foreach ( wp_get_abilities() as $ability ) {
			$name     = $ability->get_name();
			$category = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
			$off      = false;
			if ( class_exists( '\\Novamira\\Features\\Manager' ) && function_exists( '\\Novamira\\Features\\features' ) ) {
				try {
					$features = \Novamira\Features\features();
					$off      = ! $features->is_ability_active( $name, $category );
				} catch ( \Throwable $e ) {
					$off = false;
				}
			}
			$out[]         = array(
				'name'        => $name,
				'label'       => (string) $ability->get_label(),
				'description' => (string) $ability->get_description(),
				'provider'    => (string) strstr( $name, '/', true ),
				'category'    => $category,
				'disabled'    => ! empty( $rules[ $name ]['disabled'] ),
				'off'         => $off,
			);
			$seen[ $name ] = true;
		}
	}
	// A rule can name an ability that is not registered right now (its
	// plugin is off, or the policy unregistered it before this read); keep
	// it visible so the switch can be flipped back.
	foreach ( $rules as $name => $rule ) {
		if ( isset( $seen[ $name ] ) || empty( $rule['disabled'] ) ) {
			continue;
		}
		$out[] = array(
			'name'        => $name,
			'label'       => ucwords( str_replace( '-', ' ', (string) substr( strrchr( $name, '/' ), 1 ) ) ),
			'description' => '',
			'provider'    => (string) strstr( $name, '/', true ),
			'category'    => '',
			'disabled'    => true,
			'off'         => false,
		);
	}
	$cache = $out;
	return $out;
}

/**
 * The group an ability is listed under: its registered category (what the
 * Abilities Hub groups by, and what Novamira Pro names its specializations
 * with: "Elementor", "ACF", "Yoast SEO"), else its provider namespace.
 */
function minn_admin_novamira_ability_group( $a ) {
	return '' !== $a['category'] ? 'c-' . $a['category'] : 'p-' . $a['provider'];
}

function minn_admin_novamira_group_label( $group ) {
	if ( 0 === strpos( $group, 'c-' ) ) {
		$slug = substr( $group, 2 );
		$cat  = function_exists( 'wp_get_ability_category' ) ? wp_get_ability_category( $slug ) : null;
		return $cat && method_exists( $cat, 'get_label' ) ? (string) $cat->get_label() : ucwords( str_replace( '-', ' ', $slug ) );
	}
	return minn_admin_novamira_provider_label( substr( $group, 2 ) );
}

/** Settings tabs: Context first, then ability groups (Novamira's own first, then by size). */
function minn_admin_novamira_ability_tabs() {
	$by = array();
	foreach ( minn_admin_novamira_abilities() as $a ) {
		$by[ minn_admin_novamira_ability_group( $a ) ][] = $a;
	}
	$own = function ( $g ) {
		return 0 === strpos( $g, 'p-novamira' ) || in_array( $g, array( 'c-novamira', 'c-skill', 'c-design-system', 'c-memory', 'c-files', 'c-php', 'c-wp-cli', 'c-gutenberg' ), true );
	};
	uksort( $by, function ( $x, $y ) use ( $by, $own ) {
		if ( $own( $x ) !== $own( $y ) ) return $own( $x ) ? -1 : 1;
		return count( $by[ $y ] ) - count( $by[ $x ] );
	} );
	$tabs = array();
	if ( function_exists( '\\Novamira\\Context\\instructions_get_content' ) ) {
		$tabs[] = array( 'id' => 'context', 'label' => __( 'Context', 'minn-admin' ) );
	}
	foreach ( $by as $group => $list ) {
		$tabs[] = array( 'id' => sanitize_key( $group ), 'label' => minn_admin_novamira_group_label( $group ) );
	}
	return $tabs;
}

function minn_admin_novamira_provider_label( $provider ) {
	static $names = null;
	if ( null === $names ) {
		$names = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			$names[ dirname( $file ) ] = $data['Name'];
		}
	}
	if ( 'core' === $provider ) {
		return __( 'WordPress', 'minn-admin' );
	}
	if ( 'novamira' === $provider ) {
		return 'Novamira';
	}
	if ( 'mcp-adapter' === $provider || 'novamira-mcp-adapter' === $provider ) {
		return __( 'MCP adapter', 'minn-admin' );
	}
	// Providers name themselves loosely ("ninjaforms", "yoast-seo", "wpcode");
	// match the folder or the plugin name with punctuation ignored.
	$norm = function ( $v ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $v ) );
	};
	$want = $norm( $provider );
	foreach ( $names as $dir => $name ) {
		if ( $norm( $dir ) === $want || $norm( $name ) === $want ) {
			return $name;
		}
	}
	foreach ( $names as $dir => $name ) {
		if ( 0 === strpos( $norm( $name ), $want ) ) {
			return $name;
		}
	}
	return ucwords( str_replace( '-', ' ', $provider ) );
}

function minn_admin_novamira_tab_shape( $tab ) {
	$tab = sanitize_key( $tab );
	if ( 'context' === $tab ) {
		return minn_admin_novamira_context_shape();
	}
	$fields = array();
	$values = array();
	$locked = 0;
	foreach ( minn_admin_novamira_abilities() as $a ) {
		if ( sanitize_key( minn_admin_novamira_ability_group( $a ) ) !== $tab ) {
			continue;
		}
		if ( $a['off'] ) {
			$locked++;
			continue;
		}
		$key            = 'ability:' . $a['name'];
		$fields[]       = array(
			'key'   => $key,
			'label' => $a['label'],
			'type'  => 'toggle',
			'help'  => $a['description'],
		);
		$values[ $key ] = ! $a['disabled'];
	}
	if ( ! $fields && ! $locked ) {
		return new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return array(
		'groups'   => array(
			array(
				'title'  => __( 'Abilities an agent may call', 'minn-admin' ),
				'desc'   => ( minn_admin_novamira_enabled() ? '' : __( 'AI abilities are off: Novamira registers its own PHP, WP-CLI and file abilities only while they are on, so this list is what other plugins provide. ', 'minn-admin' ) ) . ( $locked
					? sprintf( /* translators: %d: abilities of a switched-off Novamira feature. */ _n( '%d ability belongs to a Novamira feature that is switched off; turn the feature on in Novamira to offer it.', '%d abilities belong to Novamira features that are switched off; turn the feature on in Novamira to offer them.', $locked, 'minn-admin' ), $locked )
					: '' ),
				'fields' => $fields,
				'locked' => $locked,
			),
		),
		'values'   => $values,
		'adminUrl' => admin_url( 'admin.php?page=novamira-abilities' ),
	);
}

/**
 * The site instructions every agent session reads (Novamira's Context
 * screen). Stored by their own writer; whether the instructions are
 * injected at all is a Novamira feature switch left on their screen.
 */
function minn_admin_novamira_context_shape() {
	$enabled = function_exists( '\\Novamira\\Context\\instructions_is_enabled' ) ? \Novamira\Context\instructions_is_enabled() : true;
	return array(
		'groups'   => array(
			array(
				'title'  => __( 'Site instructions for agents', 'minn-admin' ),
				'desc'   => $enabled
					? __( 'Every agent session receives this text as context about the site.', 'minn-admin' )
					: __( 'Saved, but Novamira\'s user-context feature is switched off, so agents are not receiving it; turn it on from the Novamira screen.', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'context', 'label' => __( 'Instructions', 'minn-admin' ), 'type' => 'textarea', 'rows' => 14, 'mono' => true ),
				),
				'locked' => 0,
			),
		),
		'values'   => array( 'context' => \Novamira\Context\instructions_get_content() ),
		'adminUrl' => admin_url( 'admin.php?page=novamira-context' ),
	);
}

/** Write toggles into Novamira's rules through its own helper (it keeps only disabled entries). */
function minn_admin_novamira_save( array $values ) {
	if ( array_key_exists( 'context', $values ) && function_exists( '\\Novamira\\Context\\instructions_update_content' ) ) {
		\Novamira\Context\instructions_update_content( (string) $values['context'] );
	}
	$rules = novamira_get_ability_rules();
	foreach ( $values as $key => $on ) {
		if ( 0 !== strpos( (string) $key, 'ability:' ) ) {
			continue;
		}
		$name = substr( (string) $key, 8 );
		if ( ! function_exists( 'novamira_is_valid_ability_name' ) || ! novamira_is_valid_ability_name( $name ) ) {
			continue;
		}
		$rules[ $name ] = array( 'disabled' => ! ( true === $on || '1' === $on || 1 === $on || 'true' === $on ) );
	}
	novamira_update_ability_rules( $rules );
	minn_admin_novamira_abilities( true );
}

/* ===== Memory (Novamira Pro): what agents remember between sessions =====
 *
 * A novamira_memory post per memory: title = name, excerpt = description,
 * content = the note (plain text), meta _novamira_memory_type in
 * user|feedback|project|reference. The abilities that write these load
 * only under an active license, but the posts outlive it, so the view
 * shows whenever the post type exists. Reads and writes are plain post
 * calls, the same ones Pro's own Memory screen makes. */

function minn_admin_novamira_memory_ready() {
	return post_type_exists( 'novamira_memory' );
}

function minn_admin_novamira_memory_types() {
	$types = defined( '\\Novamira\\Pro\\Abilities\\Memory\\NOVAMIRA_MEMORY_TYPES' ) ? (array) constant( '\\Novamira\\Pro\\Abilities\\Memory\\NOVAMIRA_MEMORY_TYPES' ) : array( 'user', 'feedback', 'project', 'reference' );
	$labels = array(
		'user'      => __( 'User', 'minn-admin' ),
		'feedback'  => __( 'Feedback', 'minn-admin' ),
		'project'   => __( 'Project', 'minn-admin' ),
		'reference' => __( 'Reference', 'minn-admin' ),
	);
	$out = array();
	foreach ( $types as $t ) {
		$out[ $t ] = isset( $labels[ $t ] ) ? $labels[ $t ] : ucfirst( $t );
	}
	return $out;
}

function minn_admin_novamira_memory_item( WP_Post $p ) {
	$types = minn_admin_novamira_memory_types();
	$type  = (string) get_post_meta( $p->ID, '_novamira_memory_type', true );
	return array(
		'id'          => $p->ID,
		'name'        => $p->post_title,
		'description' => $p->post_excerpt,
		'type'        => $type,
		'typeText'    => isset( $types[ $type ] ) ? $types[ $type ] : ( $type ? ucfirst( $type ) : '' ),
		'content'     => $p->post_content,
		'updated'     => get_gmt_from_date( $p->post_modified ) ? gmdate( 'c', strtotime( $p->post_modified_gmt . ' UTC' ) ) : '',
	);
}

function minn_admin_novamira_memories( $q = '', $type = '' ) {
	$args = array(
		'post_type'      => 'novamira_memory',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	if ( '' !== $q ) {
		$args['s'] = $q;
	}
	if ( '' !== $type ) {
		$args['meta_key']   = '_novamira_memory_type'; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_value'] = $type; // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return array_map( 'minn_admin_novamira_memory_item', get_posts( $args ) );
}

function minn_admin_novamira_memory_view() {
	$type_tabs = array();
	foreach ( minn_admin_novamira_memory_types() as $slug => $label ) {
		$type_tabs[] = array( $slug, $label );
	}
	$type_options = array();
	foreach ( minn_admin_novamira_memory_types() as $slug => $label ) {
		$type_options[] = array( $slug, $label );
	}
	return array(
		'viewLabel' => __( 'Memory', 'minn-admin' ),
		'cap'       => novamira_manage_capability(),
		'route'     => 'minn-admin/v1/novamira/memories',
		'itemsKey'  => 'items',
		'totalKey'  => 'total',
		'search'    => 'search={q}',
		'tabs'      => array( 'param' => 'type', 'static' => $type_tabs ),
		'columns'   => array(
			array( 'key' => 'name', 'label' => __( 'Memory', 'minn-admin' ), 'format' => 'title' ),
			array( 'key' => 'typeText', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'pill' ),
			array( 'key' => 'description', 'label' => __( 'About', 'minn-admin' ), 'format' => 'text' ),
			array( 'key' => 'updated', 'label' => __( 'Updated', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
		),
		'detail'    => array(
			'skip' => array( 'id', 'type', 'typeText', 'updated' ),
			'edit' => array(
				'route'  => 'minn-admin/v1/novamira/memories/{id}',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ) ),
					array( 'key' => 'description', 'label' => __( 'Description', 'minn-admin' ) ),
					array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_options ),
					array( 'key' => 'content', 'label' => __( 'Content', 'minn-admin' ), 'type' => 'textarea', 'rows' => 10, 'mono' => true ),
				),
			),
		),
		'actions'   => array(
			array(
				'label'   => __( 'Delete memory', 'minn-admin' ),
				'method'  => 'DELETE',
				'route'   => 'minn-admin/v1/novamira/memories/{id}',
				'confirm' => __( 'Delete this memory? Agents will no longer recall it.', 'minn-admin' ),
				'danger'  => true,
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_novamira_active() ) {
		return $surfaces;
	}
	$surfaces['novamira'] = array(
		'label'      => __( 'Agent Access', 'minn-admin' ),
		'sub'        => 'Novamira',
		'plugin'     => array( 'novamira', 'novamira-pro' ),
		'icon'       => 'plug',
		'cap'        => novamira_manage_capability(),
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/novamira/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/novamira/connections',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'viewLabel' => __( 'Connections', 'minn-admin' ),
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Connection', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'kindText', 'label' => __( 'Kind', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'ident', 'label' => __( 'Client ID', 'minn-admin' ), 'format' => 'mono' ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
				array( 'key' => 'lastUsed', 'label' => __( 'Last used', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
				array( 'key' => 'expires', 'label' => __( 'Expires', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'actions'   => array(
				array(
					'label'   => __( 'Revoke', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/novamira/connections/{id}/revoke',
					'confirm' => __( 'Revoke this connection? The agent using it loses access immediately.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
		'settings'   => array(
			'label' => __( 'Abilities', 'minn-admin' ),
			'tabs'  => minn_admin_novamira_ability_tabs(),
			'route' => 'minn-admin/v1/novamira/abilities/{tab}',
		),
	);
	if ( minn_admin_novamira_memory_ready() ) {
		$surfaces['novamira']['views'] = array( minn_admin_novamira_memory_view() );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_novamira_active() ) {
		return;
	}
	// Novamira unregisters a disabled ability on every non-Hub request, so a
	// listing here would lose the switched-off rows and their labels. The Hub
	// exempts itself by screen; these routes are that screen's equivalent, so
	// the policy is lifted for them alone, before the Abilities API first
	// initializes in this request.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( false !== strpos( $uri, 'minn-admin/v1/novamira/' ) && function_exists( 'novamira_apply_ability_policy' ) ) {
		remove_action( 'wp_abilities_api_init', 'novamira_apply_ability_policy', PHP_INT_MAX );
	}
	$perm = 'minn_admin_novamira_can';

	register_rest_route( 'minn-admin/v1', '/novamira/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_novamira_status_model() );
		},
	) );
	register_rest_route( 'minn-admin/v1', '/novamira/enabled/(?P<state>on|off)', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( $req ) {
			$on = 'on' === $req['state'];
			$ok   = $on ? novamira_enable_ai_abilities() : novamira_disable_ai_abilities();
			if ( ! $ok ) {
				return new WP_Error( 'novamira_dependency', function_exists( 'novamira_get_mcp_dependency_error' ) ? (string) novamira_get_mcp_dependency_error() : __( 'Novamira could not enable abilities.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'enabled' => minn_admin_novamira_enabled(), 'status' => minn_admin_novamira_status_model() ) );
		},
	) );
	register_rest_route( 'minn-admin/v1', '/novamira/connections', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$items = minn_admin_novamira_connections();
			return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
		},
	) );
	register_rest_route( 'minn-admin/v1', '/novamira/connections/(?P<id>[a-z]+:[A-Za-z0-9_\-]+)/revoke', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( $req ) {
			$r = minn_admin_novamira_revoke( (string) $req['id'] );
			return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'ok' => true ) );
		},
	) );
	if ( minn_admin_novamira_memory_ready() ) {
		register_rest_route( 'minn-admin/v1', '/novamira/memories', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				$items = minn_admin_novamira_memories( sanitize_text_field( (string) $req->get_param( 'search' ) ), sanitize_key( (string) $req->get_param( 'type' ) ) );
				return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
			},
		) );
		register_rest_route( 'minn-admin/v1', '/novamira/memories/(?P<id>\d+)', array(
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => function ( $req ) {
					$post = get_post( (int) $req['id'] );
					if ( ! $post || 'novamira_memory' !== $post->post_type ) {
						return new WP_Error( 'not_found', __( 'No such memory.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					$b     = (array) $req->get_json_params();
					$types = minn_admin_novamira_memory_types();
					$upd   = array( 'ID' => $post->ID );
					if ( isset( $b['name'] ) ) {
						$upd['post_title'] = sanitize_text_field( (string) $b['name'] );
					}
					if ( isset( $b['description'] ) ) {
						$upd['post_excerpt'] = sanitize_text_field( (string) $b['description'] );
					}
					if ( isset( $b['content'] ) ) {
						$upd['post_content'] = wp_strip_all_tags( (string) $b['content'] );
					}
					$r = wp_update_post( wp_slash( $upd ), true );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
					if ( isset( $b['type'] ) && isset( $types[ (string) $b['type'] ] ) ) {
						update_post_meta( $post->ID, '_novamira_memory_type', (string) $b['type'] );
					}
					return rest_ensure_response( minn_admin_novamira_memory_item( get_post( $post->ID ) ) );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $perm,
				'callback'            => function ( $req ) {
					$post = get_post( (int) $req['id'] );
					if ( ! $post || 'novamira_memory' !== $post->post_type ) {
						return new WP_Error( 'not_found', __( 'No such memory.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					wp_trash_post( $post->ID );
					return rest_ensure_response( array( 'ok' => true ) );
				},
			),
		) );
	}
	register_rest_route( 'minn-admin/v1', '/novamira/abilities/(?P<tab>[a-z0-9_\-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				return rest_ensure_response( minn_admin_novamira_tab_shape( $req['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				$body   = $req->get_json_params();
				$values = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
				minn_admin_novamira_save( $values );
				return rest_ensure_response( minn_admin_novamira_tab_shape( $req['tab'] ) );
			},
		),
	) );
} );
