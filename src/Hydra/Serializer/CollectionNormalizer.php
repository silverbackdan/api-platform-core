<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Hydra\Serializer;

use ApiPlatform\Core\Api\IriConverterInterface;
use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\Api\ResourceClassResolverInterface;
use ApiPlatform\Core\DataProvider\PaginatorInterface;
use ApiPlatform\Core\DataProvider\PartialPaginatorInterface;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use ApiPlatform\Core\JsonLd\ContextBuilderInterface;
use ApiPlatform\Core\JsonLd\Serializer\JsonLdContextTrait;
use ApiPlatform\Core\Serializer\ContextTrait;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * This normalizer handles collections.
 *
 * @author Kevin Dunglas <dunglas@gmail.com>
 * @author Samuel ROZE <samuel.roze@gmail.com>
 */
final class CollectionNormalizer implements NormalizerInterface, NormalizerAwareInterface, CacheableSupportsMethodInterface
{
    use ContextTrait;
    use JsonLdContextTrait;
    use NormalizerAwareTrait;

    public const FORMAT = 'jsonld';

    private $contextBuilder;
    private $resourceClassResolver;
    private $iriConverter;

    public function __construct(ContextBuilderInterface $contextBuilder, ResourceClassResolverInterface $resourceClassResolver, IriConverterInterface $iriConverter)
    {
        $this->contextBuilder = $contextBuilder;
        $this->resourceClassResolver = $resourceClassResolver;
        $this->iriConverter = $iriConverter;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return self::FORMAT === $format && is_iterable($data);
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        if (isset($context['api_sub_level'])) {
            return $this->normalizeRawCollection($object, $format, $context);
        }

        try {
            $resourceClass = $this->resourceClassResolver->getResourceClass($object, $context['resource_class'] ?? null, true);
        } catch (InvalidArgumentException $e) {
            if (!isset($context['resource_class'])) {
                return $this->normalizeRawCollection($object, $format, $context);
            }

            throw $e;
        }
        $data = $this->addJsonLdContext($this->contextBuilder, $resourceClass, $context);
        $context = $this->initContext($resourceClass, $context);

        if (isset($context['operation_type']) && OperationType::SUBRESOURCE === $context['operation_type']) {
            $data['@id'] = $this->iriConverter->getSubresourceIriFromResourceClass($resourceClass, $context);
        } else {
            $data['@id'] = $this->iriConverter->getIriFromResourceClass($resourceClass);
        }

        $data['@type'] = 'hydra:Collection';

        $data['hydra:member'] = [];
        foreach ($object as $obj) {
            $data['hydra:member'][] = $this->normalizer->normalize($obj, $format, $context);
        }

        $paginated = null;
        if (
            \is_array($object) ||
            ($paginated = $object instanceof PaginatorInterface) ||
            $object instanceof \Countable && !$object instanceof PartialPaginatorInterface
        ) {
            $data['hydra:totalItems'] = $paginated ? $object->getTotalItems() : \count($object);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function hasCacheableSupportsMethod(): bool
    {
        return true;
    }

    /**
     * Normalizes a raw collection (not API resources).
     */
    private function normalizeRawCollection($object, $format = null, array $context = []): array
    {
        $data = [];
        foreach ($object as $index => $obj) {
            $data[$index] = $this->normalizer->normalize($obj, $format, $context);
        }

        return $data;
    }
}
