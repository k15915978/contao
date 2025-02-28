<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\ManagerBundle\Security\Logout;

use Contao\ManagerBundle\HttpKernel\JwtManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Logout\LogoutHandlerInterface;

class LogoutHandler implements LogoutHandlerInterface
{
    /**
     * @internal Do not inherit from this class; decorate the "contao_manager.security.logout_handler" service instead
     */
    public function __construct(private JwtManager|null $jwtManager = null)
    {
    }

    public function logout(Request $request, Response $response, TokenInterface $token): void
    {
        $this->jwtManager?->clearResponseCookie($response);
    }
}
