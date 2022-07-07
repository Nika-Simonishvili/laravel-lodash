<?php

declare(strict_types=1);

namespace App\Longman\LaravelLodash\Auth\Passport\Grants;

use App\Longman\LaravelLodash\Auth\Contracts\AuthServiceContract;
use App\Longman\LaravelLodash\Auth\Contracts\RefreshTokenBridgeRepositoryContract;
use DateInterval;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function is_null;

class GoogleAccessTokenGrant extends AbstractGrant
{
    protected readonly AuthServiceContract $authService;

    public function __construct(
        AuthServiceContract $authService,
        RefreshTokenBridgeRepositoryContract $refreshTokenRepository,
    ) {
        $this->authService = $authService;
        $this->setRefreshTokenRepository($refreshTokenRepository);
        $this->refreshTokenTTL = new DateInterval('P1M');
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTtl,
    ) {
        // Validate request
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request));
        $user = $this->validateUser($request);

        // Finalize the requested scopes
        $scopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $user->getIdentifier());

        // Issue and persist access token
        $accessToken = $this->issueAccessToken($accessTokenTtl, $client, $user->getIdentifier(), $scopes);
        $refreshToken = $this->issueRefreshToken($accessToken);

        // Inject access token into response type
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        // Fire login event
        $this->authService->fireLoginEvent('api', $user);

        return $responseType;
    }

    public function getIdentifier(): string
    {
        return 'google_access_token';
    }

    protected function validateClient(ServerRequestInterface $request): ClientEntityInterface
    {
        [$basicAuthUser,] = $this->getBasicAuthCredentials($request);

        $clientId = $this->getRequestParameter('client_id', $request, $basicAuthUser);
        if (is_null($clientId)) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        // Get client without validating secret
        $client = $this->clientRepository->getClientEntity($clientId);

        if ($client instanceof ClientEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));
            throw OAuthServerException::invalidClient($request);
        }

        return $client;
    }

    protected function validateUser(ServerRequestInterface $request): UserEntityInterface
    {
        $googleToken = $this->getRequestParameter('token', $request);
        if (is_null($googleToken)) {
            throw OAuthServerException::invalidRequest('token');
        }

        try {
            $user = $this->authService->getGoogleUserByAccessToken($googleToken);
        } catch (Throwable $e) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidRequest('token');
        }

        if (! $user instanceof UserEntityInterface) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidCredentials();
        }

        return $user;
    }
}
