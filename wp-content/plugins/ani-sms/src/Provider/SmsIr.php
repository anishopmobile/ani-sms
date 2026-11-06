<?php
/** Original WordPress HTTP adapter; no third-party SDK runtime. @package AniSms */
namespace AniSms\Provider;

final class SmsIr implements Provider {
	public function __construct( #[\SensitiveParameter] private string $key ) {}
	public function capabilities(): array {
		return array(
			'template'   => true,
			'connection' => true,
			'text'       => false,
			'delivery'   => false,
		); }
	public function send_template( string $phone, int $template, string $parameter, #[\SensitiveParameter] string $code ): Result {
		$phone = \AniSms\Support\Phone::normalize( $phone );
		if ( $template < 1 || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $parameter ) || ! preg_match( '/^[0-9]{6}$/D', $code ) ) {
			return new Result( 'failed', 'configuration' ); }
		return $this->request(
			'POST',
			'/v1/send/verify/',
			array(
				'mobile'     => '0' . substr( $phone, 3 ),
				'templateId' => $template,
				'parameters' => array(
					array(
						'name'  => $parameter,
						'value' => $code,
					),
				),
			)
		);
	}
	public function connection(): Result {
		$result = $this->request( 'GET', '/v1/credit' );
		return new Result( $result->state, $result->error, credit: $result->credit, operation: 'connection' );
	}
	private function request( string $method, string $path, #[\SensitiveParameter] array $payload = array() ): Result {
		if ( '' === $this->key || strlen( $this->key ) > 512 || preg_match( '/[\r\n]/', $this->key ) ) {
			return new Result( 'failed', 'configuration' ); }
		$args = array(
			'method'              => $method,
			'timeout'             => 10,
			'redirection'         => 0,
			'sslverify'           => true,
			'limit_response_size' => 65536,
			'headers'             => array(
				'X-API-KEY'    => $this->key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);
		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $payload ); }
		$response  = wp_remote_request( 'https://api.sms.ir' . $path, $args );
		$ambiguous = 'POST' === $method ? 'unknown' : 'failed';
		if ( is_wp_error( $response ) ) {
			return new Result( $ambiguous, 'transport' ); }
		$http = wp_remote_retrieve_response_code( $response );
		if ( $http < 200 || $http >= 300 ) {
			return new Result( in_array( $http, array( 400, 401, 403, 422 ), true ) ? 'failed' : $ambiguous, 429 === $http ? 'provider_limit' : 'http' );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['status'] ) || ! is_int( $body['status'] ) ) {
			return new Result( $ambiguous, 'malformed' ); }
		if ( 1 !== $body['status'] ) {
			return new Result( 'failed', 'rejected' ); }
		if ( 'GET' === $method ) {
			$credit = $body['data'] ?? null;
			return is_int( $credit ) || is_float( $credit ) ? new Result( 'accepted', credit: (float) $credit ) : new Result( 'failed', 'malformed' );
		}
		$id = $body['data']['messageId'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return new Result( 'unknown', 'malformed' ); }
		return new Result( 'accepted', message_id: (string) $id );
	}
}
