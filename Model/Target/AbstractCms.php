<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

use Magento\Framework\Model\AbstractModel;
use Zero\Cms\Model\Content\Repository as ContentRepository;

/**
 * CMS pages and blocks: the entity's own fields (title, status, meta: the
 * same in every store view it's assigned to) and its Zero CMS content, which
 * a store view may have of its own or inherit from All Store Views. The
 * content is saved through Zero CMS, so each switch shows in its versions.
 */
abstract class AbstractCms implements TargetInterface
{
    public const CONTENT = 'content';

    public function __construct(protected readonly ContentRepository $contentRepository)
    {
    }

    /** cms_page or cms_block, as Zero CMS names it. */
    abstract protected function contentType(): string;

    /**
     * The entity's own fields a change may set.
     *
     * @return array<string, array{label: string, type: string}>
     */
    abstract protected function entityFields(): array;

    abstract protected function load(int $id): ?AbstractModel;

    abstract protected function persist(AbstractModel $entity): void;

    public function isStoreScoped(): bool
    {
        return true;
    }

    public function fields(): array
    {
        $fields = [];
        foreach ($this->entityFields() as $code => $field) {
            $field['hint'] = (string) __('Same in every store view.');
            $fields[$code] = $field;
        }
        return $fields + [
            self::CONTENT => [
                'label' => (string) __('Content (Zero CMS)'),
                'type' => 'content',
                'hint' => (string) __('Edited in the content editor.'),
            ],
        ];
    }

    public function read(int $id, int $storeId, array $fields): array
    {
        $entity = $this->load($id);
        if (!$entity) {
            return [];
        }
        $values = [];
        foreach ($fields as $code) {
            if ($code === self::CONTENT) {
                $own = $this->contentRepository->getOwn($this->contentType(), $id, $storeId);
                $values[$code] = $own === null && $storeId !== 0
                    ? ['value' => $this->contentRepository->getOwn($this->contentType(), $id, 0), 'inherited' => true]
                    : ['value' => $own, 'inherited' => false];
            } elseif (isset($this->entityFields()[$code])) {
                $values[$code] = ['value' => $entity->getData($code), 'inherited' => false];
            }
        }
        return $values;
    }

    public function write(int $id, int $storeId, array $values): void
    {
        $entity = $this->load($id);
        if (!$entity) {
            throw new \RuntimeException((string) __('%1 %2 no longer exists.', $this->label(), $id));
        }
        $own = array_intersect_key($values, $this->entityFields());
        if ($own) {
            foreach ($own as $code => $entry) {
                $entity->setData($code, $entry['value'] ?? null);
            }
            $this->persist($entity);
        }
        if (array_key_exists(self::CONTENT, $values)) {
            $entry = $values[self::CONTENT];
            $content = $entry['value'] ?? null;
            if ((!empty($entry['inherited']) && $storeId !== 0) || !is_array($content)) {
                $this->contentRepository->delete($this->contentType(), $id, $storeId, 'Zero Staging');
            } else {
                $this->contentRepository->save($this->contentType(), $id, $storeId, $content, 'Zero Staging', 'campaign');
            }
        }
    }

    public function cacheTags(int $id): array
    {
        $entity = $this->load($id);
        return $entity instanceof \Magento\Framework\DataObject\IdentityInterface ? (array) $entity->getIdentities() : [];
    }
}
