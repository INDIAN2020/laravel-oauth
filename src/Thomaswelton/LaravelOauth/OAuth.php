<?php namespace Thomaswelton\LaravelOauth;

use \Config;
use \Input;
use \Str;
use \Redis;
use \URL;
use \Session;

use OAuth\ServiceFactory;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Exception\TokenResponseException;

class OAuth extends ServiceFactory{

	public function login($provider, $redirect = null)
	{
		$oAuthLoginUrl = new OAuthLoginUrl($provider);

		if(!is_null($redirect)) $oAuthLoginUrl->redirect($redirect);

		return $oAuthLoginUrl;
	}

	public function token($provider)
	{
		return Session::get('oauth_token_' . $provider);
	}

	public function hasToken($provider)
	{
		return Session::has('oauth_token_' . $provider);
	}

	public function getAuthorizationUri($service, $redirect = null, $scope = null)
	{
		$factory = $this->getServiceFactory($service, $scope);

		$state = $this->encodeState(array(
			'redirect' => $redirect
		));

		if($this->isOAuth2($service)){
			$authUriArray = array('state' => $state);
		}else{
			$token = $factory->requestRequestToken();
			$requestToken = $token->getRequestToken();

			// No state in OAuth 1.0
			// Handles custom redirects by setting the redirect in a session
			$this->setOAuth1State($token->getRequestToken(), $state);

			$authUriArray = array( 'oauth_token' => $requestToken);
		}

		$authUrl = $factory->getAuthorizationUri($authUriArray);

		return htmlspecialchars_decode($authUrl);
	}

	public function getServiceFactory($service, $scope = null)
	{
		if(!$this->serviceExists($service)){
			throw  new ServiceNotSupportedException( Str::studly($service) . ' is not a supported OAuth1 or OAuth2 service provider');
		}

		$credentials = $this->getCredentials($service);
		$scopes 	 = (!is_null($scope)) ? array_map("trim", explode(',', $scope)) : array_values( $this->getScopes($service) );

		$storage 	 = $this->getStorage();

		if($this->isOAuth2($service)){
			return $this->createService($service, $credentials, $storage, $scopes);
		}else{
			return $this->createService($service, $credentials, $storage);
		}
	}

	public function getCredentials($service)
	{
		return new Credentials(
			Config::get("laravel-oauth::{$service}.key"),
		    Config::get("laravel-oauth::{$service}.secret"),
			url("oauth/{$service}")
		);
	}

	public function getScopes($service)
	{
		$array = explode(',', Config::get("laravel-oauth::{$service}.scope"));
		return array_map("trim", $array);
	}

	public function getStorage()
	{
		// LaravelSession implmentsTokenStorageInterface
		return new Common\Storage\LaravelSession();
	}

	public function serviceExists($service)
	{
		return ($this->isOAuth2($service) || $this->isOAuth1($service));
	}

	public function isOAuth2($service)
	{
		$serviceName = ucfirst($service);
		$className = "\\OAuth\\OAuth2\\Service\\$serviceName";

		return class_exists($className);
	}

	public function isOAuth1($service)
	{
		$serviceName = ucfirst($service);
		$className = "\\OAuth\\OAuth1\\Service\\$serviceName";

		return class_exists($className);
	}

	public function encodeState($state)
	{
		return base64_encode(json_encode($state));
	}

	public function decodeState($state)
	{
		return json_decode(base64_decode($state));
	}

	public function getRedirectFromState($provider)
	{
		$decodedState = null;

		if($this->isOAuth2($provider)){
			$state = Input::get('state');
			$decodedState = (object) $this->decodeState($state);
		}else{
			$service = $this->getServiceFactory($provider);

			$namespace 	= $this->getStorageNamespace($service);
			$token 		= $this->getStorage()->retrieveAccessToken($namespace);

			$requestToken = $token->getRequestToken();

			$state = $this->getOAuth1State($token->getRequestToken());

			$decodedState = $this->decodeState($state);
		}

		if(property_exists($decodedState, 'redirect')){
			return $decodedState->redirect;
		}
	}

	public function setOAuth1State($requestToken, $state)
	{
		Session::put($requestToken . '_state', $state);
	}

	public function getOAuth1State($requestToken)
	{
		return Session::get($requestToken . '_state');
	}

	public function getStorageNamespace($service)
	{
		// get class name without backslashes
        $classname = get_class($service);
        return preg_replace('/^.*\\\\/', '', $classname);
	}

	public function requestAccessToken($provider)
	{
		$service = $this->getServiceFactory($provider);

		if($this->isOAuth2($provider)){
			// error required by OAuth 2.0 error_description optional
			$error = Input::get('error');

			if($error){
				$errorDescription = Input::get('error_description');
				$errorMessage = ($errorDescription) ? $errorDescription : $error;

				if($error == 'access_denied'){
					throw new UserDeniedException($errorMessage, 1);
				}else{
					throw new Exception($errorMessage, 1);
				}
			}

			try{
				$token = $service->requestAccessToken(Input::get('code'));
				Session::set('oauth_token_' . $provider, $token);

				return $token;
			}catch(TokenResponseException $e){
				throw new Exception($e->getMessage(), 1);
			}
		}else{
			if(Input::get('denied') || Input::get('oauth_token') == 'denied'){
				throw new UserDeniedException('User Denied OAuth Permissions', 1);
			}

			if(!Input::get('oauth_token')){
				throw new Exception("OAuth token not found", 1);
			}

			$namespace 	= $this->getStorageNamespace($service);
			$token 		= $this->getStorage()->retrieveAccessToken($namespace);

			try{
				$token = $service->requestAccessToken( Input::get('oauth_token'), Input::get('oauth_verifier'), $token->getRequestTokenSecret() );

				Session::set('oauth_token_' . $provider, $token);
				return $token;
			}catch(TokenResponseException $e){
				throw new Exception($e->getMessage(), 1);
			}
		}
	}
}
