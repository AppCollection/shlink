<?php
declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\Action;

use Interop\Http\ServerMiddleware\DelegateInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophecy\ObjectProphecy;
use Shlinkio\Shlink\Core\Action\RedirectAction;
use Shlinkio\Shlink\Core\Exception\EntityDoesNotExistException;
use Shlinkio\Shlink\Core\Service\UrlShortener;
use Shlinkio\Shlink\Core\Service\VisitsTracker;
use ShlinkioTest\Shlink\Common\Util\TestUtils;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequestFactory;

class RedirectActionTest extends TestCase
{
    /**
     * @var RedirectAction
     */
    protected $action;
    /**
     * @var ObjectProphecy
     */
    protected $urlShortener;

    public function setUp()
    {
        $this->urlShortener = $this->prophesize(UrlShortener::class);
        $visitTracker = $this->prophesize(VisitsTracker::class);
        $visitTracker->track(Argument::any());
        $this->action = new RedirectAction($this->urlShortener->reveal(), $visitTracker->reveal());
    }

    /**
     * @test
     */
    public function redirectionIsPerformedToLongUrl()
    {
        $shortCode = 'abc123';
        $expectedUrl = 'http://domain.com/foo/bar';
        $this->urlShortener->shortCodeToUrl($shortCode)->willReturn($expectedUrl)
                                                       ->shouldBeCalledTimes(1);

        $request = ServerRequestFactory::fromGlobals()->withAttribute('shortCode', $shortCode);
        $response = $this->action->process($request, TestUtils::createDelegateMock()->reveal());

        $this->assertInstanceOf(Response\RedirectResponse::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
        $this->assertEquals($expectedUrl, $response->getHeaderLine('Location'));
    }

    /**
     * @test
     */
    public function nextMiddlewareIsInvokedIfLongUrlIsNotFound()
    {
        $shortCode = 'abc123';
        $this->urlShortener->shortCodeToUrl($shortCode)->willThrow(EntityDoesNotExistException::class)
                                                       ->shouldBeCalledTimes(1);
        $delegate = $this->prophesize(DelegateInterface::class);
        /** @var MethodProphecy $process */
        $process = $delegate->process(Argument::any())->willReturn(new Response());

        $request = ServerRequestFactory::fromGlobals()->withAttribute('shortCode', $shortCode);
        $this->action->process($request, $delegate->reveal());

        $process->shouldHaveBeenCalledTimes(1);
    }
}
