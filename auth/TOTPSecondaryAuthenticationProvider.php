<?php

use MediaWiki\Auth\AbstractSecondaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\Throttler;
use MediaWiki\Session\SessionManager;

/**
 * AuthManager secondary authentication provider for TOTP second-factor authentication.
 *
 * After a successful primary authentication, requests a time-based one-time password
 * (typically generated by a mobile app such as Google Authenticator) from the user.
 *
 * @see AuthManager
 * @see https://en.wikipedia.org/wiki/Time-based_One-time_Password_Algorithm
 */
class TOTPSecondaryAuthenticationProvider extends AbstractSecondaryAuthenticationProvider {

	public function getAuthenticationRequests( $action, array $options ) {
		switch ( $action ) {
			case AuthManager::ACTION_LOGIN:
				// don't ask for anything initially so the second factor is on a separate screen
				return [];
			default:
				return [];
		}
	}

	/**
	 * If the user has enabled two-factor authentication, request a second factor.
	 * @inheritdoc
	 */
	public function beginSecondaryAuthentication( $user, array $reqs ) {
		$oathuser = OATHAuthHooks::getOATHUserRepository()->findByUser( $user );

		if ( $oathuser->getKey() === null ) {
			return AuthenticationResponse::newAbstain();
		} else {
			return AuthenticationResponse::newUI( array( new TOTPAuthenticationRequest() ),
				wfMessage( 'oathauth-auth-ui' ), 'warning' );
		}
	}

	/**
	 * Verify the second factor.
	 * @inheritdoc
	 */
	public function continueSecondaryAuthentication( $user, array $reqs ) {
		/** @var TOTPAuthenticationRequest $request */
		$request = AuthenticationRequest::getRequestByClass( $reqs, TOTPAuthenticationRequest::class );
		if ( !$request ) {
			return AuthenticationResponse::newUI( array( new TOTPAuthenticationRequest() ),
				wfMessage( 'oathauth-login-failed' ), 'error' );
		}

		$throttler = new Throttler( null, [ 'type' => 'TOTP' ] );
		$result = $throttler->increase( $user->getName(), null, __METHOD__ );
		if ( $result ) {
			return AuthenticationResponse::newUI( array( new TOTPAuthenticationRequest() ),
				new Message(
					'oathauth-throttled',
					[ Message::durationParam( $result['wait'] ) ]
				), 'error' );
		}

		$oathuser = OATHAuthHooks::getOATHUserRepository()->findByUser( $user );
		$token = $request->OATHToken;

		if ( $oathuser->getKey() === null ) {
			$this->logger->warning( 'Two-factor authentication was disabled mid-authentication for '
				. $user->getName() );
			return AuthenticationResponse::newAbstain();
		}

		if ( $oathuser->getKey()->verifyToken( $token, $oathuser ) ) {
			$throttler->clear( $user->getName(), null );
			return AuthenticationResponse::newPass();
		} else {
			return AuthenticationResponse::newUI( array( new TOTPAuthenticationRequest() ),
				wfMessage( 'oathauth-login-failed' ), 'error' );
		}
	}

	public function beginSecondaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}
}
