<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Controller\BackendPreviewSwitchController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\Authentication\FrontendPreviewAuthenticator;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Tests\TestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class BackendPreviewSwitchControllerTest extends TestCase
{
    public function testExitsOnNonAjaxRequest(): void
    {
        $controller = new BackendPreviewSwitchController(
            $this->createMock(FrontendPreviewAuthenticator::class),
            $this->mockTokenChecker(),
            $this->createMock(Connection::class),
            $this->mockSecurity(),
            $this->getTwigMock(),
            $this->mockRouter(),
            $this->mockTokenManager(),
        );

        $request = $this->createMock(Request::class);
        $request
            ->method('isXmlHttpRequest')
            ->willReturn(false)
        ;

        $response = $controller($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testRendersToolbar(): void
    {
        $controller = new BackendPreviewSwitchController(
            $this->createMock(FrontendPreviewAuthenticator::class),
            $this->mockTokenChecker(),
            $this->createMock(Connection::class),
            $this->mockSecurity(),
            $this->getTwigMock(),
            $this->mockRouter(),
            $this->mockTokenManager(),
        );

        $request = $this->createMock(Request::class);
        $request
            ->method('isXmlHttpRequest')
            ->willReturn(true)
        ;

        $request
            ->method('isMethod')
            ->with('GET')
            ->willReturn(true)
        ;

        $response = $controller($request);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('CONTAO', $response->getContent());
    }

    public function testAddsShareLinkToToolbar(): void
    {
        $controller = new BackendPreviewSwitchController(
            $this->createMock(FrontendPreviewAuthenticator::class),
            $this->mockTokenChecker(),
            $this->createMock(Connection::class),
            $this->mockSecurity(true),
            $this->getTwigMock(),
            $this->mockRouter(true),
            $this->mockTokenManager(),
        );

        $request = $this->createMock(Request::class);
        $request
            ->method('isXmlHttpRequest')
            ->willReturn(true)
        ;

        $request
            ->method('isMethod')
            ->with('GET')
            ->willReturn(true)
        ;

        $controller($request);
    }

    /**
     * @dataProvider getAuthenticationScenarios
     */
    public function testProcessesAuthentication(string|null $username, string $authenticateMethod): void
    {
        $frontendPreviewAuthenticator = $this->createMock(FrontendPreviewAuthenticator::class);
        $frontendPreviewAuthenticator
            ->expects($this->once())
            ->method($authenticateMethod)
        ;

        $controller = new BackendPreviewSwitchController(
            $frontendPreviewAuthenticator,
            $this->mockTokenChecker($username),
            $this->createMock(Connection::class),
            $this->mockSecurity(),
            $this->getTwigMock(),
            $this->mockRouter(),
            $this->mockTokenManager(),
        );

        $request = new Request(
            [],
            [
                'FORM_SUBMIT' => 'tl_switch',
                'user' => $username,
            ],
            [],
            [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'REQUEST_METHOD' => 'POST']
        );

        $response = $controller($request);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function getAuthenticationScenarios(): \Generator
    {
        yield [null, 'authenticateFrontendGuest'];
        yield ['', 'authenticateFrontendGuest'];
        yield ['k.jones', 'authenticateFrontendUser'];
    }

    public function testReturnsEmptyMemberList(): void
    {
        $controller = new BackendPreviewSwitchController(
            $this->createMock(FrontendPreviewAuthenticator::class),
            $this->mockTokenChecker(),
            $this->createMock(Connection::class),
            $this->mockSecurity(),
            $this->getTwigMock(),
            $this->mockRouter(),
            $this->mockTokenManager(),
        );

        $request = new Request(
            [],
            ['FORM_SUBMIT' => 'datalist_members'],
            [],
            [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'REQUEST_METHOD' => 'POST']
        );

        $response = $controller($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(json_encode([]), $response->getContent());
    }

    /**
     * @return RouterInterface&MockObject
     */
    private function mockRouter(bool $canShare = false, bool $isPreviewMode = true): RouterInterface
    {
        $router = $this->createMock(RouterInterface::class);

        if ($canShare) {
            $router
                ->expects($this->exactly(2))
                ->method('generate')
                ->withConsecutive(
                    [
                        'contao_backend',
                        ['do' => 'preview_link', 'act' => 'create', 'showUnpublished' => $isPreviewMode ? '1' : '', 'rt' => 'csrf', 'nb' => '1'],
                    ],
                    ['contao_backend_switch']
                )
                ->willReturn('/_contao/preview/1', '/contao/preview_switch')
            ;
        } else {
            $router
                ->method('generate')
                ->with('contao_backend_switch')
                ->willReturn('/contao/preview_switch')
            ;
        }

        return $router;
    }

    /**
     * @return TokenChecker&MockObject
     */
    private function mockTokenChecker(string $frontendUsername = null, bool $previewMode = true): TokenChecker
    {
        $tokenChecker = $this->createMock(TokenChecker::class);
        $tokenChecker
            ->method('getFrontendUsername')
            ->willReturn($frontendUsername)
        ;

        $tokenChecker
            ->method('isPreviewMode')
            ->willReturn($previewMode)
        ;

        return $tokenChecker;
    }

    /**
     * @return Security&MockObject
     */
    private function mockSecurity(bool $canShare = false): Security
    {
        $user = $this->createMock(BackendUser::class);

        $security = $this->createMock(Security::class);
        $security
            ->method('getUser')
            ->willReturn($user)
        ;

        if ($canShare) {
            $security
                ->expects($this->exactly(2))
                ->method('isGranted')
                ->withConsecutive(
                    ['ROLE_ALLOWED_TO_SWITCH_MEMBER'],
                    [ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'preview_link']
                )
                ->willReturn(true, $canShare)
            ;
        }

        return $security;
    }

    /**
     * @return Environment&MockObject
     */
    private function getTwigMock(): Environment
    {
        $twig = $this->createMock(Environment::class);
        $twig
            ->method('render')
            ->willReturn('CONTAO')
        ;

        return $twig;
    }

    /**
     * @return ContaoCsrfTokenManager&MockObject
     */
    private function mockTokenManager(): ContaoCsrfTokenManager
    {
        $tokenManager = $this->createMock(ContaoCsrfTokenManager::class);
        $tokenManager
            ->method('getDefaultTokenValue')
            ->willReturn('csrf')
        ;

        return $tokenManager;
    }
}
