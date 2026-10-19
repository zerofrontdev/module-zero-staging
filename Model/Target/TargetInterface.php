<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Target;

/**
 * Something a campaign can change: a product, a CMS page, a banner slide.
 * Registered in Pool (di.xml) under its code; another module adds its own
 * entities the same way.
 *
 * Values travel as the target reads and writes them (strings, numbers, a
 * Zero CMS content array); read() also says whether a store view's value is
 * its own or the one it inherits, so undoing a change can bring the
 * inheritance back instead of freezing a copy.
 */
interface TargetInterface
{
    /** What the admin calls it: "Product", "CMS Page". */
    public function label(): string;

    /**
     * The fields a change may set, in the order the form shows them.
     *
     * @return array<string, array{label: string, type: string, options?: array<string, string>, hint?: string}>
     *     type: text, textarea, price, boolean, select, content (Zero CMS components)
     */
    public function fields(): array;

    /** Whether a change is made for one store view (or All Store Views), or for the entity as a whole. */
    public function isStoreScoped(): bool;

    /** The entity's name for lists, or null when it no longer exists. */
    public function describe(int $id): ?string;

    /**
     * Entities matching a search, for the admin's picker.
     *
     * @param array<string, mixed> $filter Target specific (a banner's slides: ['banner' => 3])
     * @return array<int, string> id => name
     */
    public function search(string $query, array $filter = [], int $limit = 20): array;

    /**
     * The entity's current values.
     *
     * @param string[] $fields
     * @return array<string, array{value: mixed, inherited: bool}>
     */
    public function read(int $id, int $storeId, array $fields): array;

    /**
     * Sets values (what the change says, or on undo what read() returned).
     * An entry with 'inherited' => true removes the store view's own value.
     *
     * @param array<string, array{value: mixed, inherited?: bool}> $values
     */
    public function write(int $id, int $storeId, array $values): void;

    /**
     * Page and block cache tags to clean once the entity changed.
     *
     * @return string[]
     */
    public function cacheTags(int $id): array;

    /** The entity's own admin form, or '' when it has none. */
    public function adminUrl(int $id): string;
}
