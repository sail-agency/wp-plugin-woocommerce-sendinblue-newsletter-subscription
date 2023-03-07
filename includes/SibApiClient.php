<?php


class SibApiClient {

	const API_BASE_URL           = 'https://api.sendinblue.com/v3';
	const HTTP_METHOD_GET        = 'GET';
	const HTTP_METHOD_POST       = 'POST';
	const HTTP_METHOD_PUT        = 'PUT';
	const HTTP_METHOD_DELETE     = 'DELETE';
	const CAMPAIGN_TYPE_EMAIL    = 'email';
	const CAMPAIGN_TYPE_SMS      = 'sms';
	const RESPONSE_CODE_OK       = 200;
	const RESPONSE_CODE_CREATED  = 201;
	const RESPONSE_CODE_ACCEPTED = 202;
	const RESPONSE_CODE_UPDATED  = 204;
	const PLUGIN_VERSION         = '2.0.15';

	private $apiKey;
	private $lastResponseCode;

	/**
	 * SibApiClient constructor.
	 */
	public function __construct() {
		$this->apiKey = get_option( WC_Sendinblue::API_KEY_V3_OPTION_NAME );
	}

	/**
	 * Get account details.
	 *
	 * @return mixed
	 */
	public function getAccount() {
		return $this->get( '/account' );
	}

	/**
	 * Create sms campaign.
	 *
	 * @param $data - data
	 * @return mixed
	 */
	public function createSmsCampaign( $data ) {
		return $this->post( '/smsCampaigns', $data );
	}


	/**
	 * Send SMS.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function sendSms( $data ) {
		return $this->post( '/transactionalSMS/sms', $data );
	}

	/**
	 * Get list.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function getLists( $data ) {
		return $this->get( '/contacts/lists', $data );
	}

	/**
	 * Get list in folder.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function getListsInFolder( $folder, $data ) {
		return $this->get( '/contacts/folders/' . $folder . '/lists', $data );
	}

	/**
	 * Get all list.
	 *
	 * @param $folder id optional
	 * @return mixed
	 */
	public function getAllLists( $folder = 0 ) {
		$lists  = array(
			'lists' => array(),
			'count' => 0,
		);
		$offset = 0;
		$limit  = 50;
		do {
			if ( $folder > 0 ) {
				$list_data = $this->getListsInFolder(
					$folder,
					array(
						'limit'  => $limit,
						'offset' => $offset,
					)
				);
			} else {
				$list_data = $this->getLists(
					array(
						'limit'  => $limit,
						'offset' => $offset,
					)
				);
			}
			if ( ! isset( $list_data['lists'] ) ) {
				$list_data = array(
					'lists' => array(),
					'count' => 0,
				);
			}
			$lists['lists'] = array_merge( $lists['lists'], $list_data['lists'] );
			$offset        += 50;
		} while ( count( $lists['lists'] ) < $list_data['count'] );
		$lists['count'] = $list_data['count'];
		return $lists;
	}

	/**
	 * Create list.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function createList( $data ) {
		return $this->post( '/contacts/lists', $data );
	}

	/**
	 * Get user details.
	 *
	 * @param $email
	 * @return mixed
	 */
	public function getUser( $email ) {
		return $this->get( '/contacts/' . urlencode( $email ) );
	}

	/**
	 * Create new user.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function createUser( $data ) {
		return $this->post( '/contacts', $data );
	}

	/**
	 * Update User.
	 *
	 * @param email, $data
	 * @return mixed
	 */
	public function updateUser( $email, $data ) {
		return $this->put( '/contacts/' . $email, $data );
	}

	/**
	 * Import Contact via csv.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function importContacts( $data ) {
		return $this->post( '/contacts/import', $data );
	}

	/**
	 * Get email template.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function getEmailTemplates( $data ) {
		return $this->get( '/smtp/templates', $data );
	}

	/**
	 * Get all email templates.
	 *
	 * @return mixed
	 */
	public function getAllEmailTemplates() {
		$templates = array(
			'templates' => array(),
			'count'     => 0,
		);
		$offset    = 0;
		$limit     = 50;
		do {
			$template_data          = $this->getEmailTemplates(
				array(
					'templateStatus' => 'true',
					'limit'          => $limit,
					'offset'         => $offset,
				)
			);
			$templates['templates'] = array_merge( $templates['templates'], $template_data['templates'] );
			$offset                += 50;
		} while ( count( $templates['templates'] ) == $limit && count( $templates['templates'] ) <= $template_data['count'] );
		$templates['count'] = count( $templates['templates'] );
		return $templates;
	}

