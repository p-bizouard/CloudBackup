<?php

declare(strict_types=1);

namespace App\Security;

use EcPhp\CasBundle\Security\CasAuthenticator as EcPhpCasdAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Override the default CasAuthenticator with a decorator pattern to return a user from a custom user provider.
 */
class CasAuthenticator extends AbstractAuthenticator
{
    protected UserProviderInterface $userProvider;

    protected EcPhpCasdAuthenticator $ecpPhpcasAuthenticator;

    public function __construct(UserProviderInterface $userProvider, EcPhpCasdAuthenticator $ecpPhpcasAuthenticator)
    {
        $this->userProvider = $userProvider;
        $this->ecpPhpcasAuthenticator = $ecpPhpcasAuthenticator;
    }

    public function authenticate(Request $request): Passport
    {
        return $this->ecpPhpcasAuthenticator->authenticate($request);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->ecpPhpcasAuthenticator->onAuthenticationFailure($request, $exception);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey): Response
    {
        return $this->ecpPhpcasAuthenticator->onAuthenticationSuccess($request, $token, $providerKey);
    }

    public function supports(Request $request): bool
    {
        return $this->ecpPhpcasAuthenticator->supports($request);
    }
}
