<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Routing\ResponseContext;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class ResponseContextAccessor
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function getResponseContext(): ResponseContext|null
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->attributes->get(ResponseContext::REQUEST_ATTRIBUTE_NAME);
    }

    public function setResponseContext(ResponseContext|null $responseContext): self
    {
        $request = $this->requestStack->getCurrentRequest();
        $request?->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $responseContext);

        return $this;
    }

    public function endCurrentContext(): self
    {
        $this->setResponseContext(null);

        return $this;
    }

    /**
     * Every controller is free to call this method or not. After all, it's the
     * controller that specifies the response context and which parts of it it
     * wants to apply.
     *
     * This method applies the header bag and then ends the current context.
     */
    public function finalizeCurrentContext(Response $response): self
    {
        $responseContext = $this->getResponseContext();

        if (null === $responseContext) {
            return $this;
        }

        foreach ($responseContext->getHeaderBag()->all() as $name => $values) {
            $response->headers->set($name, $values, false); // Do not replace but add
        }

        $this->endCurrentContext();

        return $this;
    }
}
