<?php

declare(strict_types=1);

namespace App\Security;

use EcPhp\CasBundle\Security\CasGuardAuthenticator as EcPhpCasGuardAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/**
 * Override the default CasGuardAuthenticator with a decorator pattern to return a user from a custom user provider.
 */
class CasGuardAuthenticator extends AbstractGuardAuthenticator
{
    protected UserProviderInterface $userProvider;

    protected EcPhpCasGuardAuthenticator $ecpPhpcasGuardAuthenticator;

    public function __construct(UserProviderInterface $userProvider, EcPhpCasGuardAuthenticator $ecpPhpcasGuardAuthenticator)
    {
        $this->userProvider = $userProvider;
        $this->ecpPhpcasGuardAuthenticator = $ecpPhpcasGuardAuthenticator;
    }

    public function checkCredentials($credentials, UserInterface $user): bool
    {
        return $this->ecpPhpcasGuardAuthenticator->checkCredentials($credentials, $user);
    }

    public function getCredentials(Request $request): ?ResponseInterface
    {
        return $this->ecpPhpcasGuardAuthenticator->getCredentials($request);
    }

    public function getUser($credentials, UserProviderInterface $userProvider): ?UserInterface
    {
        $casUser = $this->ecpPhpcasGuardAuthenticator->getUser($credentials, $userProvider);

        $user = $this->userProvider->loadUserByIdentifier($casUser->getUsername());

        return $user;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->ecpPhpcasGuardAuthenticator->onAuthenticationFailure($request, $exception);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey): Response
    {
        return $this->ecpPhpcasGuardAuthenticator->onAuthenticationSuccess($request, $token, $providerKey);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->ecpPhpcasGuardAuthenticator->start($request, $authException);
    }

    public function supports(Request $request): bool
    {
        return $this->ecpPhpcasGuardAuthenticator->supports($request);
    }

    public function supportsRememberMe(): bool
    {
        return $this->ecpPhpcasGuardAuthenticator->supportsRememberMe();
    }
}
