<?php

declare(strict_types=1);

namespace App\Security;

use EcPhp\CasBundle\Security\CasAuthenticator as EcPhpCasdAuthenticator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Override the default CasAuthenticator with a decorator pattern to return a user from a custom user provider.
 */
class CasAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private UserProviderInterface $userProvider,
        private EcPhpCasdAuthenticator $ecpPhpcasAuthenticator
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $passport = $this->ecpPhpcasAuthenticator->authenticate($request);

        $userEmail = $passport->getBadge(UserBadge::class)?->getUserIdentifier();

        if (null === $userEmail) {
            throw new AuthenticationException('No user email found.');
        }

        $user = $this->userProvider->loadUserByIdentifier($userEmail);

        if (null === $user) {
            throw new AuthenticationException('No user found.');
        }

        return new SelfValidatingPassport(
            new UserBadge($userEmail, function (string $userIdentifier) use ($user) {
                return $user;
            })
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        /** @var Session */
        $session = $request->getSession();

        $session->getFlashBag()->add('danger', $exception->getMessage());

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
