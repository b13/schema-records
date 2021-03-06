<?php
declare(strict_types = 1);

namespace Brotkrueml\SchemaRecords\Middleware;

/*
 * This file is part of the "schema_records" extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use Brotkrueml\Schema\Core\Model\AbstractType;
use Brotkrueml\Schema\Manager\SchemaManager;
use Brotkrueml\Schema\Utility\Utility;
use Brotkrueml\SchemaRecords\Domain\Model\Property;
use Brotkrueml\SchemaRecords\Domain\Model\Type;
use Brotkrueml\SchemaRecords\Domain\Repository\TypeRepository;
use Brotkrueml\SchemaRecords\Enumeration\BoolEnumeration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class TypeEmbedding implements MiddlewareInterface
{
    private const MAX_NESTED_TYPES = 5;

    /** @var TypoScriptFrontendController */
    private $controller;

    /** @var ObjectManagerInterface */
    private $objectManager;

    /** @var SchemaManager */
    private $schemaManager;

    /** @var Dispatcher */
    private $signalSlotDispatcher;

    private $referencedRecords = [];
    private $processedRecords = [];

    /**
     * Counting the nested types, this can also be a hint of a type loop!
     *
     * @var int
     */
    private $nestedTypesCounter = 0;

    /**
     * The parameters are only used for easing the testing!
     *
     * @param TypoScriptFrontendController|null $controller
     * @param ObjectManagerInterface|null $objectManager
     * @param SchemaManager|null $schemaManager
     * @param Dispatcher|null $signalSlotDispatcher
     */
    public function __construct(
        TypoScriptFrontendController $controller = null,
        ObjectManagerInterface $objectManager = null,
        SchemaManager $schemaManager = null,
        Dispatcher $signalSlotDispatcher = null
    ) {
        $this->controller = $controller ?: $GLOBALS['TSFE'];
        $this->objectManager = $objectManager ?: GeneralUtility::makeInstance(ObjectManager::class);
        $this->schemaManager = $schemaManager ?: GeneralUtility::makeInstance(SchemaManager::class);
        $this->signalSlotDispatcher = $signalSlotDispatcher ?: GeneralUtility::makeInstance(Dispatcher::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var TypeRepository $typeRepository */
        $typeRepository = $this->objectManager->get(TypeRepository::class);
        $query = $typeRepository->createQuery();
        $querySettings = $query->getQuerySettings();
        $querySettings->setStoragePageIds([$request->getAttribute('routing')->getPageId()]);
        $typeRepository->setDefaultQuerySettings($querySettings);

        $records = $typeRepository->findAll();

        $this->referencedRecords = [];
        $this->processedRecords = [];

        foreach ($records as $record) {
            $this->nestedTypesCounter = 0;

            if (\array_key_exists($record->getUid(), $this->referencedRecords)) {
                continue;
            }

            $this->buildType($record, true);
        }

        foreach ($this->processedRecords as $recordUid => $processed) {
            if (\in_array($recordUid, $this->referencedRecords)) {
                continue;
            }

            if ($processed['isWebPageMainEntity']) {
                $this->schemaManager->setMainEntityOfWebPage($processed['type']);
            } else {
                $this->schemaManager->addType($processed['type']);
            }
        }

        return $handler->handle($request);
    }

    private function buildType(Type $record, $isRootType = false, $onlyReference = false): ?AbstractType
    {
        $this->nestedTypesCounter++;

        if ($this->nestedTypesCounter > static::MAX_NESTED_TYPES) {
            $message = sprintf(
                'Too many nested schema types in page uid "%s", last type "%s" with uid "%s"',
                $this->controller->page['uid'],
                $record->getSchemaType(),
                $record->getUid()
            );

            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(static::class)
                ->warning($message);

            $this->nestedTypesCounter--;

            return null;
        }

        /** @var Type $record */
        $typeClass = Utility::getNamespacedClassNameForType($record->getSchemaType());

        if (empty($typeClass)) {
            throw new \DomainException(
                sprintf('Type "%s" is not valid, no model found!', $record->getSchemaType()),
                1563797009
            );
        }

        /** @var AbstractType $typeModel */
        $typeModel = new $typeClass();

        $id = $record->getSchemaId();
        if (!empty($id)) {
            $typeModel->setId($id);
        }

        if (!$onlyReference || empty($id)) {
            foreach ($record->getProperties() as $property) {
                /** @var Property $property */
                switch ($property->getVariant()) {
                    case Property::VARIANT_SINGLE_VALUE:
                        $typeModel->addProperty(
                            $property->getName(),
                            $this->emitPlaceholderSubstitutionSignal($property->getSingleValue())
                        );
                        break;

                    case Property::VARIANT_URL:
                        $url = GeneralUtility::makeInstance(ContentObjectRenderer::class)->typoLink_URL([
                            'parameter' => $property->getUrl(),
                            'forceAbsoluteUrl' => 1
                        ]);

                        $typeModel->addProperty($property->getName(), $url);
                        break;

                    case Property::VARIANT_BOOLEAN:
                        $typeModel->setProperty(
                            $property->getName(),
                            $property->getFlag() ? BoolEnumeration::TRUE : BoolEnumeration::FALSE
                        );
                        break;

                    case Property::VARIANT_IMAGE:
                        $images = $property->getImages()->getArray();
                        if (!empty($images)) {
                            $imageService = GeneralUtility::makeInstance(ImageService::class);
                            $imagePath = $imageService->getImageUri(
                                $images[0]->getOriginalResource(),
                                true
                            );
                            $typeModel->addProperty($property->getName(), $imagePath);
                        }
                        break;

                    case Property::VARIANT_TYPE_REFERENCE:
                        $typeModel->addProperty(
                            $property->getName(),
                            $this->buildType($property->getTypeReference(), false, $property->getReferenceOnly())
                        );
                        break;

                    case Property::VARIANT_DATETIME:
                        if ($property->getTimestamp()) {
                            $dateTime = (new \DateTime())->setTimestamp($property->getTimestamp());
                            $typeModel->setProperty($property->getName(), $dateTime->format('c'));
                        }
                        break;

                    case Property::VARIANT_DATE:
                        if ($property->getTimestamp()) {
                            $dateTime = (new \DateTime())->setTimestamp($property->getTimestamp());
                            $typeModel->setProperty($property->getName(), $dateTime->format('Y-m-d'));
                        }
                        break;

                    default:
                        throw new \DomainException(
                            sprintf('Variant "%s" for a property is not valid!', $property->getVariant()),
                            1563791267
                        );
                }
            }

            if (!$isRootType) {
                $this->referencedRecords[] = $record->getUid();
            }
        }

        if ($isRootType) {
            $this->processedRecords[$record->getUid()] = [
                'isWebPageMainEntity' => $record->getWebpageMainentity(),
                'type' => $typeModel
            ];

            return null;
        }

        $this->nestedTypesCounter--;

        return $typeModel;
    }

    private function emitPlaceholderSubstitutionSignal(string $value): ?string
    {
        if (\strpos($value, '{') === 0) {
            $this->signalSlotDispatcher->dispatch(
                __CLASS__,
                'placeholderSubstitution',
                [&$value, $this->controller->page]
            );
        }

        return $value;
    }
}
