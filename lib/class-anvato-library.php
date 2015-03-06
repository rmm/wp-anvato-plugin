<?php
/**
 * Get data about videos using the Anvato API.
 *
 * @package
 */
class Anvato_Library {

	/**
	 * printf()-friendly string for querying the Anvato API.
	 *
	 * The arguments are the MCP URL, a timestamp, a unique signature, the
	 * public key, and optional parameters.
	 *
	 * @see $this->build_request_url().
	 *
	 * @var string.
	 */
	private $api_request_url = '%s/api?ts=%d&sgn=%s&id=%s&%s';

	/**
	 * The value of the plugin settings on instantiation.
	 *
	 * @var array.
	 */
	private $general_settings;

	/**
	 * The value of the stations settings.
	 *
	 * @var array.
	 */
	private $selected_station;

	/**
	 * The body of the XML request to send to the API.
	 *
	 * @todo Possibly convert to a printf()-friendly string for substituting
	 *     "list_groups" for "list_videos.""
	 *
	 * @var string.
	 */
	private $xml_body = "<?xml version=\"1.0\" encoding=\"utf-8\"?><request><type>%API_METHOD%</type><params></params></request>";

	/**
	 * Instance of this class.
	 *
	 * @var object.
	 */
	protected static $instance = null;

	/**
	 * Initialize the class.
	 */
	private function __construct() 
	{
		$this->general_settings = Anvato_Settings()->get_mcp_options();
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object.
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Check whether the settings required for using the API are set.
	 *
	 * @return boolean.
	 */
	public function has_required_settings() {
		return ! ( empty( $this->general_settings ) || false !== array_search( '', array( $this->general_settings['mcp']['url'], $this->selected_station['public_key'], $this->selected_station['private_key'] ) ) );
	}

	/**
	 * Create the unique signature for a request.
	 *
	 * @see  $this->build_request_url() for detail about the timestamp.
	 *
	 * @param  int $time UNIX timestamp of the request.
	 * @return string.
	 */
	private function build_request_signature( $time ) {
		return base64_encode( hash_hmac( 'sha256', $this->xml_body . $time, $this->selected_station['private_key'], true ) );
	}

	/**
	 * Set up the filtering conditions to use as part of a search of the library.
	 *
	 * @param array $args {
	 *		@type string $lk Search keyword.
	 * }
	 * @return array.
	 */
	private function build_request_params( $args = array() ) {
		$params = array();
		foreach( $args as $key => $value ) {
			switch ( $key ) {
				case 'lk' :
					$params['filter_by'][] = 'name';
					$params['filter_cond'][] = 'lk';
					$params['filter_value'][] = sanitize_text_field( $value );
				break;
			}
		}
		return $params;
	}

	/**
	 * Construct the URL to send to the API.
	 *
	 * @see  $this->api_request_url for detail about the URL.
	 * @see  $this->build_request_parameters() for allowed search parameters.
	 *
	 * @param array $params Search parameters.
	 * @param int $time The UNIX timestamp of the request. Passed to the
	 *     function because the same timestamp is needed more than once.
	 * @return string The URL after formatting with sprintf().
	 */
	private function build_request_url( $params = array(), $time ) {
		return sprintf(
			$this->api_request_url,
			esc_url( $this->general_settings['mcp']['url'] ),
			$time,
			urlencode( $this->build_request_signature( $time ) ),
			$this->selected_station['public_key'],
			build_query( $params )
		);
	}

	/**
	 * Check whether the Anvato API reported an unsuccessful request.
	 *
	 * @param array $response The response array from wp_remote_get().
	 * @return boolean.
	 */
	private function is_api_error( $response ) {
		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
		if ( is_object( $xml ) ) {
			return 'failure' == $xml->result;
		} else {
			return true;
		}
	}

	/**
	 * Get the error message from the Anvato API during an unsuccessful request.
	 *
	 * @param array $response The response array from wp_remote_get().
	 * @return string The message.
	 */
	private function get_api_error( $response ) {
		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
		if ( is_object( $xml ) && ! empty( $xml->comment ) ) {
			return sprintf( __( '"%s"', ANVATO_DOMAIN_SLUG ), esc_html( $xml->comment ) );
		} else {
			// Intentionally uncapitalized.
			return __( 'no error message provided', ANVATO_DOMAIN_SLUG  );
		}
	}

	/**
	 * Request data from the Anvato API.
	 *
	 * @uses  vip_safe_wp_remote_get() if available.
	 * @see  $this->build_request_parameters() for allowed search parameters.
	 *
	 * @param array $params Search parameters.
	 * @return string|WP_Error String of XML of success, or WP_Error on failure.
	 */
	private function request( $params ) {
		if ( ! $this->has_required_settings() ) {
			return new WP_Error( 'missing_required_settings', __( 'The MCP URL, Public Key, and Private Key settings are required.', ANVATO_DOMAIN_SLUG  ) );
		}

		$url = $this->build_request_url( $params, time() );
		$args = array( 'body' => $this->xml_body );
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url, false, 3, 1, 20, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		} elseif ( wp_remote_retrieve_response_code( $response ) === 200 ) {
			if ( $this->is_api_error( $response ) ) {
				return new WP_Error( 'api_error', sprintf( __( 'Anvato responded with an error (%s).', ANVATO_DOMAIN_SLUG  ), $this->get_api_error( $response ) ) );
			} else {
				return $response;
			}
		} else {
			return new WP_Error( 'request_unsuccessful', __( 'There was an error contacting Anvato.', ANVATO_DOMAIN_SLUG  ) );
		}
	}

	/**
	 * Search the library for videos.
	 *
	 * @see  $this->build_request_parameters() for allowed search parameters.
	 *
	 * @param  array $args Search parameters.
	 * @return array|WP_Error Array with SimpleXMLElements of any videos found, or WP_Error on failure.
	 */
	public function search( $args = array() ) {

		$defaults = array(
			'lk' => '',
		);

		if ($args['type'] === "live" )
                {
                    $api_method = "list_embeddable_channels";
                }
		elseif($args['type'] === "vod" )
                {
                    $api_method = "list_videos";
                }
		elseif($args['type'] === "playlist" )
                {
                    $api_method = "list_playlists";
                }
                
                if ( $args['station'] === '' )
                {
                        return new WP_Error( 'missing_required_settings', __( 'Please select station.', ANVATO_DOMAIN_SLUG  ) );
                }

                foreach ( $this->general_settings['owners'] as $ow_item )
                {
                    if( $args['station'] === $ow_item['id'] )
                    {
                        $this->selected_station = $ow_item;
                        break;
                    }
                }
                
		$this->xml_body = str_replace("%API_METHOD%", $api_method, $this->xml_body );

		$response = $this->request( $this->build_request_params( $args ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		} else {
			$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
			if ( is_object( $xml ) ) 
                        {
				if( $api_method === 'list_videos' )
                                {
                                    return $xml->params->video_list->xpath( "//video" );
                                }
				if( $api_method === 'list_playlists' )
                                {
                                    return $xml->params->video_list->xpath( "//playlist" );
                                }
				elseif ($api_method === 'list_embeddable_channels')
				{
                                    return $xml->params->channel_list->xpath( "//channel" );
                                }
			} else {
				return new WP_Error( 'parse_error', __( 'There was an error processing the search results.', ANVATO_DOMAIN_SLUG  ) );
			}
		}
	}

}

/**
 * Helper function to use the class instance.
 *
 * @return object.
 */
function Anvato_Library() {
	return Anvato_Library::get_instance();
}