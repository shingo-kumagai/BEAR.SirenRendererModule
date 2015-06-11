<?php
/**
 * This file is part of the BEAR.SirenRenderer package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\SirenRenderer\Provide;

use BEAR\Resource\Exception\BadRequestException;
use BEAR\Resource\FactoryInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\SirenRenderer\Annotation\Action;
use BEAR\SirenRenderer\Annotation\EmbedLink;
use BEAR\SirenRenderer\Annotation\EmbedResource;
use BEAR\SirenRenderer\Annotation\Field;
use BEAR\SirenRenderer\Annotation\Name;
use BEAR\SirenRenderer\Annotation\Title;
use Doctrine\Common\Annotations\Reader;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

final class EmbedResourceInterceptor implements MethodInterceptor
{
    /**
     * @var \BEAR\Resource\ResourceInterface
     */
    private $resource;
    /**
     * @var Reader
     */
    private $reader;
    /**
     * @param ResourceInterface $resource
     * @param Reader            $reader
     */
    public function __construct(ResourceInterface $resource, Reader $reader)
    {
        $this->resource = $resource;
        $this->reader = $reader;
    }
    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        /** @var $resourceObject ResourceObject */
        $resourceObject = $invocation->getThis();
        $method = $invocation->getMethod();
        $query = $this->getArgsByInvocation($invocation);
        $embeds = $this->reader->getMethodAnnotations($method);
        // embedding resource
        $this->embedResource($embeds, $resourceObject, $query);
        // request (method can modify embedded resource)
        $result = $invocation->proceed();
        return $result;
    }
    /**
     * @param Embed[]        $embeds
     * @param ResourceObject $resourceObject
     * @param array          $query
     */
    private function embedResource(array $embeds, ResourceObject $resourceObject, array $query)
    {
        foreach ($embeds as $embed) {
            /* @var $embed EmbedResource */
            if (! $embed instanceof EmbedResource) {
                continue;
            }
            try {
                $templateUri = $this->getFullUri($embed->src, $resourceObject);
                $uri = uri_template($templateUri, $query);
                $resourceObject->body[$embed->rel] = clone $this->resource->get->uri($uri);
            } catch (BadRequestException $e) {
                // wrap ResourceNotFound or Uri exception
                throw new EmbedException($embed->src, 500, $e);
            }
        }
    }
    /**
     * @param string         $uri
     * @param ResourceObject $resourceObject
     *
     * @return string
     */
    private function getFullUri($uri, ResourceObject $resourceObject)
    {
        if (substr($uri, 0, 1) == '/') {
            $uri = "{$resourceObject->uri->scheme}://{$resourceObject->uri->host}" . $uri;
        }
        return $uri;
    }
    /**
     * @param MethodInvocation $invocation
     *
     * @return array
     */
    private function getArgsByInvocation(MethodInvocation $invocation)
    {
        $args = $invocation->getArguments()->getArrayCopy();
        $params = $invocation->getMethod()->getParameters();
        $namedParameters = [];
        foreach ($params as $param) {
            $namedParameters[$param->name] = array_shift($args);
        }
        return $namedParameters;
    }
}