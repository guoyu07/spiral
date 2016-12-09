<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Spiral\Core\Component;
use Spiral\Core\ContainerInterface;
use Spiral\Debug\Traits\BenchmarkTrait;
use Spiral\Http\Exceptions\HttpException;
use Spiral\Http\Responses\Emitter;
use Spiral\Http\Traits\MiddlewaresTrait;
use Zend\Diactoros\Response as ZendResponse;
use Zend\Diactoros\Response\EmitterInterface;

/**
 * Magically simple implementation of PRS7 Http core.
 */
class HttpCore extends Component implements HttpInterface
{
    use BenchmarkTrait, MiddlewaresTrait;

    /**
     * @var EmitterInterface
     */
    private $emitter = null;

    /**
     * Dispatcher endpoint.
     *
     * @var string|callable|null
     */
    private $endpoint = null;

    /**
     * @invisible
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * @param ContainerInterface   $container Https requests are executed in a container scopes.
     * @param array                $middlewares
     * @param callable|null|string $endpoint
     */
    public function __construct(
        ContainerInterface $container,
        array $middlewares = [],
        callable $endpoint = null
    ) {
        $this->container = $container;
        $this->middlewares = $middlewares;
        $this->endpoint = $endpoint;
    }

    /**
     * @param EmitterInterface $emitter
     *
     * @return $this|self
     */
    public function setEmitter(EmitterInterface $emitter): HttpCore
    {
        $this->emitter = $emitter;

        return $this;
    }

    /**
     * Set endpoint as callable function or invokable class name (will be resolved using container).
     *
     * @param callable $endpoint
     *
     * @return $this|self
     */
    public function setEndpoint(callable $endpoint): HttpCore
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Pass request thought all http middlewares to appropriate endpoint. Default endpoint will be
     * used as fallback. Can thrown an exception happen in internal code.
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     *
     * @throws HttpException
     */
    public function perform(Request $request, Response $response = null): Response
    {
        //Init response with default headers and etc
        $response = $response ?? $this->initResponse();

        $endpoint = $this->getEndpoint();
        if (empty($endpoint)) {
            throw new HttpException("Unable to execute request without destination endpoint");
        }

        $pipeline = new MiddlewarePipeline($this->middlewares, $this->container);

        //Working in a scope
        $benchmark = $this->benchmark('request', $request->getUri());
        $scope = self::staticContainer($this->container);
        try {
            //Exceptions (including client one) must be handled by pipeline
            return $pipeline->target($endpoint)->run($request, $response);
        } finally {
            $this->benchmark($benchmark);
            self::staticContainer($scope);
        }
    }

    /**
     * Running spiral as middleware.
     *
     * @todo add ability to call $next on NotFound exceptions
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Response
     *
     * @throws HttpException
     */
    public function __invoke(Request $request, Response $response): Response
    {
        return $this->perform($request, $response);
    }

    /**
     * Dispatch response to client.
     *
     * @param Response $response
     *
     * @return null Specifically.
     */
    public function dispatch(Response $response)
    {
        if (empty($this->emitter)) {
            $this->emitter = new Emitter();
        }

        $this->emitter->emit($response, ob_get_level());

        return null;
    }

    /**
     * Create instance of initial response.
     *
     * @return Response
     */
    protected function initResponse(): Response
    {
        return new ZendResponse('php://memory');
    }

    /**
     * Default endpoint.
     *
     * @return callable|null
     */
    protected function getEndpoint()
    {
        if (empty($this->endpoint)) {
            return null;
        }

        if (!is_string($this->endpoint)) {
            //Presumably callable
            return $this->endpoint;
        }

        //Specified as class name
        return $this->container->get($this->endpoint);
    }
}