	/**
	 * Send email.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function sendEmail( $data ) {
		return $this->post( '/smtp/email', $data );
	}

	/**
	 * Get attribute.
	 *
	 * @return mixed
	 */
	public function getAttributes() {
		return $this->get( '/contacts/attributes' );
	}

	/**
	 * Create attributes.
	 *
	 * @param type, $name,$data
	 * @return mixed
	 */
	public function createAttribute( $type, $name, $data ) {
		return $this->post( '/contacts/attributes/' . $type . '/' . $name, $data );
	}

	/**
	 * Get transactional email reports.
	 *
	 * @param $tag
	 * @param $startDate
	 * @param $endDate
	 * @return mixed
	 */
	public function getTransactionalEmailReports( $tag, $startDate, $endDate ) {
		return $this->get(
			'/smtp/statistics/aggregatedReport',
			array(
				'startDate' => $startDate,
				'endDate'   => $endDate,
				'tag'       => $tag,
			)
		);
	}

	/**
	 * Create folder.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function createFolder( $data ) {
		return $this->post( '/contacts/folders', $data );
	}

	/**
	 * Get folders.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function getFolders( $data ) {
		return $this->get( '/contacts/folders', $data );
	}

	/**
	 * Get all folders.
	 *
	 * @return mixed
	 */
	public function getAllFolders() {
		$folders = array(
			'folders' => array(),
			'count'   => 0,
		);
		$offset  = 0;
		$limit   = 50;

		do {
			$folder_data = $this->getFolders(
				array(
					'limit'  => $limit,
					'offset' => $offset,
				)
			);
			if ( isset( $folder_data['folders'] ) && is_array( $folder_data['folders'] ) ) {
				$folders['folders'] = array_merge( $folders['folders'], $folder_data['folders'] );
				$offset            += 50;
				$folders['count']   = $folder_data['count'];
			}
		} while ( ! empty( $folders['folders'] ) && count( $folders['folders'] ) < $folder_data['count'] );

		return $folders;
	}

	/**
	 * Create installation info.
	 *
	 * @param $data
	 * @return mixed
	 */
	public function createInstallationInfo( $data ) {
		return $this->post( '/account/partner/information', $data );
	}

	/**
	 * Update installation info.
	 *
	 * @param installationId , $data
	 * @return mixed
	 */
	public function updateInstallationInfo( $installationId, $data ) {
		return $this->put( '/account/partner/information/' . $installationId, $data );
	}

	/**
	 * Get function.
	 *
	 * @param $endpoint
	 * @param array    $parameters
	 * @return mixed
	 */
	public function get( $endpoint, $parameters = array() ) {
		if ( $parameters ) {
			$endpoint .= '?' . http_build_query( $parameters );
		}
		return $this->makeHttpRequest( self::HTTP_METHOD_GET, $endpoint );
	}

	/**
	 * Post function.
	 *
	 * @param $endpoint
	 * @param array    $data
	 * @return mixed
	 */
	public function post( $endpoint, $data = array() ) {
		return $this->makeHttpRequest( self::HTTP_METHOD_POST, $endpoint, $data );
	}

	/**
	 * Put function.
	 *
	 * @param $endpoint
	 * @param array    $data
	 * @return mixed
	 */
	public function put( $endpoint, $data = array() ) {
		return $this->makeHttpRequest( self::HTTP_METHOD_PUT, $endpoint, $data );
	}

	/**
	 * Make http request.
	 *
	 * @param $method
	 * @param $endpoint
	 * @param array    $body
	 * @return mixed
	 */
	private function makeHttpRequest( $method, $endpoint, $body = array() ) {
		$url = self::API_BASE_URL . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'api-key'      => $this->apiKey,
				'Content-Type' => 'application/json',
				'sib-plugin'   => 'wc-' . self::PLUGIN_VERSION,
				'User-Agent'   => 'sendinblue_plugins/woocommerce',
			),
		);

		if ( self::HTTP_METHOD_GET != $method && self::HTTP_METHOD_DELETE != $method ) {
			if ( isset( $body['listIds'] ) ) {
				$body['listIds'] = array_map( 'intval', (array) $body['listIds'] );
			}
			if ( is_array( $body ) ) {
				foreach ( $body as $k => $v ) {
					if ( empty( $v ) && false != $v && 0 != $v ) {
						unset( $body[ $k ] );
					}
				}
			}
			$args['body'] = wp_json_encode( $body );
		}

		$response               = wp_remote_request( $url, $args );
		$data                   = wp_remote_retrieve_body( $response );
		$this->lastResponseCode = wp_remote_retrieve_response_code( $response );

		return json_decode( $data, true );
	}

	/**
	 * Get last responce.
	 *
	 * @return int
	 */
	public function getLastResponseCode() {
		return $this->lastResponseCode;
	}
}
