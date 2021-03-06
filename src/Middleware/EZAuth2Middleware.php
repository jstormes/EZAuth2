<?php
/**
 * Created by PhpStorm.
 * User: jstormes
 * Date: 6/23/2018
 * Time: 4:43 PM
 */

namespace JStormes\EZAuth2\Middleware;

use JStormes\EZAuth2\Entities\OAuth2EntityInterface;
use JStormes\EZAuth2\Entities\OAuth2Entity;
use JStormes\EZAuth2\Crypt\CryptKey;
use JStormes\EZAuth2\Exception\ExpiredToken;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use PSR7Sessions\Storageless\Session\DefaultSessionData;
use PSR7Sessions\Storageless\Http\SessionMiddleware;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Provider\GenericProvider;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Diactoros\Uri;


class EZAuth2Middleware implements MiddlewareInterface
{
    public const OAUTH2_CLAIMS         = 'OAuth2Claims';
    public const OAUTH2_JWT_TOKEN      = 'jwtToken';
    private const OAUTH2_STATE         = 'OAuth2State';
    private const OAUTH2_REFRESH_TOKEN = 'refreshToken';

    /** @var GenericProvider */
    private $provider;

    /** @var OAuth2EntityInterface  */
    private $OAuth2EntityPrototype;

    /** @var LoggerInterface  */
    private $log;

    /** @var bool  */
    private $passOptionsThrough;

    public function __construct(string $serverUri, string $clientId, string $clientSecret, $scopes = null, LoggerInterface $log = null, bool $passOptionsThrough=false)
    {

        $options = [
            'clientId'                => $clientId,     // The client ID assigned to you by the provider
            'clientSecret'            => $clientSecret, // The client password assigned to you by the provider
            'redirectUri'             => $serverUri.'/app',
            'urlAuthorize'            => $serverUri.'/oauth2/auth',
            'urlAccessToken'          => $serverUri.'/oauth2',
            'urlResourceOwnerDetails' => $serverUri.'/oauth2/user/resource'
        ];

        $provider = new GenericProvider($options);
        $this->provider = $provider;

        $OAuth2Prototype = new OAuth2Entity();

        $cryptKey = new CryptKey($serverUri, null, false);
        $OAuth2Prototype->setKey($cryptKey);
        $this->OAuth2EntityPrototype = $OAuth2Prototype;

        $this->log = $log;
        $this->passOptionsThrough = $passOptionsThrough;
    }


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler) : ResponseInterface
    {
        if ($request->getHeader("access-control-request-method")[0] === "OPTIONS") {
            if ($this->passOptionsThrough) {
                return $handler->handle($request);
            }
            return new JsonResponse([]);
        }

        if ($this->isJsonRequest($request)){
            try {
                if ($request->hasHeader('authorization')) {
                    $authorizationHeader = $request->getHeader('authorization')[0];
                    $jwt = trim(preg_replace('/^(?:\s+)?Bearer\s/', '', $authorizationHeader));
                    $this->OAuth2EntityPrototype->setJWT($jwt);
                    return $this->addOAuth2EntityToResponse($handler, $request, $this->OAuth2EntityPrototype);
                }
            }
            catch (ExpiredToken $ex)
            {
                return new JsonResponse(['error'=>['code'=>401,'message'=>'Token Expired']],401);
            }
            catch (\Exception $ex)
            {
                return new JsonResponse(['error'=>['code'=>403,'message'=>'Bad Token']],403);
            }
        }

        /* @var DefaultSessionData $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        if ($session===null) {
            throw new \Exception('Session Attribute not available in request Attributes.');
        }

        try
        {
            if ($session->has(self::OAUTH2_JWT_TOKEN)){
                $jwtToken = $session->get(self::OAUTH2_JWT_TOKEN);
                $this->OAuth2EntityPrototype->setJWT($jwtToken);
                return $this->addOAuth2EntityToResponse($handler, $request, $this->OAuth2EntityPrototype);
            }
        }
        catch (ExpiredToken $ex)
        {
            try
            {
                $accessToken = $this->provider->getAccessToken('refresh_token', [
                    'refresh_token' => $session->get(self::OAUTH2_REFRESH_TOKEN)
                ]);
                $jwtToken=$accessToken->getToken();
                $session->set(self::OAUTH2_JWT_TOKEN, $jwtToken);
                // TODO: Set REFRESH??

                $this->OAuth2EntityPrototype->setJWT($jwtToken);
                return $this->addOAuth2EntityToResponse($handler, $request, $this->OAuth2EntityPrototype);
            }
            catch (\Exception $ex)
            {
                // TODO: Log exception
                throw $ex;
            }

        }
        catch (\Exception $ex)
        {
            // TODO: Log exception
            throw $ex;
        }

        if ($this->isCodeInRequest($request))
        {
            if ($this->isRequestStateValid($request, $session->get(self::OAUTH2_STATE)))
            {
                $session->remove(self::OAUTH2_STATE);
                $queryParams = $request->getQueryParams();
                $accessToken = $this->getAccessTokenFromOAuth2Server($queryParams['code']);
                $session->set(self::OAUTH2_JWT_TOKEN, $accessToken->getToken());
                $session->set(self::OAUTH2_REFRESH_TOKEN, $accessToken->getRefreshToken());
                $requestedUrl = $session->get('requestedUrl');
                $session->remove('requestedUrl');
                return new RedirectResponse( $requestedUrl );
            }
        }

        $actual_link = $request->getUri()->__toString();
        if ($request->hasHeader('upgrade-insecure-requests')) {
            $actual_link = $request->getUri()->withScheme('https')->__toString();
        }

        $session->set('requestedUrl',$actual_link);
        $authorizationUrl = $this->provider->getAuthorizationUrl();
        $session->set(self::OAUTH2_STATE,$this->provider->getState());
        $response = new RedirectResponse($authorizationUrl);
        return $response;
    }

    private function isJsonRequest(ServerRequestInterface $request){

        if ($request->hasHeader('authorization')){
            return true;
        }

        if ($request->hasHeader('accept')) {
            $acceptHeader = $request->getHeader('accept')[0];
            if (stristr($acceptHeader,'json')!==false) {
                return true;
            }
        }
        return false;
    }

    private function addOAuth2EntityToResponse($handler, $request, $OAuth2Prototype)
    {
        /** @var OAuth2EntityInterface $OAuth2 */
        $OAuth2 = clone $OAuth2Prototype;
        return $handler->handle($request->withAttribute(self::OAUTH2_CLAIMS, $OAuth2));
    }

    private function isCodeInRequest($request)
    {
        $queryParams = $request->getQueryParams();
        return isset($queryParams['code']);
    }

    private function isRequestStateValid($request, $state)
    {
        $params = $request->getQueryParams();

        if (empty($params['state'])) {
            return false;
        }

        if ($params['state'] === $state) {
            return true;
        }

        return false;
    }

    private function getAccessTokenFromOAuth2Server($code) : AccessToken
    {
        $accessToken = $this->provider->getAccessToken('authorization_code', [
            'code' => $code
        ]);

        return $accessToken;
    }

}