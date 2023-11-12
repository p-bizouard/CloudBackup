<?php

declare(strict_types=1);

namespace App\Security;

use EcPhp\CasBundle\Security\CasAuthenticator as EcPhpCasdAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Override the default CasAuthenticator with a decorator pattern to return a user from a custom user provider.
 */
class CasAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly UserProviderInterface $userProvider,
        private readonly EcPhpCasdAuthenticator $ecPhpCasdAuthenticator,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $passport = $this->ecPhpCasdAuthenticator->authenticate($request);

        $userEmail = $passport->getBadge(UserBadge::class)?->getUserIdentifier();

        if (null === $userEmail) {
            throw new AuthenticationException('No user email found.');
        }

        $user = $this->userProvider->loadUserByIdentifier($userEmail);

        return new SelfValidatingPassport(
            new UserBadge($userEmail, function (string $userIdentifier) use ($user) {
                return $user;
            })
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $authenticationException): ?Response
    {
        /** @var Session */
        $session = $request->getSession();

        $session->getFlashBag()->add('danger', $authenticationException->getMessage());

        return $this->ecPhpCasdAuthenticator->onAuthenticationFailure($request, $authenticationException);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $providerKey): Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), 'main')) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('admin'));
    }

    public function supports(Request $request): bool
    {
        return $this->ecPhpCasdAuthenticator->supports($request);
    }
}
