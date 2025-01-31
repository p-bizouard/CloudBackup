<?php

// src/Security/Authentication/AuthenticationSuccessHandler.php

namespace App\Security\Authentication;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccessEvent')]
class AuthenticationSuccessHandler
{
    use TargetPathTrait;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onLoginSuccessEvent(LoginSuccessEvent $loginSuccessEvent): void
    {
        $token = $loginSuccessEvent->getAuthenticatedToken();

        if (($token instanceof UsernamePasswordToken || $token instanceof PostAuthenticationToken) && ($targetPath = $this->getTargetPath($loginSuccessEvent->getRequest()->getSession(), $token->getFirewallName()))) {
            $response = new RedirectResponse($targetPath);
        } else {
            $response = new RedirectResponse($this->urlGenerator->generate('admin_dashboard'));
        }

        $loginSuccessEvent->setResponse($response);
    }
}
