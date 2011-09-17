<?php

class P2P_Connection_Type {

	protected $args;

	protected $direction = 'from';
	protected $reversed = false;

	public function __construct( $args ) {
		$this->args = $args;
	}

	public function __get( $key ) {
		return $this->args[$key];
	}

	public function get_direction( $post_type ) {
		if ( $this->args['to'] == $this->args['from'] )
			return 'any';

		if ( $this->args['to'] == $post_type )
			return 'to';

		if ( $this->args['from'] == $post_type )
			return 'from';

		return false;
	}

	// TODO: remove
	public function set_direction( $direction ) {
		$this->direction = $direction;
		$this->reversed = ( 'to' == $direction );
	}

	public function get_title() {
		$title = $this->args['title'];

		if ( is_array( $title ) ) {
			$key = $this->reversed ? 'to' : 'from';

			if ( isset( $title[ $key ] ) )
				$title = $title[ $key ];
			else
				$title = '';
		}

		if ( empty( $title ) ) {
			$title = sprintf( __( 'Connected %s', P2P_TEXTDOMAIN ), get_post_type_object( $this->to )->labels->name );
		}

		return $title;
	}

	public function create_post( $title ) {
		$args = array(
			'post_title' => $title,
			'post_author' => get_current_user_id(),
			'post_type' => $this->to
		);

		$args = apply_filters( 'p2p_new_post_args', $args, $this );

		return wp_insert_post( $args );
	}

	public function get_connection_candidates( $current_post_id, $page, $search ) {
		$args = array(
			'paged' => $page,
			'post_type' => $this->to,
			'post_status' => 'any',
			'posts_per_page' => 5,
			'suppress_filters' => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false
		);

		if ( $search ) {
			add_filter( 'posts_search', array( __CLASS__, '_search_by_title' ), 10, 2 );
			$args['s'] = $search;
		}

		if ( $this->prevent_duplicates )
			$args['post__not_in'] = P2P_Storage::get( $current_post_id, $this->direction, $this->data );

		$args = apply_filters( 'p2p_possible_connections_args', $args, $this );

		$query = new WP_Query( $args );

		return (object) array(
			'posts' => $query->posts,
			'current_page' => max( 1, $query->get('paged') ),
			'total_pages' => $query->max_num_pages
		);
	}

	function _search_by_title( $sql, $wp_query ) {
		if ( $wp_query->is_search ) {
			list( $sql ) = explode( ' OR ', $sql, 2 );
			return $sql . '))';
		}

		return $sql;
	}

	public function get_current_connections( $post_id ) {
		$post = get_post( $post_id );
		if ( !$post )
			return array();

		$direction = $this->get_direction( $post->post_type );

		if ( !$direction ) {
			trigger_error( sprintf( "Invalid post type. Expected '%s' or '%s', but received '%s'.",
				$this->args['from'],
				$this->args['to'],
				$post->post_type
			), E_USER_WARNING );
			return array();
		}

		$other_post_type = 'from' == $direction ? $this->args['to'] : $this->args['from'];

		$args = array(
			array_search( $direction, P2P_Query::$qv_map ) => $post_id,
			'connected_meta' => $this->data,
			'post_type'=> $other_post_type,
			'post_status' => 'any',
			'nopaging' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts' => true,
		);

		if ( $this->sortable && 'to' != $direction ) {
			$args['connected_orderby'] = $this->sortable;
			$args['connected_order'] = 'ASC';
			$args['connected_order_num'] = true;
		}

		$args = apply_filters( 'p2p_current_connections_args', $args, $this );

		$q = new WP_Query( $args );

		return $q->posts;
	}

	public function connect( $from, $to ) {
		$post_from = get_post( $from );
		$post_to = get_post( $to );

		if ( !$post_a || !$post_b ) {
			return false;
		}

		$args = array( $from, $to );

		if ( $post_from->post_type == $this->to )
			$args = array_reverse( $args );

		$p2p_id = false;

		if ( $this->prevent_duplicates ) {
			$p2p_ids = P2P_Storage::get( $args[0], $args[1], $this->data );

			if ( !empty( $p2p_ids ) )
				$p2p_id = $p2p_ids[0];
		}

		if ( !$p2p_id ) {
			$p2p_id = P2P_Storage::connect( $args[0], $args[1], $this->data );
		}

		return $p2p_id;
	}

	public function disconnect( $post_id ) {
		p2p_disconnect( $post_id, $this->direction, $this->data );
	}

	public function delete_connection( $p2p_id ) {
		p2p_delete_connection( $p2p_id );
	}
}

